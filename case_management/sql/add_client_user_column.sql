-- Migration script to add user_id column to clients table
-- Run this in phpMyAdmin or your MySQL client

USE `case_management`;

-- Add user_id column to clients table
ALTER TABLE `clients`
ADD COLUMN `user_id` INT NULL AFTER `id`,
ADD COLUMN `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `phone`,
ADD CONSTRAINT `fk_clients_user_id` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL;

-- Create case_comments table for client-lawyer communication
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

-- Add updated_at column to cases table if it doesn't exist
ALTER TABLE `cases`
ADD COLUMN `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`;

-- Update case_comments table to allow NULL user_id for admin comments
ALTER TABLE `case_comments`
MODIFY COLUMN `user_id` INT NULL;

-- Add status and lawyer_id columns to appointments table
DELIMITER //
CREATE PROCEDURE AddAppointmentColumns()
BEGIN
    -- Add lawyer_id column if it doesn't exist
    IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments' AND COLUMN_NAME = 'lawyer_id') THEN
        ALTER TABLE `appointments` ADD COLUMN `lawyer_id` INT NULL AFTER `case_id`;
    END IF;

    -- Add status column if it doesn't exist
    IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments' AND COLUMN_NAME = 'status') THEN
        ALTER TABLE `appointments` ADD COLUMN `status` ENUM('pending', 'accepted', 'rejected') DEFAULT 'pending' AFTER `notes`;
    END IF;

    -- Add created_at column if it doesn't exist
    IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments' AND COLUMN_NAME = 'created_at') THEN
        ALTER TABLE `appointments` ADD COLUMN `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER `status`;
    END IF;

    -- Add updated_at column if it doesn't exist
    IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments' AND COLUMN_NAME = 'updated_at') THEN
        ALTER TABLE `appointments` ADD COLUMN `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`;
    END IF;

    -- Add foreign key constraint if it doesn't exist
    IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments' AND CONSTRAINT_NAME = 'fk_appointments_lawyer') THEN
        ALTER TABLE `appointments` ADD CONSTRAINT `fk_appointments_lawyer` FOREIGN KEY (`lawyer_id`) REFERENCES `lawyers`(`id`) ON DELETE SET NULL;
    END IF;
END //
DELIMITER ;
CALL AddAppointmentColumns();
DROP PROCEDURE AddAppointmentColumns;

-- Create lawyer_time_slots table for detailed availability management
-- This is the new table for the availability system
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
