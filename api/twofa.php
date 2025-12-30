<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/audit.php';

start_app_session();
if (!is_logged_in()) json_response(['error'=>'Unauthorized'],401);
$u = current_user();
$pdo = DB::conn();

$action = $_GET['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['error'=>'Method not allowed'],405);
require_csrf();

function send_twofa_code(array $user, string $purpose){
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $exp = (new DateTime('+10 minutes'))->format('Y-m-d H:i:s');
    DB::conn()->prepare('UPDATE users SET twofa_code = :c, twofa_expires_at = :e WHERE id = :id')->execute([':c'=>$code, ':e'=>$exp, ':id'=>$user['id']]);
    try {
        if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            $mail->setFrom('no-reply@localhost', 'LMS Security');
            $mail->addAddress($user['email']);
            $mail->Subject = 'Your 2FA ' . ucfirst($purpose) . ' Code';
            $mail->Body = 'Your verification code is: ' . $code;
            $mail->send();
        } else {
            @mail($user['email'], 'Your 2FA ' . ucfirst($purpose) . ' Code', 'Your verification code is: ' . $code);
        }
    } catch (Throwable $e) {}
}

if ($action === 'request_setup') {
    // mode=enable|disable
    $mode = ($_GET['mode'] ?? 'enable');
    $stmt = $pdo->prepare('SELECT id, email, username FROM users WHERE id = :id');
    $stmt->execute([':id'=>$u['id']]);
    $user = $stmt->fetch();
    send_twofa_code($user, $mode);
    audit('2fa_code_sent','twofa', $u['id'], ['mode'=>$mode]);
    json_response(['ok'=>true]);
}

$body = read_json_body();
if ($action === 'enable') {
    $code = $body['code'] ?? '';
    $stmt = $pdo->prepare('SELECT twofa_code, twofa_expires_at FROM users WHERE id = :id');
    $stmt->execute([':id'=>$u['id']]);
    $row = $stmt->fetch();
    if (!$row || !$row['twofa_code'] || !$row['twofa_expires_at']) json_response(['error'=>'No pending code'],400);
    if (!hash_equals($row['twofa_code'], $code) || new DateTime() > new DateTime($row['twofa_expires_at'])) json_response(['error'=>'Invalid or expired code'],401);
    $pdo->prepare('UPDATE users SET twofa_enabled = 1, twofa_code = NULL, twofa_expires_at = NULL WHERE id = :id')->execute([':id'=>$u['id']]);
    audit('2fa_enabled','twofa', $u['id']);
    json_response(['ok'=>true]);
}

if ($action === 'disable') {
    $code = $body['code'] ?? '';
    $stmt = $pdo->prepare('SELECT twofa_code, twofa_expires_at FROM users WHERE id = :id');
    $stmt->execute([':id'=>$u['id']]);
    $row = $stmt->fetch();
    if (!$row || !$row['twofa_code'] || !$row['twofa_expires_at']) json_response(['error'=>'No pending code'],400);
    if (!hash_equals($row['twofa_code'], $code) || new DateTime() > new DateTime($row['twofa_expires_at'])) json_response(['error'=>'Invalid or expired code'],401);
    $pdo->prepare('UPDATE users SET twofa_enabled = 0, twofa_code = NULL, twofa_expires_at = NULL WHERE id = :id')->execute([':id'=>$u['id']]);
    audit('2fa_disabled','twofa', $u['id']);
    json_response(['ok'=>true]);
}

json_response(['error'=>'Unknown action'],400);
?>

