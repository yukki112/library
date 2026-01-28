<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_login();

$pdo = DB::conn();

$reportId = $_GET['report_id'] ?? 0;

if (!$reportId) {
    echo json_encode(['success' => false, 'message' => 'Report ID is required']);
    exit;
}

// Get report first to get patron info
$reportSql = "SELECT r.*, p.id as patron_id 
              FROM lost_damaged_reports r
              JOIN patrons p ON r.patron_id = p.id
              WHERE r.id = ?";
$reportStmt = $pdo->prepare($reportSql);
$reportStmt->execute([$reportId]);
$report = $reportStmt->fetch(PDO::FETCH_ASSOC);

if (!$report) {
    echo json_encode(['success' => false, 'message' => 'Report not found']);
    exit;
}

// Find receipt for this report
// We'll look for receipts with damage_fee > 0 for this patron around the report date
$receiptSql = "SELECT * FROM receipts 
               WHERE patron_id = ? 
               AND damage_fee > 0 
               AND payment_date >= DATE_SUB(?, INTERVAL 1 DAY)
               AND payment_date <= DATE_ADD(?, INTERVAL 1 DAY)
               ORDER BY payment_date DESC 
               LIMIT 1";

$receiptStmt = $pdo->prepare($receiptSql);
$receiptStmt->execute([
    $report['patron_id'],
    $report['created_at'],
    $report['created_at']
]);

$receipt = $receiptStmt->fetch(PDO::FETCH_ASSOC);

if ($receipt) {
    echo json_encode([
        'success' => true,
        'receipt' => $receipt
    ]);
} else {
    // Try another approach: look for receipts with LDR prefix
    $receiptSql2 = "SELECT * FROM receipts 
                    WHERE receipt_number LIKE 'LDR%'
                    AND patron_id = ?
                    ORDER BY payment_date DESC 
                    LIMIT 1";
    
    $receiptStmt2 = $pdo->prepare($receiptSql2);
    $receiptStmt2->execute([$report['patron_id']]);
    $receipt2 = $receiptStmt2->fetch(PDO::FETCH_ASSOC);
    
    if ($receipt2) {
        echo json_encode([
            'success' => true,
            'receipt' => $receipt2
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'No receipt found for this report'
        ]);
    }
}