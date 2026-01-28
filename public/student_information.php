<?php
// Enhanced Library Attendance and Borrowing Management System
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/audit.php';
require_login();
$pdo = DB::conn();

// Add settings helper function if not exists
if (!function_exists('settings_get')) {
    function settings_get($key, $default = null) {
        global $pdo;
        try {
            $stmt = $pdo->prepare("SELECT value FROM settings WHERE `key` = ?");
            $stmt->execute([$key]);
            $result = $stmt->fetchColumn();
            return $result !== false ? $result : $default;
        } catch (Exception $e) {
            return $default;
        }
    }
}

// Check if library_attendance table exists, create if not
try {
    $pdo->query("SELECT 1 FROM library_attendance LIMIT 1");
} catch (PDOException $e) {
    $createTableSQL = "
    CREATE TABLE IF NOT EXISTS library_attendance (
        id INT AUTO_INCREMENT PRIMARY KEY,
        patron_id INT NOT NULL,
        library_id VARCHAR(32) NOT NULL,
        entry_time DATETIME DEFAULT CURRENT_TIMESTAMP,
        exit_time DATETIME NULL,
        status ENUM('in_library','left') DEFAULT 'in_library',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_library_id (library_id),
        INDEX idx_entry_time (entry_time),
        INDEX idx_status (status),
        FOREIGN KEY (patron_id) REFERENCES patrons(id) ON DELETE CASCADE
    )";
    $pdo->exec($createTableSQL);
}

