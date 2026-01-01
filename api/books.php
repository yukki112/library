<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/audit.php';

// Start session
start_app_session();

// Check if user is logged in and has staff role
$user = current_user();
if (!$user || !in_array($user['role'], ['admin','librarian','assistant'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$action = $_GET['action'] ?? '';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Create uploads directory if it doesn't exist
$upload_dir = __DIR__ . '/../uploads/covers/';
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

$pdo = DB::conn();

switch ($action) {
    case 'create':
        require_csrf();
        handleBookCreate($pdo, $upload_dir);
        break;
        
    case 'update':
        require_csrf();
        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'Book ID is required']);
            exit;
        }
        handleBookUpdate($pdo, $id, $upload_dir);
        break;
        
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
        exit;
}

function handleBookCreate($pdo, $upload_dir) {
    try {
        $pdo->beginTransaction();
        
        // Prepare book data
        $bookData = [
            'title' => trim($_POST['title'] ?? ''),
            'author' => trim($_POST['author'] ?? ''),
            'isbn' => !empty($_POST['isbn']) ? trim($_POST['isbn']) : null,
            'category_id' => !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null,
            'category' => !empty($_POST['category']) ? trim($_POST['category']) : null,
            'publisher' => !empty($_POST['publisher']) ? trim($_POST['publisher']) : null,
            'year_published' => !empty($_POST['year_published']) ? (int)$_POST['year_published'] : null,
            'description' => !empty($_POST['description']) ? trim($_POST['description']) : null,
            'is_active' => 1,
            'total_copies_cache' => 0,
            'available_copies_cache' => 0
        ];
        
        // Validate required fields
        if (empty($bookData['title']) || empty($bookData['author'])) {
            throw new Exception('Title and author are required');
        }
        
        // Handle cover image upload
        $cover_image = handleCoverImageUpload($_FILES['cover_image'] ?? null, $upload_dir);
        if ($cover_image) {
            $bookData['cover_image'] = $cover_image;
            $bookData['cover_image_cache'] = $cover_image;
        }
        
        // Insert book
        $columns = implode(', ', array_keys($bookData));
        $placeholders = ':' . implode(', :', array_keys($bookData));
        $sql = "INSERT INTO books ($columns) VALUES ($placeholders)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($bookData);
        
        $bookId = $pdo->lastInsertId();
        
        // Handle initial copies if provided
        if (!empty($_POST['initial_copies'])) {
            $count = (int)($_POST['initial_copies']['count'] ?? 0);
            $condition = $_POST['initial_copies']['condition'] ?? 'good';
            $notes = $_POST['initial_copies']['notes'] ?? '';
            
            if ($count > 0) {
                // Get the book title for copy number prefix
                $bookTitle = $bookData['title'];
                $prefix = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $bookTitle), 0, 3));
                if (empty($prefix)) $prefix = 'BK' . substr(str_pad($bookId, 3, '0', STR_PAD_LEFT), -3);
                
                // Find the highest copy number for this book
                $stmt = $pdo->prepare("SELECT MAX(CAST(SUBSTRING(copy_number, LENGTH(?)+1) AS UNSIGNED)) as max_num 
                                      FROM book_copies 
                                      WHERE book_id = ? AND copy_number LIKE ?");
                $stmt->execute([$prefix, $bookId, $prefix . '%']);
                $result = $stmt->fetch();
                $start_num = $result['max_num'] ? $result['max_num'] + 1 : 1;
                
                // Generate copies
                for ($i = 0; $i < $count; $i++) {
                    $copy_number = $prefix . str_pad($start_num + $i, 3, '0', STR_PAD_LEFT);
                    $barcode = 'LIB-' . $bookId . '-' . str_pad($start_num + $i, 3, '0', STR_PAD_LEFT);
                    
                    $stmt = $pdo->prepare("
                        INSERT INTO book_copies 
                        (book_id, copy_number, barcode, status, book_condition, notes, is_active)
                        VALUES (?, ?, ?, 'available', ?, ?, 1)
                    ");
                    
                    $stmt->execute([
                        $bookId,
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
                
                // Update the book's cache counts
                $updateStmt = $pdo->prepare("
                    UPDATE books 
                    SET total_copies_cache = ?,
                        available_copies_cache = ?
                    WHERE id = ?
                ");
                $updateStmt->execute([$count, $count, $bookId]);
            }
        }
        
        $pdo->commit();
        
        // Get the created book
        $stmt = $pdo->prepare("SELECT * FROM books WHERE id = ?");
        $stmt->execute([$bookId]);
        $book = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Audit log
        audit('create', 'books', $bookId, $book);
        
        http_response_code(201);
        echo json_encode($book);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    }
}

function handleBookUpdate($pdo, $id, $upload_dir) {
    try {
        // Check if book exists
        $stmt = $pdo->prepare("SELECT * FROM books WHERE id = ?");
        $stmt->execute([$id]);
        $existingBook = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$existingBook) {
            throw new Exception('Book not found');
        }
        
        $pdo->beginTransaction();
        
        // Prepare book data
        $bookData = [];
        $fields = ['title', 'author', 'isbn', 'category_id', 'category', 'publisher', 'year_published', 'description'];
        
        foreach ($fields as $field) {
            if (isset($_POST[$field])) {
                $value = trim($_POST[$field]);
                $bookData[$field] = $value === '' ? null : $value;
            }
        }
        
        // Handle cover image upload
        if (!empty($_FILES['cover_image']['name'])) {
            // Delete old cover image if exists
            if ($existingBook['cover_image'] && file_exists($upload_dir . $existingBook['cover_image'])) {
                unlink($upload_dir . $existingBook['cover_image']);
            }
            if ($existingBook['cover_image_cache'] && file_exists($upload_dir . $existingBook['cover_image_cache'])) {
                unlink($upload_dir . $existingBook['cover_image_cache']);
            }
            
            $cover_image = handleCoverImageUpload($_FILES['cover_image'], $upload_dir);
            if ($cover_image) {
                $bookData['cover_image'] = $cover_image;
                $bookData['cover_image_cache'] = $cover_image;
            }
        }
        
        // Update book
        if (!empty($bookData)) {
            $set = [];
            foreach (array_keys($bookData) as $key) {
                $set[] = "$key = :$key";
            }
            
            $sql = "UPDATE books SET " . implode(', ', $set) . " WHERE id = :id";
            $bookData['id'] = $id;
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($bookData);
        }
        
        $pdo->commit();
        
        // Get the updated book
        $stmt = $pdo->prepare("SELECT * FROM books WHERE id = ?");
        $stmt->execute([$id]);
        $book = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Audit log
        audit('update', 'books', $id, $book);
        
        echo json_encode($book);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    }
}

function handleCoverImageUpload($file, $upload_dir) {
    if (!$file || empty($file['name']) || $file['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    
    $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/gif'];
    $max_size = 2 * 1024 * 1024; // 2MB
    
    // Validate file type
    if (!in_array($file['type'], $allowed_types)) {
        throw new Exception('Invalid file type. Please upload JPG, PNG, WebP or GIF.');
    }
    
    // Validate file size
    if ($file['size'] > $max_size) {
        throw new Exception('File size too large. Maximum size is 2MB.');
    }
    
    // Generate unique filename
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = uniqid('book_cover_', true) . '.' . $extension;
    $filepath = $upload_dir . $filename;
    
    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        throw new Exception('Failed to upload cover image.');
    }
    
    return $filename;
}