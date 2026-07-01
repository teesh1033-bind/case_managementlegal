<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../lib/documents-portal.php';

legalpro_documents_require_admin();

$draft = legalpro_documents_get_generated_draft();
if ($draft === null) {
    http_response_code(404);
    echo 'No generated document found. Please generate a document first.';
    exit;
}

legalpro_deliver_legal_document($draft);
