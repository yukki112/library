<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_login();

$pdo = DB::conn();
$borrowId = $_GET['id'] ?? 0;

$sql = "SELECT 
            bl.*,
            b.title as book_name,
            b.author,
            b.isbn,
            b.price as book_price,  -- ADDED: Get book price
            b.cover_image_cache as cover_image,
            b.category_id,
            bc.copy_number,
            bc.barcode,
            bc.book_condition,
            bc.current_section,
            bc.current_shelf,
            bc.current_row,
            bc.current_slot,
            p.name as patron_name,
            p.library_id,
            p.department,
            p.semester,
            c.name as category_name,
            -- Add extension attempts
            bl.extension_attempts,
            bl.last_extension_date,
            bl.lost_status,  -- ADDED: Get lost status
            bl.lost_fee,     -- ADDED: Get lost fee
            CONCAT(
                COALESCE(bc.current_section, 'A'), 
                '-S', COALESCE(bc.current_shelf, '1'),
                '-R', COALESCE(bc.current_row, '1'),
                '-P', COALESCE(bc.current_slot, '1')
            ) as full_location
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
    '../uploads/covers/' . $data['cover_image_cache'] : 
    '../assets/images/default-book.jpg';

// Parse damage types
if (!empty($data['damage_types'])) {
    $data['damage_types_array'] = json_decode($data['damage_types'], true);
    if (!is_array($data['damage_types_array'])) {
        $data['damage_types_array'] = [];
    }
}

echo json_encode(['success' => true, 'data' => $data]);
?>