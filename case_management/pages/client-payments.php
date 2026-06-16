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

function clientQuotationStatusBadge(array $meta): string
{
    return clientInvoiceStatusBadge($meta);
}

$message = '';
$messageType = '';

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
                <a href="invoice-download.php?id=' . (int) $invoice['id'] . '" class="btn-cp-link btn-cp-download" target="_blank" rel="noopener">Download PDF</a>
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
                <a href="payment-receipt.php?id=' . (int) $payment['id'] . '" class="btn-cp-link btn-cp-download" target="_blank" rel="noopener">Download PDF</a>
            </td>
        </tr>';
    }
}

// Build quotations table rows
$quotationsRows = '';
if (empty($quotations)) {
    $quotationsRows = '<tr><td colspan="7" class="border-0">
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
        $statusMeta = client_quotation_status_meta(
            (string) ($quotation['status'] ?? 'sent'),
            !empty($quotation['valid_until']) ? (string) $quotation['valid_until'] : null
        );
        $statusBadge = clientQuotationStatusBadge($statusMeta);
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
            $statusMeta['label'],
            $issuedDate,
            $validUntilDate,
            (string) $quotation['total_amount'],
        ]));

        $quotationsRows .= '<tr class="cp-quotation-row cp-search-row" data-search="' . htmlspecialchars($quoteHay, ENT_QUOTES, 'UTF-8') . '">
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
            <td class="align-middle text-center">
                ' . $statusBadge . '
            </td>
            <td class="align-middle text-center pe-4">
                <a href="client-quotation-view.php?id=' . (int) $quotation['id'] . '" class="btn-cp-link btn-cp-download" target="_blank" rel="noopener">Download PDF</a>
            </td>
        </tr>';
    }
}

