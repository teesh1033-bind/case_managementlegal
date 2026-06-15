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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?php echo h($quotationNumber); ?> · Quotation</title>
    <style>
        body { font-family: Arial, Helvetica, sans-serif; margin: 0; padding: 24px; color: #222; background: #f8fafc; }
        .quotation { max-width: 760px; margin: 0 auto; background: #fff; border: 1px solid #e0e0e0; border-radius: 8px; padding: 32px; }
        .header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 24px; gap: 12px; }
        .header h1 { margin: 0 0 6px; font-size: 22px; letter-spacing: 0.5px; }
        .badge { background: #111827; color: #fff; padding: 6px 10px; border-radius: 4px; font-size: 12px; display: inline-block; }
        .print-btn { background: #111827; color: #fff; border: none; border-radius: 4px; padding: 8px 14px; cursor: pointer; font-size: 13px; }
        .print-btn:hover { opacity: 0.9; }
        .section { margin-bottom: 24px; }
        .section-title { font-size: 14px; letter-spacing: 1px; color: #6b7280; text-transform: uppercase; margin-bottom: 8px; }
        .info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; font-size: 14px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; font-size: 14px; }
        th, td { border: 1px solid #e5e7eb; padding: 10px; text-align: left; }
        th { background: #f9fafb; text-transform: uppercase; font-size: 12px; letter-spacing: 0.5px; color: #6b7280; }
        .text-end { text-align: right; }
        .totals { width: 50%; margin-left: auto; margin-top: 18px; font-size: 14px; }
        .totals td { border: none; padding: 4px 0; }
        .totals td.label { color: #6b7280; text-transform: uppercase; font-size: 12px; }
        .totals td.value { text-align: right; font-weight: bold; }
        .notes { background: #f9fafb; border-radius: 6px; padding: 14px; font-size: 14px; line-height: 1.5; }
        @media print { body { padding: 0; background: #fff; } .quotation { border: none; border-radius: 0; } .print-btn { display: none; } }
    </style>
</head>
<body>
    <div class="quotation">
        <div class="header">
            <div>
                <h1><?php echo h($title); ?></h1>
                <div><span class="badge"><?php echo h($quotationNumber); ?></span></div>
                <p style="margin:10px 0 0;font-size:14px;color:#6b7280;">Prepared on <?php echo h($today); ?></p>
            </div>
            <button type="button" class="print-btn" onclick="window.print()">Print / Save PDF</button>
        </div>

        <div class="section">
            <div class="section-title">From</div>
            <div class="info-grid">
                <div><strong><?php echo h($firmName); ?></strong><br><?php echo nl2br(h($firmAddress)); ?></div>
            </div>
        </div>

        <div class="section">
            <div class="section-title">Prepared for</div>
            <div class="info-grid">
                <div><strong><?php echo h($clientName); ?></strong></div>
                <div><strong>Case:</strong> <?php echo h($caseTitle); ?></div>
                <div><strong>Status:</strong> <?php echo h($status); ?></div>
                <div><strong>Issued:</strong> <?php echo h($issuedDate); ?></div>
                <div><strong>Valid until:</strong> <?php echo h($validUntil); ?></div>
            </div>
        </div>

        <div class="section">
            <div class="section-title">Quoted services</div>
            <table>
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

            <table class="totals">
                <tr>
                    <td class="label">Subtotal</td>
                    <td class="value"><?php echo h(formatCurrency((float) $quotation['subtotal'])); ?></td>
                </tr>
                <tr>
                    <td class="label">Tax (<?php echo h(number_format((float) $quotation['tax_rate'], 2)); ?>%)</td>
                    <td class="value"><?php echo h(formatCurrency((float) $quotation['tax_amount'])); ?></td>
                </tr>
                <tr>
                    <td class="label">Total</td>
                    <td class="value"><?php echo h(formatCurrency((float) $quotation['total_amount'])); ?></td>
                </tr>
            </table>
        </div>

        <?php if ($notes !== ''): ?>
            <div class="section">
                <div class="section-title">Notes</div>
                <div class="notes"><?php echo nl2br(h($notes)); ?></div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
