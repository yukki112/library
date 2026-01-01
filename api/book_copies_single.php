    <?php
    require_once __DIR__ . '/../includes/db.php';
    require_once __DIR__ . '/../includes/helpers.php';
    require_once __DIR__ . '/../includes/auth.php';

    $pdo = DB::conn();

    // Get copy_id from query string
    $copy_id = isset($_GET['copy_id']) ? (int)$_GET['copy_id'] : 0;

    if ($copy_id <= 0) {
        json_response(['error' => 'Invalid copy_id'], 400);
    }

    try {
        // GET - Fetch single copy
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $stmt = $pdo->prepare("SELECT * FROM book_copies WHERE id = ?");
            $stmt->execute([$copy_id]);
            $copy = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$copy) {
                json_response(['error' => 'Copy not found'], 404);
            }
            
            json_response($copy);
        }
        // PUT - Update copy
        else if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
            require_login();
            
            // Only staff can update copies
            if (!in_array(current_user()['role'], ['admin','librarian','assistant'], true)) {
                json_response(['error' => 'Unauthorized'], 403);
            }
            
            $data = json_decode(file_get_contents('php://input'), true);
            
            // Validate required fields
            if (!isset($data['copy_number']) || !isset($data['status']) || !isset($data['book_condition'])) {
                json_response(['error' => 'Missing required fields'], 400);
            }
            
            // Get current copy data
            $stmt = $pdo->prepare("SELECT * FROM book_copies WHERE id = ?");
            $stmt->execute([$copy_id]);
            $currentCopy = $stmt->fetch();
            
            if (!$currentCopy) {
                json_response(['error' => 'Copy not found'], 404);
            }
            
            $pdo->beginTransaction();
            
            // Update the copy
            $stmt = $pdo->prepare("
                UPDATE book_copies 
                SET copy_number = ?, 
                    barcode = ?,
                    status = ?,
                    book_condition = ?,
                    current_section = ?,
                    current_shelf = ?,
                    current_row = ?,
                    current_slot = ?,
                    notes = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            
            $stmt->execute([
                $data['copy_number'],
                $data['barcode'] ?? null,
                $data['status'],
                $data['book_condition'],
                $data['current_section'] ?? null,
                $data['current_shelf'] ?? null,
                $data['current_row'] ?? null,
                $data['current_slot'] ?? null,
                $data['notes'] ?? null,
                $copy_id
            ]);
            
            // Log status change if it changed
            if ($currentCopy['status'] !== $data['status']) {
                $stmt = $pdo->prepare("
                    INSERT INTO copy_transactions 
                    (book_copy_id, transaction_type, from_status, to_status, notes)
                    VALUES (?, 'status_change', ?, ?, ?)
                ");
                $stmt->execute([
                    $copy_id,
                    $currentCopy['status'],
                    $data['status'],
                    'Status updated via edit'
                ]);
            }
            
            $pdo->commit();
            
            json_response(['success' => true, 'message' => 'Copy updated successfully']);
        }
        // DELETE - Delete copy
        else if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
            require_login();
            
            // Only staff can delete copies
            if (!in_array(current_user()['role'], ['admin','librarian','assistant'], true)) {
                json_response(['error' => 'Unauthorized'], 403);
            }
            
            // Get copy data before deleting
            $stmt = $pdo->prepare("SELECT * FROM book_copies WHERE id = ?");
            $stmt->execute([$copy_id]);
            $copy = $stmt->fetch();
            
            if (!$copy) {
                json_response(['error' => 'Copy not found'], 404);
            }
            
            $pdo->beginTransaction();
            
            // Delete the copy (triggers will handle cache updates)
            $stmt = $pdo->prepare("DELETE FROM book_copies WHERE id = ?");
            $stmt->execute([$copy_id]);
            
            // Log the deletion
            $stmt = $pdo->prepare("
                INSERT INTO copy_transactions 
                (book_copy_id, transaction_type, from_status, to_status, notes)
                VALUES (?, 'deleted', ?, NULL, 'Copy permanently deleted')
            ");
            $stmt->execute([$copy_id, $copy['status']]);
            
            $pdo->commit();
            
            json_response(['success' => true, 'message' => 'Copy deleted successfully']);
        }
        else {
            json_response(['error' => 'Method not allowed'], 405);
        }
        
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_response(['error' => 'Database error: ' . $e->getMessage()], 500);
    }