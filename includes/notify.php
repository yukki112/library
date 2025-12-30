<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

function notify_user(?int $user_id, ?string $role_target, string $type, string $message, array $meta = []): void {
    try {
        $pdo = DB::conn();
        $stmt = $pdo->prepare('INSERT INTO notifications (user_id, role_target, type, message, meta) VALUES (:uid,:role,:type,:message,:meta)');
        $stmt->execute([
            ':uid' => $user_id,
            ':role' => $role_target,
            ':type' => $type,
            ':message' => $message,
            ':meta' => json_encode($meta, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)
        ]);
    } catch (Throwable $e) {
        // fail-safe
    }
}
?>

