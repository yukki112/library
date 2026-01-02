<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();

header('Content-Type: application/json');

// Only students can request books
$user = current_user();
if ($user['role'] !== 'student') {
    echo json_encode(['error' => 'Only students can borrow books']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Invalid request method']);
    exit;
}

$book_id = intval($_POST['book_id'] ?? 0);
$copy_id = intval($_POST['copy_id'] ?? 0);
$days = intval($_POST['days'] ?? 14);

if ($book_id <= 0) {
    echo json_encode(['error' => 'Invalid book']);
    exit;
}

if ($days < 1 || $days > 30) {
    echo json_encode(['error' => 'Invalid borrow period']);
    exit;
}

$pdo = DB::conn();

try {
    $pdo->beginTransaction();
    
    // Check if book exists and is available
    $stmt = $pdo->prepare("
        SELECT b.title, b.available_copies_cache
        FROM books b
        WHERE b.id = ? AND b.is_active = 1
    ");
    $stmt->execute([$book_id]);
    $book = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$book) {
        throw new Exception('Book not found');
    }
    
    if ($book['available_copies_cache'] <= 0) {
        throw new Exception('No copies available');
    }
    
    // Get patron ID from user
    $stmt = $pdo->prepare("SELECT id FROM patrons WHERE email = ? LIMIT 1");
    $stmt->execute([$user['email']]);
    $patron = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$patron) {
        throw new Exception('Patron record not found');
    }
    $patron_id = $patron['id'];
    
    // Find available copy
    if ($copy_id > 0) {
        // Specific copy requested
        $stmt = $pdo->prepare("
            SELECT id FROM book_copies 
            WHERE id = ? AND book_id = ? AND status = 'available' AND is_active = 1
        ");
        $stmt->execute([$copy_id, $book_id]);
        $copy = $stmt->fetch();
        
        if (!$copy) {
            throw new Exception('Requested copy is not available');
        }
        $selected_copy_id = $copy_id;
    } else {
        // Any available copy
        $stmt = $pdo->prepare("
            SELECT id FROM book_copies 
            WHERE book_id = ? AND status = 'available' AND is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$book_id]);
        $copy = $stmt->fetch();
        
        if (!$copy) {
            throw new Exception('No available copies found');
        }
        $selected_copy_id = $copy['id'];
    }
    
    // Calculate dates
    $borrowed_at = date('Y-m-d H:i:s');
    $due_date = date('Y-m-d H:i:s', strtotime("+$days days"));
    
    // Create borrow log
    $stmt = $pdo->prepare("
        INSERT INTO borrow_logs (book_id, patron_id, borrowed_at, due_date, status)
        VALUES (?, ?, ?, ?, 'borrowed')
    ");
    $stmt->execute([$book_id, $patron_id, $borrowed_at, $due_date]);
    $borrow_id = $pdo->lastInsertId();
    
    // Update copy status
    $stmt = $pdo->prepare("
        UPDATE book_copies 
        SET status = 'borrowed'
        WHERE id = ?
    ");
    $stmt->execute([$selected_copy_id]);
    
    // Create copy transaction log
    $stmt = $pdo->prepare("
        INSERT INTO copy_transactions (book_copy_id, transaction_type, patron_id, from_status, to_status, notes)
        VALUES (?, 'borrowed', ?, 'available', 'borrowed', 'Student checkout via web request')
    ");
    $stmt->execute([$selected_copy_id, $patron_id]);
    
    // Create audit log
    require_once __DIR__ . '/../includes/audit.php';
    audit('borrow', 'books', $book_id, [
        'copy_id' => $selected_copy_id,
        'patron_id' => $patron_id,
        'borrow_id' => $borrow_id
    ]);
    
    // Create notification for librarians
    require_once __DIR__ . '/../includes/notify.php';
    notify_user(null, 'librarian', 'new_borrow', 
        "New book request: {$book['title']} (Patron #$patron_id)", 
        ['book_id' => $book_id, 'patron_id' => $patron_id, 'borrow_id' => $borrow_id]
    );
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'message' => "Book requested successfully! Due date: " . date('M d, Y', strtotime($due_date)),
        'borrow_id' => $borrow_id,
        'due_date' => $due_date,
        'reference' => "BORROW-$borrow_id"
    ]);
    
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}