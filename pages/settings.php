<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../lib/admin-settings-portal.php';

$state = legalpro_admin_settings_init_state();
legalpro_admin_settings_handle_post($state);
legalpro_admin_settings_load($state);

$content = '<div class="card mb-4">
    <div class="card-header pb-0">
        <h6 class="mb-0">Settings workspace</h6>
        <p class="text-sm text-muted mb-0">Choose a section below to update branding, appearance, finance, practice catalog, AI assistant, or role access.</p>
    </div>
    <div class="card-body">' . legalpro_settings_hub_cards_html() . '</div>
</div>';

legalpro_settings_render_page('settings', $content, $state);
