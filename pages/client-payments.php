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
    $totalInvoiced = 0;
    $totalPaid = 0;
    $totalOutstanding = 0;
}

$invoiceCount = count($invoices);
$paymentCount = count($payments);
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

$messageHtml = $message ? '<div class="alert alert-' . htmlspecialchars($messageType) . ' alert-dismissible fade show" role="alert">' . htmlspecialchars($message) . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>' : '';

// Build invoices table rows
$invoicesRows = '';
if (empty($invoices)) {
    $invoicesRows = '<tr><td colspan="6" class="border-0">
        <div class="text-center py-5 px-4">
            <div class="cp-empty-icon dashboard-stat-icon-wrap dashboard-stat-icon-wrap--primary mx-auto d-flex align-items-center justify-content-center">' . $iconInvoiceEmpty . '</div>
            <h5 class="font-weight-bolder mt-4 mb-2">No invoices yet</h5>
            <p class="text-sm text-muted mb-0 mx-auto" style="max-width: 22rem;">When your firm issues an invoice for a matter, it will show here with amounts, due dates, and payment status.</p>
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
                    <div class="cp-row-icon dashboard-stat-icon-wrap dashboard-stat-icon-wrap--primary flex-shrink-0">' . $iconInvoiceRow . '</div>
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
            <td class="align-middle text-center pe-4">
                ' . $statusBadge . '
            </td>
        </tr>';
    }
}

