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
        'fields' => ['username','email','name','phone','address','role','status','password'],
        'defaults' => ['status' => 'active'],
    ],
    'patrons' => [
        'table' => 'patrons',
        'fields' => ['name','library_id','email','phone','semester','department','address','membership_date','status'],
        'defaults' => ['status' => 'active'],
    ],
    'books' => [
        'table' => 'books',
        'fields' => ['title','author','isbn','category','category_id','publisher','year_published','description','is_active','total_copies_cache','available_copies_cache'],
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
        'fields' => ['book_id','book_copy_id','patron_id','reserved_at','status','expiration_date','reason','reservation_type','notes'],
        'defaults' => ['status' => 'pending'],
    ],
    'lost_damaged_reports' => [
        'table' => 'lost_damaged_reports',
        'fields' => ['book_id','patron_id','report_date','report_type','severity','description','fee_charged','status'],
        'defaults' => ['status' => 'pending'],
    ],
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
    'book_copies' => [
        'table' => 'book_copies',
        'fields' => ['book_id','copy_number','barcode','status','current_section','current_shelf','current_row','current_slot','acquisition_date','purchase_price','book_condition','notes','is_active'],
        'defaults' => ['status' => 'available', 'is_active' => 1],
    ],
    'library_map_config' => [
        'table' => 'library_map_config',
        'fields' => ['section','shelf_count','rows_per_shelf','slots_per_row','x_position','y_position','color','is_active'],
        'defaults' => ['is_active' => 1],
    ],
    'categories' => [
        'table' => 'categories',
        'fields' => ['name','description','section_code','default_section','shelf_recommendation','row_recommendation','slot_recommendation','is_active'],
        'defaults' => ['is_active' => 1],
    ],
];

