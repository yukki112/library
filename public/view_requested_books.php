<?php
// Enhanced View Requested Books page with modern design and filtering
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';
require_login();

$pdo = DB::conn();

// Generate CSRF token for the page
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$csrf_token = $_SESSION['csrf_token'] ?? '';
if (empty($csrf_token)) {
    $csrf_token = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $csrf_token;
}

// Process status filter
$allowedStatuses = ['pending', 'approved', 'active', 'fulfilled', 'cancelled', 'expired', 'declined', 'all'];
$currentFilter = 'all';

if (isset($_GET['status']) && in_array(strtolower($_GET['status']), $allowedStatuses, true)) {
    $currentFilter = strtolower($_GET['status']);
}

// Process search filter
$searchTerm = isset($_GET['search']) ? trim($_GET['search']) : '';

// Process date range filter
$dateFrom = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$dateTo = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';

// Pagination settings
$itemsPerPage = 3; // Show only 3 items per page as requested
$currentPage = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($currentPage - 1) * $itemsPerPage;

// Build WHERE clause based on filter
$whereConditions = [];
$params = [];

if ($currentFilter !== 'all') {
    if ($currentFilter === 'active') {
        $whereConditions[] = 'r.status = "approved"';
    } else {
        $whereConditions[] = 'r.status = ?';
        $params[] = $currentFilter;
    }
}

// Always exclude fulfilled reservations unless specifically filtered
if ($currentFilter !== 'fulfilled') {
    $whereConditions[] = 'r.status <> "fulfilled"';
}

// FIXED: Don't exclude reservations with unreturned borrow logs
// This was hiding reservations for books that are currently borrowed
if ($currentFilter === 'pending') {
    // For pending reservations, we don't want to show ones that already have borrow logs
    $whereConditions[] = 'NOT EXISTS (
        SELECT 1 FROM borrow_logs bl 
        WHERE bl.book_id = r.book_id 
        AND bl.patron_id = r.patron_id 
        AND bl.status = "borrowed"
    )';
}

// Search conditions
if (!empty($searchTerm)) {
    $whereConditions[] = '(b.title LIKE ? OR u.name LIKE ? OR u.username LIKE ? OR u.email LIKE ? OR p.library_id LIKE ? OR u.student_id LIKE ?)';
    $searchPattern = '%' . $searchTerm . '%';
    for ($i = 0; $i < 6; $i++) {
        $params[] = $searchPattern;
    }
}

// Date range conditions
if (!empty($dateFrom) && !empty($dateTo)) {
    $whereConditions[] = 'DATE(r.reserved_at) BETWEEN ? AND ?';
    $params[] = $dateFrom;
    $params[] = $dateTo;
} elseif (!empty($dateFrom)) {
    $whereConditions[] = 'DATE(r.reserved_at) >= ?';
    $params[] = $dateFrom;
} elseif (!empty($dateTo)) {
    $whereConditions[] = 'DATE(r.reserved_at) <= ?';
    $params[] = $dateTo;
}

// Combine WHERE conditions
$whereClause = '';
if (!empty($whereConditions)) {
    $whereClause = 'WHERE ' . implode(' AND ', $whereConditions);
}

