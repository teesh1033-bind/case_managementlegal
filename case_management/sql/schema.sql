-- sql/schema.sql
-- SQL schema for Case Management example. Import into phpMyAdmin.

CREATE DATABASE IF NOT EXISTS `case_management` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `case_management`;

-- users table
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(100) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL,
  `email` VARCHAR(255),
  `role` VARCHAR(50) DEFAULT 'user',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- clients table
CREATE TABLE IF NOT EXISTS `clients` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NULL,
  `first_name` VARCHAR(100) NOT NULL,
  `last_name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(255),
  `phone` VARCHAR(50),
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- cases table
CREATE TABLE IF NOT EXISTS `cases` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `client_id` INT NOT NULL,
  `user_id` INT NULL,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT,
  `status` VARCHAR(50) DEFAULT 'open',
  `priority` VARCHAR(50) DEFAULT 'Normal',
  `category` VARCHAR(50) DEFAULT 'Civil',
  `estimated_fees` DECIMAL(10,2) DEFAULT 0.00,
  `start_date` DATE NULL,
  `expected_completion` DATE NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- appointments table (updated with case_id and status)
CREATE TABLE IF NOT EXISTS `appointments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `client_id` INT,
  `case_id` INT,
  `lawyer_id` INT,
  `starts_at` DATETIME,
  `ends_at` DATETIME,
  `notes` TEXT,
  `status` ENUM('pending', 'accepted', 'rejected') DEFAULT 'pending',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`case_id`) REFERENCES `cases`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`lawyer_id`) REFERENCES `lawyers`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- documents table (with additional metadata columns)
CREATE TABLE IF NOT EXISTS `documents` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `case_id` INT,
  `filename` VARCHAR(255),
  `filepath` VARCHAR(255),
  `label` VARCHAR(255) DEFAULT NULL,
  `uploaded_by` VARCHAR(100) DEFAULT NULL,
  `uploaded_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`case_id`) REFERENCES `cases`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- document_templates table
CREATE TABLE IF NOT EXISTS `document_templates` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(150) NOT NULL,
  `description` VARCHAR(255),
  `body` TEXT NOT NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- invoices table
CREATE TABLE IF NOT EXISTS `invoices` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `client_id` INT,
  `case_id` INT,
  `invoice_number` VARCHAR(100),
  `amount` DECIMAL(10,2),
  `status` VARCHAR(50) DEFAULT 'draft',
  `issue_date` DATE,
  `due_date` DATE,
  `notes` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`case_id`) REFERENCES `cases`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- payments table (tracks partial payments per case)
CREATE TABLE IF NOT EXISTS `payments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `case_id` INT NOT NULL,
  `client_id` INT NOT NULL,
  `invoice_id` INT,
  `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `method` VARCHAR(50) DEFAULT 'cash',
  `reference` VARCHAR(100),
  `notes` TEXT,
  `payment_date` DATE DEFAULT NULL,
  `recorded_by` VARCHAR(100),
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`case_id`) REFERENCES `cases`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`invoice_id`) REFERENCES `invoices`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- settings table
CREATE TABLE IF NOT EXISTS `settings` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `key` VARCHAR(100) NOT NULL UNIQUE,
  `value` TEXT,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- case_services table (stores individual services for each case)
CREATE TABLE IF NOT EXISTS `case_services` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `case_id` INT NOT NULL,
  `service_name` VARCHAR(255) NOT NULL,
  `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`case_id`) REFERENCES `cases`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- case_stages table (stores summary of events/stages for each case)
