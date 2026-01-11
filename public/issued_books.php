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

// Pagination settings
$items_per_page = 3; // Show 3 items per page
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

// First, get total count for pagination
$countSql = "SELECT COUNT(*) as total 
             FROM borrow_logs bl
             JOIN books b ON bl.book_id = b.id
             JOIN book_copies bc ON bl.book_copy_id = bc.id
             JOIN patrons p ON bl.patron_id = p.id
             LEFT JOIN users u ON u.patron_id = p.id
             LEFT JOIN categories c ON b.category_id = c.id
             LEFT JOIN receipts rc ON rc.borrow_log_id = bl.id AND rc.status = 'paid'
             $whereSQL";

$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
$totalPages = ceil($totalCount / $items_per_page);

$sql = "SELECT 
            bl.id, 
            bl.book_id,
            bl.book_copy_id,
            bl.patron_id,
            b.title AS book_name,
            b.author,
            b.cover_image_cache,
            bc.copy_number,
            bc.barcode,
            bc.book_condition,
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
            bc.current_section,
            bc.current_shelf,
            bc.current_row,
            bc.current_slot,
            rc.receipt_number,
            rc.pdf_path
        FROM borrow_logs bl
        JOIN books b ON bl.book_id = b.id
        JOIN book_copies bc ON bl.book_copy_id = bc.id
        JOIN patrons p ON bl.patron_id = p.id
        LEFT JOIN users u ON u.patron_id = p.id
        LEFT JOIN categories c ON b.category_id = c.id
        LEFT JOIN receipts rc ON rc.borrow_log_id = bl.id AND rc.status = 'paid'
        $whereSQL
        ORDER BY 
            CASE WHEN bl.status = 'overdue' THEN 1 
                 WHEN bl.status = 'borrowed' THEN 2 
                 ELSE 3 END,
            bl.due_date ASC, 
            bl.borrowed_at DESC
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
        
        /* Book Cover */
        .book-cover-container {
            width: 120px;
            height: 160px;
            border-radius: 12px;
            overflow: hidden;
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
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
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="page-header">
            <h2><i class="fas fa-book-open me-2"></i>Borrowed Books Management</h2>
            <p class="mb-0">Manage borrowed books, track overdue items, and process returns with penalty calculations</p>
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
                    </div>
                    <div>
                        <?php 
                        // Count borrowed and overdue items from the filtered results
                        $borrowedCount = 0;
                        $overdueCount = 0;
                        foreach ($issued as $item) {
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
                    <?php 
                    $activeBooks = array_filter($issued, function($item) {
                        return $item['status'] !== 'returned';
                    });
                    ?>
                    
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
                            <table class="table table-hover align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 30%;">Book Details</th>
                                        <th style="width: 20%;">Patron Info</th>
                                        <th style="width: 20%;">Borrow Details</th>
                                        <th style="width: 15%;">Fees</th>
                                        <th style="width: 15%;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($activeBooks as $row): 
                                        $isOverdue = $row['status'] === 'overdue';
                                        $daysOverdue = $isOverdue ? max(0, floor((time() - strtotime($row['due_date'])) / (60 * 60 * 24))) : 0;
                                        $coverImage = !empty($row['cover_image_cache']) ? 
                                            '../uploads/covers/' . $row['cover_image_cache'] : 
                                            '../assets/images/default-book.jpg';
                                        
                                        // Parse damage types if available
                                        $damages = [];
                                        if (!empty($row['damage_types'])) {
                                            $damages = json_decode($row['damage_types'], true);
                                            if (!is_array($damages)) {
                                                $damages = [];
                                            }
                                        }
                                    ?>
                                    <tr id="row-<?= $row['id'] ?>" class="<?= $isOverdue ? 'table-danger' : '' ?>">
                                        <td>
                                            <div class="book-info-card">
                                                <div class="book-cover-container">
                                                    <img src="<?= $coverImage ?>" 
                                                         alt="<?= htmlspecialchars($row['book_name']) ?>" 
                                                         class="book-cover"
                                                         onerror="this.src='../assets/images/default-book.jpg'">
                                                </div>
                                                <div class="book-details">
                                                    <h5><?= htmlspecialchars($row['book_name']) ?></h5>
                                                    <p class="text-muted mb-2">
                                                        <i class="fas fa-user-edit me-1"></i><?= htmlspecialchars($row['author']) ?>
                                                    </p>
                                                    <p class="text-muted mb-2">
                                                        <i class="fas fa-tag me-1"></i><?= htmlspecialchars($row['category_name'] ?? 'N/A') ?>
                                                    </p>
                                                    <?php if (!empty($row['copy_number'])): ?>
                                                        <div class="copy-info">
                                                            <small>
                                                                <i class="fas fa-copy me-1"></i><strong>Copy:</strong> <?= htmlspecialchars($row['copy_number']) ?><br>
                                                                <i class="fas fa-barcode me-1"></i><strong>Barcode:</strong> <?= htmlspecialchars($row['barcode']) ?><br>
                                                                <?php if ($row['current_section']): ?>
                                                                    <span class="location-badge">
                                                                        <i class="fas fa-map-marker-alt me-1"></i>
                                                                        <?= htmlspecialchars($row['current_section']) ?>-
                                                                        S<?= htmlspecialchars($row['current_shelf']) ?>-
                                                                        R<?= htmlspecialchars($row['current_row']) ?>-
                                                                        P<?= htmlspecialchars($row['current_slot']) ?>
                                                                    </span>
                                                                <?php endif; ?>
                                                            </small>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-start mb-2">
                                                <i class="fas fa-user mt-1 me-2 text-primary"></i>
                                                <div>
                                                    <strong><?= htmlspecialchars($row['patron_name'] ?? $row['user_name']) ?></strong><br>
                                                    <small class="text-muted">
                                                        <i class="fas fa-id-card me-1"></i>ID: <?= htmlspecialchars($row['student_id'] ?? $row['library_id']) ?><br>
                                                        <?php if (!empty($row['department'])): ?>
                                                            <i class="fas fa-building me-1"></i><?= htmlspecialchars($row['department']) ?><br>
                                                        <?php endif; ?>
                                                        <?php if (!empty($row['semester'])): ?>
                                                            <i class="fas fa-graduation-cap me-1"></i><?= htmlspecialchars($row['semester']) ?>
                                                        <?php endif; ?>
                                                    </small>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="d-flex flex-column">
                                                <small class="text-muted">Borrowed:</small>
                                                <strong><?= date('M d, Y', strtotime($row['borrowed_at'])) ?></strong>
                                                
                                                <small class="text-muted mt-2">Due Date:</small>
                                                <strong class="<?= $isOverdue ? 'text-danger' : 'text-primary' ?>">
                                                    <?= date('M d, Y', strtotime($row['due_date'])) ?>
                                                </strong>
                                                
                                                <div class="mt-2">
                                                    <span class="status-badge status-<?= $row['status'] ?>">
                                                        <?php if ($isOverdue): ?>
                                                            <i class="fas fa-exclamation-circle me-1"></i>
                                                        <?php else: ?>
                                                            <i class="fas fa-book me-1"></i>
                                                        <?php endif; ?>
                                                        <?= ucfirst($row['status']) ?>
                                                        <?php if ($isOverdue): ?>
                                                            (<?= $daysOverdue ?> days)
                                                        <?php endif; ?>
                                                    </span>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($isOverdue): ?>
                                                <span class="fee-badge fee-overdue d-block mb-2">
                                                    <i class="fas fa-clock me-1"></i>
                                                    Overdue: ₱<?= $daysOverdue * 30 ?>
                                                </span>
                                            <?php endif; ?>
                                            
                                            <?php if (!empty($row['late_fee']) && $row['late_fee'] > 0): ?>
                                                <span class="fee-badge fee-overdue d-block mb-2">
                                                    <i class="fas fa-money-bill-wave me-1"></i>
                                                    Late Fee: ₱<?= number_format($row['late_fee'], 2) ?>
                                                </span>
                                            <?php endif; ?>
                                            
                                            <?php if (!empty($row['penalty_fee']) && $row['penalty_fee'] > 0): ?>
                                                <span class="fee-badge fee-damage d-block mb-2">
                                                    <i class="fas fa-tools me-1"></i>
                                                    Damage: ₱<?= number_format($row['penalty_fee'], 2) ?>
                                                </span>
                                            <?php endif; ?>
                                            
                                            <?php if (!empty($damages)): ?>
                                                <small class="text-muted d-block mb-1">Damages:</small>
                                                <?php foreach ($damages as $damage): ?>
                                                    <span class="damage-tag"><?= htmlspecialchars($damage) ?></span>
                                                <?php endforeach; ?>
                                            <?php elseif (!empty($row['damage_type']) && $row['damage_type'] !== 'none'): ?>
                                                <small class="text-muted d-block mb-1">Damage:</small>
                                                <span class="damage-tag"><?= htmlspecialchars($row['damage_type']) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <?php if ($row['status'] !== 'returned'): ?>
                                                    <button class="btn btn-sm btn-primary" 
                                                            onclick="showReturnModal(<?= $row['id'] ?>)">
                                                        <i class="fas fa-undo me-1"></i> Return
                                                    </button>
                                                <?php endif; ?>
                                                <button class="btn btn-sm btn-info" 
                                                        onclick="viewDetails(<?= $row['id'] ?>)">
                                                    <i class="fas fa-eye me-1"></i> Details
                                                </button>
                                                <?php if ($row['status'] === 'returned' && !empty($row['pdf_path'])): ?>
                                                    <button class="btn btn-sm btn-success" 
                                                            onclick="window.open('<?= $row['pdf_path'] ?>', '_blank')">
                                                        <i class="fas fa-receipt me-1"></i> Receipt
                                                    </button>
                                                <?php endif; ?>
                                                <button class="btn btn-sm btn-danger" 
                                                        onclick="deleteRecord(<?= $row['id'] ?>)">
                                                    <i class="fas fa-trash"></i>
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
                        <div class="pagination-container">
                            <nav aria-label="Page navigation">
                                <ul class="pagination">
                                    <?php if ($current_page > 1): ?>
                                        <li class="page-item">
                                            <a class="page-link" 
                                               href="?filter=<?= $filter ?>&page=<?= $current_page - 1 ?>&student_id=<?= $student_id ?>&status=<?= $status_filter ?>">
                                                <i class="fas fa-chevron-left"></i> Previous
                                            </a>
                                        </li>
                                    <?php endif; ?>
                                    
                                    <?php 
                                    $startPage = max(1, $current_page - 2);
                                    $endPage = min($totalPages, $current_page + 2);
                                    
                                    for ($i = $startPage; $i <= $endPage; $i++): 
                                    ?>
                                        <li class="page-item <?= $i == $current_page ? 'active' : '' ?>">
                                            <a class="page-link" 
                                               href="?filter=<?= $filter ?>&page=<?= $i ?>&student_id=<?= $student_id ?>&status=<?= $status_filter ?>">
                                                <?= $i ?>
                                            </a>
                                        </li>
                                    <?php endfor; ?>
                                    
                                    <?php if ($current_page < $totalPages): ?>
                                        <li class="page-item">
                                            <a class="page-link" 
                                               href="?filter=<?= $filter ?>&page=<?= $current_page + 1 ?>&student_id=<?= $student_id ?>&status=<?= $status_filter ?>">
                                                Next <i class="fas fa-chevron-right"></i>
                                            </a>
                                        </li>
                                    <?php endif; ?>
                                </ul>
                            </nav>
                            <div class="page-info">
                                Page <?= $current_page ?> of <?= $totalPages ?> (Total: <?= $totalCount ?> items)
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
                            <table class="table table-hover align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Book Details</th>
                                        <th>Patron</th>
                                        <th>Return Date</th>
                                        <th>Condition</th>
                                        <th>Fees Paid</th>
                                        <th>Receipt</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($returnedBooks as $row): 
                                        $coverImage = !empty($row['cover_image_cache']) ? 
                                            '../uploads/covers/' . $row['cover_image_cache'] : 
                                            '../assets/images/default-book.jpg';
                                        $totalFees = ($row['late_fee'] ?? 0) + ($row['penalty_fee'] ?? 0);
                                    ?>
                                    <tr>
                                        <td style="width: 30%;">
                                            <div class="d-flex align-items-center">
                                                <div class="book-cover-container me-3" style="width: 60px; height: 80px;">
                                                    <img src="<?= $coverImage ?>" 
                                                         alt="<?= htmlspecialchars($row['book_name']) ?>" 
                                                         class="book-cover"
                                                         onerror="this.src='../assets/images/default-book.jpg'">
                                                </div>
                                                <div>
                                                    <strong class="d-block"><?= htmlspecialchars($row['book_name']) ?></strong>
                                                    <small class="text-muted"><?= htmlspecialchars($row['author']) ?></small>
                                                    <?php if (!empty($row['copy_number'])): ?>
                                                        <small class="d-block text-muted">
                                                            <i class="fas fa-copy me-1"></i><?= htmlspecialchars($row['copy_number']) ?>
                                                        </small>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td style="width: 15%;">
                                            <strong><?= htmlspecialchars($row['patron_name']) ?></strong><br>
                                            <small class="text-muted"><?= htmlspecialchars($row['library_id']) ?></small>
                                        </td>
                                        <td style="width: 15%;">
                                            <?= $row['returned_at'] ? date('M d, Y', strtotime($row['returned_at'])) : 'N/A' ?><br>
                                            <?php if ($row['returned_at']): ?>
                                                <small class="text-muted"><?= date('h:i A', strtotime($row['returned_at'])) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td style="width: 10%;">
                                            <?php
                                            $conditionClass = 'bg-success';
                                            $conditionText = ucfirst($row['return_condition'] ?? 'good');
                                            
                                            if ($row['return_condition'] === 'damaged') {
                                                $conditionClass = 'bg-warning';
                                            } elseif ($row['return_condition'] === 'poor') {
                                                $conditionClass = 'bg-danger';
                                            } elseif ($row['return_condition'] === 'lost') {
                                                $conditionClass = 'bg-dark';
                                            }
                                            ?>
                                            <span class="badge <?= $conditionClass ?> rounded-pill px-3">
                                                <?= $conditionText ?>
                                            </span>
                                        </td>
                                        <td style="width: 15%;">
                                            <?php if ($totalFees > 0): ?>
                                                <span class="fee-badge fee-overdue d-block">
                                                    ₱<?= number_format($totalFees, 2) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-success">No Fees</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="width: 10%;">
                                            <?php if (!empty($row['pdf_path'])): ?>
                                                <a href="<?= $row['pdf_path'] ?>" 
                                                   target="_blank" 
                                                   class="btn btn-sm btn-success">
                                                    <i class="fas fa-receipt"></i> View
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted">No receipt</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="width: 10%;">
                                            <div class="action-buttons">
                                                <button class="btn btn-sm btn-info" 
                                                        onclick="viewDetails(<?= $row['id'] ?>)">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <?php if (!empty($row['pdf_path'])): ?>
                                                    <button class="btn btn-sm btn-success" 
                                                            onclick="window.open('<?= $row['pdf_path'] ?>', '_blank')">
                                                        <i class="fas fa-print"></i>
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
                            <nav aria-label="Page navigation">
                                <ul class="pagination">
                                    <?php if ($current_page > 1): ?>
                                        <li class="page-item">
                                            <a class="page-link" 
                                               href="?filter=returned&page=<?= $current_page - 1 ?>&student_id=<?= $student_id ?>&status=<?= $status_filter ?>">
                                                <i class="fas fa-chevron-left"></i> Previous
                                            </a>
                                        </li>
                                    <?php endif; ?>
                                    
                                    <?php 
                                    $startPage = max(1, $current_page - 2);
                                    $endPage = min($totalPages, $current_page + 2);
                                    
                                    for ($i = $startPage; $i <= $endPage; $i++): 
                                    ?>
                                        <li class="page-item <?= $i == $current_page ? 'active' : '' ?>">
                                            <a class="page-link" 
                                               href="?filter=returned&page=<?= $i ?>&student_id=<?= $student_id ?>&status=<?= $status_filter ?>">
                                                <?= $i ?>
                                            </a>
                                        </li>
                                    <?php endfor; ?>
                                    
                                    <?php if ($current_page < $totalPages): ?>
                                        <li class="page-item">
                                            <a class="page-link" 
                                               href="?filter=returned&page=<?= $current_page + 1 ?>&student_id=<?= $student_id ?>&status=<?= $status_filter ?>">
                                                Next <i class="fas fa-chevron-right"></i>
                                            </a>
                                        </li>
                                    <?php endif; ?>
                                </ul>
                            </nav>
                            <div class="page-info">
                                Page <?= $current_page ?> of <?= $totalPages ?> (Total: <?= $totalCount ?> items)
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
                            <strong>Warning:</strong> There are <?= $overdueCount ?> overdue book(s) that need attention.
                        </div>
                        
                        <div class="row">
                            <?php foreach ($overdueBooks as $row): 
                                $daysOverdue = max(0, floor((time() - strtotime($row['due_date'])) / (60 * 60 * 24)));
                                $overdueFee = $daysOverdue * 30;
                                $coverImage = !empty($row['cover_image_cache']) ? 
                                    '../uploads/covers/' . $row['cover_image_cache'] : 
                                    '../assets/images/default-book.jpg';
                            ?>
                            <div class="col-md-6 col-lg-4 mb-4">
                                <div class="card h-100 border-danger">
                                    <div class="card-header bg-danger text-white">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span><i class="fas fa-clock me-1"></i> <?= $daysOverdue ?> days overdue</span>
                                            <span class="badge bg-light text-danger">₱<?= $overdueFee ?></span>
                                        </div>
                                    </div>
                                    <div class="card-body">
                                        <div class="text-center mb-3">
                                            <div class="book-cover-container mx-auto" style="width: 100px; height: 140px;">
                                                <img src="<?= $coverImage ?>" 
                                                     alt="<?= htmlspecialchars($row['book_name']) ?>" 
                                                     class="book-cover"
                                                     onerror="this.src='../assets/images/default-book.jpg'">
                                            </div>
                                        </div>
                                        <h6 class="card-title"><?= htmlspecialchars($row['book_name']) ?></h6>
                                        <p class="card-text text-muted small mb-2">
                                            <i class="fas fa-user-edit me-1"></i><?= htmlspecialchars($row['author']) ?>
                                        </p>
                                        <p class="card-text small">
                                            <i class="fas fa-user me-1"></i><?= htmlspecialchars($row['patron_name']) ?><br>
                                            <i class="fas fa-id-card me-1"></i><?= htmlspecialchars($row['library_id']) ?><br>
                                            <i class="fas fa-calendar-times me-1"></i>Due: <?= date('M d, Y', strtotime($row['due_date'])) ?>
                                        </p>
                                    </div>
                                    <div class="card-footer bg-transparent border-top-0">
                                        <div class="d-grid gap-2">
                                            <button class="btn btn-sm btn-primary" 
                                                    onclick="showReturnModal(<?= $row['id'] ?>)">
                                                <i class="fas fa-undo me-1"></i> Process Return
                                            </button>
                                            <button class="btn btn-sm btn-outline-danger" 
                                                    onclick="sendReminder(<?= $row['id'] ?>)">
                                                <i class="fas fa-envelope me-1"></i> Send Reminder
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <!-- Pagination -->
                        <?php if ($totalPages > 1): ?>
                        <div class="pagination-container">
                            <nav aria-label="Page navigation">
                                <ul class="pagination">
                                    <?php if ($current_page > 1): ?>
                                        <li class="page-item">
                                            <a class="page-link" 
                                               href="?filter=overdue&page=<?= $current_page - 1 ?>&student_id=<?= $student_id ?>&status=<?= $status_filter ?>">
                                                <i class="fas fa-chevron-left"></i> Previous
                                            </a>
                                        </li>
                                    <?php endif; ?>
                                    
                                    <?php 
                                    $startPage = max(1, $current_page - 2);
                                    $endPage = min($totalPages, $current_page + 2);
                                    
                                    for ($i = $startPage; $i <= $endPage; $i++): 
                                    ?>
                                        <li class="page-item <?= $i == $current_page ? 'active' : '' ?>">
                                            <a class="page-link" 
                                               href="?filter=overdue&page=<?= $i ?>&student_id=<?= $student_id ?>&status=<?= $status_filter ?>">
                                                <?= $i ?>
                                            </a>
                                        </li>
                                    <?php endfor; ?>
                                    
                                    <?php if ($current_page < $totalPages): ?>
                                        <li class="page-item">
                                            <a class="page-link" 
                                               href="?filter=overdue&page=<?= $current_page + 1 ?>&student_id=<?= $student_id ?>&status=<?= $status_filter ?>">
                                                Next <i class="fas fa-chevron-right"></i>
                                            </a>
                                        </li>
                                    <?php endif; ?>
                                </ul>
                            </nav>
                            <div class="page-info">
                                Page <?= $current_page ?> of <?= $totalPages ?> (Total: <?= $totalCount ?> items)
                            </div>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Return Modal -->
    <div id="returnModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-undo me-2"></i>Return Book</h3>
                <button class="close-modal" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div id="modalContent">
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    let currentBorrowId = null;
    const overdueFeePerDay = 30;

    function switchTab(tabName) {
        // Update URL and reload with the tab filter
        let url = new URL(window.location);
        url.searchParams.set('filter', tabName);
        url.searchParams.delete('page'); // Reset to page 1 when switching tabs
        window.location.href = url.toString();
    }

    function applyFilters() {
        const studentId = document.getElementById('student_id').value;
        const statusFilter = document.getElementById('status_filter').value;
        const currentFilter = '<?= $filter ?>';
        
        let url = new URL(window.location);
        url.searchParams.set('filter', currentFilter);
        url.searchParams.delete('page'); // Reset to page 1 when applying filters
        
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
                                ${daysOverdue > 0 ? `
                                    <div class="overdue-warning">
                                        <i class="fas fa-exclamation-triangle"></i>
                                        <strong>${daysOverdue} days overdue</strong>
                                    </div>
                                ` : ''}
                            </div>
                        </div>
                        
                        <h4><i class="fas fa-tools me-2"></i>Damage Assessment</h4>
                        <div class="damage-checkboxes" id="damageCheckboxes">
                `;
                
                // Add damage checkboxes
                <?php foreach ($damageTypes as $type): ?>
                    modalHTML += `
                        <div class="damage-checkbox">
                            <input type="checkbox" 
                                   id="damage_${borrowId}_<?= $type['id'] ?>" 
                                   name="damage_types[]" 
                                   value="<?= $type['name'] ?>"
                                   data-fee="<?= $type['fee_amount'] ?>"
                                   class="damage-checkbox-input">
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
                                      placeholder="Describe any damage to the book..."></textarea>
                        </div>
                        
                        <div class="form-group mb-4">
                            <label for="returnCondition" class="form-label">
                                <i class="fas fa-clipboard-check me-1"></i>Return Condition
                            </label>
                            <select id="returnCondition" class="form-control" onchange="updateCopyStatus()">
                                <option value="good">Good - No damage</option>
                                <option value="fair">Fair - Minor wear</option>
                                <option value="poor">Poor - Significant wear (still available)</option>
                                <option value="damaged">Damaged - Needs repair (not available)</option>
                                <option value="lost">Lost - Book is lost (not available)</option>
                            </select>
                        </div>
                        
                        <div id="copyStatusInfo" class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            Book copy will be marked as <strong id="statusText">available</strong> after return.
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
                                <span id="damageFee">₱0.00</span>
                            </div>
                            <div class="fee-item fee-total">
                                <span>Total Amount:</span>
                                <span id="totalFee">₱${overdueFee.toFixed(2)}</span>
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
                
                // Add event listeners for damage checkboxes
                document.querySelectorAll('.damage-checkbox-input').forEach(checkbox => {
                    checkbox.addEventListener('change', updateFeeSummary);
                });
                
                // Initial status update
                updateCopyStatus();
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('Error loading borrow details', 'error');
            });
    }

    function updateFeeSummary() {
        const overdueFee = parseFloat(document.getElementById('overdueFee').textContent.replace('₱', '')) || 0;
        let damageFee = 0;
        
        document.querySelectorAll('.damage-checkbox-input:checked').forEach(checkbox => {
            damageFee += parseFloat(checkbox.dataset.fee);
        });
        
        document.getElementById('damageFee').textContent = `₱${damageFee.toFixed(2)}`;
        
        const totalFee = overdueFee + damageFee;
        document.getElementById('totalFee').textContent = `₱${totalFee.toFixed(2)}`;
    }

    function updateCopyStatus() {
        const returnCondition = document.getElementById('returnCondition').value;
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
        `;
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
        const totalFee = parseFloat(document.getElementById('totalFee').textContent.replace('₱', '')) || 0;
        
        const formData = new FormData();
        formData.append('borrow_id', currentBorrowId);
        formData.append('damage_types', JSON.stringify(damageTypes));
        formData.append('damage_description', damageDescription);
        formData.append('return_condition', returnCondition);
        formData.append('late_fee', overdueFee);
        formData.append('damage_fee', totalDamageFee);
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
                    // Open receipt in new tab
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
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
                
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
                                </table>
                            </div>
                        </div>
                    `;
                }
                
                if (borrow.late_fee > 0 || borrow.penalty_fee > 0) {
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
                            </div>
                        </div>
                    `;
                }
                
                document.getElementById('detailsContent').innerHTML = detailsHTML;
                document.getElementById('detailsModal').style.display = 'block';
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
        document.getElementById('detailsModal').style.display = 'none';
        currentBorrowId = null;
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
            <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'} me-2"></i>
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