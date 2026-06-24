<?php
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../lib/case_quotations.php';
require_once __DIR__ . '/../inc/finance-document-templates.php';

ensure_case_quotation_schema($pdo);

$quotationId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($quotationId <= 0) {
    http_response_code(400);
    echo 'Invalid quotation ID.';
    exit;
}

$quotation = fetch_quotation_with_case($pdo, $quotationId);
if (!$quotation) {
    http_response_code(404);
    echo 'Quotation not found.';
    exit;
}

legalpro_require_financial_document_access(
    isset($quotation['client_id']) ? (int) $quotation['client_id'] : null
);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SESSION['client_id'])) {
    header('Location: client-payments.php#quotations');
    exit;
}

$items = fetch_quotation_items($pdo, $quotationId);
$quotationNumber = $quotation['quotation_number'] ?: ('QUO-' . str_pad((string) $quotationId, 4, '0', STR_PAD_LEFT));
$title = trim((string) ($quotation['title'] ?? '')) ?: 'Quotation';
$bodyHtml = legalpro_render_quotation_document_html($quotation, $items, $quotationId);
$fileName = 'quotation-' . legalpro_finance_safe_filename($quotationNumber) . '.pdf';

legalpro_deliver_finance_document(
    $quotationNumber . ' · ' . $title,
    $bodyHtml,
    $fileName,
    'client-quotation-view.php'
);
