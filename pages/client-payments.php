<?php
session_start();
require_once __DIR__ . '/../inc/db.php';

// Check if client is logged in
if (!isset($_SESSION['client_id'])) {
    header('Location: login.php');
    exit;
}

$client_id = $_SESSION['client_id'];
$client_name = $_SESSION['client_name'];

require_once __DIR__ . '/../lib/case_quotations.php';
ensure_case_quotation_schema($pdo);

function clientInvoiceStatusMeta(float $balanceDue, ?string $dueDate): array
{
    if ($balanceDue <= 0) {
        return ['label' => 'Paid', 'pill' => 'ca-status-pill--done'];
    }
    if ($dueDate && strtotime($dueDate) < time()) {
        return ['label' => 'Overdue', 'pill' => 'ca-status-pill--declined'];
    }

    return ['label' => 'Outstanding', 'pill' => 'ca-status-pill--pending'];
}

function clientInvoiceStatusBadge(array $meta): string
{
    $pill = isset($meta['pill']) ? $meta['pill'] : 'ca-status-pill--muted';

    return '<span class="ca-status-pill ' . htmlspecialchars($pill) . '">' . htmlspecialchars($meta['label']) . '</span>';
}

$message = '';
$messageType = '';

if (isset($_GET['msg']) && (string) $_GET['msg'] !== '') {
    $message = (string) $_GET['msg'];
    if (isset($_GET['err'])) {
        $messageType = 'danger';
    } elseif (isset($_GET['ok'])) {
        $messageType = 'success';
    } else {
        $messageType = 'info';
    }
}

