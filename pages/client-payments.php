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
require_once __DIR__ . '/../lib/client-locale.php';
require_once __DIR__ . '/../lib/client-portal-i18n.php';
ensure_case_quotation_schema($pdo);

function clientInvoiceStatusMeta(float $balanceDue, ?string $dueDate): array
{
    if ($balanceDue <= 0) {
        return ['label' => client_t('status.paid'), 'pill' => 'ca-status-pill--done'];
    }
    if ($dueDate && strtotime($dueDate) < time()) {
        return ['label' => client_t('status.overdue'), 'pill' => 'ca-status-pill--declined'];
    }

    return ['label' => client_t('status.outstanding'), 'pill' => 'ca-status-pill--pending'];
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
    $message = client_t('payments.error_load') . ' ' . htmlspecialchars($e->getMessage());
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

$messageHtml = $message ? '<div class="alert alert-' . htmlspecialchars($messageType) . ' alert-dismissible fade show" role="alert">' . htmlspecialchars($message) . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="' . htmlspecialchars(client_t('common.close')) . '"></button></div>' : '';

// Build invoices table rows
$invoicesRows = '';
if (empty($invoices)) {
    $invoicesRows = '<tr><td colspan="7" class="border-0">
        <div class="cp-empty">
            <div class="cp-empty-icon cp-empty-icon--primary">' . $iconInvoiceEmpty . '</div>
            <h5>' . htmlspecialchars(client_t('payments.no_invoices')) . '</h5>
            <p>' . htmlspecialchars(client_t('payments.no_invoices_sub')) . '</p>
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
            <h5>' . htmlspecialchars(client_t('payments.no_payments')) . '</h5>
            <p>' . htmlspecialchars(client_t('payments.no_payments_sub')) . '</p>
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
                        <p class="text-xs text-muted mb-0">' . htmlspecialchars($payment['invoice_number'] ?: client_t('payments.no_invoice_num')) . '</p>
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
            <h5>' . htmlspecialchars(client_t('payments.no_quotations')) . '</h5>
            <p>' . htmlspecialchars(client_t('payments.no_quotations_sub')) . '</p>
        </div>
    </td></tr>';
} else {
    foreach ($quotations as $quotation) {
        $quoteNumber = !empty($quotation['quotation_number'])
            ? $quotation['quotation_number']
            : 'QUO-' . str_pad((string) $quotation['id'], 4, '0', STR_PAD_LEFT);
        $quoteTitle = trim((string) ($quotation['title'] ?? '')) ?: client_t('payments.quotation_fallback');
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
            . '<a href="client-quotation-view.php?id=' . $quoteId . '&view=1" class="btn-cp-link" target="_blank" rel="noopener">' . htmlspecialchars(client_t('common.view')) . '</a>'
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
    'kicker' => client_t('payments.kicker'),
    'title' => client_t('payments.title'),
    'subtitle' => client_t('payments.subtitle'),
    'meta' => client_t('payments.meta', [
        'invoices' => (string) $invoiceCount,
        'quotations' => (string) $quotationCount,
        'payments' => (string) $paymentCount,
    ]),
    'show_date' => true,
    'aria_label' => client_t('payments.aria'),
    'stats' => [
        ['num' => formatCurrency($totalInvoiced), 'lbl' => client_t('payments.invoiced')],
        ['num' => formatCurrency($totalPaid), 'lbl' => client_t('payments.paid')],
        ['num' => formatCurrency($totalOutstanding), 'lbl' => client_t('payments.outstanding')],
        ['num' => (string) $overdueInvoiceCount, 'lbl' => client_t('payments.overdue')],
    ],
    'actions' => [
        ['url' => 'client-dashboard.php', 'label' => client_t('nav.dashboard'), 'primary' => true, 'icon' => 'layout-dashboard'],
        ['url' => 'client-cases.php', 'label' => client_t('nav.my_cases'), 'icon' => 'briefcase'],
    ],
]);

$clientPageNavbar = legalpro_render_client_page_navbar(
    client_t('payments.navbar'),
    client_t('payments.navbar_short')
);

$paymentsSearchQuery = legalpro_client_page_search_query();
$paymentsSearchHtml = '<section class="cp-pay-search-wrap cp-pay-search-wrap--featured" aria-label="' . htmlspecialchars(client_t('payments.search_aria')) . '">'
    . '<label class="cp-pay-search-label" for="cpPaymentsSearchInput">' . htmlspecialchars(client_t('payments.search_label')) . '</label>'
    . '<div class="cp-pay-search-field">'
    . '<span class="cp-pay-search-icon" aria-hidden="true">'
    . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25">'
    . '<circle cx="11" cy="11" r="7"></circle><path d="M20 20l-3-3"></path>'
    . '</svg></span>'
    . '<input type="search" id="cpPaymentsSearchInput" class="cp-pay-search-input"'
    . ' placeholder="' . htmlspecialchars(client_t('payments.search_placeholder')) . '"'
    . ' value="' . htmlspecialchars($paymentsSearchQuery, ENT_QUOTES, 'UTF-8') . '"'
    . ' autocomplete="off" maxlength="200" aria-label="' . htmlspecialchars(client_t('payments.search_label')) . '">'
    . '</div></section>';

$html = <<<'HTML'
<!DOCTYPE html>
<html lang="{HTML_LANG}">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="apple-touch-icon" sizes="76x76" href="../assets/img/apple-icon.png">
    <link rel="icon" type="image/png" href="../assets/img/favicon.png">
    <title>LegalPro - {PAGE_TITLE}</title>
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
                            <h5>{LBL_INVOICES}</h5>
                            <p>{LBL_INVOICES_SUB}</p>
                        </div>
                        <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap">
                            <span class="cp-count" id="cpInvoiceCount">{INVOICE_COUNT} {LBL_TOTAL}</span>
                            <a href="client-cases.php" class="btn-cp-link">{LBL_MY_CASES}</a>
                        </div>
                    </div>
                    <div class="cp-portal-table-wrap" data-portal-table-wrap data-portal-row=".cp-invoice-row" data-portal-per-page="10" data-portal-show-page-global="cpInvoiceShowPage">
                    <div class="table-responsive">
                        <table class="cp-table">
                            <thead>
                                <tr>
                                    <th>{COL_INVOICE}</th>
                                    <th>{COL_ISSUED}</th>
                                    <th>{COL_DUE}</th>
                                    <th style="text-align:right">{COL_AMOUNT}</th>
                                    <th style="text-align:right">{COL_PAID}</th>
                                    <th style="text-align:center">{COL_STATUS}</th>
                                    <th style="text-align:center;padding-right:1.5rem">{COL_DOWNLOAD}</th>
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
                            <h5>{LBL_PAYMENTS_HISTORY}</h5>
                            <p>{LBL_PAYMENTS_HISTORY_SUB}</p>
                        </div>
                        <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap">
                            <span class="cp-count" id="cpPaymentCount">{PAYMENT_COUNT} {LBL_TOTAL}</span>
                            <a href="client-dashboard.php" class="btn-cp-link">{LBL_DASHBOARD}</a>
                        </div>
                    </div>
                    <div class="cp-portal-table-wrap" data-portal-table-wrap data-portal-row=".cp-payment-row" data-portal-per-page="10" data-portal-show-page-global="cpPaymentShowPage">
                    <div class="table-responsive">
                        <table class="cp-table">
                            <thead>
                                <tr>
                                    <th>{COL_CASE_REF}</th>
                                    <th>{COL_DATE}</th>
                                    <th>{COL_AMOUNT}</th>
                                    <th>{COL_METHOD}</th>
                                    <th>{COL_REFERENCE}</th>
                                    <th style="text-align:center;padding-right:1.5rem">{COL_RECEIPT}</th>
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
                        <h5>{LBL_QUOTATIONS}</h5>
                        <p>{LBL_QUOTATIONS_SUB}</p>
                    </div>
                    <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap">
                        <span class="cp-count" id="cpQuotationCount">{QUOTATION_COUNT} {LBL_TOTAL}</span>
                    </div>
                </div>
                <div class="cp-portal-table-wrap" data-portal-table-wrap data-portal-row=".cp-quotation-row" data-portal-per-page="10" data-portal-show-page-global="cpQuotationShowPage">
                <div class="table-responsive">
                    <table class="cp-table">
                        <thead>
                            <tr>
                                <th>{COL_QUOTATION}</th>
                                <th>{COL_CASE}</th>
                                <th>{COL_ISSUED}</th>
                                <th>{COL_VALID_UNTIL}</th>
                                <th style="text-align:right">{COL_AMOUNT}</th>
                                <th style="text-align:center;padding-right:1.5rem">{COL_ACTIONS}</th>
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
    var cpPayLabels = {
        invoice: <?= json_encode(client_t('payments.invoice_singular'), JSON_UNESCAPED_UNICODE) ?>,
        invoices: <?= json_encode(client_t('payments.invoice_plural'), JSON_UNESCAPED_UNICODE) ?>,
        quotation: <?= json_encode(client_t('payments.quotation_singular'), JSON_UNESCAPED_UNICODE) ?>,
        quotations: <?= json_encode(client_t('payments.quotation_plural'), JSON_UNESCAPED_UNICODE) ?>,
        payment: <?= json_encode(client_t('payments.payment_singular'), JSON_UNESCAPED_UNICODE) ?>,
        payments: <?= json_encode(client_t('payments.payment_plural'), JSON_UNESCAPED_UNICODE) ?>,
        total: <?= json_encode(client_t('payments.word_total'), JSON_UNESCAPED_UNICODE) ?>
    };
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
                    countEl.textContent = visible + ' ' + (visible === 1 ? singular : plural) + ' ' + cpPayLabels.total;
                }
            }
            filterRows('.cp-invoice-row.cp-search-row', 'cpInvoiceCount', cpPayLabels.invoice, cpPayLabels.invoices);
            filterRows('.cp-quotation-row.cp-search-row', 'cpQuotationCount', cpPayLabels.quotation, cpPayLabels.quotations);
            filterRows('.cp-payment-row.cp-search-row', 'cpPaymentCount', cpPayLabels.payment, cpPayLabels.payments);
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
$html = str_replace('{HTML_LANG}', client_portal_html_lang(), $html);
$html = str_replace('{PAGE_TITLE}', htmlspecialchars(client_t('payments.page_title')), $html);
$html = str_replace('{LBL_INVOICES}', htmlspecialchars(client_t('payments.invoices')), $html);
$html = str_replace('{LBL_INVOICES_SUB}', htmlspecialchars(client_t('payments.invoices_sub')), $html);
$html = str_replace('{LBL_PAYMENTS_HISTORY}', htmlspecialchars(client_t('payments.payments_history')), $html);
$html = str_replace('{LBL_PAYMENTS_HISTORY_SUB}', htmlspecialchars(client_t('payments.payments_history_sub')), $html);
$html = str_replace('{LBL_QUOTATIONS}', htmlspecialchars(client_t('payments.quotations')), $html);
$html = str_replace('{LBL_QUOTATIONS_SUB}', htmlspecialchars(client_t('payments.quotations_sub')), $html);
$html = str_replace('{LBL_TOTAL}', htmlspecialchars(client_t('payments.word_total')), $html);
$html = str_replace('{LBL_MY_CASES}', htmlspecialchars(client_t('nav.my_cases')), $html);
$html = str_replace('{LBL_DASHBOARD}', htmlspecialchars(client_t('nav.dashboard')), $html);
$html = str_replace('{COL_INVOICE}', htmlspecialchars(client_t('payments.col_invoice')), $html);
$html = str_replace('{COL_ISSUED}', htmlspecialchars(client_t('payments.col_issued')), $html);
$html = str_replace('{COL_DUE}', htmlspecialchars(client_t('payments.col_due')), $html);
$html = str_replace('{COL_AMOUNT}', htmlspecialchars(client_t('payments.col_amount')), $html);
$html = str_replace('{COL_PAID}', htmlspecialchars(client_t('payments.col_paid')), $html);
$html = str_replace('{COL_STATUS}', htmlspecialchars(client_t('payments.col_status')), $html);
$html = str_replace('{COL_DOWNLOAD}', htmlspecialchars(client_t('payments.col_download')), $html);
$html = str_replace('{COL_CASE_REF}', htmlspecialchars(client_t('payments.col_case_ref')), $html);
$html = str_replace('{COL_DATE}', htmlspecialchars(client_t('payments.col_date')), $html);
$html = str_replace('{COL_METHOD}', htmlspecialchars(client_t('payments.col_method')), $html);
$html = str_replace('{COL_REFERENCE}', htmlspecialchars(client_t('payments.col_reference')), $html);
$html = str_replace('{COL_RECEIPT}', htmlspecialchars(client_t('payments.col_receipt')), $html);
$html = str_replace('{COL_QUOTATION}', htmlspecialchars(client_t('payments.col_quotation')), $html);
$html = str_replace('{COL_CASE}', htmlspecialchars(client_t('payments.col_case')), $html);
$html = str_replace('{COL_VALID_UNTIL}', htmlspecialchars(client_t('payments.col_valid_until')), $html);
$html = str_replace('{COL_ACTIONS}', htmlspecialchars(client_t('payments.col_actions')), $html);
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