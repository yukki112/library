<?php
// Lost/Damage Report Page for Students
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/config.php';
require_login();
$u = current_user();

// Restrict access to students and non-staff
if (!in_array($u['role'], ['student', 'non_staff'], true)) {
    header('Location: dashboard.php');
    exit;
}

$patron_id = $u['patron_id'] ?? 0;
$username = $u['username'] ?? '';
$user_id = $u['id'] ?? 0;

$pdo = DB::conn();

// Get damage types from database
$damage_types = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM damage_types WHERE is_active = 1 ORDER BY name");
    $stmt->execute();
    $damage_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error fetching damage types: " . $e->getMessage());
}

// Handle form submission
$success_msg = '';
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_report'])) {
    try {
        $pdo->beginTransaction();
        
        $book_id = (int)$_POST['book_id'];
        $book_copy_id = isset($_POST['book_copy_id']) ? (int)$_POST['book_copy_id'] : null;
        $report_type = $_POST['report_type'];
        $severity = $_POST['severity'];
        $description = trim($_POST['description']);
        $notes = trim($_POST['notes'] ?? '');
        
        // Get selected damage types for damaged reports
        $damage_type_ids = [];
        $damage_fee_total = 0.00;
        $damage_types_json = '[]';
        
        if ($report_type === 'damaged' && isset($_POST['damage_types']) && is_array($_POST['damage_types'])) {
            $damage_type_ids = array_map('intval', $_POST['damage_types']);
            
            // Calculate total damage fee
            if (!empty($damage_type_ids)) {
                $placeholders = str_repeat('?,', count($damage_type_ids) - 1) . '?';
                $damage_stmt = $pdo->prepare("
                    SELECT id, name, fee_amount 
                    FROM damage_types 
                    WHERE id IN ($placeholders) AND is_active = 1
                ");
                $damage_stmt->execute($damage_type_ids);
                $selected_damage_types = $damage_stmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($selected_damage_types as $damage) {
                    $damage_fee_total += (float)$damage['fee_amount'];
                }
                
                // Store damage types as JSON
                $damage_types_json = json_encode($selected_damage_types);
            }
        }
        
        // Validate input
        if (!$book_id) {
            throw new Exception("Please select a book");
        }
        
        if (!in_array($report_type, ['lost', 'damaged'])) {
            throw new Exception("Invalid report type");
        }
        
        if (!in_array($severity, ['minor', 'moderate', 'severe'])) {
            throw new Exception("Invalid severity level");
        }
        
        if (empty($description)) {
            throw new Exception("Please provide a description");
        }
        
        // Check if this book is currently borrowed by the student
        $borrow_check = $pdo->prepare("
            SELECT bl.id, bl.book_copy_id, bl.due_date, b.title, b.author, bc.copy_number
            FROM borrow_logs bl
            JOIN books b ON bl.book_id = b.id
            LEFT JOIN book_copies bc ON bl.book_copy_id = bc.id
            WHERE bl.patron_id = ? 
            AND bl.book_id = ?
            AND bl.status IN ('borrowed', 'overdue')
        ");
        $borrow_check->execute([$patron_id, $book_id]);
        $borrow_data = $borrow_check->fetch(PDO::FETCH_ASSOC);
        
        if (!$borrow_data) {
            throw new Exception("You don't have this book currently borrowed");
        }
        
        // Use the borrowed copy_id if not specified
        if (!$book_copy_id && $borrow_data['book_copy_id']) {
            $book_copy_id = $borrow_data['book_copy_id'];
        }
        
        // Insert report
        $stmt = $pdo->prepare("
            INSERT INTO lost_damaged_reports 
            (book_copy_id, book_id, patron_id, report_date, report_type, severity, description, fee_charged, status, created_at)
            VALUES (?, ?, ?, CURDATE(), ?, ?, ?, ?, 'pending', NOW())
        ");
        
        $fee_charged = $damage_fee_total; // Set initial fee for damaged books
        $stmt->execute([
            $book_copy_id,
            $book_id,
            $patron_id,
            $report_type,
            $severity,
            $description,
            $fee_charged
        ]);
        
        $report_id = $pdo->lastInsertId();
        
        // Update book copy status if damaged
        if ($report_type === 'damaged' && $book_copy_id) {
            // Also update damage_types in borrow_logs if there's an active borrow
            $update_borrow = $pdo->prepare("
                UPDATE borrow_logs 
                SET damage_types = ?,
                    penalty_fee = COALESCE(penalty_fee, 0) + ?,
                    updated_at = NOW()
                WHERE book_copy_id = ? 
                AND status IN ('borrowed', 'overdue')
            ");
            $update_borrow->execute([$damage_types_json, $damage_fee_total, $book_copy_id]);
            
            $update_copy = $pdo->prepare("
                UPDATE book_copies 
                SET status = 'damaged', 
                    book_condition = 'damaged',
                    updated_at = NOW()
                WHERE id = ?
            ");
            $update_copy->execute([$book_copy_id]);
        }
        
        // Create audit log
        $audit_stmt = $pdo->prepare("
            INSERT INTO audit_logs (user_id, action, entity, entity_id, details, created_at)
            VALUES (?, 'create', 'lost_damaged_reports', ?, ?, NOW())
        ");
        $audit_stmt->execute([
            $user_id,
            $report_id,
            json_encode([
                'report_type' => $report_type,
                'severity' => $severity,
                'book_id' => $book_id,
                'book_copy_id' => $book_copy_id,
                'damage_types' => $damage_type_ids,
                'damage_fee_total' => $damage_fee_total
            ])
        ]);
        
        // Create notification for admin
        $notify_stmt = $pdo->prepare("
            INSERT INTO notifications 
            (user_id, role_target, type, message, meta, created_at)
            VALUES (NULL, 'admin', 'damage_report', 'New damage/lost report submitted', ?, NOW())
        ");
        $notify_stmt->execute([
            json_encode([
                'report_id' => $report_id,
                'patron_id' => $patron_id,
                'book_id' => $book_id,
                'report_type' => $report_type,
                'severity' => $severity,
                'damage_fee' => $damage_fee_total
            ])
        ]);
        
        $pdo->commit();
        
        $success_msg = "Report submitted successfully! Your report ID is #{$report_id}. ";
        if ($report_type === 'damaged' && $damage_fee_total > 0) {
            $success_msg .= "Total damage fee: ₱" . number_format($damage_fee_total, 2) . ". ";
        }
        $success_msg .= "The library staff will review it and contact you.";
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $error_msg = "Error: " . $e->getMessage();
    }
}

// Get currently borrowed books for dropdown
$borrowed_books = [];
try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT b.id, b.title, b.author, b.cover_image_cache,
               bc.id as copy_id, bc.copy_number, bc.book_condition,
               bl.borrowed_at, bl.due_date,
               COUNT(DISTINCT bc.id) as copy_count
        FROM borrow_logs bl
        JOIN books b ON bl.book_id = b.id
        LEFT JOIN book_copies bc ON bl.book_copy_id = bc.id
        WHERE bl.patron_id = ? 
        AND bl.status IN ('borrowed', 'overdue')
        GROUP BY b.id, b.title, b.author
        ORDER BY b.title ASC
    ");
    $stmt->execute([$patron_id]);
    $borrowed_books = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error fetching borrowed books: " . $e->getMessage());
}

// Get user's previous reports with receipt information
$my_reports = [];
try {
    $stmt = $pdo->prepare("
        SELECT r.*, 
               b.title, b.author, b.cover_image_cache,
               bc.copy_number, bc.barcode,
               p.name as patron_name,
               rp.receipt_number, rp.pdf_path, rp.payment_date,
               rp.total_amount as receipt_amount, rp.status as receipt_status,
               CASE 
                   WHEN r.status = 'pending' THEN 'Pending Review'
                   WHEN r.status = 'resolved' THEN 'Resolved'
                   ELSE r.status 
               END as status_text,
               DATE_FORMAT(r.report_date, '%M %d, %Y') as report_date_formatted,
               DATE_FORMAT(r.created_at, '%M %d, %Y %h:%i %p') as created_at_formatted
        FROM lost_damaged_reports r
        LEFT JOIN books b ON r.book_id = b.id
        LEFT JOIN book_copies bc ON r.book_copy_id = bc.id
        LEFT JOIN patrons p ON r.patron_id = p.id
        LEFT JOIN receipts rp ON (rp.extension_request_id IS NULL AND rp.borrow_log_id = 0 AND rp.damage_fee > 0)
        WHERE r.patron_id = ?
        ORDER BY r.created_at DESC
    ");
    $stmt->execute([$patron_id]);
    $my_reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error fetching reports: " . $e->getMessage());
}

// Get receipts for this patron
$my_receipts = [];
try {
    $stmt = $pdo->prepare("
        SELECT r.*,
               b.title, b.author, bc.copy_number,
               p.name as patron_name,
               DATE_FORMAT(r.created_at, '%M %d, %Y %h:%i %p') as receipt_date_formatted,
               DATE_FORMAT(r.payment_date, '%M %d, %Y %h:%i %p') as payment_date_formatted
        FROM receipts r
        LEFT JOIN borrow_logs bl ON r.borrow_log_id = bl.id
        LEFT JOIN books b ON bl.book_id = b.id
        LEFT JOIN book_copies bc ON bl.book_copy_id = bc.id
        LEFT JOIN patrons p ON r.patron_id = p.id
        WHERE r.patron_id = ?
        AND (r.damage_fee > 0 OR r.total_amount > 0)
        ORDER BY r.created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$patron_id]);
    $my_receipts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error fetching receipts: " . $e->getMessage());
}

include __DIR__ . '/_header.php';
?>

<div class="page-container">
    <div class="page-header">
        <div class="header-content">
            <div class="header-title-row">
                <div>
                    <h1 class="page-title">Report Lost/Damaged Book</h1>
                    <p class="page-subtitle">Report lost or damaged books that you have borrowed</p>
                </div>
                <div class="header-stats">
                    <div class="stat-card">
                        <div class="stat-value"><?php echo count($borrowed_books); ?></div>
                        <div class="stat-label">Books Borrowed</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?php echo count($my_reports); ?></div>
                        <div class="stat-label">Reports Filed</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?php echo count($my_receipts); ?></div>
                        <div class="stat-label">Receipts</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="report-container">
        <?php if ($success_msg): ?>
            <div class="alert alert-success">
                <div class="alert-icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                        <polyline points="22 4 12 14.01 9 11.01"></polyline>
                    </svg>
                </div>
                <div class="alert-content">
                    <strong>Success!</strong> <?php echo htmlspecialchars($success_msg); ?>
                </div>
            </div>
        <?php endif; ?>
        
        <?php if ($error_msg): ?>
            <div class="alert alert-error">
                <div class="alert-icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="12" y1="8" x2="12" y2="12"></line>
                        <line x1="12" y1="16" x2="12.01" y2="16"></line>
                    </svg>
                </div>
                <div class="alert-content">
                    <strong>Error!</strong> <?php echo htmlspecialchars($error_msg); ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="grid-layout">
            <!-- Left Column: Report Form -->
            <div class="report-form-container card">
                <div class="form-header">
                    <h3>
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M12 20h9"></path>
                            <path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"></path>
                        </svg>
                        Submit New Report
                    </h3>
                    <p class="form-subtitle">Report a lost or damaged book from your borrowed items</p>
                </div>
                
                <form method="POST" id="reportForm" class="report-form">
                    <div class="form-section">
                        <h4>1. Select Book</h4>
                        <div class="form-group">
                            <label for="book_id" class="form-label required">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1 0-5H20"></path>
                                </svg>
                                Select Book
                            </label>
                            <select id="book_id" name="book_id" class="form-select" required>
                                <option value="">-- Select a book --</option>
                                <?php foreach ($borrowed_books as $book): ?>
                                    <?php 
                                    $cover_image = $book['cover_image_cache'] ? 
                                        '../uploads/covers/' . htmlspecialchars($book['cover_image_cache']) : 
                                        '../assets/default-book-cover.png';
                                    ?>
                                    <option value="<?php echo $book['id']; ?>" 
                                            data-cover="<?php echo $cover_image; ?>"
                                            data-author="<?php echo htmlspecialchars($book['author']); ?>"
                                            data-copies="<?php echo $book['copy_count']; ?>">
                                        <?php echo htmlspecialchars($book['title']); ?> 
                                        <?php if ($book['copy_count'] > 1): ?>
                                            (<?php echo $book['copy_count']; ?> copies)
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (empty($borrowed_books)): ?>
                                <div class="form-hint">
                                    You don't have any books currently borrowed. 
                                    <a href="request_book.php">Borrow a book first</a>.
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="book-preview" id="bookPreview" style="display: none;">
                            <div class="preview-cover">
                                <img id="previewCover" src="" alt="Book Cover" 
                                     onerror="this.src='../assets/default-book-cover.png'">
                            </div>
                            <div class="preview-info">
                                <h5 id="previewTitle"></h5>
                                <p class="preview-author" id="previewAuthor"></p>
                                <div class="preview-meta">
                                    <span id="previewCopies"></span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-section">
                        <h4>2. Report Details</h4>
                        
                        <div class="form-group">
                            <label class="form-label required">Report Type</label>
                            <div class="radio-group">
                                <label class="radio-option">
                                    <input type="radio" name="report_type" value="lost" required onclick="toggleDamageTypes(false)">
                                    <div class="radio-content">
                                        <div class="radio-icon">
                                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <circle cx="12" cy="12" r="10"></circle>
                                                <line x1="15" y1="9" x2="9" y2="15"></line>
                                                <line x1="9" y1="9" x2="15" y2="15"></line>
                                            </svg>
                                        </div>
                                        <div>
                                            <strong>Lost Book</strong>
                                            <small>Book cannot be found or returned</small>
                                        </div>
                                    </div>
                                </label>
                                
                                <label class="radio-option">
                                    <input type="radio" name="report_type" value="damaged" required onclick="toggleDamageTypes(true)">
                                    <div class="radio-content">
                                        <div class="radio-icon">
                                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                                                <line x1="12" y1="9" x2="12" y2="13"></line>
                                                <line x1="12" y1="17" x2="12.01" y2="17"></line>
                                            </svg>
                                        </div>
                                        <div>
                                            <strong>Damaged Book</strong>
                                            <small>Book is damaged but can be returned</small>
                                        </div>
                                    </div>
                                </label>
                            </div>
                        </div>
                        
                        <!-- Damage Types Section (Hidden by default) -->
                        <div id="damageTypesSection" class="form-group" style="display: none;">
                            <label class="form-label">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                    <polyline points="14 2 14 8 20 8"></polyline>
                                    <line x1="16" y1="13" x2="8" y2="13"></line>
                                    <line x1="16" y1="17" x2="8" y2="17"></line>
                                </svg>
                                Damage Types (Select all that apply)
                            </label>
                            <div class="checkbox-grid">
                                <?php foreach ($damage_types as $damage): ?>
                                    <label class="checkbox-option">
                                        <input type="checkbox" name="damage_types[]" value="<?php echo $damage['id']; ?>" 
                                               class="damage-type-checkbox" 
                                               data-fee="<?php echo $damage['fee_amount']; ?>"
                                               onchange="calculateDamageFee()">
                                        <div class="checkbox-content">
                                            <div class="checkbox-main">
                                                <strong><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $damage['name']))); ?></strong>
                                                <span class="damage-fee">₱<?php echo number_format($damage['fee_amount'], 2); ?></span>
                                            </div>
                                            <?php if ($damage['description']): ?>
                                                <small class="damage-desc"><?php echo htmlspecialchars($damage['description']); ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <div id="damageFeeTotal" class="fee-total" style="display: none; margin-top: 15px;">
                                <div class="fee-total-item">
                                    <span>Total Damage Fee:</span>
                                    <span id="totalFeeAmount" class="total-fee-amount">₱0.00</span>
                                </div>
                            </div>
                            <div class="form-hint">
                                Select all damage types that apply to the book. Fees will be added automatically.
                                <strong>Note:</strong> Selecting no damage type is allowed for damaged reports.
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="severity" class="form-label required">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                                    <line x1="12" y1="9" x2="12" y2="13"></line>
                                    <line x1="12" y1="17" x2="12.01" y2="17"></line>
                                </svg>
                                Severity Level
                            </label>
                            <select id="severity" name="severity" class="form-select" required>
                                <option value="">-- Select severity --</option>
                                <option value="minor">Minor Damage (e.g., small tear, light marking)</option>
                                <option value="moderate">Moderate Damage (e.g., water damage, missing pages)</option>
                                <option value="severe">Severe Damage/Lost (unusable or lost book)</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="description" class="form-label required">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M12 20h9"></path>
                                    <path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"></path>
                                </svg>
                                Description
                            </label>
                            <textarea id="description" name="description" 
                                      class="form-textarea" 
                                      placeholder="Describe what happened to the book in detail..."
                                      rows="4" required></textarea>
                            <div class="form-hint">
                                Be specific about the damage or circumstances of loss. Include details like:
                                <ul>
                                    <li>Which parts are damaged (cover, pages, binding)</li>
                                    <li>How the damage occurred</li>
                                    <li>When and where it was lost (if applicable)</li>
                                </ul>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="notes" class="form-label">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                    <polyline points="14 2 14 8 20 8"></polyline>
                                    <line x1="16" y1="13" x2="8" y2="13"></line>
                                    <line x1="16" y1="17" x2="8" y2="17"></line>
                                    <polyline points="10 9 9 9 8 9"></polyline>
                                </svg>
                                Additional Notes (Optional)
                            </label>
                            <textarea id="notes" name="notes" 
                                      class="form-textarea" 
                                      placeholder="Any additional information..."
                                      rows="2"></textarea>
                        </div>
                    </div>
                    
                    <div class="form-section">
                        <h4>3. Important Information</h4>
                        <div class="info-box">
                            <div class="info-item">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="12" cy="12" r="10"></circle>
                                    <line x1="12" y1="8" x2="12" y2="12"></line>
                                    <line x1="12" y1="16" x2="12.01" y2="16"></line>
                                </svg>
                                <div>
                                    <strong>Fees may apply</strong>
                                    <p>Damaged books incur fees based on damage type. Lost books may incur replacement fees.</p>
                                </div>
                            </div>
                            <div class="info-item">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                    <line x1="16" y1="2" x2="16" y2="6"></line>
                                    <line x1="8" y1="2" x2="8" y2="6"></line>
                                    <line x1="3" y1="10" x2="21" y2="10"></line>
                                </svg>
                                <div>
                                    <strong>Review Process</strong>
                                    <p>Library staff will review your report within 3-5 business days.</p>
                                </div>
                            </div>
                            <div class="info-item">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                                    <polyline points="7 10 12 15 17 10"></polyline>
                                    <line x1="12" y1="15" x2="12" y2="3"></line>
                                </svg>
                                <div>
                                    <strong>Keep Your Receipt</strong>
                                    <p>You will receive a receipt for any fees paid. Receipts can be viewed in the 'My Receipts' section.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn-secondary" onclick="clearForm()">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polyline points="1 4 1 10 7 10"></polyline>
                                <path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"></path>
                            </svg>
                            Clear Form
                        </button>
                        <button type="submit" name="submit_report" class="btn-primary" 
                                <?php echo empty($borrowed_books) ? 'disabled' : ''; ?>>
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M22 2L11 13"></path>
                                <path d="M22 2l-7 20-4-9-9-4 20-7z"></path>
                            </svg>
                            Submit Report
                        </button>
                    </div>
                </form>
            </div>

            <!-- Right Column: My Reports & Receipts Tabs -->
            <div class="reports-receipts-container card">
                <div class="tabs-container">
                    <div class="tabs-header">
                        <button class="tab-button active" onclick="openTab('reports-tab')">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                <polyline points="14 2 14 8 20 8"></polyline>
                                <line x1="16" y1="13" x2="8" y2="13"></line>
                                <line x1="16" y1="17" x2="8" y2="17"></line>
                            </svg>
                            My Reports
                        </button>
                        <button class="tab-button" onclick="openTab('receipts-tab')">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="2" y="5" width="20" height="14" rx="2"></rect>
                                <line x1="2" y1="10" x2="22" y2="10"></line>
                            </svg>
                            My Receipts
                        </button>
                    </div>
                    
                    <!-- Reports Tab -->
                    <div id="reports-tab" class="tab-content active">
                        <div class="list-header">
                            <h3>
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                    <polyline points="14 2 14 8 20 8"></polyline>
                                    <line x1="16" y1="13" x2="8" y2="13"></line>
                                    <line x1="16" y1="17" x2="8" y2="17"></line>
                                    <polyline points="10 9 9 9 8 9"></polyline>
                                </svg>
                                My Reports
                            </h3>
                            <p class="list-subtitle">Previous reports you've submitted</p>
                        </div>
                        
                        <?php if (empty($my_reports)): ?>
                            <div class="empty-state">
                                <div class="empty-icon">
                                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                        <polyline points="14 2 14 8 20 8"></polyline>
                                        <line x1="16" y1="13" x2="8" y2="13"></line>
                                        <line x1="16" y1="17" x2="8" y2="17"></line>
                                        <polyline points="10 9 9 9 8 9"></polyline>
                                    </svg>
                                </div>
                                <h4>No Reports Yet</h4>
                                <p>You haven't submitted any lost/damage reports.</p>
                            </div>
                        <?php else: ?>
                            <div class="reports-list">
                                <?php foreach ($my_reports as $report): ?>
                                    <?php 
                                    $cover_image = $report['cover_image_cache'] ? 
                                        '../uploads/covers/' . htmlspecialchars($report['cover_image_cache']) : 
                                        '../assets/default-book-cover.png';
                                        
                                    // Status colors
                                    $status_color = '#6b7280'; // gray for pending
                                    if ($report['status'] === 'resolved') {
                                        $status_color = '#10b981'; // green for resolved
                                    }
                                    ?>
                                    
                                    <div class="report-card" data-report-id="<?php echo $report['id']; ?>">
                                        <div class="report-header">
                                            <div class="report-type">
                                                <span class="type-badge" style="background: <?php echo $report['report_type'] === 'lost' ? '#ef4444' : '#f59e0b'; ?>">
                                                    <?php echo ucfirst($report['report_type']); ?>
                                                </span>
                                                <span class="severity-badge">
                                                    <?php echo ucfirst($report['severity']); ?> severity
                                                </span>
                                            </div>
                                            <div class="report-status" style="color: <?php echo $status_color; ?>">
                                                <?php echo $report['status_text']; ?>
                                            </div>
                                        </div>
                                        
                                        <div class="report-content">
                                            <div class="report-book">
                                                <div class="book-cover-small">
                                                    <img src="<?php echo $cover_image; ?>" 
                                                         alt="<?php echo htmlspecialchars($report['title']); ?>"
                                                         onerror="this.src='../assets/default-book-cover.png'">
                                                </div>
                                                <div class="book-info">
                                                    <h5><?php echo htmlspecialchars($report['title']); ?></h5>
                                                    <p class="book-author"><?php echo htmlspecialchars($report['author']); ?></p>
                                                    <?php if ($report['copy_number']): ?>
                                                        <p class="copy-info">Copy: <?php echo htmlspecialchars($report['copy_number']); ?></p>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            
                                            <div class="report-details">
                                                <div class="detail-item">
                                                    <span class="detail-label">Reported:</span>
                                                    <span class="detail-value"><?php echo $report['report_date_formatted']; ?></span>
                                                </div>
                                                
                                                <?php if ($report['fee_charged'] > 0): ?>
                                                    <div class="detail-item">
                                                        <span class="detail-label">Fee Charged:</span>
                                                        <span class="detail-value text-danger">₱<?php echo number_format($report['fee_charged'], 2); ?></span>
                                                    </div>
                                                <?php endif; ?>
                                                
                                                <?php if ($report['receipt_number']): ?>
                                                    <div class="detail-item">
                                                        <span class="detail-label">Receipt #:</span>
                                                        <span class="detail-value"><?php echo htmlspecialchars($report['receipt_number']); ?></span>
                                                    </div>
                                                <?php endif; ?>
                                                
                                                <div class="description-preview">
                                                    <p class="description-text"><?php echo htmlspecialchars(substr($report['description'], 0, 100)); ?>...</p>
                                                </div>
                                            </div>
                                            
                                            <div class="report-actions">
                                                <button class="btn-view-details" 
                                                        onclick="viewReportDetails(<?php echo $report['id']; ?>)">
                                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                        <circle cx="12" cy="12" r="10"></circle>
                                                        <line x1="12" y1="8" x2="12" y2="12"></line>
                                                        <line x1="12" y1="16" x2="12.01" y2="16"></line>
                                                    </svg>
                                                    View Details
                                                </button>
                                                
                                                <?php if ($report['receipt_number'] && $report['pdf_path']): ?>
                                                    <a href="<?php echo htmlspecialchars($report['pdf_path']); ?>" target="_blank" class="btn-pay-fee">
                                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                            <rect x="2" y="5" width="20" height="14" rx="2"></rect>
                                                            <line x1="2" y1="10" x2="22" y2="10"></line>
                                                        </svg>
                                                        View Receipt
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Receipts Tab -->
                    <div id="receipts-tab" class="tab-content">
                        <div class="list-header">
                            <h3>
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <rect x="2" y="5" width="20" height="14" rx="2"></rect>
                                    <line x1="2" y1="10" x2="22" y2="10"></line>
                                </svg>
                                My Receipts
                            </h3>
                            <p class="list-subtitle">Receipts for fees paid</p>
                        </div>
                        
                        <?php if (empty($my_receipts)): ?>
                            <div class="empty-state">
                                <div class="empty-icon">
                                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <rect x="2" y="5" width="20" height="14" rx="2"></rect>
                                        <line x1="2" y1="10" x2="22" y2="10"></line>
                                    </svg>
                                </div>
                                <h4>No Receipts Yet</h4>
                                <p>You haven't received any receipts for fees paid.</p>
                            </div>
                        <?php else: ?>
                            <div class="receipts-list">
                                <?php foreach ($my_receipts as $receipt): ?>
                                    <?php 
                                    $receipt_color = '#6b7280'; // default
                                    if ($receipt['status'] === 'paid') {
                                        $receipt_color = '#10b981'; // green for paid
                                    } elseif ($receipt['status'] === 'pending') {
                                        $receipt_color = '#f59e0b'; // yellow for pending
                                    }
                                    ?>
                                    
                                    <div class="receipt-card">
                                        <div class="receipt-header">
                                            <div class="receipt-info">
                                                <span class="receipt-number">
                                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                        <rect x="2" y="5" width="20" height="14" rx="2"></rect>
                                                        <line x1="2" y1="10" x2="22" y2="10"></line>
                                                    </svg>
                                                    <?php echo htmlspecialchars($receipt['receipt_number']); ?>
                                                </span>
                                                <span class="receipt-status" style="color: <?php echo $receipt_color; ?>">
                                                    <?php echo ucfirst($receipt['status']); ?>
                                                </span>
                                            </div>
                                            <div class="receipt-date">
                                                <?php echo $receipt['receipt_date_formatted']; ?>
                                            </div>
                                        </div>
                                        
                                        <div class="receipt-content">
                                            <?php if ($receipt['title']): ?>
                                                <div class="receipt-book">
                                                    <h5><?php echo htmlspecialchars($receipt['title']); ?></h5>
                                                    <?php if ($receipt['author']): ?>
                                                        <p class="receipt-author"><?php echo htmlspecialchars($receipt['author']); ?></p>
                                                    <?php endif; ?>
                                                    <?php if ($receipt['copy_number']): ?>
                                                        <p class="copy-info">Copy: <?php echo htmlspecialchars($receipt['copy_number']); ?></p>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <div class="receipt-details">
                                                <div class="detail-item">
                                                    <span class="detail-label">Total Amount:</span>
                                                    <span class="detail-value text-danger">₱<?php echo number_format($receipt['total_amount'], 2); ?></span>
                                                </div>
                                                
                                                <?php if ($receipt['damage_fee'] > 0): ?>
                                                    <div class="detail-item">
                                                        <span class="detail-label">Damage Fee:</span>
                                                        <span class="detail-value">₱<?php echo number_format($receipt['damage_fee'], 2); ?></span>
                                                    </div>
                                                <?php endif; ?>
                                                
                                                <?php if ($receipt['late_fee'] > 0): ?>
                                                    <div class="detail-item">
                                                        <span class="detail-label">Late Fee:</span>
                                                        <span class="detail-value">₱<?php echo number_format($receipt['late_fee'], 2); ?></span>
                                                    </div>
                                                <?php endif; ?>
                                                
                                                <?php if ($receipt['extension_fee'] > 0): ?>
                                                    <div class="detail-item">
                                                        <span class="detail-label">Extension Fee:</span>
                                                        <span class="detail-value">₱<?php echo number_format($receipt['extension_fee'], 2); ?></span>
                                                    </div>
                                                <?php endif; ?>
                                                
                                                <?php if ($receipt['payment_date']): ?>
                                                    <div class="detail-item">
                                                        <span class="detail-label">Payment Date:</span>
                                                        <span class="detail-value"><?php echo $receipt['payment_date_formatted']; ?></span>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <div class="receipt-actions">
                                                <?php if ($receipt['pdf_path']): ?>
                                                    <a href="<?php echo htmlspecialchars($receipt['pdf_path']); ?>" target="_blank" class="btn-view-receipt">
                                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                                                            <polyline points="7 10 12 15 17 10"></polyline>
                                                            <line x1="12" y1="15" x2="12" y2="3"></line>
                                                        </svg>
                                                        View Receipt
                                                    </a>
                                                    <button class="btn-download-receipt" onclick="downloadReceipt('<?php echo htmlspecialchars($receipt['pdf_path']); ?>', '<?php echo htmlspecialchars($receipt['receipt_number']); ?>')">
                                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                                                            <polyline points="7 10 12 15 17 10"></polyline>
                                                            <line x1="12" y1="15" x2="12" y2="3"></line>
                                                        </svg>
                                                        Download
                                                    </button>
                                                <?php endif; ?>
                                                
                                                <?php if ($receipt['status'] === 'pending' && $receipt['total_amount'] > 0): ?>
                                                    <button class="btn-pay-now" onclick="payReceipt('<?php echo htmlspecialchars($receipt['receipt_number']); ?>')">
                                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                            <rect x="2" y="5" width="20" height="14" rx="2"></rect>
                                                            <line x1="2" y1="10" x2="22" y2="10"></line>
                                                        </svg>
                                                        Pay Now
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Report Details Modal -->
<div id="reportDetailsModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                    <polyline points="14 2 14 8 20 8"></polyline>
                    <line x1="16" y1="13" x2="8" y2="13"></line>
                    <line x1="16" y1="17" x2="8" y2="17"></line>
                    <polyline points="10 9 9 9 8 9"></polyline>
                </svg>
                Report Details
            </h2>
            <button class="modal-close" onclick="closeReportDetailsModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div id="reportDetailsContent" class="report-details-content">
                <!-- Report details will be loaded here -->
                <div class="loading-spinner">
                    <div class="spinner"></div>
                    <p>Loading report details...</p>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn-secondary" onclick="closeReportDetailsModal()">Close</button>
        </div>
    </div>
</div>

<!-- Receipt Details Modal -->
<div id="receiptDetailsModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="2" y="5" width="20" height="14" rx="2"></rect>
                    <line x1="2" y1="10" x2="22" y2="10"></line>
                </svg>
                Receipt Details
            </h2>
            <button class="modal-close" onclick="closeReceiptDetailsModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div id="receiptDetailsContent" class="receipt-details-content">
                <!-- Receipt details will be loaded here -->
                <div class="loading-spinner">
                    <div class="spinner"></div>
                    <p>Loading receipt details...</p>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn-secondary" onclick="closeReceiptDetailsModal()">Close</button>
            <button class="btn-primary" onclick="printReceipt()">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="6 9 6 2 18 2 18 9"></polyline>
                    <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path>
                    <rect x="6" y="14" width="12" height="8"></rect>
                </svg>
                Print Receipt
            </button>
        </div>
    </div>
</div>

<script>
// Global variables
let currentReportId = null;
let currentReceiptNumber = null;

// Tab switching functionality
function openTab(tabName) {
    // Hide all tab contents
    const tabContents = document.querySelectorAll('.tab-content');
    tabContents.forEach(tab => {
        tab.classList.remove('active');
    });
    
    // Remove active class from all tab buttons
    const tabButtons = document.querySelectorAll('.tab-button');
    tabButtons.forEach(button => {
        button.classList.remove('active');
    });
    
    // Show the selected tab content
    document.getElementById(tabName).classList.add('active');
    
    // Activate the clicked tab button
    event.currentTarget.classList.add('active');
}

// Toggle damage types section
function toggleDamageTypes(show) {
    const damageSection = document.getElementById('damageTypesSection');
    const feeTotal = document.getElementById('damageFeeTotal');
    
    if (show) {
        damageSection.style.display = 'block';
    } else {
        damageSection.style.display = 'none';
        // Clear all checkboxes
        const checkboxes = damageSection.querySelectorAll('input[type="checkbox"]');
        checkboxes.forEach(cb => {
            cb.checked = false;
        });
        feeTotal.style.display = 'none';
    }
    calculateDamageFee();
}

// Calculate damage fee total
function calculateDamageFee() {
    const checkboxes = document.querySelectorAll('.damage-type-checkbox:checked');
    let total = 0;
    
    checkboxes.forEach(checkbox => {
        total += parseFloat(checkbox.dataset.fee) || 0;
    });
    
    const feeTotal = document.getElementById('damageFeeTotal');
    const totalAmount = document.getElementById('totalFeeAmount');
    
    if (checkboxes.length > 0) {
        feeTotal.style.display = 'block';
        totalAmount.textContent = '₱' + total.toFixed(2);
    } else {
        feeTotal.style.display = 'none';
    }
}

// Initialize book preview
document.getElementById('book_id').addEventListener('change', function() {
    const bookId = this.value;
    const bookPreview = document.getElementById('bookPreview');
    const selectedOption = this.options[this.selectedIndex];
    
    if (bookId && selectedOption) {
        bookPreview.style.display = 'flex';
        
        document.getElementById('previewTitle').textContent = selectedOption.text;
        document.getElementById('previewAuthor').textContent = selectedOption.getAttribute('data-author');
        document.getElementById('previewCopies').textContent = selectedOption.getAttribute('data-copies') + ' copy(ies) available';
        
        const coverSrc = selectedOption.getAttribute('data-cover');
        document.getElementById('previewCover').src = coverSrc;
    } else {
        bookPreview.style.display = 'none';
    }
});

// Clear form
function clearForm() {
    if (confirm('Are you sure you want to clear the form? All entered data will be lost.')) {
        document.getElementById('reportForm').reset();
        document.getElementById('bookPreview').style.display = 'none';
        document.getElementById('damageTypesSection').style.display = 'none';
        document.getElementById('damageFeeTotal').style.display = 'none';
    }
}

// View report details
async function viewReportDetails(reportId) {
    const modal = document.getElementById('reportDetailsModal');
    const content = document.getElementById('reportDetailsContent');
    
    content.innerHTML = `
        <div class="loading-spinner">
            <div class="spinner"></div>
            <p>Loading report details...</p>
        </div>
    `;
    
    modal.style.display = 'block';
    document.body.style.overflow = 'hidden';
    currentReportId = reportId;
    
    try {
        // In a real implementation, you would fetch report details via AJAX
        // For now, we'll show a placeholder with the data we already have
        const reportCard = document.querySelector(`.report-card[data-report-id="${reportId}"]`);
        if (reportCard) {
            const title = reportCard.querySelector('h5').textContent;
            const author = reportCard.querySelector('.book-author').textContent;
            const copyNumber = reportCard.querySelector('.copy-info')?.textContent || 'N/A';
            const reportDate = reportCard.querySelector('.detail-value').textContent;
            const description = reportCard.querySelector('.description-text').textContent;
            const status = reportCard.querySelector('.report-status').textContent;
            const reportType = reportCard.querySelector('.type-badge').textContent;
            const severity = reportCard.querySelector('.severity-badge').textContent;
            const feeCharged = reportCard.querySelector('.text-danger')?.textContent || '₱0.00';
            const receiptNumber = reportCard.querySelector('.detail-item:nth-child(3) .detail-value')?.textContent || 'N/A';
            
            content.innerHTML = `
                <div class="report-full-details">
                    <div class="report-header-info">
                        <div class="report-meta">
                            <span class="meta-badge ${reportType.toLowerCase()}">${reportType}</span>
                            <span class="meta-badge severity">${severity}</span>
                            <span class="meta-badge status">${status}</span>
                        </div>
                        <div class="report-date">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                <line x1="16" y1="2" x2="16" y2="6"></line>
                                <line x1="8" y1="2" x2="8" y2="6"></line>
                                <line x1="3" y1="10" x2="21" y2="10"></line>
                            </svg>
                            Reported: ${reportDate}
                        </div>
                    </div>
                    
                    <div class="book-info-section">
                        <h4>Book Information</h4>
                        <div class="book-details">
                            <div class="book-cover-medium">
                                <img src="${reportCard.querySelector('img').src}" alt="${title}">
                            </div>
                            <div class="book-text-info">
                                <h5>${title}</h5>
                                <p class="book-author">${author}</p>
                                <div class="book-meta">
                                    <span>${copyNumber}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="description-section">
                        <h4>Description</h4>
                        <div class="description-full">
                            <p>${description}</p>
                        </div>
                    </div>
                    
                    <div class="fee-section">
                        <h4>Fee Information</h4>
                        <div class="fee-info">
                            <div class="fee-item">
                                <span class="fee-label">Fee Charged:</span>
                                <span class="fee-value">${feeCharged}</span>
                            </div>
                            <div class="fee-item">
                                <span class="fee-label">Receipt Number:</span>
                                <span class="fee-status">${receiptNumber}</span>
                            </div>
                            <div class="fee-item">
                                <span class="fee-label">Payment Status:</span>
                                <span class="fee-status">${status === 'Resolved' ? 'Completed' : 'Pending'}</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="timeline-section">
                        <h4>Report Timeline</h4>
                        <div class="timeline">
                            <div class="timeline-item completed">
                                <div class="timeline-marker"></div>
                                <div class="timeline-content">
                                    <strong>Report Submitted</strong>
                                    <small>${reportDate}</small>
                                </div>
                            </div>
                            <div class="timeline-item ${status === 'Pending Review' ? 'pending' : 'completed'}">
                                <div class="timeline-marker"></div>
                                <div class="timeline-content">
                                    <strong>Under Review</strong>
                                    <small>${status === 'Pending Review' ? 'Library staff will review your report' : 'Review completed'}</small>
                                </div>
                            </div>
                            <div class="timeline-item ${status === 'Resolved' ? 'completed' : 'future'}">
                                <div class="timeline-marker"></div>
                                <div class="timeline-content">
                                    <strong>Assessment Complete</strong>
                                    <small>${status === 'Resolved' ? 'Fee assessment completed' : 'You will be notified of any fees'}</small>
                                </div>
                            </div>
                            <div class="timeline-item ${status === 'Resolved' ? 'completed' : 'future'}">
                                <div class="timeline-marker"></div>
                                <div class="timeline-content">
                                    <strong>Resolution</strong>
                                    <small>${status === 'Resolved' ? 'Case closed' : 'Case will be closed'}</small>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="contact-section">
                        <h4>Need Help?</h4>
                        <div class="contact-info">
                            <p>If you have questions about your report, please contact the library:</p>
                            <ul>
                                <li><strong>Email:</strong> library@yourinstitution.edu</li>
                                <li><strong>Phone:</strong> (123) 456-7890</li>
                                <li><strong>Hours:</strong> Mon-Fri, 8:00 AM - 5:00 PM</li>
                            </ul>
                        </div>
                    </div>
                </div>
            `;
        } else {
            content.innerHTML = `
                <div class="error-state">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="12" y1="8" x2="12" y2="12"></line>
                        <line x1="12" y1="16" x2="12.01" y2="16"></line>
                    </svg>
                    <h4>Report Not Found</h4>
                    <p>The report details could not be loaded.</p>
                </div>
            `;
        }
    } catch (error) {
        console.error('Error loading report details:', error);
        content.innerHTML = `
            <div class="error-state">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="12" y1="8" x2="12" y2="12"></line>
                    <line x1="12" y1="16" x2="12.01" y2="16"></line>
                </svg>
                <h4>Error Loading Details</h4>
                <p>Failed to load report details. Please try again.</p>
            </div>
        `;
    }
}

// View receipt details
function viewReceiptDetails(receiptNumber) {
    const modal = document.getElementById('receiptDetailsModal');
    const content = document.getElementById('receiptDetailsContent');
    
    content.innerHTML = `
        <div class="loading-spinner">
            <div class="spinner"></div>
            <p>Loading receipt details...</p>
        </div>
    `;
    
    modal.style.display = 'block';
    document.body.style.overflow = 'hidden';
    currentReceiptNumber = receiptNumber;
    
    // In a real implementation, you would fetch receipt details via AJAX
    // For now, we'll show a simple receipt display
    setTimeout(() => {
        content.innerHTML = `
            <div class="receipt-display">
                <div class="receipt-header">
                    <h3>Library Receipt</h3>
                    <p class="receipt-number">#${receiptNumber}</p>
                </div>
                
                <div class="receipt-info">
                    <div class="info-row">
                        <span>Date:</span>
                        <span>${new Date().toLocaleDateString()}</span>
                    </div>
                    <div class="info-row">
                        <span>Time:</span>
                        <span>${new Date().toLocaleTimeString()}</span>
                    </div>
                    <div class="info-row">
                        <span>Patron:</span>
                        <span><?php echo htmlspecialchars($u['name'] ?? 'Student'); ?></span>
                    </div>
                    <div class="info-row">
                        <span>Library ID:</span>
                        <span><?php echo htmlspecialchars($username); ?></span>
                    </div>
                </div>
                
                <div class="receipt-items">
                    <div class="receipt-item">
                        <div class="item-name">Damage Fee</div>
                        <div class="item-amount">₱0.00</div>
                    </div>
                    <div class="receipt-item">
                        <div class="item-name">Late Fee</div>
                        <div class="item-amount">₱0.00</div>
                    </div>
                </div>
                
                <div class="receipt-total">
                    <div class="total-label">Total:</div>
                    <div class="total-amount">₱0.00</div>
                </div>
                
                <div class="receipt-footer">
                    <p>Thank you for your payment!</p>
                    <p class="receipt-note">Please keep this receipt for your records.</p>
                </div>
            </div>
        `;
    }, 500);
}

// Download receipt
function downloadReceipt(pdfPath, receiptNumber) {
    // Create a temporary link element
    const link = document.createElement('a');
    link.href = pdfPath;
    link.download = `Receipt_${receiptNumber}.pdf`;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

// Pay receipt
function payReceipt(receiptNumber) {
    if (confirm(`Do you want to pay receipt #${receiptNumber}? You will be redirected to the payment page.`)) {
        // In a real implementation, redirect to payment gateway
        alert(`Redirecting to payment for receipt #${receiptNumber}...`);
        // window.location.href = `payment.php?receipt=${receiptNumber}`;
    }
}

// Print receipt
function printReceipt() {
    const receiptContent = document.getElementById('receiptDetailsContent').innerHTML;
    const printWindow = window.open('', '_blank');
    printWindow.document.write(`
        <html>
            <head>
                <title>Print Receipt</title>
                <style>
                    body { font-family: Arial, sans-serif; margin: 20px; }
                    .receipt { border: 1px solid #000; padding: 20px; max-width: 400px; }
                    .receipt-header { text-align: center; margin-bottom: 20px; }
                    .receipt-number { font-weight: bold; }
                    .info-row { display: flex; justify-content: space-between; margin-bottom: 5px; }
                    .receipt-items { border-top: 1px solid #000; border-bottom: 1px solid #000; margin: 20px 0; padding: 10px 0; }
                    .receipt-item { display: flex; justify-content: space-between; margin-bottom: 10px; }
                    .receipt-total { display: flex; justify-content: space-between; font-weight: bold; font-size: 1.2em; margin-top: 20px; }
                    .receipt-footer { text-align: center; margin-top: 30px; font-size: 0.9em; color: #666; }
                    @media print { button { display: none; } }
                </style>
            </head>
            <body>
                <div class="receipt">
                    ${receiptContent}
                </div>
                <br>
                <button onclick="window.print()">Print Receipt</button>
                <button onclick="window.close()">Close</button>
            </body>
        </html>
    `);
    printWindow.document.close();
}

// Close report details modal
function closeReportDetailsModal() {
    const modal = document.getElementById('reportDetailsModal');
    modal.style.display = 'none';
    document.body.style.overflow = 'auto';
    currentReportId = null;
}

// Close receipt details modal
function closeReceiptDetailsModal() {
    const modal = document.getElementById('receiptDetailsModal');
    modal.style.display = 'none';
    document.body.style.overflow = 'auto';
    currentReceiptNumber = null;
}

// Form validation
document.getElementById('reportForm').addEventListener('submit', function(e) {
    const bookId = document.getElementById('book_id').value;
    const reportType = document.querySelector('input[name="report_type"]:checked');
    const severity = document.getElementById('severity').value;
    const description = document.getElementById('description').value.trim();
    
    if (!bookId) {
        e.preventDefault();
        alert('Please select a book to report.');
        return;
    }
    
    if (!reportType) {
        e.preventDefault();
        alert('Please select a report type (Lost or Damaged).');
        return;
    }
    
    if (!severity) {
        e.preventDefault();
        alert('Please select the severity level.');
        return;
    }
    
    if (!description) {
        e.preventDefault();
        alert('Please provide a description of what happened.');
        return;
    }
    
    // Confirm submission
    if (!confirm('Are you sure you want to submit this report? This action cannot be undone.')) {
        e.preventDefault();
        return;
    }
});

// Event listeners for modals
document.addEventListener('DOMContentLoaded', () => {
    // Close modals when clicking outside
    const modals = document.querySelectorAll('.modal');
    modals.forEach(modal => {
        window.addEventListener('click', function(event) {
            if (event.target === modal) {
                if (modal.id === 'reportDetailsModal') {
                    closeReportDetailsModal();
                } else if (modal.id === 'receiptDetailsModal') {
                    closeReceiptDetailsModal();
                }
            }
        });
    });
    
    // Close with Escape key
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            const reportModal = document.getElementById('reportDetailsModal');
            const receiptModal = document.getElementById('receiptDetailsModal');
            
            if (reportModal.style.display === 'block') {
                closeReportDetailsModal();
            } else if (receiptModal.style.display === 'block') {
                closeReceiptDetailsModal();
            }
        }
    });
    
    // Auto-focus on first field if form is empty
    if (document.getElementById('book_id').value === '') {
        document.getElementById('book_id').focus();
    }
});
</script>

