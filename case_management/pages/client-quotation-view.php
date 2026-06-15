<?php
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../lib/case_quotations.php';

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

$items = fetch_quotation_items($pdo, $quotationId);
$quotationNumber = $quotation['quotation_number'] ?: ('QUO-' . str_pad((string) $quotationId, 4, '0', STR_PAD_LEFT));
$clientName = trim((string) ($quotation['client_name'] ?? '')) ?: 'Client';
$caseTitle = trim((string) ($quotation['case_title'] ?? '')) ?: 'N/A';
$title = trim((string) ($quotation['title'] ?? '')) ?: 'Quotation';
$status = quotation_status_label((string) ($quotation['status'] ?? 'sent'));
$issuedDate = !empty($quotation['created_at']) ? date('F d, Y', strtotime($quotation['created_at'])) : 'N/A';
$validUntil = !empty($quotation['valid_until']) ? date('F d, Y', strtotime($quotation['valid_until'])) : 'N/A';
$notes = trim((string) ($quotation['notes'] ?? ''));
$today = date('F d, Y');

$firmName = getCompanyName();
$firmDetails = getCompanyDetails();
$firmAddress = $firmDetails !== '' ? $firmDetails : '123 Legal Street, Capital City';

function h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

require_once __DIR__ . '/../inc/finance-document-styles.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?php echo h($quotationNumber); ?> · Quotation</title>
    <?php legalpro_render_finance_document_head('Quotation'); ?>
</head>
<body class="fin-doc-page">
    <div class="fin-doc">
        <div class="fin-doc-top">
            <div>
                <h1><?php echo h($title); ?></h1>
                <div class="fin-doc-firm"><?php echo h($firmName); ?></div>
            </div>
            <div class="fin-doc-top-actions">
                <button type="button" class="fin-doc-print" onclick="window.print()">Print / Save PDF</button>
                <div class="fin-doc-badge"><?php echo h($quotationNumber); ?></div>
            </div>
        </div>

        <div class="fin-doc-body">
            <div class="fin-doc-section">
                <div class="fin-doc-section-title">From</div>
                <div class="fin-doc-grid">
                    <div><strong><?php echo h($firmName); ?></strong><?php echo nl2br(h($firmAddress)); ?></div>
                </div>
            </div>

            <div class="fin-doc-section">
                <div class="fin-doc-section-title">Prepared for</div>
                <div class="fin-doc-grid">
                    <div><strong>Client</strong><?php echo h($clientName); ?></div>
                    <div><strong>Case</strong><?php echo h($caseTitle); ?></div>
                    <div><strong>Status</strong><?php echo h($status); ?></div>
                    <div><strong>Issued</strong><?php echo h($issuedDate); ?></div>
                    <div><strong>Valid until</strong><?php echo h($validUntil); ?></div>
                </div>
            </div>

            <div class="fin-doc-section">
                <div class="fin-doc-section-title">Quoted services</div>
                <table class="fin-doc-table">
                    <thead>
                        <tr>
                            <th>Description</th>
                            <th class="text-end">Qty</th>
                            <th class="text-end">Unit price</th>
                            <th class="text-end">Line total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td><?php echo h($item['description']); ?></td>
                                <td class="text-end"><?php echo h(rtrim(rtrim(number_format((float) $item['quantity'], 2, '.', ''), '0'), '.')); ?></td>
                                <td class="text-end"><?php echo h(formatCurrency((float) $item['unit_price'])); ?></td>
                                <td class="text-end"><?php echo h(formatCurrency((float) $item['line_total'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <table class="fin-doc-totals">
                    <tr>
                        <td class="label">Subtotal</td>
                        <td class="value"><?php echo h(formatCurrency((float) $quotation['subtotal'])); ?></td>
                    </tr>
                    <tr>
                        <td class="label">Tax (<?php echo h(number_format((float) $quotation['tax_rate'], 2)); ?>%)</td>
                        <td class="value"><?php echo h(formatCurrency((float) $quotation['tax_amount'])); ?></td>
                    </tr>
                    <tr class="grand">
                        <td class="label">Total</td>
                        <td class="value"><?php echo h(formatCurrency((float) $quotation['total_amount'])); ?></td>
                    </tr>
                </table>
            </div>

            <?php if ($notes !== ''): ?>
                <div class="fin-doc-section">
                    <div class="fin-doc-section-title">Notes</div>
                    <div class="fin-doc-notes"><?php echo nl2br(h($notes)); ?></div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
