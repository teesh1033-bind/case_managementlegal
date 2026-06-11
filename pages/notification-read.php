<?php
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../lib/portal_notifications.php';

$actor = legalpro_portal_notification_actor();
if (!$actor) {
    header('Location: admin-login.php');
    exit;
}

$notifKey = trim((string) ($_GET['key'] ?? ''));
$redirectTo = legalpro_notification_sanitize_redirect((string) ($_GET['to'] ?? ''));

if ($redirectTo === '') {
    if ($actor['role'] === 'lawyer') {
        $redirectTo = 'lawyer-dashboard.php';
    } elseif ($actor['role'] === 'client') {
        $redirectTo = 'client-dashboard.php';
    } else {
        $redirectTo = 'dashboard.php';
    }
}

if ($notifKey !== '') {
    legalpro_mark_notification_read($pdo, $actor['role'], $actor['user_id'], $notifKey);
}

header('Location: ' . $redirectTo);
exit;
