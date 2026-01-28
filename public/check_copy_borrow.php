<?php
require_once __DIR__ . '/../includes/db.php';
$pdo = DB::conn();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $copy_id = $_POST['copy_id'] ?? '';
    
    if (empty($copy_id)) {
        echo json_encode(['success' => false, 'message' => 'Copy ID is required']);
        exit;
    }
    
    try {
        // Check if this specific copy is already borrowed
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as borrowed_count 
            FROM borrow_logs 
            WHERE book_copy_id = ? AND status IN ('borrowed', 'overdue')
        ");
        $stmt->execute([$copy_id]);
        $borrowed_count = $stmt->fetch()['borrowed_count'];
        
        echo json_encode([
            'success' => true,
            'is_borrowed' => $borrowed_count > 0,
            'borrowed_count' => $borrowed_count
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
?>