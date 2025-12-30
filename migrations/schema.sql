-- Create database (run in phpMyAdmin or mysql client)
CREATE DATABASE IF NOT EXISTS `lms_php` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `lms_php`;

-- Users (staff and roles)
CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(64) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  email VARCHAR(128) NOT NULL,
  name VARCHAR(128) NULL,
  phone VARCHAR(32) NULL,
  -- Store a physical address for the user.  This allows student registrations
  -- to capture mailing or residential addresses for notification or record
  -- keeping purposes.
  address VARCHAR(255) NULL,
  patron_id INT NULL,
  -- Include a teacher role in the enumeration.  Teachers operate similarly
  -- to assistants in terms of permissions but are displayed separately in
  -- the interface.  Existing installations should run the accompanying
  -- migration to alter the users.role column accordingly.
  role ENUM('admin','librarian','assistant','teacher','student','non_staff') NOT NULL DEFAULT 'student',
  twofa_enabled TINYINT(1) NOT NULL DEFAULT 0,
  twofa_code VARCHAR(16) NULL,
  twofa_expires_at DATETIME NULL,
  status ENUM('active','disabled') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
);

-- Patrons
CREATE TABLE IF NOT EXISTS patrons (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(128) NOT NULL,
  library_id VARCHAR(32) NOT NULL UNIQUE,
  email VARCHAR(128) NULL,
  phone VARCHAR(32) NULL,
  -- Track a patron's academic semester and department.  These fields are
  -- primarily used for students but are left nullable so that other
  -- patrons (such as non‑teaching staff) are not forced to provide
  -- academic details.  Add address to patrons to mirror user contact
  -- information at the membership level.
  semester VARCHAR(64) NULL,
  department VARCHAR(128) NULL,
  address VARCHAR(255) NULL,
  membership_date DATE NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
);

-- Books
CREATE TABLE IF NOT EXISTS books (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(255) NOT NULL,
  author VARCHAR(255) NOT NULL,
  isbn VARCHAR(32) UNIQUE,
  category VARCHAR(128),
  publisher VARCHAR(128),
  year_published INT,
  total_copies INT DEFAULT 0,
  available_copies INT DEFAULT 0,
  description TEXT,
  is_active TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
);

