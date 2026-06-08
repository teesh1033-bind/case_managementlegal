-- Fix tasks table structure
-- Run this SQL in phpMyAdmin to ensure the table has the correct structure

-- First, check if the table exists and what columns it has
DESCRIBE tasks;

-- If the table exists but is missing columns, add them
ALTER TABLE tasks
ADD COLUMN IF NOT EXISTS `case_id` INT NOT NULL,
ADD COLUMN IF NOT EXISTS `assigned_lawyer_id` INT NOT NULL,
ADD COLUMN IF NOT EXISTS `title` VARCHAR(255) NOT NULL,
ADD COLUMN IF NOT EXISTS `description` TEXT,
ADD COLUMN IF NOT EXISTS `status` ENUM('pending', 'in_progress', 'completed', 'cancelled') DEFAULT 'pending',
ADD COLUMN IF NOT EXISTS `priority` ENUM('low', 'medium', 'high') DEFAULT 'medium',
ADD COLUMN IF NOT EXISTS `due_date` DATE NULL,
ADD COLUMN IF NOT EXISTS `created_by` INT NULL,
ADD COLUMN IF NOT EXISTS `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
ADD COLUMN IF NOT EXISTS `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
ADD COLUMN IF NOT EXISTS `completed_at` TIMESTAMP NULL;

-- Add indexes if they don't exist
CREATE INDEX IF NOT EXISTS `idx_tasks_case_id` ON `tasks` (`case_id`);
CREATE INDEX IF NOT EXISTS `idx_tasks_assigned_lawyer_id` ON `tasks` (`assigned_lawyer_id`);
CREATE INDEX IF NOT EXISTS `idx_tasks_status` ON `tasks` (`status`);
CREATE INDEX IF NOT EXISTS `idx_tasks_due_date` ON `tasks` (`due_date`);

-- Show the final table structure
DESCRIBE tasks;
