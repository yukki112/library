<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_login();

$pdo = DB::conn();

$receiptNumber = $_GET['receipt_number'] ?? '';

if (!$receiptNumber) {
    echo json_encode(['success' => false, 'message' => 'Receipt number is required']);
    exit;
}

$sql = "SELECT * FROM receipts WHERE receipt_number = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$receiptNumber]);
$receipt = $stmt->fetch(PDO::FETCH_ASSOC);

if ($receipt) {
    echo json_encode([
        'success' => true,
        'receipt' => $receipt
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Receipt not found'
    ]);
}