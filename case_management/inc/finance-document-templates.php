<?php

require_once __DIR__ . '/finance-document-styles.php';

function legalpro_finance_safe_filename(string $base): string
{
    return preg_replace('/[^A-Za-z0-9_\-]/', '', $base) ?: 'document';
}

function legalpro_finance_firm_details(): array
{
    $details = trim((string) getCompanyDetails());

    return [
        'name' => getCompanyName(),
        'address' => $details !== '' ? $details : '123 Legal Street, Capital City',
        'email' => 'support@legalpro.local',
        'phone' => '+1 (555) 010-0000',
    ];
}

function legalpro_finance_h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function legalpro_render_finance_document_page(
    string $title,
    string $bodyHtml,
    string $mode = 'pdf'
): string {
    $docTitle = legalpro_finance_h($title);
    $css = legalpro_finance_document_css();
    $autoPrint = $mode === 'print';
    $onload = $autoPrint ? ' onload="window.print()"' : '';

    return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
        . '<title>' . $docTitle . '</title>'
        . '<style>' . $css . '</style>'
        . '</head><body class="fin-doc-page"' . $onload . '>'
        . $bodyHtml
        . '</body></html>';
}

function legalpro_render_invoice_document_html(array $invoice, int $invoiceId): string
{
    $firm = legalpro_finance_firm_details();
    $invoiceNumber = $invoice['invoice_number'] ?: ('INV-' . str_pad((string) $invoiceId, 4, '0', STR_PAD_LEFT));
    $amountDisplay = formatCurrency((float) ($invoice['amount'] ?? 0));
    $issueDate = !empty($invoice['issue_date']) ? date('F d, Y', strtotime($invoice['issue_date'])) : 'N/A';
    $dueDate = !empty($invoice['due_date']) ? date('F d, Y', strtotime($invoice['due_date'])) : 'N/A';
    $notes = trim((string) ($invoice['notes'] ?? '')) ?: 'Thank you for your business.';

    return '<div class="fin-doc">'
        . '<div class="fin-doc-top">'
        . '<div><h1>Invoice</h1><div class="fin-doc-firm">' . legalpro_finance_h($firm['name']) . '</div></div>'
        . '<div class="fin-doc-top-actions"><div class="fin-doc-badge">' . legalpro_finance_h($invoiceNumber) . '</div></div>'
        . '</div>'
        . '<div class="fin-doc-body">'
        . '<div class="fin-doc-section"><div class="fin-doc-section-title">Invoice details</div><div class="fin-doc-grid">'
        . '<div><strong>Issue date</strong>' . legalpro_finance_h($issueDate) . '</div>'
        . '<div><strong>Due date</strong>' . legalpro_finance_h($dueDate) . '</div>'
        . '<div><strong>Status</strong>' . legalpro_finance_h(ucfirst((string) ($invoice['status'] ?? ''))) . '</div>'
        . '<div><strong>Case</strong>' . legalpro_finance_h($invoice['case_title'] ?: 'N/A') . '</div>'
        . '</div></div>'
        . '<div class="fin-doc-section"><div class="fin-doc-section-title">Client</div><div class="fin-doc-grid">'
        . '<div><strong>Name</strong>' . legalpro_finance_h($invoice['client_name'] ?: 'Client') . '</div>'
        . '<div><strong>Email</strong>' . legalpro_finance_h($invoice['client_email'] ?: 'N/A') . '</div>'
        . '<div><strong>Phone</strong>' . legalpro_finance_h($invoice['client_phone'] ?: 'N/A') . '</div>'
        . '</div></div>'
        . '<div class="fin-doc-section"><div class="fin-doc-section-title">Summary</div>'
        . '<table class="fin-doc-table"><thead><tr><th>Description</th><th class="text-end">Amount</th></tr></thead><tbody>'
        . '<tr><td>Professional services · ' . legalpro_finance_h($invoice['case_title'] ?: 'N/A') . '</td>'
        . '<td class="text-end">' . legalpro_finance_h($amountDisplay) . '</td></tr>'
        . '</tbody></table>'
        . '<table class="fin-doc-totals"><tr class="grand"><td class="label">Total due</td><td class="value">'
        . legalpro_finance_h($amountDisplay) . '</td></tr></table></div>'
        . '<div class="fin-doc-section"><div class="fin-doc-section-title">Notes</div>'
        . '<div class="fin-doc-notes">' . nl2br(legalpro_finance_h($notes)) . '</div></div>'
        . '<div class="fin-doc-section"><div class="fin-doc-section-title">Issued by</div>'
        . '<div class="fin-doc-footer">' . legalpro_finance_h($firm['name']) . ' · ' . nl2br(legalpro_finance_h($firm['address'])) . '<br>'
        . 'Email: ' . legalpro_finance_h($firm['email']) . ' · Phone: ' . legalpro_finance_h($firm['phone']) . '</div></div>'
        . '</div></div>';
}