<style>
/* Add new styles for receipts and tabs */
.tabs-container {
    padding: 0;
}

.tabs-header {
    display: flex;
    border-bottom: 1px solid var(--gray-200);
    background: var(--gray-50);
}

.tab-button {
    padding: 16px 24px;
    background: none;
    border: none;
    border-bottom: 3px solid transparent;
    font-size: 1rem;
    font-weight: 500;
    color: var(--gray-600);
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: var(--transition);
}

.tab-button:hover {
    color: var(--gray-800);
    background: var(--gray-100);
}

.tab-button.active {
    color: var(--primary);
    border-bottom-color: var(--primary);
    background: white;
}

.tab-content {
    display: none;
    padding: 0;
}

.tab-content.active {
    display: block;
}

/* Receipt card styles */
.receipts-list {
    padding: 24px;
    max-height: 800px;
    overflow-y: auto;
}

.receipt-card {
    background: white;
    border: 1px solid var(--gray-200);
    border-radius: var(--radius);
    margin-bottom: 16px;
    overflow: hidden;
    transition: var(--transition);
}

.receipt-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-lg);
}

.receipt-header {
    padding: 12px 16px;
    background: var(--gray-50);
    border-bottom: 1px solid var(--gray-200);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.receipt-info {
    display: flex;
    gap: 12px;
    align-items: center;
}

.receipt-number {
    font-weight: 600;
    color: var(--gray-800);
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 0.95rem;
}

.receipt-status {
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 3px 8px;
    border-radius: 12px;
    background: var(--gray-200);
}

.receipt-date {
    font-size: 0.875rem;
    color: var(--gray-600);
}

.receipt-content {
    padding: 20px;
}

.receipt-book {
    margin-bottom: 16px;
    padding-bottom: 16px;
    border-bottom: 1px solid var(--gray-200);
}

.receipt-book h5 {
    margin: 0 0 4px 0;
    font-size: 1rem;
    color: var(--gray-900);
    line-height: 1.3;
}

.receipt-author {
    margin: 0 0 4px 0;
    color: var(--gray-600);
    font-size: 0.875rem;
}

.receipt-details {
    margin-bottom: 16px;
}

.receipt-actions {
    display: flex;
    gap: 8px;
    align-items: center;
    justify-content: flex-start;
    padding-top: 16px;
    border-top: 1px solid var(--gray-200);
}

.btn-view-receipt, .btn-download-receipt, .btn-pay-now {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    font-size: 0.875rem;
    font-weight: 500;
    border-radius: var(--radius);
    cursor: pointer;
    transition: var(--transition);
    text-decoration: none;
    border: none;
}

.btn-view-receipt {
    background: var(--gray-200);
    color: var(--gray-700);
}

.btn-view-receipt:hover {
    background: var(--gray-300);
}

.btn-download-receipt {
    background: var(--info);
    color: white;
}

.btn-download-receipt:hover {
    background: var(--primary-dark);
    transform: translateY(-1px);
}

.btn-pay-now {
    background: var(--success);
    color: white;
}

.btn-pay-now:hover {
    background: var(--secondary-dark);
    transform: translateY(-1px);
}

/* Receipt display styles */
.receipt-display {
    max-width: 400px;
    margin: 0 auto;
    background: white;
    border: 1px solid var(--gray-300);
    border-radius: var(--radius);
    padding: 24px;
    box-shadow: var(--shadow-md);
}

.receipt-header {
    text-align: center;
    margin-bottom: 20px;
    border-bottom: 2px solid var(--gray-300);
    padding-bottom: 20px;
}

.receipt-header h3 {
    margin: 0 0 8px 0;
    color: var(--gray-900);
}

.receipt-number {
    font-weight: bold;
    color: var(--primary);
    font-size: 1.1rem;
}

.receipt-info {
    margin-bottom: 20px;
}

.info-row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 8px;
    font-size: 0.95rem;
}

