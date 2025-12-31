<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';

$pdo = DB::conn();

// Handle GET requests for AI recommendations
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? 'recommend';
    $book_id = isset($_GET['book_id']) ? (int)$_GET['book_id'] : 0;
    $category_id = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;
    
    try {
        switch ($action) {
            case 'recommend':
                if ($book_id > 0) {
                    // Get recommendation for specific book
                    $stmt = $pdo->prepare("
                        SELECT 
                            b.id AS book_id,
                            b.title,
                            b.author,
                            c.id AS category_id,
                            c.name AS category_name,
                            c.default_section,
                            c.shelf_recommendation,
                            c.row_recommendation,
                            c.slot_recommendation,
                            CONCAT(
                                c.default_section,
                                '-S', LPAD(c.shelf_recommendation, 2, '0'),
                                '-R', LPAD(c.row_recommendation, 2, '0'),
                                '-P', LPAD(c.slot_recommendation, 2, '0')
                            ) AS ai_location
                        FROM books b
                        LEFT JOIN categories c ON b.category_id = c.id
                        WHERE b.id = ? AND b.is_active = 1
                    ");
                    $stmt->execute([$book_id]);
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$result) {
                        json_response(['error' => 'Book not found'], 404);
                    }
                    
                    // Check if location is available
                    $stmt = $pdo->prepare("
                        SELECT COUNT(*) as occupied 
                        FROM book_copies bc2 
                        WHERE bc2.current_section = ? 
                          AND bc2.current_shelf = ?
                          AND bc2.current_row = ?
                          AND bc2.current_slot = ?
                    ");
                    $stmt->execute([
                        $result['default_section'],
                        $result['shelf_recommendation'],
                        $result['row_recommendation'],
                        $result['slot_recommendation']
                    ]);
                    $occupied = $stmt->fetchColumn();
                    
                    $result['location_occupied'] = $occupied;
                    
                    // If location is occupied, find alternative
                    if ($occupied > 0) {
                        $alternative = find_available_slot_nearby(
                            $pdo, 
                            $result['default_section'],
                            $result['shelf_recommendation'],
                            $result['row_recommendation'],
                            $result['slot_recommendation']
                        );
                        
                        if ($alternative) {
                            $result['default_section'] = $alternative['section'];
                            $result['shelf_recommendation'] = $alternative['shelf'];
                            $result['row_recommendation'] = $alternative['row'];
                            $result['slot_recommendation'] = $alternative['slot'];
                            $result['ai_location'] = $alternative['location'];
                        }
                    }
                    
                    json_response($result);
                } else {
                    // Get all books without physical locations
                    $stmt = $pdo->prepare("
                        SELECT 
                            b.id AS book_id,
                            b.title,
                            b.author,
                            c.id AS category_id,
                            c.name AS category_name,
                            c.default_section,
                            c.shelf_recommendation,
                            c.row_recommendation,
                            c.slot_recommendation,
                            CONCAT(
                                c.default_section,
                                '-S', LPAD(c.shelf_recommendation, 2, '0'),
                                '-R', LPAD(c.row_recommendation, 2, '0'),
                                '-P', LPAD(c.slot_recommendation, 2, '0')
                            ) AS ai_location,
                            COUNT(bc.id) as total_copies,
                            SUM(CASE WHEN bc.current_section IS NULL THEN 1 ELSE 0 END) as needs_location
                        FROM books b
                        LEFT JOIN categories c ON b.category_id = c.id
                        LEFT JOIN book_copies bc ON b.id = bc.book_id AND bc.is_active = 1
                        WHERE b.is_active = 1
                        GROUP BY b.id, b.title, b.author, c.id, c.name, c.default_section,
                                 c.shelf_recommendation, c.row_recommendation, c.slot_recommendation
                        HAVING needs_location > 0 OR total_copies = 0
                        ORDER BY c.default_section, c.shelf_recommendation
                    ");
                    $stmt->execute();
                    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    json_response($results);
                }
                break;
                
            case 'library_map':
                // Get library map configuration
                $stmt = $pdo->query("
                    SELECT lmc.*, 
                           COUNT(DISTINCT bc.book_id) as book_count,
                           COUNT(DISTINCT c.id) as category_count
                    FROM library_map_config lmc
                    LEFT JOIN book_copies bc ON lmc.section = bc.current_section AND bc.is_active = 1
                    LEFT JOIN books b ON bc.book_id = b.id
                    LEFT JOIN categories c ON b.category_id = c.id
                    WHERE lmc.is_active = 1
                    GROUP BY lmc.id, lmc.section
                    ORDER BY lmc.section
                ");
                $sections = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Add AI recommendations to each section
                foreach ($sections as &$section) {
                    $stmt = $pdo->prepare("
                        SELECT COUNT(*) as recommendations
                        FROM books b
                        LEFT JOIN categories c ON b.category_id = c.id
                        WHERE c.default_section = ?
                          AND b.is_active = 1
                          AND NOT EXISTS (
                              SELECT 1 FROM book_copies bc 
                              WHERE bc.book_id = b.id 
                                AND bc.current_section IS NOT NULL
                                AND bc.is_active = 1
                          )
                    ");
                    $stmt->execute([$section['section']]);
                    $section['ai_recommendations'] = $stmt->fetchColumn();
                }
                
                json_response($sections);
                break;
                
            case 'search_location':
                $search = $_GET['search'] ?? '';
                $section = $_GET['section'] ?? '';
                
                $query = "
                    SELECT 
                        bc.current_section as section,
                        bc.current_shelf as shelf,
                        bc.current_row as row_number,
                        bc.current_slot as slot,
                        b.title,
                        b.author,
                        c.name as category_name,
                        bc.copy_number,
                        bc.status,
                        bc.book_condition
                    FROM book_copies bc
                    JOIN books b ON bc.book_id = b.id
                    LEFT JOIN categories c ON b.category_id = c.id
                    WHERE bc.current_section IS NOT NULL
                      AND bc.is_active = 1
                ";
                
                $params = [];
                
                if ($search) {
                    $query .= " AND (b.title LIKE ? OR b.author LIKE ? OR c.name LIKE ?)";
                    $searchTerm = "%$search%";
                    $params[] = $searchTerm;
                    $params[] = $searchTerm;
                    $params[] = $searchTerm;
                }
                
                if ($section) {
                    $query .= " AND bc.current_section = ?";
                    $params[] = $section;
                }
                
                $query .= " ORDER BY bc.current_section, bc.current_shelf, bc.current_row, bc.current_slot";
                
                $stmt = $pdo->prepare($query);
                $stmt->execute($params);
                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                json_response($results);
                break;
                
            default:
                json_response(['error' => 'Invalid action'], 400);
        }
        
    } catch (Exception $e) {
        json_response(['error' => $e->getMessage()], 500);
    }
}
// Handle POST requests to apply AI recommendations
else if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_login();
    
    // Only staff can apply AI recommendations
    if (!in_array(current_user()['role'], ['admin','librarian','assistant'], true)) {
        json_response(['error' => 'Unauthorized'], 403);
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['copy_id']) || !isset($data['section']) || !isset($data['shelf']) || !isset($data['row']) || !isset($data['slot'])) {
        json_response(['error' => 'Missing required fields'], 400);
    }
    
    $copy_id = (int)$data['copy_id'];
    $section = $data['section'];
    $shelf = $data['shelf'];
    $row = $data['row'];
    $slot = $data['slot'];
    
    try {
        $pdo->beginTransaction();
        
        // Get copy and book details
        $stmt = $pdo->prepare("
            SELECT bc.*, b.category_id, b.title as book_title
            FROM book_copies bc
            JOIN books b ON bc.book_id = b.id
            WHERE bc.id = ?
        ");
        $stmt->execute([$copy_id]);
        $copy = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$copy) {
            $pdo->rollBack();
            json_response(['error' => 'Copy not found'], 404);
        }
        
        // Check if location is available
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as occupied 
            FROM book_copies 
            WHERE current_section = ? 
              AND current_shelf = ? 
              AND current_row = ? 
              AND current_slot = ?
              AND id != ?
        ");
        $stmt->execute([$section, $shelf, $row, $slot, $copy_id]);
        $occupied = $stmt->fetchColumn();
        
        if ($occupied > 0) {
            // Find alternative location
            $alternative = find_available_slot_nearby($pdo, $section, $shelf, $row, $slot);
            if ($alternative) {
                $section = $alternative['section'];
                $shelf = $alternative['shelf'];
                $row = $alternative['row'];
                $slot = $alternative['slot'];
            } else {
                $pdo->rollBack();
                json_response(['error' => 'No available location found nearby'], 409);
            }
        }
        
        // Update the copy location
        $stmt = $pdo->prepare("
            UPDATE book_copies 
            SET current_section = ?,
                current_shelf = ?,
                current_row = ?,
                current_slot = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt->execute([$section, $shelf, $row, $slot, $copy_id]);
        
        // Record in AI history
        $category_id = $copy['category_id'];
        $stmt = $pdo->prepare("
            SELECT c.default_section, c.shelf_recommendation, c.row_recommendation, c.slot_recommendation
            FROM categories c
            WHERE c.id = ?
        ");
        $stmt->execute([$category_id]);
        $ai_recommendation = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($ai_recommendation) {
            $stmt = $pdo->prepare("
                INSERT INTO ai_placement_history 
                (book_id, category_id, recommended_section, recommended_shelf, recommended_row, recommended_slot,
                 actual_section, actual_shelf, actual_row, actual_slot, confidence_score, is_correct, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0.95, 1, ?)
            ");
            
            $notes = "Applied AI recommendation for: {$copy['book_title']}";
            $stmt->execute([
                $copy['book_id'],
                $category_id,
                $ai_recommendation['default_section'],
                $ai_recommendation['shelf_recommendation'],
                $ai_recommendation['row_recommendation'],
                $ai_recommendation['slot_recommendation'],
                $section,
                $shelf,
                $row,
                $slot,
                $notes
            ]);
        }
        
        $pdo->commit();
        
        json_response([
            'success' => true,
            'message' => 'Location updated successfully',
            'location' => "$section-S$shelf-R$row-P$slot"
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        json_response(['error' => 'Database error: ' . $e->getMessage()], 500);
    }
} else {
    json_response(['error' => 'Method not allowed'], 405);
}

// Helper function to find available slot nearby
function find_available_slot_nearby($pdo, $section, $shelf, $row, $slot) {
    // Get map configuration for this section
    $stmt = $pdo->prepare("SELECT * FROM library_map_config WHERE section = ?");
    $stmt->execute([$section]);
    $config = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$config) {
        return null;
    }
    
    // Search in expanding radius
    for ($radius = 1; $radius <= 3; $radius++) {
        // Check different combinations
        $checks = [
            // Same shelf, same row, different slots
            ['s' => $shelf, 'r' => $row, 'sl' => $slot + $radius],
            ['s' => $shelf, 'r' => $row, 'sl' => $slot - $radius],
            
            // Same shelf, different rows
            ['s' => $shelf, 'r' => $row + $radius, 'sl' => $slot],
            ['s' => $shelf, 'r' => $row - $radius, 'sl' => $slot],
            
            // Different shelves
            ['s' => $shelf + $radius, 'r' => $row, 'sl' => $slot],
            ['s' => $shelf - $radius, 'r' => $row, 'sl' => $slot],
        ];
        
        foreach ($checks as $check) {
            $s = $check['s'];
            $r = $check['r'];
            $sl = $check['sl'];
            
            // Check bounds
            if ($s >= 1 && $s <= $config['shelf_count'] &&
                $r >= 1 && $r <= $config['rows_per_shelf'] &&
                $sl >= 1 && $sl <= $config['slots_per_row']) {
                
                // Check if available
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) as occupied 
                    FROM book_copies 
                    WHERE current_section = ? 
                      AND current_shelf = ? 
                      AND current_row = ? 
                      AND current_slot = ?
                ");
                $stmt->execute([$section, $s, $r, $sl]);
                
                if ($stmt->fetchColumn() == 0) {
                    return [
                        'section' => $section,
                        'shelf' => $s,
                        'row' => $r,
                        'slot' => $sl,
                        'location' => "$section-S$s-R$r-P$sl"
                    ];
                }
            }
        }
    }
    
    return null;
}
?>