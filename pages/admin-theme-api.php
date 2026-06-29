<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../lib/portal-theme.php';

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$themeMode = isset($input['theme_mode']) ? (string) $input['theme_mode'] : (string) ($_POST['theme_mode'] ?? '');
$themeMode = strtolower(trim($themeMode));

if (!in_array($themeMode, ['light', 'dark'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid theme mode selected.']);
    exit;
}

$currentTheme = getPortalTheme();
$themeColor = isset($currentTheme['color']) ? (string) $currentTheme['color'] : 'primary';
$customPrimary = isset($currentTheme['custom_primary']) ? (string) $currentTheme['custom_primary'] : null;

$result = savePortalTheme($themeMode, $themeColor, $customPrimary);
if (!$result['ok']) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $result['message']]);
    exit;
}

echo json_encode([
    'ok' => true,
    'theme_mode' => (string) (getPortalTheme()['mode'] ?? $themeMode),
]);
