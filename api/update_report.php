<?php
// update_report.php - API endpoint to update report details
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_login();

if (!is_admin()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$pdo = DB::conn();

try {
    $report_id = $_POST['report_id'] ?? 0;
    $severity = $_POST['severity'] ?? '';
    $description = $_POST['notes'] ?? $_POST['description'] ?? '';
    $fee_charged = floatval($_POST['fee_charged'] ?? 0);
    
    if (!$report_id) {
        throw new Exception('Report ID is required');
    }
    
    // Build update query
    $updates = [];
    $params = [];
    
    if ($severity) {
        $updates[] = "severity = ?";
        $params[] = $severity;
    }
    
    if ($description !== '') {
        $updates[] = "description = ?";
        $params[] = $description;
    }
    
    $updates[] = "fee_charged = ?";
    $params[] = $fee_charged;
    
    $updates[] = "updated_at = NOW()";
    
    if (empty($updates)) {
        throw new Exception('No updates provided');
    }
    
    $params[] = $report_id;
    
    $sql = "UPDATE lost_damaged_reports SET " . implode(', ', $updates) . " WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    // Create audit log
    $auditSql = "INSERT INTO audit_logs (user_id, action, entity, entity_id, details)
                 VALUES (?, 'update', 'lost_damaged_reports', ?, ?)";
    $stmt = $pdo->prepare($auditSql);
    $stmt->execute([
        $_SESSION['user_id'],
        $report_id,
        json_encode([
            'severity' => $severity,
            'fee_charged' => $fee_charged
        ])
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Report updated successfully'
    ]);
    
} catch (Exception $e) {
    error_log("Report update error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}