// Get counts for filter badges
$countSql = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN r.status = 'pending' THEN 1 ELSE 0 END) as pending,
    SUM(CASE WHEN r.status = 'approved' THEN 1 ELSE 0 END) as approved,
    SUM(CASE WHEN r.status = 'declined' THEN 1 ELSE 0 END) as declined,
    SUM(CASE WHEN r.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
    SUM(CASE WHEN r.status = 'expired' THEN 1 ELSE 0 END) as expired
    FROM reservations r
    JOIN books b ON r.book_id = b.id
    JOIN patrons p ON r.patron_id = p.id
    LEFT JOIN users u ON u.patron_id = p.id
    $whereClause";
    
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$counts = $countStmt->fetch(PDO::FETCH_ASSOC);

// Fetch reservation requests with pagination - UPDATED QUERY
try {
    // First, get total count for pagination
    $totalSql = "SELECT COUNT(*) as total FROM reservations r
                JOIN books b ON r.book_id = b.id
                JOIN patrons p ON r.patron_id = p.id
                LEFT JOIN users u ON u.patron_id = p.id
                $whereClause";
    
    $totalStmt = $pdo->prepare($totalSql);
    $totalStmt->execute($params);
    $totalResult = $totalStmt->fetch(PDO::FETCH_ASSOC);
    $totalItems = $totalResult['total'] ?? 0;
    $totalPages = ceil($totalItems / $itemsPerPage);
    
    // Adjust current page if out of bounds
    if ($currentPage > $totalPages && $totalPages > 0) {
        $currentPage = $totalPages;
        $offset = ($currentPage - 1) * $itemsPerPage;
    }
    
  // Now fetch the actual data with pagination - FIXED QUERY WITH DISTINCT
// Now fetch the actual data with pagination - FIXED QUERY
$sql = "SELECT 
        r.id, r.book_id, r.book_copy_id, r.patron_id, r.reserved_at, r.expiration_date, r.notes,
        b.title AS book_name, b.author AS book_author, b.isbn, b.category_id, 
        COALESCE(b.cover_image_cache, b.cover_image) AS book_cover,
        r.status, r.reason, r.reservation_type,
        u.role AS user_role, u.name AS user_name, u.username, u.email, u.student_id,
        p.library_id, p.department, p.semester,
        bl.id AS borrow_id,
        bl.due_date AS borrow_due_date,
        bl.status AS borrow_status,
        bc.copy_number, bc.id as copy_id,
        bc.current_section, bc.current_shelf, bc.current_row, bc.current_slot,
        c.name AS category_name,
        c.default_section AS category_section,
        c.shelf_recommendation, c.row_recommendation, c.slot_recommendation
    FROM reservations r
    JOIN books b ON r.book_id = b.id
    LEFT JOIN categories c ON b.category_id = c.id
    JOIN patrons p ON r.patron_id = p.id
    LEFT JOIN users u ON u.patron_id = p.id
    LEFT JOIN borrow_logs bl ON bl.id = (
        SELECT id FROM borrow_logs 
        WHERE book_id = r.book_id 
          AND patron_id = r.patron_id 
          AND status IN ('borrowed', 'overdue')
          AND (r.book_copy_id IS NULL OR book_copy_id = r.book_copy_id)
        ORDER BY borrowed_at DESC 
        LIMIT 1
    )
    LEFT JOIN book_copies bc ON bc.id = r.book_copy_id
    $whereClause
    ORDER BY 
        CASE 
            WHEN r.status = 'pending' THEN 1
            WHEN r.status = 'approved' THEN 2
            ELSE 3
        END,
        r.reserved_at DESC
    LIMIT $itemsPerPage OFFSET $offset";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $ex) {
    // Fallback if new columns don't exist
    if ($ex->getCode() === '42S22') {
        // Recalculate total with fallback query
        $totalSql = "SELECT COUNT(*) as total FROM reservations r
                    JOIN books b ON r.book_id = b.id
                    JOIN patrons p ON r.patron_id = p.id
                    LEFT JOIN users u ON u.patron_id = p.id
                    $whereClause";
        
        $totalStmt = $pdo->prepare($totalSql);
        $totalStmt->execute($params);
        $totalResult = $totalStmt->fetch(PDO::FETCH_ASSOC);
        $totalItems = $totalResult['total'] ?? 0;
        $totalPages = ceil($totalItems / $itemsPerPage);
        
        // Adjust current page
        if ($currentPage > $totalPages && $totalPages > 0) {
            $currentPage = $totalPages;
            $offset = ($currentPage - 1) * $itemsPerPage;
        }
        
        $sql = "SELECT r.id, r.book_id, r.patron_id, r.reserved_at, r.expiration_date,
                       b.title AS book_name, b.author AS book_author,
                       r.status, NULL AS reason,
                       u.role AS user_role, u.name AS user_name, u.username, u.email, u.student_id,
                       p.library_id,
                       bl.id AS borrow_id,
                       bl.due_date AS borrow_due_date,
                       bl.status AS borrow_status
                FROM reservations r
                JOIN books b ON r.book_id = b.id
                JOIN patrons p ON r.patron_id = p.id
                LEFT JOIN users u ON u.patron_id = p.id
                LEFT JOIN borrow_logs bl ON bl.book_id = r.book_id 
                    AND bl.patron_id = r.patron_id 
                    AND bl.status IN ('borrowed', 'overdue')
                $whereClause
                ORDER BY r.reserved_at DESC
                LIMIT $itemsPerPage OFFSET $offset";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        throw $ex;
    }
}

// Store requests data for JavaScript access
$requestsData = [];
foreach ($requests as $row) {
    $requestsData[$row['id']] = $row;
}

include __DIR__ . '/_header.php';
?>

<div class="page-container">
    <div class="page-header">
        <div class="header-content">
            <div class="header-title-row">
                <div>
                    <h1 class="page-title">Book Reservations</h1>
                    <p class="page-subtitle">Manage and review book reservation requests</p>
                </div>
                <div class="header-actions">
                    <button class="btn-refresh" onclick="window.location.reload()">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M23 4v6h-6"/>
                            <path d="M1 20v-6h6"/>
                            <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>
                        </svg>
                        Refresh
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="request-container">
        <!-- Compact Filters Card -->
        <div class="filters-section card">
            <div class="filters-header">
                <h3>
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>
                    </svg>
                    Filters & Search
                </h3>
                <button class="btn-clear-filters" onclick="clearFilters()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M18 6 6 18"/>
                        <path d="m6 6 12 12"/>
                    </svg>
                    Clear All
                </button>
            </div>
            
            <form id="filterForm" method="GET" class="filters-grid compact-grid">
                <!-- Search Input -->
                <div class="filter-group">
                    <label for="searchInput" class="filter-label">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="11" cy="11" r="8"/>
                            <path d="m21 21-4.35-4.35"/>
                        </svg>
                        Search
                    </label>
                    <input type="text" 
                           id="searchInput" 
                           name="search" 
                           placeholder="Search books, users, email..." 
                           value="<?php echo htmlspecialchars($searchTerm); ?>"
                           class="search-input">
                </div>
                
                <!-- Status Filter -->
                <div class="filter-group">
                    <label for="statusFilter" class="filter-label">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                            <polyline points="22 4 12 14.01 9 11.01"/>
                        </svg>
                        Status
                    </label>
                    <select id="statusFilter" name="status" class="form-select">
                        <option value="all" <?php echo $currentFilter === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                        <option value="pending" <?php echo $currentFilter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="approved" <?php echo $currentFilter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                        <option value="declined" <?php echo $currentFilter === 'declined' ? 'selected' : ''; ?>>Declined</option>
                        <option value="cancelled" <?php echo $currentFilter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        <option value="expired" <?php echo $currentFilter === 'expired' ? 'selected' : ''; ?>>Expired</option>
                        <option value="fulfilled" <?php echo $currentFilter === 'fulfilled' ? 'selected' : ''; ?>>Fulfilled</option>
                    </select>
                </div>
                
                <!-- Date Range -->
                <div class="filter-group">
                    <label class="filter-label">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                            <line x1="16" y1="2" x2="16" y2="6"/>
                            <line x1="8" y1="2" x2="8" y2="6"/>
                            <line x1="3" y1="10" x2="21" y2="10"/>
                        </svg>
                        Date Range
                    </label>
                    <div class="date-range-group compact">
                        <input type="date" 
                               id="dateFrom" 
                               name="date_from" 
                               value="<?php echo htmlspecialchars($dateFrom); ?>"
                               class="form-input date-input"
                               placeholder="From">
                        <span class="date-separator">to</span>
                        <input type="date" 
                               id="dateTo" 
                               name="date_to" 
                               value="<?php echo htmlspecialchars($dateTo); ?>"
                               class="form-input date-input"
                               placeholder="To">
                    </div>
                </div>
                
                <!-- Quick Stats -->
                <div class="filter-group">
                    <label class="filter-label">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M12 20h9"/>
                            <path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/>
                        </svg>
                        Quick Stats
                    </label>
                    <div class="stats-badges compact">
                        <a href="?status=pending" class="stat-badge pending <?php echo $currentFilter === 'pending' ? 'active' : ''; ?>" title="Pending">
                            <span class="stat-count"><?php echo $counts['pending'] ?? 0; ?></span>
                        </a>
                        <a href="?status=approved" class="stat-badge approved <?php echo $currentFilter === 'approved' ? 'active' : ''; ?>" title="Approved">
                            <span class="stat-count"><?php echo $counts['approved'] ?? 0; ?></span>
                        </a>
                        <a href="?status=declined" class="stat-badge declined <?php echo $currentFilter === 'declined' ? 'active' : ''; ?>" title="Declined">
                            <span class="stat-count"><?php echo $counts['declined'] ?? 0; ?></span>
                        </a>
                        <a href="?status=cancelled" class="stat-badge cancelled <?php echo $currentFilter === 'cancelled' ? 'active' : ''; ?>" title="Cancelled">
                            <span class="stat-count"><?php echo $counts['cancelled'] ?? 0; ?></span>
                        </a>
                    </div>
                </div>
                
                <!-- Apply Button -->
                <div class="filter-group filter-actions">
                    <button type="submit" class="btn-apply-filters">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="11" cy="11" r="8"/>
                            <path d="m21 21-4.35-4.35"/>
                        </svg>
                        Apply Filters
                    </button>
                </div>
            </form>
        </div>

        <!-- Results Card -->
        <div class="results-section card">
            <div class="results-header">
                <h3>
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>
                        <polyline points="17 21 17 13 7 13 7 21"/>
                        <polyline points="7 3 7 8 15 8"/>
                    </svg>
                    Reservation Requests
                    <span class="results-count"><?php echo $totalItems ?? 0; ?> total • Page <?php echo $currentPage; ?> of <?php echo max(1, $totalPages); ?></span>
                </h3>
                <div class="results-actions">
                    <button class="btn-export" onclick="exportToCSV()">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2 2v-4"/>
                            <polyline points="7 10 12 15 17 10"/>
                            <line x1="12" y1="15" x2="12" y2="3"/>
                        </svg>
                        Export CSV
                    </button>
                </div>
            </div>

            <?php if (empty($requests)): ?>
                <div class="empty-state">
                    <div class="empty-icon">
                        <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>
                            <polyline points="17 21 17 13 7 13 7 21"/>
                            <polyline points="7 3 7 8 15 8"/>
                            <line x1="9" y1="15" x2="15" y2="15"/>
                        </svg>
                    </div>
                    <h3>No Reservation Requests Found</h3>
                    <p><?php echo $currentFilter !== 'all' || !empty($searchTerm) || !empty($dateFrom) || !empty($dateTo) ? "No requests match your current filters." : "There are currently no book reservation requests."; ?></p>
                    <?php if ($currentFilter !== 'all' || !empty($searchTerm) || !empty($dateFrom) || !empty($dateTo)): ?>
                        <button onclick="clearFilters()" class="btn-primary">Clear All Filters</button>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="requests-grid">
                    <?php foreach ($requests as $row): 
                        $status = strtolower($row['status'] ?? '');
                        $resId = (int)($row['id'] ?? 0);
                        $bookId = (int)($row['book_id'] ?? 0);
                        $bookCopyId = isset($row['book_copy_id']) ? (int)$row['book_copy_id'] : null;
                        $patronId = (int)($row['patron_id'] ?? 0);
                        $borrowId = isset($row['borrow_id']) ? (int)$row['borrow_id'] : 0;
                        $copyId = isset($row['copy_id']) ? (int)$row['copy_id'] : null;
                        
                        // Get book cover image - SIMPLIFIED: Just use the path directly
                        $bookCover = null;
                        if (!empty($row['book_cover'])) {
                            $bookCover = $row['book_cover'];
                        }
                        
                        // SIMPLIFIED: Just construct the path and let the browser handle it
                        // If the image doesn't exist, the onerror handler will show default
                        $coverUrl = '../assets/images/default-book-cover.jpg';
                        if ($bookCover) {
                            // Direct path to covers directory
                            $coverUrl = '../uploads/covers/' . htmlspecialchars($bookCover);
                        }
                        
                        // Format dates
                        $reservedDate = isset($row['reserved_at']) ? date('M d, Y', strtotime($row['reserved_at'])) : 'N/A';
                        $expirationDate = isset($row['expiration_date']) ? date('M d, Y', strtotime($row['expiration_date'])) : 'N/A';
                        $dueDate = isset($row['borrow_due_date']) ? date('M d, Y', strtotime($row['borrow_due_date'])) : '';
                        
                        // Determine status color
                        $statusColors = [
                            'pending' => '#f59e0b',
                            'approved' => '#10b981',
                            'declined' => '#ef4444',
                            'cancelled' => '#6b7280',
                            'expired' => '#8b5cf6',
                            'fulfilled' => '#3b82f6'
                        ];
                        $statusColor = $statusColors[$status] ?? '#6b7280';
                    ?>
                    <div class="request-card" data-status="<?php echo $status; ?>" data-id="<?php echo $resId; ?>">
                        <!-- Card Header -->
                        <div class="request-card-header">
                            <div class="request-info">
                                <span class="request-id">#<?php echo $resId; ?></span>
                                <span class="request-date"><?php echo $reservedDate; ?></span>
                            </div>
                            <span class="request-status" style="background: <?php echo $statusColor; ?>20; color: <?php echo $statusColor; ?>; border-color: <?php echo $statusColor; ?>;">
                                <?php echo ucfirst($status); ?>
                            </span>
                        </div>
                        
                        <!-- Book Info with Cover Image -->
                        <div class="request-book-info">
                            <div class="book-cover-container">
                                <img src="<?php echo $coverUrl; ?>" 
                                     alt="Book Cover" 
                                     class="book-cover"
                                     onerror="this.onerror=null; this.src='../assets/images/default-book-cover.jpg';">
                            </div>
                            <div class="book-details">
                                <h4 class="book-title"><?php echo htmlspecialchars($row['book_name'] ?? ''); ?></h4>
                                <div class="book-meta">
                                    <?php if (!empty($row['book_author'])): ?>
                                    <span class="meta-item">by <?php echo htmlspecialchars($row['book_author']); ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($row['category_name'])): ?>
                                    <span class="meta-item category-tag"><?php echo htmlspecialchars($row['category_name']); ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($row['copy_number'])): ?>
                                    <span class="meta-item copy-tag">Copy #<?php echo htmlspecialchars($row['copy_number']); ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($row['notes'])): ?>
                                <div class="request-notes">
                                    <strong>Notes:</strong> <?php echo htmlspecialchars($row['notes']); ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- User Info -->
                        <div class="request-user-info">
                            <div class="user-avatar">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                                    <circle cx="12" cy="7" r="4"/>
                                </svg>
                            </div>
                            <div class="user-details">
                                <h5 class="user-name"><?php echo htmlspecialchars($row['user_name'] ?? 'N/A'); ?></h5>
                                <div class="user-meta">
                                    <span class="meta-item">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                                            <circle cx="12" cy="7" r="4"/>
                                        </svg>
                                        <?php echo htmlspecialchars($row['user_role'] ?? 'N/A'); ?>
                                    </span>
                                    <span class="meta-item">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <circle cx="12" cy="12" r="4"/>
                                            <path d="M16 8v5a3 3 0 0 0 6 0v-1a10 10 0 1 0-4 8"/>
                                        </svg>
                                        <?php echo htmlspecialchars($row['email'] ?? 'N/A'); ?>
                                    </span>
                                    <?php if (!empty($row['student_id'])): ?>
                                    <span class="meta-item">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M12 19l7-7 3 3-7 7-3-3z"/>
                                            <path d="M18 13l-1.5-7.5L2 2l3.5 14.5L13 18l5-5z"/>
                                            <path d="M2 2l7.586 7.586"/>
                                            <circle cx="11" cy="11" r="2"/>
                                        </svg>
                                        ID: <?php echo htmlspecialchars($row['student_id']); ?>
                                    </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Dates Info -->
                        <div class="request-dates-info">
                            <div class="date-item">
                                <div class="date-label">Reserved</div>
                                <div class="date-value"><?php echo $reservedDate; ?></div>
                            </div>
                            <div class="date-item">
                                <div class="date-label">Expires</div>
                                <div class="date-value"><?php echo $expirationDate; ?></div>
                            </div>
                            <?php if (!empty($dueDate)): ?>
                            <div class="date-item">
                                <div class="date-label">Due Date</div>
                                <div class="date-value"><?php echo $dueDate; ?></div>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Actions -->
                        <div class="request-actions">
                            <?php if ($status === 'pending'): ?>
                                <button class="btn-action btn-accept" 
                                        data-res-id="<?php echo $resId; ?>" 
                                        data-book-id="<?php echo $bookId; ?>" 
                                        data-patron-id="<?php echo $patronId; ?>"
                                        data-copy-id="<?php echo $bookCopyId; ?>">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                                        <polyline points="22 4 12 14.01 9 11.01"/>
                                    </svg>
                                    Accept
                                </button>
                                <button class="btn-action btn-decline" 
                                        data-res-id="<?php echo $resId; ?>">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <circle cx="12" cy="12" r="10"/>
                                        <line x1="15" y1="9" x2="9" y2="15"/>
                                        <line x1="9" y1="9" x2="15" y2="15"/>
                                    </svg>
                                    Decline
                                </button>
                            <?php elseif (in_array($status, ['approved', 'active'], true)): ?>
                                <?php if ($borrowId): ?>
                                    <button class="btn-action btn-edit" 
                                            data-res-id="<?php echo $resId; ?>" 
                                            data-borrow-id="<?php echo $borrowId; ?>" 
                                            data-due-date="<?php echo htmlspecialchars($row['borrow_due_date'] ?? ''); ?>">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M12 20h9"/>
                                            <path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/>
                                        </svg>
                                        Edit Due Date
                                    </button>
                                    <button class="btn-action btn-return" 
                                            data-res-id="<?php echo $resId; ?>" 
                                            data-borrow-id="<?php echo $borrowId; ?>"
                                            data-book-id="<?php echo $bookId; ?>"
                                            data-copy-id="<?php echo $copyId; ?>">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M9 10l4 4 4-4"/>
                                            <path d="M12 4v12"/>
                                            <path d="M5 18h14"/>
                                        </svg>
                                        Mark Returned
                                    </button>
                                <?php else: ?>
                                    <span class="no-borrow">No borrow record found</span>
                                <?php endif; ?>
                            <?php elseif ($status === 'declined' && !empty($row['reason'])): ?>
                                <div class="decline-reason">
                                    <strong>Reason:</strong> <?php echo htmlspecialchars($row['reason']); ?>
                                </div>
                            <?php endif; ?>
                            
                            <!-- View Details Button -->
                            <button class="btn-action btn-view-details" 
                                    onclick="viewReservationDetails(<?php echo $resId; ?>)">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="11" cy="11" r="8"/>
                                    <path d="m21 21-4.35-4.35"/>
                                </svg>
                                View Details
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                <div class="pagination-container">
                    <div class="pagination-info">
                        Showing <?php echo count($requests); ?> of <?php echo $totalItems; ?> requests
                    </div>
                    <div class="pagination">
                        <?php if ($currentPage > 1): ?>
                            <a href="?<?php echo buildPaginationUrl($currentPage - 1); ?>" class="pagination-btn prev">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M15 18l-6-6 6-6"/>
                                </svg>
                                Previous
                            </a>
                        <?php else: ?>
                            <span class="pagination-btn prev disabled">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M15 18l-6-6 6-6"/>
                                </svg>
                                Previous
                            </span>
                        <?php endif; ?>
                        
                        <div class="pagination-pages">
                            <?php
                            // Show page numbers
                            $startPage = max(1, $currentPage - 2);
                            $endPage = min($totalPages, $currentPage + 2);
                            
                            if ($startPage > 1) {
                                echo '<a href="?' . buildPaginationUrl(1) . '" class="page-number">1</a>';
                                if ($startPage > 2) echo '<span class="page-dots">...</span>';
                            }
                            
                            for ($i = $startPage; $i <= $endPage; $i++) {
                                if ($i == $currentPage) {
                                    echo '<span class="page-number active">' . $i . '</span>';
                                } else {
                                    echo '<a href="?' . buildPaginationUrl($i) . '" class="page-number">' . $i . '</a>';
                                }
                            }
                            
                            if ($endPage < $totalPages) {
                                if ($endPage < $totalPages - 1) echo '<span class="page-dots">...</span>';
                                echo '<a href="?' . buildPaginationUrl($totalPages) . '" class="page-number">' . $totalPages . '</a>';
                            }
                            ?>
                        </div>
                        
                        <?php if ($currentPage < $totalPages): ?>
                            <a href="?<?php echo buildPaginationUrl($currentPage + 1); ?>" class="pagination-btn next">
                                Next
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M9 18l6-6-6-6"/>
                                </svg>
                            </a>
                        <?php else: ?>
                            <span class="pagination-btn next disabled">
                                Next
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M9 18l6-6-6-6"/>
                                </svg>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Reservation Details Modal -->
