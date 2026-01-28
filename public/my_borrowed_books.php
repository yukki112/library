<?php
// Enhanced My Borrowed Books Page with Table/Grid View, Filtering, and Receipt Viewing
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/config.php';
require_login();
$u = current_user();

// Restrict access to students and non‑staff
if (!in_array($u['role'], ['student','non_staff'], true)) {
    header('Location: dashboard.php');
    exit;
}

$patron_id = $u['patron_id'] ?? 0;
$username = $u['username'] ?? '';
$user_id = $u['id'] ?? 0;

// Get extension settings from database
$pdo = DB::conn();
$extension_settings = [
    'max_extensions' => 2,
    'extension_fee_per_day' => 10,
    'max_extension_days' => 14,
    'notice_days' => 3,
    'overdue_fee_per_day' => 30
];

// Get settings from database
try {
    $settingsStmt = $pdo->query("SELECT `key`, `value` FROM settings");
    $settings = $settingsStmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    if (isset($settings['max_extensions_per_book'])) {
        $extension_settings['max_extensions'] = (int)$settings['max_extensions_per_book'];
    }
    if (isset($settings['extension_fee_per_day'])) {
        $extension_settings['extension_fee_per_day'] = (float)$settings['extension_fee_per_day'];
    }
    if (isset($settings['extension_max_days'])) {
        $extension_settings['max_extension_days'] = (int)$settings['extension_max_days'];
    }
    if (isset($settings['extension_notice_days'])) {
        $extension_settings['notice_days'] = (int)$settings['extension_notice_days'];
    }
    if (isset($settings['overdue_fee_per_day'])) {
        $extension_settings['overdue_fee_per_day'] = (float)$settings['overdue_fee_per_day'];
    }
} catch (Exception $e) {
    // Use defaults
}

// Get active borrows for current user
$active_borrows = [];
$books_data = [];
$copies_data = [];

// Get return history for receipt viewing
$return_history = [];