try {
    // Get all invoices for this client
    $stmt = $pdo->prepare("
        SELECT
            i.*,
            c.title as case_title,
            COALESCE(SUM(p.amount), 0) as paid_amount,
            (i.amount - COALESCE(SUM(p.amount), 0)) as balance_due
        FROM invoices i
        LEFT JOIN cases c ON c.id = i.case_id
        LEFT JOIN payments p ON p.invoice_id = i.id
        WHERE i.client_id = ?
        GROUP BY i.id
        ORDER BY i.issue_date DESC
    ");
    $stmt->execute([$client_id]);
    $invoices = $stmt->fetchAll();

    // Get all payments for this client
    $stmt = $pdo->prepare("
        SELECT
            p.*,
            c.title as case_title,
            i.invoice_number,
            i.amount as invoice_amount
        FROM payments p
        LEFT JOIN cases c ON c.id = p.case_id
        LEFT JOIN invoices i ON i.id = p.invoice_id
        WHERE p.client_id = ?
        ORDER BY p.payment_date DESC
    ");
    $stmt->execute([$client_id]);
    $payments = $stmt->fetchAll();

    $quotations = fetch_client_quotations($pdo, (int) $client_id);

    // Calculate totals
    $totalInvoiced = 0;
    $totalPaid = 0;
    $totalOutstanding = 0;

    foreach ($invoices as $invoice) {
        $totalInvoiced += $invoice['amount'];
        $totalOutstanding += $invoice['balance_due'];
    }

    foreach ($payments as $payment) {
        $totalPaid += $payment['amount'];
    }

} catch (PDOException $e) {
    $message = 'Error loading payment information: ' . htmlspecialchars($e->getMessage());
    $messageType = 'danger';
    $invoices = [];
    $payments = [];
    $quotations = [];
    $totalInvoiced = 0;
    $totalPaid = 0;
    $totalOutstanding = 0;
}

$invoiceCount = count($invoices);
$paymentCount = count($payments);
$quotationCount = count($quotations);
$overdueInvoiceCount = 0;
foreach ($invoices as $_inv) {
    if ((float) ($_inv['balance_due'] ?? 0) > 0 && !empty($_inv['due_date']) && strtotime($_inv['due_date']) < time()) {
        $overdueInvoiceCount++;
    }
}

require_once __DIR__ . '/../inc/legalpro-icons.php';
$iconInvoiceRow = legalpro_icon('file-text');
$iconInvoiceEmpty = legalpro_icon('file-text');
$iconPaymentRow = legalpro_icon('credit-card');
$iconPaymentEmpty = legalpro_icon('credit-card');
$iconQuotationRow = legalpro_icon('clipboard-list');
$iconQuotationEmpty = legalpro_icon('clipboard-list');

$messageHtml = $message ? '<div class="alert alert-' . htmlspecialchars($messageType) . ' alert-dismissible fade show" role="alert">' . htmlspecialchars($message) . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>' : '';

// Build invoices table rows
$invoicesRows = '';
if (empty($invoices)) {
    $invoicesRows = '<tr><td colspan="7" class="border-0">
        <div class="cp-empty">
            <div class="cp-empty-icon cp-empty-icon--primary">' . $iconInvoiceEmpty . '</div>
            <h5>No invoices yet</h5>
            <p>When your firm issues an invoice for a matter, it will show here with amounts, due dates, and payment status.</p>
        </div>
    </td></tr>';
} else {
    foreach ($invoices as $invoice) {
        $statusMeta = clientInvoiceStatusMeta(
            (float) ($invoice['balance_due'] ?? 0),
            !empty($invoice['due_date']) ? $invoice['due_date'] : null
        );
        $statusBadge = clientInvoiceStatusBadge($statusMeta);

        $caseTitle = $invoice['case_title'] ?: '—';

        $invoiceHay = strtolower(implode(' ', [
            $invoice['invoice_number'] ?? '',
            $caseTitle,
            $statusMeta['label'],
            $invoice['issue_date'] ?? '',
            $invoice['due_date'] ?? '',
            (string) $invoice['amount'],
        ]));

        $invoicesRows .= '<tr class="cp-invoice-row cp-search-row" data-search="' . htmlspecialchars($invoiceHay, ENT_QUOTES, 'UTF-8') . '">
            <td class="ps-4">
                <div class="d-flex align-items-center gap-3 py-1">
                    <div class="cp-row-icon flex-shrink-0">' . $iconInvoiceRow . '</div>
                    <div class="min-width-0">
                        <h6 class="mb-0 text-sm font-weight-bold text-truncate" style="max-width: 12rem;">' . htmlspecialchars($invoice['invoice_number']) . '</h6>
                        <p class="text-xs text-muted mb-0 text-truncate" style="max-width: 14rem;" title="' . htmlspecialchars($caseTitle) . '">' . htmlspecialchars($caseTitle) . '</p>
                    </div>
                </div>
            </td>
            <td>
                <p class="text-xs font-weight-bold mb-0">' . date('M j, Y', strtotime($invoice['issue_date'])) . '</p>
            </td>
            <td>
                <p class="text-xs font-weight-bold mb-0">' . date('M j, Y', strtotime($invoice['due_date'])) . '</p>
            </td>
            <td class="text-end">
                <span class="text-xs font-weight-bold">' . formatCurrency($invoice['amount']) . '</span>
            </td>
            <td class="text-end">
                <span class="text-xs font-weight-bold">' . formatCurrency($invoice['paid_amount']) . '</span>
            </td>
            <td class="align-middle text-center">
                ' . $statusBadge . '
            </td>
            <td class="align-middle text-center pe-4">
                <a href="invoice-download.php?id=' . (int) $invoice['id'] . '" class="btn-cp-link btn-cp-download" target="_blank" rel="noopener">PDF</a>
            </td>
        </tr>';
    }
}

// Build payments table rows
$paymentsRows = '';
if (empty($payments)) {
    $paymentsRows = '<tr><td colspan="6" class="border-0">
        <div class="cp-empty">
            <div class="cp-empty-icon cp-empty-icon--success">' . $iconPaymentEmpty . '</div>
            <h5>No payments recorded</h5>
            <p>Posted payments from your firm will appear here with date, method, and reference.</p>
        </div>
    </td></tr>';
} else {
    foreach ($payments as $payment) {
        $ref = $payment['reference'] ?: 'N/A';
        $refDisp = strlen($ref) > 24 ? htmlspecialchars(substr($ref, 0, 24)) . '…' : htmlspecialchars($ref);
        $caseTitle = $payment['case_title'] ?: '—';

        $paymentHay = strtolower(implode(' ', [
            $caseTitle,
            $payment['invoice_number'] ?? '',
            $ref,
            $payment['method'] ?? '',
            $payment['payment_date'] ?? '',
            (string) $payment['amount'],
        ]));

        $paymentsRows .= '<tr class="cp-payment-row cp-search-row" data-search="' . htmlspecialchars($paymentHay, ENT_QUOTES, 'UTF-8') . '">
            <td class="ps-4">
                <div class="d-flex align-items-center gap-3 py-1">
                    <div class="cp-row-icon cp-row-icon--success flex-shrink-0">' . $iconPaymentRow . '</div>
                    <div class="min-width-0">
                        <h6 class="mb-0 text-sm font-weight-bold text-truncate" style="max-width: 11rem;" title="' . htmlspecialchars($caseTitle) . '">' . htmlspecialchars($caseTitle) . '</h6>
                        <p class="text-xs text-muted mb-0">' . htmlspecialchars($payment['invoice_number'] ?: 'No invoice #') . '</p>
                    </div>
                </div>
            </td>
            <td>
                <p class="text-xs font-weight-bold mb-0">' . date('M j, Y', strtotime($payment['payment_date'])) . '</p>
            </td>
            <td>
                <span class="text-xs font-weight-bold">' . formatCurrency($payment['amount']) . '</span>
            </td>
            <td>
                <span class="text-xs font-weight-bold">' . htmlspecialchars(ucfirst($payment['method'])) . '</span>
            </td>
            <td>
                <p class="text-xs font-weight-bold mb-0 text-truncate" style="max-width: 7rem;" title="' . htmlspecialchars($ref) . '">' . $refDisp . '</p>
            </td>
            <td class="align-middle text-center pe-4">
                <a href="payment-receipt.php?id=' . (int) $payment['id'] . '" class="btn-cp-link btn-cp-download" target="_blank" rel="noopener">PDF</a>
            </td>
        </tr>';
    }
}

// Build quotations table rows
$quotationsRows = '';
if (empty($quotations)) {
    $quotationsRows = '<tr><td colspan="6" class="border-0">
        <div class="cp-empty">
            <div class="cp-empty-icon cp-empty-icon--primary">' . $iconQuotationEmpty . '</div>
            <h5>No quotations yet</h5>
            <p>When your firm sends a fee quotation for a matter, it will appear here for review.</p>
        </div>
    </td></tr>';
} else {
    foreach ($quotations as $quotation) {
        $quoteNumber = !empty($quotation['quotation_number'])
            ? $quotation['quotation_number']
            : 'QUO-' . str_pad((string) $quotation['id'], 4, '0', STR_PAD_LEFT);
        $quoteTitle = trim((string) ($quotation['title'] ?? '')) ?: 'Quotation';
        $caseTitle = $quotation['case_title'] ?: '—';
        $issuedDate = !empty($quotation['created_at'])
            ? date('M j, Y', strtotime($quotation['created_at']))
            : '—';
        $validUntilDate = !empty($quotation['valid_until'])
            ? date('M j, Y', strtotime($quotation['valid_until']))
            : '—';

        $quoteHay = strtolower(implode(' ', [
            $quoteNumber,
            $quoteTitle,
            $caseTitle,
            $issuedDate,
            $validUntilDate,
            (string) $quotation['total_amount'],
        ]));

        $quoteId = (int) $quotation['id'];
        $actionsCell = '<div class="cp-quotation-actions">'
            . '<a href="client-quotation-view.php?id=' . $quoteId . '&view=1" class="btn-cp-link" target="_blank" rel="noopener">View</a>'
            . '<a href="client-quotation-view.php?id=' . $quoteId . '" class="btn-cp-link btn-cp-download" target="_blank" rel="noopener">PDF</a>'
            . '</div>';

        $quotationsRows .= '<tr class="cp-quotation-row cp-search-row" data-quotation-id="' . $quoteId . '" data-search="' . htmlspecialchars($quoteHay, ENT_QUOTES, 'UTF-8') . '">
            <td class="ps-4">
                <div class="d-flex align-items-center gap-3 py-1">
                    <div class="cp-row-icon flex-shrink-0">' . $iconQuotationRow . '</div>
                    <div class="min-width-0">
                        <h6 class="mb-0 text-sm font-weight-bold text-truncate" style="max-width: 12rem;">' . htmlspecialchars($quoteNumber) . '</h6>
                        <p class="text-xs text-muted mb-0 text-truncate" style="max-width: 14rem;" title="' . htmlspecialchars($quoteTitle) . '">' . htmlspecialchars($quoteTitle) . '</p>
                    </div>
                </div>
            </td>
            <td>
                <p class="text-xs font-weight-bold mb-0 text-truncate" style="max-width: 11rem;" title="' . htmlspecialchars($caseTitle) . '">' . htmlspecialchars($caseTitle) . '</p>
            </td>
            <td>
                <p class="text-xs font-weight-bold mb-0">' . htmlspecialchars($issuedDate) . '</p>
            </td>
            <td>
                <p class="text-xs font-weight-bold mb-0">' . htmlspecialchars($validUntilDate) . '</p>
            </td>
            <td class="text-end">
                <span class="text-xs font-weight-bold">' . formatCurrency($quotation['total_amount']) . '</span>
            </td>
            <td class="align-middle text-center pe-4">
                ' . $actionsCell . '
            </td>
        </tr>';
    }
}

require_once __DIR__ . '/../inc/client-portal-navbar.php';
require_once __DIR__ . '/../lib/client-portal-page-ui.php';

$heroHtml = client_portal_render_hero([
    'kicker' => 'Billing',
    'title' => 'Your financial snapshot',
    'subtitle' => 'Review issued invoices, quotations, what you have paid, and any balance still due. Contact your firm if you need a payment plan or receipt.',
    'meta' => $invoiceCount . ' invoices · ' . $quotationCount . ' quotations · ' . $paymentCount . ' payments recorded',
    'show_date' => true,
    'aria_label' => 'Payments overview',
    'stats' => [
        ['num' => formatCurrency($totalInvoiced), 'lbl' => 'Invoiced'],
        ['num' => formatCurrency($totalPaid), 'lbl' => 'Paid'],
        ['num' => formatCurrency($totalOutstanding), 'lbl' => 'Outstanding'],
        ['num' => (string) $overdueInvoiceCount, 'lbl' => 'Overdue'],
    ],
    'actions' => [
        ['url' => 'client-dashboard.php', 'label' => 'Dashboard', 'primary' => true, 'icon' => 'layout-dashboard'],
        ['url' => 'client-cases.php', 'label' => 'My cases', 'icon' => 'briefcase'],
    ],
]);

$clientPageNavbar = legalpro_render_client_page_navbar(
    'Payments & invoices',
    'Payments'
);

$paymentsSearchQuery = legalpro_client_page_search_query();
$paymentsSearchHtml = '<section class="cp-pay-search-wrap cp-pay-search-wrap--featured" aria-label="Search billing">'
    . '<label class="cp-pay-search-label" for="cpPaymentsSearchInput">Search invoices &amp; payments</label>'
    . '<div class="cp-pay-search-field">'
    . '<span class="cp-pay-search-icon" aria-hidden="true">'
    . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25">'
    . '<circle cx="11" cy="11" r="7"></circle><path d="M20 20l-3-3"></path>'
    . '</svg></span>'
    . '<input type="search" id="cpPaymentsSearchInput" class="cp-pay-search-input"'
    . ' placeholder="Search by invoice, quotation, payment, case, or reference…"'
    . ' value="' . htmlspecialchars($paymentsSearchQuery, ENT_QUOTES, 'UTF-8') . '"'
    . ' autocomplete="off" maxlength="200" aria-label="Search invoices, quotations, and payments">'
    . '</div></section>';

$html = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="apple-touch-icon" sizes="76x76" href="../assets/img/apple-icon.png">
    <link rel="icon" type="image/png" href="../assets/img/favicon.png">
    <title>LegalPro - My Payments</title>
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-icons.css" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-svg.css" rel="stylesheet" />
    <script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
    <link id="pagestyle" href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
<link href="../assets/css/app-font-montserrat.css?v=4" rel="stylesheet" />
    <?php include __DIR__ . '/../inc/client-portal-head.php'; ?>
    <link href="../assets/css/client-portal-pages.css?v=6" rel="stylesheet" />
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-client-portal client-payments-page{PORTAL_THEME_BODY_CLASS}">
    <div class="min-height-300 bg-legalpro-client position-absolute w-100"></div>
    <?php include __DIR__ . '/../inc/client-menunav.php'; ?>
    <main class="main-content position-relative border-radius-lg">
        {CLIENT_NAVBAR}
        <div class="container-fluid py-4">
            <div class="cp-page">
            {MESSAGE}

            {HERO}

            {PAYMENTS_SEARCH}

            <div class="cp-layout">
                <div class="cp-panel">
                    <div class="cp-panel-hdr">
                        <div>
                            <h5>Invoices</h5>
                            <p>Issued for your matters, newest first.</p>
                        </div>
                        <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap">
                            <span class="cp-count" id="cpInvoiceCount">{INVOICE_COUNT} total</span>
                            <a href="client-cases.php" class="btn-cp-link">My cases</a>
                        </div>
                    </div>
                    <div class="cp-portal-table-wrap" data-portal-table-wrap data-portal-row=".cp-invoice-row" data-portal-per-page="10" data-portal-show-page-global="cpInvoiceShowPage">
                    <div class="table-responsive">
                        <table class="cp-table">
                            <thead>
                                <tr>
                                    <th>Invoice</th>
                                    <th>Issued</th>
                                    <th>Due</th>
                                    <th style="text-align:right">Amount</th>
                                    <th style="text-align:right">Paid</th>
                                    <th style="text-align:center">Status</th>
                                    <th style="text-align:center;padding-right:1.5rem">Download</th>
                                </tr>
                            </thead>
                            <tbody>
                                {INVOICES_ROWS}
                            </tbody>
                        </table>
                    </div>
                    <nav class="cp-portal-pagination" data-portal-pagination aria-label="Invoices pagination" hidden>
                        <p class="cp-portal-pagination__info" data-portal-range></p>
                        <div class="cp-portal-pagination__controls" data-portal-pages></div>
                    </nav>
                    </div>
                </div>

                <div class="cp-panel">
                    <div class="cp-panel-hdr">
                        <div>
                            <h5>Payment history</h5>
                            <p>Recorded receipts and transfers.</p>
                        </div>
                        <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap">
                            <span class="cp-count" id="cpPaymentCount">{PAYMENT_COUNT} total</span>
                            <a href="client-dashboard.php" class="btn-cp-link">Dashboard</a>
                        </div>
                    </div>
                    <div class="cp-portal-table-wrap" data-portal-table-wrap data-portal-row=".cp-payment-row" data-portal-per-page="10" data-portal-show-page-global="cpPaymentShowPage">
                    <div class="table-responsive">
                        <table class="cp-table">
                            <thead>
                                <tr>
                                    <th>Case / ref</th>
                                    <th>Date</th>
                                    <th>Amount</th>
                                    <th>Method</th>
                                    <th>Reference</th>
                                    <th style="text-align:center;padding-right:1.5rem">Receipt</th>
                                </tr>
                            </thead>
                            <tbody>
                                {PAYMENTS_ROWS}
                            </tbody>
                        </table>
                    </div>
                    <nav class="cp-portal-pagination" data-portal-pagination aria-label="Payments pagination" hidden>
                        <p class="cp-portal-pagination__info" data-portal-range></p>
                        <div class="cp-portal-pagination__controls" data-portal-pages></div>
                    </nav>
                    </div>
                </div>
            </div>

            <div class="cp-panel" id="quotations">
                <div class="cp-panel-hdr">
                    <div>
                        <h5>Quotations</h5>
                        <p>Fee quotes from your firm.</p>
                    </div>
                    <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap">
                        <span class="cp-count" id="cpQuotationCount">{QUOTATION_COUNT} total</span>
                    </div>
                </div>
                <div class="cp-portal-table-wrap" data-portal-table-wrap data-portal-row=".cp-quotation-row" data-portal-per-page="10" data-portal-show-page-global="cpQuotationShowPage">
                <div class="table-responsive">
                    <table class="cp-table">
                        <thead>
                            <tr>
                                <th>Quotation</th>
                                <th>Case</th>
                                <th>Issued</th>
                                <th>Valid until</th>
                                <th style="text-align:right">Amount</th>
                                <th style="text-align:center;padding-right:1.5rem">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {QUOTATIONS_ROWS}
                        </tbody>
                    </table>
                </div>
                <nav class="cp-portal-pagination" data-portal-pagination aria-label="Quotations pagination" hidden>
                    <p class="cp-portal-pagination__info" data-portal-range></p>
                    <div class="cp-portal-pagination__controls" data-portal-pages></div>
                </nav>
                </div>
            </div>
            </div>
        </div>
    </main>

    <script src="../assets/js/core/popper.min.js"></script>
    <script src="../assets/js/core/bootstrap.min.js"></script>
    <script src="../assets/js/plugins/perfect-scrollbar.min.js"></script>
    <script src="../assets/js/plugins/smooth-scrollbar.min.js"></script>
    <script src="../assets/js/argon-dashboard.min.js?v=2.1.0"></script>
    <script>
    (function () {
        function getPaymentsSearchQuery() {
            var input = document.getElementById('cpPaymentsSearchInput');
            if (input) {
                return input.value.trim().toLowerCase();
            }
            return (new URLSearchParams(window.location.search).get('q') || '').trim().toLowerCase();
        }

        function applyPaymentsPageSearch() {
            var q = getPaymentsSearchQuery();
            function filterRows(selector, countId, singular, plural) {
                var rows = document.querySelectorAll(selector);
                var visible = 0;
                rows.forEach(function (row) {
                    if (!q) {
                        row.style.display = '';
                        visible++;
                        return;
                    }
                    var hay = (row.getAttribute('data-search') || row.textContent || '').toLowerCase();
                    var show = hay.indexOf(q) !== -1;
                    row.style.display = show ? '' : 'none';
                    if (show) visible++;
                });
                var countEl = document.getElementById(countId);
                if (countEl) {
                    countEl.textContent = visible + ' ' + (visible === 1 ? singular : plural) + ' total';
                }
            }
            filterRows('.cp-invoice-row.cp-search-row', 'cpInvoiceCount', 'invoice', 'invoices');
            filterRows('.cp-quotation-row.cp-search-row', 'cpQuotationCount', 'quotation', 'quotations');
            filterRows('.cp-payment-row.cp-search-row', 'cpPaymentCount', 'payment', 'payments');
            if (typeof legalproResetClientTablePaginations === 'function') {
                legalproResetClientTablePaginations();
            }

            var params = new URLSearchParams(window.location.search);
            if (window.location.hash === '#quotations') {
                var quotationsPanel = document.getElementById('quotations');
                if (quotationsPanel) {
                    quotationsPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            }

            var quoteId = (params.get('quote') || '').trim();
            if (quoteId) {
                var quoteRow = document.querySelector('.cp-quotation-row[data-quotation-id="' + quoteId + '"]');
                if (quoteRow) {
                    quoteRow.classList.add('cp-quotation-row--highlight');
                    quoteRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            }
        }

        document.addEventListener('DOMContentLoaded', function () {
            applyPaymentsPageSearch();
            var input = document.getElementById('cpPaymentsSearchInput');
            if (input) {
                input.addEventListener('input', applyPaymentsPageSearch);
            }
        });
    })();
    </script>
</body>
</html>
HTML;

// Replace placeholders
$html = str_replace('{MESSAGE}', $messageHtml, $html);
$html = str_replace('{HERO}', $heroHtml, $html);
$html = str_replace('{PAYMENTS_SEARCH}', $paymentsSearchHtml, $html);
$html = str_replace('{CLIENT_NAVBAR}', $clientPageNavbar, $html);
$html = str_replace('{CLIENT_NAME}', htmlspecialchars($client_name), $html);
$html = str_replace('{TOTAL_INVOICED}', formatCurrency($totalInvoiced), $html);
$html = str_replace('{TOTAL_PAID}', formatCurrency($totalPaid), $html);
$html = str_replace('{TOTAL_OUTSTANDING}', formatCurrency($totalOutstanding), $html);
$html = str_replace('{INVOICES_ROWS}', $invoicesRows, $html);
$html = str_replace('{QUOTATIONS_ROWS}', $quotationsRows, $html);
$html = str_replace('{PAYMENTS_ROWS}', $paymentsRows, $html);
$html = str_replace('{INVOICE_COUNT}', (string) $invoiceCount, $html);
$html = str_replace('{QUOTATION_COUNT}', (string) $quotationCount, $html);
$html = str_replace('{PAYMENT_COUNT}', (string) $paymentCount, $html);
$html = str_replace('{OVERDUE_COUNT}', (string) $overdueInvoiceCount, $html);

require_once __DIR__ . '/../inc/client-sidebar.php';
$html = inject_client_sidebar($html);

echo $html;
?>