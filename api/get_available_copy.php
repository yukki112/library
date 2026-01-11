<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');

try {
    $pdo = DB::conn();
    
    $bookId = isset($_GET['book_id']) ? (int)$_GET['book_id'] : 0;
    $patronId = isset($_GET['patron_id']) ? (int)$_GET['patron_id'] : 0;
    
    if ($bookId <= 0) {
        throw new Exception('Invalid book ID');
    }
    
    // Find an available copy that is NOT currently borrowed by this patron
    $sql = "SELECT bc.id as available_copy_id, bc.copy_number
            FROM book_copies bc
            WHERE bc.book_id = :book_id
              AND bc.status = 'available'
              AND bc.is_active = 1
              AND NOT EXISTS (
                  SELECT 1 FROM borrow_logs bl 
                  WHERE bl.book_copy_id = bc.id 
                    AND bl.patron_id = :patron_id 
                    AND bl.status IN ('borrowed', 'overdue')
              )
            ORDER BY bc.id ASC
            LIMIT 1";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':book_id' => $bookId,
        ':patron_id' => $patronId
    ]);
    
    $copy = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($copy) {
        echo json_encode([
            'success' => true,
            'available_copy_id' => $copy['available_copy_id'],
            'copy_number' => $copy['copy_number']
        ]);
    } else {
        // Try to find any available copy
        $sql2 = "SELECT bc.id as available_copy_id, bc.copy_number
                 FROM book_copies bc
                 WHERE bc.book_id = :book_id
                   AND bc.status = 'available'
                   AND bc.is_active = 1
                 ORDER BY bc.id ASC
                 LIMIT 1";
        
        $stmt2 = $pdo->prepare($sql2);
        $stmt2->execute([':book_id' => $bookId]);
        $copy2 = $stmt2->fetch(PDO::FETCH_ASSOC);
        
        if ($copy2) {
            echo json_encode([
                'success' => true,
                'available_copy_id' => $copy2['available_copy_id'],
                'copy_number' => $copy2['copy_number'],
                'warning' => 'Copy found but patron might already have another copy borrowed'
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'No available copies found'
            ]);
        }
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>