// Get default book cover path
$default_cover = '../assets/default-book.jpg';
if (!file_exists($default_cover)) {
    $default_cover = 'data:image/svg+xml;base64,' . base64_encode('
        <svg xmlns="http://www.w3.org/2000/svg" width="200" height="300" viewBox="0 0 200 300">
            <rect width="200" height="300" fill="#f3f4f6"/>
            <rect x="20" y="40" width="160" height="220" fill="white" stroke="#d1d5db" stroke-width="2"/>
            <rect x="40" y="70" width="120" height="30" fill="#e5e7eb"/>
            <rect x="40" y="110" width="100" height="20" fill="#e5e7eb"/>
            <rect x="40" y="140" width="80" height="20" fill="#e5e7eb"/>
            <rect x="170" y="40" width="10" height="220" fill="#d1d5db"/>
            <text x="100" y="190" text-anchor="middle" fill="#9ca3af" font-size="14">No Cover</text>
        </svg>
    ');
}

// Process form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'enter_library':
                if (!empty($_POST['library_id'])) {
                    $library_id = trim($_POST['library_id']);
                    
                    // Check if patron exists
                    $stmt = $pdo->prepare("SELECT id, name FROM patrons WHERE library_id = ?");
                    $stmt->execute([$library_id]);
                    $patron = $stmt->fetch();
                    
                    if ($patron) {
                        // Check if already in library
                        $stmt = $pdo->prepare("SELECT id FROM library_attendance WHERE library_id = ? AND status = 'in_library'");
                        $stmt->execute([$library_id]);
                        $existing = $stmt->fetch();
                        
                        if (!$existing) {
                            $stmt = $pdo->prepare("INSERT INTO library_attendance (patron_id, library_id, status) VALUES (?, ?, 'in_library')");
                            $stmt->execute([$patron['id'], $library_id]);
                            $attendance_id = $pdo->lastInsertId();
                            audit('create', 'library_attendance', $attendance_id, [
                                'library_id' => $library_id,
                                'patron_id' => $patron['id']
                            ]);
                            $success_msg = "✅ " . htmlspecialchars($patron['name']) . " entered library successfully!";
                        } else {
                            $error_msg = "⚠️ Student is already in the library!";
                        }
                    } else {
                        $error_msg = "❌ Invalid Library ID! Student not found.";
                    }
                }
                break;
                
            case 'exit_library':
                if (!empty($_POST['library_id'])) {
                    $library_id = trim($_POST['library_id']);
                    
                    // Get patron name for message
                    $stmt = $pdo->prepare("SELECT p.name FROM library_attendance la JOIN patrons p ON la.patron_id = p.id WHERE la.library_id = ? AND la.status = 'in_library'");
                    $stmt->execute([$library_id]);
                    $patron_name = $stmt->fetchColumn();
                    
                    $stmt = $pdo->prepare("UPDATE library_attendance SET exit_time = NOW(), status = 'left' WHERE library_id = ? AND status = 'in_library'");
                    $stmt->execute([$library_id]);
                    
                    if ($stmt->rowCount() > 0) {
                        audit('update', 'library_attendance', 0, [
                            'library_id' => $library_id, 
                            'action' => 'exit'
                        ]);
                        $success_msg = "👋 " . htmlspecialchars($patron_name ?: 'Student') . " exited library successfully!";
                    } else {
                        $error_msg = "⚠️ No active entry found for this Library ID!";
                    }
                }
                break;
                
            case 'borrow_book':
                if (!empty($_POST['library_id']) && !empty($_POST['copy_number']) && isset($_POST['borrow_days'])) {
                    $library_id = trim($_POST['library_id']);
                    $copy_number = trim($_POST['copy_number']);
                    $borrow_days = intval($_POST['borrow_days']);
                    
                    // Validate borrow days
                    if ($borrow_days < 1 || $borrow_days > 7) {
                        $borrow_error = "❌ Borrow period must be between 1 and 7 days!";
                        break;
                    }
                    
                    try {
                        $pdo->beginTransaction();
                        
                        // Get patron info
                        $stmt = $pdo->prepare("SELECT id, name, department, semester FROM patrons WHERE library_id = ?");
                        $stmt->execute([$library_id]);
                        $patron = $stmt->fetch();
                        
                        if (!$patron) {
                            throw new Exception("❌ Invalid Library ID! Student not found.");
                        }
                        
                        // Get book copy info with cover image
                        $stmt = $pdo->prepare("
                            SELECT bc.*, b.title, b.author, b.isbn, b.cover_image, b.cover_image_cache, b.category_id, 
                                   b.description, b.publisher, b.year_published, b.category
                            FROM book_copies bc
                            JOIN books b ON bc.book_id = b.id
                            WHERE bc.copy_number = ? AND bc.is_active = 1
                        ");
                        $stmt->execute([$copy_number]);
                        $book_copy = $stmt->fetch();
                        
                        if (!$book_copy) {
                            throw new Exception("❌ Invalid Copy Number! Book not found.");
                        }
                        
                        // Check if copy is available (not reserved or borrowed)
                        if ($book_copy['status'] !== 'available') {
                            $status_text = ucfirst($book_copy['status']);
                            throw new Exception("⚠️ This book copy is currently <strong>{$status_text}</strong>!");
                        }
                        
                        // ADDITIONAL CHECK: Check if this specific copy is already borrowed
                        $stmt = $pdo->prepare("
                            SELECT COUNT(*) as already_borrowed 
                            FROM borrow_logs 
                            WHERE book_copy_id = ? AND status IN ('borrowed', 'overdue')
                        ");
                        $stmt->execute([$book_copy['id']]);
                        $already_borrowed = $stmt->fetch()['already_borrowed'];
                        
                        if ($already_borrowed > 0) {
                            throw new Exception("⚠️ This specific book copy is already borrowed by another student!");
                        }
                        
                        // Check if student is in library
                        $stmt = $pdo->prepare("SELECT id FROM library_attendance WHERE patron_id = ? AND status = 'in_library'");
                        $stmt->execute([$patron['id']]);
                        $in_library = $stmt->fetch();
                        
                        if (!$in_library) {
                            throw new Exception("⚠️ Student must be in the library to borrow books!");
                        }
                        
                        // Check if student has overdue books
                        $stmt = $pdo->prepare("SELECT COUNT(*) as overdue_count FROM borrow_logs WHERE patron_id = ? AND status = 'overdue'");
                        $stmt->execute([$patron['id']]);
                        $overdue_count = $stmt->fetch()['overdue_count'];
                        
                        if ($overdue_count > 0) {
                            throw new Exception("⚠️ Student has {$overdue_count} overdue book(s). Clear dues first!");
                        }
                        
                        // Check borrowing limit
                        $stmt = $pdo->prepare("SELECT COUNT(*) as active_borrows FROM borrow_logs WHERE patron_id = ? AND status IN ('borrowed', 'overdue')");
                        $stmt->execute([$patron['id']]);
                        $active_borrows = $stmt->fetch()['active_borrows'];
                        $max_borrows = 5; // Maximum books a student can borrow
                        
                        if ($active_borrows >= $max_borrows) {
                            throw new Exception("⚠️ Student has reached the maximum borrowing limit ({$max_borrows} books)!");
                        }
                        
                        // Check if student already has this specific book (same book_id) borrowed
                        // This prevents borrowing multiple copies of the same book
                        $stmt = $pdo->prepare("
                            SELECT COUNT(*) as same_book_count 
                            FROM borrow_logs bl
                            JOIN book_copies bc ON bl.book_copy_id = bc.id
                            WHERE bl.patron_id = ? 
                            AND bl.status IN ('borrowed', 'overdue')
                            AND bc.book_id = ?
                        ");
                        $stmt->execute([$patron['id'], $book_copy['book_id']]);
                        $same_book_count = $stmt->fetch()['same_book_count'];
                        
                        if ($same_book_count > 0) {
                            throw new Exception("⚠️ Student already has a copy of this book borrowed!");
                        }
                        
                        // Calculate dates
                        $borrowed_at = date('Y-m-d H:i:s');
                        $due_date = date('Y-m-d H:i:s', strtotime("+{$borrow_days} days"));
                        
                        // Create borrow log
                        $stmt = $pdo->prepare("
                            INSERT INTO borrow_logs 
                            (book_id, book_copy_id, patron_id, borrowed_at, due_date, status, notes)
                            VALUES (?, ?, ?, ?, ?, 'borrowed', ?)
                        ");
                        $notes = "Borrowed in-library for {$borrow_days} days. Student: " . $patron['name'] . " (Library ID: {$library_id})";
                        $stmt->execute([
                            $book_copy['book_id'],
                            $book_copy['id'],
                            $patron['id'],
                            $borrowed_at,
                            $due_date,
                            $notes
                        ]);
                        $borrow_id = $pdo->lastInsertId();
                        
                        // Update book copy status
                        $stmt = $pdo->prepare("UPDATE book_copies SET status = 'borrowed' WHERE id = ?");
                        $stmt->execute([$book_copy['id']]);
                        
                        // Update book available count
                        $stmt = $pdo->prepare("
                            UPDATE books 
                            SET available_copies_cache = available_copies_cache - 1 
                            WHERE id = ? AND available_copies_cache > 0
                        ");
                        $stmt->execute([$book_copy['book_id']]);
                        
                        // Log copy transaction
                        $stmt = $pdo->prepare("
                            INSERT INTO copy_transactions 
                            (book_copy_id, transaction_type, patron_id, from_status, to_status, notes)
                            VALUES (?, 'borrowed', ?, 'available', 'borrowed', ?)
                        ");
                        $stmt->execute([
                            $book_copy['id'],
                            $patron['id'],
                            "Borrowed in-library for {$borrow_days} days by patron #{$patron['id']} (Library ID: {$library_id})"
                        ]);
                        
                        // Create notification for student
                        $stmt = $pdo->prepare("
                            INSERT INTO notifications (user_id, type, message, meta)
                            SELECT u.id, 'borrow_created', ?, ?
                            FROM users u WHERE u.patron_id = ?
                        ");
                        $message = "You borrowed '{$book_copy['title']}' (Copy: {$book_copy['copy_number']}) for {$borrow_days} days";
                        $meta = json_encode([
                            'borrow_log_id' => $borrow_id,
                            'book_id' => $book_copy['book_id'],
                            'book_copy_id' => $book_copy['id'],
                            'due_date' => $due_date
                        ]);
                        $stmt->execute([$message, $meta, $patron['id']]);
                        
                        // Audit and notifications
                        audit('create', 'borrow_logs', $borrow_id, [
                            'patron_id' => $patron['id'],
                            'book_copy_id' => $book_copy['id'],
                            'library_id' => $library_id,
                            'borrow_days' => $borrow_days
                        ]);
                        
                        $pdo->commit();
                        
                        // Get book cover image - check both cover_image and cover_image_cache
                        $cover_image = $default_cover;
                        if (!empty($book_copy['cover_image_cache']) && $book_copy['cover_image_cache'] !== 'null') {
                            $cover_path = "../uploads/covers/" . $book_copy['cover_image_cache'];
                            if (file_exists($cover_path)) {
                                $cover_image = $cover_path;
                            }
                        } elseif (!empty($book_copy['cover_image']) && $book_copy['cover_image'] !== 'null') {
                            $cover_path = "../uploads/covers/" . $book_copy['cover_image'];
                            if (file_exists($cover_path)) {
                                $cover_image = $cover_path;
                            }
                        }
                        
                        // Get current location if available
                        $location = '';
                        if ($book_copy['current_section']) {
                            $location = "{$book_copy['current_section']}-S{$book_copy['current_shelf']}-R{$book_copy['current_row']}-P{$book_copy['current_slot']}";
                        }
                        
                        $borrow_success = [
                            'title' => "✅ Book Borrowed Successfully!",
                            'message' => "<strong>{$book_copy['title']}</strong> has been borrowed by <strong>{$patron['name']}</strong> for <strong>{$borrow_days} days</strong>",
                            'details' => [
                                'Copy Number' => $book_copy['copy_number'],
                                'Book Title' => $book_copy['title'],
                                'Author' => $book_copy['author'],
                                'ISBN' => $book_copy['isbn'] ?? 'N/A',
                                'Category' => $book_copy['category'] ?? 'N/A',
                                'Publisher' => $book_copy['publisher'] ?? 'N/A',
                                'Year' => $book_copy['year_published'] ?? 'N/A',
                                'Borrowed Date' => date('F j, Y g:i A', strtotime($borrowed_at)),
                                'Due Date' => date('F j, Y g:i A', strtotime($due_date)),
                                'Borrow Period' => "{$borrow_days} days",
                                'Book Condition' => ucfirst($book_copy['book_condition']),
                                'Location' => $location ?: 'Not specified',
                                'Library ID' => $library_id,
                                'Student Name' => $patron['name'],
                                'Department' => $patron['department'] ?? 'N/A',
                                'Semester' => $patron['semester'] ?? 'N/A'
                            ],
                            'cover_image' => $cover_image
                        ];
                        
                    } catch (PDOException $e) {
                        $pdo->rollBack();
                        
                        // Check for specific SQL errors
                        if (strpos($e->getMessage(), 'This specific book copy is already borrowed') !== false) {
                            $borrow_error = "⚠️ This specific book copy is already borrowed by another student!";
                        } elseif (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                            $borrow_error = "⚠️ Student already has this book borrowed!";
                        } else {
                            $borrow_error = "❌ Database error: " . $e->getMessage();
                        }
                    } catch (Exception $e) {
                        $pdo->rollBack();
                        $borrow_error = $e->getMessage();
                    }
                } else {
                    $borrow_error = "❌ Please fill all required fields!";
                }
                break;
        }
    }
}

// Get current students in library with filtering
$current_filter_name = $_GET['current_filter_name'] ?? '';
$current_filter_library_id = $_GET['current_filter_library_id'] ?? '';

$current_query = "
    SELECT la.*, p.name, p.library_id, p.department, p.semester,
           TIMEDIFF(NOW(), la.entry_time) as duration
    FROM library_attendance la
    JOIN patrons p ON la.patron_id = p.id
    WHERE la.status = 'in_library'
";

$current_params = [];

if ($current_filter_name) {
    $current_query .= " AND p.name LIKE ?";
    $current_params[] = "%$current_filter_name%";
}

if ($current_filter_library_id) {
    $current_query .= " AND p.library_id LIKE ?";
    $current_params[] = "%$current_filter_library_id%";
}

$current_query .= " ORDER BY la.entry_time DESC";

$stmt = $pdo->prepare($current_query);
$stmt->execute($current_params);
$students_in_library = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate average time in library
$avg_time = '00:00:00';
if (!empty($students_in_library)) {
    $total_seconds = 0;
    foreach ($students_in_library as $student) {
        list($hours, $minutes, $seconds) = explode(':', $student['duration']);
        $total_seconds += ($hours * 3600) + ($minutes * 60) + $seconds;
    }
    $avg_seconds = floor($total_seconds / count($students_in_library));
    $avg_hours = floor($avg_seconds / 3600);
    $avg_minutes = floor(($avg_seconds % 3600) / 60);
    $avg_seconds = $avg_seconds % 60;
    $avg_time = sprintf('%02d:%02d:%02d', $avg_hours, $avg_minutes, $avg_seconds);
}

// Get attendance history with pagination (5 records per page)
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 5; // Changed from 20 to 5
$offset = ($page - 1) * $limit;

// Get filter parameters for history
$filter_library_id = $_GET['filter_library_id'] ?? '';
$filter_date = $_GET['filter_date'] ?? '';
$filter_status = $_GET['filter_status'] ?? '';

// Build query for attendance history
$query = "
    SELECT la.*, p.name, p.library_id, p.department,
           CASE 
               WHEN la.exit_time IS NOT NULL THEN 
                   TIMEDIFF(la.exit_time, la.entry_time)
               ELSE 
                   TIMEDIFF(NOW(), la.entry_time)
           END as duration
    FROM library_attendance la
    JOIN patrons p ON la.patron_id = p.id
    WHERE 1=1
";

$params = [];

if ($filter_library_id) {
    $query .= " AND p.library_id LIKE ?";
    $params[] = "%$filter_library_id%";
}

if ($filter_date) {
    $query .= " AND DATE(la.entry_time) = ?";
    $params[] = $filter_date;
}

if ($filter_status) {
    $query .= " AND la.status = ?";
    $params[] = $filter_status;
}

$query .= " ORDER BY la.entry_time DESC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$attendance_history = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get total count for pagination
$count_query = "
    SELECT COUNT(*) as total 
    FROM library_attendance la
    JOIN patrons p ON la.patron_id = p.id
    WHERE 1=1
";

$count_params = [];

if ($filter_library_id) {
    $count_query .= " AND p.library_id LIKE ?";
    $count_params[] = "%$filter_library_id%";
}

if ($filter_date) {
    $count_query .= " AND DATE(la.entry_time) = ?";
    $count_params[] = $filter_date;
}

if ($filter_status) {
    $count_query .= " AND la.status = ?";
    $count_params[] = $filter_status;
}

$stmt = $pdo->prepare($count_query);
$stmt->execute($count_params);
$total_count = $stmt->fetch()['total'];
$total_pages = ceil($total_count / $limit);

include __DIR__ . '/_header.php';
?>

<h2>Library Attendance & Book Borrowing System</h2>

<?php if (isset($success_msg)): ?>
    <div class="alert alert-success"><?= $success_msg ?></div>
<?php endif; ?>

<?php if (isset($error_msg)): ?>
    <div class="alert alert-danger"><?= $error_msg ?></div>
<?php endif; ?>

<!-- Borrow Success Modal -->
<?php if (isset($borrow_success)): ?>
<div id="borrowSuccessModal" style="display:flex; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.7); align-items:center; justify-content:center; z-index:9999;">
    <div style="background:linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%); padding:30px; border-radius:15px; width:700px; max-width:90%; max-height:90vh; overflow-y:auto; box-shadow:0 20px 60px rgba(0,0,0,0.3); border:2px solid #10b981;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:25px;">
            <div style="display:flex; align-items:center; gap:10px;">
                <div style="background:#10b981; width:40px; height:40px; border-radius:50%; display:flex; align-items:center; justify-content:center;">
                    <span style="color:white; font-size:20px;">✓</span>
                </div>
                <h3 style="margin:0; color:#065f46; font-size:24px;">Book Borrowed Successfully!</h3>
            </div>
            <button onclick="closeSuccessModal()" style="background:none; border:none; font-size:24px; cursor:pointer; color:#94a3b8; transition:color 0.3s;" onmouseover="this.style.color='#ef4444'" onmouseout="this.style.color='#94a3b8'">&times;</button>
        </div>
        
        <div style="display:flex; gap:25px; margin-bottom:25px; align-items:flex-start;">
            <div style="flex-shrink:0;">
                <img src="<?= htmlspecialchars($borrow_success['cover_image']) ?>" 
                     alt="Book Cover" 
                     style="width:140px; height:180px; object-fit:cover; border-radius:10px; border:3px solid white; box-shadow:0 4px 6px rgba(0,0,0,0.1);">
            </div>
            <div style="flex-grow:1;">
                <div style="background:white; padding:20px; border-radius:10px; border:1px solid #d1fae5; box-shadow:0 2px 4px rgba(0,0,0,0.05);">
                    <p style="font-size:18px; margin:0 0 15px 0; color:#111; line-height:1.5;"><?= $borrow_success['message'] ?></p>
                    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:10px;">
                        <?php foreach ($borrow_success['details'] as $key => $value): ?>
                            <div style="background:#f0fdf4; padding:10px; border-radius:6px; border-left:3px solid #10b981;">
                                <div style="font-size:12px; color:#047857; font-weight:500; margin-bottom:2px;"><?= htmlspecialchars($key) ?></div>
                                <div style="font-size:14px; color:#111;"><?= htmlspecialchars($value) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <div style="display:flex; gap:15px; justify-content:center; margin-top:25px;">
            <button onclick="printReceipt()" 
                    class="btn" 
                    style="background:#3b82f6; color:white; padding:12px 24px; border-radius:8px; border:none; cursor:pointer; font-weight:500; display:flex; align-items:center; gap:8px; transition:all 0.3s;"
                    onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 12px rgba(59, 130, 246, 0.3)'"
                    onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='none'">
                <i class="fa fa-print"></i> Print Receipt
            </button>
            <button onclick="closeSuccessModalAndClear()" 
                    class="btn" 
                    style="background:#10b981; color:white; padding:12px 24px; border-radius:8px; border:none; cursor:pointer; font-weight:500; display:flex; align-items:center; gap:8px; transition:all 0.3s;"
                    onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 12px rgba(16, 185, 129, 0.3)'"
                    onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='none'">
                <i class="fa fa-book"></i> Borrow Another Book
            </button>
        </div>
    </div>
</div>
<script>
    function closeSuccessModal() {
        document.getElementById('borrowSuccessModal').style.display = 'none';
    }
    
    function closeSuccessModalAndClear() {
        document.getElementById('borrowSuccessModal').style.display = 'none';
        document.getElementById('copy_number').value = '';
        document.getElementById('borrow_days').value = '7';
        document.getElementById('bookPreview').style.display = 'none';
        document.getElementById('studentPreview').style.display = 'none';
    }
    
    function printReceipt() {
        const modal = document.getElementById('borrowSuccessModal');
        const printContent = modal.innerHTML;
        const originalContent = document.body.innerHTML;
        
        document.body.innerHTML = `
            <div style="padding:40px; max-width:800px; margin:0 auto;">
                <div style="text-align:center; margin-bottom:30px;">
                    <h1 style="color:#10b981; margin-bottom:10px;">Library Book Borrowing Receipt</h1>
                    <p style="color:#6b7280;">Generated on ${new Date().toLocaleDateString()}</p>
                </div>
                ${printContent}
            </div>
        `;
        window.print();
        document.body.innerHTML = originalContent;
        location.reload();
    }
</script>
<?php endif; ?>

<!-- Borrow Error Modal -->
<?php if (isset($borrow_error)): ?>
<div id="borrowErrorModal" style="display:flex; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.7); align-items:center; justify-content:center; z-index:9999;">
    <div style="background:linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%); padding:30px; border-radius:15px; width:500px; max-width:90%; box-shadow:0 20px 60px rgba(0,0,0,0.3); border:2px solid #ef4444;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
            <div style="display:flex; align-items:center; gap:10px;">
                <div style="background:#ef4444; width:40px; height:40px; border-radius:50%; display:flex; align-items:center; justify-content:center;">
                    <span style="color:white; font-size:20px;">✗</span>
                </div>
                <h3 style="margin:0; color:#991b1b; font-size:22px;">Borrow Failed</h3>
            </div>
            <button onclick="document.getElementById('borrowErrorModal').style.display='none'" style="background:none; border:none; font-size:24px; cursor:pointer; color:#94a3b8; transition:color 0.3s;" onmouseover="this.style.color='#ef4444'" onmouseout="this.style.color='#94a3b8'">&times;</button>
        </div>
        
        <div style="background:white; padding:20px; border-radius:10px; margin-bottom:25px; border:1px solid #fecaca;">
            <div style="color:#7f1d1d; font-size:16px; line-height:1.6;"><?= $borrow_error ?></div>
        </div>
        
        <div style="text-align:center;">
            <button onclick="document.getElementById('borrowErrorModal').style.display='none'" 
                    class="btn" 
                    style="background:#ef4444; color:white; padding:12px 30px; border-radius:8px; border:none; cursor:pointer; font-weight:500; display:inline-flex; align-items:center; gap:8px; transition:all 0.3s;"
                    onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 12px rgba(239, 68, 68, 0.3)'"
                    onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='none'">
                <i class="fa fa-redo"></i> Try Again
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Statistics Cards -->
<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(250px, 1fr)); gap:20px; margin-bottom:25px;">
    <div style="background:linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%); color:white; padding:20px; border-radius:12px; box-shadow:0 4px 6px rgba(0,0,0,0.1);">
        <div style="display:flex; justify-content:space-between; align-items:center;">
            <div>
                <div style="font-size:14px; opacity:0.9;">Current in Library</div>
                <div style="font-size:36px; font-weight:bold; margin:5px 0;"><?= count($students_in_library) ?></div>
                <div style="font-size:12px; opacity:0.8;">Active students</div>
            </div>
            <div style="background:rgba(255,255,255,0.2); width:50px; height:50px; border-radius:50%; display:flex; align-items:center; justify-content:center;">
                <i class="fa fa-users" style="font-size:24px;"></i>
            </div>
        </div>
    </div>
    
    <div style="background:linear-gradient(135deg, #10b981 0%, #047857 100%); color:white; padding:20px; border-radius:12px; box-shadow:0 4px 6px rgba(0,0,0,0.1);">
        <div style="display:flex; justify-content:space-between; align-items:center;">
            <div>
                <div style="font-size:14px; opacity:0.9;">Avg. Time Today</div>
                <div style="font-size:36px; font-weight:bold; margin:5px 0;"><?= $avg_time ?></div>
                <div style="font-size:12px; opacity:0.8;">Hours:Minutes:Seconds</div>
            </div>
            <div style="background:rgba(255,255,255,0.2); width:50px; height:50px; border-radius:50%; display:flex; align-items:center; justify-content:center;">
                <i class="fa fa-clock" style="font-size:24px;"></i>
            </div>
        </div>
    </div>
    
    <div style="background:linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%); color:white; padding:20px; border-radius:12px; box-shadow:0 4px 6px rgba(0,0,0,0.1);">
        <div style="display:flex; justify-content:space-between; align-items:center;">
            <div>
                <div style="font-size:14px; opacity:0.9;">Max Borrow Days</div>
                <div style="font-size:36px; font-weight:bold; margin:5px 0;">7d</div>
                <div style="font-size:12px; opacity:0.8;">Maximum allowed</div>
            </div>
            <div style="background:rgba(255,255,255,0.2); width:50px; height:50px; border-radius:50%; display:flex; align-items:center; justify-content:center;">
                <i class="fa fa-calendar" style="font-size:24px;"></i>
            </div>
        </div>
    </div>
</div>

<div class="dashboard-container">
    <!-- Quick Actions Card -->
    <div class="card" style="margin-bottom:25px; border:none; box-shadow:0 4px 12px rgba(0,0,0,0.08);">
        <h3 style="margin-top:0; color:#1e40af; display:flex; align-items:center; gap:10px;">
            <i class="fa fa-bolt" style="color:#3b82f6;"></i>
            Quick Actions
        </h3>
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(320px, 1fr)); gap:25px;">
            <!-- Enter Library -->
            <div style="background:linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%); padding:20px; border-radius:12px; border:2px solid #0ea5e9;">
                <h4 style="margin-top:0; color:#0284c7; display:flex; align-items:center; gap:8px;">
                    <i class="fa fa-sign-in" style="font-size:18px;"></i>
                    Enter Library
                </h4>
                <form method="POST" style="display:flex; gap:10px;">
                    <input type="hidden" name="action" value="enter_library">
                    <input type="text" name="library_id" placeholder="🔢 Enter Library ID" required 
                           style="flex-grow:1; padding:12px; border:2px solid #cbd5e1; border-radius:8px; font-size:16px; transition:all 0.3s;"
                           onfocus="this.style.borderColor='#3b82f6'; this.style.boxShadow='0 0 0 3px rgba(59, 130, 246, 0.1)'"
                           onblur="this.style.borderColor='#cbd5e1'; this.style.boxShadow='none'">
                    <button type="submit" class="btn" 
                            style="background:linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%); color:white; padding:12px 24px; border-radius:8px; border:none; cursor:pointer; font-weight:500; display:flex; align-items:center; gap:8px; transition:all 0.3s;"
                            onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 12px rgba(59, 130, 246, 0.3)'"
                            onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='none'">
                        <i class="fa fa-door-open"></i> Enter
                    </button>
                </form>
            </div>
            
            <!-- Exit Library -->
            <div style="background:linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%); padding:20px; border-radius:12px; border:2px solid #ef4444;">
                <h4 style="margin-top:0; color:#dc2626; display:flex; align-items:center; gap:8px;">
                    <i class="fa fa-sign-out" style="font-size:18px;"></i>
                    Exit Library
                </h4>
                <form method="POST" style="display:flex; gap:10px;">
                    <input type="hidden" name="action" value="exit_library">
                    <input type="text" name="library_id" placeholder="🔢 Enter Library ID" required 
                           style="flex-grow:1; padding:12px; border:2px solid #cbd5e1; border-radius:8px; font-size:16px; transition:all 0.3s;"
                           onfocus="this.style.borderColor='#ef4444'; this.style.boxShadow='0 0 0 3px rgba(239, 68, 68, 0.1)'"
                           onblur="this.style.borderColor='#cbd5e1'; this.style.boxShadow='none'">
                    <button type="submit" class="btn" 
                            style="background:linear-gradient(135deg, #ef4444 0%, #dc2626 100%); color:white; padding:12px 24px; border-radius:8px; border:none; cursor:pointer; font-weight:500; display:flex; align-items:center; gap:8px; transition:all 0.3s;"
                            onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 12px rgba(239, 68, 68, 0.3)'"
                            onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='none'">
                        <i class="fa fa-door-closed"></i> Exit
                    </button>
                </form>
            </div>
            
            <!-- Borrow Book -->
            <div style="background:linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%); padding:20px; border-radius:12px; border:2px solid #10b981; grid-column: span 2;">
                <h4 style="margin-top:0; color:#059669; display:flex; align-items:center; gap:8px;">
                    <i class="fa fa-book" style="font-size:18px;"></i>
                    Borrow Book
                </h4>
                <form method="POST" id="borrowForm" style="display:grid; grid-template-columns:1fr 1fr 1fr auto; gap:15px; align-items:end;">
                    <div>
                        <label style="display:block; margin-bottom:8px; font-size:14px; color:#047857; font-weight:500;">
                            <i class="fa fa-barcode"></i> Book Copy Number
                        </label>
                        <input type="text" name="copy_number" id="copy_number" placeholder="e.g., PSYM003" required 
                               style="width:100%; padding:12px; border:2px solid #cbd5e1; border-radius:8px; font-size:16px; transition:all 0.3s;"
                               onfocus="this.style.borderColor='#10b981'; this.style.boxShadow='0 0 0 3px rgba(16, 185, 129, 0.1)'"
                               onblur="this.style.borderColor='#cbd5e1'; this.style.boxShadow='none'"
                               oninput="checkBookCopy(this.value)">
                    </div>
                    
                    <div>
                        <label style="display:block; margin-bottom:8px; font-size:14px; color:#047857; font-weight:500;">
                            <i class="fa fa-id-card"></i> Student Library ID
                        </label>
                        <input type="hidden" name="action" value="borrow_book">
                        <input type="text" name="library_id" id="borrow_library_id" placeholder="e.g., 123123123" required 
                               style="width:100%; padding:12px; border:2px solid #cbd5e1; border-radius:8px; font-size:16px; transition:all 0.3s;"
                               onfocus="this.style.borderColor='#10b981'; this.style.boxShadow='0 0 0 3px rgba(16, 185, 129, 0.1)'"
                               onblur="this.style.borderColor='#cbd5e1'; this.style.boxShadow='none'"
                               oninput="checkStudent(this.value)">
                    </div>
                    
                    <div>
                        <label style="display:block; margin-bottom:8px; font-size:14px; color:#047857; font-weight:500;">
                            <i class="fa fa-calendar"></i> Days to Borrow
                        </label>
                        <select name="borrow_days" id="borrow_days" required 
                                style="width:100%; padding:12px; border:2px solid #cbd5e1; border-radius:8px; font-size:16px; transition:all 0.3s;"
                                onfocus="this.style.borderColor='#10b981'; this.style.boxShadow='0 0 0 3px rgba(16, 185, 129, 0.1)'"
                                onblur="this.style.borderColor='#cbd5e1'; this.style.boxShadow='none'">
                            <option value="1">1 day</option>
                            <option value="2">2 days</option>
                            <option value="3">3 days</option>
                            <option value="4">4 days</option>
                            <option value="5">5 days</option>
                            <option value="6">6 days</option>
                            <option value="7" selected>7 days</option>
                        </select>
                    </div>
                    
                    <div>
                        <button type="submit" class="btn" id="borrowBtn"
                                style="background:linear-gradient(135deg, #10b981 0%, #047857 100%); color:white; padding:12px 30px; border-radius:8px; border:none; cursor:pointer; font-weight:500; height:46px; display:flex; align-items:center; gap:8px; transition:all 0.3s;"
                                onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 12px rgba(16, 185, 129, 0.3)'"
                                onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='none'">
                            <i class="fa fa-handshake"></i> Borrow Book
                        </button>
                    </div>
                </form>
                
                <!-- Student Information Preview -->
                <div id="studentPreview" style="margin-top:15px; display:none; animation:fadeIn 0.5s;">
                    <div style="background:white; padding:15px; border-radius:10px; border:2px solid #d1fae5; box-shadow:0 2px 8px rgba(0,0,0,0.05);">
                        <h5 style="margin:0 0 10px 0; color:#065f46; display:flex; align-items:center; gap:8px;">
                            <i class="fa fa-user"></i> Student Information
                        </h5>
                        <div id="studentDetails"></div>
                    </div>
                </div>
                
                <!-- Book Information Preview -->
                <div id="bookPreview" style="margin-top:15px; display:none; animation:fadeIn 0.5s;">
                    <div style="background:white; padding:15px; border-radius:10px; border:2px solid #d1fae5; box-shadow:0 2px 8px rgba(0,0,0,0.05);">
                        <h5 style="margin:0 0 10px 0; color:#065f46; display:flex; align-items:center; gap:8px;">
                            <i class="fa fa-search"></i> Book Details
                        </h5>
                        <div id="bookDetails"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Current in Library -->
    <div class="card" style="margin-bottom:25px; border:none; box-shadow:0 4px 12px rgba(0,0,0,0.08);">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:15px;">
            <div>
                <h3 style="margin:0; color:#1e40af; display:flex; align-items:center; gap:10px;">
                    <i class="fa fa-users" style="color:#3b82f6;"></i>
                    Currently in Library
                    <span class="badge" style="background:#3b82f6; color:white; padding:4px 12px; border-radius:20px; font-size:14px; font-weight:500;">
                        <?= count($students_in_library) ?> student<?= count($students_in_library) !== 1 ? 's' : '' ?>
                    </span>
                </h3>
            </div>
            
            <!-- Filters for Current in Library -->
            <form method="GET" style="display:flex; gap:10px; flex-wrap:wrap;">
                <input type="text" name="current_filter_name" value="<?= htmlspecialchars($current_filter_name) ?>" 
                       placeholder="Filter by name"
                       style="padding:8px 12px; border:2px solid #cbd5e1; border-radius:6px; min-width:150px;"
                       onfocus="this.style.borderColor='#3b82f6'"
                       onblur="this.style.borderColor='#cbd5e1'">
                <input type="text" name="current_filter_library_id" value="<?= htmlspecialchars($current_filter_library_id) ?>" 
                       placeholder="Filter by Library ID"
                       style="padding:8px 12px; border:2px solid #cbd5e1; border-radius:6px; min-width:150px;"
                       onfocus="this.style.borderColor='#3b82f6'"
                       onblur="this.style.borderColor='#cbd5e1'">
                <button type="submit" class="btn" 
                        style="background:#3b82f6; color:white; padding:8px 16px; border-radius:6px; border:none; cursor:pointer; font-weight:500; display:flex; align-items:center; gap:6px;">
                    <i class="fa fa-filter"></i> Filter
                </button>
                <?php if ($current_filter_name || $current_filter_library_id): ?>
                <a href="?" class="btn" 
                   style="background:#94a3b8; color:white; padding:8px 16px; border-radius:6px; text-decoration:none; display:inline-flex; align-items:center; gap:6px;">
                    <i class="fa fa-times"></i> Clear
                </a>
                <?php endif; ?>
            </form>
        </div>
        
        <?php if (empty($students_in_library)): ?>
            <div style="text-align:center; padding:40px; color:#6b7280;">
                <div style="width:80px; height:80px; background:#f3f4f6; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; margin-bottom:15px;">
                    <i class="fa fa-users" style="font-size:32px; color:#cbd5e1;"></i>
                </div>
                <h4 style="margin:0 0 10px 0; color:#4b5563;">No students currently in the library</h4>
                <p style="margin:0; color:#9ca3af;">Students will appear here when they enter the library.</p>
            </div>
        <?php else: ?>
            <div class="table-container" style="border-radius:8px; overflow:hidden; border:1px solid #e5e7eb;">
                <table class="data-table">
                    <thead>
                        <tr style="background:linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);">
                            <th style="padding:16px; font-weight:600; color:#374151; border-bottom:2px solid #e5e7eb; min-width:120px;">
                                <i class="fa fa-id-card"></i> Library ID
                            </th>
                            <th style="padding:16px; font-weight:600; color:#374151; border-bottom:2px solid #e5e7eb; min-width:150px;">
                                <i class="fa fa-user"></i> Name
                            </th>
                            <th style="padding:16px; font-weight:600; color:#374151; border-bottom:2px solid #e5e7eb;">
                                <i class="fa fa-building"></i> Department
                            </th>
                            <th style="padding:16px; font-weight:600; color:#374151; border-bottom:2px solid #e5e7eb;">
                                <i class="fa fa-sign-in"></i> Entry Time
                            </th>
                            <th style="padding:16px; font-weight:600; color:#374151; border-bottom:2px solid #e5e7eb;">
                                <i class="fa fa-clock"></i> Duration
                            </th>
                            <th style="padding:16px; font-weight:600; color:#374151; border-bottom:2px solid #e5e7eb; text-align:center;">
                                <i class="fa fa-cogs"></i> Actions
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students_in_library as $student): 
                            $duration_parts = explode(':', $student['duration']);
                            $hours = (int)$duration_parts[0];
                            $minutes = (int)$duration_parts[1];
                            $duration_class = '';
                            if ($hours >= 3) $duration_class = 'duration-long';
                            elseif ($hours >= 1) $duration_class = 'duration-medium';
                            else $duration_class = 'duration-short';
                        ?>
                        <tr style="border-bottom:1px solid #f3f4f6; transition:background-color 0.2s;" 
                            onmouseover="this.style.backgroundColor='#f9fafb'" 
                            onmouseout="this.style.backgroundColor='white'">
                            <td style="padding:16px;">
                                <div style="display:flex; align-items:center; gap:8px;">
                                    <div style="background:#3b82f6; color:white; width:32px; height:32px; border-radius:6px; display:flex; align-items:center; justify-content:center;">
                                        <i class="fa fa-id-badge" style="font-size:14px;"></i>
                                    </div>
                                    <strong style="color:#1e40af;"><?= htmlspecialchars($student['library_id']) ?></strong>
                                </div>
                            </td>
                            <td style="padding:16px; font-weight:500; color:#111;"><?= htmlspecialchars($student['name']) ?></td>
                            <td style="padding:16px;">
                                <span class="badge" style="background:#e0f2fe; color:#0369a1; padding:4px 10px; border-radius:12px; font-size:12px;">
                                    <?= htmlspecialchars($student['department'] ?? 'N/A') ?>
                                </span>
                            </td>
                            <td style="padding:16px; color:#6b7280;">
                                <div style="display:flex; align-items:center; gap:6px;">
                                    <i class="fa fa-clock-o" style="color:#9ca3af;"></i>
                                    <?= date('g:i A', strtotime($student['entry_time'])) ?>
                                </div>
                                <div style="font-size:12px; color:#9ca3af; margin-top:2px;">
                                    <?= date('M j, Y', strtotime($student['entry_time'])) ?>
                                </div>
                            </td>
                            <td style="padding:16px;">
                                <div class="duration-badge <?= $duration_class ?>" 
                                     style="padding:6px 12px; border-radius:20px; font-weight:500; font-size:13px; display:inline-flex; align-items:center; gap:6px;">
                                    <i class="fa fa-hourglass-half"></i>
                                    <?= htmlspecialchars($student['duration']) ?>
                                </div>
                            </td>
                            <td style="padding:16px; text-align:center;">
                                <div style="display:flex; gap:8px; justify-content:center;">
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="action" value="exit_library">
                                        <input type="hidden" name="library_id" value="<?= htmlspecialchars($student['library_id']) ?>">
                                        <button type="submit" class="btn btn-sm" 
                                                style="background:linear-gradient(135deg, #ef4444 0%, #dc2626 100%); color:white; padding:6px 12px; border-radius:6px; border:none; cursor:pointer; font-size:12px; font-weight:500; display:flex; align-items:center; gap:4px; transition:all 0.3s;"
                                                onmouseover="this.style.transform='translateY(-1px)'"
                                                onmouseout="this.style.transform='translateY(0)'">
                                            <i class="fa fa-sign-out"></i> Exit
                                        </button>
                                    </form>
                                    <button onclick="selectStudentForBorrow('<?= htmlspecialchars($student['library_id']) ?>', '<?= htmlspecialchars($student['name']) ?>')" 
                                            class="btn btn-sm" 
                                            style="background:linear-gradient(135deg, #10b981 0%, #047857 100%); color:white; padding:6px 12px; border-radius:6px; border:none; cursor:pointer; font-size:12px; font-weight:500; display:flex; align-items:center; gap:4px; transition:all 0.3s;"
                                            onmouseover="this.style.transform='translateY(-1px)'"
                                            onmouseout="this.style.transform='translateY(0)'">
                                        <i class="fa fa-book"></i> Borrow
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Summary -->
            <div style="margin-top:20px; padding:15px; background:#f8fafc; border-radius:8px; border:1px solid #e5e7eb;">
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                    <div style="font-size:14px; color:#6b7280;">
                        <i class="fa fa-info-circle"></i> Showing <?= count($students_in_library) ?> student<?= count($students_in_library) !== 1 ? 's' : '' ?> currently in library
                    </div>
                    <div style="font-size:14px; color:#6b7280;">
                        Average time: <strong><?= $avg_time ?></strong>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Attendance History -->
    <div class="card" style="border:none; box-shadow:0 4px 12px rgba(0,0,0,0.08);">
        <h3 style="margin-top:0; color:#1e40af; display:flex; align-items:center; gap:10px;">
            <i class="fa fa-history" style="color:#8b5cf6;"></i>
            Attendance History
        </h3>
        
        <!-- Filters -->
        <form method="GET" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:15px; margin-bottom:25px; padding:20px; background:#f8fafc; border-radius:10px; border:1px solid #e5e7eb;">
            <div>
                <label style="display:block; margin-bottom:8px; font-size:14px; color:#64748b; font-weight:500;">
                    <i class="fa fa-id-card"></i> Library ID
                </label>
                <input type="text" name="filter_library_id" value="<?= htmlspecialchars($filter_library_id) ?>" 
                       placeholder="Filter by Library ID"
                       style="width:100%; padding:10px; border:2px solid #cbd5e1; border-radius:6px; transition:all 0.3s;"
                       onfocus="this.style.borderColor='#8b5cf6'"
                       onblur="this.style.borderColor='#cbd5e1'">
            </div>
            
            <div>
                <label style="display:block; margin-bottom:8px; font-size:14px; color:#64748b; font-weight:500;">
                    <i class="fa fa-calendar"></i> Date
                </label>
                <input type="date" name="filter_date" value="<?= htmlspecialchars($filter_date) ?>" 
                       style="width:100%; padding:10px; border:2px solid #cbd5e1; border-radius:6px; transition:all 0.3s;"
                       onfocus="this.style.borderColor='#8b5cf6'"
                       onblur="this.style.borderColor='#cbd5e1'">
            </div>
            
            <div>
                <label style="display:block; margin-bottom:8px; font-size:14px; color:#64748b; font-weight:500;">
                    <i class="fa fa-circle"></i> Status
                </label>
                <select name="filter_status" style="width:100%; padding:10px; border:2px solid #cbd5e1; border-radius:6px; transition:all 0.3s;"
                        onfocus="this.style.borderColor='#8b5cf6'"
                        onblur="this.style.borderColor='#cbd5e1'">
                    <option value="">All Status</option>
                    <option value="in_library" <?= $filter_status === 'in_library' ? 'selected' : '' ?>>In Library</option>
                    <option value="left" <?= $filter_status === 'left' ? 'selected' : '' ?>>Left</option>
                </select>
            </div>
            
            <div style="align-self:end;">
                <button type="submit" class="btn" 
                        style="background:linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%); color:white; padding:10px 20px; border-radius:6px; border:none; cursor:pointer; font-weight:500; width:100%; display:flex; align-items:center; justify-content:center; gap:8px; transition:all 0.3s;"
                        onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 12px rgba(139, 92, 246, 0.3)'"
                        onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='none'">
                    <i class="fa fa-filter"></i> Apply Filters
                </button>
            </div>
            
            <?php if ($filter_library_id || $filter_date || $filter_status): ?>
            <div style="align-self:end;">
                <a href="?" class="btn" 
                   style="background:#94a3b8; color:white; padding:10px 20px; border-radius:6px; text-decoration:none; display:inline-flex; align-items:center; justify-content:center; gap:8px; width:100%; transition:all 0.3s;"
                   onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 12px rgba(148, 163, 184, 0.3)'"
                   onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='none'">
                    <i class="fa fa-times"></i> Clear Filters
                </a>
            </div>
            <?php endif; ?>
        </form>
        
        <?php if (empty($attendance_history)): ?>
            <div style="text-align:center; padding:40px; color:#6b7280;">
                <div style="width:80px; height:80px; background:#f3f4f6; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; margin-bottom:15px;">
                    <i class="fa fa-history" style="font-size:32px; color:#cbd5e1;"></i>
                </div>
                <h4 style="margin:0 0 10px 0; color:#4b5563;">No attendance records found</h4>
                <p style="margin:0; color:#9ca3af;">Attendance records will appear here when students enter/exit.</p>
            </div>
        <?php else: ?>
            <div class="table-container" style="border-radius:8px; overflow:hidden; border:1px solid #e5e7eb;">
                <table class="data-table">
                    <thead>
                        <tr style="background:linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);">
                            <th style="padding:16px; font-weight:600; color:#374151; border-bottom:2px solid #e5e7eb;">
                                <i class="fa fa-id-card"></i> Library ID
                            </th>
                            <th style="padding:16px; font-weight:600; color:#374151; border-bottom:2px solid #e5e7eb;">
                                <i class="fa fa-user"></i> Name
                            </th>
                            <th style="padding:16px; font-weight:600; color:#374151; border-bottom:2px solid #e5e7eb;">
                                <i class="fa fa-sign-in"></i> Entry Time
                            </th>
                            <th style="padding:16px; font-weight:600; color:#374151; border-bottom:2px solid #e5e7eb;">
                                <i class="fa fa-sign-out"></i> Exit Time
                            </th>
                            <th style="padding:16px; font-weight:600; color:#374151; border-bottom:2px solid #e5e7eb;">
                                <i class="fa fa-clock"></i> Duration
                            </th>
                            <th style="padding:16px; font-weight:600; color:#374151; border-bottom:2px solid #e5e7eb;">
                                <i class="fa fa-circle"></i> Status
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($attendance_history as $record): ?>
                        <tr style="border-bottom:1px solid #f3f4f6; transition:background-color 0.2s;" 
                            onmouseover="this.style.backgroundColor='#f9fafb'" 
                            onmouseout="this.style.backgroundColor='white'">
                            <td style="padding:16px; font-weight:500; color:#1e40af;"><?= htmlspecialchars($record['library_id']) ?></td>
                            <td style="padding:16px; font-weight:500; color:#111;"><?= htmlspecialchars($record['name']) ?></td>
                            <td style="padding:16px; color:#6b7280;">
                                <?= date('M j, Y g:i A', strtotime($record['entry_time'])) ?>
                            </td>
                            <td style="padding:16px; color:#6b7280;">
                                <?php if ($record['exit_time']): ?>
                                    <?= date('M j, Y g:i A', strtotime($record['exit_time'])) ?>
                                <?php else: ?>
                                    <span style="color:#94a3b8; font-style:italic;">-</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding:16px;">
                                <?php if ($record['duration']): 
                                    list($h, $m, $s) = explode(':', $record['duration']);
                                    $total_minutes = ($h * 60) + $m;
                                    if ($total_minutes > 120) $duration_class = 'duration-long';
                                    elseif ($total_minutes > 60) $duration_class = 'duration-medium';
                                    else $duration_class = 'duration-short';
                                ?>
                                <div class="duration-badge <?= $duration_class ?>" 
                                     style="padding:4px 10px; border-radius:20px; font-size:12px; display:inline-flex; align-items:center; gap:4px;">
                                    <i class="fa fa-hourglass-end"></i>
                                    <?= htmlspecialchars($record['duration']) ?>
                                </div>
                                <?php else: ?>
                                    <span style="color:#94a3b8; font-size:12px;">N/A</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding:16px;">
                                <?php if ($record['status'] === 'in_library'): ?>
                                    <span class="badge" style="background:#d1fae5; color:#065f46; padding:6px 12px; border-radius:20px; font-size:12px; font-weight:500; display:inline-flex; align-items:center; gap:6px;">
                                        <i class="fa fa-circle" style="font-size:8px;"></i> In Library
                                    </span>
                                <?php else: ?>
                                    <span class="badge" style="background:#fee2e2; color:#991b1b; padding:6px 12px; border-radius:20px; font-size:12px; font-weight:500; display:inline-flex; align-items:center; gap:6px;">
                                        <i class="fa fa-circle" style="font-size:8px;"></i> Left
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Simple Next/Previous Pagination -->
            <div style="display:flex; justify-content:center; align-items:center; gap:15px; margin-top:30px; padding-top:25px; border-top:2px solid #e5e7eb;">
                <?php if ($page > 1): ?>
                    <a href="?page=<?= $page - 1 ?><?= $filter_library_id ? '&filter_library_id=' . urlencode($filter_library_id) : '' ?><?= $filter_date ? '&filter_date=' . urlencode($filter_date) : '' ?><?= $filter_status ? '&filter_status=' . urlencode($filter_status) : '' ?><?= $current_filter_name ? '&current_filter_name=' . urlencode($current_filter_name) : '' ?><?= $current_filter_library_id ? '&current_filter_library_id=' . urlencode($current_filter_library_id) : '' ?>" 
                       class="btn" 
                       style="background:linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%); color:white; padding:10px 20px; border-radius:8px; text-decoration:none; display:inline-flex; align-items:center; gap:8px; transition:all 0.3s;"
                       onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 12px rgba(139, 92, 246, 0.3)'"
                       onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='none'">
                        <i class="fa fa-angle-left"></i> Previous
                    </a>
                <?php endif; ?>
                
                <div style="font-size:14px; color:#6b7280; padding:8px 16px; background:#f8fafc; border-radius:8px; border:1px solid #e5e7eb;">
                    Showing <?= min($limit, count($attendance_history)) ?> of <?= $total_count ?> records
                    <span style="color:#8b5cf6; font-weight:600;">(Page <?= $page ?> of <?= $total_pages ?>)</span>
                </div>
                
                <?php if ($page < $total_pages): ?>
                    <a href="?page=<?= $page + 1 ?><?= $filter_library_id ? '&filter_library_id=' . urlencode($filter_library_id) : '' ?><?= $filter_date ? '&filter_date=' . urlencode($filter_date) : '' ?><?= $filter_status ? '&filter_status=' . urlencode($filter_status) : '' ?><?= $current_filter_name ? '&current_filter_name=' . urlencode($current_filter_name) : '' ?><?= $current_filter_library_id ? '&current_filter_library_id=' . urlencode($current_filter_library_id) : '' ?>" 
                       class="btn" 
                       style="background:linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%); color:white; padding:10px 20px; border-radius:8px; text-decoration:none; display:inline-flex; align-items:center; gap:8px; transition:all 0.3s;"
                       onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 12px rgba(139, 92, 246, 0.3)'"
                       onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='none'">
                        Next <i class="fa fa-angle-right"></i>
                    </a>
                <?php endif; ?>
            </div>
            
            <!-- Page Navigation -->
            <div style="display:flex; justify-content:center; align-items:center; gap:8px; margin-top:15px; flex-wrap:wrap;">
                <?php for ($p = 1; $p <= $total_pages; $p++): ?>
                    <?php if ($p == $page): ?>
                        <span style="background:#8b5cf6; color:white; padding:6px 12px; border-radius:6px; font-size:14px; font-weight:600;">
                            <?= $p ?>
                        </span>
                    <?php else: ?>
                        <a href="?page=<?= $p ?><?= $filter_library_id ? '&filter_library_id=' . urlencode($filter_library_id) : '' ?><?= $filter_date ? '&filter_date=' . urlencode($filter_date) : '' ?><?= $filter_status ? '&filter_status=' . urlencode($filter_status) : '' ?><?= $current_filter_name ? '&current_filter_name=' . urlencode($current_filter_name) : '' ?><?= $current_filter_library_id ? '&current_filter_library_id=' . urlencode($current_filter_library_id) : '' ?>" 
                           style="background:#f3f4f6; color:#374151; padding:6px 12px; border-radius:6px; text-decoration:none; font-size:14px; transition:all 0.3s;"
                           onmouseover="this.style.background='#e5e7eb'; this.style.color='#1f2937'">
                            <?= $p ?>
                        </a>
                    <?php endif; ?>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
// Function to select student from the table for borrowing
function selectStudentForBorrow(libraryId, studentName) {
    document.getElementById('borrow_library_id').value = libraryId;
    checkStudent(libraryId);
    document.getElementById('copy_number').focus();
    
    // Show a quick notification
    const notification = document.createElement('div');
    notification.innerHTML = `
        <div style="position:fixed; top:20px; right:20px; background:#10b981; color:white; padding:12px 20px; border-radius:8px; box-shadow:0 4px 6px rgba(0,0,0,0.1); z-index:10000; animation:slideIn 0.3s;">
            <i class="fa fa-check-circle"></i> Selected student: ${studentName}
        </div>
    `;
    document.body.appendChild(notification);
    setTimeout(() => notification.remove(), 3000);
}

// Student validation and preview
async function checkStudent(libraryId) {
    const studentPreview = document.getElementById('studentPreview');
    const studentDetails = document.getElementById('studentDetails');
    const borrowBtn = document.getElementById('borrowBtn');
    
    if (!libraryId.trim()) {
        studentPreview.style.display = 'none';
        borrowBtn.disabled = false;
        borrowBtn.innerHTML = '<i class="fa fa-handshake"></i> Borrow Book';
        borrowBtn.style.background = 'linear-gradient(135deg, #10b981 0%, #047857 100%)';
        return;
    }
    
    // Show loading state
    studentDetails.innerHTML = `
        <div style="display:flex; align-items:center; gap:10px; color:#f59e0b;">
            <div class="spinner" style="border:2px solid #f3f4f6; border-top:2px solid #f59e0b; border-radius:50%; width:20px; height:20px; animation:spin 1s linear infinite;"></div>
            <span>Checking student information...</span>
        </div>
    `;
    studentPreview.style.display = 'block';
    
    try {
        // Use fetch to check student directly via PHP
        const response = await fetch('check_student.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'library_id=' + encodeURIComponent(libraryId)
        });
        
        if (!response.ok) throw new Error('Network error');
        
        const data = await response.json();
        
        if (data.success) {
            const student = data.student;
            const isInLibrary = data.is_in_library;
            const activeBorrows = data.active_borrows || 0;
            const overdueCount = data.overdue_count || 0;
            
            // Create student info display
            studentDetails.innerHTML = `
                <div style="display:flex; gap:15px; align-items:center;">
                    <div style="background:#3b82f6; color:white; width:60px; height:60px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:24px; flex-shrink:0;">
                        ${escapeHtml(student.name.charAt(0).toUpperCase())}
                    </div>
                    <div style="flex-grow:1;">
                        <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:5px;">
                            <div>
                                <div style="font-weight:600; color:#111; margin-bottom:2px;">${escapeHtml(student.name)}</div>
                                <div style="font-size:12px; color:#6b7280; margin-bottom:5px;">
                                    <span style="display:inline-flex; align-items:center; gap:3px;">
                                        <i class="fa fa-id-card"></i> ${escapeHtml(student.library_id)}
                                    </span>
                                </div>
                            </div>
                            <span class="badge" style="background:${isInLibrary ? '#10b981' : '#ef4444'}; color:white; padding:2px 8px; border-radius:10px; font-size:11px; display:inline-flex; align-items:center; gap:3px;">
                                <i class="fa fa-${isInLibrary ? 'check' : 'times'}"></i> ${isInLibrary ? 'In Library' : 'Not in Library'}
                            </span>
                        </div>
                        <div style="display:flex; flex-wrap:wrap; gap:8px; margin-top:8px;">
                            <span class="badge" style="background:#8b5cf6; color:white; padding:2px 8px; border-radius:10px; font-size:11px; display:inline-flex; align-items:center; gap:3px;">
                                <i class="fa fa-graduation-cap"></i> ${escapeHtml(student.department || 'N/A')}
                            </span>
                            <span class="badge" style="background:#3b82f6; color:white; padding:2px 8px; border-radius:10px; font-size:11px; display:inline-flex; align-items:center; gap:3px;">
                                <i class="fa fa-calendar"></i> ${escapeHtml(student.semester || 'N/A')}
                            </span>
                            <span class="badge" style="background:${activeBorrows < 5 ? '#10b981' : '#ef4444'}; color:white; padding:2px 8px; border-radius:10px; font-size:11px; display:inline-flex; align-items:center; gap:3px;">
                                <i class="fa fa-book"></i> ${activeBorrows} active borrows
                            </span>
                            ${overdueCount > 0 ? `
                            <span class="badge" style="background:#ef4444; color:white; padding:2px 8px; border-radius:10px; font-size:11px; display:inline-flex; align-items:center; gap:3px;">
                                <i class="fa fa-exclamation-triangle"></i> ${overdueCount} overdue
                            </span>
                            ` : ''}
                        </div>
                        ${!isInLibrary ? `
                        <div style="margin-top:8px; padding:6px 10px; background:#fee2e2; border-radius:5px; border-left:3px solid #ef4444;">
                            <div style="font-size:11px; color:#991b1b;">
                                <i class="fa fa-exclamation-triangle"></i> Student must be in the library to borrow books
                            </div>
                        </div>
                        ` : ''}
                        ${overdueCount > 0 ? `
                        <div style="margin-top:8px; padding:6px 10px; background:#fef3c7; border-radius:5px; border-left:3px solid #f59e0b;">
                            <div style="font-size:11px; color:#92400e;">
                                <i class="fa fa-exclamation-triangle"></i> Student has ${overdueCount} overdue book(s)
                            </div>
                        </div>
                        ` : ''}
                        ${activeBorrows >= 5 ? `
                        <div style="margin-top:8px; padding:6px 10px; background:#fee2e2; border-radius:5px; border-left:3px solid #ef4444;">
                            <div style="font-size:11px; color:#991b1b;">
                                <i class="fa fa-exclamation-triangle"></i> Maximum borrowing limit (5 books) reached
                            </div>
                        </div>
                        ` : ''}
                    </div>
                </div>
            `;
            
            // Update borrow button state
            if (!isInLibrary || overdueCount > 0 || activeBorrows >= 5) {
                borrowBtn.disabled = true;
                borrowBtn.style.background = 'linear-gradient(135deg, #94a3b8 0%, #64748b 100%)';
                borrowBtn.innerHTML = '<i class="fa fa-ban"></i> Cannot Borrow';
            } else {
                borrowBtn.disabled = false;
                borrowBtn.style.background = 'linear-gradient(135deg, #10b981 0%, #047857 100%)';
                borrowBtn.innerHTML = '<i class="fa fa-handshake"></i> Borrow Book';
            }
        } else {
            studentDetails.innerHTML = `
                <div style="color:#ef4444; display:flex; align-items:center; gap:8px;">
                    <i class="fa fa-times-circle" style="font-size:18px;"></i>
                    <div>
                        <div style="font-weight:500;">Student Not Found</div>
                        <div style="font-size:12px; color:#9ca3af;">No student found with this Library ID</div>
                    </div>
                </div>
            `;
            
            // Disable borrow button
            borrowBtn.disabled = true;
            borrowBtn.style.background = 'linear-gradient(135deg, #94a3b8 0%, #64748b 100%)';
            borrowBtn.innerHTML = '<i class="fa fa-ban"></i> Invalid Student';
        }
    } catch (error) {
        console.error('Error checking student:', error);
        studentDetails.innerHTML = `
            <div style="color:#f59e0b; display:flex; align-items:center; gap:8px;">
                <i class="fa fa-exclamation-circle" style="font-size:18px;"></i>
                <div>
                    <div style="font-weight:500;">Error checking student</div>
                    <div style="font-size:12px; color:#9ca3af;">Please try again or check connection</div>
                </div>
            </div>
        `;
        
        // Reset borrow button
        borrowBtn.disabled = false;
        borrowBtn.style.background = 'linear-gradient(135deg, #10b981 0%, #047857 100%)';
        borrowBtn.innerHTML = '<i class="fa fa-handshake"></i> Borrow Book';
    }
}

// Book copy validation and preview - UPDATED VERSION
async function checkBookCopy(copyNumber) {
    const bookPreview = document.getElementById('bookPreview');
    const bookDetails = document.getElementById('bookDetails');
    const borrowBtn = document.getElementById('borrowBtn');
    
    if (!copyNumber.trim()) {
        bookPreview.style.display = 'none';
        return;
    }
    
    // Show loading state
    bookDetails.innerHTML = `
        <div style="display:flex; align-items:center; gap:10px; color:#f59e0b;">
            <div class="spinner" style="border:2px solid #f3f4f6; border-top:2px solid #f59e0b; border-radius:50%; width:20px; height:20px; animation:spin 1s linear infinite;"></div>
            <span>Checking book availability...</span>
        </div>
    `;
    bookPreview.style.display = 'block';
    
    try {
        // Use fetch to check book copy directly via PHP
        const response = await fetch('check_book_copy.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'copy_number=' + encodeURIComponent(copyNumber)
        });
        
        if (!response.ok) throw new Error('Network error');
        
        const data = await response.json();
        
        if (data.success) {
            const book = data.book;
            const status = book.status.toLowerCase();
            const condition = book.book_condition || 'good';
            const conditionColor = getConditionColor(condition);
            const statusColor = getStatusColor(status);
            
            // ADDITIONAL CHECK: Check if this specific copy is already borrowed
            // We need to make another request to check borrow_logs
            let isAlreadyBorrowed = false;
            try {
                const borrowCheckResponse = await fetch('check_copy_borrow.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'copy_id=' + encodeURIComponent(book.id)
                });
                
                if (borrowCheckResponse.ok) {
                    const borrowData = await borrowCheckResponse.json();
                    isAlreadyBorrowed = borrowData.success && borrowData.is_borrowed;
                }
            } catch (borrowError) {
                console.error('Error checking borrow status:', borrowError);
            }
            
            // Determine cover image
            let coverImage = '<?= $default_cover ?>';
            if (book.cover_image_cache && book.cover_image_cache !== 'null') {
                coverImage = '../uploads/covers/' + book.cover_image_cache;
            } else if (book.cover_image && book.cover_image !== 'null') {
                coverImage = '../uploads/covers/' + book.cover_image;
            }
            
            // Create status badge - UPDATED to check both status and borrow_logs
            let statusBadge = '';
            let canBorrow = status === 'available' && !isAlreadyBorrowed;
            
            if (canBorrow) {
                statusBadge = `<span class="badge" style="background:#10b981; color:white; padding:2px 8px; border-radius:10px; font-size:11px;">
                    <i class="fa fa-check"></i> Available for Borrow
                </span>`;
            } else if (isAlreadyBorrowed) {
                statusBadge = `<span class="badge" style="background:#ef4444; color:white; padding:2px 8px; border-radius:10px; font-size:11px;">
                    <i class="fa fa-times"></i> Already Borrowed
                </span>`;
            } else if (status === 'reserved') {
                statusBadge = `<span class="badge" style="background:#f59e0b; color:white; padding:2px 8px; border-radius:10px; font-size:11px;">
                    <i class="fa fa-clock"></i> Reserved
                </span>`;
            } else if (status === 'borrowed') {
                statusBadge = `<span class="badge" style="background:#ef4444; color:white; padding:2px 8px; border-radius:10px; font-size:11px;">
                    <i class="fa fa-times"></i> Borrowed
                </span>`;
            } else {
                statusBadge = `<span class="badge" style="background:#94a3b8; color:white; padding:2px 8px; border-radius:10px; font-size:11px;">
                    ${escapeHtml(status.charAt(0).toUpperCase() + status.slice(1))}
                </span>`;
            }
            
            // Get location if available
            let location = '';
            if (book.current_section) {
                location = `${book.current_section}-S${book.current_shelf}-R${book.current_row}-P${book.current_slot}`;
            }
            
            bookDetails.innerHTML = `
                <div style="display:flex; gap:15px; align-items:center;">
                    <div style="flex-shrink:0; position:relative;">
                        <img src="${coverImage}" 
                             alt="Book Cover" 
                             style="width:70px; height:90px; object-fit:cover; border-radius:6px; border:2px solid white; box-shadow:0 2px 4px rgba(0,0,0,0.1);">
                        <div style="position:absolute; top:-5px; right:-5px; background:${canBorrow ? '#10b981' : statusColor}; color:white; width:20px; height:20px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:10px;">
                            ${canBorrow ? '✓' : isAlreadyBorrowed ? '✗' : status === 'available' ? '✓' : status === 'reserved' ? '!' : '✗'}
                        </div>
                    </div>
                    <div style="flex-grow:1;">
                        <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:5px;">
                            <div>
                                <div style="font-weight:600; color:#111; margin-bottom:2px;">${escapeHtml(book.title)}</div>
                                <div style="font-size:12px; color:#6b7280; margin-bottom:5px;">
                                    by ${escapeHtml(book.author || 'Unknown')}
                                </div>
                            </div>
                            ${statusBadge}
                        </div>
                        <div style="font-size:11px; color:#6b7280; margin-bottom:8px; line-height:1.4;">
                            ${book.description ? escapeHtml(book.description.substring(0, 100)) + '...' : 'No description available'}
                        </div>
                        <div style="display:flex; flex-wrap:wrap; gap:8px; margin-top:8px;">
                            <span class="badge" style="background:#3b82f6; color:white; padding:2px 8px; border-radius:10px; font-size:11px; display:inline-flex; align-items:center; gap:3px;">
                                <i class="fa fa-barcode"></i> ${escapeHtml(book.copy_number)}
                            </span>
                            <span class="badge" style="background:${conditionColor}; color:white; padding:2px 8px; border-radius:10px; font-size:11px; display:inline-flex; align-items:center; gap:3px;">
                                <i class="fa fa-stethoscope"></i> ${escapeHtml(condition)}
                            </span>
                            <span class="badge" style="background:#8b5cf6; color:white; padding:2px 8px; border-radius:10px; font-size:11px; display:inline-flex; align-items:center; gap:3px;">
                                <i class="fa fa-hashtag"></i> ${escapeHtml(book.isbn || 'No ISBN')}
                            </span>
                            ${location ? `
                            <span class="badge" style="background:#10b981; color:white; padding:2px 8px; border-radius:10px; font-size:11px; display:inline-flex; align-items:center; gap:3px;">
                                <i class="fa fa-map-marker"></i> ${escapeHtml(location)}
                            </span>
                            ` : ''}
                        </div>
                        ${!canBorrow ? `
                        <div style="margin-top:8px; padding:6px 10px; background:#fee2e2; border-radius:5px; border-left:3px solid #ef4444;">
                            <div style="font-size:11px; color:#991b1b;">
                                <i class="fa fa-exclamation-triangle"></i> This book cannot be borrowed. 
                                ${isAlreadyBorrowed ? 'Already borrowed by another student.' : `Status: <strong>${escapeHtml(status)}</strong>`}
                            </div>
                        </div>
                        ` : ''}
                    </div>
                </div>
            `;
            
            // Check if borrow button should be enabled
            const currentStudentId = document.getElementById('borrow_library_id').value;
            if (!canBorrow && borrowBtn.disabled === false) {
                borrowBtn.disabled = true;
                borrowBtn.style.background = 'linear-gradient(135deg, #94a3b8 0%, #64748b 100%)';
                borrowBtn.innerHTML = '<i class="fa fa-ban"></i> Cannot Borrow';
            } else if (canBorrow && currentStudentId && !borrowBtn.disabled) {
                // Re-check student to ensure they're still valid
                checkStudent(currentStudentId);
            }
        } else {
            bookDetails.innerHTML = `
                <div style="color:#ef4444; display:flex; align-items:center; gap:8px;">
                    <i class="fa fa-times-circle" style="font-size:18px;"></i>
                    <div>
                        <div style="font-weight:500;">Invalid Copy Number</div>
                        <div style="font-size:12px; color:#9ca3af;">Book with this copy number was not found</div>
                    </div>
                </div>
            `;
            
            // Disable borrow button if it was enabled
            if (borrowBtn.disabled === false) {
                borrowBtn.disabled = true;
                borrowBtn.style.background = 'linear-gradient(135deg, #94a3b8 0%, #64748b 100%)';
                borrowBtn.innerHTML = '<i class="fa fa-ban"></i> Invalid Book';
            }
        }
    } catch (error) {
        console.error('Error checking book copy:', error);
        bookDetails.innerHTML = `
            <div style="color:#f59e0b; display:flex; align-items:center; gap:8px;">
                <i class="fa fa-exclamation-circle" style="font-size:18px;"></i>
                <div>
                    <div style="font-weight:500;">Error checking book</div>
                    <div style="font-size:12px; color:#9ca3af;">Please try again or check connection</div>
                </div>
            </div>
        `;
        
        // Reset borrow button
        const currentStudentId = document.getElementById('borrow_library_id').value;
        if (currentStudentId) {
            // Re-check student to update button state
            checkStudent(currentStudentId);
        }
    }
}

