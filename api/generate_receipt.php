<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_login();

$pdo = DB::conn();
$borrowId = $_GET['borrow_id'] ?? 0;

// Check if receipt already exists
$sql = "SELECT r.*, bl.*, b.title, b.author, p.name AS patron_name, p.library_id
        FROM receipts r
        JOIN borrow_logs bl ON r.borrow_log_id = bl.id
        JOIN books b ON bl.book_id = b.id
        JOIN patrons p ON bl.patron_id = p.id
        WHERE r.borrow_log_id = ? AND r.status = 'paid'";
$stmt = $pdo->prepare($sql);
$stmt->execute([$borrowId]);
$receipt = $stmt->fetch(PDO::FETCH_ASSOC);

if ($receipt && !empty($receipt['pdf_path'])) {
    // Receipt already exists, return it
    echo json_encode([
        'success' => true,
        'receipt_pdf' => $receipt['pdf_path'],
        'receipt_number' => $receipt['receipt_number']
    ]);
    exit;
}

// Check if book is returned
$sql = "SELECT status FROM borrow_logs WHERE id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$borrowId]);
$borrow = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$borrow || $borrow['status'] !== 'returned') {
    echo json_encode(['success' => false, 'message' => 'Book must be returned first']);
    exit;
}

// Generate new receipt using existing data
require_once 'process_return.php';
echo json_encode(['success' => false, 'message' => 'Receipt generation failed']);
?>