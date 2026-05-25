<?php
require_once __DIR__ . '/../inc/db.php';

$invoiceId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
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
            c.title AS case_title
        FROM invoices inv
        LEFT JOIN clients cl ON cl.id = inv.client_id
        LEFT JOIN cases c ON c.id = inv.case_id
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

$invoiceNumber = $invoice['invoice_number'] ?: ('INV-' . str_pad($invoiceId, 4, '0', STR_PAD_LEFT));
$clientName = $invoice['client_name'] ?: 'Client';
$clientEmail = $invoice['client_email'] ?: 'N/A';
$clientPhone = $invoice['client_phone'] ?: 'N/A';
$caseTitle = $invoice['case_title'] ?: 'N/A';
$amount = $invoice['amount'] ?: 0;
$currencyConfig = getCurrencyConfig();
$currencyLabel = isset($currencyConfig['code']) ? strtoupper($currencyConfig['code']) : 'USD';
$pdfAmount = $currencyLabel . ' ' . number_format((float)$amount, 2);
$amountDisplay = formatCurrency($amount);
$issueDate = $invoice['issue_date'] ? date('F d, Y', strtotime($invoice['issue_date'])) : 'N/A';
$dueDate = $invoice['due_date'] ? date('F d, Y', strtotime($invoice['due_date'])) : 'N/A';
$status = ucfirst($invoice['status']);
$notes = trim($invoice['notes']) ?: 'Thank you for your business.';
$today = date('F d, Y');

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
    <title><?php echo h($invoiceNumber); ?> · Invoice</title>
    <style>
        body { font-family: Arial, Helvetica, sans-serif; margin: 0; padding: 24px; color: #222; }
        .invoice { max-width: 760px; margin: 0 auto; border: 1px solid #e0e0e0; border-radius: 8px; padding: 32px; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; gap: 12px; }
        .header h1 { margin: 0; font-size: 22px; letter-spacing: 0.5px; }
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
        .totals td.label { color: #6b7280; text-transform: uppercase; font-size: 12px; }
        .totals td.value { text-align: right; font-weight: bold; }
        @media print { body { padding: 0; } .invoice { border: none; border-radius: 0; } .print-btn { display: none; } }
    </style>
</head>
<body>
    <div class="invoice">
        <div class="header">
            <div>
                <h1>Invoice</h1>
                <div><?php echo h($firmName); ?></div>
            </div>
            <div style="display:flex; align-items:center; gap:8px;">
                <button class="print-btn" onclick="window.print()">Print</button>
                <div class="badge"><?php echo h($invoiceNumber); ?></div>
            </div>
        </div>

        <div class="section">
            <div class="section-title">Invoice Details</div>
            <div class="info-grid">
                <div><strong>Issue Date:</strong><br><?php echo h($issueDate); ?></div>
                <div><strong>Due Date:</strong><br><?php echo h($dueDate); ?></div>
                <div><strong>Status:</strong><br><?php echo h($status); ?></div>
                <div><strong>Case:</strong><br><?php echo h($caseTitle); ?></div>
            </div>
        </div>

        <div class="section">
            <div class="section-title">Client</div>
            <div class="info-grid">
                <div><strong>Name:</strong><br><?php echo h($clientName); ?></div>
                <div><strong>Email:</strong><br><?php echo h($clientEmail); ?></div>
                <div><strong>Phone:</strong><br><?php echo h($clientPhone); ?></div>
            </div>
        </div>

        <div class="section">
            <div class="section-title">Invoice Summary</div>
            <table>
                <thead>
                    <tr>
                        <th>Description</th>
                        <th>Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Professional Services · <?php echo h($caseTitle); ?></td>
                        <td><?php echo h($amountDisplay); ?></td>
                    </tr>
                </tbody>
            </table>
            <table class="totals">
                <tr>
                    <td class="label">Total Due</td>
                    <td class="value"><?php echo h($amountDisplay); ?></td>
                </tr>
            </table>
        </div>

        <div class="section">
            <div class="section-title">Notes</div>
            <div><?php echo nl2br(h($notes)); ?></div>
        </div>

        <div class="section">
            <div class="section-title">Issued By</div>
            <div><?php echo h($firmName); ?> · <?php echo h($firmAddress); ?><br>Email: <?php echo h($firmEmail); ?> · Phone: <?php echo h($firmPhone); ?></div>
        </div>
    </div>
</body>
</html>
<?php
$invoiceHtml = ob_get_clean();
$fileName = 'invoice-' . preg_replace('/[^A-Za-z0-9_\-]/', '', $invoiceNumber) . '.html';
header('Content-Type: text/html; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $fileName . '"');
echo $invoiceHtml;
exit;

