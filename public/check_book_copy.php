<?php
require_once __DIR__ . '/../includes/db.php';
$pdo = DB::conn();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $copy_number = $_POST['copy_number'] ?? '';
    
    if (empty($copy_number)) {
        echo json_encode(['success' => false, 'message' => 'Copy number is required']);
        exit;
    }
    
    try {
        // Get book copy info with cover image
        $stmt = $pdo->prepare("
            SELECT bc.*, b.title, b.author, b.isbn, b.cover_image, b.cover_image_cache, 
                   b.category_id, b.description, b.publisher, b.year_published, b.category
            FROM book_copies bc
            JOIN books b ON bc.book_id = b.id
            WHERE bc.copy_number = ? AND bc.is_active = 1
        ");
        $stmt->execute([$copy_number]);
        $book_copy = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$book_copy) {
            // Try alternative search with more flexibility
            $stmt = $pdo->prepare("
                SELECT bc.*, b.title, b.author, b.isbn, b.cover_image, b.cover_image_cache, 
                       b.category_id, b.description, b.publisher, b.year_published, b.category
                FROM book_copies bc
                JOIN books b ON bc.book_id = b.id
                WHERE bc.copy_number LIKE ? AND bc.is_active = 1
                LIMIT 1
            ");
            $stmt->execute(["%$copy_number%"]);
            $book_copy = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$book_copy) {
                echo json_encode(['success' => false, 'message' => 'Book copy not found']);
                exit;
            }
        }
        
        echo json_encode([
            'success' => true,
            'book' => $book_copy
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
?>