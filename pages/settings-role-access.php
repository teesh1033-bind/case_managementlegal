<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../lib/admin-settings-portal.php';
require_once __DIR__ . '/../lib/admin-role-access.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

if (!legalpro_admin_can_manage_role_access()) {
    header('Location: dashboard.php?msg=' . urlencode('Access denied. Only administrators can manage role access.') . '&type=danger');
    exit;
}

$state = legalpro_admin_settings_init_state();
legalpro_admin_settings_handle_post($state);
legalpro_admin_settings_load($state);

legalpro_settings_render_page('settings-role-access', legalpro_settings_role_access_content_html($state), $state);