// Helper function to get color based on book status
function getStatusColor(status) {
    const colors = {
        'available': '#10b981',
        'reserved': '#f59e0b',
        'borrowed': '#ef4444',
        'lost': '#dc2626',
        'damaged': '#991b1b',
        'maintenance': '#8b5cf6'
    };
    return colors[status] || '#94a3b8';
}

// Helper function to get color based on book condition
function getConditionColor(condition) {
    const colors = {
        'new': '#10b981',
        'good': '#3b82f6',
        'fair': '#f59e0b',
        'poor': '#ef4444',
        'damaged': '#dc2626',
        'lost': '#6b7280'
    };
    return colors[condition] || '#94a3b8';
}

// Helper function to escape HTML
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Auto-focus on the library ID field when entering/borrowing
document.addEventListener('DOMContentLoaded', function() {
    const urlParams = new URLSearchParams(window.location.search);
    const focusField = urlParams.get('focus');
    
    if (focusField === 'enter') {
        document.querySelector('input[name="library_id"]').focus();
    } else if (focusField === 'borrow') {
        document.getElementById('borrow_library_id').focus();
    }
    
    // Show success/error modals if they exist
    <?php if (isset($borrow_success)): ?>
        document.getElementById('borrowSuccessModal').style.display = 'flex';
    <?php endif; ?>
    
    <?php if (isset($borrow_error)): ?>
        document.getElementById('borrowErrorModal').style.display = 'flex';
    <?php endif; ?>
    
    // Add animations
    const style = document.createElement('style');
    style.textContent = `
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .duration-long { background: #fee2e2; color: #991b1b; }
        .duration-medium { background: #fef3c7; color: #92400e; }
        .duration-short { background: #d1fae5; color: #065f46; }
        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        .btn:disabled:hover {
            transform: none !important;
            box-shadow: none !important;
        }
    `;
    document.head.appendChild(style);
});
</script>

