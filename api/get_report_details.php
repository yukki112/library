<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_login();

$pdo = DB::conn();

$reportId = $_GET['id'] ?? 0;

$sql = "SELECT 
            r.*,
            b.title AS book_title,
            b.author,
            b.isbn,
            b.price AS book_price,
            b.cover_image_cache,
            b.category,
            p.name AS patron_name,
            p.library_id,
            p.email AS patron_email,
            p.phone AS patron_phone,
            bc.copy_number,
            bc.barcode,
            bc.current_section,
            bc.current_shelf,
            bc.current_row,
            bc.current_slot,
            bc.book_condition as copy_condition,
            bc.status as copy_status,
            bl.id as borrow_log_id,
            bl.due_date as borrow_due_date,
            bl.borrowed_at,
            bl.status as borrow_status
        FROM lost_damaged_reports r
        JOIN books b ON r.book_id = b.id
        JOIN patrons p ON r.patron_id = p.id
        LEFT JOIN book_copies bc ON r.book_copy_id = bc.id
        LEFT JOIN borrow_logs bl ON r.book_copy_id = bl.book_copy_id 
            AND bl.status IN ('borrowed', 'overdue')
        WHERE r.id = ?";

$stmt = $pdo->prepare($sql);
$stmt->execute([$reportId]);
$report = $stmt->fetch(PDO::FETCH_ASSOC);

if ($report) {
    echo json_encode([
        'success' => true,
        'data' => $report
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Report not found'
    ]);
}