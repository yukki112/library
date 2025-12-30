USE `lms_php`;

-- Application settings
CREATE TABLE IF NOT EXISTS settings (
  `key` VARCHAR(64) PRIMARY KEY,
  `value` VARCHAR(255) NOT NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT INTO settings (`key`, `value`) VALUES
('borrow_period_days','14')
ON DUPLICATE KEY UPDATE value = value;

INSERT INTO settings (`key`, `value`) VALUES
('fee_minor','50'),('fee_moderate','200'),('fee_severe','1000')
ON DUPLICATE KEY UPDATE value = value;

