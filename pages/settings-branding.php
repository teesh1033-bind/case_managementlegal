<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../lib/admin-settings-portal.php';

$state = legalpro_admin_settings_init_state();
legalpro_admin_settings_handle_post($state);
legalpro_admin_settings_load($state);

legalpro_settings_render_page('settings-branding', legalpro_settings_branding_content_html($state), $state);
