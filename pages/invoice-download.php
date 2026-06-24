<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/finance-document-templates.php';

$invoiceId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($invoiceId <= 0) {
    http_response_code(400);
    echo 'Invalid invoice ID.';
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT
            inv.*,
            CONCAT(cl.first_name, ' ', cl.last_name) AS client_name,
            cl.email AS client_email,
            cl.phone AS client_phone,
            c.title AS case_title,
            COALESCE(p.total_paid, 0) AS total_paid
        FROM invoices inv
        LEFT JOIN clients cl ON cl.id = inv.client_id
        LEFT JOIN cases c ON c.id = inv.case_id
        LEFT JOIN (
            SELECT invoice_id, SUM(amount) AS total_paid
            FROM payments
            WHERE invoice_id IS NOT NULL
            GROUP BY invoice_id
        ) p ON p.invoice_id = inv.id
        WHERE inv.id = ?
    ");
    $stmt->execute([$invoiceId]);
    $invoice = $stmt->fetch();
} catch (PDOException $e) {
    http_response_code(500);
    echo 'Unable to load invoice: ' . htmlspecialchars($e->getMessage());
    exit;
}

if (!$invoice) {
    http_response_code(404);
    echo 'Invoice not found.';
    exit;
}

legalpro_require_financial_document_access(
    isset($invoice['client_id']) ? (int) $invoice['client_id'] : null
);

$invoiceNumber = $invoice['invoice_number'] ?: ('INV-' . str_pad((string) $invoiceId, 4, '0', STR_PAD_LEFT));
$bodyHtml = legalpro_render_invoice_document_html($invoice, $invoiceId);
$fileName = 'invoice-' . legalpro_finance_safe_filename($invoiceNumber) . '.pdf';

legalpro_deliver_finance_document(
    $invoiceNumber . ' · Invoice',
    $bodyHtml,
    $fileName,
    'invoice-download.php'
);
