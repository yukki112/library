-- This script creates or recreates the reservations table used by the LMS.
-- It is provided separately for convenience if you only need to create
-- or reset the reservations table without running the full schema.

-- Drop the table if it already exists (use with caution!)
DROP TABLE IF EXISTS `reservations`;

-- Recreate the reservations table with proper indexes and foreign keys.
CREATE TABLE `reservations` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `book_id` INT NOT NULL,
  `patron_id` INT NOT NULL,
  `reserved_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  -- Include pending, approved and declined statuses to support reservation approval workflow. Default to pending.
  `status` ENUM('pending','approved','fulfilled','cancelled','expired','declined') NOT NULL DEFAULT 'pending',
  `expiration_date` DATE NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_book_id` (`book_id`),
  KEY `idx_patron_id` (`patron_id`),
  CONSTRAINT `reservations_ibfk_1` FOREIGN KEY (`book_id`) REFERENCES `books` (`id`) ON DELETE CASCADE,
  CONSTRAINT `reservations_ibfk_2` FOREIGN KEY (`patron_id`) REFERENCES `patrons` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
