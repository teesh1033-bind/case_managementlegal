<?php

require_once __DIR__ . '/finance-document-styles.php';
require_once dirname(__DIR__) . '/lib/bank_accounts.php';

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

function legalpro_render_finance_payment_details_html(
    ?int $bankSlot,
    ?string $paymentTerms = null,
    ?string $paymentInstructions = null,
    ?string $statusLabel = null
): string {
    $slot = $bankSlot !== null && $bankSlot > 0 ? $bankSlot : getDefaultBankAccountSlot();
    $account = getBankAccountBySlot($slot) ?? bank_account_empty_slot($slot);
    $vat = getCompanyVatNumber();

    $lines = [];
    if ($statusLabel !== null && trim($statusLabel) !== '') {
        $lines[] = '<p class="fin-doc-pay-status">' . legalpro_finance_h($statusLabel) . '</p>';
    }

    $detailRows = [
        'Account name' => $account['account_name'] ?? '',
        'Account number' => $account['account_number'] ?? '',
        'Bank name' => $account['bank_name'] ?? '',
    ];

    if ($vat !== '') {
        $detailRows['VAT Number'] = $vat;
    }

    foreach ($detailRows as $label => $value) {
        $value = trim((string) $value);
        if ($value === '') {
            continue;
        }
        $lines[] = '<div class="fin-doc-pay-line"><span class="fin-doc-pay-label">' . legalpro_finance_h($label) . ':</span> '
            . legalpro_finance_h($value) . '</div>';
    }

    $lines[] = '<div class="fin-doc-thanks">Thank you for your business.</div>';

    return '<div class="fin-doc-payment">' . implode('', $lines) . '</div>';
}

function legalpro_render_finance_summary_box_html(array $rows, string $grandTotal): string
{
    $html = '<div class="fin-doc-summary-box"><table class="fin-doc-summary-table">';
    foreach ($rows as $row) {
        if (!empty($row['divider'])) {
            $html .= '<tr class="divider"><td colspan="2"></td></tr>';
            continue;
        }
        $class = '';
        if (!empty($row['emphasis'])) {
            $class = ' class="emphasis"';
        } elseif (!empty($row['grand'])) {
            $class = ' class="grand"';
        }
        $html .= '<tr' . $class . '><td class="label">' . legalpro_finance_h($row['label']) . '</td>'
            . '<td class="value">' . legalpro_finance_h($row['value']) . '</td></tr>';
    }
    $html .= '<tr class="grand-total"><td class="label">Grand Total</td><td class="value">'
        . legalpro_finance_h($grandTotal) . '</td></tr>';
    $html .= '</table></div>';

    return $html;
}

/**
 * Shared PDF/HTML document header with company logo beside company name.
 */