// NEW: Special API endpoints for book details
if (in_array($resource, ['book-details', 'book-copies', 'book-locations', 'library-sections'])) {
    // Handle book details API endpoints
    $pdo = DB::conn();
    
    switch ($resource) {
        case 'book-details':
            if (!$id) {
                json_response(['error' => 'Book ID required'], 400);
            }
            
            try {
                // Get book details
                $stmt = $pdo->prepare("
                    SELECT b.*, c.name as category_name, c.default_section
                    FROM books b
                    LEFT JOIN categories c ON b.category_id = c.id
                    WHERE b.id = ? AND b.is_active = 1
                ");
                $stmt->execute([$id]);
                $book = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$book) {
                    json_response(['error' => 'Book not found'], 404);
                }
                
                // Get available copies count - FIXED: Properly count available copies
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) as available_count
                    FROM book_copies
                    WHERE book_id = ? AND status = 'available' AND is_active = 1
                ");
                $stmt->execute([$id]);
                $available = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Get total copies count
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) as total_count
                    FROM book_copies
                    WHERE book_id = ? AND is_active = 1
                ");
                $stmt->execute([$id]);
                $total = $stmt->fetch(PDO::FETCH_ASSOC);
                
                $book['available_copies'] = $available['available_count'] ?? 0;
                $book['total_copies'] = $total['total_count'] ?? 0;
                
                // Update cache if different
                if ($book['available_copies_cache'] != $book['available_copies'] || 
                    $book['total_copies_cache'] != $book['total_copies']) {
                    $updateStmt = $pdo->prepare("
                        UPDATE books 
                        SET available_copies_cache = ?, total_copies_cache = ?
                        WHERE id = ?
                    ");
                    $updateStmt->execute([
                        $book['available_copies'],
                        $book['total_copies'],
                        $id
                    ]);
                }
                
                json_response($book);
                
            } catch (Exception $e) {
                json_response(['error' => 'Database error: ' . $e->getMessage()], 500);
            }
            break;
            
        case 'book-copies':
            $book_id = isset($_GET['book_id']) ? intval($_GET['book_id']) : 0;
            if (!$book_id) {
                json_response(['error' => 'Book ID required'], 400);
            }
            
            try {
                $stmt = $pdo->prepare("
                    SELECT id, copy_number, barcode, status, 
                           current_section, current_shelf, current_row, current_slot,
                           book_condition
                    FROM book_copies
                    WHERE book_id = ? AND is_active = 1
                    ORDER BY copy_number
                ");
                $stmt->execute([$book_id]);
                $copies = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                json_response($copies);
                
            } catch (Exception $e) {
                json_response(['error' => 'Database error: ' . $e->getMessage()], 500);
            }
            break;
            
        case 'book-locations':
            $book_id = isset($_GET['book_id']) ? intval($_GET['book_id']) : 0;
            if (!$book_id) {
                json_response(['error' => 'Book ID required'], 400);
            }
            
            try {
                // Get sections where this book is located
                $stmt = $pdo->prepare("
                    SELECT DISTINCT bc.current_section
                    FROM book_copies bc
                    WHERE bc.book_id = ? AND bc.status = 'available' 
                          AND bc.current_section IS NOT NULL
                    ORDER BY bc.current_section
                ");
                $stmt->execute([$book_id]);
                $bookSections = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
                
                // Get all library sections
                $stmt = $pdo->query("
                    SELECT section, x_position as x, y_position as y, color
                    FROM library_map_config
                    WHERE is_active = 1
                    ORDER BY section
                ");
                $sections = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Add book location flag
                foreach ($sections as &$section) {
                    $section['containsBook'] = in_array($section['section'], $bookSections);
                }
                
                json_response([
                    'sections' => $sections,
                    'book_sections' => $bookSections
                ]);
                
            } catch (Exception $e) {
                json_response(['error' => 'Database error: ' . $e->getMessage()], 500);
            }
            break;
            
        case 'library-sections':
            try {
                $stmt = $pdo->query("
                    SELECT section, shelf_count, rows_per_shelf, slots_per_row, color
                    FROM library_map_config
                    WHERE is_active = 1
                    ORDER BY section
                ");
                $sections = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                json_response($sections);
                
            } catch (Exception $e) {
                json_response(['error' => 'Database error: ' . $e->getMessage()], 500);
            }
            break;
    }
    
    exit;
}

// NEW: Search endpoint for books - FIXED: Include proper available copies count
if ($resource === 'books' && isset($_GET['search'])) {
    $pdo = DB::conn();
    $search = $_GET['search'] ?? '';
    $category = $_GET['category'] ?? '';
    $available_only = isset($_GET['available_only']) ? $_GET['available_only'] : '';
    
    try {
        $query = "SELECT b.*, c.name as category_name FROM books b 
                  LEFT JOIN categories c ON b.category_id = c.id 
                  WHERE b.is_active = 1";
        
        $params = [];
        
        if (!empty($search)) {
            $query .= " AND (b.title LIKE ? OR b.author LIKE ? OR b.isbn LIKE ?)";
            $searchTerm = "%$search%";
            $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm]);
        }
        
        if (!empty($category)) {
            $query .= " AND b.category_id = ?";
            $params[] = $category;
        }
        
        if ($available_only === '1') {
            $query .= " AND b.available_copies_cache > 0";
        }
        
        $query .= " ORDER BY b.title";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $books = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Ensure available_copies_cache is accurate
        foreach ($books as &$book) {
            // Recalculate if cache seems off
            if ($book['available_copies_cache'] < 0 || $book['available_copies_cache'] > $book['total_copies_cache']) {
                $countStmt = $pdo->prepare("
                    SELECT COUNT(*) as available_count
                    FROM book_copies
                    WHERE book_id = ? AND status = 'available' AND is_active = 1
                ");
                $countStmt->execute([$book['id']]);
                $available = $countStmt->fetch(PDO::FETCH_ASSOC);
                
                $book['available_copies_cache'] = $available['available_count'] ?? 0;
                
                // Update cache
                $updateStmt = $pdo->prepare("
                    UPDATE books SET available_copies_cache = ? WHERE id = ?
                ");
                $updateStmt->execute([$book['available_copies_cache'], $book['id']]);
            }
        }
        
        json_response($books);
        
    } catch (Exception $e) {
        json_response(['error' => 'Database error: ' . $e->getMessage()], 500);
    }
    exit;
}

// NEW: User reservations endpoint
if ($resource === 'user-reservations') {
    $pdo = DB::conn();
    $user = current_user();
    
    if (!$user) {
        json_response(['error' => 'Not authenticated'], 401);
    }
    
    $patron_id = $user['patron_id'] ?? 0;
    
    if (!$patron_id) {
        json_response(['error' => 'No patron account linked'], 400);
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT r.*, 
                   b.title as book_title, 
                   b.author as book_author,
                   b.isbn as book_isbn,
                   bc.copy_number as copy_number,
                   bc.barcode as barcode,
                   bc.current_section,
                   bc.current_shelf,
                   bc.current_row,
                   bc.current_slot
            FROM reservations r
            LEFT JOIN books b ON r.book_id = b.id
            LEFT JOIN book_copies bc ON r.book_copy_id = bc.id
            WHERE r.patron_id = ? 
            ORDER BY r.reserved_at DESC
        ");
        $stmt->execute([$patron_id]);
        $reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        json_response($reservations);
        
    } catch (Exception $e) {
        json_response(['error' => 'Database error: ' . $e->getMessage()], 500);
    }
    exit;
}

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
if ($resource === 'reservations') {
    try {
        $pdo->query('SELECT reason, notes FROM reservations LIMIT 1');
    } catch (Throwable $ex) {
        $msg = $ex->getMessage();
        if (strpos($msg, '42S22') !== false || strpos($msg, '1054') !== false) {
            try {
                $pdo->exec('ALTER TABLE reservations ADD COLUMN reason VARCHAR(255) NULL AFTER expiration_date');
                $pdo->exec('ALTER TABLE reservations ADD COLUMN notes TEXT NULL AFTER reason');
            } catch (Throwable $e) {
                // Silently ignore if another request has already created it.
            }
        } else {
            throw $ex;
        }
    }
}

// -------------------------------------------------------------------------
// Automatically provision the ebook_requests table if it does not exist.
if ($resource === 'ebook_requests') {
    try {
        $pdo->query("SELECT 1 FROM ebook_requests LIMIT 1");
    } catch (Throwable $ex) {
        $msg = $ex->getMessage();
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
            
            // For books, ensure cache is accurate
            if ($resource === 'books' && $row) {
                // Recalculate available copies
                $availStmt = $pdo->prepare("
                    SELECT COUNT(*) as available_count
                    FROM book_copies
                    WHERE book_id = ? AND status = 'available' AND is_active = 1
                ");
                $availStmt->execute([$id]);
                $available = $availStmt->fetch(PDO::FETCH_ASSOC);
                
                $totalStmt = $pdo->prepare("
                    SELECT COUNT(*) as total_count
                    FROM book_copies
                    WHERE book_id = ? AND is_active = 1
                ");
                $totalStmt->execute([$id]);
                $total = $totalStmt->fetch(PDO::FETCH_ASSOC);
                
                $row['available_copies_cache'] = $available['available_count'] ?? 0;
                $row['total_copies_cache'] = $total['total_count'] ?? 0;
                
                // Update cache if different
                if ($row['available_copies_cache'] != ($row['available_copies_cache'] ?? 0) || 
                    $row['total_copies_cache'] != ($row['total_copies_cache'] ?? 0)) {
                    $updateStmt = $pdo->prepare("
                        UPDATE books 
                        SET available_copies_cache = ?, total_copies_cache = ?
                        WHERE id = ?
                    ");
                    $updateStmt->execute([
                        $row['available_copies_cache'],
                        $row['total_copies_cache'],
                        $id
                    ]);
                }
            }
            
            json_response($row ?: null);
        } else {
            if (in_array($role, ['student','non_staff'], true)) {
                // Students and non‑teaching staff can only query their own
                // reservations, borrow logs, lost/damaged reports and e‑book
                // access requests.
                if (in_array($resource, ['reservations','borrow_logs','lost_damaged_reports','ebook_requests'], true)) {
                    if ($resource === 'ebook_requests') {
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
                } elseif ($resource === 'books' || $resource === 'ebooks' || $resource === 'categories' || $resource === 'library_map_config') {
                    $stmt = $pdo->query('SELECT * FROM ' . $conf['table'] . ' ORDER BY id DESC');
                    $rows = $stmt->fetchAll();
                    
                    // For books, ensure cache is accurate
                    if ($resource === 'books') {
                        foreach ($rows as &$book) {
                            $availStmt = $pdo->prepare("
                                SELECT COUNT(*) as available_count
                                FROM book_copies
                                WHERE book_id = ? AND status = 'available' AND is_active = 1
                            ");
                            $availStmt->execute([$book['id']]);
                            $available = $availStmt->fetch(PDO::FETCH_ASSOC);
                            
                            $totalStmt = $pdo->prepare("
                                SELECT COUNT(*) as total_count
                                FROM book_copies
                                WHERE book_id = ? AND is_active = 1
                            ");
                            $totalStmt->execute([$book['id']]);
                            $total = $totalStmt->fetch(PDO::FETCH_ASSOC);
                            
                            $book['available_copies_cache'] = $available['available_count'] ?? 0;
                            $book['total_copies_cache'] = $total['total_count'] ?? 0;
                            
                            // Update cache if different
                            if ($book['available_copies_cache'] != ($book['available_copies_cache'] ?? 0) || 
                                $book['total_copies_cache'] != ($book['total_copies_cache'] ?? 0)) {
                                $updateStmt = $pdo->prepare("
                                    UPDATE books 
                                    SET available_copies_cache = ?, total_copies_cache = ?
                                    WHERE id = ?
                                ");
                                $updateStmt->execute([
                                    $book['available_copies_cache'],
                                    $book['total_copies_cache'],
                                    $book['id']
                                ]);
                            }
                        }
                        unset($book);
                    }
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
                
                // For books, ensure cache is accurate
                if ($resource === 'books') {
                    foreach ($rows as &$book) {
                        $availStmt = $pdo->prepare("
                            SELECT COUNT(*) as available_count
                            FROM book_copies
                            WHERE book_id = ? AND status = 'available' AND is_active = 1
                        ");
                        $availStmt->execute([$book['id']]);
                        $available = $availStmt->fetch(PDO::FETCH_ASSOC);
                        
                        $totalStmt = $pdo->prepare("
                            SELECT COUNT(*) as total_count
                            FROM book_copies
                            WHERE book_id = ? AND is_active = 1
                        ");
                        $totalStmt->execute([$book['id']]);
                        $total = $totalStmt->fetch(PDO::FETCH_ASSOC);
                        
                        $book['available_copies_cache'] = $available['available_count'] ?? 0;
                        $book['total_copies_cache'] = $total['total_count'] ?? 0;
                        
                        // Update cache if different
                        if ($book['available_copies_cache'] != ($book['available_copies_cache'] ?? 0) || 
                            $book['total_copies_cache'] != ($book['total_copies_cache'] ?? 0)) {
                            $updateStmt = $pdo->prepare("
                                UPDATE books 
                                SET available_copies_cache = ?, total_copies_cache = ?
                                WHERE id = ?
                            ");
                            $updateStmt->execute([
                                $book['available_copies_cache'],
                                $book['total_copies_cache'],
                                $book['id']
                            ]);
                        }
                    }
                    unset($book);
                }
            }
            
            // Augment rows with user names when a patron_id column exists
            if (!empty($rows) && isset($rows[0]) && array_key_exists('patron_id', $rows[0])) {
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
                    $placeholders = implode(',', array_fill(0, count($patronIds), '?'));
                    $uq = $pdo->prepare("SELECT patron_id, COALESCE(name, '') AS name, username FROM users WHERE patron_id IN ($placeholders)");
                    $uq->execute($patronIds);
                    foreach ($uq->fetchAll() as $m) {
                        $pidKey = (int)$m['patron_id'];
                        $userMap[$pidKey] = $m['name'];
                        $usernameMap[$pidKey] = $m['username'];
                    }
                    
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
        
        // For books resource, handle initial_copies separately
        $initial_copies_data = null;
        if ($resource === 'books' && isset($data['initial_copies'])) {
            $initial_copies_data = $data['initial_copies'];
            unset($data['initial_copies']);
        }
        
        // Remove null values so that database defaults are used
        if (is_array($data)) {
            $data = array_filter($data, function ($v) {
                return $v !== null;
            });
            
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
        
        // Special handling for password (users)
        if ($resource === 'users' && !empty($data['password'])) {
            $data['password_hash'] = password_hash($data['password'], PASSWORD_DEFAULT);
            unset($data['password']);
        }
        
        // student/non_staff: enforce ownership and populate identifying fields
        if (in_array($role, ['student','non_staff'], true)) {
            if (in_array($resource, ['reservations','borrow_logs','lost_damaged_reports','ebook_requests'], true)) {
                if ($resource === 'ebook_requests') {
                    $data['username'] = $user['username'] ?? '';
                    unset($data['patron_id']);
                } else {
                    $data['patron_id'] = (int)($user['patron_id'] ?? 0);
                }
            } else {
                json_response(['error' => 'Forbidden'], 403);
            }
        }
        
        // Defaults for borrow logs
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
            $avail = $pdo->prepare('SELECT available_copies_cache FROM books WHERE id = :id');
            $avail->execute([':id'=>(int)$data['book_id']]);
            $available = (int)$avail->fetchColumn();
            if ($available < 1) json_response(['error'=>'Book not available'],422);
        }
        
        // Validate reservations
        if ($resource === 'reservations') {
            if (empty($data['book_id'])) {
                json_response(['error' => 'book_id required'], 422);
            }
            
            // Check if book exists and get its details
            $chkBook = $pdo->prepare('SELECT id, available_copies_cache, total_copies_cache FROM books WHERE id = :id');
            $chkBook->execute([':id' => (int)$data['book_id']]);
            $bookRow = $chkBook->fetch();
            if (!$bookRow) {
                json_response(['error' => 'Invalid book_id'], 422);
            }
            
            // If a specific copy is selected, check its status
            if (!empty($data['book_copy_id'])) {
                $chkCopy = $pdo->prepare('SELECT id, status, book_id FROM book_copies WHERE id = :copy_id');
                $chkCopy->execute([':copy_id' => (int)$data['book_copy_id']]);
                $copyRow = $chkCopy->fetch();
                
                if (!$copyRow) {
                    json_response(['error' => 'Invalid copy selected'], 422);
                }
                
                if ($copyRow['book_id'] != $data['book_id']) {
                    json_response(['error' => 'Selected copy does not belong to this book'], 422);
                }
                
                if ($copyRow['status'] !== 'available') {
                    json_response(['error' => 'Selected copy is not available'], 422);
                }
                
                // Update the copy status to 'reserved'
                $pdo->prepare('UPDATE book_copies SET status = "reserved" WHERE id = :cid')
                    ->execute([':cid' => (int)$data['book_copy_id']]);
                    
                // Update available count - reservation makes copy unavailable
                $pdo->prepare('UPDATE books SET available_copies_cache = GREATEST(available_copies_cache - 1, 0) WHERE id = :bid')
                    ->execute([':bid' => (int)$data['book_id']]);
            } else {
                // If no specific copy, just check general availability
                if ((int)$bookRow['available_copies_cache'] < 1) {
                    json_response(['error' => 'No available copies for reservation'], 422);
                }
                // Reduce available count by 1 for general reservation
                $pdo->prepare('UPDATE books SET available_copies_cache = GREATEST(available_copies_cache - 1, 0) WHERE id = :bid')
                    ->execute([':bid' => (int)$data['book_id']]);
            }
            
            // Set default reservation date if not provided
            if (empty($data['reserved_at'])) {
                $data['reserved_at'] = (new DateTime())->format('Y-m-d H:i:s');
            }
        }
        
        $fields = array_intersect(array_keys($data), $conf['fields']);
        $insert = array_merge($conf['defaults'] ?? [], array_intersect_key($data, array_flip($fields)));
        
        // Ensure notes field is included if present in data
        if (isset($data['notes']) && !in_array('notes', $fields)) {
            $insert['notes'] = $data['notes'];
        }
        
        if (empty($insert)) json_response(['error' => 'No valid fields'], 422);
        $cols = array_keys($insert);
        $params = array_map(function ($c) {
            return ':' . $c;
        }, $cols);
        $sql = 'INSERT INTO ' . $conf['table'] . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', $params) . ')';
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_combine($params, array_values($insert)));
        $newId = (int)$pdo->lastInsertId();
        
        // SPECIAL HANDLING FOR BOOKS: Create initial copies if provided
        if ($resource === 'books' && $initial_copies_data && $initial_copies_data['count'] > 0) {
            try {
                $pdo->beginTransaction();
                
                $count = (int)$initial_copies_data['count'];
                $condition = $initial_copies_data['condition'] ?? 'good';
                $notes = $initial_copies_data['notes'] ?? '';
                
                // Get the book title for copy number prefix
                $bookStmt = $pdo->prepare("SELECT title FROM books WHERE id = ?");
                $bookStmt->execute([$newId]);
                $book = $bookStmt->fetch();
                
                if ($book) {
                    $prefix = strtoupper(substr($book['title'], 0, 3));
                    
                    // Find the highest copy number for this book
                    $stmt = $pdo->prepare("SELECT MAX(CAST(SUBSTRING(copy_number, LENGTH(?)+1) AS UNSIGNED)) as max_num 
                                          FROM book_copies 
                                          WHERE book_id = ? AND copy_number LIKE ?");
                    $stmt->execute([$prefix, $newId, $prefix . '%']);
                    $result = $stmt->fetch();
                    $start_num = $result['max_num'] ? $result['max_num'] + 1 : 1;
                    
                    // Generate copies
                    for ($i = 0; $i < $count; $i++) {
                        $copy_number = $prefix . str_pad($start_num + $i, 3, '0', STR_PAD_LEFT);
                        $barcode = 'LIB-' . $newId . '-' . str_pad($start_num + $i, 3, '0', STR_PAD_LEFT);
                        
                        $stmt = $pdo->prepare("
                            INSERT INTO book_copies 
                            (book_id, copy_number, barcode, status, book_condition, notes, is_active)
                            VALUES (?, ?, ?, 'available', ?, ?, 1)
                        ");
                        
                        $stmt->execute([
                            $newId,
                            $copy_number,
                            $barcode,
                            $condition,
                            $notes
                        ]);
                        
                        $copy_id = $pdo->lastInsertId();
                        
                        // Log the transaction
                        $stmt = $pdo->prepare("
                            INSERT INTO copy_transactions 
                            (book_copy_id, transaction_type, from_status, to_status, notes)
                            VALUES (?, 'acquired', NULL, 'available', ?)
                        ");
                        $stmt->execute([$copy_id, 'New copy added: ' . $notes]);
                    }
                }
                
                $pdo->commit();
                
                // Update the book's cache counts
                $updateStmt = $pdo->prepare("
                    UPDATE books 
                    SET total_copies_cache = (SELECT COUNT(*) FROM book_copies WHERE book_id = ?),
                        available_copies_cache = (SELECT COUNT(*) FROM book_copies WHERE book_id = ? AND status = 'available')
                    WHERE id = ?
                ");
                $updateStmt->execute([$newId, $newId, $newId]);
                
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log("Failed to create initial copies: " . $e->getMessage());
            }
        }
        
        $stmt = $pdo->prepare('SELECT * FROM ' . $conf['table'] . ' WHERE id = :id');
        $stmt->execute([':id' => $newId]);
        $row = $stmt->fetch();
        
        // Post-insert hooks
        if ($resource === 'borrow_logs') {
            if (($row['status'] ?? 'borrowed') === 'borrowed') {
                // Update available copies count
                $pdo->prepare('UPDATE books SET available_copies_cache = GREATEST(available_copies_cache - 1, 0) WHERE id = :bid')
                    ->execute([':bid'=>$row['book_id']]);
                    
                // Update the book copy status to borrowed
                if (isset($data['book_copy_id'])) {
                    $pdo->prepare('UPDATE book_copies SET status = "borrowed" WHERE id = :cid')
                        ->execute([':cid' => $data['book_copy_id']]);
                }
                
                notify_user(null, 'librarian', 'borrowed', 'A book was borrowed', ['borrow_log_id'=>$row['id']]);
            }
            audit('create','borrow_logs', (int)$row['id'], $row);
        } elseif ($resource === 'lost_damaged_reports') {
            $fee = compute_damage_fee($row['severity'] ?? 'minor');
            $pdo->prepare('UPDATE lost_damaged_reports SET fee_charged = :f WHERE id = :id')->execute([':f'=>$fee, ':id'=>$row['id']]);
            notify_user(null, 'librarian', 'report', 'A lost/damaged report was filed', ['report_id'=>$row['id']]);
            audit('create','lost_damaged_reports', (int)$row['id'], $row);
        } elseif ($resource === 'reservations') {
            // Log the copy transaction if a specific copy was reserved
            if (!empty($data['book_copy_id'])) {
                $transStmt = $pdo->prepare("
                    INSERT INTO copy_transactions 
                    (book_copy_id, transaction_type, from_status, to_status, notes)
                    VALUES (?, 'reserved', 'available', 'reserved', ?)
                ");
                $transStmt->execute([
                    (int)$data['book_copy_id'],
                    'Reserved by patron #' . $row['patron_id'] . ' (Reservation ID: ' . $row['id'] . ')'
                ]);
            }
            
            // When a student creates a reservation, notify staff for approval
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
            if (!in_array($resource, ['reservations','borrow_logs','lost_damaged_reports','ebook_requests'], true)) {
                json_response(['error' => 'Forbidden'], 403);
            }
            if ($resource === 'ebook_requests') {
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
        
        // Normalize datetime-local inputs on updates
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
        
        // Capture the current state before applying updates
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
        
        // For reservations, handle status changes (especially cancellations)
        if ($resource === 'reservations') {
            if ($prevReservation && isset($data['status']) && ($prevReservation['status'] ?? '') !== ($row['status'] ?? '')) {
                // If reservation is cancelled or declined, return the copy to available status
                if (in_array($row['status'], ['cancelled', 'declined', 'expired']) && in_array($prevReservation['status'], ['pending', 'approved'])) {
                    if (!empty($prevReservation['book_copy_id'])) {
                        // Update book copy status back to available
                        $pdo->prepare('UPDATE book_copies SET status = "available" WHERE id = :cid')
                            ->execute([':cid' => (int)$prevReservation['book_copy_id']]);
                            
                        // Increase available count
                        $pdo->prepare('UPDATE books SET available_copies_cache = available_copies_cache + 1 WHERE id = :bid')
                            ->execute([':bid' => (int)$prevReservation['book_id']]);
                            
                        // Log the transaction
                        $transStmt = $pdo->prepare("
                            INSERT INTO copy_transactions 
                            (book_copy_id, transaction_type, from_status, to_status, notes)
                            VALUES (?, 'cancelled', 'reserved', 'available', ?)
                        ");
                        $transStmt->execute([
                            (int)$prevReservation['book_copy_id'],
                            'Reservation cancelled (ID: ' . $row['id'] . ')'
                        ]);
                    } else {
                        // For general reservation (no specific copy), still increase available count
                        $pdo->prepare('UPDATE books SET available_copies_cache = available_copies_cache + 1 WHERE id = :bid')
                            ->execute([':bid' => (int)$prevReservation['book_id']]);
                    }
                }
                
                // Notify user about status change
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

            // Automatically convert an approved reservation into a borrow entry
            if ($prevReservation && isset($data['status']) && ($row['status'] ?? '') === 'approved' && ($prevReservation['status'] ?? '') !== 'approved') {
                $borrowedAt = $row['reserved_at'] ?? null;
                if (!$borrowedAt) {
                    $borrowedAt = (new DateTime())->format('Y-m-d H:i:s');
                }
                
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
                        ':notes' => 'Reservation ID ' . $row['id'] . ($row['notes'] ? ' - ' . $row['notes'] : ''),
                    ]);
                    $newBorrowId = (int)$pdo->lastInsertId();
                    
                    // Update book copy status from 'reserved' to 'borrowed' if it was reserved
                    if (!empty($prevReservation['book_copy_id'])) {
                        $pdo->prepare('UPDATE book_copies SET status = "borrowed" WHERE id = :cid')
                            ->execute([':cid' => (int)$prevReservation['book_copy_id']]);
                    }
                    
                    // Retrieve the inserted borrow row for auditing and notifications
                    $stmtBorrow = $pdo->prepare('SELECT * FROM borrow_logs WHERE id = :id');
                    $stmtBorrow->execute([':id' => $newBorrowId]);
                    $borrowRow = $stmtBorrow->fetch();
                    audit('create', 'borrow_logs', $newBorrowId, $borrowRow);
                    
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
                // Update available copies count
                $pdo->prepare('UPDATE books SET available_copies_cache = available_copies_cache + 1 WHERE id = :bid')
                    ->execute([':bid'=>$row['book_id']]);
                    
                // Update book copy status back to available
                if (!empty($data['book_copy_id'])) {
                    $pdo->prepare('UPDATE book_copies SET status = "available" WHERE id = :cid')
                        ->execute([':cid' => (int)$data['book_copy_id']]);
                }
                    
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
        
        // For reservations, return the book copy to available status before deletion
        if ($resource === 'reservations') {
            $reservation = $pdo->prepare('SELECT book_id, book_copy_id, status FROM reservations WHERE id = :id');
            $reservation->execute([':id' => $id]);
            $resData = $reservation->fetch();
            
            if ($resData) {
                // Update book copy status back to available if specific copy was reserved
                if (!empty($resData['book_copy_id'])) {
                    $pdo->prepare('UPDATE book_copies SET status = "available" WHERE id = :cid')
                        ->execute([':cid' => (int)$resData['book_copy_id']]);
                }
                
                // Increase available count
                $pdo->prepare('UPDATE books SET available_copies_cache = available_copies_cache + 1 WHERE id = :bid')
                    ->execute([':bid' => (int)$resData['book_id']]);
            }
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