-- Seed sample books.  These entries align with the curated book list used
-- in online_search.php.  Explicit IDs ensure that the book IDs shown on
-- the search page match the database records.  Feel free to adjust or
-- replace these entries as needed.  total_copies and available_copies
-- are equal to simplify reservation logic.
INSERT INTO books (id, title, author, isbn, category, publisher, year_published, total_copies, available_copies, description, is_active) VALUES
  (1,  'World History','John Brown','9780000000001','History','Generic Press',2001,3,3,'Comprehensive overview of world history',1),
  (2,  'Ancient Civilizations','Mary Johnson','9780000000002','History','Generic Press',2002,2,2,'Study of ancient societies',1),
  (3,  'Modern History Essentials','David Lee','9780000000003','History','Generic Press',2003,4,4,'Guide to modern historical events',1),
  (4,  'Philippine History','Ana Cruz','9780000000004','History','Generic Press',2004,1,1,'Chronicles of the Philippines',1),
  (5,  'Sports Science Basics','Alex Garcia','9780000000005','Physical Education','Generic Press',2005,5,5,'Introduction to sports science',1),
  (6,  'Health and Fitness','Emily Martinez','9780000000006','Physical Education','Generic Press',2006,2,2,'Guide to staying healthy and fit',1),
  (7,  'Introduction to PE','Sam Davis','9780000000007','Physical Education','Generic Press',2007,1,1,'Basics of physical education',1),
  (8,  'Team Sports Handbook','Linda Taylor','9780000000008','Physical Education','Generic Press',2008,3,3,'Rules and strategies for team sports',1),
  (9,  'Physics Fundamentals','Robert Wilson','9780000000009','Physics','Generic Press',2009,3,3,'Core principles of physics',1),
  (10, 'Quantum Mechanics Intro','Patricia Moore','9780000000010','Physics','Generic Press',2010,2,2,'Introduction to quantum mechanics',1),
  (11, 'Electricity and Magnetism','James White','9780000000011','Physics','Generic Press',2011,4,4,'Understanding electromagnetism',1),
  (12, 'Physics Experiments','Lisa Martin','9780000000012','Physics','Generic Press',2012,2,2,'Hands-on physics experiments',1),
  (13, 'Calculus I','Michael Clark','9780000000013','Mathematics','Generic Press',2013,3,3,'Introductory calculus',1),
  (14, 'Linear Algebra','Barbara Lewis','9780000000014','Mathematics','Generic Press',2014,4,4,'Matrices and vector spaces',1),
  (15, 'Probability & Statistics','William Young','9780000000015','Mathematics','Generic Press',2015,2,2,'Probability theory and statistics',1),
  (16, 'Discrete Mathematics','Nancy Hall','9780000000016','Mathematics','Generic Press',2016,1,1,'Logic and combinatorics',1),
  (17, 'Learn C Programming','Andrew Adams','9780000000017','Programming','Generic Press',2017,3,3,'Beginner''s guide to C programming',1),
  (18, 'Python for Beginners','Grace Nelson','9780000000018','Programming','Generic Press',2018,4,4,'Getting started with Python',1),
  (19, 'JavaScript Essentials','Chris Hernandez','9780000000019','Programming','Generic Press',2019,2,2,'Core JavaScript concepts',1),
  (20, 'Java Fundamentals','Olivia Turner','9780000000020','Programming','Generic Press',2020,2,2,'Fundamentals of Java programming',1)
ON DUPLICATE KEY UPDATE id = VALUES(id);

-- Ebooks
CREATE TABLE IF NOT EXISTS ebooks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  book_id INT NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  file_format VARCHAR(16) NOT NULL,
  is_active TINYINT(1) DEFAULT 1,
  description TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE
);

-- Borrow logs
CREATE TABLE IF NOT EXISTS borrow_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  book_id INT NOT NULL,
  patron_id INT NOT NULL,
  borrowed_at DATETIME NOT NULL,
  due_date DATETIME NOT NULL,
  returned_at DATETIME NULL,
  status ENUM('borrowed','returned','overdue') NOT NULL DEFAULT 'borrowed',
  late_fee DECIMAL(10,2) NOT NULL DEFAULT 0,
  notes TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE RESTRICT,
  FOREIGN KEY (patron_id) REFERENCES patrons(id) ON DELETE RESTRICT
);

-- Reservations
CREATE TABLE IF NOT EXISTS reservations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  book_id INT NOT NULL,
  patron_id INT NOT NULL,
  reserved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  -- Include pending, approved and declined statuses to support reservation approval workflow. Default to pending.
  status ENUM('pending','approved','fulfilled','cancelled','expired','declined') NOT NULL DEFAULT 'pending',
  expiration_date DATE NULL,
  -- Reason for declining a reservation.  This field is optional and is
  -- populated only when staff decline a reservation request.  When
  -- present it helps communicate to the requester why their reservation
  -- was not approved.
  reason VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE RESTRICT,
  FOREIGN KEY (patron_id) REFERENCES patrons(id) ON DELETE RESTRICT
);

-- Lost / Damaged reports
CREATE TABLE IF NOT EXISTS lost_damaged_reports (
  id INT AUTO_INCREMENT PRIMARY KEY,
  book_id INT NOT NULL,
  patron_id INT NOT NULL,
  report_date DATE NOT NULL,
  report_type ENUM('lost','damaged') NOT NULL,
  severity ENUM('minor','moderate','severe') NOT NULL DEFAULT 'minor',
  description TEXT,
  fee_charged DECIMAL(10,2) DEFAULT 0,
  status ENUM('pending','resolved') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE RESTRICT,
  FOREIGN KEY (patron_id) REFERENCES patrons(id) ON DELETE RESTRICT
);