<style>
/* Additional styles for the new functionality */
.dashboard-container {
    display: flex;
    flex-direction: column;
    gap: 25px;
}

.card {
    background: white;
    border-radius: 12px;
    padding: 25px;
    box-shadow: 0 4px 6px rgba(0,0,0,0.05);
    border: 1px solid #e5e7eb;
    transition: transform 0.3s, box-shadow 0.3s;
}

.card:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 12px rgba(0,0,0,0.08);
}

.badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 500;
    white-space: nowrap;
}

.alert {
    padding: 16px 20px;
    border-radius: 10px;
    margin-bottom: 25px;
    border: 2px solid transparent;
    font-size: 16px;
    display: flex;
    align-items: center;
    gap: 12px;
    animation: fadeIn 0.5s;
}

.alert-success {
    background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
    border-color: #10b981;
    color: #065f46;
}

.alert-danger {
    background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
    border-color: #ef4444;
    color: #991b1b;
}

.btn {
    padding: 10px 20px;
    border-radius: 8px;
    border: none;
    cursor: pointer;
    font-size: 14px;
    font-weight: 500;
    transition: all 0.3s;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

.btn:hover:not(:disabled) {
    transform: translateY(-2px);
}

.btn-sm {
    padding: 6px 12px;
    font-size: 12px;
}

.table-container {
    overflow-x: auto;
    border-radius: 8px;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 14px;
    min-width: 800px;
}

.data-table th {
    text-align: left;
    font-weight: 600;
    color: #374151;
    border-bottom: 2px solid #e5e7eb;
    position: sticky;
    top: 0;
    background: white;
    z-index: 10;
}

.data-table td {
    border-bottom: 1px solid #f3f4f6;
    color: #6b7280;
}

.data-table tr:hover {
    background-color: #f9fafb;
}

/* Print styles for receipt */
@media print {
    body * {
        visibility: hidden;
    }
    #borrowSuccessModal,
    #borrowSuccessModal * {
        visibility: visible;
    }
    #borrowSuccessModal {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background: white;
        padding: 40px;
        box-shadow: none;
        border: none;
    }
    .btn {
        display: none !important;
    }
}

/* Animation for preview sections */
@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Status badges for duration */
.duration-badge {
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 500;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .dashboard-container {
        gap: 15px;
    }
    
    .card {
        padding: 15px;
    }
    
    .btn {
        padding: 8px 16px;
        font-size: 13px;
    }
    
    .data-table {
        font-size: 12px;
    }
    
    .data-table th,
    .data-table td {
        padding: 10px;
    }
    
    /* Adjust borrow form for mobile */
    #borrowForm {
        grid-template-columns: 1fr !important;
        gap: 10px !important;
    }
    
    /* Adjust preview sections for mobile */
    #studentPreview, #bookPreview {
        margin-top: 10px !important;
    }
    
    #studentDetails, #bookDetails {
        flex-direction: column !important;
        align-items: flex-start !important;
    }
    
    #studentDetails > div, #bookDetails > div {
        flex-direction: column !important;
        gap: 10px !important;
    }
    
    #studentDetails img, #bookDetails img {
        width: 50px !important;
        height: 65px !important;
    }
}
</style>

<?php include __DIR__ . '/_footer.php'; ?>