CREATE TABLE IF NOT EXISTS `case_stages` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `case_id` INT NOT NULL,
  `stage_number` INT NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT,
  `result` TEXT,
  `file_path` VARCHAR(255),
  `start_date` DATE,
  `expected_end_date` DATE,
  `actual_end_date` DATE,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`case_id`) REFERENCES `cases`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `unique_case_stage` (`case_id`, `stage_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- lawyers table (detailed lawyer information and availability)
CREATE TABLE IF NOT EXISTS `lawyers` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `first_name` VARCHAR(100) NOT NULL,
  `last_name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(255) NOT NULL UNIQUE,
  `phone` VARCHAR(50),
  `license_number` VARCHAR(100),
  `specialization` VARCHAR(255),
  `experience_years` INT DEFAULT 0,
  `bio` TEXT,
  `office_address` TEXT,
  `is_active` BOOLEAN DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- lawyer_availability table (legacy table - kept for backward compatibility)
CREATE TABLE IF NOT EXISTS `lawyer_availability` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `lawyer_id` INT NOT NULL,
  `day_of_week` ENUM('monday','tuesday','wednesday','thursday','friday','saturday','sunday') NOT NULL,
  `start_time` TIME NOT NULL,
  `end_time` TIME NOT NULL,
  `is_available` BOOLEAN DEFAULT 1,
  FOREIGN KEY (`lawyer_id`) REFERENCES `lawyers`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `unique_lawyer_day` (`lawyer_id`, `day_of_week`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- lawyer_time_slots table (new detailed time slots for each day)
CREATE TABLE IF NOT EXISTS `lawyer_time_slots` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `lawyer_id` INT NOT NULL,
  `day_of_week` ENUM('monday','tuesday','wednesday','thursday','friday','saturday','sunday') NOT NULL,
  `start_time` TIME NOT NULL,
  `end_time` TIME NOT NULL,
  `slot_type` ENUM('available', 'unavailable') DEFAULT 'available',
  `slot_order` INT DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`lawyer_id`) REFERENCES `lawyers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- case_lawyers table (many-to-many relationship between cases and lawyers)
CREATE TABLE IF NOT EXISTS `case_lawyers` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `case_id` INT NOT NULL,
  `lawyer_id` INT NOT NULL,
  `assigned_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `is_primary` BOOLEAN DEFAULT 0,
  FOREIGN KEY (`case_id`) REFERENCES `cases`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`lawyer_id`) REFERENCES `lawyers`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `unique_case_lawyer` (`case_id`, `lawyer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- case_comments table (for client comments on their cases)
CREATE TABLE IF NOT EXISTS `case_comments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `case_id` INT NOT NULL,
  `user_id` INT NULL,
  `comment` TEXT NOT NULL,
  `comment_type` ENUM('client', 'lawyer', 'admin', 'staff') DEFAULT 'client',
  `is_private` BOOLEAN DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`case_id`) REFERENCES `cases`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- case_events table (tracks all changes/updates made to cases)
CREATE TABLE IF NOT EXISTS `case_events` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `case_id` INT NOT NULL,
  `user_id` INT NULL,
  `event_type` VARCHAR(100) NOT NULL,
  `event_description` TEXT NOT NULL,
  `old_value` TEXT,
  `new_value` TEXT,
  `ip_address` VARCHAR(45),
  `user_agent` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`case_id`) REFERENCES `cases`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX `idx_case_events_case_id` (`case_id`),
  INDEX `idx_case_events_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- tasks table (tracks tasks assigned to lawyers for specific cases)
CREATE TABLE IF NOT EXISTS `tasks` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `case_id` INT NOT NULL,
  `assigned_lawyer_id` INT NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT,
  `status` ENUM('pending', 'in_progress', 'completed', 'cancelled') DEFAULT 'pending',
  `priority` ENUM('low', 'medium', 'high') DEFAULT 'medium',
  `due_date` DATE NULL,
  `created_by` INT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `completed_at` TIMESTAMP NULL,
  FOREIGN KEY (`case_id`) REFERENCES `cases`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`assigned_lawyer_id`) REFERENCES `lawyers`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX `idx_tasks_case_id` (`case_id`),
  INDEX `idx_tasks_assigned_lawyer_id` (`assigned_lawyer_id`),
  INDEX `idx_tasks_status` (`status`),
  INDEX `idx_tasks_due_date` (`due_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- sample admin user
INSERT INTO `users` (`username`, `password`, `email`, `role`) VALUES
('admin', 'password', 'admin@example.com', 'admin');

-- sample client
INSERT INTO `clients` (`first_name`, `last_name`, `email`, `phone`) VALUES
('John', 'Doe', 'john@example.com', '555-0101');

-- sample case
INSERT INTO `cases` (`client_id`, `title`, `description`, `status`) VALUES
(1, 'New Intake', 'Initial intake for client John Doe', 'open');

-- Court dates tracking
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

-- end of file
