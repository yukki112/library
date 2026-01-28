<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_login();
require_role(['admin', 'librarian', 'assistant']);

$pdo = DB::conn();
$current_user = current_user();

header('Content-Type: application/json');

try {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data) {
        throw new Exception('No data received');
    }
    
    $pdo->beginTransaction();
    
    $reportId = $data['report_id'] ?? 0;
    $adminNotes = $data['admin_notes'] ?? '';
    $action = $data['action'] ?? 'process'; // process, reject
    
    // Get report details
    $sql = "SELECT r.*, b.price AS book_price, b.title, p.id as patron_id, 
                   p.name as patron_name, p.library_id, bc.id as copy_id,
                   bc.copy_number, bc.status as copy_status
            FROM lost_damaged_reports r
            JOIN books b ON r.book_id = b.id
            JOIN patrons p ON r.patron_id = p.id
            LEFT JOIN book_copies bc ON r.book_copy_id = bc.id
            WHERE r.id = ? AND r.status = 'pending'";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$reportId]);
    $report = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$report) {
        throw new Exception('Report not found or already processed');
    }
    
    $isLost = $report['report_type'] === 'lost';
    $bookPrice = floatval($report['book_price']);
    
    if ($action === 'reject') {
        // Just mark as resolved without fees
        $updateSql = "UPDATE lost_damaged_reports 
                      SET status = 'resolved', 
                          admin_notes = ?,
                          updated_at = NOW()
                      WHERE id = ?";
        $updateStmt = $pdo->prepare($updateSql);
        $updateStmt->execute([$adminNotes ?: 'Report rejected', $reportId]);
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Report rejected successfully'
        ]);
        exit;
    }
    
    // Process the report (with fees)
    $damageFee = 0;
    $damageTypeDetails = [];
    
    if (!$isLost && !empty($report['damage_types'])) {
        $damageTypeIds = json_decode($report['damage_types'], true);
        
        if (is_array($damageTypeIds) && !empty($damageTypeIds)) {
            $placeholders = implode(',', array_fill(0, count($damageTypeIds), '?'));
            
            $damageTypeSql = "SELECT id, name, fee_amount FROM damage_types WHERE id IN ($placeholders)";
            $damageTypeStmt = $pdo->prepare($damageTypeSql);
            $damageTypeStmt->execute($damageTypeIds);
            $damageTypesData = $damageTypeStmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($damageTypesData as $damageType) {
                $damageFee += floatval($damageType['fee_amount']);
                $damageTypeDetails[] = [
                    'id' => $damageType['id'],
                    'name' => $damageType['name'],
                    'fee_amount' => $damageType['fee_amount']
                ];
            }
        }
    }
    
    if (!$isLost && $damageFee == 0) {
        $damageFee = 500; // Default damage fee
    }
    
    $totalFee = $isLost ? ($bookPrice * 1.5) : $damageFee;
    
    // Update report
    $updateSql = "UPDATE lost_damaged_reports 
                  SET fee_charged = ?, 
                      damage_types_fees = ?, 
                      status = 'resolved', 
                      admin_notes = ?,
                      updated_at = NOW()
                  WHERE id = ?";
    
    $damageTypesFeesJson = !empty($damageTypeDetails) ? json_encode($damageTypeDetails) : null;
    
    $updateStmt = $pdo->prepare($updateSql);
    $updateStmt->execute([
        $totalFee, 
        $damageTypesFeesJson, 
        $adminNotes ?: "Processed report",
        $reportId
    ]);
    
    // Update book copy if it exists
    if ($report['copy_id']) {
        $copyStatus = $isLost ? 'lost' : 'damaged';
        $copyCondition = $isLost ? 'lost' : 'damaged';
        
        $updateCopySql = "UPDATE book_copies 
                          SET status = ?, 
                              book_condition = ?, 
                              updated_at = NOW()
                          WHERE id = ?";
        $copyStmt = $pdo->prepare($updateCopySql);
        $copyStmt->execute([$copyStatus, $copyCondition, $report['copy_id']]);
        
        // Log transaction
        $transactionSql = "INSERT INTO copy_transactions 
                          (book_copy_id, transaction_type, from_status, to_status, notes, created_at)
                          VALUES (?, ?, ?, ?, ?, NOW())";
        $transStmt = $pdo->prepare($transactionSql);
        $transStmt->execute([
            $report['copy_id'],
            $isLost ? 'lost' : 'damaged',
            $report['copy_status'],
            $copyStatus,
            $isLost ? 'Book marked as lost from report' : 'Book marked as damaged from report'
        ]);
    }
    
    // Generate receipt
    $receiptNumber = 'LDR' . date('Ymd') . str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);
    
    $receiptSql = "INSERT INTO receipts 
                    (receipt_number, patron_id, total_amount, 
                     damage_fee, late_fee, payment_date, status, notes, created_at) 
                   VALUES (?, ?, ?, ?, 0, NOW(), 'paid', ?, NOW())";
    $receiptStmt = $pdo->prepare($receiptSql);
    $receiptStmt->execute([
        $receiptNumber,
        $report['patron_id'],
        $totalFee,
        $totalFee,
        $adminNotes ?: "Processed lost/damaged report #{$reportId}"
    ]);
    
    $receiptId = $pdo->lastInsertId();
    
    // Generate PDF receipt (similar to previous function)
    $pdfPath = generateReceiptPDF($receiptNumber, $report, [
        'report_type' => $report['report_type'],
        'fee_charged' => $totalFee,
        'book_price' => $bookPrice,
        'damage_fee' => $damageFee,
        'is_lost' => $isLost,
        'admin_notes' => $adminNotes,
        'damage_type_details' => $damageTypeDetails
    ]);
    
    // Update receipt with PDF path
    $updateReceipt = "UPDATE receipts SET pdf_path = ?, updated_at = NOW() WHERE id = ?";
    $updReceiptStmt = $pdo->prepare($updateReceipt);
    $updReceiptStmt->execute([$pdfPath, $receiptId]);
    
    // Create notification for patron
    $patronNotifSql = "INSERT INTO notifications 
                       (user_id, type, message, meta, created_at)
                       VALUES (?, 'report_resolved', 
                               'Your lost/damaged book report has been processed', 
                               ?, NOW())";
    
    $userStmt = $pdo->prepare("SELECT id FROM users WHERE patron_id = ?");
    $userStmt->execute([$report['patron_id']]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        $notifMeta = json_encode([
            'report_id' => $reportId,
            'receipt_number' => $receiptNumber,
            'fee_charged' => $totalFee,
            'report_type' => $report['report_type'],
            'pdf_path' => $pdfPath
        ]);
        
        $patronNotifStmt = $pdo->prepare($patronNotifSql);
        $patronNotifStmt->execute([$user['id'], $notifMeta]);
    }
    
    // Log the action
    $auditSql = "INSERT INTO audit_logs (user_id, action, entity, entity_id, details, created_at) 
                 VALUES (?, 'update', 'lost_damaged_reports', ?, ?, NOW())";
    $auditStmt = $pdo->prepare($auditSql);
    $auditStmt->execute([
        $current_user['id'],
        $reportId,
        json_encode([
            'action' => 'processed',
            'fee_charged' => $totalFee,
            'report_type' => $report['report_type'],
            'receipt_number' => $receiptNumber
        ])
    ]);
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Report processed successfully',
        'receipt_number' => $receiptNumber,
        'receipt_pdf' => $pdfPath,
        'fee_charged' => $totalFee
    ]);
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Damage report processing error: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => 'Error processing report: ' . $e->getMessage()
    ]);
}

function generateReceiptPDF($receiptNumber, $report, $data) {
    // Similar PDF generation code as before
    // ... (copy from previous function)
}
?>