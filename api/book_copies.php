<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';

$pdo = DB::conn();

// Handle POST request to add copies with AI location recommendation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_login();
    
    // Only staff can add copies
    if (!in_array(current_user()['role'], ['admin','librarian','assistant'], true)) {
        json_response(['error' => 'Unauthorized'], 403);
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Check if this is a location check request
    if (isset($_GET['check_location'])) {
        if (!isset($data['section']) || !isset($data['shelf']) || !isset($data['row']) || !isset($data['slot'])) {
            json_response(['error' => 'Missing location parameters'], 400);
        }
        
        $section = $data['section'];
        $shelf = $data['shelf'];
        $row = $data['row'];
        $slot = $data['slot'];
        
        try {
            // Check if location is occupied
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as occupied 
                FROM book_copies 
                WHERE current_section = ? 
                  AND current_shelf = ? 
                  AND current_row = ? 
                  AND current_slot = ?
                  AND is_active = 1
            ");
            $stmt->execute([$section, $shelf, $row, $slot]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            json_response(['occupied' => (int)$result['occupied'] > 0]);
            
        } catch (Exception $e) {
            json_response(['error' => 'Database error: ' . $e->getMessage()], 500);
        }
        exit;
    }
    
    // Original POST logic for adding copies
    // Validate input
    if (!isset($data['book_id']) || !isset($data['count']) || !isset($data['condition'])) {
        json_response(['error' => 'Missing required fields'], 400);
    }
    
    $book_id = (int)$data['book_id'];
    $count = (int)$data['count'];
    $condition = $data['condition'];
    $notes = $data['notes'] ?? '';
    $auto_location = isset($data['auto_location']) ? (bool)$data['auto_location'] : true;
    
    if ($book_id <= 0 || $count <= 0) {
        json_response(['error' => 'Invalid parameters'], 400);
    }
    
    try {
        $pdo->beginTransaction();
        
        // Get the book to verify it exists
        $stmt = $pdo->prepare("SELECT * FROM books WHERE id = ?");
        $stmt->execute([$book_id]);
        $book = $stmt->fetch();
        
        if (!$book) {
            $pdo->rollBack();
            json_response(['error' => 'Book not found'], 404);
        }
        
        // Get AI recommendation for book category
        $ai_location = null;
        if ($auto_location && $book['category_id']) {
            $ai_location = get_ai_recommendation($pdo, $book['category_id'], $book_id);
        }
        
        // Find the highest copy number for this book
        $stmt = $pdo->prepare("SELECT MAX(CAST(SUBSTRING(copy_number, LENGTH(?)+1) AS UNSIGNED)) as max_num 
                              FROM book_copies 
                              WHERE book_id = ? AND copy_number LIKE ?");
        $prefix = strtoupper(substr($book['title'], 0, 3));
        $stmt->execute([$prefix, $book_id, $prefix . '%']);
        $result = $stmt->fetch();
        $start_num = $result['max_num'] ? $result['max_num'] + 1 : 1;
        
        // Generate unique copies
        $copies_added = [];
        $location_tracker = $ai_location ? [
            'section' => $ai_location['section'],
            'shelf' => $ai_location['shelf'],
            'row' => $ai_location['row'],
            'slot' => $ai_location['slot']
        ] : null;
        
        for ($i = 0; $i < $count; $i++) {
            $copy_number = $prefix . str_pad($start_num + $i, 3, '0', STR_PAD_LEFT);
            $barcode = 'LIB-' . $book_id . '-' . str_pad($start_num + $i, 3, '0', STR_PAD_LEFT);
            
            // Determine location for this copy
            $location_data = null;
            if ($location_tracker && $auto_location) {
                $location_data = find_available_location($pdo, $location_tracker);
                $location_tracker = $location_data['next_location'];
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO book_copies 
                (book_id, copy_number, barcode, status, book_condition, 
                 current_section, current_shelf, current_row, current_slot,
                 notes, is_active)
                VALUES (?, ?, ?, 'available', ?, ?, ?, ?, ?, ?, 1)
            ");
            
            $stmt->execute([
                $book_id,
                $copy_number,
                $barcode,
                $condition,
                $location_data['current']['section'] ?? null,
                $location_data['current']['shelf'] ?? null,
                $location_data['current']['row'] ?? null,
                $location_data['current']['slot'] ?? null,
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
            
            // Record AI placement if applicable
            if ($location_data && $ai_location) {
                $stmt = $pdo->prepare("
                    INSERT INTO ai_placement_history 
                    (book_id, category_id, recommended_section, recommended_shelf, 
                     recommended_row, recommended_slot, actual_section, actual_shelf, 
                     actual_row, actual_slot, confidence_score, is_correct, notes)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)
                ");
                
                $stmt->execute([
                    $book_id,
                    $book['category_id'],
                    $ai_location['section'],
                    $ai_location['shelf'],
                    $ai_location['row'],
                    $ai_location['slot'],
                    $location_data['current']['section'],
                    $location_data['current']['shelf'],
                    $location_data['current']['row'],
                    $location_data['current']['slot'],
                    0.95, // Confidence score
                    'Auto-assigned during copy creation'
                ]);
            }
            
            $copies_added[] = [
                'id' => $copy_id,
                'copy_number' => $copy_number,
                'barcode' => $barcode,
                'location' => $location_data ? 
                    "{$location_data['current']['section']}-S{$location_data['current']['shelf']}-R{$location_data['current']['row']}-P{$location_data['current']['slot']}" : 
                    null
            ];
        }
        
        $pdo->commit();
        
        // Return success with copies info
        json_response([
            'success' => true,
            'message' => "Successfully added $count copies",
            'copies_added' => $copies_added,
            'ai_recommendation' => $ai_location ? [
                'section' => $ai_location['section'],
                'shelf' => $ai_location['shelf'],
                'row' => $ai_location['row'],
                'slot' => $ai_location['slot'],
                'location' => "{$ai_location['section']}-S{$ai_location['shelf']}-R{$ai_location['row']}-P{$ai_location['slot']}"
            ] : null
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        json_response(['error' => 'Database error: ' . $e->getMessage()], 500);
    }
}
// Handle GET requests for existing functionality
else if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $book_id = isset($_GET['book_id']) ? (int)$_GET['book_id'] : 0;
    $summary = isset($_GET['summary']) && $_GET['summary'] == 'true';
    $action = $_GET['action'] ?? '';

    // Handle AI recommendation requests
    if ($action === 'ai_recommendation' && $book_id > 0) {
        try {
            // Get book and category
            $stmt = $pdo->prepare("
                SELECT b.*, c.default_section, c.shelf_recommendation, 
                       c.row_recommendation, c.slot_recommendation
                FROM books b
                LEFT JOIN categories c ON b.category_id = c.id
                WHERE b.id = ?
            ");
            $stmt->execute([$book_id]);
            $book = $stmt->fetch();
            
            if (!$book) {
                json_response(['error' => 'Book not found'], 404);
            }
            
            if (!$book['category_id']) {
                json_response(['error' => 'Book has no category for AI recommendation'], 400);
            }
            
            // Get AI recommendation
            $recommendation = get_ai_recommendation($pdo, $book['category_id'], $book_id);
            
            // Check if location is available
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as occupied 
                FROM book_copies 
                WHERE current_section = ? 
                  AND current_shelf = ? 
                  AND current_row = ? 
                  AND current_slot = ?
            ");
            $stmt->execute([
                $recommendation['section'],
                $recommendation['shelf'],
                $recommendation['row'],
                $recommendation['slot']
            ]);
            $occupied = $stmt->fetchColumn();
            
            if ($occupied > 0) {
                // Find alternative location
                $alternative = find_available_location_nearby($pdo, $recommendation);
                $recommendation = $alternative;
            }
            
            json_response([
                'book_id' => $book_id,
                'title' => $book['title'],
                'category_id' => $book['category_id'],
                'recommendation' => $recommendation,
                'location' => "{$recommendation['section']}-S{$recommendation['shelf']}-R{$recommendation['row']}-P{$recommendation['slot']}",
                'available' => $occupied == 0
            ]);
            
        } catch (Exception $e) {
            json_response(['error' => $e->getMessage()], 500);
        }
        exit;
    }
    
    if ($action === 'available_locations') {
        $section = $_GET['section'] ?? 'A';
        
        try {
            $locations = get_available_locations($pdo, $section);
            json_response($locations);
        } catch (Exception $e) {
            json_response(['error' => $e->getMessage()], 500);
        }
        exit;
    }

    if ($book_id <= 0 && !isset($_GET['check_availability'])) {
        json_response(['error' => 'Invalid book_id'], 400);
    }

    try {
        if ($summary) {
            // Return summary counts by status
            $stmt = $pdo->prepare("
                SELECT status, COUNT(*) as count 
                FROM book_copies 
                WHERE book_id = ? 
                GROUP BY status
            ");
            $stmt->execute([$book_id]);
            $results = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            
            // Ensure all statuses are represented
            $allStatuses = ['available', 'borrowed', 'reserved', 'lost', 'damaged', 'maintenance'];
            $summaryData = [];
            foreach ($allStatuses as $status) {
                $summaryData[$status] = $results[$status] ?? 0;
            }
            
            json_response($summaryData);
        } else if (isset($_GET['check_availability'])) {
            // Check location availability
            $section = $_GET['section'] ?? '';
            $shelf = isset($_GET['shelf']) ? (int)$_GET['shelf'] : 0;
            $row = isset($_GET['row']) ? (int)$_GET['row'] : 0;
            $slot = isset($_GET['slot']) ? (int)$_GET['slot'] : 0;
            
            if (!$section || $shelf <= 0 || $row <= 0 || $slot <= 0) {
                json_response(['error' => 'Invalid location parameters'], 400);
            }
            
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as occupied 
                FROM book_copies 
                WHERE current_section = ? 
                  AND current_shelf = ? 
                  AND current_row = ? 
                  AND current_slot = ?
                  AND is_active = 1
            ");
            $stmt->execute([$section, $shelf, $row, $slot]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            json_response([
                'available' => (int)$result['occupied'] == 0,
                'section' => $section,
                'shelf' => $shelf,
                'row' => $row,
                'slot' => $slot
            ]);
        } else {
            // Return all copies with details
            $stmt = $pdo->prepare("
                SELECT bc.*, 
                       c.default_section as recommended_section,
                       c.shelf_recommendation as recommended_shelf,
                       c.row_recommendation as recommended_row,
                       c.slot_recommendation as recommended_slot
                FROM book_copies bc
                LEFT JOIN books b ON bc.book_id = b.id
                LEFT JOIN categories c ON b.category_id = c.id
                WHERE bc.book_id = ? 
                ORDER BY bc.copy_number
            ");
            $stmt->execute([$book_id]);
            $copies = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Add AI location recommendation for copies without location
            foreach ($copies as &$copy) {
                if (!$copy['current_section'] && $copy['recommended_section']) {
                    $copy['ai_recommendation'] = [
                        'section' => $copy['recommended_section'],
                        'shelf' => $copy['recommended_shelf'],
                        'row' => $copy['recommended_row'],
                        'slot' => $copy['recommended_slot'],
                        'location' => "{$copy['recommended_section']}-S{$copy['recommended_shelf']}-R{$copy['recommended_row']}-P{$copy['recommended_slot']}"
                    ];
                }
            }
            
            json_response($copies);
        }
    } catch (Exception $e) {
        json_response(['error' => $e->getMessage()], 500);
    }
} else {
    json_response(['error' => 'Method not allowed'], 405);
}

// Helper functions for AI location recommendations

function get_ai_recommendation($pdo, $category_id, $book_id) {
    // Get category recommendations
    $stmt = $pdo->prepare("
        SELECT default_section, shelf_recommendation, row_recommendation, slot_recommendation
        FROM categories 
        WHERE id = ?
    ");
    $stmt->execute([$category_id]);
    $category = $stmt->fetch();
    
    if (!$category) {
        return [
            'section' => 'A',
            'shelf' => 1,
            'row' => 1,
            'slot' => 1
        ];
    }
    
    // Check if location is available
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as occupied 
        FROM book_copies 
        WHERE current_section = ? 
          AND current_shelf = ? 
          AND current_row = ? 
          AND current_slot = ?
    ");
    $stmt->execute([
        $category['default_section'],
        $category['shelf_recommendation'],
        $category['row_recommendation'],
        $category['slot_recommendation']
    ]);
    $occupied = $stmt->fetchColumn();
    
    if ($occupied == 0) {
        return [
            'section' => $category['default_section'],
            'shelf' => $category['shelf_recommendation'],
            'row' => $category['row_recommendation'],
            'slot' => $category['slot_recommendation']
        ];
    }
    
    // Find alternative location nearby
    return find_available_location_nearby($pdo, [
        'section' => $category['default_section'],
        'shelf' => $category['shelf_recommendation'],
        'row' => $category['row_recommendation'],
        'slot' => $category['slot_recommendation']
    ]);
}

function find_available_location($pdo, $start_location) {
    $section = $start_location['section'];
    $shelf = $start_location['shelf'];
    $row = $start_location['row'];
    $slot = $start_location['slot'];
    
    // Get map configuration for this section
    $stmt = $pdo->prepare("
        SELECT shelf_count, rows_per_shelf, slots_per_row 
        FROM library_map_config 
        WHERE section = ?
    ");
    $stmt->execute([$section]);
    $config = $stmt->fetch();
    
    if (!$config) {
        // Default configuration if not found
        $config = [
            'shelf_count' => 5,
            'rows_per_shelf' => 6,
            'slots_per_row' => 12
        ];
    }
    
    // Try to find available location starting from current position
    for ($attempt = 0; $attempt < 100; $attempt++) {
        // Check current location
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as occupied 
            FROM book_copies 
            WHERE current_section = ? 
              AND current_shelf = ? 
              AND current_row = ? 
              AND current_slot = ?
        ");
        $stmt->execute([$section, $shelf, $row, $slot]);
        
        if ($stmt->fetchColumn() == 0) {
            // Location is available
            $current_location = [
                'section' => $section,
                'shelf' => $shelf,
                'row' => $row,
                'slot' => $slot
            ];
            
            // Calculate next location for subsequent copies
            $next_slot = $slot + 1;
            $next_row = $row;
            $next_shelf = $shelf;
            
            if ($next_slot > $config['slots_per_row']) {
                $next_slot = 1;
                $next_row++;
                
                if ($next_row > $config['rows_per_shelf']) {
                    $next_row = 1;
                    $next_shelf++;
                    
                    if ($next_shelf > $config['shelf_count']) {
                        $next_shelf = 1;
                        // Could move to next section here if needed
                    }
                }
            }
            
            $next_location = [
                'section' => $section,
                'shelf' => $next_shelf,
                'row' => $next_row,
                'slot' => $next_slot
            ];
            
            return [
                'current' => $current_location,
                'next_location' => $next_location
            ];
        }
        
        // Move to next slot
        $slot++;
        if ($slot > $config['slots_per_row']) {
            $slot = 1;
            $row++;
            
            if ($row > $config['rows_per_shelf']) {
                $row = 1;
                $shelf++;
                
                if ($shelf > $config['shelf_count']) {
                    $shelf = 1;
                    // Reset to start if we've checked all locations
                    if ($attempt > 50) {
                        // Try a different approach - find any available slot
                        return find_any_available_location($pdo, $section);
                    }
                }
            }
        }
    }
    
    // If no location found, return default
    return [
        'current' => [
            'section' => $section,
            'shelf' => 1,
            'row' => 1,
            'slot' => 1
        ],
        'next_location' => [
            'section' => $section,
            'shelf' => 1,
            'row' => 1,
            'slot' => 2
        ]
    ];
}

function find_available_location_nearby($pdo, $start_location) {
    $section = $start_location['section'];
    $shelf = $start_location['shelf'];
    $row = $start_location['row'];
    $slot = $start_location['slot'];
    
    // Get map configuration
    $stmt = $pdo->prepare("
        SELECT shelf_count, rows_per_shelf, slots_per_row 
        FROM library_map_config 
        WHERE section = ?
    ");
    $stmt->execute([$section]);
    $config = $stmt->fetch();
    
    if (!$config) {
        $config = [
            'shelf_count' => 5,
            'rows_per_shelf' => 6,
            'slots_per_row' => 12
        ];
    }
    
    // Search in expanding radius
    $max_radius = 3;
    
    for ($radius = 1; $radius <= $max_radius; $radius++) {
        // Check positions within radius
        for ($ds = -$radius; $ds <= $radius; $ds++) {
            for ($dr = -$radius; $dr <= $radius; $dr++) {
                for ($dslot = -$radius; $dslot <= $radius; $dslot++) {
                    // Skip if all deltas are within smaller radius
                    if (abs($ds) < $radius && abs($dr) < $radius && abs($dslot) < $radius) {
                        continue;
                    }
                    
                    $check_shelf = $shelf + $ds;
                    $check_row = $row + $dr;
                    $check_slot = $slot + $dslot;
                    
                    // Validate bounds
                    if ($check_shelf < 1 || $check_shelf > $config['shelf_count'] ||
                        $check_row < 1 || $check_row > $config['rows_per_shelf'] ||
                        $check_slot < 1 || $check_slot > $config['slots_per_row']) {
                        continue;
                    }
                    
                    // Check availability
                    $stmt = $pdo->prepare("
                        SELECT COUNT(*) as occupied 
                        FROM book_copies 
                        WHERE current_section = ? 
                          AND current_shelf = ? 
                          AND current_row = ? 
                          AND current_slot = ?
                    ");
                    $stmt->execute([$section, $check_shelf, $check_row, $check_slot]);
                    
                    if ($stmt->fetchColumn() == 0) {
                        return [
                            'section' => $section,
                            'shelf' => $check_shelf,
                            'row' => $check_row,
                            'slot' => $check_slot,
                            'distance' => $radius
                        ];
                    }
                }
            }
        }
    }
    
    // If no nearby location found, return the original
    return $start_location;
}

function find_any_available_location($pdo, $section) {
    // Get map configuration
    $stmt = $pdo->prepare("
        SELECT shelf_count, rows_per_shelf, slots_per_row 
        FROM library_map_config 
        WHERE section = ?
    ");
    $stmt->execute([$section]);
    $config = $stmt->fetch();
    
    if (!$config) {
        $config = [
            'shelf_count' => 5,
            'rows_per_shelf' => 6,
            'slots_per_row' => 12
        ];
    }
    
    // Search systematically through all locations
    for ($shelf = 1; $shelf <= $config['shelf_count']; $shelf++) {
        for ($row = 1; $row <= $config['rows_per_shelf']; $row++) {
            for ($slot = 1; $slot <= $config['slots_per_row']; $slot++) {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) as occupied 
                    FROM book_copies 
                    WHERE current_section = ? 
                      AND current_shelf = ? 
                      AND current_row = ? 
                      AND current_slot = ?
                ");
                $stmt->execute([$section, $shelf, $row, $slot]);
                
                if ($stmt->fetchColumn() == 0) {
                    $current_location = [
                        'section' => $section,
                        'shelf' => $shelf,
                        'row' => $row,
                        'slot' => $slot
                    ];
                    
                    // Calculate next location
                    $next_slot = $slot + 1;
                    $next_row = $row;
                    $next_shelf = $shelf;
                    
                    if ($next_slot > $config['slots_per_row']) {
                        $next_slot = 1;
                        $next_row++;
                        
                        if ($next_row > $config['rows_per_shelf']) {
                            $next_row = 1;
                            $next_shelf++;
                            
                            if ($next_shelf > $config['shelf_count']) {
                                $next_shelf = 1;
                            }
                        }
                    }
                    
                    $next_location = [
                        'section' => $section,
                        'shelf' => $next_shelf,
                        'row' => $next_row,
                        'slot' => $next_slot
                    ];
                    
                    return [
                        'current' => $current_location,
                        'next_location' => $next_location
                    ];
                }
            }
        }
    }
    
    // If no location found in this section, try next section
    $next_section = chr(ord($section) + 1);
    if ($next_section > 'F') $next_section = 'A';
    
    return [
        'current' => [
            'section' => $next_section,
            'shelf' => 1,
            'row' => 1,
            'slot' => 1
        ],
        'next_location' => [
            'section' => $next_section,
            'shelf' => 1,
            'row' => 1,
            'slot' => 2
        ]
    ];
}

function get_available_locations($pdo, $section) {
    // Get map configuration
    $stmt = $pdo->prepare("
        SELECT shelf_count, rows_per_shelf, slots_per_row 
        FROM library_map_config 
        WHERE section = ?
    ");
    $stmt->execute([$section]);
    $config = $stmt->fetch();
    
    if (!$config) {
        $config = [
            'shelf_count' => 5,
            'rows_per_shelf' => 6,
            'slots_per_row' => 12
        ];
    }
    
    $available_locations = [];
    
    // Check all locations in the section
    for ($shelf = 1; $shelf <= $config['shelf_count']; $shelf++) {
        for ($row = 1; $row <= $config['rows_per_shelf']; $row++) {
            for ($slot = 1; $slot <= $config['slots_per_row']; $slot++) {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) as occupied 
                    FROM book_copies 
                    WHERE current_section = ? 
                      AND current_shelf = ? 
                      AND current_row = ? 
                      AND current_slot = ?
                ");
                $stmt->execute([$section, $shelf, $row, $slot]);
                
                if ($stmt->fetchColumn() == 0) {
                    $available_locations[] = [
                        'section' => $section,
                        'shelf' => $shelf,
                        'row' => $row,
                        'slot' => $slot,
                        'location' => "$section-S$shelf-R$row-P$slot"
                    ];
                }
            }
        }
    }
    
    return $available_locations;
}