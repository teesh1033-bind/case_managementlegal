<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/finance-document-templates.php';
require_once __DIR__ . '/../lib/finance-document-i18n.php';
require_once __DIR__ . '/../lib/finance-reference-numbers.php';

$paymentId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($paymentId <= 0) {
    http_response_code(400);
    echo 'Invalid payment id.';
    exit;
}

$stmt = $pdo->prepare("
    SELECT
        p.*,
        c.id AS case_id,
        c.title AS case_title,
        c.status AS case_status,
        COALESCE(c.estimated_fees, 0) AS estimated_fees,
        c.category,
        c.priority,
        CONCAT(cl.first_name, ' ', cl.last_name) AS client_name,
        cl.email AS client_email,
        cl.phone AS client_phone,
        i.bank_account_slot AS invoice_bank_account_slot,
        i.payment_terms AS invoice_payment_terms,
        i.payment_instructions AS invoice_payment_instructions
    FROM payments p
    LEFT JOIN cases c ON c.id = p.case_id
    LEFT JOIN clients cl ON cl.id = p.client_id
    LEFT JOIN invoices i ON i.id = p.invoice_id
    WHERE p.id = ?
");
$stmt->execute([$paymentId]);
$payment = $stmt->fetch();

if (!$payment) {
    http_response_code(404);
    echo 'Receipt data not found.';
    exit;
}

legalpro_require_financial_document_access(
    isset($payment['client_id']) ? (int) $payment['client_id'] : null
);

$clientId = isset($payment['client_id']) ? (int) $payment['client_id'] : 0;
legalpro_finance_doc_begin($clientId > 0 ? $clientId : null);

$caseId = isset($payment['case_id']) ? (int) $payment['case_id'] : 0;
$caseNumber = $caseId ? 'C-' . str_pad((string) $caseId, 4, '0', STR_PAD_LEFT) : fin_doc_t('na');
$receiptNumber = legalpro_resolve_receipt_number($pdo, $paymentId, $payment);
$bodyHtml = legalpro_render_payment_receipt_document_html($payment, $paymentId);
$fileName = 'receipt-' . legalpro_finance_safe_filename($caseNumber) . '-' . legalpro_finance_safe_filename($receiptNumber) . '.pdf';

legalpro_deliver_finance_document(
    fin_doc_t('title_receipt', ['number' => $receiptNumber]),
    $bodyHtml,
    $fileName,
    'payment-receipt.php'
);
