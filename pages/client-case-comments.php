<?php
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../lib/client-cases-portal.php';

$state = legalpro_client_case_init_state($pdo);
legalpro_client_case_render(
    'client-case-comments',
    legalpro_client_case_comments_html($state),
    $state,
    legalpro_client_case_comments_script()
);
