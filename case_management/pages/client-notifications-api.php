<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../lib/client-portal-features.php';
require_once __DIR__ . '/../lib/case_quotations.php';

if (!isset($_SESSION['client_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$clientId = (int) $_SESSION['client_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (is_array($input) && isset($input['action'])) {
        $action = (string) $input['action'];
    }
}

switch ($action) {
    case 'mark_read':
        $id = (int) ($_POST['id'] ?? ($input['id'] ?? 0));
        $ok = legalpro_client_mark_notification_read($pdo, $clientId, $id);
        echo json_encode(['ok' => $ok, 'unread' => legalpro_client_notification_count_unread($pdo, $clientId)]);
        break;

    case 'mark_all_read':
        $ok = legalpro_client_mark_all_notifications_read($pdo, $clientId);
        echo json_encode(['ok' => $ok, 'unread' => 0]);
        break;

    case 'list':
    default:
        $notifications = legalpro_client_get_notifications($pdo, $clientId, 50);
        $payload = [];
        foreach ($notifications as $n) {
            $time = legalpro_client_notification_time_parts((string) ($n['created_at'] ?? ''));
            $payload[] = [
                'id' => (int) $n['id'],
                'type' => $n['type'],
                'title' => $n['title'],
                'body' => $n['body'],
                'link_url' => legalpro_client_resolve_notification_link($n),
                'icon' => $n['icon'],
                'is_read' => (bool) $n['is_read'],
                'created_at' => $n['created_at'],
                'time_ago' => $time['ago'],
                'time_label' => $time['label'],
                'time_iso' => $time['iso'],
            ];
        }
        echo json_encode([
            'ok' => true,
            'unread' => legalpro_client_notification_count_unread($pdo, $clientId),
            'notifications' => $payload,
        ]);
        break;
}