.info-row span:first-child {
    color: var(--gray-600);
    font-weight: 500;
}

.info-row span:last-child {
    color: var(--gray-800);
}

.receipt-items {
    border-top: 1px solid var(--gray-300);
    border-bottom: 1px solid var(--gray-300);
    margin: 20px 0;
    padding: 15px 0;
}

.receipt-item {
    display: flex;
    justify-content: space-between;
    margin-bottom: 12px;
    font-size: 0.95rem;
}

.receipt-item:last-child {
    margin-bottom: 0;
}

.item-name {
    color: var(--gray-700);
}

.item-amount {
    color: var(--gray-800);
    font-weight: 500;
}

.receipt-total {
    display: flex;
    justify-content: space-between;
    font-weight: bold;
    font-size: 1.2rem;
    margin-top: 20px;
    padding-top: 15px;
    border-top: 2px solid var(--gray-300);
}

.total-label {
    color: var(--gray-800);
}

.total-amount {
    color: var(--primary);
}

.receipt-footer {
    text-align: center;
    margin-top: 30px;
    font-size: 0.9rem;
    color: var(--gray-600);
}

.receipt-note {
    font-style: italic;
    margin-top: 10px;
}

/* Add new styles for damage types */
.checkbox-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 12px;
}

.checkbox-option {
    position: relative;
    cursor: pointer;
}

