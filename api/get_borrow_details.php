<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_login();

$pdo = DB::conn();
$borrowId = $_GET['id'] ?? 0;

$sql = "SELECT 
            bl.*,
            b.title AS book_name,
            b.author,
            b.isbn,
            b.cover_image_cache,
            bc.copy_number,
            bc.barcode,
            bc.book_condition,
            p.name AS patron_name,
            p.library_id,
            p.department,
            p.semester,
            c.name AS category_name
        FROM borrow_logs bl
        JOIN books b ON bl.book_id = b.id
        LEFT JOIN book_copies bc ON bl.book_copy_id = bc.id
        JOIN patrons p ON bl.patron_id = p.id
        LEFT JOIN categories c ON b.category_id = c.id
        WHERE bl.id = ?";

$stmt = $pdo->prepare($sql);
$stmt->execute([$borrowId]);
$data = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$data) {
    echo json_encode(['success' => false, 'message' => 'Borrow record not found']);
    exit;
}

// Format the data
$data['cover_image'] = !empty($data['cover_image_cache']) ? 
    '../uploads/book_covers/' . $data['cover_image_cache'] : 
    '../assets/default-book.png';

echo json_encode(['success' => true, 'data' => $data]);
?>