<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

function start_app_session(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function current_user(): ?array {
    start_app_session();
    return $_SESSION['user'] ?? null;
}

function is_logged_in(): bool {
    return current_user() !== null;
}

function login_user(array $user): void {
    start_app_session();
    $_SESSION['user'] = [
        'id' => $user['id'],
        'username' => $user['username'],
        'email' => $user['email'],
        'role' => $user['role'],
        'name' => $user['name'] ?? $user['username'],
        'patron_id' => $user['patron_id'] ?? null,
    ];
}

function logout_user(): void {
    start_app_session();
    // remove remember-me token cookie and db record if present
    if (!empty($_COOKIE['LMS_REMEMBER'])) {
        [$selector, $validator] = explode(':', $_COOKIE['LMS_REMEMBER']) + [null, null];
        if ($selector) {
            $stmt = DB::conn()->prepare('DELETE FROM auth_tokens WHERE selector = :sel');
            $stmt->execute([':sel' => $selector]);
        }
        setcookie('LMS_REMEMBER', '', time() - 3600, '/', '', false, true);
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function require_login(): void {
    if (!is_logged_in()) {
        header('Location: ' . APP_BASE_URL . '/login.php');
        exit;
    }
}

function require_role(array $roles): void {
    require_login();
    $user = current_user();
    if (!$user || !in_array($user['role'], $roles, true)) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
}

// Permission matrix per resource and role
function can_access_resource(string $resource, string $method, string $role, ?array $context = null): bool {
    $method = strtoupper($method);
    $matrix = [
        'admin' => [ '*' => ['GET','POST','PUT','DELETE'] ],
        'librarian' => [
            'users' => ['GET','POST','PUT'], // cannot DELETE staff
            'patrons' => ['GET','POST','PUT','DELETE'],
            'books' => ['GET','POST','PUT','DELETE'],
            'ebooks' => ['GET','POST','PUT','DELETE'],
            'borrow_logs' => ['GET','POST','PUT'],
            'reservations' => ['GET','POST','PUT','DELETE'],
            'lost_damaged_reports' => ['GET','POST','PUT','DELETE'],
            // Librarians can no longer manage clearances. Only administrators have access to this resource.
        ],
        'assistant' => [
            'users' => ['GET'],
            'patrons' => ['GET','POST','PUT'],
            'books' => ['GET','POST','PUT'],
            'ebooks' => ['GET','POST','PUT'],
            'borrow_logs' => ['GET','POST','PUT'],
            'reservations' => ['GET','POST','PUT'],
            'lost_damaged_reports' => ['GET','POST','PUT'],
            // Assistants can no longer manage clearances. Only administrators have access to this resource.
        ],

        // Teachers share the same permissions as assistants.  Teachers are able to
        // view and manage book and borrow information but cannot perform
        // destructive actions such as deleting staff or clearing patrons.  This
        // mirrors the assistant role, granting them read/write access to
        // books, ebooks, borrow logs, and reservations while preventing
        // deletion of system resources.
        'teacher' => [
            'users' => ['GET'],
            'patrons' => ['GET','POST','PUT'],
            'books' => ['GET','POST','PUT'],
            'ebooks' => ['GET','POST','PUT'],
            'borrow_logs' => ['GET','POST','PUT'],
            'reservations' => ['GET','POST','PUT'],
            'lost_damaged_reports' => ['GET','POST','PUT'],
            // Teachers cannot manage clearances; only administrators have access.
        ],
        'student' => [
            'books' => ['GET'],
            'ebooks' => ['GET'],
            // Permit students to submit and view their e‑book access requests.
            'ebook_requests' => ['GET','POST'],
            'reservations' => ['GET','POST','DELETE'], // self only (enforced in API)
            'profile' => ['GET','PUT'],
        ],
        'non_staff' => [
            'books' => ['GET'],
            'ebooks' => ['GET'],
            'ebook_requests' => ['GET','POST'],
            'reservations' => ['GET','POST','DELETE'],
            'profile' => ['GET','PUT'],
        ],
    ];

    if ($role === 'admin') return true;
    $rolePerms = $matrix[$role] ?? [];
    $allowed = $rolePerms[$resource] ?? [];
    if (in_array($method, $allowed, true)) return true;
    // wildcard support
    if (isset($rolePerms['*']) && in_array($method, $rolePerms['*'], true)) return true;
    return false;
}

// Remember-me support for eligible roles (student, non_staff)
function issue_remember_token(int $user_id, int $days = 30): void {
    $selector = bin2hex(random_bytes(8));
    $validator = bin2hex(random_bytes(16));
    $hash = hash('sha256', $validator);
    $expires = (new DateTime("+{$days} days"))->format('Y-m-d H:i:s');
    $pdo = DB::conn();
    $stmt = $pdo->prepare('INSERT INTO auth_tokens (user_id, selector, validator_hash, expires_at) VALUES (:uid,:sel,:val,:exp)');
    $stmt->execute([':uid'=>$user_id, ':sel'=>$selector, ':val'=>$hash, ':exp'=>$expires]);
    setcookie('LMS_REMEMBER', $selector . ':' . $validator, time() + ($days*86400), '/', '', false, true);
}

function try_remember_login(): void {
    start_app_session();
    if (is_logged_in()) return;
    if (empty($_COOKIE['LMS_REMEMBER'])) return;
    [$selector, $validator] = explode(':', $_COOKIE['LMS_REMEMBER']) + [null, null];
    if (!$selector || !$validator) return;
    $pdo = DB::conn();
    $stmt = $pdo->prepare('SELECT * FROM auth_tokens WHERE selector = :sel AND expires_at > NOW() LIMIT 1');
    $stmt->execute([':sel' => $selector]);
    $tok = $stmt->fetch();
    if (!$tok) return;
    if (!hash_equals($tok['validator_hash'], hash('sha256', $validator))) {
        // revoke
        $pdo->prepare('DELETE FROM auth_tokens WHERE id = :id')->execute([':id'=>$tok['id']]);
        return;
    }
    $u = $pdo->prepare('SELECT id, username, email, role, name FROM users WHERE id = :id AND status = "active"');
    $u->execute([':id' => $tok['user_id']]);
    $user = $u->fetch();
    if ($user && in_array($user['role'], ['student','non_staff'], true)) {
        login_user($user);
    }
}

// Run remember login as early as possible on include
try_remember_login();
?>
