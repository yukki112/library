<?php
// Messages API.  Provides simple endpoints for sending and retrieving
// direct messages between users (e.g. students and administrators).
//
// Supported operations:
//   GET  /api/messages.php?with=<id>
//       Fetch the full conversation between the current user and the
//       specified user.  If `with` is omitted and the current user is
//       a student or non‑staff, the endpoint automatically selects the
//       first administrator (sorted by ID) as the conversation partner.
//   POST /api/messages.php
//       Send a new message.  The JSON body must contain at least a
//       `content` property.  A `receiver_id` may optionally be
//       specified.  When omitted the receiver defaults to the first
//       administrator.  The response body contains { ok: true } or
//       { error: string } on failure.

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/notify.php';

start_app_session();
$user = current_user();
if (!$user) {
    json_response(['error' => 'Authentication required'], 401);
}
$pdo = DB::conn();

// -----------------------------------------------------------------------------
// Ensure the messages table exists
//
// Some deployments may not have run the migration that creates the `messages`
// table. Attempting to query or insert into a non‑existent table will
// produce a database error (SQLSTATE 42S02 / error 1146) and cause the
// frontend to receive an invalid JSON response. To provide a seamless
// experience, detect this scenario and automatically create the table using
// the same schema defined in migrations/alter_003.sql. This check runs
// once per request and imposes negligible overhead when the table already
// exists.
try {
    // Perform a trivial query to test for the existence of the messages
    // table. If the table is missing, this will throw an exception.
    $pdo->query("SELECT 1 FROM messages LIMIT 1");
} catch (Throwable $ex) {
    $msg = $ex->getMessage();
    // SQLSTATE 42S02 (base table not found) or error number 1146
    // indicates that the table does not exist. Create it on the fly.
    if (strpos($msg, '42S02') !== false || strpos($msg, '1146') !== false) {
        $createSql = <<<SQL
CREATE TABLE IF NOT EXISTS messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sender_id INT NOT NULL,
  receiver_id INT NOT NULL,
  content TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE
)
SQL;
        $pdo->exec($createSql);
    } else {
        // For any other error, rethrow so the global exception handler
        // returns a proper JSON error response.
        throw $ex;
    }
}

// Determine the first administrator's user ID.  This lookup is used
// whenever a student or non‑staff does not specify a receiver.  If
// there are no administrators defined, a null is returned.  The
// result is cached to avoid multiple queries.
function get_first_admin_id(PDO $pdo): ?int {
    static $cache = null;
    if ($cache !== null) return $cache;
    $stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1");
    $stmt->execute();
    $id = $stmt->fetchColumn();
    $cache = $id ? (int)$id : null;
    return $cache;
}

// GET: return conversation messages
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // If a specific message ID is requested, return that message with sender and receiver ids.  This
    // facilitates linking notifications to chat conversations.  Only the message
    // record is returned; authentication ensures the current user has access to
    // message metadata (it does not reveal content to unauthorized users).
    if (isset($_GET['id']) && is_numeric($_GET['id'])) {
        $mid = (int)$_GET['id'];
        $stmt = $pdo->prepare('SELECT m.id, m.sender_id, m.receiver_id, m.content, m.created_at, s.username AS sender_name, r.username AS receiver_name
                                FROM messages m
                                JOIN users s ON m.sender_id = s.id
                                JOIN users r ON m.receiver_id = r.id
                                WHERE m.id = :mid LIMIT 1');
        $stmt->execute([':mid' => $mid]);
        $msg = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$msg) {
            json_response([]);
        }
        // Restrict access: only allow participants (sender or receiver) or staff to see
        $uid = (int)$user['id'];
        $urole = $user['role'] ?? '';
        if ($uid !== (int)$msg['sender_id'] && $uid !== (int)$msg['receiver_id'] && !in_array($urole, ['admin','librarian','assistant'], true)) {
            json_response(['error' => 'Forbidden'], 403);
        }
        json_response($msg);
    }
    // Determine the peer user.  Students default to the first admin when
    // no `with` parameter is provided.  Admins must specify a user.
    $withIdParam = isset($_GET['with']) ? (int)$_GET['with'] : null;
    $peerId = null;
    $role = $user['role'] ?? '';
    if ($withIdParam) {
        $peerId = $withIdParam;
    } elseif (in_array($role, ['student','non_staff'], true)) {
        $peerId = get_first_admin_id($pdo);
    }
    if (!$peerId) {
        json_response([]);
    }
    $uid = (int)$user['id'];
    // Fetch all messages where current user is sender or receiver and peer is opposite.
    $stmt = $pdo->prepare(
        'SELECT m.id, m.sender_id, m.receiver_id, m.content, m.created_at,
                s.username AS sender_name, r.username AS receiver_name
         FROM messages m
         JOIN users s ON m.sender_id = s.id
         JOIN users r ON m.receiver_id = r.id
         WHERE (m.sender_id = :uid AND m.receiver_id = :peer)
            OR (m.sender_id = :peer AND m.receiver_id = :uid)
         ORDER BY m.created_at ASC'
    );
    $stmt->execute([':uid' => $uid, ':peer' => $peerId]);
    $msgs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    json_response($msgs);
}

// POST: create a message
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = read_json_body();
    $content = trim((string)($body['content'] ?? ''));
    $receiverId = isset($body['receiver_id']) ? (int)$body['receiver_id'] : null;
    if ($content === '') {
        json_response(['error' => 'Content is required'], 400);
    }
    // Determine receiver default.  Students default to first admin.
    if (!$receiverId) {
        $receiverId = get_first_admin_id($pdo);
    }
    if (!$receiverId) {
        json_response(['error' => 'No administrator found to receive messages'], 400);
    }
    $senderId = (int)$user['id'];
    try {
        $stmt = $pdo->prepare('INSERT INTO messages (sender_id, receiver_id, content) VALUES (:s, :r, :c)');
        $stmt->execute([':s' => $senderId, ':r' => $receiverId, ':c' => $content]);
        $msgId = (int)$pdo->lastInsertId();
        // Send a notification to the receiver to indicate a new message has arrived.
        notify_user($receiverId, null, 'message', 'New message from ' . ($user['username'] ?? 'user'), ['message_id' => $msgId]);
        json_response(['ok' => true, 'id' => $msgId]);
    } catch (Throwable $e) {
        json_response(['error' => $e->getMessage()], 500);
    }
}

// Unsupported method
json_response(['error' => 'Method not allowed'], 405);
?>