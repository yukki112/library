<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');

try {
    $pdo = DB::conn();
    
    $bookId = isset($_GET['book_id']) ? (int)$_GET['book_id'] : 0;
    $patronId = isset($_GET['patron_id']) ? (int)$_GET['patron_id'] : 0;
    $excludeReservation = isset($_GET['exclude_reservation']) ? (int)$_GET['exclude_reservation'] : 0;
    
    if ($bookId <= 0) {
        throw new Exception('Invalid book ID');
    }
    
    // First, check if there are any pending reservations for specific copies
    // We need to exclude copies that are already reserved by other pending reservations
    $excludedCopies = [];
    if ($excludeReservation > 0) {
        $excludeSql = "SELECT book_copy_id FROM reservations 
                       WHERE book_id = :book_id 
                       AND status = 'pending' 
                       AND book_copy_id IS NOT NULL
                       AND id != :exclude_id";
        $excludeStmt = $pdo->prepare($excludeSql);
        $excludeStmt->execute([
            ':book_id' => $bookId,
            ':exclude_id' => $excludeReservation
        ]);
        $excludedCopies = $excludeStmt->fetchAll(PDO::FETCH_COLUMN, 0);
    }
    
    // Build query to find available copies
    $sql = "SELECT bc.id as available_copy_id, bc.copy_number, bc.barcode
            FROM book_copies bc
            WHERE bc.book_id = :book_id
              AND bc.status = 'available'
              AND bc.is_active = 1";
    
    // Exclude copies that are already reserved by other pending reservations
    if (!empty($excludedCopies)) {
        $excludedIds = implode(',', array_map('intval', $excludedCopies));
        $sql .= " AND bc.id NOT IN ($excludedIds)";
    }
    
    // Also exclude copies that are already borrowed by this patron
    $sql .= " AND NOT EXISTS (
                SELECT 1 FROM borrow_logs bl 
                WHERE bl.book_copy_id = bc.id 
                  AND bl.patron_id = :patron_id 
                  AND bl.status IN ('borrowed', 'overdue')
              )";
    
    $sql .= " ORDER BY bc.id ASC LIMIT 1";
    
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
            'copy_number' => $copy['copy_number'],
            'barcode' => $copy['barcode']
        ]);
    } else {
        // Try to find any available copy, even if patron already has one
        $sql2 = "SELECT bc.id as available_copy_id, bc.copy_number, bc.barcode
                 FROM book_copies bc
                 WHERE bc.book_id = :book_id
                   AND bc.status = 'available'
                   AND bc.is_active = 1";
        
        if (!empty($excludedCopies)) {
            $excludedIds = implode(',', array_map('intval', $excludedCopies));
            $sql2 .= " AND bc.id NOT IN ($excludedIds)";
        }
        
        $sql2 .= " ORDER BY bc.id ASC LIMIT 1";
        
        $stmt2 = $pdo->prepare($sql2);
        $stmt2->execute([':book_id' => $bookId]);
        $copy2 = $stmt2->fetch(PDO::FETCH_ASSOC);
        
        if ($copy2) {
            echo json_encode([
                'success' => true,
                'available_copy_id' => $copy2['available_copy_id'],
                'copy_number' => $copy2['copy_number'],
                'barcode' => $copy2['barcode'],
                'warning' => 'Patron might already have another copy of this book'
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'No available copies found for this book'
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