function legalpro_render_payment_receipt_document_html(array $payment, int $paymentId): string
{
    $firm = legalpro_finance_firm_details();
    global $pdo;

    $caseId = isset($payment['case_id']) ? (int) $payment['case_id'] : 0;
    $caseNumber = $caseId ? 'C-' . str_pad((string) $caseId, 4, '0', STR_PAD_LEFT) : 'N/A';
    $receiptNumber = 'RC-' . str_pad((string) $paymentId, 6, '0', STR_PAD_LEFT);
    $issuedDate = !empty($payment['payment_date'])
        ? date('d M Y', strtotime($payment['payment_date']))
        : date('d M Y', strtotime((string) $payment['created_at']));
    $amountFormatted = formatCurrency((float) ($payment['amount'] ?? 0));

    $paidToDate = 0.0;
    if ($caseId > 0 && $pdo instanceof PDO) {
        $sumStmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE case_id = ? AND id <= ?');
        $sumStmt->execute([$caseId, $paymentId]);
        $paidToDate = (float) $sumStmt->fetchColumn();
    }
    $balance = max((float) ($payment['estimated_fees'] ?? 0) - $paidToDate, 0);

    return '<div class="fin-doc">'
        . '<div class="fin-doc-top">'
        . '<div><h1>Payment Receipt</h1><div class="fin-doc-firm">' . legalpro_finance_h($firm['name']) . '</div></div>'
        . '<div class="fin-doc-top-actions"><div class="fin-doc-badge">' . legalpro_finance_h($receiptNumber) . '</div></div>'
        . '</div>'
        . '<div class="fin-doc-body">'
        . '<div class="fin-doc-section"><div class="fin-doc-section-title">Receipt details</div><div class="fin-doc-grid">'
        . '<div><strong>Issued on</strong>' . legalpro_finance_h($issuedDate) . '</div>'
        . '<div><strong>Case number</strong>' . legalpro_finance_h($caseNumber) . '</div>'
        . '<div><strong>Case title</strong>' . legalpro_finance_h($payment['case_title'] ?? '') . '</div>'
        . '<div><strong>Payment method</strong>' . legalpro_finance_h(ucfirst((string) ($payment['method'] ?? ''))) . '</div>'
        . '</div></div>'
        . '<div class="fin-doc-section"><div class="fin-doc-section-title">Client</div><div class="fin-doc-grid">'
        . '<div><strong>Name</strong>' . legalpro_finance_h($payment['client_name'] ?? '') . '</div>'
        . '<div><strong>Email</strong>' . legalpro_finance_h($payment['client_email'] ?? '') . '</div>'
        . '<div><strong>Phone</strong>' . legalpro_finance_h($payment['client_phone'] ?? '') . '</div>'
        . '</div></div>'
        . '<div class="fin-doc-section"><div class="fin-doc-section-title">Payment summary</div>'
        . '<table class="fin-doc-table"><thead><tr><th>Description</th><th>Reference</th><th>Recorded by</th><th class="text-end">Amount</th></tr></thead><tbody>'
        . '<tr><td>' . legalpro_finance_h('Payment for ' . ($payment['case_title'] ?? '')) . '</td>'
        . '<td>' . legalpro_finance_h($payment['reference'] ?: '—') . '</td>'
        . '<td>' . legalpro_finance_h($payment['recorded_by'] ?: '—') . '</td>'
        . '<td class="text-end">' . legalpro_finance_h($amountFormatted) . '</td></tr>'
        . '</tbody></table>'
        . '<table class="fin-doc-totals">'
        . '<tr><td class="label">Total fees</td><td class="value">' . legalpro_finance_h(formatCurrency((float) ($payment['estimated_fees'] ?? 0))) . '</td></tr>'
        . '<tr><td class="label">Paid to date</td><td class="value">' . legalpro_finance_h(formatCurrency($paidToDate)) . '</td></tr>'
        . '<tr class="grand"><td class="label">Balance remaining</td><td class="value">' . legalpro_finance_h(formatCurrency($balance)) . '</td></tr>'
        . '</table></div>'
        . '<div class="fin-doc-section"><div class="fin-doc-section-title">Notes</div>'
        . '<div class="fin-doc-notes">' . nl2br(legalpro_finance_h($payment['notes'] ?: 'No additional notes were provided.')) . '</div></div>'
        . '<div class="fin-doc-section"><div class="fin-doc-section-title">Issued by</div>'
        . '<div class="fin-doc-footer">' . legalpro_finance_h($firm['name']) . ' · ' . nl2br(legalpro_finance_h($firm['address'])) . '<br>'
        . 'Email: ' . legalpro_finance_h($firm['email']) . ' · Phone: ' . legalpro_finance_h($firm['phone']) . '</div></div>'
        . '<div class="fin-doc-signature"><div>Authorized signature</div><div class="fin-doc-signature-line"></div></div>'
        . '</div></div>';
}

