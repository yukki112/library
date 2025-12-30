<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';

start_app_session();
if (!is_logged_in()) json_response(['error'=>'Unauthorized'],401);
$role = current_user()['role'];
if (!in_array($role, ['admin','librarian','assistant'], true)) json_response(['error'=>'Forbidden'],403);

$resource = strtolower($_GET['resource'] ?? '');
$format = strtolower($_GET['format'] ?? 'csv');
if (!$resource) json_response(['error'=>'Resource required'],400);

$allowed = ['users','patrons','books','ebooks','borrow_logs','reservations','lost_damaged_reports','clearances','audit_logs'];
if (!in_array($resource, $allowed, true)) json_response(['error'=>'Unknown resource'],404);

$pdo = DB::conn();
$stmt = $pdo->query('SELECT * FROM ' . $resource);
$rows = $stmt->fetchAll();

if ($format === 'json') {
    header('Content-Type: application/json');
    echo json_encode($rows);
    exit;
}

// default csv
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $resource . '.csv"');
$out = fopen('php://output', 'w');
if (!$rows) { fclose($out); exit; }
fputcsv($out, array_keys($rows[0]));
foreach ($rows as $r) fputcsv($out, $r);
fclose($out);
exit;
?>