function legalpro_render_finance_document_top(string $heading, string $badge = ''): string
{
    $firm = legalpro_finance_firm_details();
    $logoSrc = function_exists('legalpro_company_logo_data_uri') ? legalpro_company_logo_data_uri() : null;

    $logoHtml = '';
    if ($logoSrc !== null && $logoSrc !== '') {
        $logoHtml = '<img class="fin-doc-logo" src="' . $logoSrc . '" alt="' . legalpro_finance_h($firm['name']) . ' logo">';
    }

    $badgeHtml = $badge !== ''
        ? '<div class="fin-doc-top-actions"><div class="fin-doc-badge">' . legalpro_finance_h($badge) . '</div></div>'
        : '<div class="fin-doc-top-actions"></div>';

    return '<div class="fin-doc-top">'
        . '<div class="fin-doc-top-main">'
        . '<div class="fin-doc-brand-row">' . $logoHtml
        . '<div class="fin-doc-brand-text"><div class="fin-doc-firm">' . legalpro_finance_h($firm['name']) . '</div></div>'
        . '</div>'
        . '<h1>' . legalpro_finance_h($heading) . '</h1>'
        . '</div>'
        . $badgeHtml
        . '</div>';
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
    global $pdo;

    $firm = legalpro_finance_firm_details();
    $invoiceNumber = $invoice['invoice_number'] ?: ('INV-' . str_pad((string) $invoiceId, 4, '0', STR_PAD_LEFT));
    $amount = (float) ($invoice['amount'] ?? 0);
    $taxRate = (float) ($invoice['tax_rate'] ?? 0);
    $subtotal = $taxRate > 0 ? round($amount / (1 + ($taxRate / 100)), 2) : $amount;
    $taxAmount = round($amount - $subtotal, 2);
    $amountDisplay = formatCurrency($amount);
    $issueDate = !empty($invoice['issue_date']) ? date('F d, Y', strtotime($invoice['issue_date'])) : 'N/A';
    $dueDate = !empty($invoice['due_date']) ? date('F d, Y', strtotime($invoice['due_date'])) : 'N/A';
    $notes = trim((string) ($invoice['notes'] ?? ''));
    $status = ucfirst((string) ($invoice['status'] ?? 'draft'));

    $paidTotal = (float) ($invoice['total_paid'] ?? 0);
    if ($paidTotal <= 0 && $pdo instanceof PDO) {
        try {
            $paidStmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE invoice_id = ?');
            $paidStmt->execute([$invoiceId]);
            $paidTotal = (float) $paidStmt->fetchColumn();
        } catch (PDOException $e) {
            $paidTotal = 0.0;
        }
    }

    $amountDue = max($amount - $paidTotal, 0);
    if ($status === 'Paid' || $paidTotal >= $amount) {
        $status = 'Paid';
    }

    $bankSlot = isset($invoice['bank_account_slot']) ? (int) $invoice['bank_account_slot'] : getDefaultBankAccountSlot();
    $paymentTerms = (string) ($invoice['payment_terms'] ?? '');
    $paymentInstructions = (string) ($invoice['payment_instructions'] ?? '');

    $summaryRows = [
        ['label' => 'Subtotal', 'value' => formatCurrency($subtotal)],
        ['label' => 'VAT Amount', 'value' => formatCurrency($taxAmount)],
        ['label' => 'Net Amount (Excluding VAT)', 'value' => formatCurrency($subtotal)],
        ['label' => 'Net Amount (Including VAT)', 'value' => formatCurrency($amount)],
        ['label' => 'Amount Paid', 'value' => formatCurrency($paidTotal)],
        ['divider' => true],
        ['label' => 'Amount Due', 'value' => formatCurrency($amountDue), 'emphasis' => true],
        ['divider' => true],
    ];

    $notesBlock = '';
    if ($notes !== '') {
        $notesBlock = '<div class="fin-doc-section"><div class="fin-doc-section-title">Notes</div>'
            . '<div class="fin-doc-notes">' . nl2br(legalpro_finance_h($notes)) . '</div></div>';
    }

    return '<div class="fin-doc">'
        . legalpro_render_finance_document_top('Invoice', $invoiceNumber)
        . '<div class="fin-doc-body">'
        . '<div class="fin-doc-section"><div class="fin-doc-section-title">Invoice details</div><div class="fin-doc-grid">'
        . '<div><strong>Issue date</strong>' . legalpro_finance_h($issueDate) . '</div>'
        . '<div><strong>Due date</strong>' . legalpro_finance_h($dueDate) . '</div>'
        . '<div><strong>Status</strong>' . legalpro_finance_h($status) . '</div>'
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
        . legalpro_render_finance_summary_box_html($summaryRows, $amountDisplay)
        . '</div>'
        . $notesBlock
        . legalpro_render_finance_payment_details_html($bankSlot, $paymentTerms, $paymentInstructions, $status)
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
        . legalpro_render_finance_document_top('Payment Receipt', $receiptNumber)
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
        . legalpro_render_finance_payment_details_html(getDefaultBankAccountSlot(), getDefaultPaymentTerms(), getDefaultPaymentInstructions(), 'Paid')
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

    $bankSlot = isset($quotation['bank_account_slot']) ? (int) $quotation['bank_account_slot'] : getDefaultBankAccountSlot();
    $paymentTerms = (string) ($quotation['payment_terms'] ?? '');
    $paymentInstructions = (string) ($quotation['payment_instructions'] ?? '');
    $grandTotal = formatCurrency((float) ($quotation['total_amount'] ?? 0));
    $summaryRows = [
        ['label' => 'Subtotal', 'value' => formatCurrency((float) ($quotation['subtotal'] ?? 0))],
        ['label' => 'VAT Amount (' . number_format((float) ($quotation['tax_rate'] ?? 0), 2) . '%)', 'value' => formatCurrency((float) ($quotation['tax_amount'] ?? 0))],
        ['label' => 'Amount Due', 'value' => $grandTotal, 'grand' => true],
    ];

    return '<div class="fin-doc">'
        . legalpro_render_finance_document_top($title, $quotationNumber)
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
        . legalpro_render_finance_summary_box_html($summaryRows, $grandTotal)
        . '</div>'
        . $notesBlock
        . legalpro_render_finance_payment_details_html($bankSlot, $paymentTerms, $paymentInstructions, ucfirst((string) ($quotation['status'] ?? 'sent')))
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
