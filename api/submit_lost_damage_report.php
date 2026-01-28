<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_login();

$pdo = DB::conn();
$current_user = current_user();

// Check if user has a patron record
$patronStmt = $pdo->prepare("SELECT id FROM patrons WHERE library_id = ?");
$patronStmt->execute([$current_user['username']]);
$patron = $patronStmt->fetch(PDO::FETCH_ASSOC);

if (!$patron) {
    echo json_encode([
        'success' => false,
        'message' => 'Patron record not found. Please contact library staff.'
    ]);
    exit;
}

$bookId = $_POST['book_id'] ?? 0;
$bookCopyId = $_POST['book_copy_id'] ?? null;
$reportType = $_POST['report_type'] ?? '';
$description = $_POST['description'] ?? '';
$severity = $_POST['severity'] ?? 'minor';
$damageTypesJson = $_POST['damage_types'] ?? '[]';

// Validate
if (!$bookId) {
    echo json_encode(['success' => false, 'message' => 'Book is required']);
    exit;
}

if (!in_array($reportType, ['lost', 'damaged'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid report type']);
    exit;
}

try {
    $pdo->beginTransaction();
    
    // Calculate damage fee for damaged reports
    $damageFeeTotal = 0;
    if ($reportType === 'damaged' && $damageTypesJson !== '[]') {
        $damageTypeIds = json_decode($damageTypesJson, true);
        if (is_array($damageTypeIds) && !empty($damageTypeIds)) {
            $placeholders = implode(',', array_fill(0, count($damageTypeIds), '?'));
            $damageTypeSql = "SELECT SUM(fee_amount) as total_fee FROM damage_types WHERE id IN ($placeholders)";
            $damageTypeStmt = $pdo->prepare($damageTypeSql);
            $damageTypeStmt->execute($damageTypeIds);
            $damageFeeData = $damageTypeStmt->fetch(PDO::FETCH_ASSOC);
            $damageFeeTotal = floatval($damageFeeData['total_fee'] ?? 0);
        }
    }
    
    // Insert report with damage_types
    $sql = "INSERT INTO lost_damaged_reports 
            (book_copy_id, book_id, patron_id, report_date, report_type, 
             severity, damage_types, description, fee_charged, status, created_at)
            VALUES (?, ?, ?, CURDATE(), ?, ?, ?, ?, ?, 'pending', NOW())";
    
    // Set initial fee_charged based on report type
    $initialFee = ($reportType === 'lost') ? 0 : $damageFeeTotal;
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $bookCopyId,
        $bookId,
        $patron['id'],
        $reportType,
        $severity,
        $damageTypesJson,
        $description,
        $initialFee
    ]);
    
    $reportId = $pdo->lastInsertId();
    
    // Log the action
    $auditSql = "INSERT INTO audit_logs (user_id, action, entity, entity_id, details) 
                 VALUES (?, 'create', 'lost_damaged_reports', ?, ?)";
    $auditStmt = $pdo->prepare($auditSql);
    $auditStmt->execute([
        $current_user['id'],
        $reportId,
        json_encode([
            'report_type' => $reportType,
            'severity' => $severity,
            'book_id' => $bookId,
            'book_copy_id' => $bookCopyId,
            'damage_types' => json_decode($damageTypesJson, true),
            'damage_fee_total' => $damageFeeTotal
        ])
    ]);
    
    // Create notification for admins
    $notificationSql = "INSERT INTO notifications 
                        (role_target, type, message, meta, created_at)
                        VALUES ('admin', 'damage_report', 
                                'New damage/lost report submitted', 
                                ?, NOW())";
    
    $notificationMeta = json_encode([
        'report_id' => $reportId,
        'patron_id' => $patron['id'],
        'book_id' => $bookId,
        'report_type' => $reportType,
        'severity' => $severity,
        'damage_fee' => $damageFeeTotal
    ]);
    
    $notifStmt = $pdo->prepare($notificationSql);
    $notifStmt->execute([$notificationMeta]);
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Report submitted successfully',
        'report_id' => $reportId,
        'damage_fee_total' => $damageFeeTotal
    ]);
    
} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Report submission error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error submitting report: ' . $e->getMessage()
    ]);
}