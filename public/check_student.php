<?php
require_once __DIR__ . '/../includes/db.php';
$pdo = DB::conn();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $library_id = $_POST['library_id'] ?? '';
    
    if (empty($library_id)) {
        echo json_encode(['success' => false, 'message' => 'Library ID is required']);
        exit;
    }
    
    try {
        // Check if patron exists
        $stmt = $pdo->prepare("SELECT id, name, library_id, department, semester FROM patrons WHERE library_id = ?");
        $stmt->execute([$library_id]);
        $patron = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$patron) {
            echo json_encode(['success' => false, 'message' => 'Student not found']);
            exit;
        }
        
        // Check if student is in library
        $stmt = $pdo->prepare("SELECT id FROM library_attendance WHERE patron_id = ? AND status = 'in_library'");
        $stmt->execute([$patron['id']]);
        $is_in_library = $stmt->fetch() ? true : false;
        
        // Check active borrows
        $stmt = $pdo->prepare("SELECT COUNT(*) as active_borrows FROM borrow_logs WHERE patron_id = ? AND status IN ('borrowed', 'overdue')");
        $stmt->execute([$patron['id']]);
        $active_borrows = $stmt->fetch()['active_borrows'];
        
        // Check overdue count
        $stmt = $pdo->prepare("SELECT COUNT(*) as overdue_count FROM borrow_logs WHERE patron_id = ? AND status = 'overdue'");
        $stmt->execute([$patron['id']]);
        $overdue_count = $stmt->fetch()['overdue_count'];
        
        echo json_encode([
            'success' => true,
            'student' => $patron,
            'is_in_library' => $is_in_library,
            'active_borrows' => $active_borrows,
            'overdue_count' => $overdue_count
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
?>