<div id="detailsModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="11" cy="11" r="8"/>
                    <path d="m21 21-4.35-4.35"/>
                </svg>
                Reservation Details
            </h2>
            <button class="modal-close" onclick="closeDetailsModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div id="detailsLoading" class="loading-state">
                <div class="spinner"></div>
                <p>Loading reservation details...</p>
            </div>
            <div id="detailsContent" class="details-content" style="display: none;">
                <!-- Details will be loaded here -->
            </div>
        </div>
    </div>
</div>

<!-- Decline Reason Modal -->
<div id="declineModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="15" y1="9" x2="9" y2="15"/>
                    <line x1="9" y1="9" x2="15" y2="15"/>
                </svg>
                Decline Reservation
            </h2>
            <button class="modal-close" onclick="closeDeclineModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label for="declineReason" class="form-label">Reason for Declining</label>
                <select id="declineReason" class="form-select" onchange="toggleCustomReason()">
                    <option value="">Select a reason...</option>
                    <option value="no_availability">No Availability</option>
                    <option value="patron_limit">Patron Borrowing Limit Reached</option>
                    <option value="reservation_expired">Reservation Expired</option>
                    <option value="duplicate_request">Duplicate Request</option>
                    <option value="invalid_information">Invalid Information</option>
                    <option value="other">Other (specify below)</option>
                </select>
            </div>
            <div class="form-group">
                <label for="customReason" class="form-label" style="display: none;">Custom Reason</label>
                <textarea id="customReason" 
                          class="form-textarea" 
                          placeholder="Enter your custom reason here..."
                          rows="3"
                          style="display: none;"></textarea>
            </div>
            <div class="modal-actions">
                <button class="btn-secondary" onclick="closeDeclineModal()">Cancel</button>
                <button class="btn-primary" onclick="submitDecline()">Submit</button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Due Date Modal -->
