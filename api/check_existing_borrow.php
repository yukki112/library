<?php
// check_existing_borrow.php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');

try {
    $pdo = DB::conn();
    
    $book_copy_id = $_GET['book_copy_id'] ?? null;
    $patron_id = $_GET['patron_id'] ?? null;
    
    if (!$book_copy_id || !$patron_id) {
        throw new Exception('Missing parameters');
    }
    
    // Check for existing active borrow
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count 
        FROM borrow_logs 
        WHERE book_copy_id = ? 
          AND patron_id = ? 
          AND status IN ('borrowed', 'overdue')
    ");
    $stmt->execute([$book_copy_id, $patron_id]);
    $result = $stmt->fetch();
    
    echo json_encode([
        'exists' => $result['count'] > 0,
        'count' => $result['count']
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}