require_once __DIR__ . '/../inc/client-portal-navbar.php';
$clientPageNavbar = legalpro_render_client_page_navbar(
    'Payments & invoices',
    'Payments',
    'Search invoices & payments…',
    legalpro_client_page_search_options('client-payments.php')
);

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
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body.client-payments-page {
            background: #f0f2f8;
            --cp-pay-primary: var(--legalpro-theme-primary, #5e72e4);
            --cp-pay-primary-dark: var(--legalpro-theme-primary-dark, #825ee4);
            --cp-pay-primary-soft: var(--lp-cases-accent-soft, rgba(94, 114, 228, 0.12));
            --cp-pay-gradient: var(--legalpro-theme-gradient, linear-gradient(135deg, #5e72e4, #825ee4));
            --cp-pay-r: 16px;
            --cp-pay-shadow: 0 2px 12px rgba(0,0,0,0.07);
        }

        .cp-hero-card {
            background: var(--cp-pay-gradient);
            border-radius: 20px;
            padding: 2rem 2.5rem;
            color: #fff;
            margin-bottom: 1.5rem;
            position: relative;
            overflow: hidden;
        }
        .cp-hero-card::before {
            content: '';
            position: absolute;
            top: -50px; right: -50px;
            width: 180px; height: 180px;
            border-radius: 50%;
            background: rgba(255,255,255,.08);
        }
        .cp-hero-kicker {
            font-size: 11px; font-weight: 600;
            letter-spacing: .12em; text-transform: uppercase;
            opacity: .75; margin-bottom: .35rem;
        }
        .cp-hero-title { font-size: 22px; font-weight: 800; margin-bottom: .3rem; }
        .cp-hero-sub { font-size: 13px; opacity: .8; margin-bottom: .5rem; max-width: 32rem; }
        .cp-hero-meta { font-size: 12px; opacity: .7; margin-bottom: 1.25rem; }
        .cp-hero-stats { display: flex; gap: .85rem; flex-wrap: wrap; position: relative; z-index: 1; }
        .cp-stat-pill {
            background: rgba(255,255,255,.15);
            border: 1px solid rgba(255,255,255,.2);
            border-radius: 12px;
            padding: .6rem 1.1rem;
            backdrop-filter: blur(10px);
            min-width: 5.5rem;
            text-align: center;
        }
        .cp-stat-pill .num { font-size: 18px; font-weight: 700; line-height: 1.1; }
        .cp-stat-pill .lbl { font-size: 11px; opacity: .75; margin-top: 2px; }

        .cp-layout {
            display: grid;
            grid-template-columns: 1.2fr 1fr;
            gap: 1.25rem;
            align-items: start;
        }
        @media (max-width: 991px) { .cp-layout { grid-template-columns: 1fr; } }

        .cp-panel {
            background: #fff;
            border-radius: var(--cp-pay-r);
            border: 1px solid #e9ecf3;
            box-shadow: var(--cp-pay-shadow);
            overflow: hidden;
            margin-bottom: 2rem;
        }
        .cp-panel-hdr {
            padding: 1.1rem 1.5rem;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: .75rem;
            flex-wrap: wrap;
        }
        .cp-panel-hdr h5 { font-size: 15px; font-weight: 700; color: #1e293b; margin: 0; }
        .cp-panel-hdr p { font-size: 12px; color: #94a3b8; margin: 2px 0 0; }
        .cp-count {
            background: var(--cp-pay-primary-soft);
            color: var(--cp-pay-primary);
            font-size: 11px; font-weight: 700;
            padding: .2rem .65rem; border-radius: 99px;
        }
        .cp-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .cp-table thead th {
            background: #f8fafc; color: #94a3b8;
            font-size: 10.5px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase;
            padding: .7rem 1rem; border-bottom: 1px solid #f1f5f9; white-space: nowrap;
        }
        .cp-table thead th:first-child { padding-left: 1.5rem; }
        .cp-table tbody tr { border-bottom: 1px solid #f8fafc; transition: background .1s; }
        .cp-table tbody tr:hover { background: rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.04); }
        .cp-table tbody td { padding: .85rem 1rem; vertical-align: middle; }
        .cp-table tbody td:first-child { padding-left: 1.5rem; }

        .cp-row-icon {
            width: 36px; height: 36px; border-radius: 10px;
            background: var(--cp-pay-primary-soft); color: var(--cp-pay-primary);
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .cp-row-icon--success {
            background: rgba(45, 206, 137, 0.12);
            color: #1e9e6a;
        }
        .cp-row-icon .lp-icon svg { stroke: currentColor; }

        .cp-empty {
            padding: 3.5rem 1.5rem; text-align: center;
        }
        .cp-empty-icon {
            width: 52px; height: 52px; border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 1rem;
        }
        .cp-empty-icon--primary { background: var(--cp-pay-primary-soft); color: var(--cp-pay-primary); }
        .cp-empty-icon--success { background: rgba(45, 206, 137, 0.12); color: #1e9e6a; }
        .cp-empty h5 { font-size: 15px; font-weight: 700; color: #1e293b; margin-bottom: .35rem; }
        .cp-empty p { font-size: 13px; color: #94a3b8; max-width: 22rem; margin: 0 auto; }

        .btn-cp-link {
            padding: .35rem .9rem; border-radius: 8px;
            border: 1.5px solid var(--cp-pay-primary); color: var(--cp-pay-primary);
            font-size: 12px; font-weight: 600; background: none;
            text-decoration: none; display: inline-block;
            transition: background .15s, color .15s;
        }
        .btn-cp-link:hover { background: var(--cp-pay-primary); color: #fff; }
        .btn-cp-download {
            padding: .3rem .65rem;
            font-size: 11px;
            white-space: nowrap;
        }

        .client-payments-page .ca-status-pill {
            font-size: .68rem;
            padding: 3px 10px;
        }
        .client-payments-page .ca-status-pill--scheduled {
            background: var(--cp-pay-primary-soft);
            color: var(--cp-pay-primary);
        }
        .client-payments-page .min-width-0 { min-width: 0; }

        @media (max-width: 640px) {
            .cp-hero-card { padding: 1.5rem; }
        }

        body.legalpro-dark-mode.client-payments-page {
            background: #0f172a;
        }
        body.legalpro-dark-mode.client-payments-page .cp-panel {
            background: #1e293b;
            border-color: rgba(255, 255, 255, 0.08);
        }
        body.legalpro-dark-mode.client-payments-page .cp-panel-hdr {
            border-bottom-color: rgba(255, 255, 255, 0.08);
        }
        body.legalpro-dark-mode.client-payments-page .cp-panel-hdr h5,
        body.legalpro-dark-mode.client-payments-page .cp-empty h5 {
            color: #f1f5f9;
        }
        body.legalpro-dark-mode.client-payments-page .cp-table thead th {
            background: #0f172a;
            color: #94a3b8;
            border-bottom-color: rgba(255, 255, 255, 0.08);
        }
        body.legalpro-dark-mode.client-payments-page .cp-table tbody tr:hover {
            background: rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.1);
        }
    </style>
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-client-portal client-payments-page{PORTAL_THEME_BODY_CLASS}">
    <div class="min-height-300 bg-legalpro-client position-absolute w-100"></div>
    <?php include __DIR__ . '/../inc/client-menunav.php'; ?>
    <main class="main-content position-relative border-radius-lg">
        {CLIENT_NAVBAR}
        <div class="container-fluid py-4">
            {MESSAGE}

            <div class="cp-hero-card">
                <p class="cp-hero-kicker">Billing</p>
                <h4 class="cp-hero-title">Your financial snapshot</h4>
                <p class="cp-hero-sub">Review issued invoices, quotations, what you have paid, and any balance still due. Contact your firm if you need a payment plan or receipt.</p>
                <p class="cp-hero-meta">{INVOICE_COUNT} invoices · {QUOTATION_COUNT} quotations · {PAYMENT_COUNT} payments recorded</p>
                <div class="cp-hero-stats">
                    <div class="cp-stat-pill">
                        <div class="num">{TOTAL_INVOICED}</div>
                        <div class="lbl">Invoiced</div>
                    </div>
                    <div class="cp-stat-pill">
                        <div class="num">{TOTAL_PAID}</div>
                        <div class="lbl">Paid</div>
                    </div>
                    <div class="cp-stat-pill">
                        <div class="num">{TOTAL_OUTSTANDING}</div>
                        <div class="lbl">Outstanding</div>
                    </div>
                    <div class="cp-stat-pill">
                        <div class="num">{OVERDUE_COUNT}</div>
                        <div class="lbl">Overdue</div>
                    </div>
                </div>
            </div>

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
                </div>
            </div>

            <div class="cp-panel" id="quotations">
                <div class="cp-panel-hdr">
                    <div>
                        <h5>Quotations</h5>
                        <p>Fee quotes sent by your firm for review.</p>
                    </div>
                    <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap">
                        <span class="cp-count" id="cpQuotationCount">{QUOTATION_COUNT} total</span>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="cp-table">
                        <thead>
                            <tr>
                                <th>Quotation</th>
                                <th>Case</th>
                                <th>Issued</th>
                                <th>Valid until</th>
                                <th style="text-align:right">Amount</th>
                                <th style="text-align:center">Status</th>
                                <th style="text-align:center;padding-right:1.5rem">View</th>
                            </tr>
                        </thead>
                        <tbody>
                            {QUOTATIONS_ROWS}
                        </tbody>
                    </table>
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
        function applyPaymentsPageSearch() {
            var params = new URLSearchParams(window.location.search);
            var q = (params.get('q') || '').trim().toLowerCase();
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
        }
        document.addEventListener('DOMContentLoaded', applyPaymentsPageSearch);
    })();
    </script>
</body>
</html>
HTML;

// Replace placeholders
$html = str_replace('{MESSAGE}', $messageHtml, $html);
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