<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';

$pdo = DB::conn();

// Get POST data
$data = json_decode(file_get_contents('php://input'), true);
$reservation_id = isset($_GET['reservation_id']) ? intval($_GET['reservation_id']) : 0;
$due_date = isset($data['due_date']) ? $data['due_date'] : '';

if ($reservation_id <= 0 || empty($due_date)) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid reservation ID or due date']);
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

// Update all borrow logs for this reservation
$sql = "UPDATE borrow_logs 
        SET due_date = ?
        WHERE book_id = ? 
        AND patron_id = ?
        AND DATE(borrowed_at) = DATE(?)
        AND status IN ('borrowed', 'overdue')";

$stmt = $pdo->prepare($sql);
$result = $stmt->execute([
    $due_date,
    $reservation['book_id'],
    $reservation['patron_id'],
    $reservation['reserved_at']
]);

header('Content-Type: application/json');
if ($result) {
    echo json_encode(['success' => true, 'updated' => $stmt->rowCount()]);
} else {
    echo json_encode(['error' => 'Failed to update due dates']);
}