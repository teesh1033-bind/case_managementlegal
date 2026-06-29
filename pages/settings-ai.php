<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../lib/admin-settings-portal.php';

$state = legalpro_admin_settings_init_state();
legalpro_admin_settings_handle_post($state);
legalpro_admin_settings_load($state);

legalpro_settings_render_page('settings-ai', legalpro_settings_ai_content_html($state), $state);
