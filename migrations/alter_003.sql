-- Migration to add a simple messages table for one‑to‑one messaging between
-- users (for example students and administrators).  Each message has a
-- sender, a receiver, the message content and a timestamp.  This table
-- uses ON DELETE CASCADE foreign keys so that if a user is deleted,
-- any associated messages are automatically removed.  Run this script
-- against the `lms_php` database after the base schema has been
-- created.

USE `lms_php`;

CREATE TABLE IF NOT EXISTS messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sender_id INT NOT NULL,
  receiver_id INT NOT NULL,
  content TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE
);