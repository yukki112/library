<?php
// accept_all_reservations.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';

require_login();

// Only admins/librarians can process reservations
if (!in_array($_SESSION['user_role'], ['admin', 'librarian'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Get parameters
$book_id = isset($_GET['book_id']) ? (int)$_GET['book_id'] : 0;
$patron_id = isset($_GET['patron_id']) ? (int)$_GET['patron_id'] : 0;
$starting_reservation_id = isset($_GET['starting_reservation_id']) ? (int)$_GET['starting_reservation_id'] : 0;

if (!$book_id || !$patron_id || !$starting_reservation_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required parameters']);
    exit;
}

$pdo = DB::conn();

try {
    // Start transaction
    $pdo->beginTransaction();
    
    // Get all pending reservations for this book and patron
    $sql = "SELECT r.id, r.book_id, r.patron_id, r.book_copy_id 
            FROM reservations r 
            WHERE r.book_id = ? 
            AND r.patron_id = ? 
            AND r.status = 'pending'
            ORDER BY r.reserved_at ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$book_id, $patron_id]);
    $pendingReservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($pendingReservations)) {
        throw new Exception('No pending reservations found for this book and patron');
    }
    
    // Get available copies for this book (excluding those already borrowed by this patron)
    $sql = "SELECT bc.id as copy_id, bc.copy_number, bc.status 
            FROM book_copies bc 
            WHERE bc.book_id = ? 
            AND bc.status = 'available'
            AND bc.is_active = 1
            AND NOT EXISTS (
                SELECT 1 FROM borrow_logs bl 
                WHERE bl.book_copy_id = bc.id 
                AND bl.patron_id = ? 
                AND bl.status IN ('borrowed', 'overdue')
            )
            ORDER BY bc.id";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$book_id, $patron_id]);
    $availableCopies = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($availableCopies) < count($pendingReservations)) {
        throw new Exception('Not enough available copies. Available: ' . count($availableCopies) . ', Needed: ' . count($pendingReservations));
    }
    
    $processedCount = 0;
    $borrowLogsCreated = 0;
    
    // Process each pending reservation
    foreach ($pendingReservations as $index => $reservation) {
        $copy = $availableCopies[$index] ?? null;
        
        if (!$copy) {
            // No more copies available
            break;
        }
        
        $reservationId = $reservation['id'];
        $copyId = $copy['copy_id'];
        $copyNumber = $copy['copy_number'];
        
        // Update reservation status
        $updateReservationSql = "UPDATE reservations 
                                SET status = 'approved', 
                                    book_copy_id = ?,
                                    updated_at = NOW()
                                WHERE id = ?";
        $stmt = $pdo->prepare($updateReservationSql);
        $stmt->execute([$copyId, $reservationId]);
        
        // Update book copy status
        $updateCopySql = "UPDATE book_copies 
                         SET status = 'borrowed',
                             updated_at = NOW()
                         WHERE id = ?";
        $stmt = $pdo->prepare($updateCopySql);
        $stmt->execute([$copyId]);
        
        // Create borrow log
        $now = date('Y-m-d H:i:s');
        $dueDate = date('Y-m-d H:i:s', strtotime('+14 days'));
        
        $insertBorrowSql = "INSERT INTO borrow_logs 
                            (book_id, book_copy_id, patron_id, borrowed_at, due_date, status, notes) 
                            VALUES (?, ?, ?, ?, ?, 'borrowed', ?)";
        $stmt = $pdo->prepare($insertBorrowSql);
        $stmt->execute([
            $book_id,
            $copyId,
            $patron_id,
            $now,
            $dueDate,
            "Reservation ID: $reservationId - Copy: $copyNumber"
        ]);
        
        $borrowLogId = $pdo->lastInsertId();
        
        // Create audit log
        $auditSql = "INSERT INTO audit_logs (user_id, action, entity, entity_id, details) 
                     VALUES (?, 'create', 'borrow_logs', ?, 'Accept All Reservations - Reservation ID: $reservationId')";
        $stmt = $pdo->prepare($auditSql);
        $stmt->execute([$_SESSION['user_id'], $borrowLogId]);
        
        // Create notification
        $notificationSql = "INSERT INTO notifications (user_id, type, message, meta) 
                            VALUES ((SELECT id FROM users WHERE patron_id = ?), 'borrow_created', 
                                    'Your reservation has been approved and recorded as a borrow', 
                                    ?)";
        $stmt = $pdo->prepare($notificationSql);
        $stmt->execute([$patron_id, json_encode([
            'borrow_log_id' => $borrowLogId,
            'reservation_id' => $reservationId,
            'book_id' => $book_id,
            'borrowed_at' => $now,
            'due_date' => $dueDate
        ])]);
        
        $processedCount++;
        $borrowLogsCreated++;
    }
    
    // Commit transaction
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Successfully processed ' . $processedCount . ' reservation(s)',
        'reservations_processed' => $processedCount,
        'borrow_logs_created' => $borrowLogsCreated
    ]);
    
} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}