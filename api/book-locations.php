<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json');

if (!isset($_GET['book_id'])) {
    echo json_encode(['error' => 'Book ID required']);
    exit;
}

$book_id = intval($_GET['book_id']);

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
    
    echo json_encode([
        'sections' => $sections,
        'book_sections' => $bookSections
    ]);
    
} catch (Exception $e) {
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}