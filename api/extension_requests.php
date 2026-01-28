<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/notify.php';
require_once __DIR__ . '/../includes/settings.php';

// Start session
start_app_session();

// Check authentication
if (!is_logged_in()) {
    json_response(['error' => 'Authentication required'], 401);
}

$user = current_user();
$role = $user['role'] ?? 'guest';

// Only students and non-staff can create extension requests
if (!in_array($role, ['student', 'non_staff'], true)) {
    json_response(['error' => 'Forbidden'], 403);
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    require_csrf();
    
    $data = read_json_body();
    
    // Validate required fields
    if (empty($data['borrow_log_id'])) {
        json_response(['error' => 'Borrow log ID is required'], 400);
    }
    
    if (empty($data['extension_days'])) {
        json_response(['error' => 'Extension days are required'], 400);
    }
    
    $pdo = DB::conn();
    
    try {
        // Check if borrow log exists and belongs to current user
        $stmt = $pdo->prepare("
            SELECT bl.*, bc.copy_number, b.title as book_title
            FROM borrow_logs bl
            JOIN book_copies bc ON bl.book_copy_id = bc.id
            JOIN books b ON bl.book_id = b.id
            WHERE bl.id = ? AND bl.patron_id = ?
        ");
        $stmt->execute([$data['borrow_log_id'], $user['patron_id']]);
        $borrowLog = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$borrowLog) {
            json_response(['error' => 'Borrow log not found or access denied'], 404);
        }
        
        // Check if book is still borrowed
        if ($borrowLog['status'] !== 'borrowed') {
            json_response(['error' => 'Cannot extend a book that is not currently borrowed'], 400);
        }
        
        // Check for existing pending extension request
        $stmt = $pdo->prepare("
            SELECT id FROM extension_requests 
            WHERE borrow_log_id = ? AND status = 'pending'
        ");
        $stmt->execute([$data['borrow_log_id']]);
        if ($stmt->fetch()) {
            json_response(['error' => 'An extension request is already pending for this book'], 400);
        }
        
        // Calculate new due date
        $currentDueDate = new DateTime($borrowLog['due_date']);
        $extensionDays = (int)$data['extension_days'];
        $newDueDate = $currentDueDate->modify("+{$extensionDays} days")->format('Y-m-d');
        
        // Calculate extension fee
        $feePerDay = (float)settings_get('extension_fee_per_day', 10);
        $extensionFee = $extensionDays * $feePerDay;
        
        // Generate receipt number
        $receiptNumber = 'EXT' . date('YmdHis') . rand(100, 999);
        
        // Create extension request
        $stmt = $pdo->prepare("
            INSERT INTO extension_requests 
            (borrow_log_id, patron_id, book_copy_id, current_due_date, 
             requested_extension_date, extension_days, reason, status, 
             extension_fee, receipt_number, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, NOW())
        ");
        
        $stmt->execute([
            $data['borrow_log_id'],
            $user['patron_id'],
            $borrowLog['book_copy_id'],
            $borrowLog['due_date'],
            $newDueDate,
            $extensionDays,
            $data['reason'] ?? null,
            $extensionFee,
            $receiptNumber
        ]);
        
        $requestId = $pdo->lastInsertId();
        
        // Notify librarians
        notify_user(null, 'librarian', 'extension_request', 'New extension request submitted', [
            'request_id' => $requestId,
            'patron_id' => $user['patron_id'],
            'book_title' => $borrowLog['book_title'],
            'current_due_date' => $borrowLog['due_date'],
            'requested_date' => $newDueDate
        ]);
        
        // Audit log
        audit('create', 'extension_requests', $requestId, [
            'borrow_log_id' => $data['borrow_log_id'],
            'extension_days' => $extensionDays,
            'fee' => $extensionFee
        ]);
        
        json_response([
            'success' => true,
            'request_id' => $requestId,
            'receipt_number' => $receiptNumber,
            'message' => 'Extension request submitted successfully'
        ]);
        
    } catch (Exception $e) {
        error_log("Extension request error: " . $e->getMessage());
        json_response(['error' => 'Failed to submit extension request: ' . $e->getMessage()], 500);
    }
} else {
    json_response(['error' => 'Method not allowed'], 405);
}