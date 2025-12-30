<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/audit.php';

// -------------------------------------------------------------------------
// Robust error handling for the registration API.  Without these
// handlers, any PHP warnings or uncaught exceptions (e.g. database
// connection problems) will produce HTML output that the frontend cannot
// parse.  Convert all such issues to JSON responses.
set_exception_handler(function (Throwable $e): void {
    http_response_code(500);
    header('Content-Type: application/json');
    $msg = $e->getMessage();
    echo json_encode(['error' => 'Server error: ' . $msg], JSON_UNESCAPED_UNICODE);
    exit;
});
set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline): void {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

// Open endpoint to allow self-registration for Students and Non-Staff only.
// Accepts: { username, password, email, name, role } with role in ['student','non_staff']
// Creates a users record and a patrons record, linking users.patron_id.

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$body = read_json_body();
// Accept additional optional fields during registration.  Students and non‑staff
// may provide a phone number, physical address, academic semester and
// department.  These fields are stored either on the users table (phone,
// address) or on the patrons table (semester, department).  Trim all
// incoming string values to avoid leading/trailing whitespace.
$username   = trim((string)($body['username'] ?? ''));
$password   = (string)($body['password'] ?? '');
$email      = trim((string)($body['email'] ?? ''));
$name       = trim((string)($body['name'] ?? ''));
$role       = (string)($body['role'] ?? 'student');
$phone      = trim((string)($body['phone'] ?? ''));
$address    = trim((string)($body['address'] ?? ''));
$semester   = trim((string)($body['semester'] ?? ''));
$department = trim((string)($body['department'] ?? ''));

if (!$username || !$password || !$email) {
    json_response(['error' => 'username, password, and email are required'], 400);
}

if (!in_array($role, ['student','non_staff'], true)) {
    json_response(['error' => 'Registration allowed only for students and non_staff'], 403);
}

$pdo = DB::conn();
// ensure unique username
$exists = $pdo->prepare('SELECT 1 FROM users WHERE username = :u LIMIT 1');
$exists->execute([':u' => $username]);
if ($exists->fetchColumn()) {
    json_response(['error' => 'Username already taken'], 409);
}

// Create patron record.  In addition to the default fields, store
// optional academic details (semester, department) and address.  We
// include phone here for completeness although it is primarily stored
// on the users table.  Omitting null values allows the database
// defaults to apply.  Some deployments may not include the semester,
// department or address columns on the patrons table.  Attempting to
// insert values for these columns in such schemas will raise a
// SQLSTATE 42S22 (unknown column) error.  To maintain compatibility
// with older databases, wrap the insert in a try/catch and retry the
// insertion without the offending columns if necessary.
$libId = 'LIB' . random_int(100000, 999999);
// Build the column and parameter lists for inserting into patrons.  Include
// the mandatory fields first.  We'll append optional fields below.
$patronStmtFields = ['name','library_id','email','status'];
$patronValues = [
    ':name'   => ($name ?: $username),
    ':library_id' => $libId,
    ':email'   => $email,
    ':status' => 'active',
];
if ($phone !== '') { $patronStmtFields[] = 'phone'; $patronValues[':phone'] = $phone; }
if ($semester !== '') { $patronStmtFields[] = 'semester'; $patronValues[':semester'] = $semester; }
if ($department !== '') { $patronStmtFields[] = 'department'; $patronValues[':department'] = $department; }
if ($address !== '') { $patronStmtFields[] = 'address'; $patronValues[':address'] = $address; }
// Convert the field names into parameter names.  Since we prefix all
// parameter keys with a colon at insertion time (above), strip the colon
// when generating the placeholders.
$placeholders = [];
foreach ($patronStmtFields as $f) {
    $placeholders[] = ':' . $f;
}
// Build and execute the insert.  If the database does not contain one
// of the optional columns (semester or department), catch the
// exception and retry without that column.
$patronId = null;
try {
    $pstmt = $pdo->prepare('INSERT INTO patrons (' . implode(',', $patronStmtFields) . ') VALUES (' . implode(',', $placeholders) . ')');
    $pstmt->execute($patronValues);
    $patronId = (int)$pdo->lastInsertId();
} catch (\PDOException $e) {
    // Unknown column errors can manifest either via the SQLSTATE code 42S22
    // or through vendor specific error codes/messages (for example when
    // using SQLite or MariaDB).  To gracefully handle these scenarios and
    // maintain backwards compatibility with databases that lack newer
    // columns (phone, semester, department, address), detect the error
    // using both the SQLSTATE and the exception message.  We then
    // rebuild the INSERT excluding any optional columns that may not
    // exist on the target table.  This allows registrations to
    // succeed even when the database schema has not yet been upgraded.
    $sqlState = $e->getCode();
    $msg = $e->getMessage();
    if ($sqlState === '42S22' || strpos($msg, 'Unknown column') !== false || strpos($msg, 'no such column') !== false) {
        // List all optional columns on the patrons table.  These fields
        // may not exist in older installations, so we strip them on retry.
        $optionalCols = ['phone','semester','department','address'];
        $filteredFields = [];
        $filteredPlaceholders = [];
        $filteredValues = [];
        foreach ($patronStmtFields as $f) {
            if (in_array($f, $optionalCols, true)) continue;
            $filteredFields[] = $f;
            $filteredPlaceholders[] = ':' . $f;
            $paramName = ':' . $f;
            if (array_key_exists($paramName, $patronValues)) {
                $filteredValues[$paramName] = $patronValues[$paramName];
            }
        }
        if (empty($filteredFields)) {
            // If no fields remain, rethrow the original error since we
            // cannot perform a meaningful insert without mandatory columns.
            throw $e;
        }
        $stmtFallback = $pdo->prepare(
            'INSERT INTO patrons (' . implode(',', $filteredFields) . ') VALUES (' . implode(',', $filteredPlaceholders) . ')'
        );
        $stmtFallback->execute($filteredValues);
        $patronId = (int)$pdo->lastInsertId();
    } else {
        throw $e;
    }
}

// Create user record.  Persist the phone number and address if supplied.
$userFields = ['username','password_hash','email','name','role','status','patron_id'];
$userParams = [
    ':username' => $username,
    ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
    ':email' => $email,
    ':name' => ($name ?: $username),
    ':role' => $role,
    ':status' => 'active',
    ':patron_id' => $patronId,
];
if ($phone !== '') { $userFields[] = 'phone'; $userParams[':phone'] = $phone; }
if ($address !== '') { $userFields[] = 'address'; $userParams[':address'] = $address; }
$placeholdersU = [];
foreach ($userFields as $f) {
    $placeholdersU[] = ':' . $f;
}
try {
    $ustmt = $pdo->prepare('INSERT INTO users (' . implode(',', $userFields) . ') VALUES (' . implode(',', $placeholdersU) . ')');
    $ustmt->execute($userParams);
    $userId = (int)$pdo->lastInsertId();
} catch (\PDOException $e) {
    // Unknown column error when inserting into users.  Handle missing
    // optional columns (phone, address) similarly to the patrons insert.
    $sqlState = $e->getCode();
    $msg = $e->getMessage();
    if ($sqlState === '42S22' || strpos($msg, 'Unknown column') !== false || strpos($msg, 'no such column') !== false) {
        $optionalU = ['phone','address'];
        $filteredUF = [];
        $filteredUP = [];
        foreach ($userFields as $f) {
            if (in_array($f, $optionalU, true)) continue;
            $filteredUF[] = $f;
            $paramName = ':' . $f;
            if (array_key_exists($paramName, $userParams)) {
                $filteredUP[$paramName] = $userParams[$paramName];
            }
        }
        // Prepare the fallback statement with only the retained columns.
        $placeholdersUF = array_map(function($f){ return ':' . $f; }, $filteredUF);
        $stmtUFallback = $pdo->prepare(
            'INSERT INTO users (' . implode(',', $filteredUF) . ') VALUES (' . implode(',', $placeholdersUF) . ')'
        );
        $stmtUFallback->execute($filteredUP);
        $userId = (int)$pdo->lastInsertId();
    } else {
        throw $e;
    }
}

audit('register','users', $userId, ['username'=>$username, 'role'=>$role]);

json_response(['ok' => true, 'user_id' => $userId]);
?>