try {
    // Get all active borrows (borrowed or overdue status)
    $stmt = $pdo->prepare("
        SELECT bl.*, 
               b.title, b.author, b.cover_image_cache, b.isbn, b.publisher, b.year_published, b.category_id,
               bc.copy_number, bc.barcode, bc.book_condition, bc.current_section, bc.current_shelf, bc.current_row, bc.current_slot,
               p.name as patron_name, p.library_id,
               cat.name as category_name
        FROM borrow_logs bl
        LEFT JOIN books b ON bl.book_id = b.id
        LEFT JOIN book_copies bc ON bl.book_copy_id = bc.id
        LEFT JOIN patrons p ON bl.patron_id = p.id
        LEFT JOIN categories cat ON b.category_id = cat.id
        WHERE bl.patron_id = ? AND bl.status IN ('borrowed', 'overdue')
        ORDER BY bl.due_date ASC
    ");
    $stmt->execute([$patron_id]);
    $active_borrows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get return history for receipt viewing
    $history_stmt = $pdo->prepare("
        SELECT bl.*, 
               b.title, b.author, b.cover_image_cache,
               bc.copy_number, bc.barcode,
               p.name as patron_name, p.library_id,
               r.receipt_number, r.pdf_path, r.total_amount, r.payment_date,
               er.receipt_number as extension_receipt, er.extension_fee
        FROM borrow_logs bl
        LEFT JOIN books b ON bl.book_id = b.id
        LEFT JOIN book_copies bc ON bl.book_copy_id = bc.id
        LEFT JOIN patrons p ON bl.patron_id = p.id
        LEFT JOIN receipts r ON bl.id = r.borrow_log_id AND r.extension_request_id IS NULL
        LEFT JOIN extension_requests er ON bl.id = er.borrow_log_id AND er.status = 'approved'
        WHERE bl.patron_id = ? AND bl.status = 'returned'
        ORDER BY bl.returned_at DESC
        LIMIT 50
    ");
    $history_stmt->execute([$patron_id]);
    $return_history = $history_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Debug: Check if we got any data
    if (empty($active_borrows)) {
        error_log("No borrowed books found for patron_id: " . $patron_id);
    } else {
        error_log("Found " . count($active_borrows) . " borrowed books for patron_id: " . $patron_id);
    }
    
    // Calculate days until due and format dates for active borrows
    foreach ($active_borrows as &$borrow) {
        if ($borrow['due_date']) {
            $due_date = new DateTime($borrow['due_date']);
            $now = new DateTime();
            
            // Calculate days difference correctly
            if ($now > $due_date) {
                // Book is overdue
                $interval = $now->diff($due_date);
                $borrow['days_until_due'] = -($interval->days);
                $borrow['is_overdue'] = true;
                $borrow['due_soon'] = false;
            } else {
                // Book is not overdue
                $interval = $now->diff($due_date);
                $borrow['days_until_due'] = $interval->days;
                $borrow['is_overdue'] = false;
                $borrow['due_soon'] = ($borrow['days_until_due'] <= $extension_settings['notice_days']);
            }
            
            // Format dates nicely
            $borrow['borrowed_at_formatted'] = (new DateTime($borrow['borrowed_at']))->format('M d, Y h:i A');
            $borrow['due_date_formatted'] = $due_date->format('M d, Y h:i A');
            $borrow['due_date_short'] = $due_date->format('Y-m-d');
        } else {
            $borrow['days_until_due'] = 0;
            $borrow['is_overdue'] = false;
            $borrow['due_soon'] = false;
            $borrow['borrowed_at_formatted'] = 'N/A';
            $borrow['due_date_formatted'] = 'N/A';
            $borrow['due_date_short'] = '';
        }
    }
    unset($borrow);
    
    // Format dates for return history
    foreach ($return_history as &$history) {
        if ($history['borrowed_at']) {
            $history['borrowed_at_formatted'] = (new DateTime($history['borrowed_at']))->format('M d, Y h:i A');
        }
        if ($history['returned_at']) {
            $history['returned_at_formatted'] = (new DateTime($history['returned_at']))->format('M d, Y h:i A');
        }
        if ($history['due_date']) {
            $history['due_date_formatted'] = (new DateTime($history['due_date']))->format('M d, Y h:i A');
        }
        if ($history['payment_date']) {
            $history['payment_date_formatted'] = (new DateTime($history['payment_date']))->format('M d, Y h:i A');
        }
    }
    unset($history);
    
} catch (Exception $e) {
    error_log("Error fetching borrows: " . $e->getMessage());
    error_log("SQL Error: " . $e->getMessage());
}

// Handle AJAX requests
if (isset($_GET['ajax']) && $_GET['ajax'] == '1' && isset($_POST['action'])) {
    if ($_POST['action'] == 'request_extension') {
        // Handle extension request directly
        try {
            $borrow_log_id = (int)$_POST['borrow_log_id'];
            $book_copy_id = (int)$_POST['book_copy_id'];
            $extension_days = (int)$_POST['extension_days'];
            $reason = $_POST['reason'];
            $extension_fee = (float)$_POST['extension_fee'];
            $receipt_number = $_POST['receipt_number'];
            
            // Calculate new due date
            $current_borrow = $pdo->prepare("SELECT due_date FROM borrow_logs WHERE id = ?");
            $current_borrow->execute([$borrow_log_id]);
            $borrow_data = $current_borrow->fetch();
            
            if (!$borrow_data) {
                die(json_encode(['error' => 'Borrow record not found']));
            }
            
            $current_due_date = new DateTime($borrow_data['due_date']);
            $requested_extension_date = clone $current_due_date;
            $requested_extension_date->modify("+{$extension_days} days");
            
            // Insert into extension_requests table
            $stmt = $pdo->prepare("
                INSERT INTO extension_requests 
                (borrow_log_id, patron_id, book_copy_id, current_due_date, 
                 requested_extension_date, extension_days, reason, status, 
                 extension_fee, receipt_number, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, NOW())
            ");
            
            $success = $stmt->execute([
                $borrow_log_id,
                $patron_id,
                $book_copy_id,
                $current_due_date->format('Y-m-d'),
                $requested_extension_date->format('Y-m-d'),
                $extension_days,
                $reason,
                $extension_fee,
                $receipt_number
            ]);
            
            if ($success) {
                $extension_id = $pdo->lastInsertId();
                
                // Log the transaction
                $log_stmt = $pdo->prepare("
                    INSERT INTO copy_transactions 
                    (book_copy_id, transaction_type, from_status, to_status, notes)
                    VALUES (?, 'extension_requested', NULL, NULL, ?)
                ");
                $log_stmt->execute([
                    $book_copy_id,
                    "Extension requested for borrow #{$borrow_log_id}. Days: {$extension_days}. Reason: " . substr($reason, 0, 100)
                ]);
                
                // Create notification
                $notify_stmt = $pdo->prepare("
                    INSERT INTO notifications 
                    (user_id, role_target, type, message, meta, created_at)
                    VALUES (NULL, 'admin', 'extension_request', 'New extension request pending approval', ?, NOW())
                ");
                $notify_stmt->execute([
                    json_encode([
                        'extension_request_id' => $extension_id,
                        'borrow_log_id' => $borrow_log_id,
                        'patron_id' => $patron_id,
                        'book_copy_id' => $book_copy_id,
                        'extension_days' => $extension_days,
                        'extension_fee' => $extension_fee
                    ])
                ]);
                
                echo json_encode([
                    'success' => true,
                    'id' => $extension_id,
                    'message' => 'Extension request submitted successfully'
                ]);
            } else {
                echo json_encode(['error' => 'Failed to insert extension request']);
            }
            
        } catch (Exception $e) {
            echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }
    elseif ($_POST['action'] == 'get_borrow_details') {
        // Get detailed information about a borrow
        try {
            $borrow_id = (int)$_POST['borrow_id'];
            
            $stmt = $pdo->prepare("
                SELECT bl.*, 
                       b.title, b.author, b.isbn, b.publisher, b.year_published, b.description,
                       bc.copy_number, bc.barcode, bc.book_condition, 
                       bc.current_section, bc.current_shelf, bc.current_row, bc.current_slot,
                       p.name as patron_name, p.library_id, p.email, p.phone,
                       cat.name as category_name,
                       r.receipt_number, r.pdf_path, r.total_amount, r.payment_date, r.payment_method,
                       er.receipt_number as extension_receipt, er.extension_fee, er.extension_days,
                       er.approved_at as extension_approved_at
                FROM borrow_logs bl
                LEFT JOIN books b ON bl.book_id = b.id
                LEFT JOIN book_copies bc ON bl.book_copy_id = bc.id
                LEFT JOIN patrons p ON bl.patron_id = p.id
                LEFT JOIN categories cat ON b.category_id = cat.id
                LEFT JOIN receipts r ON bl.id = r.borrow_log_id AND r.extension_request_id IS NULL
                LEFT JOIN extension_requests er ON bl.id = er.borrow_log_id AND er.status = 'approved'
                WHERE bl.id = ? AND bl.patron_id = ?
            ");
            $stmt->execute([$borrow_id, $patron_id]);
            $details = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($details) {
                // Format dates
                $details['borrowed_at_formatted'] = $details['borrowed_at'] ? 
                    (new DateTime($details['borrowed_at']))->format('M d, Y h:i A') : 'N/A';
                $details['due_date_formatted'] = $details['due_date'] ? 
                    (new DateTime($details['due_date']))->format('M d, Y h:i A') : 'N/A';
                $details['returned_at_formatted'] = $details['returned_at'] ? 
                    (new DateTime($details['returned_at']))->format('M d, Y h:i A') : 'Not returned yet';
                $details['payment_date_formatted'] = $details['payment_date'] ? 
                    (new DateTime($details['payment_date']))->format('M d, Y h:i A') : 'N/A';
                $details['extension_approved_at_formatted'] = $details['extension_approved_at'] ? 
                    (new DateTime($details['extension_approved_at']))->format('M d, Y h:i A') : 'N/A';
                
                echo json_encode([
                    'success' => true,
                    'details' => $details
                ]);
            } else {
                echo json_encode(['error' => 'Borrow record not found']);
            }
            
        } catch (Exception $e) {
            echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }
}

include __DIR__ . '/_header.php';
?>

<div class="page-container">
    <div class="page-header">
        <div class="header-content">
            <div class="header-title-row">
                <div>
                    <h1 class="page-title">My Borrowed Books</h1>
                    <p class="page-subtitle">View and manage your currently borrowed books</p>
                </div>
                <div class="header-stats">
                    <div class="stat-card">
                        <div class="stat-value"><?php echo count($active_borrows); ?></div>
                        <div class="stat-label">Books Borrowed</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?php echo count($return_history); ?></div>
                        <div class="stat-label">Return History</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="borrowed-books-container">
        <!-- Controls Section -->
        <div class="controls-section card">
            <div class="controls-header">
                <h3>
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M3 6h18M7 12h10M10 18h4"/>
                    </svg>
                    Filters & Controls
                </h3>
                <div class="view-toggle">
                    <button class="view-toggle-btn active" data-view="grid">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="3" width="7" height="7"/>
                            <rect x="14" y="3" width="7" height="7"/>
                            <rect x="14" y="14" width="7" height="7"/>
                            <rect x="3" y="14" width="7" height="7"/>
                        </svg>
                        Grid View
                    </button>
                    <button class="view-toggle-btn" data-view="table">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                            <line x1="3" y1="9" x2="21" y2="9"/>
                            <line x1="9" y1="21" x2="9" y2="9"/>
                        </svg>
                        Table View
                    </button>
                </div>
            </div>
            
            <div class="filters-grid">
                <div class="filter-group">
                    <label for="statusFilter" class="filter-label">Status</label>
                    <select id="statusFilter" class="form-select">
                        <option value="all">All Books</option>
                        <option value="borrowed">Borrowed</option>
                        <option value="overdue">Overdue</option>
                        <option value="due_soon">Due Soon</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label for="categoryFilter" class="filter-label">Category</label>
                    <select id="categoryFilter" class="form-select">
                        <option value="">All Categories</option>
                        <?php
                        // Get unique categories from the borrowed books
                        $categories = [];
                        foreach ($active_borrows as $borrow) {
                            if ($borrow['category_id'] && $borrow['category_name']) {
                                $categories[$borrow['category_id']] = $borrow['category_name'];
                            }
                        }
                        foreach ($categories as $cat_id => $cat_name): ?>
                            <option value="<?php echo htmlspecialchars($cat_id); ?>">
                                <?php echo htmlspecialchars($cat_name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label for="sortFilter" class="filter-label">Sort By</label>
                    <select id="sortFilter" class="form-select">
                        <option value="due_date_asc">Due Date (Ascending)</option>
                        <option value="due_date_desc">Due Date (Descending)</option>
                        <option value="title_asc">Title (A-Z)</option>
                        <option value="title_desc">Title (Z-A)</option>
                        <option value="borrowed_date_desc">Recently Borrowed</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label for="searchFilter" class="filter-label">Search</label>
                    <div class="search-input-group">
                        <div class="search-icon">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="11" cy="11" r="8"/>
                                <path d="m21 21-4.35-4.35"/>
                            </svg>
                        </div>
                        <input type="text" 
                               id="searchFilter" 
                               placeholder="Search books..." 
                               class="search-input">
                    </div>
                </div>
            </div>
        </div>

        <!-- Content Area -->
        <div class="content-area">
           

            <!-- Grid View -->
            <div id="gridView" class="books-grid-view">
                <?php if (empty($active_borrows)): ?>
                    <div class="empty-state">
                        <div class="empty-icon">
                            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1 0-5H20"/>
                            </svg>
                        </div>
                        <h3>No Borrowed Books</h3>
                        <p>You don't have any books borrowed at the moment.</p>
                        <a href="request_book.php" class="btn-primary">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M12 5v14M5 12h14"/>
                            </svg>
                            Request a Book
                        </a>
                    </div>
                <?php else: ?>
                    <div class="grid-container" id="booksGrid">
                        <?php foreach ($active_borrows as $borrow): ?>
                            <?php
                            // Calculate extension eligibility
                            $can_extend = false;
                            $extension_reason = '';
                            
                            if ($borrow['status'] === 'borrowed') {
                                if ($borrow['is_overdue']) {
                                    $extension_reason = 'Cannot extend - book is overdue';
                                } elseif ($borrow['extension_attempts'] >= $extension_settings['max_extensions']) {
                                    $extension_reason = 'Maximum extensions reached';
                                } else {
                                    $can_extend = true;
                                }
                            } else {
                                $extension_reason = 'Cannot extend - book status: ' . $borrow['status'];
                            }
                            
                            // Determine status and color
                            $status_color = '#3b82f6'; // blue for normal
                            $status_text = 'Borrowed';
                            
                            if ($borrow['is_overdue']) {
                                $status_color = '#ef4444'; // red for overdue
                                $status_text = 'Overdue';
                            } elseif ($borrow['due_soon']) {
                                $status_color = '#f59e0b'; // yellow for due soon
                                $status_text = 'Due Soon';
                            }
                            
                            // Calculate late fee if overdue
                            $late_fee = 0;
                            if ($borrow['is_overdue'] && $borrow['days_until_due'] < 0) {
                                $days_overdue = abs($borrow['days_until_due']);
                                $late_fee = $days_overdue * $extension_settings['overdue_fee_per_day'];
                            }
                            
                            // Book cover image
                            $cover_image = $borrow['cover_image_cache'] ? 
                                '../uploads/covers/' . htmlspecialchars($borrow['cover_image_cache']) : 
                                '../assets/default-book-cover.png';
                            ?>
                            
                            <div class="book-card-grid" 
                                 data-borrow-id="<?php echo $borrow['id']; ?>"
                                 data-status="<?php echo $borrow['is_overdue'] ? 'overdue' : ($borrow['due_soon'] ? 'due_soon' : 'borrowed'); ?>"
                                 data-category="<?php echo htmlspecialchars($borrow['category_id'] ?? ''); ?>"
                                 data-due-date="<?php echo htmlspecialchars($borrow['due_date_short']); ?>">
                                <div class="book-card-header">
                                    <div class="book-status" style="background: <?php echo $status_color; ?>">
                                        <?php echo $status_text; ?>
                                    </div>
                                    <?php if ($borrow['extension_attempts'] > 0): ?>
                                        <div class="book-extensions">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                                                <line x1="16" y1="2" x2="16" y2="6"/>
                                                <line x1="8" y1="2" x2="8" y2="6"/>
                                                <line x1="3" y1="10" x2="21" y2="10"/>
                                                <path d="M17 14h-5"/>
                                                <path d="M13 18h-1"/>
                                            </svg>
                                            Extended <?php echo $borrow['extension_attempts']; ?> time<?php echo $borrow['extension_attempts'] != 1 ? 's' : ''; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="book-card-image">
                                    <img src="<?php echo $cover_image; ?>" 
                                         alt="<?php echo htmlspecialchars($borrow['title']); ?>"
                                         onerror="this.src='../assets/default-book-cover.png'">
                                </div>
                                
                                <div class="book-card-content">
                                    <h4 class="book-title"><?php echo htmlspecialchars($borrow['title'] ?? 'Unknown Title'); ?></h4>
                                    <p class="book-author"><?php echo htmlspecialchars($borrow['author'] ?? 'Unknown Author'); ?></p>
                                    
                                    <div class="book-meta">
                                        <div class="meta-item">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <rect x="3" y="5" width="18" height="14" rx="2" ry="2"/>
                                                <path d="M7 10h10"/>
                                                <path d="M7 14h4"/>
                                            </svg>
                                            Copy <?php echo htmlspecialchars($borrow['copy_number'] ?? 'N/A'); ?>
                                        </div>
                                        <div class="meta-item">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/>
                                                <circle cx="12" cy="10" r="3"/>
                                            </svg>
                                            <?php echo htmlspecialchars($borrow['current_section'] ?? 'A'); ?>-S<?php echo htmlspecialchars($borrow['current_shelf'] ?? '1'); ?>
                                        </div>
                                    </div>
                                    
                                    <div class="borrow-dates">
                                        <div class="date-item">
                                            <div class="date-label">Borrowed</div>
                                            <div class="date-value"><?php echo $borrow['borrowed_at_formatted']; ?></div>
                                        </div>
                                        <div class="date-item">
                                            <div class="date-label">Due Date</div>
                                            <div class="date-value <?php echo $borrow['is_overdue'] || $borrow['due_soon'] ? 'text-danger' : ''; ?>">
                                                <?php echo $borrow['due_date_formatted']; ?>
                                                <?php if ($borrow['is_overdue']): ?>
                                                    <br><small class="text-danger">⚠️ OVERDUE!</small>
                                                <?php elseif ($borrow['due_soon']): ?>
                                                    <br><small class="text-danger">⚠️ Due soon!</small>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="book-footer">
                                        <div class="days-remaining">
                                            <?php if ($borrow['is_overdue']): ?>
                                                <span class="text-danger">
                                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                        <circle cx="12" cy="12" r="10"/>
                                                        <line x1="12" y1="8" x2="12" y2="12"/>
                                                        <line x1="12" y1="16" x2="12.01" y2="16"/>
                                                    </svg>
                                                    Overdue by <?php echo abs($borrow['days_until_due']); ?> days
                                                    <?php if ($late_fee > 0): ?>
                                                        <br><small>Late fee: ₱<?php echo number_format($late_fee, 2); ?></small>
                                                    <?php endif; ?>
                                                </span>
                                            <?php elseif ($borrow['due_soon']): ?>
                                                <span class="text-warning">
                                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                        <circle cx="12" cy="12" r="10"/>
                                                        <line x1="12" y1="8" x2="12" y2="12"/>
                                                        <line x1="12" y1="16" x2="12.01" y2="16"/>
                                                    </svg>
                                                    Due in <?php echo $borrow['days_until_due']; ?> days
                                                </span>
                                            <?php else: ?>
                                                <span class="text-success">
                                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                                                        <polyline points="22 4 12 14.01 9 11.01"/>
                                                    </svg>
                                                    <?php echo $borrow['days_until_due']; ?> days remaining
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div class="book-actions">
                                            <button class="btn-details" 
                                                    onclick="openDetailsModal(<?php echo $borrow['id']; ?>)"
                                                    title="View Book Details">
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                    <circle cx="12" cy="12" r="10"/>
                                                    <line x1="12" y1="16" x2="12" y2="12"/>
                                                    <line x1="12" y1="8" x2="12.01" y2="8"/>
                                                </svg>
                                                Details
                                            </button>
                                            
                                            <?php if ($can_extend): ?>
                                                <button class="btn-extend" 
                                                        onclick="openExtensionModal(this)"
                                                        data-borrow-id="<?php echo $borrow['id']; ?>"
                                                        data-book-id="<?php echo $borrow['book_id']; ?>"
                                                        data-book-copy-id="<?php echo $borrow['book_copy_id']; ?>"
                                                        data-book-title="<?php echo htmlspecialchars($borrow['title'] ?? 'Unknown'); ?>"
                                                        data-due-date="<?php echo $borrow['due_date']; ?>"
                                                        data-extension-attempts="<?php echo $borrow['extension_attempts']; ?>">
                                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                                        <polyline points="14 2 14 8 20 8"/>
                                                        <path d="M12 18v-6"/>
                                                        <path d="M9 15h6"/>
                                                    </svg>
                                                    Extend
                                                </button>
                                            <?php else: ?>
                                                <span class="cannot-extend" title="<?php echo htmlspecialchars($extension_reason); ?>">
                                                    Cannot extend
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Table View -->
            <div id="tableView" class="books-table-view" style="display: none;">
                <?php if (empty($active_borrows)): ?>
                    <div class="empty-state">
                        <div class="empty-icon">
                            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                                <line x1="3" y1="9" x2="21" y2="9"/>
                                <line x1="9" y1="21" x2="9" y2="9"/>
                            </svg>
                        </div>
                        <h3>No Borrowed Books</h3>
                        <p>You don't have any books borrowed at the moment.</p>
                        <a href="request_book.php" class="btn-primary">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M12 5v14M5 12h14"/>
                            </svg>
                            Request a Book
                        </a>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="borrowed-books-table" id="booksTable">
                            <thead>
                                <tr>
                                    <th>Book Title</th>
                                    <th>Author</th>
                                    <th>Copy #</th>
                                    <th>Category</th>
                                    <th>Borrowed Date</th>
                                    <th>Due Date</th>
                                    <th>Status</th>
                                    <th>Days Remaining</th>
                                    <th>Extensions</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($active_borrows as $borrow): ?>
                                    <?php
                                    // Calculate extension eligibility
                                    $can_extend = false;
                                    $extension_reason = '';
                                    
                                    if ($borrow['status'] === 'borrowed') {
                                        if ($borrow['is_overdue']) {
                                            $extension_reason = 'Cannot extend - book is overdue';
                                        } elseif ($borrow['extension_attempts'] >= $extension_settings['max_extensions']) {
                                            $extension_reason = 'Maximum extensions reached';
                                        } else {
                                            $can_extend = true;
                                        }
                                    } else {
                                        $extension_reason = 'Cannot extend - book status: ' . $borrow['status'];
                                    }
                                    
                                    // Determine status
                                    $status_class = '';
                                    if ($borrow['is_overdue']) {
                                        $status_class = 'status-overdue';
                                    } elseif ($borrow['due_soon']) {
                                        $status_class = 'status-due-soon';
                                    } else {
                                        $status_class = 'status-borrowed';
                                    }
                                    
                                    // Calculate late fee if overdue
                                    $late_fee = 0;
                                    if ($borrow['is_overdue'] && $borrow['days_until_due'] < 0) {
                                        $days_overdue = abs($borrow['days_until_due']);
                                        $late_fee = $days_overdue * $extension_settings['overdue_fee_per_day'];
                                    }
                                    
                                    // Book cover image
                                    $cover_image = $borrow['cover_image_cache'] ? 
                                        '../uploads/covers/' . htmlspecialchars($borrow['cover_image_cache']) : 
                                        '../assets/default-book-cover.png';
                                    ?>
                                    <tr data-borrow-id="<?php echo $borrow['id']; ?>"
                                        data-status="<?php echo $borrow['is_overdue'] ? 'overdue' : ($borrow['due_soon'] ? 'due_soon' : 'borrowed'); ?>"
                                        data-category="<?php echo htmlspecialchars($borrow['category_id'] ?? ''); ?>"
                                        data-due-date="<?php echo htmlspecialchars($borrow['due_date_short']); ?>">
                                        <td>
                                            <div class="book-title-cell">
                                                <div class="book-cover-small">
                                                    <img src="<?php echo $cover_image; ?>" 
                                                         alt="<?php echo htmlspecialchars($borrow['title'] ?? 'Unknown'); ?>"
                                                         onerror="this.src='../assets/default-book-cover.png'">
                                                </div>
                                                <span><?php echo htmlspecialchars($borrow['title'] ?? 'Unknown Title'); ?></span>
                                            </div>
                                        </td>
                                        <td><?php echo htmlspecialchars($borrow['author'] ?? 'Unknown Author'); ?></td>
                                        <td><?php echo htmlspecialchars($borrow['copy_number'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($borrow['category_name'] ?? 'Uncategorized'); ?></td>
                                        <td><?php echo $borrow['borrowed_at_formatted']; ?></td>
                                        <td class="<?php echo $borrow['is_overdue'] || $borrow['due_soon'] ? 'text-danger' : ''; ?>">
                                            <?php echo $borrow['due_date_formatted']; ?>
                                        </td>
                                        <td>
                                            <span class="status-badge <?php echo $status_class; ?>">
                                                <?php echo $borrow['is_overdue'] ? 'Overdue' : ($borrow['due_soon'] ? 'Due Soon' : 'Borrowed'); ?>
                                            </span>
                                            <?php if ($borrow['is_overdue'] && $late_fee > 0): ?>
                                                <br><small class="text-danger">Late fee: ₱<?php echo number_format($late_fee, 2); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($borrow['is_overdue']): ?>
                                                <span class="text-danger">
                                                    <?php echo abs($borrow['days_until_due']); ?> days overdue
                                                </span>
                                            <?php elseif ($borrow['due_soon']): ?>
                                                <span class="text-warning">
                                                    <?php echo $borrow['days_until_due']; ?> days
                                                </span>
                                            <?php else: ?>
                                                <span class="text-success">
                                                    <?php echo $borrow['days_until_due']; ?> days
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($borrow['extension_attempts'] > 0): ?>
                                                <?php echo $borrow['extension_attempts']; ?> 
                                                <?php if ($borrow['last_extension_date']): ?>
                                                    <br><small>Last: <?php echo (new DateTime($borrow['last_extension_date']))->format('M d, Y'); ?></small>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                None
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="table-actions">
                                                <button class="btn-details-sm" 
                                                        onclick="openDetailsModal(<?php echo $borrow['id']; ?>)"
                                                        title="View Book Details">
                                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                        <circle cx="12" cy="12" r="10"/>
                                                        <line x1="12" y1="16" x2="12" y2="12"/>
                                                        <line x1="12" y1="8" x2="12.01" y2="8"/>
                                                    </svg>
                                                    Details
                                                </button>
                                                
                                                <?php if ($can_extend): ?>
                                                    <button class="btn-extend-sm" 
                                                            onclick="openExtensionModal(this)"
                                                            data-borrow-id="<?php echo $borrow['id']; ?>"
                                                            data-book-id="<?php echo $borrow['book_id']; ?>"
                                                            data-book-copy-id="<?php echo $borrow['book_copy_id']; ?>"
                                                            data-book-title="<?php echo htmlspecialchars($borrow['title'] ?? 'Unknown'); ?>"
                                                            data-due-date="<?php echo $borrow['due_date']; ?>"
                                                            data-extension-attempts="<?php echo $borrow['extension_attempts']; ?>">
                                                        Extend
                                                    </button>
                                                <?php else: ?>
                                                    <span class="text-muted" title="<?php echo htmlspecialchars($extension_reason); ?>">
                                                        Can't extend
                                                    </span>
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
        
        <!-- Return History Section -->
        <div class="history-section card" style="margin-top: 30px;">
            <div class="section-header">
                <h3>
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M20 14.66V20a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h5.34"/>
                        <polygon points="18 2 22 6 12 16 8 16 8 12 18 2"/>
                    </svg>
                    Return History & Receipts
                </h3>
            </div>
            
            <?php if (empty($return_history)): ?>
                <div class="empty-history">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M9 17H7A5 5 0 0 1 7 7h2"/>
                        <path d="M15 7h2a5 5 0 1 1 0 10h-2"/>
                        <line x1="8" y1="12" x2="16" y2="12"/>
                    </svg>
                    <p>No return history found.</p>
                </div>
            <?php else: ?>
                <div class="history-table">
                    <table class="return-history-table">
                        <thead>
                            <tr>
                                <th>Book Title</th>
                                <th>Copy #</th>
                                <th>Borrowed Date</th>
                                <th>Returned Date</th>
                                <th>Status</th>
                                <th>Fees Paid</th>
                                <th>Receipt</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($return_history as $history): ?>
                                <?php
                                // Determine receipt availability
                                $has_receipt = !empty($history['pdf_path']) && file_exists(__DIR__ . '/../receipts/' . $history['pdf_path']);
                                $receipt_number = $history['receipt_number'] ?? $history['extension_receipt'] ?? 'N/A';
                                $total_fees = $history['total_amount'] ?? $history['extension_fee'] ?? 0;
                                ?>
                                <tr>
                                    <td>
                                        <div class="book-title-cell">
                                            <?php echo htmlspecialchars($history['title'] ?? 'Unknown Book'); ?>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($history['copy_number'] ?? 'N/A'); ?></td>
                                    <td><?php echo $history['borrowed_at_formatted']; ?></td>
                                    <td><?php echo $history['returned_at_formatted']; ?></td>
                                    <td>
                                        <span class="status-badge status-returned">
                                            Returned
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($total_fees > 0): ?>
                                            <span class="text-success">₱<?php echo number_format($total_fees, 2); ?></span>
                                            <?php if ($history['extension_fee']): ?>
                                                <br><small>(Extension fee)</small>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted">No fees</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($has_receipt): ?>
                                            <span class="receipt-available">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                                    <polyline points="14 2 14 8 20 8"/>
                                                    <path d="M16 13H8"/>
                                                    <path d="M16 17H8"/>
                                                    <path d="M10 9H8"/>
                                                </svg>
                                                <?php echo $receipt_number; ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">No receipt</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="history-actions">
                                            <button class="btn-view-details" 
                                                    onclick="openHistoryDetailsModal(<?php echo $history['id']; ?>)">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                    <circle cx="12" cy="12" r="10"/>
                                                    <line x1="12" y1="16" x2="12" y2="12"/>
                                                    <line x1="12" y1="8" x2="12.01" y2="8"/>
                                                </svg>
                                                Details
                                            </button>
                                            
                                            <?php if ($has_receipt): ?>
                                                <a href="<?php echo '../receipts/' . $history['pdf_path']; ?>" 
                                                   target="_blank" 
                                                   class="btn-view-receipt">
                                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                                        <polyline points="7 10 12 15 17 10"/>
                                                        <line x1="12" y1="15" x2="12" y2="3"/>
                                                    </svg>
                                                    View Receipt
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

<!-- Details Modal -->
<div id="detailsModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="12" y1="16" x2="12" y2="12"/>
                    <line x1="12" y1="8" x2="12.01" y2="8"/>
                </svg>
                Book Borrow Details
            </h2>
            <button class="modal-close" onclick="closeDetailsModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div id="detailsContent" class="details-content">
                <!-- Details will be loaded here via AJAX -->
                <div class="loading-spinner">
                    <div class="spinner"></div>
                    <p>Loading details...</p>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn-secondary" onclick="closeDetailsModal()">Close</button>
        </div>
    </div>
</div>

<!-- History Details Modal -->
<div id="historyDetailsModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M20 14.66V20a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h5.34"/>
                    <polygon points="18 2 22 6 12 16 8 16 8 12 18 2"/>
                </svg>
                Return History Details
            </h2>
            <button class="modal-close" onclick="closeHistoryDetailsModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div id="historyDetailsContent" class="details-content">
                <!-- History details will be loaded here -->
                <div class="loading-spinner">
                    <div class="spinner"></div>
                    <p>Loading history details...</p>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn-secondary" onclick="closeHistoryDetailsModal()">Close</button>
        </div>
    </div>
</div>

<!-- Extension Modal (keep existing) -->
<div id="extensionModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                    <polyline points="14 2 14 8 20 8"/>
                    <path d="M12 18v-6"/>
                    <path d="M9 15h6"/>
                </svg>
                Request Book Extension
            </h2>
            <button class="modal-close" onclick="closeExtensionModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div id="extensionBookInfo" class="book-info-card">
                <!-- Book info will be populated here -->
            </div>
            
            <div class="form-section">
                <div class="form-group">
                    <label for="extensionDays" class="form-label">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                            <line x1="16" y1="2" x2="16" y2="6"/>
                            <line x1="8" y1="2" x2="8" y2="6"/>
                            <line x1="3" y1="10" x2="21" y2="10"/>
                        </svg>
                        Extension Duration
                    </label>
                    <select id="extensionDays" class="form-select" onchange="updateFeeDisplay()">
                        <?php
                        $extension_options = [7, 14];
                        foreach ($extension_options as $days) {
                            if ($days <= $extension_settings['max_extension_days']) {
                                echo '<option value="' . $days . '">' . $days . ' days (' . ($days == 7 ? '1 week' : '2 weeks') . ')</option>';
                            }
                        }
                        ?>
                    </select>
                </div>
                
                <div class="fee-info">
                    <div id="extensionFeeInfo" class="fee-amount"></div>
                    <div class="max-extensions-info">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"/>
                            <line x1="12" y1="8" x2="12" y2="12"/>
                            <line x1="12" y1="16" x2="12.01" y2="16"/>
                        </svg>
                        Maximum extensions allowed per book: <?php echo $extension_settings['max_extensions']; ?>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="extensionReason" class="form-label">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M12 20h9"/>
                            <path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/>
                        </svg>
                        Reason for Extension <span class="text-danger">*</span>
                    </label>
                    <textarea id="extensionReason" 
                              class="form-textarea" 
                              placeholder="Please explain why you need to extend this book..."
                              rows="3"></textarea>
                    <small class="form-hint">Your reason will be reviewed by library staff</small>
                </div>
            </div>
            
            <div id="extensionError" class="alert alert-error" style="display: none;"></div>
            <div id="extensionSuccess" class="alert alert-success" style="display: none;"></div>
            
            <div class="modal-actions">
                <button id="cancelExtension" class="btn-secondary" onclick="closeExtensionModal()">Cancel</button>
                <button id="submitExtension" class="btn-primary" onclick="submitExtensionRequest()">
                    <span class="btn-text">Submit Extension Request</span>
                    <span class="btn-loader"></span>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Global variables
let currentExtensionBook = null;
let extensionSettings = <?php echo json_encode($extension_settings); ?>;

// Utility functions
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function formatDate(dateString) {
    if (!dateString) return 'N/A';
    try {
        const date = new Date(dateString);
        if (isNaN(date.getTime())) return dateString;
        return date.toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    } catch (e) {
        return dateString;
    }
}

function calculateExtensionFee(days) {
    return days * extensionSettings.extension_fee_per_day;
}

function calculateNewDueDate(currentDueDate, extensionDays) {
    const dueDate = new Date(currentDueDate);
    dueDate.setDate(dueDate.getDate() + parseInt(extensionDays));
    return dueDate;
}

// Filter and sort functions (keep existing)
function filterBooks() {
    const statusFilter = document.getElementById('statusFilter').value;
    const categoryFilter = document.getElementById('categoryFilter').value;
    const searchFilter = document.getElementById('searchFilter').value.toLowerCase();
    const sortFilter = document.getElementById('sortFilter').value;
    
    // Filter grid view
    const gridCards = document.querySelectorAll('.book-card-grid');
    gridCards.forEach(card => {
        let show = true;
        const status = card.getAttribute('data-status');
        const category = card.getAttribute('data-category');
        const dueDate = card.getAttribute('data-due-date');
        const title = card.querySelector('.book-title').textContent.toLowerCase();
        const author = card.querySelector('.book-author').textContent.toLowerCase();
        
        // Status filter
        if (statusFilter !== 'all' && status !== statusFilter) {
            show = false;
        }
        
        // Category filter
        if (categoryFilter && category !== categoryFilter) {
            show = false;
        }
        
        // Search filter
        if (searchFilter && !title.includes(searchFilter) && !author.includes(searchFilter)) {
            show = false;
        }
        
        card.style.display = show ? 'block' : 'none';
    });
    
    // Filter table view
    const tableRows = document.querySelectorAll('#booksTable tbody tr');
    tableRows.forEach(row => {
        let show = true;
        const status = row.getAttribute('data-status');
        const category = row.getAttribute('data-category');
        const title = row.querySelector('td:nth-child(1)').textContent.toLowerCase();
        const author = row.querySelector('td:nth-child(2)').textContent.toLowerCase();
        
        // Status filter
        if (statusFilter !== 'all' && status !== statusFilter) {
            show = false;
        }
        
        // Category filter
        if (categoryFilter && category !== categoryFilter) {
            show = false;
        }
        
        // Search filter
        if (searchFilter && !title.includes(searchFilter) && !author.includes(searchFilter)) {
            show = false;
        }
        
        row.style.display = show ? '' : 'none';
    });
    
    // Sort functionality
    sortBooks(sortFilter);
}

function sortBooks(sortType) {
    const gridView = document.getElementById('gridView').style.display !== 'none';
    
    if (gridView) {
        sortGridView(sortType);
    } else {
        sortTableView(sortType);
    }
}

function sortGridView(sortType) {
    const container = document.getElementById('booksGrid');
    const cards = Array.from(container.querySelectorAll('.book-card-grid'));
    
    cards.sort((a, b) => {
        switch(sortType) {
            case 'due_date_asc':
                return new Date(a.getAttribute('data-due-date')) - new Date(b.getAttribute('data-due-date'));
            case 'due_date_desc':
                return new Date(b.getAttribute('data-due-date')) - new Date(a.getAttribute('data-due-date'));
            case 'title_asc':
                return a.querySelector('.book-title').textContent.localeCompare(b.querySelector('.book-title').textContent);
            case 'title_desc':
                return b.querySelector('.book-title').textContent.localeCompare(a.querySelector('.book-title').textContent);
            case 'borrowed_date_desc':
                return 0;
            default:
                return 0;
        }
    });
    
    cards.forEach(card => container.appendChild(card));
}

function sortTableView(sortType) {
    const tbody = document.querySelector('#booksTable tbody');
    const rows = Array.from(tbody.querySelectorAll('tr[data-borrow-id]'));
    
    rows.sort((a, b) => {
        switch(sortType) {
            case 'due_date_asc':
                return new Date(a.getAttribute('data-due-date')) - new Date(b.getAttribute('data-due-date'));
            case 'due_date_desc':
                return new Date(b.getAttribute('data-due-date')) - new Date(a.getAttribute('data-due-date'));
            case 'title_asc':
                return a.querySelector('td:nth-child(1)').textContent.localeCompare(b.querySelector('td:nth-child(1)').textContent);
            case 'title_desc':
                return b.querySelector('td:nth-child(1)').textContent.localeCompare(a.querySelector('td:nth-child(1)').textContent);
            case 'borrowed_date_desc':
                return 0;
            default:
                return 0;
        }
    });
    
    rows.forEach(row => tbody.appendChild(row));
}

// View toggle
document.querySelectorAll('.view-toggle-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const view = this.getAttribute('data-view');
        
        document.querySelectorAll('.view-toggle-btn').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        
        if (view === 'grid') {
            document.getElementById('gridView').style.display = 'block';
            document.getElementById('tableView').style.display = 'none';
        } else {
            document.getElementById('gridView').style.display = 'none';
            document.getElementById('tableView').style.display = 'block';
        }
    });
});

// Filter event listeners
document.getElementById('statusFilter').addEventListener('change', filterBooks);
document.getElementById('categoryFilter').addEventListener('change', filterBooks);
document.getElementById('sortFilter').addEventListener('change', filterBooks);
document.getElementById('searchFilter').addEventListener('input', filterBooks);

// Details modal functions
async function openDetailsModal(borrowId) {
    const modal = document.getElementById('detailsModal');
    const content = document.getElementById('detailsContent');
    
    content.innerHTML = `
        <div class="loading-spinner">
            <div class="spinner"></div>
            <p>Loading details...</p>
        </div>
    `;
    
    modal.style.display = 'block';
    document.body.style.overflow = 'hidden';
    
    try {
        const formData = new FormData();
        formData.append('action', 'get_borrow_details');
        formData.append('borrow_id', borrowId);
        
        const response = await fetch('my_borrowed_books.php?ajax=1', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success && result.details) {
            const details = result.details;
            let damageTypes = [];
            try {
                damageTypes = JSON.parse(details.damage_types || '[]');
            } catch (e) {}
            
            // Calculate days until due
            const now = new Date();
            const dueDate = new Date(details.due_date);
            const daysUntilDue = details.status === 'returned' ? 0 : 
                                Math.ceil((dueDate - now) / (1000 * 60 * 60 * 24));
            
            // Check if overdue
            const isOverdue = daysUntilDue < 0 && details.status !== 'returned';
            const dueSoon = daysUntilDue >= 0 && daysUntilDue <= extensionSettings.notice_days && details.status !== 'returned';
            
            content.innerHTML = `
                <div class="details-grid">
                    <div class="detail-section">
                        <h4>Book Information</h4>
                        <div class="detail-item">
                            <span class="detail-label">Title:</span>
                            <span class="detail-value">${escapeHtml(details.title || 'N/A')}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Author:</span>
                            <span class="detail-value">${escapeHtml(details.author || 'N/A')}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">ISBN:</span>
                            <span class="detail-value">${escapeHtml(details.isbn || 'N/A')}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Publisher:</span>
                            <span class="detail-value">${escapeHtml(details.publisher || 'N/A')}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Year:</span>
                            <span class="detail-value">${details.year_published || 'N/A'}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Category:</span>
                            <span class="detail-value">${escapeHtml(details.category_name || 'N/A')}</span>
                        </div>
                    </div>
                    
                    <div class="detail-section">
                        <h4>Copy Information</h4>
                        <div class="detail-item">
                            <span class="detail-label">Copy Number:</span>
                            <span class="detail-value">${escapeHtml(details.copy_number || 'N/A')}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Barcode:</span>
                            <span class="detail-value">${escapeHtml(details.barcode || 'N/A')}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Condition:</span>
                            <span class="detail-value ${details.book_condition === 'damaged' ? 'text-danger' : ''}">
                                ${escapeHtml(details.book_condition || 'good').toUpperCase()}
                            </span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Location:</span>
                            <span class="detail-value">
                                ${escapeHtml(details.current_section || 'A')}-S${escapeHtml(details.current_shelf || '1')}
                            </span>
                        </div>
                    </div>
                    
                    <div class="detail-section">
                        <h4>Borrow Information</h4>
                        <div class="detail-item">
                            <span class="detail-label">Borrowed Date:</span>
                            <span class="detail-value">${details.borrowed_at_formatted}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Due Date:</span>
                            <span class="detail-value ${isOverdue || dueSoon ? 'text-danger' : ''}">
                                ${details.due_date_formatted}
                                ${isOverdue ? ' (OVERDUE!)' : dueSoon ? ' (Due soon!)' : ''}
                            </span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Returned Date:</span>
                            <span class="detail-value">${details.returned_at_formatted}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Status:</span>
                            <span class="detail-value status-badge ${details.status === 'overdue' ? 'status-overdue' : 
                                                                   details.status === 'returned' ? 'status-returned' : 
                                                                   'status-borrowed'}">
                                ${details.status.toUpperCase()}
                            </span>
                        </div>
                        ${details.extension_attempts > 0 ? `
                        <div class="detail-item">
                            <span class="detail-label">Extensions Used:</span>
                            <span class="detail-value">${details.extension_attempts}</span>
                        </div>
                        ` : ''}
                    </div>
                    
                    <div class="detail-section">
                        <h4>Fees & Payment</h4>
                        <div class="detail-item">
                            <span class="detail-label">Late Fee:</span>
                            <span class="detail-value ${details.late_fee > 0 ? 'text-danger' : ''}">
                                ₱${parseFloat(details.late_fee || 0).toFixed(2)}
                            </span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Penalty Fee:</span>
                            <span class="detail-value ${details.penalty_fee > 0 ? 'text-danger' : ''}">
                                ₱${parseFloat(details.penalty_fee || 0).toFixed(2)}
                            </span>
                        </div>
                        ${details.extension_fee > 0 ? `
                        <div class="detail-item">
                            <span class="detail-label">Extension Fee:</span>
                            <span class="detail-value">₱${parseFloat(details.extension_fee).toFixed(2)}</span>
                        </div>
                        ` : ''}
                        ${damageTypes.length > 0 ? `
                        <div class="detail-item">
                            <span class="detail-label">Damage Types:</span>
                            <span class="detail-value text-danger">
                                ${damageTypes.map(type => type.replace('_', ' ')).join(', ')}
                            </span>
                        </div>
                        ` : ''}
                        ${details.return_damage_type ? `
                        <div class="detail-item">
                            <span class="detail-label">Return Damage:</span>
                            <span class="detail-value text-danger">
                                ${escapeHtml(details.return_damage_type)}
                            </span>
                        </div>
                        ` : ''}
                        ${details.receipt_number ? `
                        <div class="detail-item">
                            <span class="detail-label">Receipt #:</span>
                            <span class="detail-value">${escapeHtml(details.receipt_number)}</span>
                        </div>
                        ` : ''}
                        ${details.payment_date_formatted ? `
                        <div class="detail-item">
                            <span class="detail-label">Payment Date:</span>
                            <span class="detail-value">${details.payment_date_formatted}</span>
                        </div>
                        ` : ''}
                        ${details.payment_method ? `
                        <div class="detail-item">
                            <span class="detail-label">Payment Method:</span>
                            <span class="detail-value">${escapeHtml(details.payment_method)}</span>
                        </div>
                        ` : ''}
                        ${details.total_amount > 0 ? `
                        <div class="detail-item">
                            <span class="detail-label">Total Paid:</span>
                            <span class="detail-value text-success">₱${parseFloat(details.total_amount).toFixed(2)}</span>
                        </div>
                        ` : ''}
                    </div>
                    
                    ${details.description ? `
                    <div class="detail-section full-width">
                        <h4>Book Description</h4>
                        <div class="description-box">
                            ${escapeHtml(details.description)}
                        </div>
                    </div>
                    ` : ''}
                    
                    ${details.notes ? `
                    <div class="detail-section full-width">
                        <h4>Notes</h4>
                        <div class="notes-box">
                            ${escapeHtml(details.notes)}
                        </div>
                    </div>
                    ` : ''}
                    
                    ${details.return_damage_description ? `
                    <div class="detail-section full-width">
                        <h4>Damage Description</h4>
                        <div class="damage-box text-danger">
                            ${escapeHtml(details.return_damage_description)}
                        </div>
                    </div>
                    ` : ''}
                </div>
            `;
        } else {
            content.innerHTML = `
                <div class="error-state">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/>
                        <line x1="12" y1="8" x2="12" y2="12"/>
                        <line x1="12" y1="16" x2="12.01" y2="16"/>
                    </svg>
                    <h4>Unable to Load Details</h4>
                    <p>${result.error || 'An error occurred while loading the details.'}</p>
                </div>
            `;
        }
    } catch (error) {
        console.error('Error loading details:', error);
        content.innerHTML = `
            <div class="error-state">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="12" y1="8" x2="12" y2="12"/>
                    <line x1="12" y1="16" x2="12.01" y2="16"/>
                </svg>
                <h4>Error Loading Details</h4>
                <p>Failed to load book details. Please try again.</p>
            </div>
        `;
    }
}

function closeDetailsModal() {
    const modal = document.getElementById('detailsModal');
    modal.style.display = 'none';
    document.body.style.overflow = 'auto';
}

// History details modal
function openHistoryDetailsModal(historyId) {
    // For now, just show a simple message
    const modal = document.getElementById('historyDetailsModal');
    const content = document.getElementById('historyDetailsContent');
    
    // In a real implementation, you would fetch detailed history information
    // For now, show a placeholder
    content.innerHTML = `
        <div class="details-grid">
            <div class="detail-section">
                <h4>Return Information</h4>
                <p>Detailed return information would be displayed here.</p>
                <p>This includes:</p>
                <ul>
                    <li>Complete borrow/return timeline</li>
                    <li>All fees and payments</li>
                    <li>Condition at return</li>
                    <li>Receipt information</li>
                </ul>
            </div>
        </div>
    `;
    
    modal.style.display = 'block';
    document.body.style.overflow = 'hidden';
}

function closeHistoryDetailsModal() {
    const modal = document.getElementById('historyDetailsModal');
    modal.style.display = 'none';
    document.body.style.overflow = 'auto';
}

// Extension modal functions (keep existing)
function openExtensionModal(button) {
    const modal = document.getElementById('extensionModal');
    const bookInfo = document.getElementById('extensionBookInfo');
    const feeInfo = document.getElementById('extensionFeeInfo');
    const errorDiv = document.getElementById('extensionError');
    const successDiv = document.getElementById('extensionSuccess');
    const reasonTextarea = document.getElementById('extensionReason');
    
    errorDiv.style.display = 'none';
    successDiv.style.display = 'none';
    reasonTextarea.value = '';
    
    currentExtensionBook = {
        borrowId: button.getAttribute('data-borrow-id'),
        bookId: button.getAttribute('data-book-id'),
        bookCopyId: button.getAttribute('data-book-copy-id'),
        bookTitle: button.getAttribute('data-book-title'),
        dueDate: button.getAttribute('data-due-date'),
        extensionAttempts: parseInt(button.getAttribute('data-extension-attempts')) || 0
    };
    
    const currentDueDate = new Date(currentExtensionBook.dueDate);
    const newDueDate = calculateNewDueDate(currentDueDate, 7);
    const now = new Date();
    const isOverdue = now > currentDueDate;
    
    bookInfo.innerHTML = `
        <div class="book-info-content">
            <div class="book-info-header">
                <h4>${escapeHtml(currentExtensionBook.bookTitle)}</h4>
                <div class="book-info-meta">
                    <span class="meta-item ${isOverdue ? 'text-danger' : ''}">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                            <line x1="16" y1="2" x2="16" y2="6"/>
                            <line x1="8" y1="2" x2="8" y2="6"/>
                            <line x1="3" y1="10" x2="21" y2="10"/>
                        </svg>
                        Current Due: ${formatDate(currentExtensionBook.dueDate)}
                    </span>
                    <span class="meta-item text-success">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                            <line x1="16" y1="2" x2="16" y2="6"/>
                            <line x1="8" y1="2" x2="8" y2="6"/>
                            <line x1="3" y1="10" x2="21" y2="10"/>
                        </svg>
                        New Due: <span id="newDueDateDisplay">${formatDate(newDueDate.toISOString())}</span>
                    </span>
                </div>
            </div>
            <div class="extensions-info">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 8v4l3 3"/>
                    <circle cx="12" cy="12" r="10"/>
                </svg>
                Extensions used: ${currentExtensionBook.extensionAttempts} of ${extensionSettings.max_extensions}
                ${currentExtensionBook.extensionAttempts >= extensionSettings.max_extensions ? 
                    '<span class="text-danger">(Maximum reached)</span>' : ''}
            </div>
        </div>
    `;
    
    updateFeeDisplay();
    modal.style.display = 'block';
    document.body.style.overflow = 'hidden';
}

function updateFeeDisplay() {
    if (!currentExtensionBook) return;
    
    const daysSelect = document.getElementById('extensionDays');
    const days = parseInt(daysSelect.value);
    const fee = calculateExtensionFee(days);
    const feeInfo = document.getElementById('extensionFeeInfo');
    
    const currentDueDate = new Date(currentExtensionBook.dueDate);
    const newDueDate = calculateNewDueDate(currentDueDate, days);
    document.getElementById('newDueDateDisplay').textContent = formatDate(newDueDate.toISOString());
    
    feeInfo.innerHTML = `
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10"/>
            <path d="M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8"/>
            <path d="M12 18V6"/>
        </svg>
        Extension fee: ₱${fee.toFixed(2)}
    `;
    feeInfo.style.color = fee > 0 ? '#10b981' : '#6b7280';
}

function closeExtensionModal() {
    const modal = document.getElementById('extensionModal');
    const errorDiv = document.getElementById('extensionError');
    const successDiv = document.getElementById('extensionSuccess');
    const reasonTextarea = document.getElementById('extensionReason');
    
    modal.style.display = 'none';
    document.body.style.overflow = 'auto';
    errorDiv.style.display = 'none';
    successDiv.style.display = 'none';
    reasonTextarea.value = '';
    currentExtensionBook = null;
}

async function submitExtensionRequest() {
    if (!currentExtensionBook) return;
    
    const submitBtn = document.getElementById('submitExtension');
    const errorDiv = document.getElementById('extensionError');
    const successDiv = document.getElementById('extensionSuccess');
    const daysSelect = document.getElementById('extensionDays');
    const reasonTextarea = document.getElementById('extensionReason');
    
    const days = parseInt(daysSelect.value);
    const reason = reasonTextarea.value.trim();
    
    if (!reason) {
        errorDiv.textContent = '❌ Please provide a reason for the extension.';
        errorDiv.style.display = 'block';
        reasonTextarea.focus();
        return;
    }
    
    if (days <= 0 || days > extensionSettings.max_extension_days) {
        errorDiv.textContent = `❌ Extension must be between 1 and ${extensionSettings.max_extension_days} days`;
        errorDiv.style.display = 'block';
        return;
    }
    
    if (currentExtensionBook.extensionAttempts >= extensionSettings.max_extensions) {
        errorDiv.textContent = `❌ Maximum extensions (${extensionSettings.max_extensions}) already reached for this book`;
        errorDiv.style.display = 'block';
        return;
    }
    
    const currentDueDate = new Date(currentExtensionBook.dueDate);
    const now = new Date();
    if (now > currentDueDate) {
        errorDiv.textContent = '❌ Cannot extend overdue books. Please return the book and pay any late fees first.';
        errorDiv.style.display = 'block';
        return;
    }
    
    submitBtn.disabled = true;
    submitBtn.classList.add('loading');
    errorDiv.style.display = 'none';
    
    try {
        const extensionFee = calculateExtensionFee(days);
        const receiptNumber = 'EXT' + Date.now() + Math.floor(Math.random() * 1000);
        
        const formData = new FormData();
        formData.append('action', 'request_extension');
        formData.append('borrow_log_id', currentExtensionBook.borrowId);
        formData.append('book_copy_id', currentExtensionBook.bookCopyId);
        formData.append('extension_days', days);
        formData.append('reason', reason);
        formData.append('extension_fee', extensionFee);
        formData.append('receipt_number', receiptNumber);
        
        const response = await fetch('my_borrowed_books.php?ajax=1', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.text();
        
        if (!response.ok) {
            throw new Error(result || 'Failed to submit extension request');
        }
        
        try {
            const jsonResult = JSON.parse(result);
            if (jsonResult.error) {
                throw new Error(jsonResult.error);
            }
            
            successDiv.innerHTML = `
                <div style="display: flex; align-items: center; gap: 10px;">
                    <div style="font-size: 24px;">✅</div>
                    <div>
                        <strong>Extension Request Submitted!</strong><br>
                        <small>Request ID: ${jsonResult.id || 'N/A'}<br>
                        Receipt: ${receiptNumber}<br>
                        Your request will be reviewed by library staff.</small>
                    </div>
                </div>
            `;
            successDiv.style.display = 'block';
            
            submitBtn.innerHTML = '<span class="btn-text">✅ Request Submitted</span>';
            submitBtn.style.backgroundColor = '#10b981';
            submitBtn.disabled = true;
            
            setTimeout(() => {
                closeExtensionModal();
                window.location.reload();
            }, 5000);
            
        } catch (parseError) {
            if (result.includes('success') || result.includes('inserted')) {
                successDiv.innerHTML = `
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <div style="font-size: 24px;">✅</div>
                        <div>
                            <strong>Extension Request Submitted!</strong><br>
                            <small>Receipt: ${receiptNumber}<br>
                            Your request will be reviewed by library staff.</small>
                        </div>
                    </div>
                `;
                successDiv.style.display = 'block';
                
                submitBtn.innerHTML = '<span class="btn-text">✅ Request Submitted</span>';
                submitBtn.style.backgroundColor = '#10b981';
                submitBtn.disabled = true;
                
                setTimeout(() => {
                    closeExtensionModal();
                    window.location.reload();
                }, 5000);
            } else {
                throw new Error(result);
            }
        }
        
    } catch (error) {
        console.error('Error submitting extension request:', error);
        errorDiv.innerHTML = `
            <div style="display: flex; align-items: center; gap: 10px;">
                <div style="font-size: 24px;">❌</div>
                <div>
                    <strong>Failed to submit extension request</strong><br>
                    <small>${error.message}</small>
                </div>
            </div>
        `;
        errorDiv.style.display = 'block';
        submitBtn.disabled = false;
        submitBtn.classList.remove('loading');
        submitBtn.innerHTML = '<span class="btn-text">Submit Extension Request</span>';
    }
}

// Event Listeners
document.addEventListener('DOMContentLoaded', () => {
    // Close modals when clicking outside
    const modals = document.querySelectorAll('.modal');
    modals.forEach(modal => {
        window.addEventListener('click', function(event) {
            if (event.target === modal) {
                if (modal.id === 'detailsModal') closeDetailsModal();
                else if (modal.id === 'historyDetailsModal') closeHistoryDetailsModal();
                else if (modal.id === 'extensionModal') closeExtensionModal();
            }
        });
    });
    
    // Close with Escape key
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            if (document.getElementById('detailsModal').style.display === 'block') {
                closeDetailsModal();
            } else if (document.getElementById('historyDetailsModal').style.display === 'block') {
                closeHistoryDetailsModal();
            } else if (document.getElementById('extensionModal').style.display === 'block') {
                closeExtensionModal();
            }
        }
    });
});
</script>

<style>
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
    margin-bottom: 40px;
}

.header-content {
    margin-bottom: 20px;
}

.header-title-row {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 20px;
    margin-bottom: 16px;
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

.header-stats {
    display: flex;
    gap: 16px;
}

.stat-card {
    background: white;
    border-radius: var(--radius-lg);
    padding: 20px;
    min-width: 150px;
    text-align: center;
    box-shadow: var(--shadow-md);
    border: 1px solid var(--gray-200);
}

.stat-value {
    font-size: 2rem;
    font-weight: 700;
    color: var(--primary);
    line-height: 1;
}

.stat-label {
    font-size: 0.875rem;
    color: var(--gray-600);
    margin-top: 4px;
}

/* Controls Section */
.controls-section {
    background: white;
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-lg);
    margin-bottom: 24px;
    overflow: hidden;
}

.controls-header {
    padding: 20px 24px;
    border-bottom: 1px solid var(--gray-200);
    background: linear-gradient(135deg, var(--gray-50), white);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.controls-header h3 {
    margin: 0;
    font-size: 1.25rem;
    color: var(--gray-800);
    display: flex;
    align-items: center;
    gap: 10px;
}

.view-toggle {
    display: flex;
    gap: 8px;
    background: var(--gray-100);
    padding: 4px;
    border-radius: var(--radius);
}

.view-toggle-btn {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    background: transparent;
    border: none;
    border-radius: var(--radius-sm);
    font-size: 0.875rem;
    font-weight: 500;
    color: var(--gray-600);
    cursor: pointer;
    transition: var(--transition);
}

.view-toggle-btn.active {
    background: white;
    color: var(--primary);
    box-shadow: var(--shadow-sm);
}

.view-toggle-btn:hover:not(.active) {
    color: var(--gray-800);
}

.filters-grid {
    padding: 24px;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.filter-label {
    font-weight: 500;
    color: var(--gray-700);
    font-size: 0.95rem;
}

.form-select {
    padding: 10px 16px;
    border: 1px solid var(--gray-300);
    border-radius: var(--radius);
    background: white;
    color: var(--gray-800);
    font-size: 1rem;
    transition: var(--transition);
    width: 100%;
}

.form-select:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
}

.search-input-group {
    position: relative;
}

.search-icon {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--gray-400);
}

.search-input {
    padding: 10px 16px 10px 40px;
    border: 1px solid var(--gray-300);
    border-radius: var(--radius);
    background: white;
    color: var(--gray-800);
    font-size: 1rem;
    transition: var(--transition);
    width: 100%;
}

.search-input:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
}

/* Grid View */
.books-grid-view {
    background: white;
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-lg);
    overflow: hidden;
}

.grid-container {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 24px;
    padding: 24px;
}

@media (max-width: 768px) {
    .grid-container {
        grid-template-columns: 1fr;
    }
}

.book-card-grid {
    background: white;
    border: 1px solid var(--gray-200);
    border-radius: var(--radius);
    overflow: hidden;
    transition: var(--transition);
    display: flex;
    flex-direction: column;
}

.book-card-grid:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-lg);
    border-color: var(--primary);
}

.book-card-header {
    padding: 12px 16px;
    background: var(--gray-50);
    border-bottom: 1px solid var(--gray-200);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.book-status {
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
    color: white;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.book-extensions {
    font-size: 0.75rem;
    color: var(--gray-600);
    display: flex;
    align-items: center;
    gap: 4px;
}

.book-card-image {
    height: 200px;
    background: var(--gray-100);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
}

.book-card-image img {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
}

.book-card-content {
    padding: 20px;
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.book-title {
    margin: 0;
    font-size: 1.125rem;
    font-weight: 600;
    color: var(--gray-900);
    line-height: 1.3;
}

.book-author {
    margin: 0;
    color: var(--gray-600);
    font-size: 0.95rem;
}

.book-meta {
    display: flex;
    gap: 12px;
    margin-top: 8px;
}

.meta-item {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 0.85rem;
    color: var(--gray-500);
    background: var(--gray-100);
    padding: 3px 8px;
    border-radius: 12px;
}

.borrow-dates {
    margin-top: 12px;
    padding-top: 12px;
    border-top: 1px solid var(--gray-200);
    display: grid;
    gap: 8px;
}

.date-item {
    display: flex;
    justify-content: space-between;
}

.date-label {
    font-size: 0.75rem;
    color: var(--gray-500);
}

.date-value {
    font-weight: 500;
    color: var(--gray-800);
    font-size: 0.875rem;
}

.book-footer {
    margin-top: auto;
    padding-top: 16px;
    border-top: 1px solid var(--gray-200);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.days-remaining {
    font-size: 0.875rem;
    font-weight: 500;
}

.book-actions {
    display: flex;
    gap: 8px;
    align-items: center;
}

.btn-details {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    background: var(--gray-200);
    color: var(--gray-700);
    border: none;
    border-radius: var(--radius);
    font-weight: 500;
    font-size: 0.875rem;
    cursor: pointer;
    transition: var(--transition);
}

.btn-details:hover {
    background: var(--gray-300);
    transform: translateY(-1px);
}

.btn-extend {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    background: var(--primary);
    color: white;
    border: none;
    border-radius: var(--radius);
    font-weight: 500;
    font-size: 0.875rem;
    cursor: pointer;
    transition: var(--transition);
}

.btn-extend:hover {
    background: var(--primary-dark);
    transform: translateY(-1px);
}

.cannot-extend {
    font-size: 0.75rem;
    color: var(--gray-500);
    font-style: italic;
    cursor: not-allowed;
}

.text-danger { color: var(--danger); }
.text-warning { color: var(--warning); }
.text-success { color: var(--success); }
.text-muted { color: var(--gray-500); }

/* Table View */
.books-table-view {
    background: white;
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-lg);
    overflow: hidden;
}

.table-responsive {
    overflow-x: auto;
}

.borrowed-books-table {
    width: 100%;
    border-collapse: collapse;
}

.borrowed-books-table thead {
    background: linear-gradient(135deg, var(--gray-50), white);
}

.borrowed-books-table th {
    padding: 16px;
    text-align: left;
    font-weight: 600;
    color: var(--gray-700);
    border-bottom: 2px solid var(--gray-200);
    white-space: nowrap;
}

.borrowed-books-table td {
    padding: 16px;
    border-bottom: 1px solid var(--gray-200);
    vertical-align: middle;
}

.borrowed-books-table tbody tr:hover {
    background: var(--gray-50);
}

.book-title-cell {
    display: flex;
    align-items: center;
    gap: 12px;
}

.book-cover-small {
    width: 40px;
    height: 60px;
    flex-shrink: 0;
    background: var(--gray-100);
    border-radius: 4px;
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
}

.book-cover-small img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.status-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.status-borrowed {
    background: #dbeafe;
    color: #1e40af;
}

.status-overdue {
    background: #fee2e2;
    color: #991b1b;
}

.status-due-soon {
    background: #fef3c7;
    color: #92400e;
}

.status-returned {
    background: #d1fae5;
    color: #065f46;
}

.table-actions {
    display: flex;
    gap: 8px;
    align-items: center;
}

.btn-details-sm {
    display: flex;
    align-items: center;
    gap: 4px;
    padding: 6px 12px;
    background: var(--gray-200);
    color: var(--gray-700);
    border: none;
    border-radius: var(--radius-sm);
    font-size: 0.75rem;
    font-weight: 500;
    cursor: pointer;
    transition: var(--transition);
}

.btn-details-sm:hover {
    background: var(--gray-300);
}

.btn-extend-sm {
    padding: 6px 12px;
    background: var(--primary);
    color: white;
    border: none;
    border-radius: var(--radius-sm);
    font-size: 0.75rem;
    font-weight: 500;
    cursor: pointer;
    transition: var(--transition);
}

.btn-extend-sm:hover {
    background: var(--primary-dark);
}

/* History Section */
.history-section {
    background: white;
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-lg);
    overflow: hidden;
}

.section-header {
    padding: 20px 24px;
    border-bottom: 1px solid var(--gray-200);
    background: linear-gradient(135deg, var(--gray-50), white);
}

.section-header h3 {
    margin: 0;
    font-size: 1.25rem;
    color: var(--gray-800);
    display: flex;
    align-items: center;
    gap: 10px;
}

.empty-history {
    padding: 60px 40px;
    text-align: center;
    color: var(--gray-500);
}

.empty-history svg {
    margin-bottom: 20px;
}

.history-table {
    padding: 24px;
}

.return-history-table {
    width: 100%;
    border-collapse: collapse;
}

.return-history-table thead {
    background: var(--gray-50);
}

.return-history-table th {
    padding: 12px 16px;
    text-align: left;
    font-weight: 600;
    color: var(--gray-700);
    border-bottom: 2px solid var(--gray-200);
    font-size: 0.875rem;
}

.return-history-table td {
    padding: 12px 16px;
    border-bottom: 1px solid var(--gray-200);
    vertical-align: middle;
    font-size: 0.875rem;
}

.return-history-table tbody tr:hover {
    background: var(--gray-50);
}

.receipt-available {
    display: flex;
    align-items: center;
    gap: 6px;
    color: var(--success);
    font-weight: 500;
}

.history-actions {
    display: flex;
    gap: 8px;
}

.btn-view-details {
    display: flex;
    align-items: center;
    gap: 4px;
    padding: 6px 12px;
    background: var(--gray-200);
    color: var(--gray-700);
    border: none;
    border-radius: var(--radius-sm);
    font-size: 0.75rem;
    font-weight: 500;
    cursor: pointer;
    transition: var(--transition);
}

.btn-view-details:hover {
    background: var(--gray-300);
}

.btn-view-receipt {
    display: flex;
    align-items: center;
    gap: 4px;
    padding: 6px 12px;
    background: var(--success);
    color: white;
    border: none;
    border-radius: var(--radius-sm);
    font-size: 0.75rem;
    font-weight: 500;
    cursor: pointer;
    text-decoration: none;
    transition: var(--transition);
}

.btn-view-receipt:hover {
    background: var(--secondary-dark);
    transform: translateY(-1px);
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
    max-width: 800px;
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
    padding: 24px 32px;
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    color: white;
    border-radius: var(--radius-xl) var(--radius-xl) 0 0;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-title {
    margin: 0;
    font-size: 1.5rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 12px;
}

.modal-close {
    background: none;
    border: none;
    color: white;
    font-size: 2rem;
    cursor: pointer;
    padding: 0;
    width: 40px;
    height: 40px;
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
    padding: 32px;
    overflow-y: auto;
    flex: 1;
}

.modal-footer {
    padding: 24px 32px;
    border-top: 1px solid var(--gray-200);
    display: flex;
    justify-content: flex-end;
    gap: 12px;
}

/* Details Content */
.details-content {
    min-height: 200px;
}

.loading-spinner {
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
    border: 4px solid var(--gray-200);
    border-top: 4px solid var(--primary);
    border-radius: 50%;
    animation: spin 1s linear infinite;
    margin-bottom: 20px;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

.error-state {
    text-align: center;
    padding: 60px 20px;
}

.error-state svg {
    margin-bottom: 20px;
    color: var(--danger);
}

.details-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 24px;
}

.detail-section {
    background: var(--gray-50);
    border-radius: var(--radius);
    padding: 20px;
    border: 1px solid var(--gray-200);
}

.detail-section.full-width {
    grid-column: 1 / -1;
}

.detail-section h4 {
    margin: 0 0 16px 0;
    color: var(--gray-800);
    font-size: 1.125rem;
    border-bottom: 2px solid var(--gray-300);
    padding-bottom: 8px;
}

.detail-item {
    display: flex;
    justify-content: space-between;
    margin-bottom: 12px;
    align-items: flex-start;
}

.detail-item:last-child {
    margin-bottom: 0;
}

.detail-label {
    font-weight: 500;
    color: var(--gray-700);
    font-size: 0.9rem;
    flex: 1;
}

.detail-value {
    color: var(--gray-800);
    font-size: 0.9rem;
    flex: 2;
    text-align: right;
}

.description-box, .notes-box, .damage-box {
    background: white;
    border: 1px solid var(--gray-200);
    border-radius: var(--radius);
    padding: 16px;
    margin-top: 8px;
    font-size: 0.9rem;
    line-height: 1.6;
}

.damage-box {
    background: #fef2f2;
    border-color: #fecaca;
}

/* Extension Modal Styles (keep existing) */
.book-info-card {
    background: var(--gray-50);
    border-radius: var(--radius);
    padding: 20px;
    margin-bottom: 24px;
    border: 1px solid var(--gray-200);
}

.book-info-content {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.book-info-header h4 {
    margin: 0 0 12px 0;
    color: var(--gray-900);
    font-size: 1.25rem;
}

.book-info-meta {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.meta-item {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.9rem;
    color: var(--gray-700);
}

.extensions-info {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.85rem;
    color: var(--gray-600);
    padding: 8px 12px;
    background: white;
    border-radius: var(--radius-sm);
    border: 1px solid var(--gray-200);
}

.form-section {
    margin-bottom: 24px;
}

.form-group {
    margin-bottom: 20px;
}

.form-label {
    display: block;
    margin-bottom: 8px;
    font-weight: 500;
    color: var(--gray-700);
    font-size: 0.95rem;
    display: flex;
    align-items: center;
    gap: 6px;
}

.form-textarea {
    padding: 12px 16px;
    border: 1px solid var(--gray-300);
    border-radius: var(--radius);
    background: white;
    color: var(--gray-800);
    font-size: 1rem;
    transition: var(--transition);
    width: 100%;
    font-family: inherit;
    resize: vertical;
    min-height: 100px;
}

.form-textarea:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
}

.form-hint {
    display: block;
    margin-top: 4px;
    font-size: 0.75rem;
    color: var(--gray-500);
}

.fee-info {
    background: var(--gray-50);
    border-radius: var(--radius);
    padding: 16px;
    margin-bottom: 20px;
    border: 1px solid var(--gray-200);
}

.fee-amount {
    font-size: 1.125rem;
    font-weight: 600;
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.max-extensions-info {
    font-size: 0.85rem;
    color: var(--gray-600);
    display: flex;
    align-items: center;
    gap: 6px;
}

.alert {
    padding: 16px;
    border-radius: var(--radius);
    margin-bottom: 20px;
    display: flex;
    align-items: flex-start;
    gap: 12px;
}

.alert-error {
    background: #fef2f2;
    border: 1px solid #fecaca;
    color: #991b1b;
}

.alert-success {
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
    color: #166534;
}

.modal-actions {
    display: flex;
    gap: 12px;
    justify-content: flex-end;
    padding-top: 24px;
    border-top: 1px solid var(--gray-200);
}

.btn-primary {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 12px 24px;
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    color: white;
    border: none;
    border-radius: var(--radius);
    font-weight: 600;
    font-size: 1rem;
    cursor: pointer;
    transition: var(--transition);
    position: relative;
}

.btn-primary:hover:not(:disabled) {
    transform: translateY(-2px);
    box-shadow: var(--shadow-lg);
}

.btn-primary:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    transform: none !important;
    box-shadow: none !important;
}

.btn-primary.loading .btn-text {
    opacity: 0;
}

.btn-loader {
    position: absolute;
    width: 20px;
    height: 20px;
    border: 2px solid rgba(255, 255, 255, 0.3);
    border-radius: 50%;
    border-top-color: white;
    animation: spin 1s linear infinite;
    opacity: 0;
}

.btn-primary.loading .btn-loader {
    opacity: 1;
}

.btn-secondary {
    padding: 12px 24px;
    background: var(--gray-200);
    color: var(--gray-700);
    border: none;
    border-radius: var(--radius);
    font-weight: 600;
    font-size: 1rem;
    cursor: pointer;
    transition: var(--transition);
}

.btn-secondary:hover {
    background: var(--gray-300);
}

/* Empty State */
.empty-state {
    padding: 80px 40px;
    text-align: center;
    background: white;
    border-radius: var(--radius-lg);
}

.empty-icon {
    color: var(--gray-300);
    margin-bottom: 24px;
}

.empty-state h3 {
    margin: 0 0 12px 0;
    color: var(--gray-700);
    font-size: 1.5rem;
}

.empty-state p {
    margin: 0 0 32px 0;
    color: var(--gray-500);
    max-width: 400px;
    margin-left: auto;
    margin-right: auto;
    line-height: 1.6;
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
    
    .header-stats {
        justify-content: center;
    }
    
    .stat-card {
        min-width: 120px;
    }
    
    .details-grid {
        grid-template-columns: 1fr;
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
    
    .filters-grid {
        grid-template-columns: 1fr;
    }
    
    .controls-header {
        flex-direction: column;
        gap: 16px;
        align-items: stretch;
    }
    
    .view-toggle {
        align-self: flex-start;
    }
    
    .borrowed-books-table,
    .return-history-table {
        font-size: 0.875rem;
    }
    
    .borrowed-books-table th,
    .borrowed-books-table td,
    .return-history-table th,
    .return-history-table td {
        padding: 12px 8px;
    }
    
    .modal-content {
        width: 95%;
        margin: 20px auto;
        max-width: 95%;
    }
    
    .modal-header {
        padding: 20px 24px;
    }
    
    .modal-body {
        padding: 24px;
    }
    
    .modal-footer,
    .modal-actions {
        flex-direction: column;
    }
    
    .modal-footer .btn-secondary,
    .modal-actions .btn-primary,
    .modal-actions .btn-secondary {
        width: 100%;
    }
    
    .detail-item {
        flex-direction: column;
        gap: 4px;
    }
    
    .detail-label,
    .detail-value {
        text-align: left;
        width: 100%;
    }
    
    .book-actions,
    .history-actions {
        flex-direction: column;
        gap: 8px;
    }
}
</style>

<?php include __DIR__ . '/_footer.php'; ?>