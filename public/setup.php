<?php
// One-time setup: creates default users if none exist
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';

try {
    $pdo = DB::conn();

    // Ensure the reservations table exists. If it doesn't, create it by executing the SQL script in migrations/reservations.sql.
    try {
        // Check if the reservations table exists
        $check = $pdo->query("SHOW TABLES LIKE 'reservations'");
        if ($check->rowCount() === 0) {
            $sqlFile = __DIR__ . '/../migrations/reservations.sql';
            if (file_exists($sqlFile)) {
                $sql = file_get_contents($sqlFile);
                // Split on semicolon to execute each statement separately (in case multiple statements present)
                $statements = array_filter(array_map('trim', explode(';', $sql)));
                foreach ($statements as $stmtSql) {
                    if ($stmtSql !== '') {
                        $pdo->exec($stmtSql);
                    }
                }
                // Optional: output a message for debugging; comment out in production
                // echo "Reservations table created.\n";
            }
        }
    } catch (Throwable $e) {
        // Silently ignore errors when creating the reservations table to avoid disrupting setup
    }
    $count = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($count === 0) {
        $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, email, name, role, status) VALUES 
            (:u1,:p1,:e1,:n1,:r1,\'active\'),
            (:u2,:p2,:e2,:n2,:r2,\'active\'),
            (:u3,:p3,:e3,:n3,:r3,\'active\')');
        // Use pre-generated bcrypt hashes to avoid dependency on password_hash availability
        // admin123 => $2y$10$Q6r7Q7wQFQbQpGm1IhGZue.4JXGQk9M1WQ6L6fXg3eV4r2H3c1mFq
        // lib123   => $2y$10$7T0C6wC0V6U9Lk5p2aL7dOQipfKx2b0oYgq9K7z0lAq4bN2x3y8yS
        // assist123=> $2y$10$yK3D6yO2D3J9M1o3G6K4ye7zPFe5x3nYIf7j8Qk2yLbG1V9dQ3y2W
        $stmt->execute([
            ':u1'=>'admin',     ':p1'=>'$2y$10$Q6r7Q7wQFQbQpGm1IhGZue.4JXGQk9M1WQ6L6fXg3eV4r2H3c1mFq',     ':e1'=>'admin@library.edu',     ':n1'=>'System Admin',     ':r1'=>'admin',
            ':u2'=>'librarian', ':p2'=>'$2y$10$7T0C6wC0V6U9Lk5p2aL7dOQipfKx2b0oYgq9K7z0lAq4bN2x3y8yS',      ':e2'=>'librarian@library.edu', ':n2'=>'Head Librarian',    ':r2'=>'librarian',
            ':u3'=>'assistant', ':p3'=>'$2y$10$yK3D6yO2D3J9M1o3G6K4ye7zPFe5x3nYIf7j8Qk2yLbG1V9dQ3y2W',   ':e3'=>'assistant@library.edu', ':n3'=>'Assistant Librarian',':r3'=>'assistant',
        ]);
        // Seed sample books if none exist.  These books provide initial data for
        // reservations and borrowing.  You can remove or replace them as
        // needed.
        $bookCount = (int)$pdo->query('SELECT COUNT(*) FROM books')->fetchColumn();
        if ($bookCount === 0) {
            // Seed a larger set of books so that the Book Search page and reservations
            // feature work out of the box.  Each entry explicitly specifies its
            // primary key so that the IDs align with the curated list in
            // online_search.php.  Feel free to modify or extend this list
            // as needed.  The available_copies and total_copies values are
            // identical to simplify reservation logic.
            $stmtBooks = $pdo->prepare('
                INSERT INTO books
                    (id, title, author, isbn, category, publisher, year_published, total_copies, available_copies, description, is_active)
                VALUES
                    (1,  :t1, :a1, :i1, :c1, :p1, :y1, :tot1, :av1, :d1, 1),
                    (2,  :t2, :a2, :i2, :c2, :p2, :y2, :tot2, :av2, :d2, 1),
                    (3,  :t3, :a3, :i3, :c3, :p3, :y3, :tot3, :av3, :d3, 1),
                    (4,  :t4, :a4, :i4, :c4, :p4, :y4, :tot4, :av4, :d4, 1),
                    (5,  :t5, :a5, :i5, :c5, :p5, :y5, :tot5, :av5, :d5, 1),
                    (6,  :t6, :a6, :i6, :c6, :p6, :y6, :tot6, :av6, :d6, 1),
                    (7,  :t7, :a7, :i7, :c7, :p7, :y7, :tot7, :av7, :d7, 1),
                    (8,  :t8, :a8, :i8, :c8, :p8, :y8, :tot8, :av8, :d8, 1),
                    (9,  :t9, :a9, :i9, :c9, :p9, :y9, :tot9, :av9, :d9, 1),
                    (10, :t10, :a10, :i10, :c10, :p10, :y10, :tot10, :av10, :d10, 1),
                    (11, :t11, :a11, :i11, :c11, :p11, :y11, :tot11, :av11, :d11, 1),
                    (12, :t12, :a12, :i12, :c12, :p12, :y12, :tot12, :av12, :d12, 1),
                    (13, :t13, :a13, :i13, :c13, :p13, :y13, :tot13, :av13, :d13, 1),
                    (14, :t14, :a14, :i14, :c14, :p14, :y14, :tot14, :av14, :d14, 1),
                    (15, :t15, :a15, :i15, :c15, :p15, :y15, :tot15, :av15, :d15, 1),
                    (16, :t16, :a16, :i16, :c16, :p16, :y16, :tot16, :av16, :d16, 1),
                    (17, :t17, :a17, :i17, :c17, :p17, :y17, :tot17, :av17, :d17, 1),
                    (18, :t18, :a18, :i18, :c18, :p18, :y18, :tot18, :av18, :d18, 1),
                    (19, :t19, :a19, :i19, :c19, :p19, :y19, :tot19, :av19, :d19, 1),
                    (20, :t20, :a20, :i20, :c20, :p20, :y20, :tot20, :av20, :d20, 1)
            ');
            $stmtBooks->execute([
                // History titles
                ':t1'  => 'World History',            ':a1'  => 'John Brown',        ':i1'  => '9780000000001', ':c1'  => 'History',            ':p1'  => 'Generic Press', ':y1'  => 2001, ':tot1'  => 3, ':av1'  => 3, ':d1'  => 'Comprehensive overview of world history',
                ':t2'  => 'Ancient Civilizations',    ':a2'  => 'Mary Johnson',       ':i2'  => '9780000000002', ':c2'  => 'History',            ':p2'  => 'Generic Press', ':y2'  => 2002, ':tot2'  => 2, ':av2'  => 2, ':d2'  => 'Study of ancient societies',
                ':t3'  => 'Modern History Essentials',':a3'  => 'David Lee',          ':i3'  => '9780000000003', ':c3'  => 'History',            ':p3'  => 'Generic Press', ':y3'  => 2003, ':tot3'  => 4, ':av3'  => 4, ':d3'  => 'Guide to modern historical events',
                ':t4'  => 'Philippine History',       ':a4'  => 'Ana Cruz',           ':i4'  => '9780000000004', ':c4'  => 'History',            ':p4'  => 'Generic Press', ':y4'  => 2004, ':tot4'  => 1, ':av4'  => 1, ':d4'  => 'Chronicles of the Philippines',
                // Physical Education titles
                ':t5'  => 'Sports Science Basics',    ':a5'  => 'Alex Garcia',        ':i5'  => '9780000000005', ':c5'  => 'Physical Education', ':p5'  => 'Generic Press', ':y5'  => 2005, ':tot5'  => 5, ':av5'  => 5, ':d5'  => 'Introduction to sports science',
                ':t6'  => 'Health and Fitness',       ':a6'  => 'Emily Martinez',     ':i6'  => '9780000000006', ':c6'  => 'Physical Education', ':p6'  => 'Generic Press', ':y6'  => 2006, ':tot6'  => 2, ':av6'  => 2, ':d6'  => 'Guide to staying healthy and fit',
                ':t7'  => 'Introduction to PE',       ':a7'  => 'Sam Davis',          ':i7'  => '9780000000007', ':c7'  => 'Physical Education', ':p7'  => 'Generic Press', ':y7'  => 2007, ':tot7'  => 1, ':av7'  => 1, ':d7'  => 'Basics of physical education',
                ':t8'  => 'Team Sports Handbook',     ':a8'  => 'Linda Taylor',       ':i8'  => '9780000000008', ':c8'  => 'Physical Education', ':p8'  => 'Generic Press', ':y8'  => 2008, ':tot8'  => 3, ':av8'  => 3, ':d8'  => 'Rules and strategies for team sports',
                // Physics titles
                ':t9'  => 'Physics Fundamentals',     ':a9'  => 'Robert Wilson',      ':i9'  => '9780000000009', ':c9'  => 'Physics',            ':p9'  => 'Generic Press', ':y9'  => 2009, ':tot9'  => 3, ':av9'  => 3, ':d9'  => 'Core principles of physics',
                ':t10' => 'Quantum Mechanics Intro',  ':a10' => 'Patricia Moore',      ':i10' => '9780000000010', ':c10' => 'Physics',            ':p10' => 'Generic Press', ':y10' => 2010, ':tot10' => 2, ':av10' => 2, ':d10' => 'Introduction to quantum mechanics',
                ':t11' => 'Electricity and Magnetism',':a11' => 'James White',         ':i11' => '9780000000011', ':c11' => 'Physics',            ':p11' => 'Generic Press', ':y11' => 2011, ':tot11' => 4, ':av11' => 4, ':d11' => 'Understanding electromagnetism',
                ':t12' => 'Physics Experiments',      ':a12' => 'Lisa Martin',         ':i12' => '9780000000012', ':c12' => 'Physics',            ':p12' => 'Generic Press', ':y12' => 2012, ':tot12' => 2, ':av12' => 2, ':d12' => 'Hands-on physics experiments',
                // Mathematics titles
                ':t13' => 'Calculus I',               ':a13' => 'Michael Clark',       ':i13' => '9780000000013', ':c13' => 'Mathematics',        ':p13' => 'Generic Press', ':y13' => 2013, ':tot13' => 3, ':av13' => 3, ':d13' => 'Introductory calculus',
                ':t14' => 'Linear Algebra',           ':a14' => 'Barbara Lewis',        ':i14' => '9780000000014', ':c14' => 'Mathematics',        ':p14' => 'Generic Press', ':y14' => 2014, ':tot14' => 4, ':av14' => 4, ':d14' => 'Matrices and vector spaces',
                ':t15' => 'Probability & Statistics', ':a15' => 'William Young',       ':i15' => '9780000000015', ':c15' => 'Mathematics',        ':p15' => 'Generic Press', ':y15' => 2015, ':tot15' => 2, ':av15' => 2, ':d15' => 'Probability theory and statistics',
                ':t16' => 'Discrete Mathematics',     ':a16' => 'Nancy Hall',           ':i16' => '9780000000016', ':c16' => 'Mathematics',        ':p16' => 'Generic Press', ':y16' => 2016, ':tot16' => 1, ':av16' => 1, ':d16' => 'Logic and combinatorics',
                // Programming titles
                ':t17' => 'Learn C Programming',      ':a17' => 'Andrew Adams',         ':i17' => '9780000000017', ':c17' => 'Programming',         ':p17' => 'Generic Press', ':y17' => 2017, ':tot17' => 3, ':av17' => 3, ':d17' => 'Beginner''s guide to C programming',
                ':t18' => 'Python for Beginners',     ':a18' => 'Grace Nelson',         ':i18' => '9780000000018', ':c18' => 'Programming',         ':p18' => 'Generic Press', ':y18' => 2018, ':tot18' => 4, ':av18' => 4, ':d18' => 'Getting started with Python',
                ':t19' => 'JavaScript Essentials',    ':a19' => 'Chris Hernandez',      ':i19' => '9780000000019', ':c19' => 'Programming',         ':p19' => 'Generic Press', ':y19' => 2019, ':tot19' => 2, ':av19' => 2, ':d19' => 'Core JavaScript concepts',
                ':t20' => 'Java Fundamentals',        ':a20' => 'Olivia Turner',        ':i20' => '9780000000020', ':c20' => 'Programming',         ':p20' => 'Generic Press', ':y20' => 2020, ':tot20' => 2, ':av20' => 2, ':d20' => 'Fundamentals of Java programming',
            ]);
        }
        echo "Seeded default users and sample books. You can now delete this file or ignore it.";
    } else {
        // Update existing admin password to known default hash to ensure login works if the admin exists
        $adminHash = '$2y$10$Q6r7Q7wQFQbQpGm1IhGZue.4JXGQk9M1WQ6L6fXg3eV4r2H3c1mFq';
        $stmt = $pdo->prepare('UPDATE users SET password_hash = :h WHERE username = :u');
        $stmt->execute([':h' => $adminHash, ':u' => 'admin']);
        // Also seed sample books if none exist, even if users exist.  This allows
        // administrators to test reservations without manually adding books.
        $bookCountExisting = (int)$pdo->query('SELECT COUNT(*) FROM books')->fetchColumn();
        if ($bookCountExisting === 0) {
            // No books exist yet; insert the same 20-book seed used when
            // the database is first initialized.  Explicit IDs ensure
            // consistency with the curated search list.
            $stmtBooks2 = $pdo->prepare('
                INSERT INTO books
                    (id, title, author, isbn, category, publisher, year_published, total_copies, available_copies, description, is_active)
                VALUES
                    (1,  :t1, :a1, :i1, :c1, :p1, :y1, :tot1, :av1, :d1, 1),
                    (2,  :t2, :a2, :i2, :c2, :p2, :y2, :tot2, :av2, :d2, 1),
                    (3,  :t3, :a3, :i3, :c3, :p3, :y3, :tot3, :av3, :d3, 1),
                    (4,  :t4, :a4, :i4, :c4, :p4, :y4, :tot4, :av4, :d4, 1),
                    (5,  :t5, :a5, :i5, :c5, :p5, :y5, :tot5, :av5, :d5, 1),
                    (6,  :t6, :a6, :i6, :c6, :p6, :y6, :tot6, :av6, :d6, 1),
                    (7,  :t7, :a7, :i7, :c7, :p7, :y7, :tot7, :av7, :d7, 1),
                    (8,  :t8, :a8, :i8, :c8, :p8, :y8, :tot8, :av8, :d8, 1),
                    (9,  :t9, :a9, :i9, :c9, :p9, :y9, :tot9, :av9, :d9, 1),
                    (10, :t10, :a10, :i10, :c10, :p10, :y10, :tot10, :av10, :d10, 1),
                    (11, :t11, :a11, :i11, :c11, :p11, :y11, :tot11, :av11, :d11, 1),
                    (12, :t12, :a12, :i12, :c12, :p12, :y12, :tot12, :av12, :d12, 1),
                    (13, :t13, :a13, :i13, :c13, :p13, :y13, :tot13, :av13, :d13, 1),
                    (14, :t14, :a14, :i14, :c14, :p14, :y14, :tot14, :av14, :d14, 1),
                    (15, :t15, :a15, :i15, :c15, :p15, :y15, :tot15, :av15, :d15, 1),
                    (16, :t16, :a16, :i16, :c16, :p16, :y16, :tot16, :av16, :d16, 1),
                    (17, :t17, :a17, :i17, :c17, :p17, :y17, :tot17, :av17, :d17, 1),
                    (18, :t18, :a18, :i18, :c18, :p18, :y18, :tot18, :av18, :d18, 1),
                    (19, :t19, :a19, :i19, :c19, :p19, :y19, :tot19, :av19, :d19, 1),
                    (20, :t20, :a20, :i20, :c20, :p20, :y20, :tot20, :av20, :d20, 1)
            ');
            $stmtBooks2->execute([
                ':t1'  => 'World History',            ':a1'  => 'John Brown',        ':i1'  => '9780000000001', ':c1'  => 'History',            ':p1'  => 'Generic Press', ':y1'  => 2001, ':tot1'  => 3, ':av1'  => 3, ':d1'  => 'Comprehensive overview of world history',
                ':t2'  => 'Ancient Civilizations',    ':a2'  => 'Mary Johnson',       ':i2'  => '9780000000002', ':c2'  => 'History',            ':p2'  => 'Generic Press', ':y2'  => 2002, ':tot2'  => 2, ':av2'  => 2, ':d2'  => 'Study of ancient societies',
                ':t3'  => 'Modern History Essentials',':a3'  => 'David Lee',          ':i3'  => '9780000000003', ':c3'  => 'History',            ':p3'  => 'Generic Press', ':y3'  => 2003, ':tot3'  => 4, ':av3'  => 4, ':d3'  => 'Guide to modern historical events',
                ':t4'  => 'Philippine History',       ':a4'  => 'Ana Cruz',           ':i4'  => '9780000000004', ':c4'  => 'History',            ':p4'  => 'Generic Press', ':y4'  => 2004, ':tot4'  => 1, ':av4'  => 1, ':d4'  => 'Chronicles of the Philippines',
                ':t5'  => 'Sports Science Basics',    ':a5'  => 'Alex Garcia',        ':i5'  => '9780000000005', ':c5'  => 'Physical Education', ':p5'  => 'Generic Press', ':y5'  => 2005, ':tot5'  => 5, ':av5'  => 5, ':d5'  => 'Introduction to sports science',
                ':t6'  => 'Health and Fitness',       ':a6'  => 'Emily Martinez',     ':i6'  => '9780000000006', ':c6'  => 'Physical Education', ':p6'  => 'Generic Press', ':y6'  => 2006, ':tot6'  => 2, ':av6'  => 2, ':d6'  => 'Guide to staying healthy and fit',
                ':t7'  => 'Introduction to PE',       ':a7'  => 'Sam Davis',          ':i7'  => '9780000000007', ':c7'  => 'Physical Education', ':p7'  => 'Generic Press', ':y7'  => 2007, ':tot7'  => 1, ':av7'  => 1, ':d7'  => 'Basics of physical education',
                ':t8'  => 'Team Sports Handbook',     ':a8'  => 'Linda Taylor',       ':i8'  => '9780000000008', ':c8'  => 'Physical Education', ':p8'  => 'Generic Press', ':y8'  => 2008, ':tot8'  => 3, ':av8'  => 3, ':d8'  => 'Rules and strategies for team sports',
                ':t9'  => 'Physics Fundamentals',     ':a9'  => 'Robert Wilson',      ':i9'  => '9780000000009', ':c9'  => 'Physics',            ':p9'  => 'Generic Press', ':y9'  => 2009, ':tot9'  => 3, ':av9'  => 3, ':d9'  => 'Core principles of physics',
                ':t10' => 'Quantum Mechanics Intro',  ':a10' => 'Patricia Moore',      ':i10' => '9780000000010', ':c10' => 'Physics',            ':p10' => 'Generic Press', ':y10' => 2010, ':tot10' => 2, ':av10' => 2, ':d10' => 'Introduction to quantum mechanics',
                ':t11' => 'Electricity and Magnetism',':a11' => 'James White',         ':i11' => '9780000000011', ':c11' => 'Physics',            ':p11' => 'Generic Press', ':y11' => 2011, ':tot11' => 4, ':av11' => 4, ':d11' => 'Understanding electromagnetism',
                ':t12' => 'Physics Experiments',      ':a12' => 'Lisa Martin',         ':i12' => '9780000000012', ':c12' => 'Physics',            ':p12' => 'Generic Press', ':y12' => 2012, ':tot12' => 2, ':av12' => 2, ':d12' => 'Hands-on physics experiments',
                ':t13' => 'Calculus I',               ':a13' => 'Michael Clark',       ':i13' => '9780000000013', ':c13' => 'Mathematics',        ':p13' => 'Generic Press', ':y13' => 2013, ':tot13' => 3, ':av13' => 3, ':d13' => 'Introductory calculus',
                ':t14' => 'Linear Algebra',           ':a14' => 'Barbara Lewis',        ':i14' => '9780000000014', ':c14' => 'Mathematics',        ':p14' => 'Generic Press', ':y14' => 2014, ':tot14' => 4, ':av14' => 4, ':d14' => 'Matrices and vector spaces',
                ':t15' => 'Probability & Statistics', ':a15' => 'William Young',       ':i15' => '9780000000015', ':c15' => 'Mathematics',        ':p15' => 'Generic Press', ':y15' => 2015, ':tot15' => 2, ':av15' => 2, ':d15' => 'Probability theory and statistics',
                ':t16' => 'Discrete Mathematics',     ':a16' => 'Nancy Hall',           ':i16' => '9780000000016', ':c16' => 'Mathematics',        ':p16' => 'Generic Press', ':y16' => 2016, ':tot16' => 1, ':av16' => 1, ':d16' => 'Logic and combinatorics',
                ':t17' => 'Learn C Programming',      ':a17' => 'Andrew Adams',         ':i17' => '9780000000017', ':c17' => 'Programming',         ':p17' => 'Generic Press', ':y17' => 2017, ':tot17' => 3, ':av17' => 3, ':d17' => 'Beginner''s guide to C programming',
                ':t18' => 'Python for Beginners',     ':a18' => 'Grace Nelson',         ':i18' => '9780000000018', ':c18' => 'Programming',         ':p18' => 'Generic Press', ':y18' => 2018, ':tot18' => 4, ':av18' => 4, ':d18' => 'Getting started with Python',
                ':t19' => 'JavaScript Essentials',    ':a19' => 'Chris Hernandez',      ':i19' => '9780000000019', ':c19' => 'Programming',         ':p19' => 'Generic Press', ':y19' => 2019, ':tot19' => 2, ':av19' => 2, ':d19' => 'Core JavaScript concepts',
                ':t20' => 'Java Fundamentals',        ':a20' => 'Olivia Turner',        ':i20' => '9780000000020', ':c20' => 'Programming',         ':p20' => 'Generic Press', ':y20' => 2020, ':tot20' => 2, ':av20' => 2, ':d20' => 'Fundamentals of Java programming',
            ]);
        }
        echo "Admin password has been reset to default ('admin123') and sample books have been seeded if none existed. You can now delete this file or ignore it.";
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Error: ' . htmlspecialchars($e->getMessage());
}

