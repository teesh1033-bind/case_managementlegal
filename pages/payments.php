<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../lib/admin-payments-activity-portal.php';
require_once __DIR__ . '/../lib/finance-reference-numbers.php';

legalpro_ensure_payment_receipt_number_column($pdo);
$state = legalpro_payments_portal_load($pdo);
$content = legalpro_payments_portal_wrap_content(
    'payments',
    legalpro_payments_portal_overview_html($state)
);
legalpro_payments_activity_render_page('payments', $content, $state);
