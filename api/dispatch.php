<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/notify.php';
require_once __DIR__ . '/../includes/settings.php';

// -----------------------------------------------------------------------------
// Robust error handling
//
// The API endpoints should always return valid JSON.  However, if a PHP
// warning or exception bubbles up (for example, due to a database
// misconfiguration or a missing table) PHP will by default emit an HTML error
// page.  The frontend expects JSON and will fail to parse the response,
// resulting in errors like "Unexpected token '<'".  To prevent that, we
// register global error and exception handlers up front.  Any uncaught
// exception or warning will be translated into a JSON error response.  This
// keeps the API contract consistent and makes debugging easier.
set_exception_handler(function (Throwable $e): void {
    http_response_code(500);
    header('Content-Type: application/json');
    // Do not leak sensitive details in production; include message for easier
    // debugging in development.  Prefix with a generic label so clients can
    // display a friendly error.
    $msg = $e->getMessage();
    echo json_encode(['error' => 'Server error: ' . $msg], JSON_UNESCAPED_UNICODE);
    exit;
});

set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline): void {
    // Convert all errors to exceptions so they are handled uniformly by the
    // exception handler above.  Triggering an exception here ensures that
    // fatal errors such as undefined variables or database warnings are
    // reported as JSON.
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

start_app_session();

$resource = strtolower($_GET['resource'] ?? '');
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$method = $_SERVER['REQUEST_METHOD'];

if (!$resource) {
    json_response(['error' => 'Resource is required'], 400);
}

// Resource configuration: table name and allowed fields
$RESOURCES = [
    'users' => [
        'table' => 'users',
        // Allow updates to address in addition to existing fields.  Password is
        // accepted but is hashed before storage in the POST/PUT logic.
        'fields' => ['username','email','name','phone','address','role','status','password'],
        'defaults' => ['status' => 'active'],
    ],
    'patrons' => [
        'table' => 'patrons',
        // Accept semester, department and address fields on patrons so that
        // student academic information can be maintained.  These fields are
        // optional when creating or updating patrons.
        'fields' => ['name','library_id','email','phone','semester','department','address','membership_date','status'],
        'defaults' => ['status' => 'active'],
    ],
    'books' => [
        'table' => 'books',
        'fields' => ['title','author','isbn','category','publisher','year_published','total_copies','available_copies','description','is_active'],
        'defaults' => ['is_active' => 1],
    ],
    'ebooks' => [
        'table' => 'ebooks',
        'fields' => ['book_id','file_path','file_format','is_active','description'],
        'defaults' => ['is_active' => 1],
    ],
    'borrow_logs' => [
        'table' => 'borrow_logs',
        'fields' => ['book_id','patron_id','borrowed_at','due_date','returned_at','status','notes'],
        'defaults' => ['status' => 'borrowed'],
    ],
    'reservations' => [
        'table' => 'reservations',
        // Permit an optional `reason` field used to record why a reservation was declined.
        'fields' => ['book_id','patron_id','reserved_at','status','expiration_date','reason'],
        // Reservations now default to a "pending" status so that staff can review and approve or decline
        'defaults' => ['status' => 'pending'],
    ],
    'lost_damaged_reports' => [
        'table' => 'lost_damaged_reports',
        'fields' => ['book_id','patron_id','report_date','report_type','severity','description','fee_charged','status'],
        'defaults' => ['status' => 'pending'],
    ],

    // E‑Book access requests.  Students and non‑teaching staff submit
    // a request to access the library's e‑book collection.  The request
    // is stored in the ebook_requests table and must be approved by a
    // staff member.  Only the status may be updated after creation.
    // E‑book access requests.  Each request now stores the username of the
    // requester and the optional book being requested rather than a
    // patron_id.  This allows the system to decouple from the patrons
    // table and simplifies the UI.  The request_date and action fields are
    // exposed for creation/update.
    'ebook_requests' => [
        'table' => 'ebook_requests',
        'fields' => ['book_id','username','request_date','status','action'],
        'defaults' => ['status' => 'pending'],
    ],
    'clearances' => [
        'table' => 'clearances',
        'fields' => ['patron_id','clearance_date','status','notes'],
        'defaults' => ['status' => 'pending'],
    ],
];

if (!isset($RESOURCES[$resource])) {
    json_response(['error' => 'Unknown resource'], 404);
}

$conf = $RESOURCES[$resource];
$role = current_user()['role'] ?? 'guest';
$user = current_user();

// Gate access
if (!can_access_resource($resource, $method, $role)) {
    json_response(['error' => 'Forbidden'], 403);
}

$pdo = DB::conn();

// -----------------------------------------------------------------------------
// Ensure the reservations table has a `reason` column.
//
// Several installations may not have run the migration that adds the
// optional decline reason column on the reservations table.  When a PUT or
// POST request includes a `reason` property or when queries attempt to
// select it, MySQL will emit SQLSTATE 42S22 / 1054 unknown column errors.
// To avoid these fatal errors and to support the decline reason feature
// seamlessly, attempt to select the `reason` column and create it on the fly
// if it does not exist.  This check executes once per request and has
// negligible overhead when the column exists.  It is performed before
// entering the switch statement to cover all CRUD methods.
if ($resource === 'reservations') {
    try {
        // Attempt to select the column.  If the column is missing this
        // statement will throw.
        $pdo->query('SELECT reason FROM reservations LIMIT 1');
    } catch (Throwable $ex) {
        $msg = $ex->getMessage();
        // SQLSTATE 42S22 or error number 1054 correspond to unknown column
        // errors.  Add the column dynamically.
        if (strpos($msg, '42S22') !== false || strpos($msg, '1054') !== false) {
            try {
                $pdo->exec('ALTER TABLE reservations ADD COLUMN reason VARCHAR(255) NULL AFTER expiration_date');
            } catch (Throwable $e) {
                // Silently ignore if another request has already created it.
            }
        } else {
            // Re-throw if unrelated to missing column
            throw $ex;
        }
    }
}

// -------------------------------------------------------------------------
// Automatically provision the ebook_requests table if it does not exist.
//
// Some deployments may omit running the migration that creates the
// ebook_requests table. When a student attempts to request access to
// e‑books, the API will try to insert into this table. Without the
// table, MySQL throws a 42S02/1146 error (base table not found). To
// provide a seamless experience and avoid confusing server errors on
// the frontend, detect the absence of the table and create it on the
// fly using the same schema as defined in migrations/schema.sql. This
// check executes only when the ebook_requests resource is being used
// and imposes negligible overhead on other resources.
if ($resource === 'ebook_requests') {
    try {
        // Attempt a simple query. If the table does not exist the
        // statement will throw.
        $pdo->query("SELECT 1 FROM ebook_requests LIMIT 1");
    } catch (Throwable $ex) {
        $msg = $ex->getMessage();
        // SQLSTATE 42S02 or error number 1146 correspond to missing table.
        if (strpos($msg, '42S02') !== false || strpos($msg, '1146') !== false) {
            $createSql = <<<SQL
CREATE TABLE IF NOT EXISTS ebook_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  book_id INT NULL,
  username VARCHAR(64) NOT NULL,
  request_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  status ENUM('pending','approved','declined') NOT NULL DEFAULT 'pending',
  action VARCHAR(32) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE SET NULL,
  INDEX idx_username (username)
)
SQL;
            $pdo->exec($createSql);
        } else {
            // If the error is unrelated to a missing table, rethrow so the
            // global exception handler can return a 500 to the client.
            throw $ex;
        }
    }
}

