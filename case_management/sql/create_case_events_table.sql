-- Create case_events table for tracking all case activities
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
