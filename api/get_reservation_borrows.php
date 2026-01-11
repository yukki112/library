<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';

$pdo = DB::conn();

$reservation_id = isset($_GET['reservation_id']) ? intval($_GET['reservation_id']) : 0;

if ($reservation_id <= 0) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid reservation ID']);
    exit;
}

// Get reservation details
$sql = "SELECT r.book_id, r.patron_id, r.reserved_at 
        FROM reservations r 
        WHERE r.id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$reservation_id]);
$reservation = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$reservation) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Reservation not found']);
    exit;
}

// Get all borrow logs for this reservation (matching book, patron, and reservation date)
$sql = "SELECT bl.*, bc.copy_number
        FROM borrow_logs bl
        LEFT JOIN book_copies bc ON bl.book_copy_id = bc.id
        WHERE bl.book_id = ? 
        AND bl.patron_id = ?
        AND DATE(bl.borrowed_at) = DATE(?)
        ORDER BY bl.borrowed_at ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute([
    $reservation['book_id'],
    $reservation['patron_id'],
    $reservation['reserved_at']
]);

$borrows = $stmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/json');
echo json_encode([
    'borrows' => $borrows,
    'count' => count($borrows)
]);