<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

try {
    $pdo = DB::conn();
    
    // Disable foreign key checks
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    
    // Fix users table auto-increment
    $pdo->exec("ALTER TABLE users MODIFY COLUMN id INT(11) AUTO_INCREMENT");
    
    // Fix patrons table auto-increment
    $pdo->exec("ALTER TABLE patrons MODIFY COLUMN id INT(11) AUTO_INCREMENT");
    
    // Re-enable foreign key checks
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    
    echo "Auto-increment fixed successfully!";
    
    // Now test with a dummy insert
    $testStmt = $pdo->prepare("INSERT INTO users (username, password_hash, email, role, status) VALUES (?, ?, ?, ?, ?)");
    $testData = [
        'test_' . time(),
        password_hash('test123', PASSWORD_DEFAULT),
        'test@test.com',
        'student',
        'active'
    ];
    
    if ($testStmt->execute($testData)) {
        echo "<br>Test insert successful! ID: " . $pdo->lastInsertId();
    } else {
        echo "<br>Test insert failed.";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>