-- Full LMS schema for XAMPP MySQL (localhost:3306)
-- Paste/import this file in phpMyAdmin or mysql client.

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
  -- Store a physical address for the user.  This allows student
  -- registrations to capture mailing or residential addresses for
  -- notification or record keeping purposes.
  address VARCHAR(255) NULL,
  patron_id INT NULL,
  role ENUM('admin','librarian','assistant','student','non_staff') NOT NULL DEFAULT 'student',
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
  -- Academic details (semester and department) and a contact address.
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
  -- Reservation status options.  A reservation begins as pending and may
  -- be approved by staff.  Once a reservation is processed and the book
  -- is issued (i.e. a borrow_log entry is created), its status
  -- transitions to "fulfilled" to indicate that it should no longer
  -- appear as an active reservation.  Additional statuses include
  -- cancelled, expired and declined.
  status ENUM('pending','approved','fulfilled','cancelled','expired','declined') NOT NULL DEFAULT 'pending',
  expiration_date DATE NULL,
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

-- Audit logs
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

-- Settings
CREATE TABLE IF NOT EXISTS settings (
  `key` VARCHAR(64) PRIMARY KEY,
  `value` VARCHAR(255) NOT NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT INTO settings (`key`, `value`) VALUES
('borrow_period_days','14')
ON DUPLICATE KEY UPDATE value = value;

INSERT INTO settings (`key`, `value`) VALUES
('late_fee_per_day','10'),('fee_minor','50'),('fee_moderate','200'),('fee_severe','1000')
ON DUPLICATE KEY UPDATE value = value;

-- Seed demo staff users (bcrypt hashes for demo passwords: admin123 / lib123 / assist123)
INSERT INTO users (username, password_hash, email, name, role, status) VALUES
('admin',      '$2y$10$Q6r7Q7wQFQbQpGm1IhGZue.4JXGQk9M1WQ6L6fXg3eV4r2H3c1mFq', 'admin@library.edu', 'System Admin', 'admin', 'active'),
('librarian',  '$2y$10$7T0C6wC0V6U9Lk5p2aL7dOQipfKx2b0oYgq9K7z0lAq4bN2x3y8yS', 'librarian@library.edu', 'Head Librarian', 'librarian', 'active'),
('assistant',  '$2y$10$yK3D6yO2D3J9M1o3G6K4ye7zPFe5x3nYIf7j8Qk2yLbG1V9dQ3y2W', 'assistant@library.edu', 'Assistant Librarian', 'assistant', 'active')
ON DUPLICATE KEY UPDATE email = VALUES(email);

