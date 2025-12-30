USE `lms_php`;

-- Add decline reason column to reservations
--
-- Some existing installations may have created the `reservations` table
-- without the `reason` column.  This column stores an optional reason
-- when staff decline a reservation request.  Adding the column with
-- IF NOT EXISTS ensures the migration can be run safely multiple
-- times without error.
ALTER TABLE reservations
  ADD COLUMN IF NOT EXISTS reason VARCHAR(255) NULL AFTER expiration_date;