.checkbox-option input {
    position: absolute;
    opacity: 0;
    cursor: pointer;
}

.checkbox-content {
    padding: 12px;
    border: 2px solid var(--gray-300);
    border-radius: var(--radius);
    background: var(--gray-50);
    transition: var(--transition);
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.checkbox-option input:checked + .checkbox-content {
    border-color: var(--primary);
    background: var(--primary-light);
    box-shadow: var(--shadow-sm);
}

.checkbox-option:hover .checkbox-content {
    border-color: var(--gray-400);
}

.checkbox-main {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.checkbox-main strong {
    color: var(--gray-900);
    font-size: 0.9rem;
}

.damage-fee {
    font-weight: 600;
    color: var(--danger);
    font-size: 0.875rem;
}

.damage-desc {
    color: var(--gray-600);
    font-size: 0.75rem;
    line-height: 1.4;
}

.fee-total {
    background: var(--gray-50);
    border: 1px solid var(--gray-300);
    border-radius: var(--radius);
    padding: 16px;
}

.fee-total-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 1rem;
}

.fee-total-item span:first-child {
    font-weight: 600;
    color: var(--gray-700);
}

.total-fee-amount {
    font-weight: 700;
    color: var(--danger);
    font-size: 1.25rem;
}

/* Adjust existing styles for new elements */
.form-hint {
    margin-top: 8px;
    font-size: 0.875rem;
    color: var(--gray-500);
    line-height: 1.5;
}

.form-hint ul {
    margin: 4px 0 0 20px;
    padding: 0;
}

.form-hint li {
    margin-bottom: 2px;
}

/* Keep all existing CSS styles from the original code */
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

/* Alert Styles */
.alert {
    padding: 16px 20px;
    border-radius: var(--radius);
    margin-bottom: 24px;
    display: flex;
    align-items: flex-start;
    gap: 12px;
    animation: slideIn 0.3s ease;
}

@keyframes slideIn {
    from {
        transform: translateY(-10px);
        opacity: 0;
    }
    to {
        transform: translateY(0);
        opacity: 1;
    }
}

.alert-success {
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
    color: #166534;
}

.alert-error {
    background: #fef2f2;
    border: 1px solid #fecaca;
    color: #991b1b;
}

.alert-icon {
    flex-shrink: 0;
}

.alert-content {
    flex: 1;
}

/* Grid Layout */
.grid-layout {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 30px;
}

@media (max-width: 1024px) {
    .grid-layout {
        grid-template-columns: 1fr;
    }
}

/* Card Styles */
.card {
    background: white;
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-lg);
    overflow: hidden;
}

