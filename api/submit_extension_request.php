<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_login();

$pdo = DB::conn();

try {
    $borrowId = $_POST['borrow_id'] ?? 0;
    $extensionDays = $_POST['extension_days'] ?? 0;
    $reason = $_POST['reason'] ?? '';
    
    if (!$borrowId || !$extensionDays) {
        throw new Exception('Missing required parameters');
    }
    
    // Get borrow details
    $sql = "SELECT bl.*, p.id as patron_id, bc.id as book_copy_id, 
                   bl.due_date as current_due_date
            FROM borrow_logs bl
            JOIN patrons p ON bl.patron_id = p.id
            LEFT JOIN book_copies bc ON bl.book_copy_id = bc.id
            WHERE bl.id = ? AND bl.status IN ('borrowed', 'overdue')";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$borrowId]);
    $borrow = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$borrow) {
        throw new Exception('Borrow record not found or already returned');
    }
    
    // Check if already reached max extensions
    $maxExtensions = $pdo->query("SELECT `value` FROM settings WHERE `key` = 'max_extensions_per_book'")
                        ->fetchColumn();
    $maxExtensions = $maxExtensions ? (int)$maxExtensions : 2;
    
    if ($borrow['extension_attempts'] >= $maxExtensions) {
        throw new Exception('Maximum number of extensions reached for this book');
    }
    
    // Get extension fee per day
    $feePerDay = $pdo->query("SELECT `value` FROM settings WHERE `key` = 'extension_fee_per_day'")
                     ->fetchColumn();
    $feePerDay = $feePerDay ? (float)$feePerDay : 10;
    
    // Calculate new due date and fee
    $currentDue = new DateTime($borrow['due_date']);
    $newDue = clone $currentDue;
    $newDue->modify("+{$extensionDays} days");
    
    $extensionFee = $extensionDays * $feePerDay;
    
    // Insert extension request
    $insertSql = "INSERT INTO extension_requests 
                  (borrow_log_id, patron_id, book_copy_id, current_due_date, 
                   requested_extension_date, extension_days, reason, extension_fee, status)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')";
    
    $stmt = $pdo->prepare($insertSql);
    $stmt->execute([
        $borrowId,
        $borrow['patron_id'],
        $borrow['book_copy_id'],
        $currentDue->format('Y-m-d'),
        $newDue->format('Y-m-d'),
        $extensionDays,
        $reason,
        $extensionFee
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Extension request submitted successfully',
        'extension_id' => $pdo->lastInsertId()
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>