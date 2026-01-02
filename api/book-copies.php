<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json');

if (!isset($_GET['book_id'])) {
    echo json_encode(['error' => 'Book ID required']);
    exit;
}

$book_id = intval($_GET['book_id']);

try {
    $stmt = $pdo->prepare("
        SELECT id, copy_number, barcode, status, 
               current_section, current_shelf, current_row, current_slot,
               book_condition
        FROM book_copies
        WHERE book_id = ? AND is_active = 1
        ORDER BY copy_number
    ");
    $stmt->execute([$book_id]);
    $copies = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($copies);
    
} catch (Exception $e) {
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}