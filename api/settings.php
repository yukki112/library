<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/settings.php';

start_app_session();
if (!is_logged_in()) json_response(['error'=>'Unauthorized'],401);
$role = current_user()['role'] ?? 'guest';
if ($role !== 'admin') json_response(['error'=>'Forbidden'],403);

$allowed_keys = ['borrow_period_days','late_fee_per_day','fee_minor','fee_moderate','fee_severe'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $out = [];
    foreach ($allowed_keys as $k) $out[$k] = settings_get($k, null);
    json_response($out);
}

if ($_SERVER['REQUEST_METHOD'] === 'PUT' || $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $body = read_json_body();
    foreach ($allowed_keys as $k) {
        if (array_key_exists($k, $body) && $body[$k] !== null) {
            settings_set($k, (string)$body[$k]);
        }
    }
    json_response(['ok'=>true]);
}

json_response(['error'=>'Method not allowed'],405);
?>

