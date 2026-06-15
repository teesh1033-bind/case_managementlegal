<?php
require_once __DIR__ . '/../inc/db.php';

$paymentId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
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
        cl.phone AS client_phone
    FROM payments p
    LEFT JOIN cases c ON c.id = p.case_id
    LEFT JOIN clients cl ON cl.id = p.client_id
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

$caseId = isset($payment['case_id']) ? (int)$payment['case_id'] : 0;
$caseNumber = $caseId ? 'C-' . str_pad($caseId, 4, '0', STR_PAD_LEFT) : 'N/A';
$receiptNumber = 'RC-' . str_pad($paymentId, 6, '0', STR_PAD_LEFT);
$issuedDate = $payment['payment_date'] ? date('d M Y', strtotime($payment['payment_date'])) : date('d M Y', strtotime($payment['created_at']));
$amountFormatted = formatCurrency($payment['amount']);

$sumStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE case_id = ? AND id <= ?");
$sumStmt->execute([$caseId, $paymentId]);
$paidToDate = (float)$sumStmt->fetchColumn();
$balance = max((float)$payment['estimated_fees'] - $paidToDate, 0);

$firmName = getCompanyName();
$firmDetails = getCompanyDetails();
$firmAddress = $firmDetails !== '' ? $firmDetails : '123 Legal Street, Capital City';
$firmEmail = 'support@legalpro.local';
$firmPhone = '+1 (555) 010-0000';

function h($value) {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

require_once __DIR__ . '/../inc/finance-document-styles.php';

ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?php echo h($receiptNumber); ?> · Payment Receipt</title>
    <?php legalpro_render_finance_document_head('Payment Receipt'); ?>
</head>
<body class="fin-doc-page">
    <div class="fin-doc">
        <div class="fin-doc-top">
            <div>
                <h1>Payment Receipt</h1>
                <div class="fin-doc-firm"><?php echo h($firmName); ?></div>
            </div>
            <div class="fin-doc-top-actions">
                <button type="button" class="fin-doc-print" onclick="window.print()">Print</button>
                <div class="fin-doc-badge"><?php echo h($receiptNumber); ?></div>
            </div>
        </div>

        <div class="fin-doc-body">
            <div class="fin-doc-section">
                <div class="fin-doc-section-title">Receipt details</div>
                <div class="fin-doc-grid">
                    <div><strong>Issued on</strong><?php echo h($issuedDate); ?></div>
                    <div><strong>Case number</strong><?php echo h($caseNumber); ?></div>
                    <div><strong>Case title</strong><?php echo h($payment['case_title']); ?></div>
                    <div><strong>Payment method</strong><?php echo h(ucfirst($payment['method'])); ?></div>
                </div>
            </div>

            <div class="fin-doc-section">
                <div class="fin-doc-section-title">Client</div>
                <div class="fin-doc-grid">
                    <div><strong>Name</strong><?php echo h($payment['client_name']); ?></div>
                    <div><strong>Email</strong><?php echo h($payment['client_email']); ?></div>
                    <div><strong>Phone</strong><?php echo h($payment['client_phone']); ?></div>
                </div>
            </div>

            <div class="fin-doc-section">
                <div class="fin-doc-section-title">Payment summary</div>
                <table class="fin-doc-table">
                    <thead>
                        <tr>
                            <th>Description</th>
                            <th>Reference</th>
                            <th>Recorded by</th>
                            <th class="text-end">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><?php echo h('Payment for ' . $payment['case_title']); ?></td>
                            <td><?php echo h($payment['reference'] ?: '—'); ?></td>
                            <td><?php echo h($payment['recorded_by'] ?: '—'); ?></td>
                            <td class="text-end"><?php echo h($amountFormatted); ?></td>
                        </tr>
                    </tbody>
                </table>
                <table class="fin-doc-totals">
                    <tr>
                        <td class="label">Total fees</td>
                        <td class="value"><?php echo formatCurrency($payment['estimated_fees']); ?></td>
                    </tr>
                    <tr>
                        <td class="label">Paid to date</td>
                        <td class="value"><?php echo formatCurrency($paidToDate); ?></td>
                    </tr>
                    <tr class="grand">
                        <td class="label">Balance remaining</td>
                        <td class="value"><?php echo formatCurrency($balance); ?></td>
                    </tr>
                </table>
            </div>

            <div class="fin-doc-section">
                <div class="fin-doc-section-title">Notes</div>
                <div class="fin-doc-notes"><?php echo nl2br(h($payment['notes'] ?: 'No additional notes were provided.')); ?></div>
            </div>

            <div class="fin-doc-section">
                <div class="fin-doc-section-title">Issued by</div>
                <div class="fin-doc-footer">
                    <?php echo h($firmName); ?> · <?php echo h($firmAddress); ?><br>
                    Email: <?php echo h($firmEmail); ?> · Phone: <?php echo h($firmPhone); ?>
                </div>
            </div>

            <div class="fin-doc-signature">
                <div>Authorized signature</div>
                <div class="fin-doc-signature-line"></div>
            </div>
        </div>
    </div>
</body>
</html>
<?php
$receiptHtml = ob_get_clean();
$fileName = 'receipt-' . preg_replace('/[^A-Za-z0-9_\-]/', '', $caseNumber) . '-' . $receiptNumber . '.html';
header('Content-Type: text/html; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $fileName . '"');
echo $receiptHtml;
exit;

