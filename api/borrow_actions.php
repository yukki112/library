<?php
// Borrow actions API.  Provides endpoints to return or extend a
// borrowed book.  Students and non‑staff may initiate return or
// extension requests, which are processed immediately for
// demonstration purposes.  In a production system these actions
// should trigger an approval workflow for administrators.

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/notify.php';

start_app_session();
$user = current_user();
if (!$user) {
    json_response(['error' => 'Authentication required'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$body = read_json_body();
$id = isset($body['id']) ? (int)$body['id'] : 0;
$action = $body['action'] ?? '';
if (!$id || !in_array($action, ['return','extend'], true)) {
    json_response(['error' => 'Invalid parameters'], 400);
}

$pdo = DB::conn();
// Verify borrow_log exists and belongs to current user if user is student/non-staff
$stmt = $pdo->prepare('SELECT bl.*, b.available_copies FROM borrow_logs bl JOIN books b ON bl.book_id = b.id WHERE bl.id = :id');
$stmt->execute([':id' => $id]);
$log = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$log) {
    json_response(['error' => 'Borrow record not found'], 404);
}
$role = $user['role'] ?? '';
// Students can only act on their own records
if (in_array($role, ['student','non_staff'], true)) {
    if ((int)$log['patron_id'] !== (int)($user['patron_id'] ?? 0)) {
        json_response(['error' => 'Forbidden'], 403);
    }
}
// Perform the requested action
try {
    if ($action === 'return') {
        // Update borrow_log: set returned_at to now and status to returned
        $now = (new DateTime('now'))->format('Y-m-d H:i:s');
        $stmt = $pdo->prepare('UPDATE borrow_logs SET returned_at = :ret, status = :st WHERE id = :id');
        $stmt->execute([':ret' => $now, ':st' => 'returned', ':id' => $id]);
        // Increment available_copies on books
        $stmt = $pdo->prepare('UPDATE books SET available_copies = available_copies + 1 WHERE id = :bid');
        $stmt->execute([':bid' => (int)$log['book_id']]);
        // Notify administrators
        notify_user(null, 'admin', 'return', 'Book returned by ' . ($user['username'] ?? 'user'), ['borrow_id' => $id]);
    } elseif ($action === 'extend') {
        // Compute new due date by adding 7 days to the current due_date
        $currentDue = new DateTime($log['due_date']);
        $currentDue->modify('+7 days');
        $newDue = $currentDue->format('Y-m-d H:i:s');
        $stmt = $pdo->prepare('UPDATE borrow_logs SET due_date = :due WHERE id = :id');
        $stmt->execute([':due' => $newDue, ':id' => $id]);
        // Notify administrators
        notify_user(null, 'admin', 'extend', 'Borrow extended by ' . ($user['username'] ?? 'user'), ['borrow_id' => $id]);
    }
    json_response(['ok' => true]);
} catch (Throwable $e) {
    json_response(['error' => $e->getMessage()], 500);
}
?>