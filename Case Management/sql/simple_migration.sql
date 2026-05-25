-- Simple migration script - just add the new tables without modifying existing ones
-- Run this in phpMyAdmin to add the required tables for the new features

-- Allow NULL user_id in case_comments for admin comments
ALTER TABLE `case_comments` MODIFY COLUMN `user_id` INT NULL;

-- Add appointment status system safely
DELIMITER //
CREATE PROCEDURE AddAppointmentStatusColumns()
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
END //
DELIMITER ;
CALL AddAppointmentStatusColumns();
DROP PROCEDURE AddAppointmentStatusColumns;

-- Add foreign key for lawyer_id (ignore error if it already exists)
DELIMITER //
CREATE PROCEDURE AddAppointmentForeignKey()
BEGIN
    IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments' AND CONSTRAINT_NAME = 'fk_appointments_lawyer') THEN
        ALTER TABLE `appointments` ADD CONSTRAINT `fk_appointments_lawyer` FOREIGN KEY (`lawyer_id`) REFERENCES `lawyers`(`id`) ON DELETE SET NULL;
    END IF;
END //
DELIMITER ;
CALL AddAppointmentForeignKey();
DROP PROCEDURE AddAppointmentForeignKey;

-- Create lawyer_time_slots table for the new availability system
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
