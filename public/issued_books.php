<?php
// Borrowed Books page with copy-based tracking, damage assessment, and penalty calculations
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';
require_login();
$pdo = DB::conn();

// Get filter parameters
$filter = $_GET['filter'] ?? 'all';
$student_id = $_GET['student_id'] ?? '';
$status_filter = $_GET['status'] ?? '';

// Pagination settings - Changed to 6 items per page
$items_per_page = 6;
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($current_page - 1) * $items_per_page;

// Build query with filters
$params = [];
$whereClauses = [];

// Determine which tab is active
$activeTab = 'active'; // Default tab

if ($filter === 'overdue') {
    $whereClauses[] = "bl.status = 'overdue'";
    $activeTab = 'overdue';
} elseif ($filter === 'returned') {
    $whereClauses[] = "bl.status = 'returned'";
    $activeTab = 'returned';
} else {
    // Default: show all non-returned (active + overdue)
    $whereClauses[] = "bl.status IN ('borrowed', 'overdue')";
    $activeTab = 'active';
}

if (!empty($student_id)) {
    $whereClauses[] = "(u.student_id = :student_id OR p.library_id = :student_id2)";
    $params[':student_id'] = $student_id;
    $params[':student_id2'] = $student_id;
}

if (!empty($status_filter) && in_array($status_filter, ['borrowed', 'overdue', 'returned'])) {
    $whereClauses[] = "bl.status = :status";
    $params[':status'] = $status_filter;
}

$whereSQL = !empty($whereClauses) ? "WHERE " . implode(" AND ", $whereClauses) : "";

// Count total records
$countSql = "SELECT COUNT(DISTINCT bl.id) as total 
             FROM borrow_logs bl
             JOIN books b ON bl.book_id = b.id
             LEFT JOIN book_copies bc ON bl.book_copy_id = bc.id
             JOIN patrons p ON bl.patron_id = p.id
             LEFT JOIN users u ON u.patron_id = p.id
             LEFT JOIN categories c ON b.category_id = c.id
             $whereSQL";

$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
$totalPages = ceil($totalCount / $items_per_page);

// Main query to fetch borrow records - UPDATED to fetch extension receipts
$sql = "SELECT 
            bl.id, 
            bl.book_id,
            bl.book_copy_id,
            bl.patron_id,
            b.title AS book_name,
            b.author,
            b.isbn,
            b.cover_image_cache,
            bc.copy_number,
            bc.barcode,
            bc.book_condition,
            bc.current_section,
            bc.current_shelf,
            bc.current_row,
            bc.current_slot,
            bl.borrowed_at, 
            bl.due_date,
            bl.returned_at,
            bl.status,
            bl.late_fee,
            bl.penalty_fee,
            bl.damage_type,
            bl.damage_description,
            bl.return_condition,
            bl.return_status,
            bl.return_book_condition,
            bl.damage_types,
            bl.extension_attempts,
            bl.last_extension_date,
            u.role AS user_role, 
            u.name AS user_name, 
            u.username,
            u.email,
            u.student_id,
            p.name AS patron_name,
            p.library_id,
            p.department,
            p.semester,
            c.name AS category_name,
            -- Return receipts
            rc.receipt_number as return_receipt_number,
            rc.pdf_path as return_receipt_pdf,
            -- Extension receipts (if any)
            er.id as extension_request_id,
            er.receipt_number as extension_receipt_number,
            rc_ext.pdf_path as extension_receipt_pdf,
            rc_ext.created_at as extension_receipt_date,
            -- Add copy location info
            CONCAT(
                COALESCE(bc.current_section, 'A'), 
                '-S', COALESCE(bc.current_shelf, '1'),
                '-R', COALESCE(bc.current_row, '1'),
                '-P', COALESCE(bc.current_slot, '1')
            ) as full_location
        FROM borrow_logs bl
        JOIN books b ON bl.book_id = b.id
        LEFT JOIN book_copies bc ON bl.book_copy_id = bc.id
        JOIN patrons p ON bl.patron_id = p.id
        LEFT JOIN users u ON u.patron_id = p.id
        LEFT JOIN categories c ON b.category_id = c.id
        -- Left join for return receipts
        LEFT JOIN receipts rc ON rc.borrow_log_id = bl.id AND rc.status = 'paid' AND rc.extension_request_id IS NULL
        -- Left join for extension requests and their receipts
       LEFT JOIN extension_requests er ON er.borrow_log_id = bl.id AND er.status = 'approved'
LEFT JOIN receipts rc_ext ON rc_ext.extension_request_id = er.id AND rc_ext.status = 'paid'
        $whereSQL
        ORDER BY 
            bl.patron_id ASC,
            CASE WHEN bl.status = 'overdue' THEN 1 
                 WHEN bl.status = 'borrowed' THEN 2 
                 ELSE 3 END,
            bl.due_date ASC, 
            bl.borrowed_at DESC,
            bl.book_copy_id ASC
        LIMIT :limit OFFSET :offset";
        
$stmt = $pdo->prepare($sql);
$params[':limit'] = $items_per_page;
$params[':offset'] = $offset;

foreach ($params as $key => $value) {
    if ($key === ':limit' || $key === ':offset') {
        $stmt->bindValue($key, $value, PDO::PARAM_INT);
    } else {
        $stmt->bindValue($key, $value);
    }
}

