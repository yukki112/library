<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json');

if (!isset($_GET['id'])) {
    echo json_encode(['error' => 'Book ID required']);
    exit;
}

$book_id = intval($_GET['id']);

try {
    // Get book details
    $stmt = $pdo->prepare("
        SELECT b.*, c.name as category, c.default_section
        FROM books b
        LEFT JOIN categories c ON b.category_id = c.id
        WHERE b.id = ? AND b.is_active = 1
    ");
    $stmt->execute([$book_id]);
    $book = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$book) {
        echo json_encode(['error' => 'Book not found']);
        exit;
    }
    
    // Get available copies count
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as available_count
        FROM book_copies
        WHERE book_id = ? AND status = 'available' AND is_active = 1
    ");
    $stmt->execute([$book_id]);
    $available = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $book['available_copies'] = $available['available_count'] ?? 0;
    $book['total_copies'] = $book['total_copies_cache'] ?? 0;
    
    echo json_encode($book);
    
} catch (Exception $e) {
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}