switch ($method) {
    case 'GET':
        if ($id) {
            $stmt = $pdo->prepare("SELECT * FROM {$conf['table']} WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch();
            // Ownership restriction for student/non_staff
            if (in_array($role, ['student','non_staff'], true)) {
                if ($resource === 'reservations' && $row && (int)$row['patron_id'] !== (int)($user['patron_id'] ?? 0)) $row = null;
                if ($resource === 'borrow_logs' && $row && (int)$row['patron_id'] !== (int)($user['patron_id'] ?? 0)) $row = null;
                if ($resource === 'lost_damaged_reports' && $row && (int)$row['patron_id'] !== (int)($user['patron_id'] ?? 0)) $row = null;
                if ($resource === 'users') $row = null;
                if ($resource === 'patrons' && $row && (int)$row['id'] !== (int)($user['patron_id'] ?? -1)) $row = null;
            }
            json_response($row ?: null);
        } else {
            if (in_array($role, ['student','non_staff'], true)) {
                // Students and non‑teaching staff can only query their own
                // reservations, borrow logs, lost/damaged reports and e‑book
                // access requests.  Restrict the resource accordingly.
                if (in_array($resource, ['reservations','borrow_logs','lost_damaged_reports','ebook_requests'], true)) {
                    if ($resource === 'ebook_requests') {
                        // Filter by username rather than patron_id for the
                        // new schema.  Students should only see their own
                        // e‑book access requests.
                        $uname = $user['username'] ?? '';
                        $stmt = $pdo->prepare('SELECT * FROM ' . $conf['table'] . ' WHERE username = :uname ORDER BY id DESC');
                        $stmt->execute([':uname' => $uname]);
                        $rows = $stmt->fetchAll();
                    } else {
                        $pid = (int)($user['patron_id'] ?? 0);
                        $stmt = $pdo->prepare('SELECT * FROM ' . $conf['table'] . ' WHERE patron_id = :pid ORDER BY id DESC');
                        $stmt->execute([':pid' => $pid]);
                        $rows = $stmt->fetchAll();
                    }
                } elseif ($resource === 'books' || $resource === 'ebooks') {
                    $stmt = $pdo->query('SELECT * FROM ' . $conf['table'] . ' ORDER BY id DESC');
                    $rows = $stmt->fetchAll();
                } elseif ($resource === 'patrons') {
                    $stmt = $pdo->prepare('SELECT * FROM patrons WHERE id = :pid');
                    $stmt->execute([':pid' => (int)($user['patron_id'] ?? 0)]);
                    $rows = $stmt->fetchAll();
                } else {
                    $rows = [];
                }
            } else {
                $stmt = $pdo->query("SELECT * FROM {$conf['table']} ORDER BY id DESC");
                $rows = $stmt->fetchAll();
            }
            // Augment rows with user names when a patron_id column exists.  The
            // frontend replaces the `patron_id` column with a `user` column and
            // displays the associated name.  We build a mapping of patron IDs
            // to names using both the users and patrons tables.  See public/crud.php
            // for UI handling.
            if (!empty($rows) && isset($rows[0]) && array_key_exists('patron_id', $rows[0])) {
                // Gather distinct patron IDs.
                $pidsMap = [];
                foreach ($rows as $r) {
                    if (isset($r['patron_id'])) {
                        $pidsMap[(int)$r['patron_id']] = true;
                    }
                }
                $patronIds = array_keys($pidsMap);
                $userMap = [];
                $usernameMap = [];
                if ($patronIds) {
                    // Fetch names and usernames from the users table keyed by patron_id.
                    $placeholders = implode(',', array_fill(0, count($patronIds), '?'));
                    $uq = $pdo->prepare("SELECT patron_id, COALESCE(name, '') AS name, username FROM users WHERE patron_id IN ($placeholders)");
                    $uq->execute($patronIds);
                    foreach ($uq->fetchAll() as $m) {
                        $pidKey = (int)$m['patron_id'];
                        $userMap[$pidKey] = $m['name'];
                        $usernameMap[$pidKey] = $m['username'];
                    }
                    // Look up any missing IDs in the patrons table for names only.  If a
                    // patron has no associated user record (e.g. guest checkout), we
                    // still attempt to show their name from the patrons table.  The
                    // username will remain undefined in that case.
                    $missing = array_values(array_diff($patronIds, array_keys($userMap)));
                    if ($missing) {
                        $ph = implode(',', array_fill(0, count($missing), '?'));
                        $pq = $pdo->prepare("SELECT id, name FROM patrons WHERE id IN ($ph)");
                        $pq->execute($missing);
                        foreach ($pq->fetchAll() as $p) {
                            $userMap[(int)$p['id']] = $p['name'];
                        }
                    }
                }
                foreach ($rows as &$rec) {
                    $pid = (int)($rec['patron_id'] ?? 0);
                    $rec['user'] = $userMap[$pid] ?? '';
                    if (!empty($usernameMap[$pid])) {
                        $rec['username'] = $usernameMap[$pid];
                    }
                }
                unset($rec);
            }
            json_response($rows);
        }
        break;
    case 'POST':
        require_csrf();
        $data = read_json_body();
        // Remove null values so that database defaults (e.g., CURRENT_TIMESTAMP) are used
        if (is_array($data)) {
            $data = array_filter($data, function ($v) {
                return $v !== null;
            });
            // Normalize any datetime-local inputs.  Browsers submit
            // datetime-local values in the form "YYYY-MM-DDTHH:MM".  MySQL
            // accepts "YYYY-MM-DD HH:MM:SS" or similar.  Convert common
            // patterns by replacing the "T" separator and adding seconds if
            // missing.  Only certain fields should be converted.
            foreach (['reserved_at','borrowed_at','due_date','returned_at'] as $dtField) {
                if (isset($data[$dtField]) && is_string($data[$dtField])) {
                    $v = $data[$dtField];
                    // Replace ISO separator T with space
                    $v = str_replace('T', ' ', $v);
                    // Append seconds if only minutes are present
                    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $v)) {
                        $v .= ':00';
                    }
                    $data[$dtField] = $v;
                }
            }
        }
        // Special handling for password (users)
        if ($resource === 'users' && !empty($data['password'])) {
            $data['password_hash'] = password_hash($data['password'], PASSWORD_DEFAULT);
            unset($data['password']);
        }
        // student/non_staff: enforce ownership and populate identifying fields
        if (in_array($role, ['student','non_staff'], true)) {
            // Students and non‑staff may create reservations, borrow logs,
            // lost/damaged reports, or e‑book access requests.  For
            // reservations, borrow_logs and lost_damaged_reports we bind
            // the patron_id to the currently logged in user.  For
            // ebook_requests we instead bind the username to the
            // currently logged in user and leave book_id untouched.
            if (in_array($resource, ['reservations','borrow_logs','lost_damaged_reports','ebook_requests'], true)) {
                if ($resource === 'ebook_requests') {
                    // Remove any supplied username to prevent spoofing and
                    // set it from the session user.  Do not allow
                    // students/non‑staff to impersonate another user.
                    $data['username'] = $user['username'] ?? '';
                    // Do not attach patron_id; the new schema uses
                    // username instead.  book_id may be supplied by the
                    // client when requesting a specific e‑book; if it is
                    // absent the DB default of NULL applies.
                    unset($data['patron_id']);
                } else {
                    $data['patron_id'] = (int)($user['patron_id'] ?? 0);
                }
            } else {
                json_response(['error' => 'Forbidden'], 403);
            }
        }
        // Defaults for borrow logs (students/non_staff or staff if omitted)
        if ($resource === 'borrow_logs') {
            if (empty($data['borrowed_at'])) {
                $data['borrowed_at'] = (new DateTime())->format('Y-m-d H:i:s');
            }
            if (empty($data['due_date'])) {
                $days = (int)settings_get('borrow_period_days', 14);
                $data['due_date'] = (new DateTime('+' . $days . ' days'))->format('Y-m-d H:i:s');
            }
            // ensure book available
            if (empty($data['book_id'])) json_response(['error'=>'book_id required'],422);
            $avail = $pdo->prepare('SELECT available_copies FROM books WHERE id = :id');
            $avail->execute([':id'=>(int)$data['book_id']]);
            $available = (int)$avail->fetchColumn();
            if ($available < 1) json_response(['error'=>'Book not available'],422);
        }
        // Validate reservations: ensure the referenced book exists and, optionally, has available copies.
        if ($resource === 'reservations') {
            if (empty($data['book_id'])) {
                json_response(['error' => 'book_id required'], 422);
            }
            $chkBook = $pdo->prepare('SELECT available_copies FROM books WHERE id = :id');
            $chkBook->execute([':id' => (int)$data['book_id']]);
            $bookRow = $chkBook->fetch();
            if (!$bookRow) {
                json_response(['error' => 'Invalid book_id'], 422);
            }
            // Only allow a reservation if at least one copy is available.  This mirrors the borrow
            // logic and prevents overbooking books that are out of stock.  If you wish to
            // allow reservations even when no copies are currently available, comment out
            // the following condition.
            if ((int)$bookRow['available_copies'] < 1) {
                json_response(['error' => 'No available copies for reservation'], 422);
            }
        }
        $fields = array_intersect(array_keys($data), $conf['fields']);
        $insert = array_merge($conf['defaults'] ?? [], array_intersect_key($data, array_flip($fields)));
        if (empty($insert)) json_response(['error' => 'No valid fields'], 422);
        $cols = array_keys($insert);
        // Use a standard closure instead of arrow function for compatibility
        $params = array_map(function ($c) {
            return ':' . $c;
        }, $cols);
        $sql = 'INSERT INTO ' . $conf['table'] . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', $params) . ')';
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_combine($params, array_values($insert)));
        $newId = (int)$pdo->lastInsertId();
        $stmt = $pdo->prepare('SELECT * FROM ' . $conf['table'] . ' WHERE id = :id');
        $stmt->execute([':id' => $newId]);
        $row = $stmt->fetch();
        // Post-insert hooks
        if ($resource === 'borrow_logs') {
            if (($row['status'] ?? 'borrowed') === 'borrowed') {
                $pdo->prepare('UPDATE books SET available_copies = GREATEST(available_copies - 1, 0) WHERE id = :bid')->execute([':bid'=>$row['book_id']]);
                notify_user(null, 'librarian', 'borrowed', 'A book was borrowed', ['borrow_log_id'=>$row['id']]);
            }
            audit('create','borrow_logs', (int)$row['id'], $row);
        } elseif ($resource === 'lost_damaged_reports') {
            $fee = compute_damage_fee($row['severity'] ?? 'minor');
            $pdo->prepare('UPDATE lost_damaged_reports SET fee_charged = :f WHERE id = :id')->execute([':f'=>$fee, ':id'=>$row['id']]);
            notify_user(null, 'librarian', 'report', 'A lost/damaged report was filed', ['report_id'=>$row['id']]);
            audit('create','lost_damaged_reports', (int)$row['id'], $row);
        } elseif ($resource === 'reservations') {
            // When a student creates a reservation, notify staff for approval. Use a role target of
            // admin so that all staff (admin, librarian, assistant) receive it via the notifications API.
            notify_user(null, 'admin', 'reservation', 'New reservation pending approval', [
                'reservation_id' => $row['id'],
                'patron_id' => $row['patron_id'],
                'book_id' => $row['book_id']
            ]);
            audit('create','reservations',(int)$row['id'],$row);
        } else {
            audit('create', $resource, (int)$row['id'], $row);
        }
        json_response($row, 201);
        break;
    case 'PUT':
    case 'PATCH':
        require_csrf();
        if (!$id) json_response(['error' => 'ID required'], 400);
        $data = read_json_body();
        // Remove null values to avoid overwriting with NULL and use DB defaults
        if (is_array($data)) {
            $data = array_filter($data, function ($v) {
                return $v !== null;
            });
        }
        if ($resource === 'users' && !empty($data['password'])) {
            $data['password_hash'] = password_hash($data['password'], PASSWORD_DEFAULT);
            unset($data['password']);
        }
        // Ownership enforcement for students
        if (in_array($role, ['student','non_staff'], true)) {
            // Students and non‑staff can only update their own reservations,
            // borrow logs, lost/damaged reports or e‑book requests.  Any
            // attempt to update other resources should be rejected.
            if (!in_array($resource, ['reservations','borrow_logs','lost_damaged_reports','ebook_requests'], true)) {
                json_response(['error' => 'Forbidden'], 403);
            }
            if ($resource === 'ebook_requests') {
                // For e‑book requests ownership is determined by username
                $chk = $pdo->prepare('SELECT username FROM ' . $conf['table'] . ' WHERE id = :id');
                $chk->execute([':id' => $id]);
                $ownUser = (string)$chk->fetchColumn();
                if ($ownUser === '') json_response(['error' => 'Not found'], 404);
                if ($ownUser !== (string)($user['username'] ?? '')) json_response(['error' => 'Forbidden'], 403);
            } else {
                $chk = $pdo->prepare('SELECT patron_id FROM ' . $conf['table'] . ' WHERE id = :id');
                $chk->execute([':id'=>$id]);
                $own = (int)$chk->fetchColumn();
                if ($own !== (int)($user['patron_id'] ?? 0)) json_response(['error' => 'Forbidden'], 403);
            }
        }
        // Normalize datetime-local inputs on updates just like POST.  See
        // commentary above.  Perform this before intersecting with allowed fields
        // so that converted values are passed through.
        if (is_array($data)) {
            foreach (['reserved_at','borrowed_at','due_date','returned_at'] as $dtField) {
                if (isset($data[$dtField]) && is_string($data[$dtField])) {
                    $v = $data[$dtField];
                    $v = str_replace('T', ' ', $v);
                    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $v)) {
                        $v .= ':00';
                    }
                    $data[$dtField] = $v;
                }
            }
        }

        $fields = array_intersect(array_keys($data), $conf['fields']);
        // Capture the current state for reservations and ebook requests before applying updates
        $prevReservation = null;
        $prevEbookReq = null;
        if ($resource === 'reservations') {
            $stmtPrev = $pdo->prepare('SELECT * FROM reservations WHERE id = :id');
            $stmtPrev->execute([':id' => $id]);
            $prevReservation = $stmtPrev->fetch();
        } elseif ($resource === 'ebook_requests') {
            $stmtPrev = $pdo->prepare('SELECT * FROM ebook_requests WHERE id = :id');
            $stmtPrev->execute([':id' => $id]);
            $prevEbookReq = $stmtPrev->fetch();
        }
        if (empty($fields)) json_response(['error' => 'No valid fields'], 422);
        $set = [];
        $params = [':id' => $id];
        foreach ($fields as $f) {
            $set[] = "$f = :$f";
            $params[":$f"] = $data[$f];
        }
        $sql = 'UPDATE ' . $conf['table'] . ' SET ' . implode(',', $set) . ' WHERE id = :id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $stmt = $pdo->prepare('SELECT * FROM ' . $conf['table'] . ' WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        // For ebook requests, if the status changed from pending to another state, notify the requesting user.
        if ($resource === 'ebook_requests') {
            if ($prevEbookReq && isset($data['status']) && ($prevEbookReq['status'] ?? '') !== ($row['status'] ?? '')) {
                // Look up the user ID by username to deliver the notification
                $uname = $row['username'] ?? '';
                $uidStmt = $pdo->prepare('SELECT id FROM users WHERE username = :uname LIMIT 1');
                $uidStmt->execute([':uname' => $uname]);
                $uidTarget = (int)$uidStmt->fetchColumn();
                if ($uidTarget) {
                    if ($row['status'] === 'approved') {
                        notify_user($uidTarget, null, 'ebook_request_approved', 'Your e‑book access request has been approved', [ 'ebook_request_id' => $row['id'], 'book_id' => $row['book_id'] ]);
                    } elseif ($row['status'] === 'declined') {
                        notify_user($uidTarget, null, 'ebook_request_declined', 'Your e‑book access request has been declined', [ 'ebook_request_id' => $row['id'], 'book_id' => $row['book_id'] ]);
                    }
                }
            }
        }
        if ($resource === 'reservations') {
            // If the reservation status changed from pending to another state, notify the patron accordingly.
            if ($prevReservation && isset($data['status']) && ($prevReservation['status'] ?? '') !== ($row['status'] ?? '')) {
                // Determine the patron's user ID for notification. Each patron maps to exactly one user.
                $uidStmt = $pdo->prepare('SELECT id FROM users WHERE patron_id = :pid LIMIT 1');
                $uidStmt->execute([':pid' => $row['patron_id']]);
                $uid = (int)$uidStmt->fetchColumn();
                if ($uid) {
                    if ($row['status'] === 'approved') {
                        notify_user($uid, null, 'reservation_approved', 'Your reservation has been approved', [
                            'reservation_id' => $row['id'],
                            'book_id' => $row['book_id']
                        ]);
                    } elseif ($row['status'] === 'declined' || $row['status'] === 'cancelled') {
                        // Include the decline reason when notifying the patron.  When staff
                        // supply a reason via the API the `reason` column will be set on
                        // the reservation.  Compose a helpful message for the user and
                        // include the reason in the meta payload for client display.
                        $reasonMsg = '';
                        $declineReason = isset($row['reason']) ? trim((string)$row['reason']) : '';
                        if ($declineReason !== '') {
                            $reasonMsg = ': ' . $declineReason;
                        }
                        notify_user($uid, null, 'reservation_declined', 'Your reservation has been declined' . $reasonMsg, [
                            'reservation_id' => $row['id'],
                            'book_id' => $row['book_id'],
                            'reason' => $declineReason
                        ]);
                    }
                }
            }

            // Automatically convert an approved reservation into a borrow entry.
            // When staff approve a reservation, create a corresponding borrow log so that the student
            // can see it under My Borrows along with pickup and return dates. Only run this
            // conversion when transitioning from a non-approved state to approved.
            if ($prevReservation && isset($data['status']) && ($row['status'] ?? '') === 'approved' && ($prevReservation['status'] ?? '') !== 'approved') {
                // Determine the borrowing start date. Use the reservation's reserved_at if present,
                // otherwise default to the current timestamp.  Normalize to Y-m-d H:i:s.
                $borrowedAt = $row['reserved_at'] ?? null;
                if (!$borrowedAt) {
                    $borrowedAt = (new DateTime())->format('Y-m-d H:i:s');
                }
                // Compute the due date. Prefer the expiration_date provided on the reservation. If it
                // contains only a date (YYYY-MM-DD), append a time so MySQL will accept it. If no
                // expiration_date is supplied, fall back to using the configured borrow period.
                $dueDate = null;
                if (!empty($row['expiration_date'])) {
                    $exp = $row['expiration_date'];
                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $exp)) {
                        $dueDate = $exp . ' 23:59:59';
                    } else {
                        $dueDate = $exp;
                    }
                }
                if (!$dueDate) {
                    $days = (int)settings_get('borrow_period_days', 14);
                    try {
                        $dBorrow = new DateTime($borrowedAt);
                    } catch (Throwable $e) {
                        $dBorrow = new DateTime();
                    }
                    $dBorrow->modify('+' . $days . ' days');
                    $dueDate = $dBorrow->format('Y-m-d H:i:s');
                }
                // Avoid inserting duplicate borrow logs for the same reservation.  Check if a borrow
                // already exists for this book and patron combination on the same borrowed_at date.
                $dupStmt = $pdo->prepare('SELECT id FROM borrow_logs WHERE book_id = :bid AND patron_id = :pid AND borrowed_at = :bat LIMIT 1');
                $dupStmt->execute([':bid' => (int)$row['book_id'], ':pid' => (int)$row['patron_id'], ':bat' => $borrowedAt]);
                $existingBorrowId = $dupStmt->fetchColumn();
                if (!$existingBorrowId) {
                    // Insert the borrow log entry
                    $stmtInsert = $pdo->prepare('INSERT INTO borrow_logs (book_id, patron_id, borrowed_at, due_date, status, notes) VALUES (:bid, :pid, :bat, :dd, :status, :notes)');
                    $stmtInsert->execute([
                        ':bid' => (int)$row['book_id'],
                        ':pid' => (int)$row['patron_id'],
                        ':bat' => $borrowedAt,
                        ':dd' => $dueDate,
                        ':status' => 'borrowed',
                        ':notes' => 'Reservation ID ' . $row['id'],
                    ]);
                    $newBorrowId = (int)$pdo->lastInsertId();
                    // Deduct an available copy of the book when the borrow is created.  Mirrors the
                    // behavior of the normal borrow_logs creation path.
                    $pdo->prepare('UPDATE books SET available_copies = GREATEST(available_copies - 1, 0) WHERE id = :bid')->execute([':bid' => (int)$row['book_id']]);
                    // Retrieve the inserted borrow row for auditing and notifications
                    $stmtBorrow = $pdo->prepare('SELECT * FROM borrow_logs WHERE id = :id');
                    $stmtBorrow->execute([':id' => $newBorrowId]);
                    $borrowRow = $stmtBorrow->fetch();
                    audit('create', 'borrow_logs', $newBorrowId, $borrowRow);
                    // Notify the patron that their reservation has been converted to a borrow
                    $uidStmt2 = $pdo->prepare('SELECT id FROM users WHERE patron_id = :pid LIMIT 1');
                    $uidStmt2->execute([':pid' => $row['patron_id']]);
                    $uidBorrow = (int)$uidStmt2->fetchColumn();
                    if ($uidBorrow) {
                        notify_user($uidBorrow, null, 'borrow_created', 'Your reservation has been approved and recorded as a borrow', [
                            'borrow_log_id' => $newBorrowId,
                            'reservation_id' => $row['id'],
                            'book_id' => $row['book_id'],
                            'borrowed_at' => $borrowedAt,
                            'due_date' => $dueDate,
                        ]);
                    }
                }
            }
            audit('update','reservations', (int)$row['id'], $row);
        } elseif ($resource === 'borrow_logs') {
            if (($row['status'] ?? '') === 'returned') {
                $pdo->prepare('UPDATE books SET available_copies = available_copies + 1 WHERE id = :bid')->execute([':bid'=>$row['book_id']]);
                $late = compute_late_fee($row['due_date'] ?? null, $row['returned_at'] ?? null);
                $pdo->prepare('UPDATE borrow_logs SET late_fee = :f WHERE id = :id')->execute([':f'=>$late, ':id'=>$row['id']]);
                notify_user(null, 'librarian', 'returned', 'A book was returned', ['borrow_log_id'=>$row['id'],'late_fee'=>$late]);
            }
            audit('update','borrow_logs', (int)$row['id'], $row);
        } elseif ($resource === 'lost_damaged_reports') {
            $fee = compute_damage_fee($row['severity'] ?? 'minor');
            $pdo->prepare('UPDATE lost_damaged_reports SET fee_charged = :f WHERE id = :id')->execute([':f'=>$fee, ':id'=>$row['id']]);
            notify_user(null, 'librarian', 'report_update', 'A report was updated', ['report_id'=>$row['id'],'fee'=>$fee]);
            audit('update','lost_damaged_reports', (int)$row['id'], $row);
        } else {
            audit('update', $resource, (int)$row['id'], $row);
        }
        json_response($row);
        break;
    case 'DELETE':
        require_csrf();
        if (!$id) json_response(['error' => 'ID required'], 400);
        if (in_array($role, ['student','non_staff'], true)) {
            if (!in_array($resource, ['reservations','lost_damaged_reports'], true)) json_response(['error'=>'Forbidden'],403);
            $chk = $pdo->prepare('SELECT patron_id FROM ' . $conf['table'] . ' WHERE id = :id');
            $chk->execute([':id'=>$id]);
            $own = (int)$chk->fetchColumn();
            if ($own !== (int)($user['patron_id'] ?? 0)) json_response(['error' => 'Forbidden'], 403);
        }
        audit('delete', $resource, (int)$id);
        $stmt = $pdo->prepare('DELETE FROM ' . $conf['table'] . ' WHERE id = :id');
        $stmt->execute([':id' => $id]);
        json_response(['ok' => true]);
        break;
    default:
        json_response(['error' => 'Method not allowed'], 405);
}

// Helpers for fees
function compute_late_fee(?string $due, ?string $returned): float {
    if (!$due || !$returned) return 0.0;
    try {
        $d1 = new DateTime($due);
        $d2 = new DateTime($returned);
        if ($d2 <= $d1) return 0.0;
        $days = (int)$d1->diff($d2)->format('%a');
        $rate = (float)settings_get('late_fee_per_day', 10);
        return round($days * $rate, 2);
    } catch (Throwable $e) { return 0.0; }
}

function compute_damage_fee(string $severity): float {
    $minor = (float)settings_get('fee_minor', 50);
    $moderate = (float)settings_get('fee_moderate', 200);
    $severe = (float)settings_get('fee_severe', 1000);
    switch ($severity) {
        case 'moderate': return $moderate;
        case 'severe': return $severe;
        case 'minor':
        default: return $minor;
    }
}
?>
