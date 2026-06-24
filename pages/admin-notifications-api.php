<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../lib/portal_notifications.php';

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$adminId = (int) $_SESSION['admin_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
$input = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $decoded = json_decode(file_get_contents('php://input'), true);
    if (is_array($decoded)) {
        $input = $decoded;
        if (isset($input['action'])) {
            $action = (string) $input['action'];
        }
    }
}

function legalpro_admin_notifications_payload(PDO $pdo): array
{
    $items = legalpro_fetch_admin_notifications($pdo, 50);
    $payload = [];

    foreach ($items as $item) {
        $key = (string) ($item['key'] ?? '');
        $destinationUrl = (string) ($item['url'] ?? '');
        $payload[] = [
            'key' => $key,
            'type' => (string) ($item['type'] ?? 'default'),
            'title' => (string) ($item['title'] ?? ''),
            'message' => (string) ($item['message'] ?? ''),
            'url' => $destinationUrl,
            'link_url' => $key !== '' ? legalpro_notification_click_url($key, $destinationUrl) : $destinationUrl,
            'icon' => (string) ($item['icon'] ?? 'bell'),
            'time' => (string) ($item['time'] ?? ''),
        ];
    }

    return $payload;
}

switch ($action) {
    case 'mark_read':
        $key = trim((string) ($_POST['key'] ?? ($input['key'] ?? '')));
        $ok = $key !== '' && legalpro_mark_notification_read($pdo, 'admin', $adminId, $key);
        echo json_encode([
            'ok' => $ok,
            'unread' => legalpro_admin_notification_unread_count($pdo),
        ]);
        break;

    case 'mark_all_read':
        $items = legalpro_fetch_admin_notifications($pdo, 100);
        $ok = legalpro_mark_all_portal_notifications_read($pdo, 'admin', $adminId, $items);
        echo json_encode([
            'ok' => $ok,
            'unread' => legalpro_admin_notification_unread_count($pdo),
        ]);
        break;

    case 'list':
    default:
        echo json_encode([
            'ok' => true,
            'unread' => legalpro_admin_notification_unread_count($pdo),
            'notifications' => legalpro_admin_notifications_payload($pdo),
        ]);
        break;
}
