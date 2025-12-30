<?php
require_once __DIR__ . '/db.php';

function settings_get(string $key, $default = null) {
    try {
        $stmt = DB::conn()->prepare('SELECT value FROM settings WHERE `key` = :k');
        $stmt->execute([':k'=>$key]);
        $v = $stmt->fetchColumn();
        return ($v === false) ? $default : $v;
    } catch (Throwable $e) { return $default; }
}

function settings_set(string $key, string $value): void {
    $pdo = DB::conn();
    $stmt = $pdo->prepare('INSERT INTO settings (`key`, `value`) VALUES (:k,:v) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)');
    $stmt->execute([':k'=>$key, ':v'=>$value]);
}
?>