<div id="editModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 20h9"/>
                    <path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/>
                </svg>
                Edit Due Date
            </h2>
            <button class="modal-close" onclick="closeEditModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label for="newDueDate" class="form-label">New Due Date</label>
                <input type="date" 
                       id="newDueDate" 
                       class="form-input"
                       min="<?php echo date('Y-m-d'); ?>">
            </div>
            <div class="modal-actions">
                <button class="btn-secondary" onclick="closeEditModal()">Cancel</button>
                <button class="btn-primary" onclick="submitEdit()">Update</button>
            </div>
        </div>
    </div>
</div>

<script>
const csrfToken = '<?php echo $csrf_token; ?>';
let currentReservationId = 0;
let currentBorrowId = 0;
let currentDueDate = '';

// Store reservation data from PHP
const reservationsData = <?php echo json_encode($requestsData); ?>;

// Helper function to build pagination URL
function buildPaginationUrl(page) {
    const params = new URLSearchParams(window.location.search);
    params.set('page', page);
    return params.toString();
}

// Clear all filters
function clearFilters() {
    window.location.href = window.location.pathname;
}

// Export to CSV
function exportToCSV() {
    const params = new URLSearchParams(window.location.search);
    params.set('export', 'csv');
    const exportUrl = window.location.pathname + '?' + params.toString();
    window.open(exportUrl, '_blank');
}

// View reservation details
function viewReservationDetails(resId) {
    const modal = document.getElementById('detailsModal');
    const loading = document.getElementById('detailsLoading');
    const content = document.getElementById('detailsContent');
    
    modal.style.display = 'block';
    document.body.style.overflow = 'hidden';
    loading.style.display = 'flex';
    content.style.display = 'none';
    
    try {
        const reservation = reservationsData[resId];
        
        if (!reservation) {
            throw new Error('Reservation not found in current view');
        }
        
        // Format dates
        const reservedDate = reservation.reserved_at ? 
            new Date(reservation.reserved_at).toLocaleDateString('en-US', {
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            }) : 'N/A';
        
        const expirationDate = reservation.expiration_date ? 
            new Date(reservation.expiration_date).toLocaleDateString('en-US', {
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            }) : 'N/A';
        
        const borrowDueDate = reservation.borrow_due_date ? 
            new Date(reservation.borrow_due_date).toLocaleDateString('en-US', {
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            }) : 'N/A';
        
        // Get book cover image - SIMPLIFIED: Use direct path
        let bookCoverUrl = '../assets/images/default-book-cover.jpg';
        if (reservation.book_cover) {
            bookCoverUrl = '../uploads/covers/' + escapeHtml(reservation.book_cover);
        }
        
        // Build details HTML with cover image
        let detailsHTML = `
            <div class="details-header">
                <div class="book-cover-large">
                    <img src="${bookCoverUrl}" 
                         alt="Book Cover" 
                         onerror="this.onerror=null; this.src='../assets/images/default-book-cover.jpg';">
                </div>
                <div class="book-basic-info">
                    <h3 class="book-title-large">${escapeHtml(reservation.book_name || 'Unknown')}</h3>
                    ${reservation.book_author ? `<p class="book-author"><strong>Author:</strong> ${escapeHtml(reservation.book_author)}</p>` : ''}
                    ${reservation.isbn ? `<p class="book-isbn"><strong>ISBN:</strong> ${escapeHtml(reservation.isbn)}</p>` : ''}
                    ${reservation.category_name ? `<p class="book-category"><strong>Category:</strong> ${escapeHtml(reservation.category_name)}</p>` : ''}
                </div>
            </div>
            
            <div class="details-grid">
                <div class="details-section">
                    <h4>Reservation Information</h4>
                    <div class="detail-item">
                        <span class="detail-label">Reservation ID:</span>
                        <span class="detail-value">#${reservation.id || resId}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Status:</span>
                        <span class="detail-value status-${reservation.status || 'unknown'}">${(reservation.status || 'Unknown').charAt(0).toUpperCase() + (reservation.status || 'Unknown').slice(1)}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Reservation Type:</span>
                        <span class="detail-value">${reservation.reservation_type === 'specific_copy' ? 'Specific Copy' : 'Any Available Copy'}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Reserved Date:</span>
                        <span class="detail-value">${reservedDate}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Expiration Date:</span>
                        <span class="detail-value">${expirationDate}</span>
                    </div>
                    ${reservation.borrow_due_date ? `
                    <div class="detail-item">
                        <span class="detail-label">Due Date:</span>
                        <span class="detail-value">${borrowDueDate}</span>
                    </div>
                    ` : ''}
                </div>
                
                <div class="details-section">
                    <h4>User Information</h4>
                    <div class="detail-item">
                        <span class="detail-label">Name:</span>
                        <span class="detail-value">${escapeHtml(reservation.user_name || 'N/A')}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Role:</span>
                        <span class="detail-value">${escapeHtml(reservation.user_role || 'N/A')}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Email:</span>
                        <span class="detail-value">${escapeHtml(reservation.email || 'N/A')}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Student ID:</span>
                        <span class="detail-value">${escapeHtml(reservation.student_id || reservation.library_id || 'N/A')}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Library ID:</span>
                        <span class="detail-value">${escapeHtml(reservation.library_id || 'N/A')}</span>
                    </div>
                    ${reservation.department ? `<div class="detail-item">
                        <span class="detail-label">Department:</span>
                        <span class="detail-value">${escapeHtml(reservation.department)}</span>
                    </div>` : ''}
                    ${reservation.semester ? `<div class="detail-item">
                        <span class="detail-label">Semester:</span>
                        <span class="detail-value">${escapeHtml(reservation.semester)}</span>
                    </div>` : ''}
                </div>
        `;
        
        // Add location info if available
        if (reservation.current_section || reservation.category_section) {
            const location = reservation.current_section ? 
                `${reservation.current_section}-S${reservation.current_shelf || '1'}-R${reservation.current_row || '1'}-P${reservation.current_slot || '1'}` :
                `${reservation.category_section || 'A'}-S${reservation.shelf_recommendation || 1}-R${reservation.row_recommendation || 1}-P${reservation.slot_recommendation || 1}`;
            
            detailsHTML += `
                <div class="details-section">
                    <h4>Book Location</h4>
                    <div class="detail-item">
                        <span class="detail-label">Location:</span>
                        <span class="detail-value location-code">${location}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Section:</span>
                        <span class="detail-value">${reservation.current_section || reservation.category_section || 'A'}</span>
                    </div>
                    ${reservation.copy_number ? `<div class="detail-item">
                        <span class="detail-label">Copy Number:</span>
                        <span class="detail-value">${escapeHtml(reservation.copy_number)}</span>
                    </div>` : ''}
                </div>
            `;
        }
        
        // Add notes if available
        if (reservation.notes) {
            detailsHTML += `
                <div class="details-section">
                    <h4>User Notes</h4>
                    <div class="detail-notes">${escapeHtml(reservation.notes)}</div>
                </div>
            `;
        }
        
        // Add decline reason if available
        if (reservation.status === 'declined' && reservation.reason) {
            detailsHTML += `
                <div class="details-section">
                    <h4>Decline Information</h4>
                    <div class="detail-item">
                        <span class="detail-label">Reason:</span>
                        <span class="detail-value">${escapeHtml(reservation.reason)}</span>
                    </div>
                </div>
            `;
        }
        
        detailsHTML += `</div>`;
        
        content.innerHTML = detailsHTML;
        loading.style.display = 'none';
        content.style.display = 'block';
        
    } catch (error) {
        console.error('Error loading details:', error);
        content.innerHTML = `
            <div class="empty-state error">
                <div class="empty-icon">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/>
                        <line x1="12" y1="8" x2="12" y2="12"/>
                        <line x1="12" y1="16" x2="12.01" y2="16"/>
                    </svg>
                </div>
                <h3>Unable to Load Details</h3>
                <p>${error.message || 'There was an error loading the reservation details.'}</p>
                <button onclick="closeDetailsModal()" class="btn-primary">Close</button>
            </div>
        `;
        loading.style.display = 'none';
        content.style.display = 'block';
    }
}