/* Report Form Styles */
.report-form-container {
    padding: 0;
}

.form-header {
    padding: 24px 32px;
    border-bottom: 1px solid var(--gray-200);
    background: linear-gradient(135deg, var(--gray-50), white);
}

.form-header h3 {
    margin: 0 0 8px 0;
    font-size: 1.5rem;
    color: var(--gray-900);
    display: flex;
    align-items: center;
    gap: 12px;
}

.form-subtitle {
    margin: 0;
    color: var(--gray-600);
    font-size: 0.95rem;
}

.report-form {
    padding: 32px;
}

.form-section {
    margin-bottom: 32px;
    padding-bottom: 32px;
    border-bottom: 1px solid var(--gray-200);
}

.form-section:last-child {
    border-bottom: none;
    margin-bottom: 0;
    padding-bottom: 0;
}

.form-section h4 {
    margin: 0 0 20px 0;
    font-size: 1.125rem;
    color: var(--gray-800);
    display: flex;
    align-items: center;
    gap: 10px;
}

.form-section h4:before {
    content: '';
    width: 6px;
    height: 6px;
    background: var(--primary);
    border-radius: 50%;
    display: inline-block;
}

.form-group {
    margin-bottom: 24px;
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

.form-label.required:after {
    content: '*';
    color: var(--danger);
    margin-left: 4px;
}

.form-select, .form-textarea, .form-input {
    width: 100%;
    padding: 12px 16px;
    border: 1px solid var(--gray-300);
    border-radius: var(--radius);
    background: white;
    color: var(--gray-800);
    font-size: 1rem;
    transition: var(--transition);
    font-family: inherit;
}

.form-select:focus, .form-textarea:focus, .form-input:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
}

