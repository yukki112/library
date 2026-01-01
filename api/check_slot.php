<?php
// check_slot.php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo = DB::conn();

// Get parameters
$section = $_GET['section'] ?? '';
$shelf = $_GET['shelf'] ?? '';
$row = $_GET['row'] ?? '';
$slot = $_GET['slot'] ?? '';
$copy_id = $_GET['copy_id'] ?? 0;

// Validate parameters
if (empty($section) || empty($shelf) || empty($row) || empty($slot)) {
    json_response(['error' => 'Missing location parameters'], 400);
    exit;
}

// Validate section format (A-F)
if (!preg_match('/^[A-F]$/', $section)) {
    json_response(['error' => 'Invalid section format. Must be A-F'], 400);
    exit;
}

// Validate numeric parameters
if (!is_numeric($shelf) || !is_numeric($row) || !is_numeric($slot)) {
    json_response(['error' => 'Shelf, row, and slot must be numeric'], 400);
    exit;
}

// Convert to integers for validation
$shelf = (int)$shelf;
$row = (int)$row;
$slot = (int)$slot;
$copy_id = (int)$copy_id;

// Validate ranges
if ($shelf < 1 || $shelf > 5) {
    json_response(['error' => 'Shelf must be between 1-5'], 400);
    exit;
}

if ($row < 1 || $row > 6) {
    json_response(['error' => 'Row must be between 1-6'], 400);
    exit;
}

if ($slot < 1 || $slot > 12) {
    json_response(['error' => 'Slot must be between 1-12'], 400);
    exit;
}

try {
    // Check if the slot is occupied
    $query = "SELECT 
        bc.id,
        bc.copy_number,
        bc.status,
        b.title as book_title,
        b.author as book_author
    FROM book_copies bc
    JOIN books b ON bc.book_id = b.id
    WHERE bc.current_section = ?
      AND bc.current_shelf = ?
      AND bc.current_row = ?
      AND bc.current_slot = ?
      AND bc.is_active = 1";

    $params = [$section, $shelf, $row, $slot];

    // Exclude the current copy if we're checking for editing
    if ($copy_id > 0) {
        $query .= " AND bc.id != ?";
        $params[] = $copy_id;
    }

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $occupant = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($occupant) {
        json_response([
            'occupied' => true,
            'copy_id' => $occupant['id'],
            'copy_number' => $occupant['copy_number'],
            'status' => $occupant['status'],
            'book_title' => $occupant['book_title'],
            'book_author' => $occupant['book_author']
        ]);
    } else {
        json_response(['occupied' => false]);
    }
    
} catch (Exception $e) {
    json_response(['error' => 'Database error: ' . $e->getMessage()], 500);
}