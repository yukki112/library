<?php
// Lost/Damaged Books Reporting System
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';
require_login();
$pdo = DB::conn();

$current_user = current_user();
$user_role = $current_user['role'];
$user_id = $current_user['id'];

// Get damage types
$damageTypes = $pdo->query("SELECT * FROM damage_types WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);

// Pagination - Changed to 5 items per page
$items_per_page = 5;
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($current_page - 1) * $items_per_page;

// Filters
$filter_status = $_GET['status'] ?? '';
$filter_type = $_GET['report_type'] ?? '';
$search_query = $_GET['search'] ?? '';

// Build query based on user role
$params = [];
$whereClauses = [];

if ($user_role === 'student' || $user_role === 'non_staff') {
    // Students can only see their own reports
    $patronStmt = $pdo->prepare("SELECT id FROM patrons WHERE library_id = ?");
    $patronStmt->execute([$current_user['username']]);
    $patron = $patronStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($patron) {
        $whereClauses[] = "r.patron_id = ?";
        $params[] = $patron['id'];
    } else {
        // If no patron record, show empty
        $whereClauses[] = "1 = 0";
    }
}

// Apply filters
if (!empty($filter_status)) {
    $whereClauses[] = "r.status = ?";
    $params[] = $filter_status;
}

if (!empty($filter_type)) {
    $whereClauses[] = "r.report_type = ?";
    $params[] = $filter_type;
}

if (!empty($search_query)) {
    $whereClauses[] = "(b.title LIKE ? OR p.name LIKE ? OR p.library_id LIKE ?)";
    $search_term = "%{$search_query}%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

$whereSQL = !empty($whereClauses) ? "WHERE " . implode(" AND ", $whereClauses) : "";

// Count total records
$countSql = "SELECT COUNT(*) as total 
             FROM lost_damaged_reports r
             JOIN books b ON r.book_id = b.id
             JOIN patrons p ON r.patron_id = p.id
             LEFT JOIN book_copies bc ON r.book_copy_id = bc.id
             $whereSQL";

$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
$totalPages = ceil($totalCount / $items_per_page);

// Ensure current page is within valid range
if ($current_page < 1) {
    $current_page = 1;
} elseif ($current_page > $totalPages && $totalPages > 0) {
    $current_page = $totalPages;
}

// Get reports with book and patron details
$sql = "SELECT 
            r.*,
            b.title AS book_title,
            b.author,
            b.isbn,
            b.price AS book_price,
            b.cover_image_cache,
            b.category,
            p.name AS patron_name,
            p.library_id,
            p.email AS patron_email,
            p.phone AS patron_phone,
            bc.copy_number,
            bc.barcode,
            bc.current_section,
            bc.current_shelf,
            bc.current_row,
            bc.current_slot,
            bc.book_condition as copy_condition,
            bc.status as copy_status,
            -- Get borrow log info if applicable
            bl.id as borrow_log_id,
            bl.due_date as borrow_due_date,
            bl.borrowed_at,
            bl.status as borrow_status
        FROM lost_damaged_reports r
        JOIN books b ON r.book_id = b.id
        JOIN patrons p ON r.patron_id = p.id
        LEFT JOIN book_copies bc ON r.book_copy_id = bc.id
        LEFT JOIN borrow_logs bl ON r.book_copy_id = bl.book_copy_id 
            AND bl.status IN ('borrowed', 'overdue')
        $whereSQL
        ORDER BY r.report_date DESC, r.created_at DESC
        LIMIT ? OFFSET ?";

$params[] = $items_per_page;
$params[] = $offset;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get receipts for processed reports
$receipts = [];
if ($totalCount > 0 && !empty($reports)) {
    // Get receipt numbers for processed reports
    $reportIds = array_column($reports, 'id');
    if (!empty($reportIds)) {
        // Get all receipts for these patrons with damage fees
        $receiptSql = "SELECT r.receipt_number, r.patron_id, r.total_amount, r.damage_fee, r.pdf_path, 
                              r.payment_date, r.status as receipt_status,
                              GROUP_CONCAT(ldr.id) as report_ids
                       FROM receipts r
                       LEFT JOIN lost_damaged_reports ldr ON r.patron_id = ldr.patron_id 
                       WHERE r.damage_fee > 0
                       AND ldr.id IS NOT NULL
                       GROUP BY r.id
                       HAVING SUM(ldr.id IN (" . str_repeat('?,', count($reportIds) - 1) . "?)) > 0
                       ORDER BY r.payment_date DESC";
        
        $receiptStmt = $pdo->prepare($receiptSql);
        $receiptStmt->execute($reportIds);
        $receiptsData = $receiptStmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($receiptsData as $receipt) {
            if (!empty($receipt['report_ids'])) {
                $receiptReportIds = explode(',', $receipt['report_ids']);
                foreach ($receiptReportIds as $reportId) {
                    if (in_array($reportId, $reportIds)) {
                        $receipts[$reportId] = $receipt;
                    }
                }
            }
        }
    }
}

// Get book price from settings/book table for lost fee calculation
$settings = [];
$stmt = $pdo->query("SELECT * FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['key']] = $row['value'];
}

// Get books for dropdown (for new reports)
$books_sql = "SELECT b.id, b.title, b.author, b.cover_image_cache,
                     GROUP_CONCAT(bc.id ORDER BY bc.id) as available_copy_ids,
                     GROUP_CONCAT(bc.copy_number ORDER BY bc.id) as available_copy_numbers
              FROM books b
              LEFT JOIN book_copies bc ON b.id = bc.book_id 
                AND bc.status = 'available' 
                AND bc.is_active = 1
              WHERE b.is_active = 1
              GROUP BY b.id
              ORDER BY b.title";

$books = $pdo->query($books_sql)->fetchAll(PDO::FETCH_ASSOC);

// Convert damage types to JSON for JavaScript
$damageTypesJson = json_encode($damageTypes);
include __DIR__ . '/_header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lost/Damaged Books Reports</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <style>
        :root {
            --primary-color: #4361ee;
            --secondary-color: #3a0ca3;
            --success-color: #06d6a0;
            --danger-color: #ef476f;
            --warning-color: #ffd166;
            --info-color: #118ab2;
            --light-bg: #f8f9fa;
            --card-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
            --hover-shadow: 0 15px 35px rgba(0, 0, 0, 0.12);
        }
        
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .container {
            max-width: 1400px;
            padding: 20px;
        }
        
        /* Header Styles */
        .page-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: var(--card-shadow);
            position: relative;
            overflow: hidden;
        }
        
        .page-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 100%;
            height: 200%;
            background: rgba(255, 255, 255, 0.1);
            transform: rotate(30deg);
        }
        
        .page-header h2 {
            font-weight: 700;
            margin-bottom: 10px;
            font-size: 2.5rem;
        }
        
        .page-header p {
            font-size: 1.1rem;
            opacity: 0.9;
        }
        
        /* Card Styles */
        .card {
            background: white;
            border-radius: 15px;
            box-shadow: var(--card-shadow);
            margin-bottom: 25px;
            border: none;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .card:hover {
            transform: translateY(-5px);
            box-shadow: var(--hover-shadow);
        }
        
        .card-header {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-bottom: none;
            padding: 20px 25px;
            border-radius: 15px 15px 0 0 !important;
            font-weight: 600;
            font-size: 1.2rem;
        }
        
        /* Table Styles */
        .reports-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .reports-table th {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            font-weight: 600;
            padding: 15px;
            text-align: left;
            border: none;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        .reports-table td {
            padding: 15px;
            border-bottom: 1px solid #e9ecef;
            vertical-align: middle;
        }
        
        .reports-table tr {
            transition: all 0.3s ease;
        }
        
        .reports-table tr:hover {
            background-color: #f8f9fa;
            transform: translateX(5px);
        }
        
        .reports-table tr:nth-child(even) {
            background-color: #f8f9fa;
        }
        
        .reports-table tr:nth-child(even):hover {
            background-color: #e9ecef;
        }
        
        /* Badges */
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            display: inline-block;
        }
        
        .status-pending {
            background: linear-gradient(135deg, var(--warning-color), #ffb347);
            color: #000;
        }
        
        .status-resolved {
            background: linear-gradient(135deg, var(--success-color), #06a078);
            color: white;
        }
        
        .type-badge {
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }
        
        .type-lost {
            background: linear-gradient(135deg, #ef476f, #b5179e);
            color: white;
        }
        
        .type-damaged {
            background: linear-gradient(135deg, #118ab2, #073b4c);
            color: white;
        }
        
        .severity-badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
        }
        
        .severity-minor {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #10b981;
        }
        
        .severity-moderate {
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #f59e0b;
        }
        
        .severity-severe {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #ef4444;
        }
        
        .fee-badge {
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        
        .fee-pending {
            background: linear-gradient(135deg, #fef3c7, #fde68a);
            color: #92400e;
            border: 1px solid #f59e0b;
        }
        
        .fee-paid {
            background: linear-gradient(135deg, #d1fae5, #a7f3d0);
            color: #065f46;
            border: 1px solid #10b981;
        }
        
        .receipt-badge {
            background: linear-gradient(135deg, #a78bfa, #8b5cf6);
            color: white;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 11px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        
        .receipt-badge:hover {
            background: linear-gradient(135deg, #8b5cf6, #7c3aed);
            transform: translateY(-1px);
            box-shadow: 0 3px 10px rgba(139, 92, 246, 0.3);
        }
        
        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }
        
        .btn {
            border-radius: 6px;
            font-weight: 600;
            padding: 6px 12px;
            transition: all 0.3s ease;
            border: none;
            font-size: 13px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(67, 97, 238, 0.3);
            color: white;
        }
        
        .btn-success {
            background: linear-gradient(135deg, var(--success-color), #06a078);
            color: white;
        }
        
        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(6, 214, 160, 0.3);
            color: white;
        }
        
        .btn-danger {
            background: linear-gradient(135deg, var(--danger-color), #b5179e);
            color: white;
        }
        
        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(239, 71, 111, 0.3);
            color: white;
        }
        
        .btn-warning {
            background: linear-gradient(135deg, var(--warning-color), #ffb347);
            color: #000;
        }
        
        .btn-warning:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(255, 209, 102, 0.3);
            color: #000;
        }
        
        .btn-info {
            background: linear-gradient(135deg, var(--info-color), #073b4c);
            color: white;
        }
        
        .btn-info:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(17, 138, 178, 0.3);
            color: white;
        }
        
        .btn-purple {
            background: linear-gradient(135deg, #8b5cf6, #7c3aed);
            color: white;
        }
        
        .btn-purple:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(139, 92, 246, 0.3);
            color: white;
        }
        
        /* Filter Container */
        .filter-container {
            background: white;
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: var(--card-shadow);
        }
        
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1050;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
            animation: fadeIn 0.3s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        .modal-content {
            background: white;
            margin: 50px auto;
            padding: 0;
            border-radius: 20px;
            width: 90%;
            max-width: 900px;
            max-height: 85vh;
            overflow: hidden;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.15);
            animation: slideIn 0.4s ease;
        }
        
        @keyframes slideIn {
            from {
                transform: translateY(-50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        
        .modal-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 25px;
            border-radius: 20px 20px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-header h3 {
            margin: 0;
            font-weight: 700;
        }
        
        .close-modal {
            background: rgba(255, 255, 255, 0.2);
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            color: white;
            font-size: 24px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }
        
        .close-modal:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: rotate(90deg);
        }
        
        .modal-body {
            padding: 30px;
            max-height: calc(85vh - 120px);
            overflow-y: auto;
        }
        
        /* Book Info Card */
        .book-info-card {
            display: flex;
            gap: 25px;
            padding: 25px;
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-radius: 15px;
            margin-bottom: 20px;
            align-items: center;
        }
        
        .book-details {
            flex: 1;
        }
        
        .book-details h5 {
            color: #212529;
            margin-bottom: 10px;
            font-weight: 700;
        }
        
        .book-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-top: 10px;
        }
        
        .meta-item {
            display: flex;
            align-items: center;
            gap: 5px;
            color: #6c757d;
        }
        
        .meta-item i {
            color: var(--primary-color);
        }
        
        /* Damage Checkboxes */
        .damage-checkboxes {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }
        
        .damage-checkbox {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 15px;
            border: 2px solid #e9ecef;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
            background: #f8f9fa;
        }
        
        .damage-checkbox:hover {
            border-color: var(--primary-color);
            background: white;
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }
        
        .damage-checkbox input:checked + .damage-label {
            color: var(--primary-color);
            font-weight: 700;
        }
        
        .damage-label {
            cursor: pointer;
            flex: 1;
            font-weight: 500;
            color: #495057;
        }
        
        .damage-fee {
            font-weight: 700;
            color: #2d6a4f;
            background: #d8f3dc;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 12px;
        }
        
        /* Fee Summary */
        .fee-summary {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            padding: 25px;
            border-radius: 15px;
            margin: 25px 0;
            border-left: 5px solid var(--primary-color);
        }
        
        .fee-item {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid #dee2e6;
            font-size: 1rem;
        }
        
        .fee-item:last-child {
            border-bottom: none;
        }
        
        .fee-total {
            font-size: 1.2rem;
            font-weight: 700;
            color: #212529;
            padding-top: 15px;
            margin-top: 10px;
            border-top: 2px solid #212529;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6c757d;
        }
        
        .empty-state i {
            font-size: 4rem;
            color: #adb5bd;
            margin-bottom: 20px;
            opacity: 0.5;
        }
        
        .empty-state h3 {
            color: #495057;
            margin-bottom: 10px;
            font-weight: 600;
        }
        
        /* Toast Notifications */
        .toast {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 25px;
            color: white;
            border-radius: 10px;
            z-index: 9999;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 10px;
            opacity: 0;
            transform: translateX(100%);
            transition: all 0.3s ease;
        }
        
        .toast.show {
            opacity: 1;
            transform: translateX(0);
        }
        
        .toast-success {
            background: linear-gradient(135deg, #06d6a0, #06a078);
        }
        
        .toast-error {
            background: linear-gradient(135deg, #ef476f, #b5179e);
        }
        
        .toast-warning {
            background: linear-gradient(135deg, #ffd166, #ffb347);
            color: #000;
        }
        
        /* Loading Spinner */
        .loading-spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s ease-in-out infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            .container {
                padding: 10px;
            }
            
            .page-header {
                padding: 20px;
            }
            
            .page-header h2 {
                font-size: 1.8rem;
            }
            
            .reports-table {
                display: block;
                overflow-x: auto;
            }
            
            .reports-table th,
            .reports-table td {
                white-space: nowrap;
                min-width: 150px;
            }
            
            .book-info-card {
                flex-direction: column;
                text-align: center;
            }
            
            .filter-grid {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .damage-checkboxes {
                grid-template-columns: 1fr;
            }
            
            .modal-content {
                width: 95%;
                margin: 20px auto;
            }
        }
        
        /* Custom Scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }
        
        ::-webkit-scrollbar-thumb {
            background: var(--primary-color);
            border-radius: 10px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: var(--secondary-color);
        }
        
        /* Pagination */
        .pagination-container {
            display: flex;
            justify-content: center;
            align-items: center;
            margin-top: 30px;
            gap: 15px;
        }
        
        .page-info {
            font-weight: 600;
            color: #495057;
            font-size: 14px;
        }
        
        .current-page {
            background: var(--primary-color);
            color: white;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
        }
        
        .pagination-buttons {
            display: flex;
            gap: 10px;
        }
        
        /* Book Cover in Table */
        .table-book-cover {
            width: 40px;
            height: 55px;
            border-radius: 5px;
            overflow: hidden;
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 3px 8px rgba(0, 0, 0, 0.1);
        }
        
        .table-book-cover img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        /* Truncate Text */
        .truncate-text {
            max-width: 200px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .truncate-sm {
            max-width: 150px;
        }
        
        /* Report Description in Table */
        .report-description {
            max-width: 250px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            text-overflow: ellipsis;
            font-size: 13px;
            color: #6c757d;
        }
        
        /* Receipt Section */
        .receipt-section {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            padding: 20px;
            border-radius: 12px;
            margin: 20px 0;
            border-left: 4px solid #8b5cf6;
        }
        
        .receipt-info {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-top: 10px;
        }
        
        .receipt-item {
            background: white;
            padding: 10px 15px;
            border-radius: 8px;
            border: 1px solid #e9ecef;
            flex: 1;
            min-width: 200px;
        }
        
        .receipt-item .label {
            font-size: 12px;
            color: #6c757d;
            font-weight: 600;
            text-transform: uppercase;
            margin-bottom: 5px;
        }
        
        .receipt-item .value {
            font-size: 16px;
            font-weight: 700;
            color: #212529;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="page-header">
            <h2><i class="fas fa-exclamation-triangle me-2"></i>Lost & Damaged Books Reports</h2>
            <p class="mb-0">
                <?php if (in_array($user_role, ['student', 'non_staff'])): ?>
                    Report lost or damaged books and view your report history
                <?php else: ?>
                    Manage and process lost/damaged book reports from patrons
                <?php endif; ?>
            </p>
        </div>

        <!-- Action Buttons -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <?php if (in_array($user_role, ['student', 'non_staff'])): ?>
                    <button class="btn btn-primary" onclick="showReportModal()">
                        <i class="fas fa-plus-circle me-2"></i>Report Lost/Damaged Book
                    </button>
                <?php endif; ?>
            </div>
            <div class="text-end">
                <small class="text-muted">
                    <i class="fas fa-info-circle me-1"></i>
                    <?php if (in_array($user_role, ['student', 'non_staff'])): ?>
                        You will be notified when your report is processed
                    <?php else: ?>
                        Click on a report to view details and process
                    <?php endif; ?>
                </small>
            </div>
        </div>

        <!-- Filter Card -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-filter me-2"></i>Filter Reports
            </div>
            <div class="card-body">
                <form method="GET" action="" id="filterForm">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label for="search" class="form-label"><i class="fas fa-search me-1"></i>Search</label>
                            <input type="text" 
                                   id="search" 
                                   name="search" 
                                   class="form-control" 
                                   placeholder="Search by book title, patron name, or ID"
                                   value="<?= htmlspecialchars($search_query) ?>">
                        </div>
                        <div class="col-md-3">
                            <label for="status" class="form-label"><i class="fas fa-tag me-1"></i>Status</label>
                            <select id="status" name="status" class="form-select">
                                <option value="">All Status</option>
                                <option value="pending" <?= $filter_status === 'pending' ? 'selected' : '' ?>>Pending</option>
                                <option value="resolved" <?= $filter_status === 'resolved' ? 'selected' : '' ?>>Resolved</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="report_type" class="form-label"><i class="fas fa-book me-1"></i>Report Type</label>
                            <select id="report_type" name="report_type" class="form-select">
                                <option value="">All Types</option>
                                <option value="lost" <?= $filter_type === 'lost' ? 'selected' : '' ?>>Lost</option>
                                <option value="damaged" <?= $filter_type === 'damaged' ? 'selected' : '' ?>>Damaged</option>
                            </select>
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-filter me-1"></i>Apply
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Reports Table -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <i class="fas fa-clipboard-list me-2"></i>Reports
                    <?php if ($totalCount > 0): ?>
                        <small class="text-muted ms-2">Showing <?= count($reports) ?> of <?= $totalCount ?> report(s)</small>
                    <?php endif; ?>
                </div>
                <div>
                    <?php
                    $pendingCount = 0;
                    $lostCount = 0;
                    $resolvedCount = 0;
                    foreach ($reports as $report) {
                        if ($report['status'] === 'pending') $pendingCount++;
                        if ($report['report_type'] === 'lost') $lostCount++;
                        if ($report['status'] === 'resolved') $resolvedCount++;
                    }
                    ?>
                    <?php if ($pendingCount > 0): ?>
                        <span class="badge bg-warning rounded-pill px-3 py-2">Pending: <?= $pendingCount ?></span>
                    <?php endif; ?>
                    <?php if ($lostCount > 0): ?>
                        <span class="badge bg-danger rounded-pill px-3 py-2 ms-2">Lost: <?= $lostCount ?></span>
                    <?php endif; ?>
                    <?php if ($resolvedCount > 0): ?>
                        <span class="badge bg-success rounded-pill px-3 py-2 ms-2">Resolved: <?= $resolvedCount ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body">
                <?php if (empty($reports)): ?>
                    <div class="empty-state">
                        <?php if (in_array($user_role, ['student', 'non_staff'])): ?>
                            <i class="fas fa-clipboard-check fa-4x mb-4 text-success"></i>
                            <h3>No Reports Yet</h3>
                            <p>You haven't submitted any lost/damaged book reports.</p>
                            <button class="btn btn-primary mt-3" onclick="showReportModal()">
                                <i class="fas fa-plus-circle me-2"></i>Submit Your First Report
                            </button>
                        <?php else: ?>
                            <i class="fas fa-check-circle fa-4x mb-4 text-success"></i>
                            <h3>No Reports Found</h3>
                            <p>No lost/damaged book reports match your filters.</p>
                            <button class="btn btn-secondary mt-3" onclick="document.getElementById('filterForm').reset(); document.getElementById('filterForm').submit();">
                                <i class="fas fa-times me-2"></i>Clear Filters
                            </button>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="reports-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Book</th>
                                    <th>Patron</th>
                                    <th>Type</th>
                                    <th>Status</th>
                                    <th>Report Date</th>
                                    <th>Fee</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($reports as $report): 
                                    $coverImage = !empty($report['cover_image_cache']) ? 
                                        '../uploads/covers/' . $report['cover_image_cache'] : 
                                        '../assets/images/default-book.jpg';
                                    
                                    // Calculate lost fee (150% of book price)
                                    $bookPrice = floatval($report['book_price']);
                                    $lostFee = $bookPrice * 1.5;
                                    
                                    // Determine if this is a lost book that needs to be returned
                                    $isLost = $report['report_type'] === 'lost';
                                    $needsReturn = $isLost && $report['status'] === 'pending';
                                    
                                    // Format fee_charged safely
                                    $feeCharged = isset($report['fee_charged']) ? floatval($report['fee_charged']) : 0;
                                    
                                    // Check if this report has a receipt
                                    $hasReceipt = isset($receipts[$report['id']]);
                                    $receiptInfo = $hasReceipt ? $receipts[$report['id']] : null;
                                ?>
                                <tr>
                                    <td><code>#<?= $report['id'] ?></code></td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="table-book-cover">
                                                <img src="<?= $coverImage ?>" 
                                                     alt="<?= htmlspecialchars($report['book_title']) ?>" 
                                                     onerror="this.src='../assets/images/default-book.jpg'">
                                            </div>
                                            <div>
                                                <div class="fw-bold truncate-text truncate-sm"><?= htmlspecialchars($report['book_title']) ?></div>
                                                <small class="text-muted"><?= htmlspecialchars($report['author']) ?></small>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div>
                                            <div class="fw-bold"><?= htmlspecialchars($report['patron_name']) ?></div>
                                            <small class="text-muted">ID: <?= htmlspecialchars($report['library_id']) ?></small>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="type-badge type-<?= $report['report_type'] ?>">
                                            <?= ucfirst($report['report_type']) ?>
                                        </span>
                                        <?php if ($report['severity']): ?>
                                            <br>
                                            <span class="severity-badge severity-<?= $report['severity'] ?> mt-1 d-inline-block">
                                                <?= ucfirst($report['severity']) ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?= $report['status'] ?>">
                                            <?= ucfirst($report['status']) ?>
                                        </span>
                                        <?php if ($hasReceipt && $receiptInfo['receipt_status'] === 'paid'): ?>
                                            <br>
                                            <small class="text-success mt-1 d-inline-block">
                                                <i class="fas fa-receipt me-1"></i>Paid
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?= date('M d, Y', strtotime($report['report_date'])) ?>
                                        <br>
                                        <small class="text-muted"><?= date('h:i A', strtotime($report['report_date'])) ?></small>
                                    </td>
                                    <td>
                                        <?php if ($feeCharged > 0): ?>
                                            <span class="fee-badge <?= $report['status'] === 'resolved' ? 'fee-paid' : 'fee-pending' ?>">
                                                ₱<?= number_format($feeCharged, 2) ?>
                                            </span>
                                            <?php if ($hasReceipt && $receiptInfo['receipt_number']): ?>
                                                <br>
                                                <small class="receipt-badge mt-1" onclick="viewReceipt('<?= $receiptInfo['receipt_number'] ?>')">
                                                    <i class="fas fa-file-pdf me-1"></i>Receipt
                                                </small>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="badge bg-light text-dark">
                                                ₱<?= number_format($isLost ? $lostFee : 0, 2) ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="btn btn-sm btn-info" onclick="viewReportDetails(<?= $report['id'] ?>)">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            
                                            <?php if (in_array($user_role, ['admin', 'librarian', 'assistant']) && $report['status'] === 'pending'): ?>
                                                <button class="btn btn-sm btn-success" onclick="processReport(<?= $report['id'] ?>)">
                                                    <i class="fas fa-cog"></i>
                                                </button>
                                            <?php endif; ?>
                                            
                                            <?php if ($hasReceipt && $receiptInfo['pdf_path']): ?>
                                                <button class="btn btn-sm btn-purple" onclick="viewReceipt('<?= $receiptInfo['receipt_number'] ?>')">
                                                    <i class="fas fa-file-pdf"></i>
                                                </button>
                                            <?php endif; ?>
                                            
                                            <?php if (in_array($user_role, ['student', 'non_staff']) && $report['status'] === 'pending'): ?>
                                                <button class="btn btn-sm btn-warning" onclick="editReport(<?= $report['id'] ?>)">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                    <div class="pagination-container">
                        <div class="pagination-buttons">
                            <?php if ($current_page > 1): ?>
                                <a class="btn btn-outline-primary" 
                                   href="?page=1&status=<?= $filter_status ?>&report_type=<?= $filter_type ?>&search=<?= urlencode($search_query) ?>">
                                    <i class="fas fa-angle-double-left"></i>
                                </a>
                                <a class="btn btn-outline-primary" 
                                   href="?page=<?= $current_page - 1 ?>&status=<?= $filter_status ?>&report_type=<?= $filter_type ?>&search=<?= urlencode($search_query) ?>">
                                    <i class="fas fa-chevron-left me-1"></i> Previous
                                </a>
                            <?php endif; ?>
                        </div>
                        
                        <div class="page-info">
                            Page 
                            <span class="current-page"><?= $current_page ?></span>
                            of <?= $totalPages ?>
                        </div>
                        
                        <div class="pagination-buttons">
                            <?php if ($current_page < $totalPages): ?>
                                <a class="btn btn-outline-primary" 
                                   href="?page=<?= $current_page + 1 ?>&status=<?= $filter_status ?>&report_type=<?= $filter_type ?>&search=<?= urlencode($search_query) ?>">
                                    Next <i class="fas fa-chevron-right ms-1"></i>
                                </a>
                                <a class="btn btn-outline-primary" 
                                   href="?page=<?= $totalPages ?>&status=<?= $filter_status ?>&report_type=<?= $filter_type ?>&search=<?= urlencode($search_query) ?>">
                                    <i class="fas fa-angle-double-right"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="text-center mt-2">
                        <small class="text-muted">
                            Showing <?= min($items_per_page, count($reports)) ?> reports per page
                            <?php if ($totalCount > 0): ?>
                                (<?= $offset + 1 ?> - <?= min($offset + $items_per_page, $totalCount) ?> of <?= $totalCount ?>)
                            <?php endif; ?>
                        </small>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Report Modal (for submitting new reports) -->
    <div id="reportModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-exclamation-triangle me-2"></i>Report Lost/Damaged Book</h3>
                <button class="close-modal" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div id="reportFormContent">
                    <!-- Dynamic content loaded via JavaScript -->
                </div>
            </div>
        </div>
    </div>

    <!-- Process Report Modal (for admins) -->
    <div id="processModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-cog me-2"></i>Process Report</h3>
                <button class="close-modal" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div id="processReportContent">
                    <!-- Dynamic content loaded via JavaScript -->
                </div>
            </div>
        </div>
    </div>

    <!-- View Details Modal -->
    <div id="detailsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-info-circle me-2"></i>Report Details</h3>
                <button class="close-modal" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div id="reportDetailsContent">
                    <!-- Dynamic content loaded via JavaScript -->
                </div>
            </div>
        </div>
    </div>

    <!-- Toast Container -->
    <div id="toastContainer"></div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // Global variables
    let currentReportId = null;
    let selectedBookId = null;
    let selectedCopyId = null;
    let damageTypes = <?= $damageTypesJson ?>;
    let userRole = '<?= $user_role ?>';
    
    function viewReceipt(receiptNumber) {
        // Open receipt PDF in new tab
        const pdfUrl = '../receipts/lost_damage_receipt_' + receiptNumber + '_*.pdf';
        
        // Try to find the exact receipt file
        fetch('../api/get_receipt_info.php?receipt_number=' + receiptNumber)
            .then(response => response.json())
            .then(data => {
                if (data.success && data.pdf_path) {
                    window.open(data.pdf_path, '_blank');
                } else {
                    // Fallback: open receipt list
                    window.open('../receipts/', '_blank');
                }
            })
            .catch(error => {
                console.error('Error fetching receipt info:', error);
                // Fallback: open receipt list
                window.open('../receipts/', '_blank');
            });
    }
    
    function showReportModal() {
        // Simple book selection for now - we'll implement the full version later
        let modalHTML = `
            <div class="report-form-container">
                <h4><i class="fas fa-book me-2"></i>Report Lost/Damaged Book</h4>
                
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    Please select a book and provide details about the issue.
                </div>
                
                <div class="form-group mb-4">
                    <label for="bookSelect" class="form-label">
                        <i class="fas fa-book me-1"></i>Select Book
                    </label>
                    <select id="bookSelect" class="form-select" onchange="loadBookDetails(this.value)">
                        <option value="">-- Select a Book --</option>
                        <?php foreach ($books as $book): ?>
                            <option value="<?= $book['id'] ?>" 
                                    data-price="<?= $book['price'] ?? 0 ?>" 
                                    data-cover="<?= htmlspecialchars($book['cover_image_cache'] ?? '') ?>">
                                <?= htmlspecialchars($book['title']) ?> by <?= htmlspecialchars($book['author']) ?>
                                <?= $book['available_copy_ids'] ? ' (Available)' : ' (No copies)' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div id="bookDetailsSection" style="display: none;">
                    <div class="book-info-card" id="selectedBookInfo">
                        <!-- Book details will be loaded here -->
                    </div>
                    
                    <div class="form-group mb-4">
                        <label for="reportType" class="form-label">
                            <i class="fas fa-exclamation-circle me-1"></i>Report Type
                        </label>
                        <select id="reportType" class="form-select" onchange="toggleReportDetails()">
                            <option value="">Select Type</option>
                            <option value="lost">Lost Book</option>
                            <option value="damaged">Damaged Book</option>
                        </select>
                    </div>
                    
                    <div id="damageDetailsSection" style="display: none;">
                        <div class="form-group mb-4">
                            <label for="severity" class="form-label">
                                <i class="fas fa-chart-line me-1"></i>Damage Severity
                            </label>
                            <select id="severity" class="form-select">
                                <option value="minor">Minor - Small tears, light wear</option>
                                <option value="moderate">Moderate - Significant damage but readable</option>
                                <option value="severe">Severe - Unreadable, needs replacement</option>
                            </select>
                        </div>
                        
                        <div class="form-group mb-4">
                            <label class="form-label">
                                <i class="fas fa-tools me-1"></i>Damage Types (Select all that apply)
                            </label>
                            <div class="damage-checkboxes" id="damageTypesCheckboxes">
                                <?php foreach ($damageTypes as $type): ?>
                                <div class="damage-checkbox">
                                    <input type="checkbox" 
                                           id="damage_type_<?= $type['id'] ?>" 
                                           name="damage_types[]" 
                                           value="<?= $type['id'] ?>"
                                           data-fee="<?= $type['fee_amount'] ?>"
                                           class="damage-checkbox-input"
                                           onchange="updateFeePreview()">
                                    <label for="damage_type_<?= $type['id'] ?>" class="damage-label">
                                        <?= ucfirst(str_replace('_', ' ', $type['name'])) ?>
                                    </label>
                                    <span class="damage-fee">₱<?= number_format($type['fee_amount'], 2) ?></span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div id="lostWarningSection" style="display: none;">
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>Important:</strong> Lost books will incur a fee of 150% of the book price. 
                        </div>
                    </div>
                    
                    <div class="form-group mb-4">
                        <label for="description" class="form-label">
                            <i class="fas fa-file-alt me-1"></i>Description
                        </label>
                        <textarea id="description" class="form-control" rows="4" 
                                  placeholder="Describe what happened..."></textarea>
                    </div>
                    
                    <div id="feePreviewSection" style="display: none;">
                        <div class="fee-summary">
                            <h5><i class="fas fa-money-bill-wave me-2"></i>Estimated Fee</h5>
                            <div class="fee-item">
                                <span>Book Price:</span>
                                <span id="bookPricePreview">₱0.00</span>
                            </div>
                            <div class="fee-item">
                                <span>Lost Book Fee (150%):</span>
                                <span id="lostFeePreview">₱0.00</span>
                            </div>
                            <div class="fee-item">
                                <span>Damage Fees:</span>
                                <span id="damageFeePreview">₱0.00</span>
                            </div>
                            <div class="fee-item fee-total">
                                <span>Estimated Total:</span>
                                <span id="totalFeePreview">₱0.00</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="d-flex gap-3 mt-4">
                        <button class="btn btn-success flex-fill" onclick="submitReport()">
                            <i class="fas fa-paper-plane me-2"></i> Submit Report
                        </button>
                        <button class="btn btn-secondary" onclick="closeModal()">
                            <i class="fas fa-times me-2"></i> Cancel
                        </button>
                    </div>
                </div>
            </div>
        `;
        
        document.getElementById('reportFormContent').innerHTML = modalHTML;
        document.getElementById('reportModal').style.display = 'block';
    }
    
    function loadBookDetails(bookId) {
        if (!bookId) {
            document.getElementById('bookDetailsSection').style.display = 'none';
            return;
        }
        
        const selectedOption = document.querySelector(`#bookSelect option[value="${bookId}"]`);
        if (!selectedOption) return;
        
        const bookPrice = parseFloat(selectedOption.dataset.price) || 0;
        const coverImage = selectedOption.dataset.cover ? 
            '../uploads/covers/' + selectedOption.dataset.cover : 
            '../assets/images/default-book.jpg';
        const bookTitle = selectedOption.textContent.split(' by ')[0];
        const author = selectedOption.textContent.split(' by ')[1]?.split(' (')[0] || '';
        
        selectedBookId = bookId;
        
        document.getElementById('selectedBookInfo').innerHTML = `
            <div class="book-cover-container">
                <img src="${coverImage}" 
                     alt="${bookTitle}" 
                     class="book-cover"
                     onerror="this.src='../assets/images/default-book.jpg'">
            </div>
            <div class="book-details">
                <h5>${bookTitle}</h5>
                <p><strong><i class="fas fa-user-edit me-1"></i>Author:</strong> ${author}</p>
                <p><strong><i class="fas fa-tag me-1"></i>Price:</strong> ₱${bookPrice.toFixed(2)}</p>
            </div>
        `;
        
        // Store book price for calculations
        document.getElementById('selectedBookInfo').dataset.bookPrice = bookPrice;
        
        document.getElementById('bookDetailsSection').style.display = 'block';
        
        // Reset form sections
        document.getElementById('reportType').value = '';
        document.getElementById('damageDetailsSection').style.display = 'none';
        document.getElementById('lostWarningSection').style.display = 'none';
        document.getElementById('feePreviewSection').style.display = 'none';
        document.getElementById('description').value = '';
        
        // Uncheck all damage checkboxes
        document.querySelectorAll('.damage-checkbox-input').forEach(cb => cb.checked = false);
    }
    
    function toggleReportDetails() {
        const reportType = document.getElementById('reportType').value;
        const damageSection = document.getElementById('damageDetailsSection');
        const lostSection = document.getElementById('lostWarningSection');
        const feeSection = document.getElementById('feePreviewSection');
        
        if (reportType === 'damaged') {
            damageSection.style.display = 'block';
            lostSection.style.display = 'none';
        } else if (reportType === 'lost') {
            damageSection.style.display = 'none';
            lostSection.style.display = 'block';
        } else {
            damageSection.style.display = 'none';
            lostSection.style.display = 'none';
            feeSection.style.display = 'none';
            return;
        }
        
        feeSection.style.display = 'block';
        updateFeePreview();
    }
    
    function updateFeePreview() {
    const reportType = document.getElementById('reportType').value;
    const bookPrice = parseFloat(document.getElementById('selectedBookInfo').dataset.bookPrice) || 0;
    const lostFee = bookPrice * 1.5;
    
    let damageFee = 0;
    if (reportType === 'damaged') {
        document.querySelectorAll('.damage-checkbox-input:checked').forEach(checkbox => {
            damageFee += parseFloat(checkbox.dataset.fee);
        });
    }
    
    const totalFee = reportType === 'lost' ? lostFee : damageFee;
    
    document.getElementById('bookPricePreview').textContent = `₱${bookPrice.toFixed(2)}`;
    document.getElementById('lostFeePreview').textContent = reportType === 'lost' ? `₱${lostFee.toFixed(2)}` : '₱0.00';
    document.getElementById('damageFeePreview').textContent = `₱${damageFee.toFixed(2)}`;
    document.getElementById('totalFeePreview').textContent = `₱${totalFee.toFixed(2)}`;
}
    
    function submitReport() {
        if (!selectedBookId) {
            showToast('Please select a book', 'error');
            return;
        }
        
        const reportType = document.getElementById('reportType').value;
        if (!reportType) {
            showToast('Please select a report type', 'error');
            return;
        }
        
        const description = document.getElementById('description').value;
        if (!description.trim()) {
            showToast('Please provide a description', 'error');
            return;
        }
        
        const formData = new FormData();
        formData.append('book_id', selectedBookId);
        formData.append('report_type', reportType);
        formData.append('description', description);
        
        if (reportType === 'damaged') {
            const severity = document.getElementById('severity').value;
            formData.append('severity', severity);
            
            const damageTypes = [];
            document.querySelectorAll('.damage-checkbox-input:checked').forEach(checkbox => {
                damageTypes.push(checkbox.value);
            });
            formData.append('damage_types', JSON.stringify(damageTypes));
        }
        
        // Show loading state
        const submitBtn = document.querySelector('.btn-success');
        const originalText = submitBtn.innerHTML;
        submitBtn.innerHTML = '<div class="loading-spinner"></div> Submitting...';
        submitBtn.disabled = true;
        
        fetch('../api/submit_lost_damage_report.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            // Check if response is JSON
            const contentType = response.headers.get("content-type");
            if (contentType && contentType.indexOf("application/json") !== -1) {
                return response.json();
            } else {
                // If not JSON, get text and try to parse
                return response.text().then(text => {
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        throw new Error('Server returned non-JSON response: ' + text.substring(0, 100));
                    }
                });
            }
        })
        .then(data => {
            if (data.success) {
                showToast('Report submitted successfully!', 'success');
                closeModal();
                setTimeout(() => location.reload(), 1500);
            } else {
                showToast(data.message || 'Error submitting report', 'error');
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('Error submitting report: ' + error.message, 'error');
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        });
    }
    
    function viewReportDetails(reportId) {
        console.log('Viewing report details for ID:', reportId);
        currentReportId = reportId;
        
        fetch(`../api/get_report_details.php?id=${reportId}`)
            .then(response => {
                // Check if response is JSON
                const contentType = response.headers.get("content-type");
                if (contentType && contentType.indexOf("application/json") !== -1) {
                    return response.json();
                } else {
                    // If not JSON, get text and try to parse
                    return response.text().then(text => {
                        console.log('Response text:', text.substring(0, 200));
                        try {
                            return JSON.parse(text);
                        } catch (e) {
                            throw new Error('Server returned non-JSON response: ' + text.substring(0, 100));
                        }
                    });
                }
            })
            .then(data => {
                console.log('Report details response:', data);
                if (!data.success) {
                    showToast(data.message || 'Error loading report details', 'error');
                    return;
                }
                
                const report = data.data;
                displayReportDetails(report);
            })
            .catch(error => {
                console.error('Error loading report details:', error);
                showToast('Error loading report details: ' + error.message, 'error');
            });
    }
    
    function displayReportDetails(report) {
        const coverImage = report.cover_image_cache ? 
            '../uploads/covers/' + report.cover_image_cache : 
            '../assets/images/default-book.jpg';
        
        const isLost = report.report_type === 'lost';
        
        // Safely parse numeric values
        const bookPrice = parseFloat(report.book_price) || 0;
        const lostFee = bookPrice * 1.5;
        
        // Handle fee_charged which might be string, number, or null
        let feeCharged = 0;
        if (report.fee_charged !== null && report.fee_charged !== undefined) {
            feeCharged = typeof report.fee_charged === 'string' ? 
                parseFloat(report.fee_charged) : 
                parseFloat(report.fee_charged);
        }
        feeCharged = isNaN(feeCharged) ? 0 : feeCharged;
        
        // Calculate damage fee breakdown for damaged reports
        let damageFeeBreakdown = [];
        let totalDamageFee = 0;
        
        if (report.report_type === 'damaged') {
            // First try to get fee from damage_types_fees if available
            if (report.damage_types_fees) {
                try {
                    const feesData = typeof report.damage_types_fees === 'string' ? 
                        JSON.parse(report.damage_types_fees) : 
                        report.damage_types_fees;
                    
                    if (Array.isArray(feesData)) {
                        damageFeeBreakdown = feesData;
                        totalDamageFee = feesData.reduce((sum, item) => {
                            return sum + parseFloat(item.fee_amount || 0);
                        }, 0);
                    }
                } catch (e) {
                    console.error('Error parsing damage_types_fees:', e);
                }
            }
            
            // If no breakdown, check damage_types field
            if (damageFeeBreakdown.length === 0 && report.damage_types) {
                try {
                    const damageTypeIds = typeof report.damage_types === 'string' ? 
                        JSON.parse(report.damage_types) : 
                        report.damage_types;
                    
                    if (Array.isArray(damageTypeIds)) {
                        damageTypeIds.forEach(typeId => {
                            const type = damageTypes.find(t => t.id == typeId);
                            if (type) {
                                damageFeeBreakdown.push({
                                    name: type.name.replace(/_/g, ' '),
                                    fee_amount: type.fee_amount
                                });
                                totalDamageFee += parseFloat(type.fee_amount);
                            }
                        });
                    }
                } catch (e) {
                    console.error('Error parsing damage types:', e);
                }
            }
            
            // If still no breakdown, use fee_charged directly
            if (damageFeeBreakdown.length === 0 && feeCharged > 0) {
                totalDamageFee = feeCharged;
                damageFeeBreakdown.push({
                    name: 'General damage assessment',
                    fee_amount: feeCharged
                });
            }
        }
        
        const totalFee = isLost ? (feeCharged > 0 ? feeCharged : lostFee) : 
                      (feeCharged > 0 ? feeCharged : totalDamageFee);
        
        // Get damage types if damaged report
        let damageTypesHTML = '';
        if (report.report_type === 'damaged' && report.damage_types) {
            try {
                const damageTypeIds = typeof report.damage_types === 'string' ? 
                    JSON.parse(report.damage_types) : 
                    report.damage_types;
                    
                const damageTypeNames = damageTypeIds.map(id => {
                    const type = damageTypes.find(t => t.id == id);
                    return type ? type.name.replace(/_/g, ' ') : '';
                }).filter(name => name);
                damageTypesHTML = damageTypeNames.join(', ');
            } catch (e) {
                console.error('Error parsing damage types:', e);
            }
        }
        
        // Get receipt information for this report
        let receiptHTML = '';
        let receiptInfo = null;
        
        // Check if we have receipt info from PHP
        <?php if (!empty($receipts)): ?>
            const phpReceipts = <?= json_encode($receipts) ?>;
            if (phpReceipts[report.id]) {
                receiptInfo = phpReceipts[report.id];
            }
        <?php endif; ?>
        
        // If not found in PHP array, try to fetch via API
        if (!receiptInfo && report.status === 'resolved') {
            // We'll add AJAX call to get receipt info if needed
        }
        
        let detailsHTML = `
            <div class="book-info-card">
                <div class="book-cover-container">
                    <img src="${coverImage}" 
                         alt="${report.book_title}" 
                         class="book-cover"
                         onerror="this.src='../assets/images/default-book.jpg'">
                </div>
                <div class="book-details">
                    <h5>${report.book_title}</h5>
                    <p><strong><i class="fas fa-user-edit me-1"></i>Author:</strong> ${report.author}</p>
                    <p><strong><i class="fas fa-tag me-1"></i>Price:</strong> ₱${bookPrice.toFixed(2)}</p>
                    <p><strong><i class="fas fa-barcode me-1"></i>ISBN:</strong> ${report.isbn || 'N/A'}</p>
                    <p><strong><i class="fas fa-layer-group me-1"></i>Category:</strong> ${report.category || 'N/A'}</p>
                </div>
            </div>
            
            <div class="row mt-4">
                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-header">
                            <i class="fas fa-exclamation-triangle me-2"></i>Report Information
                        </div>
                        <div class="card-body">
                            <table class="table table-sm">
                                <tr>
                                    <th>Report ID:</th>
                                    <td><code>${report.id}</code></td>
                                </tr>
                                <tr>
                                    <th>Type:</th>
                                    <td>
                                        <span class="type-badge type-${report.report_type}">
                                            ${report.report_type.charAt(0).toUpperCase() + report.report_type.slice(1)}
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Status:</th>
                                    <td>
                                        <span class="status-badge status-${report.status}">
                                            ${report.status.charAt(0).toUpperCase() + report.status.slice(1)}
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Date Reported:</th>
                                    <td>${new Date(report.report_date).toLocaleDateString()}</td>
                                </tr>
                                <tr>
                                    <th>Created:</th>
                                    <td>${new Date(report.created_at).toLocaleString()}</td>
                                </tr>
                                ${report.severity ? `
                                    <tr>
                                        <th>Severity:</th>
                                        <td>
                                            <span class="severity-badge severity-${report.severity}">
                                                ${report.severity.charAt(0).toUpperCase() + report.severity.slice(1)}
                                            </span>
                                        </td>
                                    </tr>
                                ` : ''}
                                ${damageTypesHTML ? `
                                    <tr>
                                        <th>Damage Types:</th>
                                        <td>${damageTypesHTML}</td>
                                    </tr>
                                ` : ''}
                            </table>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-header">
                            <i class="fas fa-user me-2"></i>Patron Information
                        </div>
                        <div class="card-body">
                            <table class="table table-sm">
                                <tr>
                                    <th>Name:</th>
                                    <td>${report.patron_name}</td>
                                </tr>
                                <tr>
                                    <th>Library ID:</th>
                                    <td>${report.library_id}</td>
                                </tr>
                                <tr>
                                    <th>Email:</th>
                                    <td>${report.patron_email || 'N/A'}</td>
                                </tr>
                                <tr>
                                    <th>Phone:</th>
                                    <td>${report.patron_phone || 'N/A'}</td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            
            ${report.copy_number ? `
                <div class="card mt-4">
                    <div class="card-header">
                        <i class="fas fa-copy me-2"></i>Copy Information
                    </div>
                    <div class="card-body">
                        <table class="table table-sm">
                            <tr>
                                <th>Copy Number:</th>
                                <td>${report.copy_number}</td>
                            </tr>
                            ${report.barcode ? `
                                <tr>
                                    <th>Barcode:</th>
                                    <td>${report.barcode}</td>
                                </tr>
                            ` : ''}
                            <tr>
                                <th>Condition:</th>
                                <td>
                                    <span class="badge ${report.copy_condition === 'new' ? 'bg-success' : 
                                                     report.copy_condition === 'good' ? 'bg-info' : 
                                                     report.copy_condition === 'fair' ? 'bg-warning' : 'bg-secondary'} rounded-pill px-3">
                                        ${report.copy_condition || 'N/A'}
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <th>Location:</th>
                                <td>
                                    ${report.current_section ? 
                                        `${report.current_section}-S${report.current_shelf}-R${report.current_row}-P${report.current_slot}` : 
                                        'N/A'}
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
            ` : ''}
            
            ${report.description ? `
                <div class="card mt-4">
                    <div class="card-header">
                        <i class="fas fa-file-alt me-2"></i>Description
                    </div>
                    <div class="card-body">
                        <p class="mb-0">${report.description.replace(/\n/g, '<br>')}</p>
                    </div>
                </div>
            ` : ''}
            
            <div class="fee-summary mt-4">
                <h5><i class="fas fa-money-bill-wave me-2"></i>Fee Information</h5>
                <div class="fee-item">
                    <span>Book Price:</span>
                    <span>₱${bookPrice.toFixed(2)}</span>
                </div>
                ${isLost ? `
                    <div class="fee-item">
                        <span>Lost Fee (150%):</span>
                        <span>₱${lostFee.toFixed(2)}</span>
                    </div>
                ` : ''}
                ${!isLost && damageFeeBreakdown.length > 0 ? `
                    ${damageFeeBreakdown.map(item => `
                        <div class="fee-item">
                            <span>${item.name}:</span>
                            <span>₱${parseFloat(item.fee_amount).toFixed(2)}</span>
                        </div>
                    `).join('')}
                ` : ''}
                ${!isLost && damageFeeBreakdown.length === 0 && feeCharged > 0 ? `
                    <div class="fee-item">
                        <span>Damage Fee:</span>
                        <span>₱${feeCharged.toFixed(2)}</span>
                    </div>
                ` : ''}
                <div class="fee-item fee-total">
                    <span>Total Fee:</span>
                    <span>₱${totalFee.toFixed(2)}</span>
                </div>
                ${report.status === 'resolved' ? `
                    <div class="alert alert-success mt-3">
                        <i class="fas fa-check-circle me-2"></i>
                        <strong>Fee has been charged.</strong> ${isLost ? 'Book marked as lost.' : 'Damage recorded.'}
                    </div>
                ` : `
                    <div class="alert alert-warning mt-3">
                        <i class="fas fa-clock me-2"></i>
                        <strong>Fee pending.</strong> ${isLost ? 'Book will be marked as lost when resolved.' : 'Damage assessment pending.'}
                    </div>
                `}
            </div>
        `;
        
        // Add receipt section if report is resolved
        if (report.status === 'resolved') {
            // Try to get receipt info
            fetch('../api/get_receipt_by_report.php?report_id=' + report.id)
                .then(response => response.json())
                .then(receiptData => {
                    if (receiptData.success && receiptData.receipt) {
                        const receipt = receiptData.receipt;
                        detailsHTML += `
                            <div class="receipt-section">
                                <h5><i class="fas fa-receipt me-2"></i>Receipt Information</h5>
                                <div class="receipt-info">
                                    <div class="receipt-item">
                                        <div class="label">Receipt Number</div>
                                        <div class="value">${receipt.receipt_number}</div>
                                    </div>
                                    <div class="receipt-item">
                                        <div class="label">Amount Paid</div>
                                        <div class="value">₱${parseFloat(receipt.total_amount).toFixed(2)}</div>
                                    </div>
                                    <div class="receipt-item">
                                        <div class="label">Payment Date</div>
                                        <div class="value">${new Date(receipt.payment_date).toLocaleDateString()}</div>
                                    </div>
                                </div>
                                ${receipt.pdf_path ? `
                                    <button class="btn btn-purple mt-3" onclick="viewReceipt('${receipt.receipt_number}')">
                                        <i class="fas fa-file-pdf me-2"></i> View Receipt PDF
                                    </button>
                                ` : ''}
                            </div>
                        `;
                        
                        // Update the modal content
                        document.getElementById('reportDetailsContent').innerHTML = detailsHTML + getActionButtons(report);
                    } else {
                        // No receipt found
                        document.getElementById('reportDetailsContent').innerHTML = detailsHTML + getActionButtons(report);
                    }
                })
                .catch(error => {
                    console.error('Error fetching receipt:', error);
                    document.getElementById('reportDetailsContent').innerHTML = detailsHTML + getActionButtons(report);
                });
        } else {
            // No receipt for pending reports
            document.getElementById('reportDetailsContent').innerHTML = detailsHTML + getActionButtons(report);
        }
        
        document.getElementById('detailsModal').style.display = 'block';
    }
    
    function getActionButtons(report) {
        let buttonsHTML = `
            <div class="d-flex gap-3 mt-4">
                <button class="btn btn-primary" onclick="closeModal()">
                    <i class="fas fa-times me-2"></i> Close
                </button>
        `;
        
        // Add process button for admins if report is pending
        if (userRole === 'admin' || userRole === 'librarian' || userRole === 'assistant') {
            if (report.status === 'pending') {
                buttonsHTML += `
                    <button class="btn btn-success" onclick="processReport(${report.id})">
                        <i class="fas fa-cog me-2"></i> Process Report
                    </button>
                `;
            } else if (report.status === 'resolved') {
                // Add receipt button for resolved reports
                buttonsHTML += `
                    <button class="btn btn-purple" onclick="viewReceiptForReport(${report.id})">
                        <i class="fas fa-receipt me-2"></i> View Receipt
                    </button>
                `;
            }
        }
        
        // Add edit button for students if report is pending
        if ((userRole === 'student' || userRole === 'non_staff') && report.status === 'pending') {
            buttonsHTML += `
                <button class="btn btn-warning" onclick="editReport(${report.id})">
                    <i class="fas fa-edit me-2"></i> Edit Report
                </button>
            `;
        }
        
        buttonsHTML += `</div>`;
        return buttonsHTML;
    }
    
    function viewReceiptForReport(reportId) {
        // Fetch receipt info for this report
        fetch('../api/get_receipt_by_report.php?report_id=' + reportId)
            .then(response => response.json())
            .then(data => {
                if (data.success && data.receipt && data.receipt.pdf_path) {
                    window.open(data.receipt.pdf_path, '_blank');
                } else {
                    showToast('Receipt not found or PDF not available', 'error');
                }
            })
            .catch(error => {
                console.error('Error fetching receipt:', error);
                showToast('Error loading receipt', 'error');
            });
    }
    
    function processReport(reportId) {
        console.log('Processing report ID:', reportId);
        currentReportId = reportId;
        
        fetch(`../api/get_report_details.php?id=${reportId}`)
            .then(response => {
                // Check if response is JSON
                const contentType = response.headers.get("content-type");
                if (contentType && contentType.indexOf("application/json") !== -1) {
                    return response.json();
                } else {
                    // If not JSON, get text and try to parse
                    return response.text().then(text => {
                        console.log('Response text:', text.substring(0, 200));
                        try {
                            return JSON.parse(text);
                        } catch (e) {
                            throw new Error('Server returned non-JSON response: ' + text.substring(0, 100));
                        }
                    });
                }
            })
            .then(data => {
                if (!data.success) {
                    showToast(data.message || 'Error loading report details', 'error');
                    return;
                }
                
                const report = data.data;
                displayProcessForm(report);
            })
            .catch(error => {
                console.error('Error loading report details:', error);
                showToast('Error loading report details: ' + error.message, 'error');
            });
    }
    
  function displayProcessForm(report) {
    const isLost = report.report_type === 'lost';
    const bookPrice = parseFloat(report.book_price) || 0;
    const lostFee = bookPrice * 1.5;
    
    // Calculate damage fees based on damage types - FIXED VERSION
    let damageFee = 0;
    let damageTypesHTML = '';
    let damageFeeBreakdown = [];
    
    if (report.report_type === 'damaged') {
        // First, check if fee_charged already has a value from the submission
        if (report.fee_charged && report.fee_charged > 0) {
            damageFee = parseFloat(report.fee_charged);
        }
        
        // Get damage types if specified
        if (report.damage_types) {
            try {
                const damageTypeIds = typeof report.damage_types === 'string' ? 
                    JSON.parse(report.damage_types) : 
                    report.damage_types;
                    
                damageFee = 0; // Reset and calculate from damage types
                damageTypesHTML = '<div class="mb-3">';
                
                damageTypeIds.forEach(typeId => {
                    const type = damageTypes.find(t => t.id == typeId);
                    if (type) {
                        const feeAmount = parseFloat(type.fee_amount);
                        damageFee += feeAmount;
                        damageFeeBreakdown.push({
                            id: type.id,
                            name: type.name.replace(/_/g, ' '),
                            fee_amount: feeAmount
                        });
                        damageTypesHTML += `
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" 
                                       id="process_damage_${type.id}" 
                                       checked disabled>
                                <label class="form-check-label" for="process_damage_${type.id}">
                                    ${type.name.replace(/_/g, ' ')} 
                                    (₱${feeAmount.toFixed(2)})
                                </label>
                            </div>
                        `;
                    }
                });
                
                damageTypesHTML += '</div>';
            } catch (e) {
                console.error('Error parsing damage types:', e);
                // If we can't parse damage types, use the fee_charged value
                damageFee = parseFloat(report.fee_charged) || 500;
                damageFeeBreakdown.push({
                    name: 'General damage assessment',
                    fee_amount: damageFee
                });
            }
        } else {
            // If no damage types selected, use fee_charged or default
            damageFee = parseFloat(report.fee_charged) || 500;
            damageFeeBreakdown.push({
                name: 'General damage assessment',
                fee_amount: damageFee
            });
        }
    }
    
    const totalFee = isLost ? lostFee : damageFee;
    
    let processHTML = `
        <div class="alert alert-info">
            <i class="fas fa-info-circle me-2"></i>
            Processing <strong>${isLost ? 'Lost' : 'Damaged'}</strong> report for: 
            <strong>${report.book_title}</strong> by ${report.author}
        </div>
        
        <div class="row">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-user me-2"></i>Patron Details
                    </div>
                    <div class="card-body">
                        <p><strong>Name:</strong> ${report.patron_name}</p>
                        <p><strong>Library ID:</strong> ${report.library_id}</p>
                        <p><strong>Book Price:</strong> ₱${bookPrice.toFixed(2)}</p>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-calculator me-2"></i>Fee Calculation
                    </div>
                    <div class="card-body">
                        <div class="fee-summary">
                            ${isLost ? `
                                <div class="fee-item">
                                    <span>Book Price:</span>
                                    <span>₱${bookPrice.toFixed(2)}</span>
                                </div>
                                <div class="fee-item">
                                    <span>Lost Fee (150%):</span>
                                    <span>₱${lostFee.toFixed(2)}</span>
                                </div>
                            ` : `
                                <div class="fee-item">
                                    <span>Damage Fee${damageFeeBreakdown.length > 1 ? 's' : ''}:</span>
                                    <span>₱${damageFee.toFixed(2)}</span>
                                </div>
                            `}
                            <div class="fee-item fee-total">
                                <span>Total Fee:</span>
                                <span>₱${totalFee.toFixed(2)}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        ${damageTypesHTML ? `
            <div class="card mt-3">
                <div class="card-header">
                    <i class="fas fa-tools me-2"></i>Selected Damage Types
                </div>
                <div class="card-body">
                    ${damageTypesHTML}
                </div>
            </div>
        ` : ''}
        
        <div class="form-group mt-4">
            <label for="adminNotes" class="form-label">
                <i class="fas fa-sticky-note me-1"></i>Admin Notes (Optional)
            </label>
            <textarea id="adminNotes" class="form-control" rows="3" 
                      placeholder="Add any notes about this report processing..."></textarea>
        </div>
        
        ${!isLost && report.borrow_log_id && report.borrow_status && ['borrowed', 'overdue'].includes(report.borrow_status) ? `
            <div class="alert alert-warning mt-3">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <strong>Note:</strong> This damaged book is currently borrowed and needs to be returned by: 
                <strong>${new Date(report.borrow_due_date).toLocaleDateString()}</strong>
            </div>
        ` : ''}
        
        ${isLost && report.borrow_log_id && report.borrow_status && ['borrowed', 'overdue'].includes(report.borrow_status) ? `
            <div class="alert alert-danger mt-3">
                <i class="fas fa-exclamation-circle me-2"></i>
                <strong>Important:</strong> This book is currently borrowed and will be marked as lost in the system. 
                The borrow record will be updated to reflect the lost status.
            </div>
        ` : ''}
        
        <div class="alert alert-info mt-4">
            <i class="fas fa-receipt me-2"></i>
            <strong>Note:</strong> Processing this report will:
            <ul class="mb-0 mt-2">
                <li>Update the book copy status to "${isLost ? 'lost' : 'damaged'}"</li>
                <li>Generate a receipt for the patron</li>
                <li>Mark this report as resolved</li>
                ${isLost ? '<li>Remove the book from available inventory</li>' : ''}
                ${!isLost ? '<li>Calculate fee based on selected damage types</li>' : ''}
            </ul>
        </div>
        
        <div class="d-flex gap-3 mt-4">
            <button class="btn btn-success flex-fill" onclick="submitReportProcessing()">
                <i class="fas fa-check-circle me-2"></i> Process & Generate Receipt
            </button>
            <button class="btn btn-secondary" onclick="closeModal()">
                <i class="fas fa-times me-2"></i> Cancel
            </button>
        </div>
    `;
    
    document.getElementById('processReportContent').innerHTML = processHTML;
    document.getElementById('processModal').style.display = 'block';
}
    
    function submitReportProcessing() {
        if (!currentReportId) return;
        
        const adminNotes = document.getElementById('adminNotes').value;
        
        const formData = new FormData();
        formData.append('report_id', currentReportId);
        formData.append('admin_notes', adminNotes);
        
        // Show loading state
        const processBtn = document.querySelector('.btn-success');
        const originalText = processBtn.innerHTML;
        processBtn.innerHTML = '<div class="loading-spinner"></div> Processing...';
        processBtn.disabled = true;
        
        fetch('../api/process_lost_damage_report.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            // Check if response is JSON
            const contentType = response.headers.get("content-type");
            if (contentType && contentType.indexOf("application/json") !== -1) {
                return response.json();
            } else {
                // If not JSON, get text and try to parse
                return response.text().then(text => {
                    console.log('Response text:', text.substring(0, 200));
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        throw new Error('Server returned non-JSON response: ' + text.substring(0, 100));
                    }
                });
            }
        })
        .then(data => {
            if (data.success) {
                showToast('Report processed successfully! Receipt generated.', 'success');
                
                if (data.receipt_pdf) {
                    setTimeout(() => {
                        window.open(data.receipt_pdf, '_blank');
                    }, 1000);
                }
                
                closeModal();
                setTimeout(() => location.reload(), 1500);
            } else {
                showToast(data.message || 'Error processing report', 'error');
                processBtn.innerHTML = originalText;
                processBtn.disabled = false;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('Error processing report: ' + error.message, 'error');
            processBtn.innerHTML = originalText;
            processBtn.disabled = false;
        });
    }
    
    function editReport(reportId) {
        showToast('Edit feature coming soon!', 'info');
    }
    
    function closeModal() {
        document.getElementById('reportModal').style.display = 'none';
        document.getElementById('processModal').style.display = 'none';
        document.getElementById('detailsModal').style.display = 'none';
        currentReportId = null;
        selectedBookId = null;
        selectedCopyId = null;
    }
    
    // Close modal when clicking outside
    window.onclick = function(event) {
        const modals = document.getElementsByClassName('modal');
        for (let modal of modals) {
            if (event.target === modal) {
                closeModal();
            }
        }
    }
    
    function showToast(message, type = 'success') {
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.innerHTML = `
            <i class="fas fa-${type === 'success' ? 'check-circle' : 
                              type === 'error' ? 'exclamation-circle' : 
                              'exclamation-triangle'} me-2"></i>
            <span>${message}</span>
        `;
        
        document.getElementById('toastContainer').appendChild(toast);
        
        // Show toast
        setTimeout(() => {
            toast.classList.add('show');
        }, 10);
        
        // Remove after 5 seconds
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => {
                if (toast.parentNode) {
                    toast.parentNode.removeChild(toast);
                }
            }, 300);
        }, 5000);
    }
    
    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeModal();
        }
    });
    </script>
</body>
</html>
<?php include __DIR__ . '/_footer.php'; ?>