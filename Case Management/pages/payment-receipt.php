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

$caseId = isset($payment['case_id']) ? (int)$payment['case_id'] : 0;
$caseNumber = $caseId ? 'C-' . str_pad($caseId, 4, '0', STR_PAD_LEFT) : 'N/A';
$receiptNumber = 'RC-' . str_pad($paymentId, 6, '0', STR_PAD_LEFT);
$issuedDate = $payment['payment_date'] ? date('d M Y', strtotime($payment['payment_date'])) : date('d M Y', strtotime($payment['created_at']));
$amountFormatted = formatCurrency($payment['amount']);

$sumStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE case_id = ? AND id <= ?");
$sumStmt->execute([$caseId, $paymentId]);
$paidToDate = (float)$sumStmt->fetchColumn();
$balance = max((float)$payment['estimated_fees'] - $paidToDate, 0);

$firmName = 'LegalPro Case Manager';
$firmAddress = '123 Legal Street, Capital City';
$firmEmail = 'support@legalpro.local';
$firmPhone = '+1 (555) 010-0000';

function h($value) {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?php echo h($receiptNumber); ?> · Payment Receipt</title>
    <style>
        body { font-family: Arial, Helvetica, sans-serif; margin: 0; padding: 24px; color: #222; }
        .receipt { max-width: 720px; margin: 0 auto; border: 1px solid #e0e0e0; border-radius: 8px; padding: 32px; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; gap: 12px; }
        .header h1 { margin: 0; font-size: 20px; letter-spacing: 0.5px; }
        .badge { background: #111827; color: #fff; padding: 6px 10px; border-radius: 4px; font-size: 12px; }
        .print-btn { background: #111827; color: #fff; border: none; border-radius: 4px; padding: 8px 14px; cursor: pointer; font-size: 13px; }
        .print-btn:hover { opacity: 0.9; }
        .section { margin-bottom: 24px; }
        .section-title { font-size: 14px; letter-spacing: 1px; color: #6b7280; text-transform: uppercase; margin-bottom: 8px; }
        .info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; font-size: 14px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; font-size: 14px; }
        th, td { border: 1px solid #e5e7eb; padding: 10px; text-align: left; }
        th { background: #f9fafb; text-transform: uppercase; font-size: 12px; letter-spacing: 0.5px; color: #6b7280; }
        .totals { width: 50%; margin-left: auto; margin-top: 18px; font-size: 14px; }
        .totals td { border: none; padding: 4px 0; }
        .totals td.label { color: #6b7280; }
        .totals td.value { text-align: right; font-weight: bold; }
        .signature { margin-top: 48px; font-size: 13px; color: #6b7280; }
        .signature-line { margin-top: 32px; border-top: 1px solid #d1d5db; width: 240px; }
        @media print { body { padding: 0; } .receipt { border: none; border-radius: 0; } .print-btn { display: none; } }
    </style>
</head>
<body>
    <div class="receipt">
        <div class="header">
            <div>
                <h1>Payment Receipt</h1>
                <div><?php echo h($firmName); ?></div>
            </div>
            <div style="display:flex; align-items:center; gap:8px;">
                <button class="print-btn" onclick="window.print()">Print</button>
                <div class="badge"><?php echo h($receiptNumber); ?></div>
            </div>
        </div>

        <div class="section">
            <div class="section-title">Receipt Details</div>
            <div class="info-grid">
                <div><strong>Issued On:</strong><br><?php echo h($issuedDate); ?></div>
                <div><strong>Case Number:</strong><br><?php echo h($caseNumber); ?></div>
                <div><strong>Case Title:</strong><br><?php echo h($payment['case_title']); ?></div>
                <div><strong>Payment Method:</strong><br><?php echo h(ucfirst($payment['method'])); ?></div>
            </div>
        </div>

        <div class="section">
            <div class="section-title">Client</div>
            <div class="info-grid">
                <div><strong>Name:</strong><br><?php echo h($payment['client_name']); ?></div>
                <div><strong>Email:</strong><br><?php echo h($payment['client_email']); ?></div>
                <div><strong>Phone:</strong><br><?php echo h($payment['client_phone']); ?></div>
            </div>
        </div>

        <div class="section">
            <div class="section-title">Payment Summary</div>
            <table>
                <thead>
                    <tr>
                        <th>Description</th>
                        <th>Reference</th>
                        <th>Recorded By</th>
                        <th>Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><?php echo h('Payment for ' . $payment['case_title']); ?></td>
                        <td><?php echo h($payment['reference'] ?: '—'); ?></td>
                        <td><?php echo h($payment['recorded_by'] ?: '—'); ?></td>
                        <td><?php echo h($amountFormatted); ?></td>
                    </tr>
                </tbody>
            </table>
            <table class="totals">
                <tr>
                    <td class="label">Total Fees</td>
                    <td class="value"><?php echo formatCurrency($payment['estimated_fees']); ?></td>
                </tr>
                <tr>
                    <td class="label">Paid to Date</td>
                    <td class="value"><?php echo formatCurrency($paidToDate); ?></td>
                </tr>
                <tr>
                    <td class="label">Balance Remaining</td>
                    <td class="value"><?php echo formatCurrency($balance); ?></td>
                </tr>
            </table>
        </div>

        <div class="section">
            <div class="section-title">Notes</div>
            <div><?php echo nl2br(h($payment['notes'] ?: 'No additional notes were provided.')); ?></div>
        </div>

        <div class="section">
            <div class="section-title">Issued By</div>
            <div><?php echo h($firmName); ?> · <?php echo h($firmAddress); ?><br>Email: <?php echo h($firmEmail); ?> · Phone: <?php echo h($firmPhone); ?></div>
        </div>

        <div class="signature">
            <div>Authorized Signature</div>
            <div class="signature-line"></div>
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

