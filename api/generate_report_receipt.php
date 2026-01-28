<?php
// generate_report_receipt.php - API endpoint to regenerate receipt for a report
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_login();

if (!is_admin()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$pdo = DB::conn();

$report_id = $_GET['id'] ?? 0;

if (!$report_id) {
    echo json_encode(['success' => false, 'message' => 'Report ID is required']);
    exit();
}

// Check if receipt already exists
$sql = "SELECT r.*, rc.receipt_number, rc.pdf_path 
        FROM lost_damaged_reports r
        LEFT JOIN receipts rc ON rc.borrow_log_id = (
            SELECT id FROM borrow_logs 
            WHERE book_id = r.book_id 
            AND patron_id = r.patron_id 
            AND status = 'returned'
            ORDER BY returned_at DESC LIMIT 1
        )
        WHERE r.id = ? AND r.status = 'resolved'";
        
$stmt = $pdo->prepare($sql);
$stmt->execute([$report_id]);
$report = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$report) {
    echo json_encode(['success' => false, 'message' => 'Report not found or not resolved']);
    exit();
}

if (empty($report['pdf_path'])) {
    echo json_encode(['success' => false, 'message' => 'No receipt found for this report']);
    exit();
}

// Verify file exists
if (!file_exists($report['pdf_path'])) {
    echo json_encode(['success' => false, 'message' => 'Receipt file not found']);
    exit();
}

echo json_encode([
    'success' => true,
    'receipt_pdf' => $report['pdf_path'],
    'receipt_number' => $report['receipt_number']
]);