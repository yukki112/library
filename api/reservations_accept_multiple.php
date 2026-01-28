<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/config.php';

header('Content-Type: application/json');

// Verify CSRF token
if (!verify_csrf_token()) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit;
}

require_login('admin', 'librarian');

$pdo = DB::conn();

try {
    $pdo->beginTransaction();
    
    // Get input data
    $input = json_decode(file_get_contents('php://input'), true);
    $startingReservationId = (int)($input['starting_reservation_id'] ?? 0);
    $bookId = (int)($input['book_id'] ?? 0);
    $patronId = (int)($input['patron_id'] ?? 0);
    $maxCopies = (int)($input['max_copies'] ?? 1);
    $reservationType = $input['reservation_type'] ?? 'any_copy';
    
    if (!$startingReservationId || !$bookId || !$patronId) {
        throw new Exception('Missing required parameters');
    }
    
    // Get the starting reservation
    $stmt = $pdo->prepare("
        SELECT r.*, b.title, p.name as patron_name 
        FROM reservations r
        JOIN books b ON r.book_id = b.id
        JOIN patrons p ON r.patron_id = p.id
        WHERE r.id = ? AND r.status = 'pending'
    ");
    $stmt->execute([$startingReservationId]);
    $startingReservation = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$startingReservation) {
        throw new Exception('Starting reservation not found or not pending');
    }
    
    // Get all pending reservations for this book and patron
    $stmt = $pdo->prepare("
        SELECT r.* 
        FROM reservations r
        WHERE r.book_id = ? 
        AND r.patron_id = ?
        AND r.status = 'pending'
        ORDER BY r.id ASC
        LIMIT ?
    ");
    $stmt->execute([$bookId, $patronId, $maxCopies]);
    $pendingReservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $reservationsProcessed = 0;
    $borrowLogsCreated = 0;
    
    foreach ($pendingReservations as $reservation) {
        $reservationId = (int)$reservation['id'];
        $specificCopyId = $reservation['book_copy_id'] ? (int)$reservation['book_copy_id'] : null;
        
        // Find available copy
        if ($reservationType === 'specific_copy' && $specificCopyId) {
            // Check if specific copy is available
            $stmt = $pdo->prepare("
                SELECT id, status 
                FROM book_copies 
                WHERE id = ? AND book_id = ? AND status = 'available' AND is_active = 1
            ");
            $stmt->execute([$specificCopyId, $bookId]);
            $copy = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$copy) {
                // Specific copy not available, skip this reservation
                continue;
            }
            
            $copyId = $specificCopyId;
        } else {
            // Find any available copy
            $stmt = $pdo->prepare("
                SELECT id 
                FROM book_copies 
                WHERE book_id = ? AND status = 'available' AND is_active = 1
                ORDER BY id ASC
                LIMIT 1
            ");
            $stmt->execute([$bookId]);
            $copy = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$copy) {
                // No available copies
                break;
            }
            
            $copyId = (int)$copy['id'];
        }
        
        // Calculate dates
        $borrowPeriodDays = 14; // Default borrow period
        $borrowedAt = date('Y-m-d H:i:s');
        $dueDate = date('Y-m-d H:i:s', strtotime("+$borrowPeriodDays days"));
        
        // Create borrow log
        $stmt = $pdo->prepare("
            INSERT INTO borrow_logs (
                book_id, book_copy_id, patron_id, borrowed_at, due_date, 
                status, notes, created_at
            ) VALUES (?, ?, ?, ?, ?, 'borrowed', ?, NOW())
        ");
        $stmt->execute([
            $bookId,
            $copyId,
            $patronId,
            $borrowedAt,
            $dueDate,
            "Reservation ID $reservationId - " . ($startingReservation['notes'] ?? '')
        ]);
        
        $borrowLogId = $pdo->lastInsertId();
        $borrowLogsCreated++;
        
        // Update book copy status
        $stmt = $pdo->prepare("
            UPDATE book_copies 
            SET status = 'borrowed', updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$copyId]);
        
        // Update reservation status
        $stmt = $pdo->prepare("
            UPDATE reservations 
            SET status = 'approved', book_copy_id = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$copyId, $reservationId]);
        
        $reservationsProcessed++;
        
        // Create audit log
        $stmt = $pdo->prepare("
            INSERT INTO audit_logs (user_id, action, entity, entity_id, details, created_at)
            VALUES (?, 'update', 'reservations', ?, 'Approved reservation and created borrow log #?', NOW())
        ");
        $stmt->execute([
            $_SESSION['user_id'] ?? null,
            $reservationId,
            $borrowLogId
        ]);
        
        // Create notification
        $stmt = $pdo->prepare("
            INSERT INTO notifications (user_id, type, message, meta, created_at)
            VALUES (?, 'reservation_approved', ?, ?, NOW())
        ");
        $stmt->execute([
            $patronId,
            'Your reservation has been approved',
            json_encode(['reservation_id' => $reservationId, 'book_id' => $bookId])
        ]);
    }
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'reservations_processed' => $reservationsProcessed,
        'borrow_logs_created' => $borrowLogsCreated,
        'message' => "Processed $reservationsProcessed reservation(s) and created $borrowLogsCreated borrow log(s)"
    ]);
    
} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}