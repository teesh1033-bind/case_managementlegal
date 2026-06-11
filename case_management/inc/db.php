<?php
// inc/db.php
// PDO database connection. Edit credentials to match your environment before use.
// Place this file in `inc/` and include from pages with: require_once __DIR__ . '/db.php';

$DB_HOST = '127.0.0.1';
$DB_NAME = 'case_management';
$DB_USER = 'root';
$DB_PASS = '1234'; // change if you have a password

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

function getDefaultCurrencyCode() {
    return 'MUR';
}

function getCurrencyOptions() {
    return [
        'MUR' => ['label' => 'Rupee (Rs)', 'symbol' => 'Rs', 'prefix' => true],
        'USD' => ['label' => 'US Dollar ($)', 'symbol' => '$', 'prefix' => true],
        'EUR' => ['label' => 'Euro (€)', 'symbol' => '€', 'prefix' => true],
    ];
}

function ensureCurrencyDefault() {
    static $initialized = false;
    if ($initialized) {
        return;
    }
    $initialized = true;
    $defaultCode = getDefaultCurrencyCode();
    $current = getSetting('currency', null);
    if ($current === null || $current === '' || strtoupper((string) $current) === 'USD') {
        setSetting('currency', $defaultCode);
    }
}

function getCurrencyConfig() {
    ensureCurrencyDefault();
    $options = getCurrencyOptions();
    $defaultCode = getDefaultCurrencyCode();
    $code = strtoupper((string) getSetting('currency', $defaultCode));
    if (!isset($options[$code])) {
        $code = $defaultCode;
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

function getOfferedServices() {
    $raw = getSetting('offered_services', null);
    if ($raw === null || $raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }
    $services = [];
    foreach ($decoded as $name) {
        $name = trim((string) $name);
        if ($name !== '') {
            $services[] = $name;
        }
    }
    return $services;
}

function setOfferedServices(array $services) {
    $normalized = [];
    foreach ($services as $name) {
        $name = trim((string) $name);
        if ($name !== '' && !in_array($name, $normalized, true)) {
            $normalized[] = $name;
        }
    }
    setSetting('offered_services', json_encode($normalized, JSON_UNESCAPED_UNICODE));
}

function getLawyerSpecializationsFromSettings() {
    $raw = getSetting('lawyer_specializations', null);
    if ($raw === null || $raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }
    $specializations = [];
    foreach ($decoded as $name) {
        $name = trim((string) $name);
        if ($name !== '') {
            $specializations[] = $name;
        }
    }
    return $specializations;
}

function getLawyerSpecializations() {
    global $pdo;

    $specializations = getLawyerSpecializationsFromSettings();

    try {
        $stmt = $pdo->query("
            SELECT DISTINCT specialization
            FROM lawyers
            WHERE specialization IS NOT NULL AND TRIM(specialization) != ''
            ORDER BY specialization
        ");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $name = trim((string) ($row['specialization'] ?? ''));
            if ($name !== '' && !in_array($name, $specializations, true)) {
                $specializations[] = $name;
            }
        }
    } catch (PDOException $e) {
        // Continue with settings-only list.
    }

    sort($specializations, SORT_NATURAL | SORT_FLAG_CASE);

    return $specializations;
}

function addLawyerSpecialization($name) {
    $name = trim((string) $name);
    if ($name === '') {
        return;
    }

    $specializations = getLawyerSpecializationsFromSettings();
    if (in_array($name, $specializations, true)) {
        return;
    }

    $specializations[] = $name;
    sort($specializations, SORT_NATURAL | SORT_FLAG_CASE);
    setSetting('lawyer_specializations', json_encode($specializations, JSON_UNESCAPED_UNICODE));
}

function getDefaultCaseCategories() {
    return ['Civil', 'Criminal', 'Corporate', 'Family'];
}

function getCaseCategoriesFromSettings() {
    $raw = getSetting('case_categories', null);
    if ($raw === null || $raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }
    $categories = [];
    foreach ($decoded as $name) {
        $name = trim((string) $name);
        if ($name !== '') {
            $categories[] = $name;
        }
    }
    return $categories;
}

function getCaseCategories() {
    global $pdo;

    $categories = array_merge(getDefaultCaseCategories(), getCaseCategoriesFromSettings());

    try {
        $stmt = $pdo->query("
            SELECT DISTINCT category
            FROM cases
            WHERE category IS NOT NULL AND TRIM(category) != ''
            ORDER BY category
        ");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $name = trim((string) ($row['category'] ?? ''));
            if ($name !== '' && !in_array($name, $categories, true)) {
                $categories[] = $name;
            }
        }
    } catch (PDOException $e) {
        // Continue with defaults/settings only.
    }

    $unique = [];
    foreach ($categories as $name) {
        if (!in_array($name, $unique, true)) {
            $unique[] = $name;
        }
    }

    sort($unique, SORT_NATURAL | SORT_FLAG_CASE);

    return $unique;
}

function addCaseCategory($name) {
    $name = trim((string) $name);
    if ($name === '') {
        return;
    }

    $categories = getCaseCategoriesFromSettings();
    if (in_array($name, $categories, true) || in_array($name, getDefaultCaseCategories(), true)) {
        return;
    }

    $categories[] = $name;
    sort($categories, SORT_NATURAL | SORT_FLAG_CASE);
    setSetting('case_categories', json_encode($categories, JSON_UNESCAPED_UNICODE));
}

function resolveSubmittedCaseCategory($fallback = 'Civil') {
    $select = trim((string) ($_POST['category_select'] ?? ''));
    if ($select !== '') {
        $category = $select;
    } else {
        $legacy = trim((string) ($_POST['category'] ?? ''));
        $category = $legacy !== '' ? $legacy : $fallback;
    }

    return $category;
}

function buildCaseCategoryFieldHtml($currentCategory, $fallback = 'Civil') {
    $categories = getCaseCategories();
    $currentCategory = trim((string) $currentCategory);
    if ($currentCategory === '') {
        $currentCategory = $fallback;
    }

    $optionsHtml = '';
    foreach ($categories as $name) {
        $selected = ($currentCategory === $name) ? ' selected' : '';
        $optionsHtml .= '<option value="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '"' . $selected . '>'
            . htmlspecialchars($name) . '</option>';
    }
    if ($currentCategory !== '' && !in_array($currentCategory, $categories, true)) {
        $optionsHtml .= '<option value="' . htmlspecialchars($currentCategory, ENT_QUOTES, 'UTF-8') . '" selected>'
            . htmlspecialchars($currentCategory) . '</option>';
    }

    return '
        <select class="form-control" name="category_select" id="category_select">' . $optionsHtml . '</select>
        <small class="text-muted d-block mt-1"><a href="settings.php">Manage categories in Settings</a>.</small>';
}

require_once __DIR__ . '/../lib/branding.php';
require_once __DIR__ . '/../lib/portal-theme.php';
require_once __DIR__ . '/../lib/client-locale.php';

/**
 * Allow admin/lawyer staff, or the owning client, to access invoice/receipt downloads.
 */
function legalpro_require_financial_document_access(?int $ownerClientId): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (isset($_SESSION['admin_id']) || isset($_SESSION['lawyer_id'])) {
        return;
    }

    if (isset($_SESSION['client_id']) && $ownerClientId !== null
        && (int) $_SESSION['client_id'] === (int) $ownerClientId) {
        return;
    }

    if (isset($_SESSION['client_id'])) {
        http_response_code(403);
        echo 'Access denied.';
        exit;
    }

    header('Location: login.php');
    exit;
}

?>