function closeDetailsModal() {
    document.getElementById('detailsModal').style.display = 'none';
    document.body.style.overflow = 'auto';
}

// Accept reservation - UPDATED TO PREVENT DUPLICATES
async function acceptReservation(resId, bookId, patronId, copyId = null) {
    if (!confirm('Approve this reservation?')) return;
    
    const btn = document.querySelector(`.btn-accept[data-res-id="${resId}"]`);
    const originalText = btn.innerHTML;
    btn.innerHTML = '<div class="spinner-small"></div> Processing...';
    btn.disabled = true;
    
    try {
        // First, find an available copy if no specific copy is reserved
        let actualCopyId = copyId;
        if (!actualCopyId || actualCopyId === 'null' || actualCopyId === '0') {
            // Find first available copy
            const copyRes = await fetch(`../api/get_available_copy.php?book_id=${bookId}`);
            if (copyRes.ok) {
                const copyData = await copyRes.json();
                if (copyData.available_copy_id) {
                    actualCopyId = copyData.available_copy_id;
                }
            }
        }
        
        if (!actualCopyId || actualCopyId === 'null' || actualCopyId === '0') {
            throw new Error('No available copy found for this book');
        }
        
        // First, check if there's already an active borrow for this book copy
        const checkRes = await fetch(`../api/check_existing_borrow.php?book_copy_id=${actualCopyId}&patron_id=${patronId}`);
        const checkData = await checkRes.json();
        
        if (checkData.exists) {
            // Update existing reservation without creating new borrow
            const res = await fetch(`../api/dispatch.php?resource=reservations&id=${resId}`, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify({ 
                    status: 'approved',
                    book_copy_id: actualCopyId 
                })
            });
            
            const out = await res.json();
            
            if (!res.ok) {
                throw new Error(out.error || 'Approval failed');
            }
            
            showToast('Reservation approved! (Book already borrowed)', 'success');
            setTimeout(() => window.location.reload(), 1000);
            return;
        }
        
        // Update reservation to link to specific copy
        const res = await fetch(`../api/dispatch.php?resource=reservations&id=${resId}`, {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify({ 
                status: 'approved',
                book_copy_id: actualCopyId 
            })
        });
        
        const out = await res.json();
        
        if (!res.ok) {
            throw new Error(out.error || 'Approval failed');
        }
        
        // Update book copy status to borrowed (not reserved)
        await fetch(`../api/dispatch.php?resource=book_copies&id=${actualCopyId}`, {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify({
                status: 'borrowed'
            })
        });
        
        // Create borrow log using the safe method
        const now = new Date();
        const borrowedAt = now.toISOString().slice(0, 19).replace('T', ' ');
        const dueDate = new Date(now.getTime() + 14 * 24 * 60 * 60 * 1000); // 14 days
        const formattedDueDate = dueDate.toISOString().slice(0, 19).replace('T', ' ');
        
        const borrowRes = await fetch('../api/dispatch.php?resource=borrow_logs', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify({
                book_id: parseInt(bookId),
                book_copy_id: parseInt(actualCopyId),
                patron_id: parseInt(patronId),
                borrowed_at: borrowedAt,
                due_date: formattedDueDate,
                status: 'borrowed',
                notes: `Reservation ID: ${resId}`
            })
        });
        
        const borrowOut = await borrowRes.json();
        
        if (!borrowRes.ok) {
            // If duplicate error, still show success but don't create new borrow
            if (borrowOut.error && borrowOut.error.includes('Duplicate')) {
                showToast('Reservation approved! (Book already borrowed)', 'success');
            } else {
                console.warn('Borrow log creation warning:', borrowOut.error);
                showToast('Reservation approved with warning: ' + borrowOut.error, 'warning');
            }
        } else {
            showToast('Reservation approved successfully!', 'success');
        }
        
        setTimeout(() => window.location.reload(), 1000);
        
    } catch (err) {
        console.error('Error:', err);
        showToast(err.message || 'Failed to approve reservation', 'error');
        btn.innerHTML = originalText;
        btn.disabled = false;
    }
}

// Decline reservation
function openDeclineModal(resId) {
    currentReservationId = resId;
    document.getElementById('declineModal').style.display = 'block';
    document.body.style.overflow = 'hidden';
    document.getElementById('declineReason').value = '';
    document.getElementById('customReason').style.display = 'none';
    document.getElementById('customReason').value = '';
}

function closeDeclineModal() {
    document.getElementById('declineModal').style.display = 'none';
    document.body.style.overflow = 'auto';
}

function toggleCustomReason() {
    const reason = document.getElementById('declineReason').value;
    const customReason = document.getElementById('customReason');
    const customLabel = document.querySelector('label[for="customReason"]');
    
    if (reason === 'other') {
        customReason.style.display = 'block';
        customLabel.style.display = 'block';
        customReason.focus();
    } else {
        customReason.style.display = 'none';
        customLabel.style.display = 'none';
        customReason.value = '';
    }
}

async function submitDecline() {
    const reasonSelect = document.getElementById('declineReason').value;
    const customReason = document.getElementById('customReason').value.trim();
    
    let finalReason = '';
    
    if (reasonSelect === 'other') {
        if (!customReason) {
            alert('Please enter a custom reason.');
            return;
        }
        finalReason = customReason;
    } else if (reasonSelect) {
        const reasonMap = {
            'no_availability': 'No Availability',
            'patron_limit': 'Patron Borrowing Limit Reached',
            'reservation_expired': 'Reservation Expired',
            'duplicate_request': 'Duplicate Request',
            'invalid_information': 'Invalid Information'
        };
        finalReason = reasonMap[reasonSelect] || reasonSelect;
    } else {
        alert('Please select a reason for declining.');
        return;
    }
    
    if (!confirm(`Decline this reservation?\nReason: ${finalReason}`)) {
        return;
    }
    
    try {
        const res = await fetch(`../api/dispatch.php?resource=reservations&id=${currentReservationId}`, {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify({
                status: 'declined',
                reason: finalReason
            })
        });
        
        const out = await res.json();
        
        if (!res.ok) {
            throw new Error(out.error || 'Decline failed');
        }
        
        showToast('Reservation declined successfully!', 'success');
        closeDeclineModal();
        setTimeout(() => window.location.reload(), 1000);
        
    } catch (err) {
        console.error('Error:', err);
        showToast(err.message || 'Failed to decline reservation', 'error');
    }
}

// Edit due date
function openEditModal(resId, borrowId, currentDue) {
    currentReservationId = resId;
    currentBorrowId = borrowId;
    currentDueDate = currentDue;
    
    const modal = document.getElementById('editModal');
    const dateInput = document.getElementById('newDueDate');
    
    if (currentDueDate) {
        const date = new Date(currentDueDate);
        dateInput.value = date.toISOString().split('T')[0];
    } else {
        const defaultDate = new Date();
        defaultDate.setDate(defaultDate.getDate() + 7);
        dateInput.value = defaultDate.toISOString().split('T')[0];
    }
    
    dateInput.min = new Date().toISOString().split('T')[0];
    modal.style.display = 'block';
    document.body.style.overflow = 'hidden';
}

function closeEditModal() {
    document.getElementById('editModal').style.display = 'none';
    document.body.style.overflow = 'auto';
}

async function submitEdit() {
    const newDate = document.getElementById('newDueDate').value;
    
    if (!newDate) {
        alert('Please select a new due date.');
        return;
    }
    
    if (!confirm(`Update due date to ${newDate}?`)) {
        return;
    }
    
    try {
        const res = await fetch(`../api/dispatch.php?resource=borrow_logs&id=${currentBorrowId}`, {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify({
                due_date: newDate + ' 23:59:59'
            })
        });
        
        const out = await res.json();
        
        if (!res.ok) {
            throw new Error(out.error || 'Update failed');
        }
        
        showToast('Due date updated successfully!', 'success');
        closeEditModal();
        setTimeout(() => window.location.reload(), 1000);
        
    } catch (err) {
        console.error('Error:', err);
        showToast(err.message || 'Failed to update due date', 'error');
    }
}