.form-textarea {
    resize: vertical;
    min-height: 100px;
}

/* Radio Group */
.radio-group {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 12px;
}

.radio-option {
    position: relative;
    cursor: pointer;
}

.radio-option input {
    position: absolute;
    opacity: 0;
    cursor: pointer;
}

.radio-content {
    padding: 16px;
    border: 2px solid var(--gray-300);
    border-radius: var(--radius);
    background: var(--gray-50);
    transition: var(--transition);
    display: flex;
    gap: 12px;
    align-items: flex-start;
}

.radio-option input:checked + .radio-content {
    border-color: var(--primary);
    background: var(--primary-light);
    box-shadow: var(--shadow-sm);
}

.radio-option:hover .radio-content {
    border-color: var(--gray-400);
}

.radio-icon {
    flex-shrink: 0;
    color: var(--gray-600);
}

.radio-option input:checked + .radio-content .radio-icon {
    color: var(--primary);
}

.radio-content strong {
    display: block;
    margin-bottom: 4px;
    color: var(--gray-900);
}

.radio-content small {
    display: block;
    color: var(--gray-600);
    font-size: 0.75rem;
    line-height: 1.4;
}

/* Book Preview */
.book-preview {
    display: none;
    align-items: center;
    gap: 20px;
    padding: 20px;
    background: var(--gray-50);
    border-radius: var(--radius);
    border: 1px solid var(--gray-200);
    margin-top: 16px;
    animation: fadeIn 0.3s ease;
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

.preview-cover {
    width: 100px;
    height: 140px;
    flex-shrink: 0;
    background: white;
    border-radius: 6px;
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: var(--shadow-md);
}

.preview-cover img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.preview-info {
    flex: 1;
}

.preview-info h5 {
    margin: 0 0 8px 0;
    font-size: 1.125rem;
    color: var(--gray-900);
    line-height: 1.3;
}

.preview-author {
    margin: 0 0 8px 0;
    color: var(--gray-600);
    font-size: 0.95rem;
}

.preview-meta {
    font-size: 0.875rem;
    color: var(--gray-500);
}

/* Info Box */
.info-box {
    background: var(--gray-50);
    border-radius: var(--radius);
    padding: 20px;
    border: 1px solid var(--gray-200);
}

.info-item {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    margin-bottom: 16px;
}

.info-item:last-child {
    margin-bottom: 0;
}

.info-item svg {
    flex-shrink: 0;
    color: var(--info);
    margin-top: 2px;
}

.info-item strong {
    display: block;
    margin-bottom: 4px;
    color: var(--gray-900);
}

.info-item p {
    margin: 0;
    color: var(--gray-600);
    font-size: 0.9rem;
    line-height: 1.5;
}

/* Form Actions */
.form-actions {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding-top: 24px;
    border-top: 1px solid var(--gray-200);
}

.btn-primary, .btn-secondary {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 12px 24px;
    font-weight: 600;
    font-size: 1rem;
    border-radius: var(--radius);
    cursor: pointer;
    transition: var(--transition);
    border: none;
    font-family: inherit;
}

.btn-primary {
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    color: white;
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

.btn-secondary {
    background: var(--gray-200);
    color: var(--gray-700);
}

.btn-secondary:hover {
    background: var(--gray-300);
}

/* Reports & Receipts Container */
.reports-receipts-container {
    padding: 0;
}

/* Reports List Styles */
.list-header {
    padding: 24px 32px;
    border-bottom: 1px solid var(--gray-200);
    background: linear-gradient(135deg, var(--gray-50), white);
}

.list-header h3 {
    margin: 0 0 8px 0;
    font-size: 1.5rem;
    color: var(--gray-900);
    display: flex;
    align-items: center;
    gap: 12px;
}

.list-subtitle {
    margin: 0;
    color: var(--gray-600);
    font-size: 0.95rem;
}

.reports-list {
    padding: 24px;
    max-height: 800px;
    overflow-y: auto;
}

.empty-state {
    padding: 60px 40px;
    text-align: center;
    color: var(--gray-500);
}

.empty-state .empty-icon {
    margin-bottom: 20px;
}

.empty-state h4 {
    margin: 0 0 12px 0;
    color: var(--gray-700);
    font-size: 1.25rem;
}

.empty-state p {
    margin: 0;
    font-size: 0.95rem;
    max-width: 300px;
    margin-left: auto;
    margin-right: auto;
}

/* Report Card */
.report-card {
    background: white;
    border: 1px solid var(--gray-200);
    border-radius: var(--radius);
    margin-bottom: 16px;
    overflow: hidden;
    transition: var(--transition);
}

.report-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-lg);
}

