-- Fix appointments table structure
-- Run this in phpMyAdmin to ensure the appointments table has the correct structure

USE `case_management`;

-- Check if lawyer_id column exists, add if missing
SET @column_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'appointments'
    AND COLUMN_NAME = 'lawyer_id'
);

SET @sql = IF(@column_exists = 0,
    'ALTER TABLE appointments ADD COLUMN lawyer_id INT NULL AFTER case_id',
    'SELECT "lawyer_id column already exists" as message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Check if status column exists, add if missing
SET @status_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'appointments'
    AND COLUMN_NAME = 'status'
);

SET @sql2 = IF(@status_exists = 0,
    'ALTER TABLE appointments ADD COLUMN status ENUM("pending", "accepted", "rejected") DEFAULT "pending" AFTER notes',
    'SELECT "status column already exists" as message'
);

PREPARE stmt2 FROM @sql2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;

-- Check if created_at column exists, add if missing
SET @created_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'appointments'
    AND COLUMN_NAME = 'created_at'
);

SET @sql3 = IF(@created_exists = 0,
    'ALTER TABLE appointments ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER status',
    'SELECT "created_at column already exists" as message'
);

PREPARE stmt3 FROM @sql3;
EXECUTE stmt3;
DEALLOCATE PREPARE stmt3;

-- Check if updated_at column exists, add if missing
SET @updated_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'appointments'
    AND COLUMN_NAME = 'updated_at'
);

SET @sql4 = IF(@updated_exists = 0,
    'ALTER TABLE appointments ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at',
    'SELECT "updated_at column already exists" as message'
);

PREPARE stmt4 FROM @sql4;
EXECUTE stmt4;
DEALLOCATE PREPARE stmt4;

-- Add foreign key constraint if it doesn't exist
SET @fk_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'appointments'
    AND CONSTRAINT_NAME = 'fk_appointments_lawyer'
);

SET @sql5 = IF(@fk_exists = 0,
    'ALTER TABLE appointments ADD CONSTRAINT fk_appointments_lawyer FOREIGN KEY (lawyer_id) REFERENCES lawyers(id) ON DELETE SET NULL',
    'SELECT "Foreign key already exists" as message'
);

PREPARE stmt5 FROM @sql5;
EXECUTE stmt5;
DEALLOCATE PREPARE stmt5;

-- Show final table structure
DESCRIBE appointments;
