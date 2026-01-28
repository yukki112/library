<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_login();

$pdo = DB::conn();
$extensionId = $_GET['id'] ?? 0;

$sql = "SELECT 
            er.*, 
            p.name as patron_name, 
            p.library_id, 
            b.title as book_title, 
            b.author, 
            bc.copy_number, 
            bc.barcode,
            bl.due_date as original_due_date,
            u.name as approved_by_name
        FROM extension_requests er
        JOIN patrons p ON er.patron_id = p.id
        JOIN book_copies bc ON er.book_copy_id = bc.id
        JOIN books b ON bc.book_id = b.id
        JOIN borrow_logs bl ON er.borrow_log_id = bl.id
        LEFT JOIN users u ON er.approved_by = u.id
        WHERE er.id = ?";

$stmt = $pdo->prepare($sql);
$stmt->execute([$extensionId]);
$data = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$data) {
    echo json_encode(['success' => false, 'message' => 'Extension request not found']);
    exit;
}

echo json_encode(['success' => true, 'data' => $data]);
?>