$stmt->execute();
$issued = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get damage types for the form
$damageTypes = $pdo->query("SELECT * FROM damage_types WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);

// Get pending extension requests with pagination
$pendingExtensions = $pdo->query("
    SELECT er.*, p.name as patron_name, p.library_id, b.title as book_title, b.author, bc.copy_number, bc.barcode,
           bl.due_date as current_due_date
    FROM extension_requests er
    JOIN patrons p ON er.patron_id = p.id
    JOIN book_copies bc ON er.book_copy_id = bc.id
    JOIN books b ON bc.book_id = b.id
    JOIN borrow_logs bl ON er.borrow_log_id = bl.id
    WHERE er.status = 'pending'
    ORDER BY er.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Get processed extension requests (history) with receipts
$extensionHistory = $pdo->query("
    SELECT er.*, p.name as patron_name, p.library_id, b.title as book_title, b.author, 
       bc.copy_number, bc.barcode, u.name as approved_by_name,
       COALESCE(rc.pdf_path, er.receipt_pdf) as receipt_pdf, 
       COALESCE(rc.receipt_number, er.receipt_number) as receipt_number, 
       rc.created_at as receipt_date,
       bl.due_date as original_due_date
    FROM extension_requests er
    JOIN patrons p ON er.patron_id = p.id
    JOIN book_copies bc ON er.book_copy_id = bc.id
    JOIN books b ON bc.book_id = b.id
    JOIN borrow_logs bl ON er.borrow_log_id = bl.id
    LEFT JOIN users u ON er.approved_by = u.id
    LEFT JOIN receipts rc ON rc.extension_request_id = er.id AND rc.status = 'paid'
    WHERE er.status IN ('approved', 'rejected')
    ORDER BY er.approved_at DESC, er.created_at DESC
    LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

// Get extension settings
$settings = [];
$stmt = $pdo->query("SELECT * FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['key']] = $row['value'];
}

$extension_fee_per_day = $settings['extension_fee_per_day'] ?? 10;
$max_extensions_per_book = $settings['max_extensions_per_book'] ?? 2;
$extension_max_days = $settings['extension_max_days'] ?? 14;

include __DIR__ . '/_header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Borrowed Books Management</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <style>
        :root {
            --primary-color: #4361ee;
            --secondary-color: #3a0ca3;
            --success-color: #4cc9f0;
            --danger-color: #f72585;
            --warning-color: #f8961e;
            --info-color: #7209b7;
            --light-bg: #f8f9fa;
            --card-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
            --hover-shadow: 0 15px 35px rgba(0, 0, 0, 0.12);
        }
        
        body {
            background: white;
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
        /* Add this to your existing CSS */
.fee-summary-mini {
    background: #f8f9fa;
    padding: 8px;
    border-radius: 8px;
    border-left: 3px solid #4361ee;
}

.fee-item-mini {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 4px;
    font-size: 12px;
}

.fee-item-mini:last-child {
    margin-bottom: 0;
}

.fee-total-mini {
    border-top: 1px solid #dee2e6;
    padding-top: 4px;
    margin-top: 4px;
    font-weight: bold;
}

.fee-lost {
    background: linear-gradient(135deg, #495057, #212529);
    color: white;
    border-left: 4px solid #212529;
}

/* Damage Report Indicator */
.damage-report-indicator {
    background: linear-gradient(135deg, #ffdeeb, #fbb1bd);
    color: #9d174d;
    padding: 8px 12px;
    border-radius: 8px;
    border-left: 4px solid #9d174d;
    margin-top: 8px;
    font-size: 12px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.damage-report-list {
    margin: 5px 0;
    padding-left: 20px;
}

.damage-report-item {
    display: flex;
    justify-content: space-between;
    padding: 4px 0;
    border-bottom: 1px solid rgba(0,0,0,0.05);
}

.damage-report-item:last-child {
    border-bottom: none;
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
        
        /* Book Cover */
        .book-cover-container {
            width: 60px;
            height: 80px;
            border-radius: 8px;
            overflow: hidden;
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease;
        }
        
        .book-cover-container:hover {
            transform: scale(1.05);
        }
        
        .book-cover {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        /* Badges */
        .status-badge {
            padding: 6px 15px;
            border-radius: 25px;
            font-size: 12px;
            font-weight: 600;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }
        
        .status-borrowed {
            background: linear-gradient(135deg, var(--primary-color), #4895ef);
            color: white;
        }
        
        .status-overdue {
            background: linear-gradient(135deg, var(--danger-color), #f72585);
            color: white;
        }
        
        .status-returned {
            background: linear-gradient(135deg, #4cc9f0, #4895ef);
            color: white;
        }
        
        .status-pending {
            background: linear-gradient(135deg, #ffd166, #ffb347);
            color: white;
        }
        
        .status-approved {
            background: linear-gradient(135deg, #06d6a0, #06a078);
            color: white;
        }
        
        .status-rejected {
            background: linear-gradient(135deg, #ef476f, #d90429);
            color: white;
        }
        
        .fee-badge {
            padding: 5px 12px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
            margin: 2px;
        }
        
        .fee-overdue {
            background: linear-gradient(135deg, #ffe5ec, #ffc2d1);
            color: #c9184a;
            border-left: 4px solid #c9184a;
        }
        
        .fee-damage {
            background: linear-gradient(135deg, #fff3cd, #ffe8a1);
            color: #e67700;
            border-left: 4px solid #e67700;
        }
        
        .fee-extension {
            background: linear-gradient(135deg, #d8f3dc, #b7e4c7);
            color: #2d6a4f;
            border-left: 4px solid #2d6a4f;
        }
        
        .damage-tag {
            display: inline-block;
            padding: 4px 10px;
            background: linear-gradient(135deg, #ffdeeb, #fbb1bd);
            color: #9d174d;
            border-radius: 15px;
            font-size: 11px;
            margin: 2px;
            font-weight: 500;
        }
        
        .location-badge {
            display: inline-block;
            padding: 4px 10px;
            background: linear-gradient(135deg, #e0d7ff, #c8b6ff);
            color: #5a189a;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 500;
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
        
        .filter-group {
            display: flex;
            flex-direction: column;
        }
        
        .filter-group label {
            font-weight: 600;
            margin-bottom: 8px;
            color: #495057;
            font-size: 0.9rem;
        }
        
        .search-box {
            display: flex;
            gap: 10px;
            margin-top: 5px;
        }
        
        /* Book Info Card */
        .book-info-card {
            display: flex;
            gap: 20px;
            padding: 20px;
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-radius: 12px;
            margin-bottom: 15px;
            align-items: center;
        }
        
        .book-details {
            flex: 1;
        }
        
        .book-details h5 {
            color: #212529;
            margin-bottom: 10px;
            font-weight: 600;
        }
        
        .copy-info {
            background: white;
            padding: 15px;
            border-radius: 10px;
            border-left: 4px solid var(--primary-color);
            margin-top: 10px;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.05);
        }
        
        .copy-unique-id {
            background: #e7f5ff;
            padding: 8px 12px;
            border-radius: 6px;
            border-left: 4px solid #228be6;
            font-family: monospace;
            font-size: 12px;
            margin-top: 8px;
        }
        
        /* Overdue Warning */
        .overdue-warning {
            background: linear-gradient(135deg, #ffe5ec, #ffc2d1);
            border-left: 4px solid #c9184a;
            padding: 15px;
            margin: 15px 0;
            border-radius: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
            color: #c9184a;
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
        
        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        .btn {
            border-radius: 8px;
            font-weight: 600;
            padding: 8px 16px;
            transition: all 0.3s ease;
            border: none;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(67, 97, 238, 0.3);
        }
        
        .btn-danger {
            background: linear-gradient(135deg, #f72585, #b5179e);
        }
        
        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(247, 37, 133, 0.3);
        }
        
        .btn-success {
            background: linear-gradient(135deg, #4cc9f0, #4895ef);
        }
        
        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(76, 201, 240, 0.3);
        }
        
        .btn-info {
            background: linear-gradient(135deg, #7209b7, #560bad);
            color: white;
        }
        
        .btn-info:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(114, 9, 183, 0.3);
        }
        
        .btn-warning {
            background: linear-gradient(135deg, #ffd166, #ffb347);
            color: white;
        }
        
        .btn-warning:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(255, 209, 102, 0.3);
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
            max-width: 800px;
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
        
        /* Return Form Container */
        .return-form-container {
            background: white;
            padding: 0;
            border-radius: 15px;
        }
        
        /* Tab Navigation */
        .tab-navigation {
            display: flex;
            gap: 10px;
            margin-bottom: 30px;
            padding: 0 20px;
            border-bottom: 2px solid #e9ecef;
            flex-wrap: wrap;
        }
        
        .tab-btn {
            padding: 12px 25px;
            background: none;
            border: none;
            color: #6c757d;
            font-weight: 600;
            border-radius: 10px 10px 0 0;
            transition: all 0.3s ease;
            position: relative;
            white-space: nowrap;
        }
        
        .tab-btn:hover {
            color: var(--primary-color);
            background: rgba(67, 97, 238, 0.05);
        }
        
        .tab-btn.active {
            color: var(--primary-color);
            background: white;
            border-bottom: 3px solid var(--primary-color);
        }
        
        .tab-btn .badge {
            margin-left: 5px;
            font-size: 10px;
            padding: 2px 6px;
        }
        
        .tab-content {
            display: none;
            animation: fadeIn 0.5s ease;
        }
        
        .tab-content.active {
            display: block;
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
        }
        
        .toast-success {
            background: linear-gradient(135deg, #52b788, #40916c);
        }
        
        .toast-error {
            background: linear-gradient(135deg, #f72585, #b5179e);
        }
        
        .toast-warning {
            background: linear-gradient(135deg, #ffd166, #ffb347);
        }
        
        /* Pagination Styles */
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
        
        /* Table Styles for Extensions */
        .extension-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }
        
        .extension-table th {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: #495057;
            border-bottom: 2px solid #dee2e6;
        }
        
        .extension-table td {
            padding: 15px;
            border-bottom: 1px solid #e9ecef;
            vertical-align: middle;
        }
        
        .extension-table tr:hover {
            background-color: rgba(67, 97, 238, 0.05);
        }
        
        /* Extension Card */
        .extension-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 15px;
            border-left: 4px solid #ffb347;
            box-shadow: 0 3px 15px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
        }
        
        .extension-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
        }
        
        .extension-card.pending {
            border-left: 4px solid #ffb347;
        }
        
        .extension-card.approved {
            border-left: 4px solid #06d6a0;
        }
        
        .extension-card.rejected {
            border-left: 4px solid #ef476f;
        }
        
        .extension-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }
        
        .extension-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: #212529;
            margin-bottom: 5px;
        }
        
        .extension-meta {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            margin-top: 10px;
        }
        
        .meta-item {
            display: flex;
            align-items: center;
            gap: 5px;
            color: #6c757d;
            font-size: 0.9rem;
        }
        
        .meta-item i {
            color: var(--primary-color);
        }
        
        .date-change {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            padding: 15px;
            border-radius: 8px;
            margin: 15px 0;
        }
        
        .date-from, .date-to {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 5px;
        }
        
        .date-label {
            font-size: 0.8rem;
            color: #6c757d;
        }
        
        .date-value {
            font-weight: 600;
            font-size: 1rem;
        }
        
        .date-from .date-value {
            color: #ef476f;
        }
        
        .date-to .date-value {
            color: #06d6a0;
        }
        
        .arrow-icon {
            font-size: 1.2rem;
            color: var(--primary-color);
        }
        
        .extension-details {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin: 15px 0;
        }
        
        .detail-row {
            display: flex;
            margin-bottom: 8px;
        }
        
        .detail-label {
            min-width: 150px;
            font-weight: 600;
            color: #495057;
        }
        
        .detail-value {
            color: #212529;
        }
        
        /* History Table */
        .history-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .history-table th {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            padding: 12px 15px;
            text-align: left;
            font-weight: 600;
            color: #495057;
            border-bottom: 2px solid #dee2e6;
        }
        
        .history-table td {
            padding: 12px 15px;
            border-bottom: 1px solid #e9ecef;
            vertical-align: middle;
        }
        
        .history-table tr:last-child td {
            border-bottom: none;
        }
        
        .history-table tr:hover {
            background-color: rgba(67, 97, 238, 0.03);
        }
        
        /* Receipt Button */
        .receipt-btn {
            background: linear-gradient(135deg, #06d6a0, #06a078);
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 0.85rem;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            text-decoration: none;
            transition: all 0.3s ease;
        }
        
        .receipt-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 3px 10px rgba(6, 214, 160, 0.3);
            color: white;
            text-decoration: none;
        }
        
        /* Extension Receipt Button */
        .extension-receipt-btn {
            background: linear-gradient(135deg, #4cc9f0, #4895ef);
            color: white;
            border: none;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.8rem;
            display: inline-flex;
            align-items: center;
            gap: 3px;
            text-decoration: none;
            transition: all 0.3s ease;
            margin-left: 5px;
        }
        
        .extension-receipt-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 3px 10px rgba(76, 201, 240, 0.3);
            color: white;
            text-decoration: none;
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
            
            .pagination-container {
                flex-direction: column;
                gap: 10px;
            }
            
            .table-responsive {
                font-size: 14px;
            }
            
            .tab-navigation {
                flex-direction: column;
            }
            
            .tab-btn {
                border-radius: 8px;
                text-align: left;
            }
            
            .tab-btn.active {
                border-bottom: none;
                border-left: 3px solid var(--primary-color);
            }
            
            .extension-header {
                flex-direction: column;
                gap: 10px;
            }
            
            .date-change {
                flex-direction: column;
                gap: 10px;
                text-align: center;
            }
            
            .detail-row {
                flex-direction: column;
                gap: 5px;
            }
            
            .detail-label {
                min-width: auto;
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
        
        /* Table Styles */
        .compact-table {
            font-size: 14px;
        }
        
        .compact-table th {
            font-weight: 600;
            color: #495057;
            background-color: #f8f9fa;
            padding: 12px 15px;
            border-bottom: 2px solid #dee2e6;
        }
        
        .compact-table td {
            padding: 12px 15px;
            vertical-align: middle;
        }
        
        .compact-table tr:hover {
            background-color: rgba(67, 97, 238, 0.05);
        }
        
        .book-info-cell {
            max-width: 300px;
        }
        
        .copy-details {
            font-size: 12px;
            color: #6c757d;
        }
        
        /* Navigation Buttons */
        .nav-buttons {
            display: flex;
            justify-content: space-between;
            margin-top: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 10px;
        }
        
        .page-indicator {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
            color: #495057;
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
        }
        
        /* Extension Sub-tabs */
        .sub-tabs {
            display: flex;
            gap: 5px;
            margin-bottom: 20px;
            background: white;
            padding: 5px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }
        
        .sub-tab-btn {
            flex: 1;
            padding: 10px 15px;
            background: none;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            color: #6c757d;
            transition: all 0.3s ease;
            text-align: center;
        }
        
        .sub-tab-btn:hover {
            background: rgba(67, 97, 238, 0.1);
            color: var(--primary-color);
        }
        
        .sub-tab-btn.active {
            background: var(--primary-color);
            color: white;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="page-header">
            <h2><i class="fas fa-book-open me-2"></i>Borrowed Books Management</h2>
            <p class="mb-0">Manage borrowed books by <strong>PHYSICAL COPIES</strong>, not just by book title. Shows ALL borrowed copies.</p>
        </div>

        <!-- Tab Navigation -->
        <div class="tab-navigation">
            <button class="tab-btn <?= $activeTab === 'active' ? 'active' : '' ?>" onclick="switchTab('active')">
                <i class="fas fa-book me-2"></i>Active Borrows
            </button>
            <button class="tab-btn <?= $activeTab === 'returned' ? 'active' : '' ?>" onclick="switchTab('returned')">
                <i class="fas fa-history me-2"></i>Return History
            </button>
            <button class="tab-btn <?= $activeTab === 'overdue' ? 'active' : '' ?>" onclick="switchTab('overdue')">
                <i class="fas fa-exclamation-triangle me-2"></i>Overdue Books
            </button>
            <button class="tab-btn" onclick="switchTab('extensions')" id="extensions-tab">
                <i class="fas fa-calendar-plus me-2"></i>Extension Requests
                <?php if (count($pendingExtensions) > 0): ?>
                    <span class="badge bg-danger rounded-pill"><?= count($pendingExtensions) ?></span>
                <?php endif; ?>
            </button>
        </div>

        <!-- Search & Filter Card -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-search me-2"></i>Search & Filter
            </div>
            <div class="card-body">
                <div class="filter-grid">
                    <div class="filter-group">
                        <label for="student_id"><i class="fas fa-id-card me-1"></i> Search by Student/Library ID</label>
                        <div class="search-box">
                            <input type="text" 
                                   id="student_id" 
                                   class="form-control" 
                                   placeholder="Enter Student ID or Library ID"
                                   value="<?= htmlspecialchars($student_id) ?>"
                                   style="border-radius: 8px; border: 2px solid #e9ecef;">
                            <button class="btn btn-primary" onclick="applyFilters()" style="border-radius: 8px;">
                                <i class="fas fa-search me-1"></i> Search
                            </button>
                            <?php if (!empty($student_id)): ?>
                                <button class="btn btn-secondary" onclick="clearFilters()" style="border-radius: 8px;">
                                    <i class="fas fa-times me-1"></i> Clear
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="filter-group">
                        <label for="status_filter"><i class="fas fa-filter me-1"></i> Filter by Status</label>
                        <select id="status_filter" class="form-control" onchange="applyFilters()" 
                                style="border-radius: 8px; border: 2px solid #e9ecef; padding: 10px;">
                            <option value="">All Status</option>
                            <option value="borrowed" <?= $status_filter === 'borrowed' ? 'selected' : '' ?>>Borrowed</option>
                            <option value="overdue" <?= $status_filter === 'overdue' ? 'selected' : '' ?>>Overdue</option>
                            <option value="returned" <?= $status_filter === 'returned' ? 'selected' : '' ?>>Returned</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <!-- Active Borrows Tab -->
        <div id="active-tab" class="tab-content <?= $activeTab === 'active' ? 'active' : '' ?>">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div>
                        <i class="fas fa-clipboard-list me-2"></i>Active Borrows (Borrowed + Overdue)
                        <small class="text-muted ms-2">Showing ALL physical copies</small>
                    </div>
                    <div>
                        <?php 
                        // Count borrowed and overdue items from the filtered results
                        $borrowedCount = 0;
                        $overdueCount = 0;
                        $activeBooks = array_filter($issued, function($item) {
                            return $item['status'] !== 'returned';
                        });
                        
                        foreach ($activeBooks as $item) {
                            if ($item['status'] === 'borrowed') {
                                $borrowedCount++;
                            } elseif ($item['status'] === 'overdue') {
                                $overdueCount++;
                            }
                        }
                        $totalActiveCount = $borrowedCount + $overdueCount;
                        ?>
                        <span class="badge bg-primary rounded-pill px-3 py-2">Total: <?= $totalActiveCount ?></span>
                        <?php if ($borrowedCount > 0): ?>
                            <span class="badge bg-info rounded-pill px-3 py-2">Borrowed: <?= $borrowedCount ?></span>
                        <?php endif; ?>
                        <?php if ($overdueCount > 0): ?>
                            <span class="badge bg-danger rounded-pill px-3 py-2">Overdue: <?= $overdueCount ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (empty($activeBooks)): ?>
                        <div class="empty-state">
                            <i class="fas fa-book-open fa-4x mb-4"></i>
                            <h3>No Active Borrows</h3>
                            <p class="mb-4">There are currently no borrowed books matching your criteria.</p>
                            <?php if (!empty($student_id)): ?>
                                <button class="btn btn-primary" onclick="clearFilters()">
                                    <i class="fas fa-times me-1"></i> Clear Search
                                </button>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover compact-table align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 30%;">Book & Copy Details</th>
                                        <th style="width: 15%;">Patron</th>
                                        <th style="width: 10%;">Borrow Date</th>
                                        <th style="width: 10%;">Due Date</th>
                                        <th style="width: 10%;">Status</th>
                                        <th style="width: 15%;">Overdue Days</th>
                                        <th style="width: 10%;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($activeBooks as $row): 
                                        $isOverdue = $row['status'] === 'overdue';
                                        $dueDate = new DateTime($row['due_date']);
                                        $today = new DateTime();
                                        $daysOverdue = $isOverdue ? $today->diff($dueDate)->days : 0;
                                        $overdueFee = $daysOverdue * 30;
                                        $coverImage = !empty($row['cover_image_cache']) ? 
                                            '../uploads/covers/' . $row['cover_image_cache'] : 
                                            '../assets/images/default-book.jpg';
                                        // Check if this borrow has extension receipts
                                        $hasExtensionReceipt = !empty($row['extension_receipt_pdf']);
                                        
                                        // Check for damage reports for this book copy
                                        $damageReports = [];
                                        if (!empty($row['book_copy_id'])) {
                                            $reportStmt = $pdo->prepare("
                                                SELECT * FROM lost_damaged_reports 
                                                WHERE book_copy_id = ? 
                                                AND report_type = 'damaged' 
                                                AND status = 'pending'
                                            ");
                                            $reportStmt->execute([$row['book_copy_id']]);
                                            $damageReports = $reportStmt->fetchAll(PDO::FETCH_ASSOC);
                                        }
                                        
                                        // Also check for damage types already stored in borrow_logs
                                        $existingDamageTypes = [];
                                        if (!empty($row['damage_types'])) {
                                            $damageData = json_decode($row['damage_types'], true);
                                            if (is_array($damageData) && !empty($damageData)) {
                                                $existingDamageTypes = $damageData;
                                            }
                                        }
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="book-cover-container me-3">
                                                    <img src="<?= $coverImage ?>" 
                                                         alt="<?= htmlspecialchars($row['book_name']) ?>" 
                                                         class="book-cover"
                                                         onerror="this.src='../assets/images/default-book.jpg'">
                                                </div>
                                                <div>
                                                    <strong class="d-block"><?= htmlspecialchars($row['book_name']) ?></strong>
                                                    <small class="text-muted"><?= htmlspecialchars($row['author']) ?></small>
                                                    <?php if (!empty($row['copy_number'])): ?>
                                                        <div class="copy-details mt-1">
                                                            <i class="fas fa-copy me-1"></i>Copy: <?= htmlspecialchars($row['copy_number']) ?>
                                                            <?php if (!empty($row['barcode'])): ?>
                                                                <br><i class="fas fa-barcode me-1"></i><?= htmlspecialchars($row['barcode']) ?>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    
                                                    <!-- Display damage reports if any -->
                                                    <?php if (!empty($damageReports) || !empty($existingDamageTypes)): ?>
                                                        <div class="damage-report-indicator mt-2">
                                                            <i class="fas fa-exclamation-triangle text-danger"></i>
                                                            <strong>Damage Reported</strong>
                                                            <div class="damage-report-list">
                                                                <?php foreach ($damageReports as $report): ?>
                                                                    <div class="damage-report-item">
                                                                        <span>Report #<?= $report['id'] ?></span>
                                                                        <span class="text-danger">Pending</span>
                                                                    </div>
                                                                <?php endforeach; ?>
                                                                <?php foreach ($existingDamageTypes as $damage): 
                                                                    $damageName = is_array($damage) ? ($damage['name'] ?? 'Unknown') : $damage;
                                                                    $damageFee = is_array($damage) ? ($damage['fee_amount'] ?? '0.00') : '0.00';
                                                                ?>
                                                                    <div class="damage-report-item">
                                                                        <span><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $damageName))) ?></span>
                                                                        <span class="text-warning">₱<?= number_format($damageFee, 2) ?></span>
                                                                    </div>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        </div>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($hasExtensionReceipt): ?>
                                                        <div class="mt-1">
                                                            <a href="<?= $row['extension_receipt_pdf'] ?>" 
                                                               target="_blank" 
                                                               class="extension-receipt-btn">
                                                                <i class="fas fa-receipt me-1"></i> Extension Receipt
                                                            </a>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <strong><?= htmlspecialchars($row['patron_name']) ?></strong><br>
                                            <small class="text-muted"><?= htmlspecialchars($row['library_id']) ?></small>
                                        </td>
                                        <td>
                                            <?= date('M d, Y', strtotime($row['borrowed_at'])) ?>
                                        </td>
                                        <td class="<?= $isOverdue ? 'text-danger fw-bold' : 'text-primary' ?>">
                                            <?= date('M d, Y', strtotime($row['due_date'])) ?>
                                            <?php if ($row['extension_attempts'] > 0): ?>
                                                <br><small class="text-success">Extended <?= $row['extension_attempts'] ?> time(s)</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($row['status'] === 'overdue'): ?>
                                                <span class="badge bg-danger rounded-pill px-3">Overdue</span>
                                            <?php else: ?>
                                                <span class="badge bg-primary rounded-pill px-3">Borrowed</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($isOverdue): ?>
                                                <div class="text-danger fw-bold">
                                                    <?= $daysOverdue ?> days<br>
                                                    <small class="text-muted">Fee: ₱<?= $overdueFee ?></small>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-success">On time</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn btn-sm btn-primary" 
                                                        onclick="showReturnModal(<?= $row['id'] ?>)">
                                                    <i class="fas fa-undo"></i> Return
                                                </button>
                                                <?php if ($row['extension_attempts'] < $max_extensions_per_book): ?>
                                                    <button class="btn btn-sm btn-warning" 
                                                            onclick="showExtensionModal(<?= $row['id'] ?>)">
                                                        <i class="fas fa-calendar-plus"></i> Extend
                                                    </button>
                                                <?php endif; ?>
                                                <button class="btn btn-sm btn-info" 
                                                        onclick="viewDetails(<?= $row['id'] ?>)">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <?php if ($isOverdue): ?>
                                                    <button class="btn btn-sm btn-warning" 
                                                            onclick="sendReminder(<?= $row['id'] ?>)">
                                                        <i class="fas fa-envelope"></i>
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
                        <div class="nav-buttons">
                            <div>
                                <?php if ($current_page > 1): ?>
                                    <a class="btn btn-outline-primary" 
                                       href="?filter=<?= $filter ?>&page=<?= $current_page - 1 ?>&student_id=<?= $student_id ?>&status=<?= $status_filter ?>">
                                        <i class="fas fa-chevron-left me-2"></i> Previous
                                    </a>
                                <?php endif; ?>
                            </div>
                            
                            <div class="page-indicator">
                                Page 
                                <span class="current-page"><?= $current_page ?></span>
                                of <?= $totalPages ?>
                                <span class="text-muted">(Total: <?= $totalCount ?> items)</span>
                            </div>
                            
                            <div>
                                <?php if ($current_page < $totalPages): ?>
                                    <a class="btn btn-outline-primary" 
                                       href="?filter=<?= $filter ?>&page=<?= $current_page + 1 ?>&student_id=<?= $student_id ?>&status=<?= $status_filter ?>">
                                        Next <i class="fas fa-chevron-right ms-2"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Return History Tab -->
<div id="returned-tab" class="tab-content <?= $activeTab === 'returned' ? 'active' : '' ?>">
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div>
                <i class="fas fa-history me-2"></i>Return History
            </div>
            <div>
                <?php 
                $returnedBooks = array_filter($issued, function($item) {
                    return $item['status'] === 'returned';
                });
                $returnedCount = count($returnedBooks);
                ?>
                <span class="badge bg-success rounded-pill px-3 py-2">Total Returns: <?= $returnedCount ?></span>
            </div>
        </div>
        <div class="card-body">
            <?php if (empty($returnedBooks)): ?>
                <div class="empty-state">
                    <i class="fas fa-history fa-4x mb-4"></i>
                    <h3>No Return History</h3>
                    <p>No books have been returned yet.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover compact-table align-middle">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 25%;">Book & Copy Details</th>
                                <th style="width: 15%;">Patron</th>
                                <th style="width: 10%;">Return Date</th>
                                <th style="width: 10%;">Condition</th>
                                <th style="width: 15%;">Fees Paid</th>
                                <th style="width: 10%;">Status</th>
                                <th style="width: 15%;">Receipt</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($returnedBooks as $row): 
                                $coverImage = !empty($row['cover_image_cache']) ? 
                                    '../uploads/covers/' . $row['cover_image_cache'] : 
                                    '../assets/images/default-book.jpg';
                                
                                // Get book price from books table
                                $bookPriceStmt = $pdo->prepare("SELECT price FROM books WHERE id = ?");
                                $bookPriceStmt->execute([$row['book_id']]);
                                $bookPrice = $bookPriceStmt->fetch(PDO::FETCH_ASSOC)['price'] ?? 0;
                                
                                // Calculate lost fee (150% of book price)
                                $lostFee = ($row['return_status'] === 'lost' || $row['return_condition'] === 'lost') ? 
                                    $bookPrice * 1.5 : 0;
                                
                                // Total fees including lost fee
                                $totalFees = ($row['late_fee'] ?? 0) + ($row['penalty_fee'] ?? 0) + $lostFee;
                                
                                // Check for extension receipts
                                $hasExtensionReceipt = !empty($row['extension_receipt_pdf']);
                            ?>
                            <tr id="row-<?= $row['id'] ?>">
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="book-cover-container me-3">
                                            <img src="<?= $coverImage ?>" 
                                                 alt="<?= htmlspecialchars($row['book_name']) ?>" 
                                                 class="book-cover"
                                                 onerror="this.src='../assets/images/default-book.jpg'">
                                        </div>
                                        <div>
                                            <strong class="d-block"><?= htmlspecialchars($row['book_name']) ?></strong>
                                            <small class="text-muted"><?= htmlspecialchars($row['author']) ?></small>
                                            <?php if (!empty($row['copy_number'])): ?>
                                                <div class="copy-details mt-1">
                                                    <i class="fas fa-copy me-1"></i>Copy: <?= htmlspecialchars($row['copy_number']) ?>
                                                    <?php if (!empty($row['barcode'])): ?>
                                                        <br><i class="fas fa-barcode me-1"></i><?= htmlspecialchars($row['barcode']) ?>
                                                    <?php endif; ?>
                                                    <?php if ($row['return_status'] === 'lost' || $row['return_condition'] === 'lost'): ?>
                                                        <br><small class="text-danger fw-bold"><i class="fas fa-exclamation-triangle me-1"></i>LOST BOOK</small>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($hasExtensionReceipt): ?>
                                                <div class="mt-1">
                                                    <a href="<?= $row['extension_receipt_pdf'] ?>" 
                                                       target="_blank" 
                                                       class="extension-receipt-btn">
                                                        <i class="fas fa-receipt me-1"></i> Extension Receipt
                                                    </a>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars($row['patron_name']) ?></strong><br>
                                    <small class="text-muted"><?= htmlspecialchars($row['library_id']) ?></small>
                                </td>
                                <td>
                                    <?= $row['returned_at'] ? date('M d, Y', strtotime($row['returned_at'])) : 'N/A' ?>
                                </td>
                                <td>
                                    <?php
                                    $condition = $row['return_condition'] ?? $row['return_book_condition'] ?? 'good';
                                    $conditionClass = 'bg-success';
                                    $conditionText = ucfirst($condition);
                                    
                                    if ($condition === 'damaged') {
                                        $conditionClass = 'bg-warning';
                                    } elseif ($condition === 'poor') {
                                        $conditionClass = 'bg-danger';
                                    } elseif ($condition === 'lost') {
                                        $conditionClass = 'bg-dark';
                                    }
                                    ?>
                                    <span class="badge <?= $conditionClass ?> rounded-pill px-3">
                                        <?= $conditionText ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($totalFees > 0): ?>
                                        <div class="fee-summary-mini">
                                            <?php if ($row['late_fee'] > 0): ?>
                                                <div class="fee-item-mini">
                                                    <small class="text-muted">Late:</small>
                                                    <span class="fee-badge fee-overdue">₱<?= number_format($row['late_fee'], 2) ?></span>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($row['penalty_fee'] > 0): ?>
                                                <div class="fee-item-mini">
                                                    <small class="text-muted">Damage:</small>
                                                    <span class="fee-badge fee-damage">₱<?= number_format($row['penalty_fee'], 2) ?></span>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($lostFee > 0): ?>
                                                <div class="fee-item-mini">
                                                    <small class="text-muted">Lost:</small>
                                                    <span class="fee-badge fee-lost">₱<?= number_format($lostFee, 2) ?></span>
                                                </div>
                                            <?php endif; ?>
                                            <div class="fee-item-mini fee-total-mini">
                                                <small class="text-muted">Total:</small>
                                                <strong>₱<?= number_format($totalFees, 2) ?></strong>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-success">No Fees</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $status = $row['return_status'] ?? 'available';
                                    $statusClass = 'bg-success';
                                    $statusText = ucfirst($status);
                                    
                                    if ($status === 'damaged') {
                                        $statusClass = 'bg-warning';
                                    } elseif ($status === 'lost') {
                                        $statusClass = 'bg-dark';
                                    } elseif ($status === 'maintenance') {
                                        $statusClass = 'bg-info';
                                    }
                                    ?>
                                    <span class="badge <?= $statusClass ?> rounded-pill px-3">
                                        <?= $statusText ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (!empty($row['return_receipt_pdf'])): ?>
                                        <div class="d-flex gap-2">
                                            <a href="<?= $row['return_receipt_pdf'] ?>" 
                                               target="_blank" 
                                               class="btn btn-sm btn-success">
                                                <i class="fas fa-receipt"></i> View
                                            </a>
                                            <button class="btn btn-sm btn-info" 
                                                    onclick="viewDetails(<?= $row['id'] ?>)">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <?php if (!empty($row['return_receipt_pdf'])): ?>
                                                <button class="btn btn-sm btn-warning" 
                                                        onclick="window.open('<?= $row['return_receipt_pdf'] ?>', '_blank')">
                                                    <i class="fas fa-print"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted">No receipt</span>
                                        <div class="mt-1">
                                            <button class="btn btn-sm btn-info" 
                                                    onclick="viewDetails(<?= $row['id'] ?>)">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                <div class="nav-buttons">
                    <div>
                        <?php if ($current_page > 1): ?>
                            <a class="btn btn-outline-primary" 
                               href="?filter=returned&page=<?= $current_page - 1 ?>&student_id=<?= $student_id ?>&status=<?= $status_filter ?>">
                                <i class="fas fa-chevron-left me-2"></i> Previous
                            </a>
                        <?php endif; ?>
                    </div>
                    
                    <div class="page-indicator">
                        Page 
                        <span class="current-page"><?= $current_page ?></span>
                        of <?= $totalPages ?>
                        <span class="text-muted">(Total: <?= $totalCount ?> items)</span>
                    </div>
                    
                    <div>
                        <?php if ($current_page < $totalPages): ?>
                            <a class="btn btn-outline-primary" 
                               href="?filter=returned&page=<?= $current_page + 1 ?>&student_id=<?= $student_id ?>&status=<?= $status_filter ?>">
                                Next <i class="fas fa-chevron-right ms-2"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

        <!-- Overdue Books Tab -->
        <div id="overdue-tab" class="tab-content <?= $activeTab === 'overdue' ? 'active' : '' ?>">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div>
                        <i class="fas fa-exclamation-triangle me-2"></i>Overdue Books
                    </div>
                    <div>
                        <?php 
                        $overdueBooks = array_filter($issued, function($item) {
                            return $item['status'] === 'overdue';
                        });
                        $overdueCount = count($overdueBooks);
                        ?>
                        <span class="badge bg-danger rounded-pill px-3 py-2">Overdue: <?= $overdueCount ?></span>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (empty($overdueBooks)): ?>
                        <div class="empty-state">
                            <i class="fas fa-check-circle fa-4x mb-4 text-success"></i>
                            <h3>No Overdue Books</h3>
                            <p>Great! All books are returned on time.</p>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-circle me-2"></i>
                            <strong>Warning:</strong> There are <?= $overdueCount ?> overdue book copy(s) that need attention.
                        </div>
                        
                        <div class="table-responsive">
                            <table class="table table-hover compact-table align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 30%;">Book & Copy Details</th>
                                        <th style="width: 15%;">Patron</th>
                                        <th style="width: 10%;">Due Date</th>
                                        <th style="width: 10%;">Days Overdue</th>
                                        <th style="width: 15%;">Overdue Fee</th>
                                        <th style="width: 10%;">Status</th>
                                        <th style="width: 10%;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($overdueBooks as $row): 
                                        $dueDate = new DateTime($row['due_date']);
                                        $today = new DateTime();
                                        $daysOverdue = $today->diff($dueDate)->days;
                                        $overdueFee = $daysOverdue * 30;
                                        $coverImage = !empty($row['cover_image_cache']) ? 
                                            '../uploads/covers/' . $row['cover_image_cache'] : 
                                            '../assets/images/default-book.jpg';
                                        // Check if this borrow has extension receipts
                                        $hasExtensionReceipt = !empty($row['extension_receipt_pdf']);
                                        
                                        // Check for damage reports for this book copy
                                        $damageReports = [];
                                        if (!empty($row['book_copy_id'])) {
                                            $reportStmt = $pdo->prepare("
                                                SELECT * FROM lost_damaged_reports 
                                                WHERE book_copy_id = ? 
                                                AND report_type = 'damaged' 
                                                AND status = 'pending'
                                            ");
                                            $reportStmt->execute([$row['book_copy_id']]);
                                            $damageReports = $reportStmt->fetchAll(PDO::FETCH_ASSOC);
                                        }
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="book-cover-container me-3">
                                                    <img src="<?= $coverImage ?>" 
                                                         alt="<?= htmlspecialchars($row['book_name']) ?>" 
                                                         class="book-cover"
                                                         onerror="this.src='../assets/images/default-book.jpg'">
                                                </div>
                                                <div>
                                                    <strong class="d-block"><?= htmlspecialchars($row['book_name']) ?></strong>
                                                    <small class="text-muted"><?= htmlspecialchars($row['author']) ?></small>
                                                    <?php if (!empty($row['copy_number'])): ?>
                                                        <div class="copy-details mt-1">
                                                            <i class="fas fa-copy me-1"></i>Copy: <?= htmlspecialchars($row['copy_number']) ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    
                                                    <!-- Display damage reports if any -->
                                                    <?php if (!empty($damageReports)): ?>
                                                        <div class="damage-report-indicator mt-2">
                                                            <i class="fas fa-exclamation-triangle text-danger"></i>
                                                            <strong>Damage Reported</strong>
                                                            <div class="damage-report-list">
                                                                <?php foreach ($damageReports as $report): ?>
                                                                    <div class="damage-report-item">
                                                                        <span>Report #<?= $report['id'] ?></span>
                                                                        <span class="text-danger">Pending</span>
                                                                    </div>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        </div>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($hasExtensionReceipt): ?>
                                                        <div class="mt-1">
                                                            <a href="<?= $row['extension_receipt_pdf'] ?>" 
                                                               target="_blank" 
                                                               class="extension-receipt-btn">
                                                                <i class="fas fa-receipt me-1"></i> Extension Receipt
                                                            </a>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <strong><?= htmlspecialchars($row['patron_name']) ?></strong><br>
                                            <small class="text-muted"><?= htmlspecialchars($row['library_id']) ?></small>
                                        </td>
                                        <td class="text-danger fw-bold">
                                            <?= date('M d, Y', strtotime($row['due_date'])) ?>
                                        </td>
                                        <td class="text-danger fw-bold">
                                            <?= $daysOverdue ?> days
                                        </td>
                                        <td>
                                            <span class="fee-badge fee-overdue">
                                                ₱<?= $overdueFee ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-danger rounded-pill px-3">Overdue</span>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn btn-sm btn-primary" 
                                                        onclick="showReturnModal(<?= $row['id'] ?>)">
                                                    <i class="fas fa-undo"></i> Return
                                                </button>
                                                <button class="btn btn-sm btn-warning" 
                                                        onclick="sendReminder(<?= $row['id'] ?>)">
                                                    <i class="fas fa-envelope"></i>
                                                </button>
                                                <button class="btn btn-sm btn-info" 
                                                        onclick="viewDetails(<?= $row['id'] ?>)">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Pagination -->
                        <?php if ($totalPages > 1): ?>
                        <div class="nav-buttons">
                            <div>
                                <?php if ($current_page > 1): ?>
                                    <a class="btn btn-outline-primary" 
                                       href="?filter=overdue&page=<?= $current_page - 1 ?>&student_id=<?= $student_id ?>&status=<?= $status_filter ?>">
                                        <i class="fas fa-chevron-left me-2"></i> Previous
                                    </a>
                                <?php endif; ?>
                            </div>
                            
                            <div class="page-indicator">
                                Page 
                                <span class="current-page"><?= $current_page ?></span>
                                of <?= $totalPages ?>
                                <span class="text-muted">(Total: <?= $totalCount ?> items)</span>
                            </div>
                            
                            <div>
                                <?php if ($current_page < $totalPages): ?>
                                    <a class="btn btn-outline-primary" 
                                       href="?filter=overdue&page=<?= $current_page + 1 ?>&student_id=<?= $student_id ?>&status=<?= $status_filter ?>">
                                        Next <i class="fas fa-chevron-right ms-2"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Extension Requests Tab -->
        <div id="extensions-tab-content" class="tab-content">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div>
                        <i class="fas fa-calendar-plus me-2"></i>Extension Requests
                        <small class="text-muted ms-2">Approve or reject book extension requests</small>
                    </div>
                    <div>
                        <span class="badge bg-warning rounded-pill px-3 py-2">
                            Pending: <?= count($pendingExtensions) ?>
                        </span>
                        <span class="badge bg-info rounded-pill px-3 py-2 ms-2">
                            History: <?= count($extensionHistory) ?>
                        </span>
                    </div>
                </div>
                <div class="card-body">
                    <!-- Sub-tabs for Extensions -->
                    <div class="sub-tabs mb-4">
                        <button class="sub-tab-btn active" onclick="switchExtensionTab('pending')">
                            <i class="fas fa-clock me-1"></i> Pending Requests
                            <?php if (count($pendingExtensions) > 0): ?>
                                <span class="badge bg-danger rounded-pill ms-1"><?= count($pendingExtensions) ?></span>
                            <?php endif; ?>
                        </button>
                        <button class="sub-tab-btn" onclick="switchExtensionTab('history')">
                            <i class="fas fa-history me-1"></i> Extension History
                            <?php if (count($extensionHistory) > 0): ?>
                                <span class="badge bg-info rounded-pill ms-1"><?= count($extensionHistory) ?></span>
                            <?php endif; ?>
                        </button>
                    </div>
                    
                    <!-- Pending Requests Section -->
                    <div id="pending-extensions-section" class="extension-section">
                        <?php if (empty($pendingExtensions)): ?>
                            <div class="empty-state">
                                <i class="fas fa-check-circle fa-4x mb-4 text-success"></i>
                                <h3>No Pending Extension Requests</h3>
                                <p>All extension requests have been processed.</p>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                <strong>Note:</strong> Extension fee is ₱<?= $extension_fee_per_day ?> per day. 
                                Maximum <?= $max_extensions_per_book ?> extensions per book.
                            </div>
                            
                            <?php foreach ($pendingExtensions as $extension): 
                                $currentDueDate = new DateTime($extension['current_due_date']);
                                $requestedDate = new DateTime($extension['requested_extension_date']);
                                $daysExtension = $extension['extension_days'];
                                $extensionFee = $extension['extension_fee'];
                                $currentDue = new DateTime($extension['current_due_date']);
                            ?>
                            <div class="extension-card pending" id="extension-<?= $extension['id'] ?>">
                                <div class="extension-header">
                                    <div>
                                        <div class="extension-title"><?= htmlspecialchars($extension['book_title']) ?></div>
                                        <div class="text-muted mb-2">by <?= htmlspecialchars($extension['author']) ?></div>
                                        <div class="extension-meta">
                                            <div class="meta-item">
                                                <i class="fas fa-user"></i>
                                                <?= htmlspecialchars($extension['patron_name']) ?> (<?= htmlspecialchars($extension['library_id']) ?>)
                                            </div>
                                            <div class="meta-item">
                                                <i class="fas fa-copy"></i>
                                                Copy: <?= htmlspecialchars($extension['copy_number']) ?>
                                            </div>
                                            <div class="meta-item">
                                                <i class="fas fa-barcode"></i>
                                                <?= htmlspecialchars($extension['barcode']) ?>
                                            </div>
                                            <div class="meta-item">
                                                <i class="fas fa-calendar-day"></i>
                                                Requested: <?= date('M d, Y', strtotime($extension['created_at'])) ?>
                                            </div>
                                            <div class="meta-item">
                                                <i class="fas fa-clock"></i>
                                                Current Due: <?= $currentDue->format('M d, Y') ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="text-end">
                                        <span class="status-badge status-pending mb-2">Pending</span>
                                        <div class="fee-badge fee-extension">Fee: ₱<?= number_format($extensionFee, 2) ?></div>
                                    </div>
                                </div>
                                
                                <div class="date-change">
                                    <div class="date-from">
                                        <span class="date-label">Current Due Date</span>
                                        <span class="date-value"><?= $currentDueDate->format('M d, Y') ?></span>
                                    </div>
                                    <div class="arrow-icon">
                                        <i class="fas fa-arrow-right"></i>
                                    </div>
                                    <div class="date-to">
                                        <span class="date-label">Requested Extension</span>
                                        <span class="date-value"><?= $requestedDate->format('M d, Y') ?></span>
                                    </div>
                                </div>
                                
                                <div class="extension-details">
                                    <div class="detail-row">
                                        <div class="detail-label">Extension Days:</div>
                                        <div class="detail-value"><?= $daysExtension ?> days</div>
                                    </div>
                                    <div class="detail-row">
                                        <div class="detail-label">Reason:</div>
                                        <div class="detail-value"><?= nl2br(htmlspecialchars($extension['reason'] ?? 'No reason provided')) ?></div>
                                    </div>
                                </div>
                                
                                <div class="action-buttons mt-3">
                                    <button class="btn btn-success" 
                                            onclick="approveExtension(<?= $extension['id'] ?>)">
                                        <i class="fas fa-check me-2"></i> Approve & Generate Receipt
                                    </button>
                                    <button class="btn btn-danger" 
                                            onclick="rejectExtension(<?= $extension['id'] ?>)">
                                        <i class="fas fa-times me-2"></i> Reject Request
                                    </button>
                                    <button class="btn btn-info" 
                                            onclick="viewExtensionDetails(<?= $extension['id'] ?>)">
                                        <i class="fas fa-eye me-2"></i> View Details
                                    </button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Extension History Section -->
                    <div id="history-extensions-section" class="extension-section" style="display: none;">
                        <?php if (empty($extensionHistory)): ?>
                            <div class="empty-state">
                                <i class="fas fa-history fa-4x mb-4"></i>
                                <h3>No Extension History</h3>
                                <p>No extension requests have been processed yet.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="history-table">
                                    <thead>
                                        <tr>
                                            <th>Book & Copy</th>
                                            <th>Patron</th>
                                            <th>Extension</th>
                                            <th>Fee</th>
                                            <th>Status</th>
                                            <th>Processed</th>
                                            <th>Receipt</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($extensionHistory as $extension): 
                                            $currentDueDate = new DateTime($extension['current_due_date']);
                                            $requestedDate = new DateTime($extension['requested_extension_date']);
                                            $daysExtension = $extension['extension_days'];
                                            $extensionFee = $extension['extension_fee'];
                                            $statusClass = $extension['status'] === 'approved' ? 'status-approved' : 'status-rejected';
                                            $originalDue = new DateTime($extension['original_due_date']);
                                        ?>
                                        <tr>
                                            <td>
                                                <strong><?= htmlspecialchars($extension['book_title']) ?></strong><br>
                                                <small class="text-muted">Copy: <?= htmlspecialchars($extension['copy_number']) ?></small>
                                            </td>
                                            <td>
                                                <?= htmlspecialchars($extension['patron_name']) ?><br>
                                                <small class="text-muted"><?= htmlspecialchars($extension['library_id']) ?></small>
                                            </td>
                                            <td>
                                                <small class="text-muted"><?= $daysExtension ?> days</small><br>
                                                <div class="date-change small">
                                                    <span class="date-from">
                                                        <small><?= $originalDue->format('M d') ?></small>
                                                    </span>
                                                    <i class="fas fa-arrow-right text-primary"></i>
                                                    <span class="date-to">
                                                        <small><?= $requestedDate->format('M d') ?></small>
                                                    </span>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="fee-badge fee-extension">₱<?= number_format($extensionFee, 2) ?></span>
                                            </td>
                                            <td>
                                                <span class="status-badge <?= $statusClass ?>">
                                                    <?= ucfirst($extension['status']) ?>
                                                </span>
                                                <?php if ($extension['approved_by_name']): ?>
                                                    <br><small>by <?= htmlspecialchars($extension['approved_by_name']) ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?= $extension['approved_at'] ? date('M d, Y H:i', strtotime($extension['approved_at'])) : date('M d, Y H:i', strtotime($extension['updated_at'])) ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($extension['receipt_pdf'])): ?>
                                                    <a href="<?= $extension['receipt_pdf'] ?>" 
                                                       target="_blank" 
                                                       class="receipt-btn">
                                                        <i class="fas fa-receipt"></i> View
                                                    </a>
                                                <?php else: ?>
                                                    <span class="text-muted">No receipt</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button class="btn btn-sm btn-info" 
                                                            onclick="viewExtensionDetails(<?= $extension['id'] ?>)">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <?php if (!empty($extension['receipt_pdf'])): ?>
                                                        <a href="<?= $extension['receipt_pdf'] ?>" 
                                                           target="_blank" 
                                                           class="btn btn-sm btn-success">
                                                            <i class="fas fa-print"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Return Modal -->
    <div id="returnModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-undo me-2"></i>Return Book (Physical Copy)</h3>
                <button class="close-modal" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div id="modalContent">
                    <!-- Dynamic content loaded via JavaScript -->
                </div>
            </div>
        </div>
    </div>

    <!-- Extension Modal -->
    <div id="extensionModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-calendar-plus me-2"></i>Request Book Extension</h3>
                <button class="close-modal" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div id="extensionContent">
                    <!-- Dynamic content loaded via JavaScript -->
                </div>
            </div>
        </div>
    </div>

    <!-- Extension Details Modal -->
    <div id="extensionDetailsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-calendar-alt me-2"></i>Extension Request Details</h3>
                <button class="close-modal" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div id="extensionDetailsContent">
                    <!-- Dynamic content loaded via JavaScript -->
                </div>
            </div>
        </div>
    </div>

    <!-- Details Modal -->
    <div id="detailsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-info-circle me-2"></i>Borrow Details</h3>
                <button class="close-modal" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div id="detailsContent">
                    <!-- Dynamic content loaded via JavaScript -->
                </div>
            </div>
        </div>
    </div>

    <!-- Copy Info Modal -->
    <div id="copyInfoModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-copy me-2"></i>Physical Copy Information</h3>
                <button class="close-modal" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div id="copyInfoContent">
                    <!-- Dynamic content loaded via JavaScript -->
                </div>
            </div>
        </div>
    </div>

   <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
let currentBorrowId = null;
let currentExtensionId = null;
const overdueFeePerDay = 30;
const extensionFeePerDay = <?= $extension_fee_per_day ?>;
const maxExtensionsPerBook = <?= $max_extensions_per_book ?>;
const maxExtensionDays = <?= $extension_max_days ?>;

function switchTab(tabName) {
    if (tabName === 'extensions') {
        // Show extensions tab
        document.querySelectorAll('.tab-content').forEach(tab => {
            tab.classList.remove('active');
        });
        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.classList.remove('active');
        });
        
        document.getElementById('extensions-tab').classList.add('active');
        document.getElementById('extensions-tab-content').classList.add('active');
        
        // Show pending extensions by default
        switchExtensionTab('pending');
        return;
    }
    
    let url = new URL(window.location);
    url.searchParams.set('filter', tabName);
    url.searchParams.delete('page');
    window.location.href = url.toString();
}

function switchExtensionTab(tabName) {
    document.querySelectorAll('.extension-section').forEach(section => {
        section.style.display = 'none';
    });
    document.querySelectorAll('.sub-tab-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    
    if (tabName === 'pending') {
        document.getElementById('pending-extensions-section').style.display = 'block';
        document.querySelector('.sub-tab-btn:nth-child(1)').classList.add('active');
    } else {
        document.getElementById('history-extensions-section').style.display = 'block';
        document.querySelector('.sub-tab-btn:nth-child(2)').classList.add('active');
    }
}

function applyFilters() {
    const studentId = document.getElementById('student_id').value;
    const statusFilter = document.getElementById('status_filter').value;
    const currentFilter = '<?= $filter ?>';
    
    let url = new URL(window.location);
    url.searchParams.set('filter', currentFilter);
    url.searchParams.delete('page');
    
    if (studentId) {
        url.searchParams.set('student_id', studentId);
    } else {
        url.searchParams.delete('student_id');
    }
    
    if (statusFilter) {
        url.searchParams.set('status', statusFilter);
    } else {
        url.searchParams.delete('status');
    }
    
    window.location.href = url.toString();
}

function clearFilters() {
    let url = new URL(window.location);
    url.searchParams.delete('student_id');
    url.searchParams.delete('status');
    url.searchParams.delete('page');
    window.location.href = url.toString();
}

function showReturnModal(borrowId) {
    currentBorrowId = borrowId;
    
    fetch(`../api/get_borrow_details.php?id=${borrowId}`)
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                showToast(data.message || 'Error loading data', 'error');
                return;
            }
            
            const borrow = data.data;
            const dueDate = new Date(borrow.due_date);
            const today = new Date();
            const daysOverdue = Math.max(0, Math.floor((today - dueDate) / (1000 * 60 * 60 * 24)));
            const overdueFee = daysOverdue * overdueFeePerDay;
            const bookPrice = parseFloat(borrow.book_price) || 0;
            const lostFee = bookPrice * 1.5; // 150% of book price for lost books
            
            // Get damage reports for this borrow
            fetch(`../api/get_damage_reports.php?borrow_id=${borrowId}`)
                .then(response => response.json())
                .then(reportData => {
                    let modalHTML = `
                        <div class="return-form-container">
                            <div class="book-info-card">
                                <div class="book-cover-container">
                                    <img src="${borrow.cover_image || '../assets/images/default-book.jpg'}" 
                                         alt="${borrow.book_name}" 
                                         class="book-cover"
                                         onerror="this.src='../assets/images/default-book.jpg'">
                                </div>
                                <div class="book-details">
                                    <h4>${borrow.book_name}</h4>
                                    <p><strong><i class="fas fa-user-edit me-1"></i>Author:</strong> ${borrow.author}</p>
                                    <p><strong><i class="fas fa-user me-1"></i>Patron:</strong> ${borrow.patron_name} (${borrow.library_id})</p>
                                    <p><strong><i class="fas fa-calendar-day me-1"></i>Due Date:</strong> ${new Date(borrow.due_date).toLocaleDateString()}</p>
                                    <p><strong><i class="fas fa-tag me-1"></i>Book Price:</strong> ₱${bookPrice.toFixed(2)}</p>
                                    ${borrow.copy_number ? `
                                        <div class="copy-info mt-2">
                                            <strong><i class="fas fa-copy me-1"></i>Physical Copy:</strong> ${borrow.copy_number}<br>
                                            ${borrow.barcode ? `<strong><i class="fas fa-barcode me-1"></i>Barcode:</strong> ${borrow.barcode}` : ''}
                                        </div>
                                    ` : ''}
                                    ${borrow.extension_attempts > 0 ? `
                                        <div class="alert alert-info mt-2">
                                            <i class="fas fa-history me-1"></i>
                                            This book has been extended ${borrow.extension_attempts} time(s)
                                        </div>
                                    ` : ''}
                                    ${daysOverdue > 0 ? `
                                        <div class="overdue-warning mt-2">
                                            <i class="fas fa-exclamation-triangle"></i>
                                            <strong>${daysOverdue} days overdue</strong>
                                        </div>
                                    ` : ''}
                                    ${borrow.lost_status === 'presumed_lost' || borrow.lost_status === 'confirmed_lost' ? `
                                        <div class="alert alert-danger mt-2">
                                            <i class="fas fa-exclamation-circle"></i>
                                            <strong>Book marked as ${borrow.lost_status.replace('_', ' ')}</strong><br>
                                            Lost Fee: ₱${borrow.lost_fee ? parseFloat(borrow.lost_fee).toFixed(2) : lostFee.toFixed(2)}
                                        </div>
                                    ` : ''}
                                </div>
                            </div>
                            
                            <!-- Display existing damage reports if any -->
                            ${reportData.success && reportData.reports.length > 0 ? `
                                <div class="alert alert-warning">
                                    <i class="fas fa-exclamation-triangle me-2"></i>
                                    <strong>Damage Reports Found:</strong>
                                    <div class="damage-report-list mt-2">
                                        ${reportData.reports.map(report => `
                                            <div class="damage-report-item">
                                                <span>Report #${report.id} (${report.severity})</span>
                                                <span class="text-danger">₱${parseFloat(report.fee_charged || 0).toFixed(2)}</span>
                                            </div>
                                        `).join('')}
                                    </div>
                                </div>
                            ` : ''}
                            
                            <h4><i class="fas fa-tools me-2"></i>Damage Assessment</h4>
                            <div class="damage-checkboxes" id="damageCheckboxes">
                    `;
                    
                    // Add damage checkboxes - pre-check if damage was reported
                    <?php foreach ($damageTypes as $type): ?>
                        modalHTML += `
                            <div class="damage-checkbox">
                                <input type="checkbox" 
                                       id="damage_${borrowId}_<?= $type['id'] ?>" 
                                       name="damage_types[]" 
                                       value="<?= $type['name'] ?>"
                                       data-fee="<?= $type['fee_amount'] ?>"
                                       class="damage-checkbox-input"
                                       onchange="updateFeeSummary()"
                                       ${reportData.success && reportData.damageTypes && reportData.damageTypes.includes('<?= $type['name'] ?>') ? 'checked' : ''}>
                                <label for="damage_${borrowId}_<?= $type['id'] ?>" class="damage-label">
                                    <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $type['name']))) ?>
                                </label>
                                <span class="damage-fee">₱<?= number_format($type['fee_amount'], 2) ?></span>
                            </div>
                        `;
                    <?php endforeach; ?>
                    
                    modalHTML += `
                            </div>
                            
                            <div class="form-group mb-4">
                                <label for="damageDescription" class="form-label">
                                    <i class="fas fa-file-alt me-1"></i>Damage Description
                                </label>
                                <textarea id="damageDescription" class="form-control" rows="3" 
                                          placeholder="Describe any damage to the book...">${reportData.success && reportData.reports.length > 0 ? reportData.reports[0].description || '' : ''}</textarea>
                            </div>
                            
                            <div class="form-group mb-4">
                                <label for="returnCondition" class="form-label">
                                    <i class="fas fa-clipboard-check me-1"></i>Return Condition
                                </label>
                                <select id="returnCondition" class="form-control" onchange="updateCopyStatus()">
                                    <option value="good">Good - No damage</option>
                                    <option value="fair">Fair - Minor wear</option>
                                    <option value="poor">Poor - Significant wear (still available)</option>
                                    <option value="damaged" ${reportData.success && reportData.reports.length > 0 ? 'selected' : ''}>Damaged - Needs repair (not available)</option>
                                    <option value="lost">Lost - Book is lost (150% of book price)</option>
                                </select>
                                <small class="text-muted">Note: Lost condition charges 150% of book price (₱${lostFee.toFixed(2)})</small>
                            </div>
                            
                            <div id="copyStatusInfo" class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                Book copy will be marked as <strong id="statusText">${reportData.success && reportData.reports.length > 0 ? 'damaged' : 'available'}</strong> after return.
                            </div>
                            
                            <div class="fee-summary">
                                <h5><i class="fas fa-money-bill-wave me-2"></i>Fee Summary</h5>
                                <div class="fee-item">
                                    <span>Overdue Days:</span>
                                    <span id="overdueDays">${daysOverdue}</span>
                                </div>
                                <div class="fee-item">
                                    <span>Overdue Fee (₱${overdueFeePerDay}/day):</span>
                                    <span id="overdueFee">₱${overdueFee.toFixed(2)}</span>
                                </div>
                                <div class="fee-item">
                                    <span>Damage Fees:</span>
                                    <span id="damageFee">₱${reportData.success && reportData.reports.length > 0 ? parseFloat(reportData.reports[0].fee_charged || 0).toFixed(2) : '0.00'}</span>
                                </div>
                                <div class="fee-item">
                                    <span>Lost Book Fee (150% of price):</span>
                                    <span id="lostFee">₱0.00</span>
                                </div>
                                <div class="fee-item fee-total">
                                    <span>Total Amount:</span>
                                    <span id="totalFee">₱${(overdueFee + (reportData.success && reportData.reports.length > 0 ? parseFloat(reportData.reports[0].fee_charged || 0) : 0)).toFixed(2)}</span>
                                </div>
                            </div>
                            
                            <div class="d-flex gap-3 mt-4">
                                <button class="btn btn-primary flex-fill" onclick="processReturn()">
                                    <i class="fas fa-check me-2"></i> Process Return & Generate Receipt
                                </button>
                                <button class="btn btn-secondary" onclick="closeModal()">
                                    <i class="fas fa-times me-2"></i> Cancel
                                </button>
                            </div>
                        </div>
                    `;
                    
                    document.getElementById('modalContent').innerHTML = modalHTML;
                    document.getElementById('returnModal').style.display = 'block';
                    
                    // Store book price and lost fee for calculations
                    document.getElementById('modalContent').dataset.bookPrice = bookPrice;
                    document.getElementById('modalContent').dataset.lostFee = lostFee;
                    
                    // Initial status update
                    updateCopyStatus();
                })
                .catch(error => {
                    console.error('Error loading damage reports:', error);
                    // Continue without damage report data
                });
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('Error loading borrow details', 'error');
        });
}

function showExtensionModal(borrowId) {
    currentBorrowId = borrowId;
    
    fetch(`../api/get_borrow_details.php?id=${borrowId}`)
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                showToast(data.message || 'Error loading data', 'error');
                return;
            }
            
            const borrow = data.data;
            const dueDate = new Date(borrow.due_date);
            const today = new Date();
            const maxExtensionDate = new Date(dueDate);
            maxExtensionDate.setDate(maxExtensionDate.getDate() + maxExtensionDays);
            
            let modalHTML = `
                <div class="return-form-container">
                    <div class="book-info-card">
                        <div class="book-cover-container">
                            <img src="${borrow.cover_image || '../assets/images/default-book.jpg'}" 
                                 alt="${borrow.book_name}" 
                                 class="book-cover"
                                 onerror="this.src='../assets/images/default-book.jpg'">
                        </div>
                        <div class="book-details">
                            <h4>${borrow.book_name}</h4>
                            <p><strong><i class="fas fa-user-edit me-1"></i>Author:</strong> ${borrow.author}</p>
                            <p><strong><i class="fas fa-user me-1"></i>Patron:</strong> ${borrow.patron_name} (${borrow.library_id})</p>
                            <p><strong><i class="fas fa-calendar-day me-1"></i>Current Due Date:</strong> 
                                <span class="text-primary fw-bold">${dueDate.toLocaleDateString()}</span>
                            </p>
                            ${borrow.copy_number ? `
                                <div class="copy-info mt-2">
                                    <strong><i class="fas fa-copy me-1"></i>Physical Copy:</strong> ${borrow.copy_number}<br>
                                    ${borrow.barcode ? `<strong><i class="fas fa-barcode me-1"></i>Barcode:</strong> ${borrow.barcode}` : ''}
                                </div>
                            ` : ''}
                            ${borrow.extension_attempts > 0 ? `
                                <div class="alert alert-info mt-2">
                                    <i class="fas fa-history me-1"></i>
                                    This book has been extended ${borrow.extension_attempts} time(s). 
                                    Maximum ${maxExtensionsPerBook} extensions allowed.
                                </div>
                            ` : ''}
                        </div>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Extension Rules:</strong> 
                        Fee is ₱${extensionFeePerDay} per day. 
                        Maximum ${maxExtensionDays} days extension.
                    </div>
                    
                    <div class="form-group mb-4">
                        <label for="extensionDays" class="form-label">
                            <i class="fas fa-calendar-plus me-1"></i>Extension Days
                        </label>
                        <input type="number" 
                               id="extensionDays" 
                               class="form-control" 
                               min="1" 
                               max="${maxExtensionDays}"
                               value="7"
                               onchange="calculateExtensionFee()"
                               style="border-radius: 8px; border: 2px solid #e9ecef;">
                        <small class="text-muted">Maximum ${maxExtensionDays} days extension allowed</small>
                    </div>
                    
                    <div class="form-group mb-4">
                        <label for="extensionReason" class="form-label">
                            <i class="fas fa-comment me-1"></i>Reason for Extension
                        </label>
                        <textarea id="extensionReason" 
                                  class="form-control" 
                                  rows="3" 
                                  placeholder="Please provide a reason for the extension..."
                                  style="border-radius: 8px; border: 2px solid #e9ecef;"></textarea>
                    </div>
                    
                    <div class="fee-summary">
                        <h5><i class="fas fa-money-bill-wave me-2"></i>Extension Fee Summary</h5>
                        <div class="fee-item">
                            <span>Extension Days:</span>
                            <span id="extensionDaysDisplay">7</span>
                        </div>
                        <div class="fee-item">
                            <span>Fee per Day:</span>
                            <span>₱${extensionFeePerDay.toFixed(2)}</span>
                        </div>
                        <div class="fee-item fee-total">
                            <span>Total Extension Fee:</span>
                            <span id="totalExtensionFee">₱${(7 * extensionFeePerDay).toFixed(2)}</span>
                        </div>
                    </div>
                    
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>Note:</strong> Extension approval will generate a receipt that must be presented to the cashier for payment.
                    </div>
                    
                    <div class="d-flex gap-3 mt-4">
                        <button class="btn btn-success flex-fill" onclick="submitExtensionRequest()">
                            <i class="fas fa-calendar-check me-2"></i> Submit Extension Request
                        </button>
                        <button class="btn btn-secondary" onclick="closeModal()">
                            <i class="fas fa-times me-2"></i> Cancel
                        </button>
                    </div>
                </div>
            `;
            
            document.getElementById('extensionContent').innerHTML = modalHTML;
            document.getElementById('extensionModal').style.display = 'block';
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('Error loading borrow details', 'error');
        });
}

function calculateExtensionFee() {
    const extensionDays = parseInt(document.getElementById('extensionDays').value) || 1;
    const maxDays = Math.min(extensionDays, maxExtensionDays);
    document.getElementById('extensionDays').value = maxDays;
    document.getElementById('extensionDaysDisplay').textContent = maxDays;
    
    const totalFee = maxDays * extensionFeePerDay;
    document.getElementById('totalExtensionFee').textContent = `₱${totalFee.toFixed(2)}`;
}

function submitExtensionRequest() {
    if (!currentBorrowId) return;
    
    const extensionDays = parseInt(document.getElementById('extensionDays').value) || 1;
    const extensionReason = document.getElementById('extensionReason').value;
    
    if (extensionDays < 1) {
        showToast('Please enter a valid number of extension days', 'error');
        return;
    }
    
    if (!extensionReason.trim()) {
        showToast('Please provide a reason for the extension', 'error');
        return;
    }
    
    const formData = new FormData();
    formData.append('borrow_id', currentBorrowId);
    formData.append('extension_days', extensionDays);
    formData.append('reason', extensionReason);
    
    // Show loading state
    const submitBtn = document.querySelector('.btn-success');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<div class="loading-spinner"></div> Submitting...';
    submitBtn.disabled = true;
    
    fetch('../api/submit_extension_request.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast('Extension request submitted successfully!', 'success');
            closeModal();
            setTimeout(() => location.reload(), 1500);
        } else {
            showToast(data.message || 'Error submitting extension request', 'error');
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('Error submitting extension request', 'error');
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    });
}

function approveExtension(extensionId) {
    if (!confirm('Approve this extension request and generate receipt?')) return;
    
    const adminNotes = prompt('Enter any admin notes (optional):', '');
    
    const formData = new FormData();
    formData.append('extension_id', extensionId);
    formData.append('action', 'approve');
    if (adminNotes !== null) {
        formData.append('admin_notes', adminNotes);
    }
    
    // Show loading state
    const card = document.getElementById(`extension-${extensionId}`);
    const originalContent = card.innerHTML;
    card.innerHTML = '<div class="text-center p-4"><div class="loading-spinner" style="border-top-color: #06d6a0;"></div><p>Processing approval...</p></div>';
    
    fetch('../api/process_extension.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast('Extension approved successfully! Receipt generated.', 'success');
            if (data.receipt_pdf) {
                setTimeout(() => {
                    window.open(data.receipt_pdf, '_blank');
                }, 1000);
            }
            
            // Remove the card from the list
            setTimeout(() => {
                document.getElementById(`extension-${extensionId}`).remove();
                
                // Update badge count
                const badge = document.querySelector('#extensions-tab .badge');
                if (badge) {
                    const currentCount = parseInt(badge.textContent) || 0;
                    if (currentCount > 1) {
                        badge.textContent = currentCount - 1;
                    } else {
                        badge.remove();
                    }
                }
            }, 500);
        } else {
            showToast(data.message || 'Error approving extension', 'error');
            card.innerHTML = originalContent;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('Error approving extension', 'error');
        card.innerHTML = originalContent;
    });
}

function rejectExtension(extensionId) {
    if (!confirm('Reject this extension request?')) return;
    
    const adminNotes = prompt('Enter reason for rejection:', 'Extension request denied.');
    
    if (adminNotes === null) return; // User cancelled
    
    const formData = new FormData();
    formData.append('extension_id', extensionId);
    formData.append('action', 'reject');
    formData.append('admin_notes', adminNotes);
    
    // Show loading state
    const card = document.getElementById(`extension-${extensionId}`);
    const originalContent = card.innerHTML;
    card.innerHTML = '<div class="text-center p-4"><div class="loading-spinner" style="border-top-color: #ef476f;"></div><p>Processing rejection...</p></div>';
    
    fetch('../api/process_extension.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast('Extension request rejected.', 'success');
            
            // Remove the card from the list
            setTimeout(() => {
                document.getElementById(`extension-${extensionId}`).remove();
                
                // Update badge count
                const badge = document.querySelector('#extensions-tab .badge');
                if (badge) {
                    const currentCount = parseInt(badge.textContent) || 0;
                    if (currentCount > 1) {
                        badge.textContent = currentCount - 1;
                    } else {
                        badge.remove();
                    }
                }
            }, 500);
        } else {
            showToast(data.message || 'Error rejecting extension', 'error');
            card.innerHTML = originalContent;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('Error rejecting extension', 'error');
        card.innerHTML = originalContent;
    });
}

function viewExtensionDetails(extensionId) {
    fetch(`../api/get_extension_details.php?id=${extensionId}`)
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                showToast(data.message || 'Error loading extension details', 'error');
                return;
            }
            
            const extension = data.data;
            const currentDueDate = new Date(extension.current_due_date);
            const requestedDate = new Date(extension.requested_extension_date);
            
            let detailsHTML = `
                <div class="extension-details">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        Extension Request ID: <strong>${extension.id}</strong>
                    </div>
                    
                    <div class="book-info-card">
                        <div class="book-details">
                            <h4>${extension.book_title}</h4>
                            <p><strong><i class="fas fa-user-edit me-1"></i>Author:</strong> ${extension.author}</p>
                            <p><strong><i class="fas fa-user me-1"></i>Patron:</strong> ${extension.patron_name} (${extension.library_id})</p>
                            <p><strong><i class="fas fa-copy me-1"></i>Copy:</strong> ${extension.copy_number} (${extension.barcode})</p>
                        </div>
                    </div>
                    
                    <div class="extension-info-grid">
                        <div class="extension-info-item">
                            <strong>Status</strong>
                            <span class="badge ${extension.status === 'pending' ? 'status-pending' : 
                                                 extension.status === 'approved' ? 'status-approved' : 'status-rejected'}">
                                ${extension.status}
                            </span>
                        </div>
                        <div class="extension-info-item">
                            <strong>Extension Days</strong>
                            <span>${extension.extension_days} days</span>
                        </div>
                        <div class="extension-info-item">
                            <strong>Extension Fee</strong>
                            <span class="fee-badge fee-extension">₱${parseFloat(extension.extension_fee).toFixed(2)}</span>
                        </div>
                        <div class="extension-info-item">
                            <strong>Requested On</strong>
                            <span>${new Date(extension.created_at).toLocaleString()}</span>
                        </div>
                    </div>
                    
                    <div class="date-change mb-3 text-center">
                        <div class="mb-2">
                            <span class="text-danger fw-bold">
                                <i class="fas fa-calendar-times me-1"></i>
                                Current Due: ${currentDueDate.toLocaleDateString()}
                            </span>
                        </div>
                        <div class="mb-2">
                            <i class="fas fa-arrow-down fa-2x text-primary"></i>
                        </div>
                        <div>
                            <span class="text-success fw-bold">
                                <i class="fas fa-calendar-check me-1"></i>
                                Extended To: ${requestedDate.toLocaleDateString()}
                            </span>
                        </div>
                    </div>
                    
                    <div class="card mb-3">
                        <div class="card-header">
                            <i class="fas fa-comment me-2"></i>Reason for Extension
                        </div>
                        <div class="card-body">
                            <p class="mb-0">${extension.reason || 'No reason provided'}</p>
                        </div>
                    </div>
                    
                    ${extension.admin_notes ? `
                        <div class="card mb-3">
                            <div class="card-header">
                                <i class="fas fa-sticky-note me-2"></i>Admin Notes
                            </div>
                            <div class="card-body">
                                <p class="mb-0">${extension.admin_notes}</p>
                            </div>
                        </div>
                    ` : ''}
                    
                    ${extension.approved_at ? `
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle me-2"></i>
                            <strong>Approved on:</strong> ${new Date(extension.approved_at).toLocaleString()}
                            ${extension.approved_by ? ` by User ID: ${extension.approved_by}` : ''}
                        </div>
                    ` : ''}
                    
                    ${extension.receipt_number ? `
                        <div class="alert alert-info">
                            <i class="fas fa-receipt me-2"></i>
                            <strong>Receipt Number:</strong> ${extension.receipt_number}
                            ${extension.receipt_pdf ? `
                                <br><br>
                                <a href="${extension.receipt_pdf}" 
                                   target="_blank" 
                                   class="receipt-btn">
                                    <i class="fas fa-eye me-1"></i> View Extension Receipt
                                </a>
                            ` : ''}
                        </div>
                    ` : ''}
                    
                    <div class="text-center mt-4">
                        <button class="btn btn-primary" onclick="closeModal()">
                            <i class="fas fa-times me-2"></i> Close
                        </button>
                    </div>
                </div>
            `;
            
            document.getElementById('extensionDetailsContent').innerHTML = detailsHTML;
            document.getElementById('extensionDetailsModal').style.display = 'block';
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('Error loading extension details', 'error');
        });
}

function updateCopyStatus() {
    const returnCondition = document.getElementById('returnCondition').value;
    const bookPrice = parseFloat(document.getElementById('modalContent').dataset.bookPrice) || 0;
    const lostFee = bookPrice * 1.5;
    
    let statusText = 'available';
    let statusClass = 'alert-info';
    let statusIcon = 'fas fa-check-circle';
    
    switch(returnCondition) {
        case 'good':
            statusText = 'available';
            statusClass = 'alert-success';
            statusIcon = 'fas fa-check-circle';
            break;
        case 'fair':
            statusText = 'available';
            statusClass = 'alert-success';
            statusIcon = 'fas fa-check-circle';
            break;
        case 'poor':
            statusText = 'available (needs maintenance)';
            statusClass = 'alert-warning';
            statusIcon = 'fas fa-exclamation-triangle';
            break;
        case 'damaged':
            statusText = 'damaged (not available for borrowing)';
            statusClass = 'alert-warning';
            statusIcon = 'fas fa-exclamation-triangle';
            break;
        case 'lost':
            statusText = 'lost (book will be removed from inventory)';
            statusClass = 'alert-danger';
            statusIcon = 'fas fa-times-circle';
            break;
    }
    
    const statusInfo = document.getElementById('copyStatusInfo');
    statusInfo.className = `alert ${statusClass}`;
    statusInfo.innerHTML = `
        <i class="${statusIcon} me-2"></i>
        Book copy will be marked as <strong>${statusText}</strong> after return.
        ${returnCondition === 'lost' ? `<br><strong>Lost Fee:</strong> ₱${lostFee.toFixed(2)} (150% of book price)` : ''}
    `;
    
    // Update fee summary
    updateFeeSummary();
}

function updateFeeSummary() {
    const overdueFee = parseFloat(document.getElementById('overdueFee').textContent.replace('₱', '')) || 0;
    const returnCondition = document.getElementById('returnCondition').value;
    const bookPrice = parseFloat(document.getElementById('modalContent').dataset.bookPrice) || 0;
    
    let damageFee = 0;
    let lostFee = 0;
    
    // Calculate damage fees
    document.querySelectorAll('.damage-checkbox-input:checked').forEach(checkbox => {
        damageFee += parseFloat(checkbox.dataset.fee);
    });
    
    // Calculate lost fee if condition is lost
    if (returnCondition === 'lost') {
        lostFee = bookPrice * 1.5; // 150% of book price
    }
    
    document.getElementById('damageFee').textContent = `₱${damageFee.toFixed(2)}`;
    document.getElementById('lostFee').textContent = `₱${lostFee.toFixed(2)}`;
    
    const totalFee = overdueFee + damageFee + lostFee;
    document.getElementById('totalFee').textContent = `₱${totalFee.toFixed(2)}`;
}

function processReturn() {
    if (!currentBorrowId) return;
    
    const damageTypes = [];
    const damageFees = [];
    let totalDamageFee = 0;
    
    document.querySelectorAll('.damage-checkbox-input:checked').forEach(checkbox => {
        damageTypes.push(checkbox.value);
        damageFees.push({
            type: checkbox.value,
            fee: parseFloat(checkbox.dataset.fee)
        });
        totalDamageFee += parseFloat(checkbox.dataset.fee);
    });
    
    const damageDescription = document.getElementById('damageDescription').value;
    const returnCondition = document.getElementById('returnCondition').value;
    const overdueFee = parseFloat(document.getElementById('overdueFee').textContent.replace('₱', '')) || 0;
    const lostFee = returnCondition === 'lost' ? parseFloat(document.getElementById('lostFee').textContent.replace('₱', '')) || 0 : 0;
    const totalFee = parseFloat(document.getElementById('totalFee').textContent.replace('₱', '')) || 0;
    
    // Also get damage reports to mark them as resolved
    fetch(`../api/get_damage_reports.php?borrow_id=${currentBorrowId}`)
        .then(response => response.json())
        .then(reportData => {
            const reportIds = reportData.success && reportData.reports.length > 0 ? 
                reportData.reports.map(report => report.id) : [];
            
            const formData = new FormData();
            formData.append('borrow_id', currentBorrowId);
            formData.append('damage_types', JSON.stringify(damageTypes));
            formData.append('damage_description', damageDescription);
            formData.append('return_condition', returnCondition);
            formData.append('late_fee', overdueFee);
            formData.append('damage_fee', totalDamageFee);
            formData.append('lost_fee', lostFee);
            formData.append('total_fee', totalFee);
            formData.append('damage_report_ids', JSON.stringify(reportIds));
            
            // Show loading state
            const processBtn = document.querySelector('.btn-primary');
            const originalText = processBtn.innerHTML;
            processBtn.innerHTML = '<div class="loading-spinner"></div> Processing...';
            processBtn.disabled = true;
            
            fetch('../api/process_return.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('Book returned successfully! Receipt generated.', 'success');
                    if (data.receipt_pdf) {
                        setTimeout(() => {
                            window.open(data.receipt_pdf, '_blank');
                        }, 1000);
                    }
                    closeModal();
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showToast(data.message || 'Error processing return', 'error');
                    processBtn.innerHTML = originalText;
                    processBtn.disabled = false;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('Error processing return', 'error');
                processBtn.innerHTML = originalText;
                processBtn.disabled = false;
            });
        })
        .catch(error => {
            console.error('Error loading damage reports:', error);
            // Process without damage reports
            const formData = new FormData();
            formData.append('borrow_id', currentBorrowId);
            formData.append('damage_types', JSON.stringify(damageTypes));
            formData.append('damage_description', damageDescription);
            formData.append('return_condition', returnCondition);
            formData.append('late_fee', overdueFee);
            formData.append('damage_fee', totalDamageFee);
            formData.append('lost_fee', lostFee);
            formData.append('total_fee', totalFee);
            
            // Show loading state
            const processBtn = document.querySelector('.btn-primary');
            const originalText = processBtn.innerHTML;
            processBtn.innerHTML = '<div class="loading-spinner"></div> Processing...';
            processBtn.disabled = true;
            
            fetch('../api/process_return.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('Book returned successfully! Receipt generated.', 'success');
                    if (data.receipt_pdf) {
                        setTimeout(() => {
                            window.open(data.receipt_pdf, '_blank');
                        }, 1000);
                    }
                    closeModal();
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showToast(data.message || 'Error processing return', 'error');
                    processBtn.innerHTML = originalText;
                    processBtn.disabled = false;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('Error processing return', 'error');
                processBtn.innerHTML = originalText;
                processBtn.disabled = false;
            });
        });
}

function viewDetails(borrowId) {
    fetch(`../api/get_borrow_details.php?id=${borrowId}`)
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                showToast(data.message || 'Error loading details', 'error');
                return;
            }
            
            const borrow = data.data;
            const isOverdue = borrow.status === 'overdue';
            const dueDate = new Date(borrow.due_date);
            const today = new Date();
            const daysOverdue = isOverdue ? Math.max(0, Math.floor((today - dueDate) / (1000 * 60 * 60 * 24))) : 0;
            const bookPrice = parseFloat(borrow.book_price) || 0;
            
            // Get damage reports
            fetch(`../api/get_damage_reports.php?borrow_id=${borrowId}`)
                .then(response => response.json())
                .then(reportData => {
                    let detailsHTML = `
                        <div class="book-info-card">
                            <div class="book-cover-container">
                                <img src="${borrow.cover_image || '../assets/images/default-book.jpg'}" 
                                     alt="${borrow.book_name}" 
                                     class="book-cover"
                                     onerror="this.src='../assets/images/default-book.jpg'">
                            </div>
                            <div class="book-details">
                                <h4>${borrow.book_name}</h4>
                                <p><strong><i class="fas fa-user-edit me-1"></i>Author:</strong> ${borrow.author}</p>
                                <p><strong><i class="fas fa-barcode me-1"></i>ISBN:</strong> ${borrow.isbn || 'N/A'}</p>
                                <p><strong><i class="fas fa-tag me-1"></i>Category:</strong> ${borrow.category_name || 'N/A'}</p>
                                <p><strong><i class="fas fa-money-bill me-1"></i>Book Price:</strong> ₱${bookPrice.toFixed(2)}</p>
                                ${borrow.copy_number ? `
                                    <div class="copy-info mt-2">
                                        <strong><i class="fas fa-copy me-1"></i>Physical Copy:</strong> ${borrow.copy_number}<br>
                                        ${borrow.barcode ? `<strong><i class="fas fa-barcode me-1"></i>Barcode:</strong> ${borrow.barcode}` : ''}
                                    </div>
                                ` : ''}
                                ${borrow.extension_attempts > 0 ? `
                                    <div class="alert alert-info mt-2">
                                        <i class="fas fa-history me-1"></i>
                                        <strong>Extensions:</strong> This book has been extended ${borrow.extension_attempts} time(s)
                                    </div>
                                ` : ''}
                                ${borrow.lost_status === 'presumed_lost' || borrow.lost_status === 'confirmed_lost' ? `
                                    <div class="alert alert-danger mt-2">
                                        <i class="fas fa-exclamation-circle"></i>
                                        <strong>Book marked as ${borrow.lost_status.replace('_', ' ')}</strong><br>
                                        Lost Fee: ₱${borrow.lost_fee ? parseFloat(borrow.lost_fee).toFixed(2) : (bookPrice * 1.5).toFixed(2)}
                                    </div>
                                ` : ''}
                            </div>
                        </div>
                        
                        <div class="row mt-4">
                            <div class="col-md-6">
                                <div class="card h-100">
                                    <div class="card-header">
                                        <i class="fas fa-calendar-alt me-2"></i>Borrow Information
                                    </div>
                                    <div class="card-body">
                                        <table class="table table-sm">
                                            <tr>
                                                <th>Borrow ID:</th>
                                                <td><code>${borrow.id}</code></td>
                                            </tr>
                                            <tr>
                                                <th>Book Copy ID:</th>
                                                <td><code>${borrow.book_copy_id || 'Not specified'}</code></td>
                                            </tr>
                                            <tr>
                                                <th>Borrowed Date:</th>
                                                <td>${new Date(borrow.borrowed_at).toLocaleString()}</td>
                                            </tr>
                                            <tr>
                                                <th>Due Date:</th>
                                                <td class="${isOverdue ? 'text-danger fw-bold' : 'text-primary'}">
                                                    ${new Date(borrow.due_date).toLocaleDateString()}
                                                    ${isOverdue ? ` (${daysOverdue} days overdue)` : ''}
                                                </td>
                                            </tr>
                                            <tr>
                                                <th>Status:</th>
                                                <td>
                                                    <span class="status-badge status-${borrow.status}">
                                                        ${borrow.status.charAt(0).toUpperCase() + borrow.status.slice(1)}
                                                    </span>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th>Returned Date:</th>
                                                <td>${borrow.returned_at ? new Date(borrow.returned_at).toLocaleString() : '<span class="text-warning">Not returned yet</span>'}</td>
                                            </tr>
                                            ${borrow.last_extension_date ? `
                                                <tr>
                                                    <th>Last Extension:</th>
                                                    <td>${new Date(borrow.last_extension_date).toLocaleDateString()}</td>
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
                                                <td>${borrow.patron_name}</td>
                                            </tr>
                                            <tr>
                                                <th>Library ID:</th>
                                                <td>${borrow.library_id}</td>
                                            </tr>
                                            <tr>
                                                <th>Department:</th>
                                                <td>${borrow.department || 'N/A'}</td>
                                            </tr>
                                            <tr>
                                                <th>Semester:</th>
                                                <td>${borrow.semester || 'N/A'}</td>
                                            </tr>
                                            <tr>
                                                <th>Student ID:</th>
                                                <td>${borrow.student_id || 'N/A'}</td>
                                            </tr>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                    
                    // Display damage reports if any
                    if (reportData.success && reportData.reports.length > 0) {
                        detailsHTML += `
                            <div class="card mt-4">
                                <div class="card-header">
                                    <i class="fas fa-exclamation-triangle me-2"></i>Damage Reports
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-sm">
                                            <thead>
                                                <tr>
                                                    <th>Report ID</th>
                                                    <th>Type</th>
                                                    <th>Severity</th>
                                                    <th>Fee Charged</th>
                                                    <th>Status</th>
                                                    <th>Report Date</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                ${reportData.reports.map(report => `
                                                    <tr>
                                                        <td>#${report.id}</td>
                                                        <td>${report.report_type}</td>
                                                        <td><span class="badge ${report.severity === 'minor' ? 'bg-warning' : report.severity === 'moderate' ? 'bg-danger' : 'bg-dark'}">${report.severity}</span></td>
                                                        <td>₱${parseFloat(report.fee_charged || 0).toFixed(2)}</td>
                                                        <td><span class="badge ${report.status === 'pending' ? 'bg-warning' : 'bg-success'}">${report.status}</span></td>
                                                        <td>${new Date(report.report_date).toLocaleDateString()}</td>
                                                    </tr>
                                                `).join('')}
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        `;
                    }
                    
                    if (borrow.copy_number) {
                        detailsHTML += `
                            <div class="card mt-4">
                                <div class="card-header">
                                    <i class="fas fa-copy me-2"></i>Copy Information
                                </div>
                                <div class="card-body">
                                    <table class="table table-sm">
                                        <tr>
                                            <th>Copy Number:</th>
                                            <td>${borrow.copy_number}</td>
                                        </tr>
                                        <tr>
                                            <th>Barcode:</th>
                                            <td>${borrow.barcode}</td>
                                        </tr>
                                        <tr>
                                            <th>Condition:</th>
                                            <td>
                                                <span class="badge ${borrow.book_condition === 'new' ? 'bg-success' : 
                                                                     borrow.book_condition === 'good' ? 'bg-info' : 
                                                                     borrow.book_condition === 'fair' ? 'bg-warning' : 'bg-secondary'} rounded-pill px-3">
                                                    ${borrow.book_condition}
                                                </span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th>Location:</th>
                                            <td>${borrow.full_location || 'N/A'}</td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                        `;
                    }
                    
                    if (borrow.late_fee > 0 || borrow.penalty_fee > 0 || borrow.lost_fee > 0) {
                        detailsHTML += `
                            <div class="card mt-4">
                                <div class="card-header">
                                    <i class="fas fa-money-bill-wave me-2"></i>Fee Information
                                </div>
                                <div class="card-body">
                                    ${borrow.late_fee > 0 ? `
                                        <div class="alert alert-danger">
                                            <i class="fas fa-clock me-2"></i>
                                            <strong>Late Fee:</strong> ₱${parseFloat(borrow.late_fee).toFixed(2)}
                                        </div>
                                    ` : ''}
                                    ${borrow.penalty_fee > 0 ? `
                                        <div class="alert alert-warning">
                                            <i class="fas fa-tools me-2"></i>
                                            <strong>Damage Fee:</strong> ₱${parseFloat(borrow.penalty_fee).toFixed(2)}
                                        </div>
                                    ` : ''}
                                    ${borrow.lost_fee > 0 ? `
                                        <div class="alert alert-dark">
                                            <i class="fas fa-exclamation-triangle me-2"></i>
                                            <strong>Lost Fee:</strong> ₱${parseFloat(borrow.lost_fee).toFixed(2)} (150% of book price)
                                        </div>
                                    ` : ''}
                                </div>
                            </div>
                        `;
                    }
                    
                    document.getElementById('detailsContent').innerHTML = detailsHTML;
                    document.getElementById('detailsModal').style.display = 'block';
                })
                .catch(error => {
                    console.error('Error loading damage reports:', error);
                    // Continue without damage reports
                });
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('Error loading details', 'error');
        });
}

function generateReceipt(borrowId) {
    fetch(`../api/generate_receipt.php?borrow_id=${borrowId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.receipt_pdf) {
                window.open(data.receipt_pdf, '_blank');
            } else {
                showToast(data.message || 'Error generating receipt', 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('Error generating receipt', 'error');
        });
}

function sendReminder(borrowId) {
    if (!confirm('Send overdue reminder to the patron?')) return;
    
    fetch(`../api/send_reminder.php?borrow_id=${borrowId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast('Reminder sent successfully!', 'success');
            } else {
                showToast(data.message || 'Error sending reminder', 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('Error sending reminder', 'error');
        });
}

function deleteRecord(borrowId) {
    if (!confirm('Are you sure you want to delete this borrow record? This action cannot be undone.')) {
        return;
    }
    
    fetch(`../api/dispatch.php?resource=borrow_logs&id=${borrowId}`, {
        method: 'DELETE',
        headers: {
            'X-CSRF-Token': sessionStorage.getItem('csrf') || ''
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            document.getElementById(`row-${borrowId}`).remove();
            showToast('Record deleted successfully', 'success');
        } else {
            showToast(data.message || 'Error deleting record', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('Error deleting record', 'error');
    });
}

function closeModal() {
    document.getElementById('returnModal').style.display = 'none';
    document.getElementById('extensionModal').style.display = 'none';
    document.getElementById('extensionDetailsModal').style.display = 'none';
    document.getElementById('detailsModal').style.display = 'none';
    document.getElementById('copyInfoModal').style.display = 'none';
    currentBorrowId = null;
    currentExtensionId = null;
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
    // Remove existing toasts
    document.querySelectorAll('.toast').forEach(toast => toast.remove());
    
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.innerHTML = `
        <i class="fas fa-${type === 'success' ? 'check-circle' : 
                          type === 'error' ? 'exclamation-circle' : 
                          'exclamation-triangle'} me-2"></i>
        <span>${message}</span>
    `;
    
    document.body.appendChild(toast);
    
    // Animate in
    setTimeout(() => {
        toast.style.transform = 'translateX(0)';
        toast.style.opacity = '1';
    }, 10);
    
    // Remove after 3 seconds
    setTimeout(() => {
        toast.style.transform = 'translateX(100%)';
        toast.style.opacity = '0';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

// Initialize animations
document.addEventListener('DOMContentLoaded', function() {
    // Set initial toast styles
    const style = document.createElement('style');
    style.textContent = `
        .toast {
            transform: translateX(100%);
            opacity: 0;
            transition: all 0.3s ease;
        }
    `;
    document.head.appendChild(style);
    
    // Show pending extensions by default when extensions tab is active
    if (document.getElementById('extensions-tab-content').classList.contains('active')) {
        switchExtensionTab('pending');
    }
});

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeModal();
    }
    if (e.key === 'r' && e.ctrlKey) {
        e.preventDefault();
        location.reload();
    }
});
</script>
</body>
</html>

<?php include __DIR__ . '/_footer.php'; ?>