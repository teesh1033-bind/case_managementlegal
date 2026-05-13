<?php
// inc/db.php
// PDO database connection. Edit credentials to match your environment before use.
// Place this file in `inc/` and include from pages with: require_once __DIR__ . '/db.php';

$DB_HOST = '127.0.0.1';
$DB_NAME = 'case_management';
$DB_USER = 'root';
$DB_PASS = ''; // change if you have a password

try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    // In production, avoid echoing raw errors. For now, show something helpful.
    http_response_code(500);
    echo "Database connection failed: " . htmlspecialchars($e->getMessage());
    exit;
}

function ensureSettingsTable() {
    static $settingsReady = false;
    if ($settingsReady) {
        return;
    }
    global $pdo;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            `key` VARCHAR(100) NOT NULL UNIQUE,
            `value` TEXT,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $settingsReady = true;
    } catch (PDOException $e) {
        // If the table cannot be created we rethrow to make the failure obvious.
        throw $e;
    }
}

function &settingsCache() {
    static $cache = [];
    return $cache;
}

function getSetting($key, $default = null) {
    ensureSettingsTable();
    $cache = &settingsCache();
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT `value` FROM settings WHERE `key` = ? LIMIT 1");
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
    } catch (PDOException $e) {
        return $default;
    }
    if ($value === false) {
        $cache[$key] = $default;
        return $default;
    }
    $cache[$key] = $value;
    return $value;
}

function setSetting($key, $value) {
    ensureSettingsTable();
    global $pdo;
    $cache = &settingsCache();
    try {
        $stmt = $pdo->prepare("INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = CURRENT_TIMESTAMP");
        $stmt->execute([$key, $value]);
        $cache[$key] = $value;
    } catch (PDOException $e) {
        throw $e;
    }
}

function getCurrencyOptions() {
    return [
        'USD' => ['label' => 'US Dollar ($)', 'symbol' => '$', 'prefix' => true],
        'EUR' => ['label' => 'Euro (€)', 'symbol' => '€', 'prefix' => true],
        'MUR' => ['label' => 'Mauritian Rupee (Rs)', 'symbol' => 'Rs', 'prefix' => true],
    ];
}

function getCurrencyConfig() {
    $options = getCurrencyOptions();
    $code = strtoupper((string) getSetting('currency', 'USD'));
    if (!isset($options[$code])) {
        $code = 'USD';
    }
    return ['code' => $code] + $options[$code];
}

function getCurrencySymbol() {
    $config = getCurrencyConfig();
    return $config['symbol'];
}

function formatCurrency($amount, $decimals = 2) {
    $config = getCurrencyConfig();
    $formattedAmount = number_format((float) $amount, $decimals);
    return $config['prefix']
        ? $config['symbol'] . $formattedAmount
        : $formattedAmount . ' ' . $config['symbol'];
}

?>