// Return book - Updated to return to correct location and make available
async function returnBook(resId, borrowId, bookId, copyId = null) {
    if (!confirm('Mark this book as returned?')) return;
    
    const now = new Date();
    const returnedAt = now.toISOString().slice(0, 19).replace('T', ' ');
    
    try {
        // First, get the book copy details to return to correct location
        let copyDetails = null;
        if (copyId) {
            const copyRes = await fetch(`../api/dispatch.php?resource=book_copies&id=${copyId}`);
            if (copyRes.ok) {
                copyDetails = await copyRes.json();
            }
        }
        
        // Update borrow log to returned status
        const borrowRes = await fetch(`../api/dispatch.php?resource=borrow_logs&id=${borrowId}`, {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify({
                status: 'returned',
                returned_at: returnedAt
            })
        });
        
        const borrowOut = await borrowRes.json();
        
        if (!borrowRes.ok) {
            throw new Error(borrowOut.error || 'Return failed');
        }
        
        // Update book copy status to available
        if (copyId) {
            const updateData = {
                status: 'available'
            };
            
            // If the copy has location information, ensure it's preserved
            if (copyDetails && copyDetails.current_section) {
                updateData.current_section = copyDetails.current_section;
                updateData.current_shelf = copyDetails.current_shelf;
                updateData.current_row = copyDetails.current_row;
                updateData.current_slot = copyDetails.current_slot;
            }
            
            await fetch(`../api/dispatch.php?resource=book_copies&id=${copyId}`, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify(updateData)
            });
        }
        
        // Update reservation status to fulfilled
        await fetch(`../api/dispatch.php?resource=reservations&id=${resId}`, {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify({
                status: 'fulfilled'
            })
        });
        
        showToast('Book marked as returned successfully! The book is now available again.', 'success');
        setTimeout(() => window.location.reload(), 1000);
        
    } catch (err) {
        console.error('Error:', err);
        showToast(err.message || 'Failed to mark book as returned', 'error');
    }
}

// Show toast message
function showToast(message, type = 'info') {
    const existingToasts = document.querySelectorAll('.toast');
    existingToasts.forEach(toast => toast.remove());
    
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.innerHTML = `
        <div class="toast-icon">
            ${type === 'success' ? '✓' : type === 'error' ? '✗' : 'ℹ'}
        </div>
        <div class="toast-message">${escapeHtml(message)}</div>
        <button class="toast-close" onclick="this.parentElement.remove()">&times;</button>
    `;
    
    document.body.appendChild(toast);
    
    setTimeout(() => {
        if (toast.parentElement) {
            toast.remove();
        }
    }, 5000);
}

// Escape HTML
function escapeHtml(s) {
    const div = document.createElement('div');
    div.textContent = s;
    return div.innerHTML;
}

// Event listeners
document.addEventListener('DOMContentLoaded', () => {
    // Accept buttons
    document.querySelectorAll('.btn-accept').forEach(btn => {
        btn.addEventListener('click', () => {
            const resId = btn.getAttribute('data-res-id');
            const bookId = btn.getAttribute('data-book-id');
            const patronId = btn.getAttribute('data-patron-id');
            const copyId = btn.getAttribute('data-copy-id');
            acceptReservation(resId, bookId, patronId, copyId);
        });
    });
    
    // Decline buttons
    document.querySelectorAll('.btn-decline').forEach(btn => {
        btn.addEventListener('click', () => {
            const resId = btn.getAttribute('data-res-id');
            openDeclineModal(resId);
        });
    });
    
    // Edit buttons
    document.querySelectorAll('.btn-edit').forEach(btn => {
        btn.addEventListener('click', () => {
            const resId = btn.getAttribute('data-res-id');
            const borrowId = btn.getAttribute('data-borrow-id');
            const dueDate = btn.getAttribute('data-due-date');
            openEditModal(resId, borrowId, dueDate);
        });
    });
    
    // Return buttons
    document.querySelectorAll('.btn-return').forEach(btn => {
        btn.addEventListener('click', () => {
            const resId = btn.getAttribute('data-res-id');
            const borrowId = btn.getAttribute('data-borrow-id');
            const bookId = btn.getAttribute('data-book-id');
            const copyId = btn.getAttribute('data-copy-id');
            returnBook(resId, borrowId, bookId, copyId);
        });
    });
    
    // Close modals when clicking outside
    window.onclick = function(event) {
        const detailsModal = document.getElementById('detailsModal');
        const declineModal = document.getElementById('declineModal');
        const editModal = document.getElementById('editModal');
        
        if (event.target === detailsModal) closeDetailsModal();
        if (event.target === declineModal) closeDeclineModal();
        if (event.target === editModal) closeEditModal();
    }
});
</script>

<style>
/* Modern Design System */
:root {
    --primary: #4f46e5;
    --primary-dark: #4338ca;
    --primary-light: #eef2ff;
    --secondary: #10b981;
    --secondary-dark: #059669;
    --danger: #ef4444;
    --warning: #f59e0b;
    --success: #10b981;
    --info: #3b82f6;
    
    --gray-50: #f9fafb;
    --gray-100: #f3f4f6;
    --gray-200: #e5e7eb;
    --gray-300: #d1d5db;
    --gray-400: #9ca3af;
    --gray-500: #6b7280;
    --gray-600: #4b5563;
    --gray-700: #374151;
    --gray-800: #1f2937;
    --gray-900: #111827;
    
    --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
    --shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px -1px rgba(0, 0, 0, 0.1);
    --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -2px rgba(0, 0, 0, 0.1);
    --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -4px rgba(0, 0, 0, 0.1);
    --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1);
    
    --radius-sm: 0.375rem;
    --radius: 0.5rem;
    --radius-md: 0.75rem;
    --radius-lg: 1rem;
    --radius-xl: 1.5rem;
    
    --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

/* Base Styles */
.page-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 20px;
}

.page-header {
    margin-bottom: 30px;
}

.page-title {
    font-size: 2.5rem;
    font-weight: 700;
    color: var(--gray-900);
    margin-bottom: 8px;
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
}

.page-subtitle {
    font-size: 1.125rem;
    color: var(--gray-600);
    margin-bottom: 0;
}

.header-title-row {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 20px;
}

.header-actions {
    display: flex;
    gap: 12px;
    align-items: center;
}

.btn-refresh {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    background: var(--primary);
    color: white;
    border: none;
    border-radius: var(--radius);
    font-weight: 600;
    font-size: 0.95rem;
    cursor: pointer;
    transition: var(--transition);
}

.btn-refresh:hover {
    background: var(--primary-dark);
    transform: translateY(-2px);
}

/* Card Styles */
.card {
    background: white;
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-lg);
    overflow: hidden;
    margin-bottom: 24px;
}

/* Compact Filters Section */
.filters-section {
    margin-bottom: 24px;
}

.filters-header {
    padding: 16px 24px;
    border-bottom: 1px solid var(--gray-200);
    background: linear-gradient(135deg, var(--gray-50), white);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.filters-header h3 {
    margin: 0;
    font-size: 1.125rem;
    color: var(--gray-800);
    display: flex;
    align-items: center;
    gap: 8px;
}

.btn-clear-filters {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    background: var(--gray-100);
    color: var(--gray-700);
    border: 1px solid var(--gray-300);
    border-radius: var(--radius);
    font-size: 0.875rem;
    font-weight: 500;
    cursor: pointer;
    transition: var(--transition);
}

.btn-clear-filters:hover {
    background: var(--gray-200);
    border-color: var(--gray-400);
}

/* Compact Filters Grid */
.compact-grid {
    padding: 20px 24px;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 20px;
    align-items: start;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.filter-label {
    font-weight: 500;
    color: var(--gray-700);
    font-size: 0.875rem;
    display: flex;
    align-items: center;
    gap: 6px;
}

.search-input {
    padding: 8px 12px;
    border: 1px solid var(--gray-300);
    border-radius: var(--radius);
    font-size: 0.95rem;
    color: var(--gray-800);
    background: white;
    width: 100%;
    transition: var(--transition);
}

.search-input:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
}

.form-select {
    padding: 8px 12px;
    border: 1px solid var(--gray-300);
    border-radius: var(--radius);
    background: white;
    color: var(--gray-800);
    font-size: 0.95rem;
    transition: var(--transition);
    width: 100%;
}

.form-select:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
}

.date-range-group.compact {
    display: flex;
    align-items: center;
    gap: 8px;
}

.date-range-group.compact .date-input {
    flex: 1;
    padding: 8px 12px;
    border: 1px solid var(--gray-300);
    border-radius: var(--radius);
    font-size: 0.95rem;
    color: var(--gray-800);
    background: white;
    transition: var(--transition);
    min-width: 120px;
}

.date-range-group.compact .date-input:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
}

.date-range-group.compact .date-separator {
    color: var(--gray-500);
    font-weight: 500;
    font-size: 0.875rem;
    min-width: 24px;
    text-align: center;
}

