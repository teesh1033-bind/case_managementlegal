<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../lib/admin-payments-activity-portal.php';

$state = legalpro_payments_portal_load($pdo);
$content = legalpro_payments_portal_wrap_content(
    'payments-ledger',
    legalpro_payments_portal_ledger_html($state)
);
legalpro_payments_activity_render_page('payments-ledger', $content, $state);