-- Clearances
CREATE TABLE IF NOT EXISTS clearances (
  id INT AUTO_INCREMENT PRIMARY KEY,
  patron_id INT NOT NULL,
  clearance_date DATE NOT NULL,
  status ENUM('pending','cleared','blocked') NOT NULL DEFAULT 'pending',
  notes TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (patron_id) REFERENCES patrons(id) ON DELETE RESTRICT
);

-- Messages
--
-- Simple one‑to‑one messaging table for direct communication between users.
-- Each message records the sender, receiver, content and timestamp.  This
-- table is also defined in migrations/alter_003.sql for incremental
-- upgrades; it is included here to support fresh installations without
-- requiring additional migrations to be executed.  ON DELETE CASCADE
-- ensures that when a user is removed, their associated messages are
-- automatically cleaned up.
CREATE TABLE IF NOT EXISTS messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sender_id INT NOT NULL,
  receiver_id INT NOT NULL,
  content TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS ebook_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  -- Optional book being requested.  When null, the request grants
  -- access to the entire e‑book catalogue rather than a specific title.
  book_id INT NULL,
  -- Username of the requesting student or staff member.  Storing
  -- usernames rather than foreign keys avoids coupling to the patrons
  -- table and allows the UI to display the name directly.
  username VARCHAR(64) NOT NULL,
  request_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  status ENUM('pending','approved','declined') NOT NULL DEFAULT 'pending',
  -- Action can record an outcome or staff note (e.g. granted, declined).
  action VARCHAR(32) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE SET NULL,
  INDEX idx_username (username)
);

-- ---------------------------------------------------------------------------
-- Seed sample e‑books
--
-- To provide initial content for the e‑books section of the application,
-- insert a few sample e‑books tied to existing book records.  The
-- `file_path` values reference files stored under the public/uploads
-- directory.  These files should be added to the repository under
-- public/uploads with the same names.
INSERT INTO ebooks (book_id, file_path, file_format, is_active, description)
VALUES
  (13, 'uploads/ebook1.pdf', 'PDF', 1, 'Sample Calculus I e‑book'),
  (2,  'uploads/ebook2.pdf', 'PDF', 1, 'Sample Ancient Civilizations e‑book')
ON DUPLICATE KEY UPDATE file_path = VALUES(file_path);

-- Audit logs (optional)
CREATE TABLE IF NOT EXISTS audit_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  action VARCHAR(32) NOT NULL,
  entity VARCHAR(64) NOT NULL,
  entity_id INT NULL,
  details TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Remember-me tokens
CREATE TABLE IF NOT EXISTS auth_tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  selector CHAR(16) NOT NULL UNIQUE,
  validator_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Notifications
CREATE TABLE IF NOT EXISTS notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  role_target ENUM('admin','librarian','assistant','student','non_staff') NULL,
  type VARCHAR(32) NOT NULL,
  message TEXT NOT NULL,
  meta JSON NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Seed users
INSERT INTO users (username, password_hash, email, name, role, status) VALUES
('admin',      '$2y$10$Q6r7Q7wQFQbQpGm1IhGZue.4JXGQk9M1WQ6L6fXg3eV4r2H3c1mFq', 'admin@library.edu', 'System Admin', 'admin', 'active'),
('librarian',  '$2y$10$7T0C6wC0V6U9Lk5p2aL7dOQipfKx2b0oYgq9K7z0lAq4bN2x3y8yS', 'librarian@library.edu', 'Head Librarian', 'librarian', 'active'),
('assistant',  '$2y$10$yK3D6yO2D3J9M1o3G6K4ye7zPFe5x3nYIf7j8Qk2yLbG1V9dQ3y2W', 'assistant@library.edu', 'Assistant Librarian', 'assistant', 'active');
-- Passwords for demo (do not use in prod): admin123 / lib123 / assist123