/* Compact Stats Badges */
.stats-badges.compact {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
}

.stats-badges.compact .stat-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px;
    background: var(--gray-100);
    color: var(--gray-700);
    border: 1px solid var(--gray-300);
    border-radius: 16px;
    font-size: 0.875rem;
    text-decoration: none;
    transition: var(--transition);
    min-width: 40px;
    justify-content: center;
}

.stats-badges.compact .stat-badge:hover {
    transform: translateY(-2px);
    text-decoration: none;
}

.stats-badges.compact .stat-badge.active {
    font-weight: 600;
}

.stats-badges.compact .stat-badge.pending.active,
.stats-badges.compact .stat-badge.pending:hover {
    background: #fef3c7;
    border-color: #fbbf24;
    color: #92400e;
}

.stats-badges.compact .stat-badge.approved.active,
.stats-badges.compact .stat-badge.approved:hover {
    background: #d1fae5;
    border-color: #10b981;
    color: #065f46;
}

.stats-badges.compact .stat-badge.declined.active,
.stats-badges.compact .stat-badge.declined:hover {
    background: #fee2e2;
    border-color: #ef4444;
    color: #991b1b;
}

.stats-badges.compact .stat-badge.cancelled.active,
.stats-badges.compact .stat-badge.cancelled:hover {
    background: #e5e7eb;
    border-color: #6b7280;
    color: #374151;
}

.stats-badges.compact .stat-count {
    font-weight: 700;
    font-size: 0.95rem;
}

.stats-badges.compact .stat-label {
    display: none; /* Hide label in compact mode */
}

.filter-actions {
    display: flex;
    flex-direction: column;
    justify-content: flex-end;
    height: 100%;
}

.btn-apply-filters {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    color: white;
    border: none;
    border-radius: var(--radius);
    font-weight: 600;
    font-size: 0.95rem;
    cursor: pointer;
    transition: var(--transition);
    width: 100%;
    justify-content: center;
    margin-top: 16px;
}

.btn-apply-filters:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}

/* Results Section */
.results-section {
    margin-bottom: 24px;
}

.results-header {
    padding: 16px 24px;
    border-bottom: 1px solid var(--gray-200);
    background: linear-gradient(135deg, var(--gray-50), white);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.results-header h3 {
    margin: 0;
    font-size: 1.125rem;
    color: var(--gray-800);
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.results-count {
    font-size: 0.875rem;
    color: var(--gray-600);
    background: var(--gray-100);
    padding: 4px 10px;
    border-radius: 16px;
    font-weight: 500;
    margin-left: 10px;
}

.results-actions {
    display: flex;
    gap: 10px;
}

.btn-export {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    background: var(--success);
    color: white;
    border: none;
    border-radius: var(--radius);
    font-weight: 500;
    font-size: 0.875rem;
    cursor: pointer;
    transition: var(--transition);
}

.btn-export:hover {
    background: var(--secondary-dark);
    transform: translateY(-2px);
}

/* Requests Grid */
.requests-grid {
    padding: 20px 24px;
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
    gap: 20px;
    min-height: 400px;
}

@media (max-width: 768px) {
    .requests-grid {
        grid-template-columns: 1fr;
        padding: 16px;
    }
}

/* Request Card with Book Cover */
.request-card {
    background: white;
    border: 1px solid var(--gray-200);
    border-radius: var(--radius-lg);
    padding: 16px;
    transition: var(--transition);
    display: flex;
    flex-direction: column;
    gap: 12px;
    height: 100%;
}

.request-card:hover {
    border-color: var(--gray-300);
    box-shadow: var(--shadow-md);
    transform: translateY(-2px);
}

.request-card-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    padding-bottom: 12px;
    border-bottom: 1px solid var(--gray-200);
}

.request-info {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.request-id {
    font-family: monospace;
    font-size: 0.875rem;
    color: var(--gray-500);
    font-weight: 500;
}

.request-date {
    font-size: 0.875rem;
    color: var(--gray-600);
}

.request-status {
    padding: 4px 10px;
    border-radius: 16px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border: 1px solid;
    white-space: nowrap;
}

/* Book Info with Cover */
.request-book-info {
    display: flex;
    gap: 12px;
    padding-bottom: 12px;
    border-bottom: 1px solid var(--gray-200);
}

.book-cover-container {
    width: 60px;
    height: 80px;
    flex-shrink: 0;
    border-radius: 8px;
    overflow: hidden;
    border: 1px solid var(--gray-200);
    background: var(--gray-100);
}

.book-cover {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.book-details {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.book-title {
    margin: 0;
    font-size: 1rem;
    color: var(--gray-800);
    font-weight: 600;
    line-height: 1.4;
}

.book-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}

.book-meta .meta-item {
    font-size: 0.75rem;
    color: var(--gray-500);
    background: var(--gray-100);
    padding: 2px 8px;
    border-radius: 12px;
}

.book-meta .category-tag {
    background: #e0f2fe;
    color: #0369a1;
}

.book-meta .copy-tag {
    background: #f3e8ff;
    color: #7c3aed;
}

.request-notes {
    font-size: 0.875rem;
    color: var(--gray-600);
    background: var(--gray-50);
    padding: 6px 10px;
    border-radius: var(--radius-sm);
    border-left: 3px solid var(--info);
    margin-top: 4px;
}

/* User Info */
.request-user-info {
    display: flex;
    align-items: center;
    gap: 12px;
    padding-bottom: 12px;
    border-bottom: 1px solid var(--gray-200);
}

.user-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: var(--gray-100);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--gray-600);
    flex-shrink: 0;
}

.user-details {
    flex: 1;
}

.user-name {
    margin: 0 0 4px 0;
    font-size: 0.95rem;
    color: var(--gray-900);
    font-weight: 600;
}

.user-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}

.user-meta .meta-item {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 0.75rem;
    color: var(--gray-500);
}

/* Dates Info */
.request-dates-info {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
    gap: 12px;
    padding-bottom: 12px;
    border-bottom: 1px solid var(--gray-200);
}

.date-item {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.date-label {
    font-size: 0.75rem;
    color: var(--gray-500);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.date-value {
    font-weight: 600;
    color: var(--gray-800);
    font-size: 0.875rem;
}

/* Actions */
.request-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
    margin-top: auto;
    padding-top: 12px;
    border-top: 1px solid var(--gray-200);
}

.btn-action {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    border: none;
    border-radius: var(--radius);
    font-weight: 500;
    font-size: 0.875rem;
    cursor: pointer;
    transition: var(--transition);
    text-decoration: none;
}

.btn-action:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-sm);
}

.btn-accept {
    background: var(--success);
    color: white;
}

.btn-accept:hover {
    background: var(--secondary-dark);
}

.btn-decline {
    background: var(--danger);
    color: white;
}

.btn-decline:hover {
    background: #dc2626;
}

.btn-edit {
    background: var(--info);
    color: white;
}

.btn-edit:hover {
    background: #2563eb;
}

.btn-return {
    background: var(--warning);
    color: white;
}

.btn-return:hover {
    background: #d97706;
}

.btn-view-details {
    background: var(--gray-200);
    color: var(--gray-700);
    margin-left: auto;
}

.btn-view-details:hover {
    background: var(--gray-300);
}

.no-borrow {
    font-size: 0.875rem;
    color: var(--gray-500);
    font-style: italic;
}

.decline-reason {
    font-size: 0.875rem;
    color: var(--gray-600);
    background: var(--gray-50);
    padding: 6px 10px;
    border-radius: var(--radius-sm);
    border-left: 3px solid var(--danger);
    flex: 1;
}

/* Pagination */
.pagination-container {
    padding: 20px 24px;
    border-top: 1px solid var(--gray-200);
    background: var(--gray-50);
    display: flex;
    flex-direction: column;
    gap: 16px;
    align-items: center;
}

.pagination-info {
    font-size: 0.875rem;
    color: var(--gray-600);
    font-weight: 500;
}

.pagination {
    display: flex;
    align-items: center;
    gap: 12px;
}

.pagination-btn {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    background: white;
    color: var(--gray-700);
    border: 1px solid var(--gray-300);
    border-radius: var(--radius);
    font-weight: 500;
    font-size: 0.875rem;
    cursor: pointer;
    transition: var(--transition);
    text-decoration: none;
}

.pagination-btn:hover:not(.disabled) {
    background: var(--gray-50);
    border-color: var(--gray-400);
    text-decoration: none;
}

.pagination-btn.disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.pagination-pages {
    display: flex;
    gap: 4px;
}

.page-number {
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: var(--radius);
    border: 1px solid var(--gray-300);
    background: white;
    color: var(--gray-700);
    cursor: pointer;
    font-weight: 500;
    font-size: 0.875rem;
    transition: var(--transition);
    text-decoration: none;
}