function legalpro_render_quotation_document_html(array $quotation, array $items, int $quotationId): string
{
    $firm = legalpro_finance_firm_details();
    $quotationNumber = $quotation['quotation_number'] ?: ('QUO-' . str_pad((string) $quotationId, 4, '0', STR_PAD_LEFT));
    $title = trim((string) ($quotation['title'] ?? '')) ?: 'Quotation';
    $issuedDate = !empty($quotation['created_at']) ? date('F d, Y', strtotime($quotation['created_at'])) : 'N/A';
    $validUntil = !empty($quotation['valid_until']) ? date('F d, Y', strtotime($quotation['valid_until'])) : 'N/A';
    $notes = trim((string) ($quotation['notes'] ?? ''));

    $rows = '';
    foreach ($items as $item) {
        $qty = rtrim(rtrim(number_format((float) ($item['quantity'] ?? 0), 2, '.', ''), '0'), '.');
        $rows .= '<tr><td>' . legalpro_finance_h($item['description'] ?? '') . '</td>'
            . '<td class="text-end">' . legalpro_finance_h($qty) . '</td>'
            . '<td class="text-end">' . legalpro_finance_h(formatCurrency((float) ($item['unit_price'] ?? 0))) . '</td>'
            . '<td class="text-end">' . legalpro_finance_h(formatCurrency((float) ($item['line_total'] ?? 0))) . '</td></tr>';
    }

    $notesBlock = '';
    if ($notes !== '') {
        $notesBlock = '<div class="fin-doc-section"><div class="fin-doc-section-title">Notes</div>'
            . '<div class="fin-doc-notes">' . nl2br(legalpro_finance_h($notes)) . '</div></div>';
    }

    return '<div class="fin-doc">'
        . '<div class="fin-doc-top">'
        . '<div><h1>' . legalpro_finance_h($title) . '</h1><div class="fin-doc-firm">' . legalpro_finance_h($firm['name']) . '</div></div>'
        . '<div class="fin-doc-top-actions"><div class="fin-doc-badge">' . legalpro_finance_h($quotationNumber) . '</div></div>'
        . '</div>'
        . '<div class="fin-doc-body">'
        . '<div class="fin-doc-section"><div class="fin-doc-section-title">From</div><div class="fin-doc-grid">'
        . '<div><strong>' . legalpro_finance_h($firm['name']) . '</strong>' . nl2br(legalpro_finance_h($firm['address'])) . '</div>'
        . '</div></div>'
        . '<div class="fin-doc-section"><div class="fin-doc-section-title">Prepared for</div><div class="fin-doc-grid">'
        . '<div><strong>Client</strong>' . legalpro_finance_h($quotation['client_name'] ?? 'Client') . '</div>'
        . '<div><strong>Case</strong>' . legalpro_finance_h($quotation['case_title'] ?? 'N/A') . '</div>'
        . '<div><strong>Issued</strong>' . legalpro_finance_h($issuedDate) . '</div>'
        . '<div><strong>Valid until</strong>' . legalpro_finance_h($validUntil) . '</div>'
        . '</div></div>'
        . '<div class="fin-doc-section"><div class="fin-doc-section-title">Quoted services</div>'
        . '<table class="fin-doc-table"><thead><tr><th>Description</th><th class="text-end">Qty</th><th class="text-end">Unit price</th><th class="text-end">Line total</th></tr></thead><tbody>'
        . $rows
        . '</tbody></table>'
        . '<table class="fin-doc-totals">'
        . '<tr><td class="label">Subtotal</td><td class="value">' . legalpro_finance_h(formatCurrency((float) ($quotation['subtotal'] ?? 0))) . '</td></tr>'
        . '<tr><td class="label">Tax (' . legalpro_finance_h(number_format((float) ($quotation['tax_rate'] ?? 0), 2)) . '%)</td><td class="value">'
        . legalpro_finance_h(formatCurrency((float) ($quotation['tax_amount'] ?? 0))) . '</td></tr>'
        . '<tr class="grand"><td class="label">Total</td><td class="value">' . legalpro_finance_h(formatCurrency((float) ($quotation['total_amount'] ?? 0))) . '</td></tr>'
        . '</table></div>'
        . $notesBlock
        . '</div></div>';
}

function legalpro_deliver_finance_document(
    string $pageTitle,
    string $bodyHtml,
    string $pdfFileName,
    string $selfScript
): void {
    require_once dirname(__DIR__) . '/lib/finance_pdf.php';

    $mode = legalpro_finance_document_request_mode();
    $idParam = isset($_GET['id']) ? 'id=' . (int) $_GET['id'] : '';
    $baseUrl = $selfScript . ($idParam !== '' ? '?' . $idParam : '');

    if ($mode === 'view' || $mode === 'print') {
        $toolbar = '<div class="fin-doc-toolbar no-print">'
            . '<a href="' . legalpro_finance_h($baseUrl) . '" class="fin-doc-action fin-doc-action--download">Download PDF</a>'
            . '</div>';
        $bodyHtml = $toolbar . $bodyHtml;

        header('Content-Type: text/html; charset=utf-8');
        echo legalpro_render_finance_document_page($pageTitle, $bodyHtml, $mode);
        exit;
    }

    $html = legalpro_render_finance_document_page($pageTitle, $bodyHtml, 'pdf');
    legalpro_output_finance_pdf($html, $pdfFileName);
}
