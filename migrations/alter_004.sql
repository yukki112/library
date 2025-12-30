-- Migration to add table for e‑book access requests.
--
-- This table stores access requests from students and non‑staff users for
-- viewing/downloading electronic books.  Each request is tied to a patron
-- (via the patrons table) and records the request timestamp and current
-- status.  Administrators and librarians can review these requests and
-- approve or decline them.  Once approved, the requesting patron will be
-- able to view the e‑books listing.

USE `lms_php`;

-- Redefine the `ebook_requests` table to remove the `patron_id` foreign
-- key and instead capture the requesting user's username and the book
-- being requested.  Each request now contains the following fields:
--   - book_id: references the ID of the book (nullable when requesting
--              access to the general e‑book collection)
--   - username: the username of the requesting student or staff member
--   - request_date: timestamp of when the request was made
--   - status: pending/approved/declined state of the request
--   - action: optional free‑form string describing the outcome or next step

CREATE TABLE IF NOT EXISTS ebook_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  book_id INT NULL,
  username VARCHAR(64) NOT NULL,
  request_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  status ENUM('pending','approved','declined') NOT NULL DEFAULT 'pending',
  action VARCHAR(32) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE SET NULL,
  INDEX idx_username (username)
);