.page-number:hover:not(.active) {
    background: var(--gray-50);
    border-color: var(--gray-400);
    text-decoration: none;
}

.page-number.active {
    background: var(--primary);
    color: white;
    border-color: var(--primary);
}

.page-dots {
    display: flex;
    align-items: center;
    padding: 0 6px;
    color: var(--gray-400);
}

/* Modal Styles */
.modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.5);
    animation: fadeIn 0.3s ease;
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

.modal-content {
    position: relative;
    background-color: white;
    margin: 5% auto;
    padding: 0;
    width: 90%;
    max-width: 600px;
    border-radius: var(--radius-xl);
    box-shadow: var(--shadow-xl);
    animation: slideIn 0.3s ease;
    max-height: 90vh;
    display: flex;
    flex-direction: column;
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
    padding: 20px 24px;
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    color: white;
    border-radius: var(--radius-xl) var(--radius-xl) 0 0;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-title {
    margin: 0;
    font-size: 1.25rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 10px;
}

.modal-close {
    background: none;
    border: none;
    color: white;
    font-size: 1.75rem;
    cursor: pointer;
    padding: 0;
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    transition: var(--transition);
}

.modal-close:hover {
    background: rgba(255, 255, 255, 0.1);
}

.modal-body {
    padding: 24px;
    overflow-y: auto;
    flex: 1;
}

/* Details Modal Specific Styles */
.details-header {
    display: flex;
    gap: 20px;
    margin-bottom: 24px;
    padding-bottom: 20px;
    border-bottom: 1px solid var(--gray-200);
}

.book-cover-large {
    width: 120px;
    height: 160px;
    flex-shrink: 0;
    border-radius: 12px;
    overflow: hidden;
    border: 1px solid var(--gray-200);
    background: var(--gray-100);
}

.book-cover-large img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.book-basic-info {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.book-title-large {
    margin: 0;
    font-size: 1.5rem;
    color: var(--gray-900);
    font-weight: 600;
}

.book-author,
.book-isbn,
.book-category {
    margin: 0;
    font-size: 0.95rem;
    color: var(--gray-600);
}

.book-author strong,
.book-isbn strong,
.book-category strong {
    color: var(--gray-800);
}

.details-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
}

.details-section {
    background: var(--gray-50);
    border: 1px solid var(--gray-200);
    border-radius: var(--radius);
    padding: 16px;
}

.details-section h4 {
    margin: 0 0 12px 0;
    color: var(--gray-800);
    font-size: 1.125rem;
    padding-bottom: 10px;
    border-bottom: 1px solid var(--gray-200);
}

.detail-item {
    margin-bottom: 10px;
}

.detail-item:last-child {
    margin-bottom: 0;
}

.detail-label {
    display: block;
    font-size: 0.75rem;
    color: var(--gray-500);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 4px;
}

.detail-value {
    font-weight: 600;
    color: var(--gray-800);
    font-size: 0.95rem;
    word-break: break-word;
}

.location-code {
    font-family: monospace;
    background: white;
    padding: 4px 8px;
    border-radius: var(--radius-sm);
    border: 1px solid var(--gray-300);
    display: inline-block;
    margin-top: 2px;
}

.detail-notes {
    font-size: 0.95rem;
    color: var(--gray-600);
    line-height: 1.6;
    padding: 10px;
    background: white;
    border-radius: var(--radius-sm);
    border: 1px solid var(--gray-200);
}

/* Status colors for details */
.status-pending { color: #f59e0b; }
.status-approved { color: #10b981; }
.status-declined { color: #ef4444; }
.status-cancelled { color: #6b7280; }
.status-expired { color: #8b5cf6; }
.status-fulfilled { color: #3b82f6; }

/* Form Elements */
.form-textarea {
    padding: 10px 12px;
    border: 1px solid var(--gray-300);
    border-radius: var(--radius);
    background: white;
    color: var(--gray-800);
    font-size: 0.95rem;
    transition: var(--transition);
    width: 100%;
    font-family: inherit;
    resize: vertical;
    min-height: 80px;
}

.form-textarea:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
}

.modal-actions {
    display: flex;
    gap: 12px;
    justify-content: flex-end;
    margin-top: 20px;
}

.btn-primary {
    padding: 10px 20px;
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    color: white;
    border: none;
    border-radius: var(--radius);
    font-weight: 600;
    font-size: 0.95rem;
    cursor: pointer;
    transition: var(--transition);
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}

.btn-secondary {
    padding: 10px 20px;
    background: var(--gray-200);
    color: var(--gray-700);
    border: none;
    border-radius: var(--radius);
    font-weight: 600;
    font-size: 0.95rem;
    cursor: pointer;
    transition: var(--transition);
}

.btn-secondary:hover {
    background: var(--gray-300);
}

/* Loading and Empty States */
.loading-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 60px 20px;
    text-align: center;
}

.spinner {
    width: 40px;
    height: 40px;
    border: 3px solid var(--gray-200);
    border-top: 3px solid var(--primary);
    border-radius: 50%;
    animation: spin 1s linear infinite;
    margin-bottom: 16px;
}

.spinner-small {
    width: 20px;
    height: 20px;
    border: 2px solid rgba(255, 255, 255, 0.3);
    border-top: 2px solid white;
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

.empty-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 60px 20px;
    text-align: center;
    min-height: 400px;
}

.empty-state.error {
    padding: 40px 20px;
}

.empty-icon {
    color: var(--gray-300);
    margin-bottom: 20px;
}

.empty-state h3 {
    margin: 0 0 12px 0;
    color: var(--gray-700);
    font-size: 1.25rem;
}

.empty-state p {
    margin: 0 0 20px 0;
    color: var(--gray-500);
    max-width: 400px;
    line-height: 1.6;
}

/* Toast Notifications */
.toast {
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 1001;
    min-width: 300px;
    max-width: 400px;
    background: white;
    border-radius: var(--radius);
    box-shadow: var(--shadow-xl);
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 16px;
    animation: slideInRight 0.3s ease;
    border-left: 4px solid;
}

@keyframes slideInRight {
    from {
        transform: translateX(100%);
        opacity: 0;
    }
    to {
        transform: translateX(0);
        opacity: 1;
    }
}

.toast-success {
    border-left-color: var(--success);
    background: #f0fdf4;
}

.toast-error {
    border-left-color: var(--danger);
    background: #fef2f2;
}

.toast-info {
    border-left-color: var(--info);
    background: #eff6ff;
}

.toast-icon {
    font-weight: bold;
    font-size: 1.2rem;
    width: 24px;
    height: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.toast-message {
    flex: 1;
    font-size: 0.95rem;
    color: var(--gray-700);
}

.toast-close {
    background: none;
    border: none;
    color: var(--gray-400);
    font-size: 1.5rem;
    cursor: pointer;
    padding: 0;
    width: 24px;
    height: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.toast-close:hover {
    color: var(--gray-600);
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

/* Responsive Design */
@media (max-width: 1024px) {
    .page-title {
        font-size: 2rem;
    }
    
    .header-title-row {
        flex-direction: column;
        align-items: stretch;
        gap: 16px;
    }
    
    .header-actions {
        justify-content: flex-start;
    }
    
    .compact-grid {
        grid-template-columns: 1fr;
    }
    
    .date-range-group.compact {
        flex-direction: column;
        align-items: stretch;
    }
    
    .date-range-group.compact .date-separator {
        text-align: center;
        padding: 4px 0;
    }
}

@media (max-width: 768px) {
    .page-container {
        padding: 16px;
    }
    
    .page-title {
        font-size: 1.75rem;
    }
    
    .page-subtitle {
        font-size: 1rem;
    }
    
    .requests-grid {
        padding: 16px;
        grid-template-columns: 1fr;
    }
    
    .request-actions {
        flex-direction: column;
        align-items: stretch;
    }
    
    .btn-view-details {
        margin-left: 0;
        margin-top: 8px;
    }
    
    .details-header {
        flex-direction: column;
        align-items: center;
        text-align: center;
    }
    
    .book-cover-large {
        width: 100px;
        height: 140px;
    }
    
    .modal-content {
        width: 95%;
        margin: 2% auto;
    }
    
    .modal-actions {
        flex-direction: column;
    }
    
    .modal-actions .btn-primary,
    .modal-actions .btn-secondary {
        width: 100%;
    }
    
    .pagination {
        flex-direction: column;
        gap: 12px;
    }
    
    .pagination-pages {
        order: -1;
    }
}
</style>

<?php 
// Helper function for building pagination URLs
function buildPaginationUrl($page) {
    $params = $_GET;
    $params['page'] = $page;
    return http_build_query($params);
}

include __DIR__ . '/_footer.php'; 
?>