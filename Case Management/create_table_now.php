<?php
require_once 'inc/db.php';

echo "<h1>Creating Court Dates Table</h1>";
echo "<pre>";

try {
    $pdo->exec("
        CREATE TABLE `court_dates` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `case_id` INT NOT NULL,
          `court_date` DATETIME NOT NULL,
          `title` VARCHAR(255) NOT NULL,
          `description` TEXT,
          `location` VARCHAR(255),
          `status` ENUM('scheduled', 'completed', 'cancelled', 'postponed') DEFAULT 'scheduled',
          `created_by` INT NOT NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "✅ Table created successfully!\n";

    // Add indexes
    $pdo->exec("ALTER TABLE `court_dates` ADD INDEX `idx_court_dates_case_id` (`case_id`)");
    $pdo->exec("ALTER TABLE `court_dates` ADD INDEX `idx_court_dates_court_date` (`court_date`)");
    $pdo->exec("ALTER TABLE `court_dates` ADD INDEX `idx_court_dates_status` (`status`)");
    echo "✅ Indexes added!\n";

    // Try foreign keys (may fail if tables don't exist)
    try {
        $pdo->exec("ALTER TABLE `court_dates` ADD CONSTRAINT `fk_court_dates_case` FOREIGN KEY (`case_id`) REFERENCES `cases`(`id`) ON DELETE CASCADE");
        echo "✅ Case foreign key added!\n";
    } catch (Exception $e) {
        echo "⚠️ Case foreign key not added (table may not exist): " . $e->getMessage() . "\n";
    }

    try {
        $pdo->exec("ALTER TABLE `court_dates` ADD CONSTRAINT `fk_court_dates_user` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE CASCADE");
        echo "✅ User foreign key added!\n";
    } catch (Exception $e) {
        echo "⚠️ User foreign key not added (table may not exist): " . $e->getMessage() . "\n";
    }

    echo "\n🎉 Court dates table is ready!\n";

} catch (Exception $e) {
    echo "❌ Error creating table: " . $e->getMessage() . "\n";
}

echo "</pre>";
echo "<p><a href='pages/court-tracking.php'>Go to Court Tracking</a></p>";
?>
