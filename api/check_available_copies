<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';

$pdo = DB::conn();

$book_id = isset($_GET['book_id']) ? intval($_GET['book_id']) : 0;

if ($book_id <= 0) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid book ID']);
    exit;
}

// Count available copies
$sql = "SELECT COUNT(*) as available_copies 
        FROM book_copies 
        WHERE book_id = ? 
        AND status = 'available' 
        AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')";

$stmt = $pdo->prepare($sql);
$stmt->execute([$book_id]);
$result = $stmt->fetch(PDO::FETCH_ASSOC);

header('Content-Type: application/json');
echo json_encode([
    'available_copies' => intval($result['available_copies'] ?? 0),
    'book_id' => $book_id
]);