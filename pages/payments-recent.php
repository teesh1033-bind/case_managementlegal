<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../lib/admin-payments-activity-portal.php';

$state = legalpro_payments_activity_init_state();
$state['recentRowsHtml'] = legalpro_payments_activity_build_recent_rows($pdo);
legalpro_payments_activity_render_page(
    'payments-recent',
    legalpro_payments_activity_recent_content_html($state),
    $state
);
