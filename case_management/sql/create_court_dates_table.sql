-- Court Dates Table Creation
-- Run this SQL script manually in phpMyAdmin or MySQL console

CREATE TABLE IF NOT EXISTS `court_dates` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `case_id` INT NOT NULL,
  `court_date` DATETIME NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT,
  `location` VARCHAR(255),
  `status` ENUM('scheduled', 'completed', 'cancelled', 'postponed') DEFAULT 'scheduled',
  `created_by` INT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`case_id`) REFERENCES `cases`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX `idx_court_dates_case_id` (`case_id`),
  INDEX `idx_court_dates_court_date` (`court_date`),
  INDEX `idx_court_dates_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- If the above fails due to foreign key constraints, use this version without FKs:
-- CREATE TABLE IF NOT EXISTS `court_dates` (
--   `id` INT AUTO_INCREMENT PRIMARY KEY,
--   `case_id` INT NOT NULL,
--   `court_date` DATETIME NOT NULL,
--   `title` VARCHAR(255) NOT NULL,
--   `description` TEXT,
--   `location` VARCHAR(255),
--   `status` ENUM('scheduled', 'completed', 'cancelled', 'postponed') DEFAULT 'scheduled',
--   `created_by` INT NOT NULL,
--   `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
--   `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
--   INDEX `idx_court_dates_case_id` (`case_id`),
--   INDEX `idx_court_dates_court_date` (`court_date`),
--   INDEX `idx_court_dates_status` (`status`)
-- ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
