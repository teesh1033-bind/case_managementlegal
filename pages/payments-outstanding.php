<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../lib/admin-payments-activity-portal.php';

$state = legalpro_payments_activity_init_state();
$state['outstandingRowsHtml'] = legalpro_payments_activity_build_outstanding_rows($pdo);
legalpro_payments_activity_render_page(
    'payments-outstanding',
    legalpro_payments_activity_outstanding_content_html($state),
    $state
);