// Build payments table rows
$paymentsRows = '';
if (empty($payments)) {
    $paymentsRows = '<tr><td colspan="5" class="border-0">
        <div class="text-center py-5 px-4">
            <div class="cp-empty-icon dashboard-stat-icon-wrap dashboard-stat-icon-wrap--success mx-auto d-flex align-items-center justify-content-center">' . $iconPaymentEmpty . '</div>
            <h5 class="font-weight-bolder mt-4 mb-2">No payments recorded</h5>
            <p class="text-sm text-muted mb-0 mx-auto" style="max-width: 22rem;">Posted payments from your firm will appear here with date, method, and reference.</p>
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
                    <div class="cp-row-icon dashboard-stat-icon-wrap dashboard-stat-icon-wrap--success flex-shrink-0">' . $iconPaymentRow . '</div>
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
            <td class="pe-4">
                <p class="text-xs font-weight-bold mb-0 text-truncate" style="max-width: 7rem;" title="' . htmlspecialchars($ref) . '">' . $refDisp . '</p>
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
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-icons.css" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-svg.css" rel="stylesheet" />
    <script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
    <link id="pagestyle" href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
<link href="../assets/css/app-font-montserrat.css?v=4" rel="stylesheet" />
    <?php include __DIR__ . '/../inc/client-portal-head.php'; ?>
    <link href="../assets/css/dashboard-enhancements.css?v=5" rel="stylesheet" />
    <style>
        .client-payments-page { --cp-radius: 1.15rem; }
        .client-payments-page .cp-hero {
            border-radius: var(--cp-radius);
            background: #fff;
            box-shadow: 0 0.25rem 1rem rgba(52, 71, 103, 0.08);
            border: 1px solid rgba(0, 0, 0, 0.06);
        }
        .client-payments-page .cp-hero .cp-hero-kicker {
            letter-spacing: 0.12em;
            color: #5e72e4;
            opacity: 1;
        }
        .client-payments-page .cp-hero .cp-hero-title {
            color: #344767;
        }
        .client-payments-page .cp-hero .cp-hero-text,
        .client-payments-page .cp-hero .cp-hero-meta {
            color: #67748e;
        }
        .client-payments-page .cp-hero-pill {
            background: #f8f9fe;
            border-radius: 0.75rem;
            padding: 0.55rem 0.9rem;
            border: 1px solid rgba(94, 114, 228, 0.15);
            min-width: 5.5rem;
            text-align: center;
        }
        .client-payments-page .cp-hero-pill .cp-hero-pill-label {
            color: #67748e;
        }
        .client-payments-page .cp-hero-pill .cp-hero-pill-value {
            color: #344767;
        }
        .client-payments-page .cp-panel {
            border-radius: var(--cp-radius);
            border: 1px solid rgba(0, 0, 0, 0.05);
            box-shadow: 0 0.25rem 1.1rem rgba(52, 71, 103, 0.07);
            overflow: hidden;
        }
        .client-payments-page .cp-panel .card-header {
            background: transparent;
            border-bottom: 1px solid rgba(0, 0, 0, 0.06);
            padding: 1.1rem 1.25rem 0.9rem;
        }
        .client-payments-page .cp-panel .card-header h5 {
            font-weight: 800;
            letter-spacing: -0.02em;
            margin: 0;
        }
        .client-payments-page .cp-panel .table thead th {
            font-size: 0.65rem;
            letter-spacing: 0.06em;
            padding-top: 0.85rem;
            padding-bottom: 0.85rem;
            background: rgba(248, 249, 250, 0.95);
            border-bottom: 1px solid rgba(0, 0, 0, 0.06);
        }
        .client-payments-page .cp-invoice-row td,
        .client-payments-page .cp-payment-row td {
            border-bottom: 1px solid rgba(0, 0, 0, 0.04);
            vertical-align: middle;
            padding-top: 1rem;
            padding-bottom: 1rem;
        }
        @media (max-width: 767.98px) {
            .client-payments-page .cp-invoice-row td,
            .client-payments-page .cp-payment-row td {
                padding-top: 1.15rem;
                padding-bottom: 1.15rem;
            }
            .client-payments-page .ca-status-pill {
                font-size: 0.78rem;
                padding: 0.45em 1em;
            }
        }
        .client-payments-page .cp-invoice-row:hover td,
        .client-payments-page .cp-payment-row:hover td {
            background: rgba(94, 114, 228, 0.04);
        }
        .client-payments-page .ca-status-pill {
            display: inline-block;
            font-size: 0.72rem;
            font-weight: 700;
            padding: 0.35em 0.85em;
            border-radius: 999px;
            line-height: 1.2;
            white-space: nowrap;
        }
        .client-payments-page .ca-status-pill--pending {
            background: rgba(251, 140, 0, 0.14);
            color: #c45c00;
        }
        .client-payments-page .ca-status-pill--scheduled {
            background: rgba(94, 114, 228, 0.14);
            color: #5e72e4;
        }
        .client-payments-page .ca-status-pill--done {
            background: rgba(103, 116, 142, 0.12);
            color: #67748e;
        }
        .client-payments-page .ca-status-pill--declined {
            background: rgba(245, 54, 92, 0.12);
            color: #d6336c;
        }
        .client-payments-page .ca-status-pill--muted {
            background: rgba(103, 116, 142, 0.1);
            color: #8392ab;
        }
        .client-payments-page .cp-row-icon.dashboard-stat-icon-wrap {
            width: 2.5rem;
            height: 2.5rem;
            min-width: 2.5rem;
            border-radius: 50%;
            box-shadow: none;
        }
        .client-payments-page .cp-empty-icon.dashboard-stat-icon-wrap {
            width: 3.25rem;
            height: 3.25rem;
            min-width: 3.25rem;
            border-radius: 50%;
            box-shadow: none;
        }
        .client-payments-page .cp-invoice-row .icon-shape,
        .client-payments-page .cp-payment-row .icon-shape,
        .client-payments-page .cp-empty-icon.icon-shape {
            display: none !important;
        }
        .client-payments-page .min-width-0 { min-width: 0; }
    </style>
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-client-portal client-payments-page">
    <div class="min-height-300 bg-legalpro-client position-absolute w-100"></div>
    <?php include __DIR__ . '/../inc/client-menunav.php'; ?>
    <main class="main-content position-relative border-radius-lg">
        {CLIENT_NAVBAR}
        <div class="container-fluid py-4">
            {MESSAGE}

            <div class="row mb-4">
                <div class="col-12">
                    <div class="card cp-hero mb-0">
                        <div class="card-body p-4 d-flex flex-column flex-lg-row align-items-lg-center justify-content-lg-between gap-4">
                            <div>
                                <p class="cp-hero-kicker text-xs text-uppercase font-weight-bold mb-1">Billing</p>
                                <h4 class="cp-hero-title font-weight-bolder mb-1">Your financial snapshot</h4>
                                <p class="cp-hero-text text-sm mb-1" style="max-width: 32rem;">Review issued invoices, what you have paid, and any balance still due. Contact your firm if you need a payment plan or receipt.</p>
                                <p class="cp-hero-meta text-xs mb-0">{INVOICE_COUNT} invoices on file · {PAYMENT_COUNT} payments recorded</p>
                            </div>
                            <div class="d-flex flex-wrap gap-3 justify-content-lg-end">
                                <div class="cp-hero-pill">
                                    <p class="cp-hero-pill-label text-xs mb-0">Invoiced</p>
                                    <p class="cp-hero-pill-value font-weight-bolder mb-0" style="font-size: 1.1rem;">{TOTAL_INVOICED}</p>
                                </div>
                                <div class="cp-hero-pill">
                                    <p class="cp-hero-pill-label text-xs mb-0">Paid</p>
                                    <p class="cp-hero-pill-value font-weight-bolder mb-0" style="font-size: 1.1rem;">{TOTAL_PAID}</p>
                                </div>
                                <div class="cp-hero-pill">
                                    <p class="cp-hero-pill-label text-xs mb-0">Outstanding</p>
                                    <p class="cp-hero-pill-value font-weight-bolder mb-0" style="font-size: 1.1rem;">{TOTAL_OUTSTANDING}</p>
                                </div>
                                <div class="cp-hero-pill">
                                    <p class="cp-hero-pill-label text-xs mb-0">Overdue</p>
                                    <p class="cp-hero-pill-value font-weight-bolder mb-0" style="font-size: 1.35rem;">{OVERDUE_COUNT}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-lg-7 mb-4">
                    <div class="card cp-panel mb-4 mb-lg-0">
                        <div class="card-header d-flex flex-wrap justify-content-between align-items-start gap-2">
                            <div>
                                <h5 class="text-dark">Invoices</h5>
                                <p class="text-sm text-muted mb-0">Issued for your matters, newest first.</p>
                            </div>
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <span class="text-xs text-muted" id="cpInvoiceCount">{INVOICE_COUNT} invoices total</span>
                                <a href="client-cases.php" class="btn btn-sm btn-outline-primary mb-0 cdoc-touch-btn">My cases</a>
                            </div>
                        </div>
                        <div class="card-body px-0 pt-0 pb-0">
                            <div class="table-responsive">
                                <table class="table align-items-center mb-0">
                                    <thead>
                                        <tr>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-4">Invoice</th>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Issued</th>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Due</th>
                                            <th class="text-end text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Amount</th>
                                            <th class="text-end text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Paid</th>
                                            <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 pe-4">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {INVOICES_ROWS}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-5 mb-4">
                    <div class="card cp-panel">
                        <div class="card-header d-flex flex-wrap justify-content-between align-items-start gap-2">
                            <div>
                                <h5 class="text-dark">Payment history</h5>
                                <p class="text-sm text-muted mb-0">Recorded receipts and transfers.</p>
                            </div>
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <span class="text-xs text-muted" id="cpPaymentCount">{PAYMENT_COUNT} payments total</span>
                                <a href="client-dashboard.php" class="btn btn-sm btn-outline-primary mb-0 cdoc-touch-btn">Dashboard</a>
                            </div>
                        </div>
                        <div class="card-body px-0 pt-0 pb-0">
                            <div class="table-responsive">
                                <table class="table align-items-center mb-0">
                                    <thead>
                                        <tr>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-4">Case / ref</th>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Date</th>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Amount</th>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Method</th>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 pe-4">Reference</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {PAYMENTS_ROWS}
                                    </tbody>
                                </table>
                            </div>
                        </div>
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
$html = str_replace('{PAYMENTS_ROWS}', $paymentsRows, $html);
$html = str_replace('{INVOICE_COUNT}', (string) $invoiceCount, $html);
$html = str_replace('{PAYMENT_COUNT}', (string) $paymentCount, $html);
$html = str_replace('{OVERDUE_COUNT}', (string) $overdueInvoiceCount, $html);

require_once __DIR__ . '/../inc/client-sidebar.php';
$html = inject_client_sidebar($html);

echo $html;
?>
