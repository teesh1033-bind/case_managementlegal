-- Run this SQL in phpMyAdmin to create/fix the case_services table
-- This table stores individual services for each case
-- If the table has the wrong structure (e.g., has service_id), this will fix it

-- Drop the table if it exists (this will delete any existing data)
DROP TABLE IF EXISTS `case_services`;

-- Create the table with the correct structure
CREATE TABLE `case_services` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `case_id` INT NOT NULL,
  `service_name` VARCHAR(255) NOT NULL,
  `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`case_id`) REFERENCES `cases`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

