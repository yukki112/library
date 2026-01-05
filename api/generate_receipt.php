<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/fpdf/fpdf.php';
require_login();

$pdo = DB::conn();
$borrowId = $_GET['borrow_id'] ?? 0;

// Check if receipt already exists
$sql = "SELECT r.*, bl.*, b.title, b.author, p.name AS patron_name, p.library_id
        FROM receipts r
        JOIN borrow_logs bl ON r.borrow_log_id = bl.id
        JOIN books b ON bl.book_id = b.id
        JOIN patrons p ON bl.patron_id = p.id
        WHERE r.borrow_log_id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$borrowId]);
$receipt = $stmt->fetch(PDO::FETCH_ASSOC);

if ($receipt && !empty($receipt['pdf_path'])) {
    // Receipt already exists, return it
    echo json_encode([
        'success' => true,
        'receipt_pdf' => $receipt['pdf_path']
    ]);
    exit;
}

// Generate new receipt
require_once 'process_return.php'; // Reuse the function
echo json_encode(['success' => false, 'message' => 'Receipt not found. Please return the book first.']);
?>