USE `lms_php`;

-- Link users to patrons for ownership checks
ALTER TABLE users ADD COLUMN IF NOT EXISTS patron_id INT NULL AFTER phone;

-- 2FA columns
ALTER TABLE users 
  ADD COLUMN IF NOT EXISTS twofa_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER role,
  ADD COLUMN IF NOT EXISTS twofa_code VARCHAR(16) NULL AFTER twofa_enabled,
  ADD COLUMN IF NOT EXISTS twofa_expires_at DATETIME NULL AFTER twofa_code;

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
  user_id INT NULL, -- null means broadcast to staff
  role_target ENUM('admin','librarian','assistant','student','non_staff') NULL,
  type VARCHAR(32) NOT NULL,
  message TEXT NOT NULL,
  meta JSON NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Borrow logs: late fee
ALTER TABLE borrow_logs ADD COLUMN IF NOT EXISTS late_fee DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER status;

-- Lost/damaged: severity
ALTER TABLE lost_damaged_reports ADD COLUMN IF NOT EXISTS severity ENUM('minor','moderate','severe') NOT NULL DEFAULT 'minor' AFTER report_type;