.report-header {
    padding: 12px 16px;
    background: var(--gray-50);
    border-bottom: 1px solid var(--gray-200);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.report-type {
    display: flex;
    gap: 8px;
    align-items: center;
}

.type-badge {
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
    color: white;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.severity-badge {
    font-size: 0.75rem;
    color: var(--gray-600);
    background: var(--gray-200);
    padding: 3px 8px;
    border-radius: 12px;
}

.report-status {
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.report-content {
    padding: 20px;
}

.report-book {
    display: flex;
    gap: 16px;
    align-items: center;
    margin-bottom: 16px;
    padding-bottom: 16px;
    border-bottom: 1px solid var(--gray-200);
}

.book-cover-small {
    width: 50px;
    height: 70px;
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

.book-info {
    flex: 1;
}

.book-info h5 {
    margin: 0 0 4px 0;
    font-size: 1rem;
    color: var(--gray-900);
    line-height: 1.3;
}

.book-author {
    margin: 0 0 4px 0;
    color: var(--gray-600);
    font-size: 0.875rem;
}

.copy-info {
    margin: 0;
    font-size: 0.75rem;
    color: var(--gray-500);
}

.report-details {
    margin-bottom: 16px;
}

.detail-item {
    display: flex;
    justify-content: space-between;
    margin-bottom: 8px;
    align-items: center;
}

.detail-item:last-child {
    margin-bottom: 0;
}

.detail-label {
    font-size: 0.875rem;
    color: var(--gray-600);
}

.detail-value {
    font-size: 0.875rem;
    color: var(--gray-800);
    font-weight: 500;
}

.description-preview {
    margin-top: 12px;
    padding: 12px;
    background: var(--gray-50);
    border-radius: var(--radius-sm);
    border-left: 3px solid var(--gray-400);
}

.description-text {
    margin: 0;
    font-size: 0.875rem;
    color: var(--gray-700);
    line-height: 1.5;
}

.report-actions {
    display: flex;
    gap: 8px;
    align-items: center;
    justify-content: space-between;
    padding-top: 16px;
    border-top: 1px solid var(--gray-200);
}

.btn-view-details, .btn-pay-fee {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    font-size: 0.875rem;
    font-weight: 500;
    border-radius: var(--radius);
    cursor: pointer;
    transition: var(--transition);
    text-decoration: none;
    border: none;
}

.btn-view-details {
    background: var(--gray-200);
    color: var(--gray-700);
}

.btn-view-details:hover {
    background: var(--gray-300);
}

.btn-pay-fee {
    background: var(--success);
    color: white;
}

.btn-pay-fee:hover {
    background: var(--secondary-dark);
    transform: translateY(-1px);
}

.text-danger { color: var(--danger); }
.text-success { color: var(--success); }
.text-muted { color: var(--gray-500); }

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

.modal-content {
    position: relative;
    background-color: white;
    margin: 5% auto;
    padding: 0;
    width: 90%;
    max-width: 700px;
    border-radius: var(--radius-xl);
    box-shadow: var(--shadow-xl);
    animation: slideIn 0.3s ease;
    max-height: 90vh;
    display: flex;
    flex-direction: column;
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

/* Report Details Content */
.report-details-content, .receipt-details-content {
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

/* Report Full Details */
.report-full-details {
    display: flex;
    flex-direction: column;
    gap: 24px;
}

.report-header-info {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding-bottom: 16px;
    border-bottom: 1px solid var(--gray-200);
}

.report-meta {
    display: flex;
    gap: 8px;
    align-items: center;
}

.meta-badge {
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.meta-badge.lost {
    background: #fee2e2;
    color: #991b1b;
}

.meta-badge.damaged {
    background: #fef3c7;
    color: #92400e;
}

.meta-badge.severity {
    background: var(--gray-200);
    color: var(--gray-700);
}

.meta-badge.status {
    background: var(--gray-100);
    color: var(--gray-600);
}

.report-date {
    font-size: 0.875rem;
    color: var(--gray-600);
    display: flex;
    align-items: center;
    gap: 6px;
}

.book-info-section h4,
.description-section h4,
.fee-section h4,
.timeline-section h4,
.contact-section h4 {
    margin: 0 0 16px 0;
    color: var(--gray-800);
    font-size: 1.125rem;
    border-bottom: 2px solid var(--gray-300);
    padding-bottom: 8px;
}

.book-details {
    display: flex;
    gap: 20px;
    align-items: center;
}

.book-cover-medium {
    width: 80px;
    height: 112px;
    flex-shrink: 0;
    background: var(--gray-100);
    border-radius: 6px;
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: var(--shadow-md);
}

.book-cover-medium img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.book-text-info h5 {
    margin: 0 0 8px 0;
    font-size: 1.25rem;
    color: var(--gray-900);
}

.book-text-info .book-author {
    margin: 0 0 8px 0;
    color: var(--gray-600);
    font-size: 1rem;
}

.book-meta {
    font-size: 0.875rem;
    color: var(--gray-500);
}

.description-full {
    background: var(--gray-50);
    border-radius: var(--radius);
    padding: 20px;
    border: 1px solid var(--gray-200);
}

.description-full p {
    margin: 0;
    color: var(--gray-700);
    line-height: 1.6;
}

.fee-info {
    background: var(--gray-50);
    border-radius: var(--radius);
    padding: 20px;
    border: 1px solid var(--gray-200);
}

.fee-item {
    display: flex;
    justify-content: space-between;
    margin-bottom: 12px;
    align-items: center;
}

.fee-item:last-child {
    margin-bottom: 0;
}

.fee-label {
    font-weight: 500;
    color: var(--gray-700);
}

.fee-value {
    font-weight: 600;
    color: var(--gray-900);
}

.fee-status {
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
    background: var(--gray-200);
    color: var(--gray-700);
}

/* Timeline */
.timeline {
    position: relative;
    padding-left: 30px;
}

.timeline:before {
    content: '';
    position: absolute;
    left: 9px;
    top: 0;
    bottom: 0;
    width: 2px;
    background: var(--gray-300);
}

.timeline-item {
    position: relative;
    margin-bottom: 24px;
}

.timeline-item:last-child {
    margin-bottom: 0;
}

.timeline-marker {
    position: absolute;
    left: -30px;
    top: 0;
    width: 20px;
    height: 20px;
    border-radius: 50%;
    border: 3px solid white;
    z-index: 1;
}

.timeline-item.completed .timeline-marker {
    background: var(--success);
    box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.2);
}

.timeline-item.pending .timeline-marker {
    background: var(--warning);
    box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.2);
}

.timeline-item.future .timeline-marker {
    background: var(--gray-300);
}

.timeline-content {
    background: var(--gray-50);
    border-radius: var(--radius);
    padding: 16px;
    border: 1px solid var(--gray-200);
}

.timeline-content strong {
    display: block;
    margin-bottom: 4px;
    color: var(--gray-900);
}

.timeline-content small {
    display: block;
    color: var(--gray-600);
    font-size: 0.875rem;
}

/* Contact Section */
.contact-info {
    background: var(--gray-50);
    border-radius: var(--radius);
    padding: 20px;
    border: 1px solid var(--gray-200);
}

.contact-info p {
    margin: 0 0 16px 0;
    color: var(--gray-700);
    line-height: 1.6;
}

.contact-info ul {
    margin: 0;
    padding: 0 0 0 20px;
    color: var(--gray-700);
}

.contact-info li {
    margin-bottom: 8px;
    line-height: 1.5;
}

.contact-info li:last-child {
    margin-bottom: 0;
}

.contact-info strong {
    color: var(--gray-900);
}

/* Responsive Design */
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
    
    .header-title-row {
        flex-direction: column;
        align-items: stretch;
        gap: 16px;
    }
    
    .header-stats {
        justify-content: center;
        flex-wrap: wrap;
    }
    
    .stat-card {
        min-width: 120px;
        padding: 16px;
        flex: 1;
    }
    
    .radio-group {
        grid-template-columns: 1fr;
    }
    
    .checkbox-grid {
        grid-template-columns: 1fr;
    }
    
    .report-form {
        padding: 24px;
    }
    
    .form-section {
        padding: 24px 0;
        margin-bottom: 24px;
    }
    
    .reports-list, .receipts-list {
        padding: 20px;
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
    
    .modal-footer {
        padding: 20px 24px;
        flex-direction: column;
    }
    
    .book-details {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .book-cover-medium {
        width: 100%;
        max-width: 150px;
        height: auto;
        aspect-ratio: 2/3;
    }
    
    .report-header-info {
        flex-direction: column;
        align-items: flex-start;
        gap: 12px;
    }
    
    .report-meta {
        flex-wrap: wrap;
    }
    
    .tabs-header {
        flex-direction: column;
    }
    
    .tab-button {
        width: 100%;
        justify-content: center;
    }
    
    .receipt-actions {
        flex-direction: column;
        align-items: stretch;
    }
    
    .btn-view-receipt, .btn-download-receipt, .btn-pay-now {
        width: 100%;
        justify-content: center;
        margin-bottom: 8px;
    }
}

@media (max-width: 480px) {
    .header-stats {
        flex-direction: column;
    }
    
    .stat-card {
        width: 100%;
    }
    
    .form-actions {
        flex-direction: column;
        gap: 12px;
    }
    
    .btn-primary, .btn-secondary {
        width: 100%;
        justify-content: center;
    }
}
</style>

<?php include __DIR__ . '/_footer.php'; ?>