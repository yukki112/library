<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();
$pdo = DB::conn();

header('Content-Type: application/json');

$borrow_id = $_GET['borrow_id'] ?? null;

if (!$borrow_id) {
    echo json_encode(['success' => false, 'message' => 'No borrow ID provided']);
    exit;
}

// Get borrow details to find the book copy ID
$borrowStmt = $pdo->prepare("SELECT book_copy_id FROM borrow_logs WHERE id = ?");
$borrowStmt->execute([$borrow_id]);
$borrow = $borrowStmt->fetch(PDO::FETCH_ASSOC);

if (!$borrow || !$borrow['book_copy_id']) {
    echo json_encode(['success' => false, 'message' => 'Borrow not found or no copy ID']);
    exit;
}

// Get damage reports for this book copy
$reportStmt = $pdo->prepare("
    SELECT * FROM lost_damaged_reports 
    WHERE book_copy_id = ? 
    AND report_type = 'damaged' 
    AND status = 'pending'
    ORDER BY report_date DESC
");
$reportStmt->execute([$borrow['book_copy_id']]);
$reports = $reportStmt->fetchAll(PDO::FETCH_ASSOC);

// Also get existing damage types from borrow_logs
$damageStmt = $pdo->prepare("SELECT damage_types FROM borrow_logs WHERE id = ?");
$damageStmt->execute([$borrow_id]);
$damageData = $damageStmt->fetch(PDO::FETCH_ASSOC);

$damageTypes = [];
if (!empty($damageData['damage_types'])) {
    $types = json_decode($damageData['damage_types'], true);
    if (is_array($types)) {
        foreach ($types as $type) {
            if (is_array($type) && isset($type['name'])) {
                $damageTypes[] = $type['name'];
            } elseif (is_string($type)) {
                $damageTypes[] = $type;
            }
        }
    }
}

echo json_encode([
    'success' => true,
    'reports' => $reports,
    'damageTypes' => $damageTypes
]);