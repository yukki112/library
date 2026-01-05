<?php
// get_available_copy.php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');

try {
    $pdo = DB::conn();
    
    $book_id = $_GET['book_id'] ?? null;
    
    if (!$book_id) {
        throw new Exception('Missing book_id parameter');
    }
    
    // Find first available copy
    $stmt = $pdo->prepare("
        SELECT id, copy_number, barcode 
        FROM book_copies 
        WHERE book_id = ? 
          AND status = 'available' 
          AND is_active = 1
        ORDER BY id ASC 
        LIMIT 1
    ");
    $stmt->execute([$book_id]);
    $copy = $stmt->fetch();
    
    if ($copy) {
        echo json_encode([
            'available_copy_id' => $copy['id'],
            'copy_number' => $copy['copy_number'],
            'barcode' => $copy['barcode']
        ]);
    } else {
        echo json_encode([
            'available_copy_id' => null,
            'message' => 'No available copies found'
        ]);
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}