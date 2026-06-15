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

legalpro_require_financial_document_access(
    isset($invoice['client_id']) ? (int) $invoice['client_id'] : null
);

$invoiceNumber = $invoice['invoice_number'] ?: ('INV-' . str_pad($invoiceId, 4, '0', STR_PAD_LEFT));
$clientName = $invoice['client_name'] ?: 'Client';
$clientEmail = $invoice['client_email'] ?: 'N/A';
$clientPhone = $invoice['client_phone'] ?: 'N/A';
$caseTitle = $invoice['case_title'] ?: 'N/A';
$amount = $invoice['amount'] ?: 0;
$currencyConfig = getCurrencyConfig();
$currencyLabel = isset($currencyConfig['code']) ? strtoupper($currencyConfig['code']) : getDefaultCurrencyCode();
$pdfAmount = $currencyLabel . ' ' . number_format((float)$amount, 2);
$amountDisplay = formatCurrency($amount);
$issueDate = $invoice['issue_date'] ? date('F d, Y', strtotime($invoice['issue_date'])) : 'N/A';
$dueDate = $invoice['due_date'] ? date('F d, Y', strtotime($invoice['due_date'])) : 'N/A';
$status = ucfirst($invoice['status']);
$notes = trim($invoice['notes']) ?: 'Thank you for your business.';
$today = date('F d, Y');

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
    <title><?php echo h($invoiceNumber); ?> · Invoice</title>
    <?php legalpro_render_finance_document_head('Invoice'); ?>
</head>
<body class="fin-doc-page">
    <div class="fin-doc">
        <div class="fin-doc-top">
            <div>
                <h1>Invoice</h1>
                <div class="fin-doc-firm"><?php echo h($firmName); ?></div>
            </div>
            <div class="fin-doc-top-actions">
                <button type="button" class="fin-doc-print" onclick="window.print()">Print</button>
                <div class="fin-doc-badge"><?php echo h($invoiceNumber); ?></div>
            </div>
        </div>

        <div class="fin-doc-body">
            <div class="fin-doc-section">
                <div class="fin-doc-section-title">Invoice details</div>
                <div class="fin-doc-grid">
                    <div><strong>Issue date</strong><?php echo h($issueDate); ?></div>
                    <div><strong>Due date</strong><?php echo h($dueDate); ?></div>
                    <div><strong>Status</strong><?php echo h($status); ?></div>
                    <div><strong>Case</strong><?php echo h($caseTitle); ?></div>
                </div>
            </div>

            <div class="fin-doc-section">
                <div class="fin-doc-section-title">Client</div>
                <div class="fin-doc-grid">
                    <div><strong>Name</strong><?php echo h($clientName); ?></div>
                    <div><strong>Email</strong><?php echo h($clientEmail); ?></div>
                    <div><strong>Phone</strong><?php echo h($clientPhone); ?></div>
                </div>
            </div>

            <div class="fin-doc-section">
                <div class="fin-doc-section-title">Summary</div>
                <table class="fin-doc-table">
                    <thead>
                        <tr>
                            <th>Description</th>
                            <th class="text-end">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Professional services · <?php echo h($caseTitle); ?></td>
                            <td class="text-end"><?php echo h($amountDisplay); ?></td>
                        </tr>
                    </tbody>
                </table>
                <table class="fin-doc-totals">
                    <tr class="grand">
                        <td class="label">Total due</td>
                        <td class="value"><?php echo h($amountDisplay); ?></td>
                    </tr>
                </table>
            </div>

            <div class="fin-doc-section">
                <div class="fin-doc-section-title">Notes</div>
                <div class="fin-doc-notes"><?php echo nl2br(h($notes)); ?></div>
            </div>

            <div class="fin-doc-section">
                <div class="fin-doc-section-title">Issued by</div>
                <div class="fin-doc-footer">
                    <?php echo h($firmName); ?> · <?php echo h($firmAddress); ?><br>
                    Email: <?php echo h($firmEmail); ?> · Phone: <?php echo h($firmPhone); ?>
                </div>
            </div>
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

