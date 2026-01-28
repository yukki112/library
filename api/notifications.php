<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';

start_app_session();

if (!is_logged_in()) json_response(['error' => 'Unauthorized'], 401);
$u = current_user();
$role = $u['role'];
$pdo = DB::conn();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $sinceId = isset($_GET['since_id']) ? (int)$_GET['since_id'] : 0;
    $all = isset($_GET['all']) ? (int)$_GET['all'] : 0;
    $type = isset($_GET['type']) ? trim((string)$_GET['type']) : '';
    $from = isset($_GET['from']) ? trim((string)$_GET['from']) : '';
    $to = isset($_GET['to']) ? trim((string)$_GET['to']) : '';
    $is_read = isset($_GET['is_read']) ? (int)$_GET['is_read'] : null;

    $cond = [];
    $params = [];
    if ($type !== '') { $cond[] = 'type = :type'; $params[':type'] = $type; }
    if ($from !== '') { $cond[] = 'created_at >= :from'; $params[':from'] = $from; }
    if ($to !== '') { $cond[] = 'created_at <= :to'; $params[':to'] = $to; }
    if ($is_read !== null) { $cond[] = 'is_read = :is_read'; $params[':is_read'] = $is_read; }
    $where = $cond ? (' AND ' . implode(' AND ', $cond)) : '';

    if (in_array($role, ['admin','librarian','assistant'], true)) {
        if ($all) {
            $stmt = $pdo->prepare("SELECT * FROM notifications WHERE (role_target IN ('admin','librarian','assistant') OR role_target IS NULL)".$where." ORDER BY id DESC LIMIT 200");
            $stmt->execute($params);
            json_response($stmt->fetchAll());
        } else {
            $sql = "SELECT * FROM notifications WHERE (role_target IN ('admin','librarian','assistant') OR role_target IS NULL) AND id > :sid".$where." ORDER BY id ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge([':sid'=>$sinceId], $params));
            json_response($stmt->fetchAll());
        }
    } else {
        if ($all) {
            $stmt = $pdo->prepare('SELECT * FROM notifications WHERE (user_id = :uid OR role_target = :role)' . $where . ' ORDER BY id DESC LIMIT 200');
            $stmt->execute(array_merge([':uid'=>$u['id'], ':role'=>$role], $params));
            json_response($stmt->fetchAll());
        } else {
            $stmt = $pdo->prepare('SELECT * FROM notifications WHERE (user_id = :uid OR role_target = :role) AND id > :sid' . $where . ' ORDER BY id ASC');
            $stmt->execute(array_merge([':uid'=>$u['id'], ':role'=>$role, ':sid'=>$sinceId], $params));
            json_response($stmt->fetchAll());
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    if (isset($_GET['action']) && $_GET['action'] === 'mark_all') {
        // mark all visible to current user as read
        if (in_array($role, ['admin','librarian','assistant'], true)) {
            $pdo->query("UPDATE notifications SET is_read = 1 WHERE (role_target IN ('admin','librarian','assistant') OR role_target IS NULL)");
        } else {
            $stmt = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE (user_id = :uid OR role_target = :role)');
            $stmt->execute([':uid'=>$u['id'], ':role'=>$role]);
        }
        json_response(['ok'=>true]);
    } else {
        $body = read_json_body();
        $id = (int)($body['id'] ?? 0);
        if (!$id) json_response(['error' => 'id required'], 400);
        $stmt = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = :id');
        $stmt->execute([':id'=>$id]);
        json_response(['ok'=>true]);
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $body = read_json_body();
    
    if (isset($_GET['action']) && $_GET['action'] === 'delete_all') {
        // Delete all notifications for current user
        if (in_array($role, ['admin','librarian','assistant'], true)) {
            $stmt = $pdo->prepare("DELETE FROM notifications WHERE (role_target IN ('admin','librarian','assistant') OR role_target IS NULL)");
            $stmt->execute();
        } else {
            $stmt = $pdo->prepare('DELETE FROM notifications WHERE (user_id = :uid OR role_target = :role)');
            $stmt->execute([':uid'=>$u['id'], ':role'=>$role]);
        }
        json_response(['success' => true, 'message' => 'All notifications deleted']);
    } elseif (isset($body['id'])) {
        // Delete single notification
        $id = (int)$body['id'];
        
        // Check if notification belongs to user
        if (in_array($role, ['admin','librarian','assistant'], true)) {
            $stmt = $pdo->prepare("SELECT id FROM notifications WHERE id = :id AND (role_target IN ('admin','librarian','assistant') OR role_target IS NULL)");
            $stmt->execute([':id' => $id]);
        } else {
            $stmt = $pdo->prepare('SELECT id FROM notifications WHERE id = :id AND (user_id = :uid OR role_target = :role)');
            $stmt->execute([':id' => $id, ':uid' => $u['id'], ':role' => $role]);
        }
        
        $notification = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$notification) {
            json_response(['error' => 'Notification not found or access denied'], 404);
        }
        
        $deleteStmt = $pdo->prepare('DELETE FROM notifications WHERE id = :id');
        $deleteStmt->execute([':id' => $id]);
        
        json_response(['success' => true, 'message' => 'Notification deleted']);
    } else {
        json_response(['error' => 'Notification ID required'], 400);
    }
}

json_response(['error' => 'Method not allowed'], 405);
?>