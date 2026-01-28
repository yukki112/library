<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();

header('Content-Type: application/json');

if (!isset($_GET['id'])) {
    echo json_encode(['eligible' => false, 'message' => 'No borrow ID provided']);
    exit;
}

$borrowId = (int)$_GET['id'];
$pdo = DB::conn();

// Get borrow details
$stmt = $pdo->prepare("
    SELECT bl.*, b.title, p.name as patron_name, p.library_id,
           (SELECT COUNT(*) FROM reservations r WHERE r.book_id = bl.book_id AND r.status IN ('pending', 'approved')) as reservation_count,
           (SELECT COUNT(*) FROM borrow_logs WHERE patron_id = bl.patron_id AND status = 'overdue' AND id != bl.id) as other_overdue_count
    FROM borrow_logs bl
    JOIN books b ON bl.book_id = b.id
    JOIN patrons p ON bl.patron_id = p.id
    WHERE bl.id = ?
");

$stmt->execute([$borrowId]);
$borrow = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$borrow) {
    echo json_encode(['eligible' => false, 'message' => 'Borrow record not found']);
    exit;
}

// Check if overdue
if ($borrow['status'] === 'overdue') {
    echo json_encode(['eligible' => false, 'message' => 'Cannot extend overdue books']);
    exit;
}

// Check if book is reserved
if ($borrow['reservation_count'] > 0) {
    echo json_encode(['eligible' => false, 'message' => 'Book is reserved by another student']);
    exit;
}

// Check if patron has other overdue books
if ($borrow['other_overdue_count'] > 0) {
    echo json_encode(['eligible' => false, 'message' => 'Patron has other overdue books']);
    exit;
}

// Check max extensions
$maxExtensions = 2; // Default
$settingsStmt = $pdo->query("SELECT value FROM settings WHERE `key` = 'max_extensions_per_book'");
$settings = $settingsStmt->fetch(PDO::FETCH_ASSOC);
if ($settings) {
    $maxExtensions = (int)$settings['value'];
}

if ($borrow['extension_attempts'] >= $maxExtensions) {
    echo json_encode(['eligible' => false, 'message' => 'Maximum extensions reached']);
    exit;
}

echo json_encode([
    'eligible' => true,
    'message' => 'Eligible for extension',
    'data' => [
        'current_extensions' => $borrow['extension_attempts'],
        'max_extensions' => $maxExtensions,
        'due_date' => $borrow['due_date']
    ]
]);
?>