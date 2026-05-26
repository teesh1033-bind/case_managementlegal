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

$messageHtml = $message ? '<div class="alert alert-' . htmlspecialchars($messageType) . ' alert-dismissible fade show" role="alert">' . htmlspecialchars($message) . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>' : '';

// Build invoices table rows
$invoicesRows = '';
if (empty($invoices)) {
    $invoicesRows = '<tr><td colspan="6" class="border-0">
        <div class="text-center py-5 px-4">
            <div class="cp-empty-icon icon icon-shape icon-lg bg-gradient-light shadow-sm mx-auto border-radius-lg d-flex align-items-center justify-content-center">
                <i class="ni ni-single-copy-04 text-primary text-lg opacity-10" aria-hidden="true"></i>
            </div>
            <h5 class="font-weight-bolder mt-4 mb-2">No invoices yet</h5>
            <p class="text-sm text-muted mb-0 mx-auto" style="max-width: 22rem;">When your firm issues an invoice for a matter, it will show here with amounts, due dates, and payment status.</p>
        </div>
    </td></tr>';
} else {
    foreach ($invoices as $invoice) {
        $statusBadge = '';
        if ($invoice['balance_due'] <= 0) {
            $statusBadge = '<span class="badge badge-sm bg-gradient-success">Paid</span>';
        } elseif (strtotime($invoice['due_date']) < time()) {
            $statusBadge = '<span class="badge badge-sm bg-gradient-danger">Overdue</span>';
        } else {
            $statusBadge = '<span class="badge badge-sm bg-gradient-warning">Pending</span>';
        }

        $caseTitle = $invoice['case_title'] ?: '—';

        $invoicesRows .= '<tr class="cp-invoice-row">
            <td class="ps-4">
                <div class="d-flex align-items-center gap-3 py-1">
                    <div class="cp-row-icon icon icon-shape icon-sm bg-gradient-primary shadow text-center border-radius-md flex-shrink-0">
                        <i class="ni ni-single-copy-04 text-white text-xs opacity-10" aria-hidden="true"></i>
                    </div>
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
                <span class="text-xs font-weight-bold">Rs ' . number_format($invoice['amount'], 2) . '</span>
            </td>
            <td class="text-end">
                <span class="text-xs font-weight-bold">Rs ' . number_format($invoice['paid_amount'], 2) . '</span>
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
            <div class="cp-empty-icon icon icon-shape icon-lg bg-gradient-light shadow-sm mx-auto border-radius-lg d-flex align-items-center justify-content-center">
                <i class="ni ni-credit-card text-success text-lg opacity-10" aria-hidden="true"></i>
            </div>
            <h5 class="font-weight-bolder mt-4 mb-2">No payments recorded</h5>
            <p class="text-sm text-muted mb-0 mx-auto" style="max-width: 22rem;">Posted payments from your firm will appear here with date, method, and reference.</p>
        </div>
    </td></tr>';
} else {
    foreach ($payments as $payment) {
        $ref = $payment['reference'] ?: 'N/A';
        $refDisp = strlen($ref) > 24 ? htmlspecialchars(substr($ref, 0, 24)) . '…' : htmlspecialchars($ref);
        $caseTitle = $payment['case_title'] ?: '—';

        $paymentsRows .= '<tr class="cp-payment-row">
            <td class="ps-4">
                <div class="d-flex align-items-center gap-3 py-1">
                    <div class="cp-row-icon icon icon-shape icon-sm bg-gradient-success shadow text-center border-radius-md flex-shrink-0">
                        <i class="ni ni-money-coins text-white text-xs opacity-10" aria-hidden="true"></i>
                    </div>
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
                <span class="text-xs font-weight-bold">Rs ' . number_format($payment['amount'], 2) . '</span>
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
<<<<<<< HEAD
<link href="../assets/css/app-font-montserrat.css?v=1" rel="stylesheet" />
    <style>
        .client-payments-page { --cp-radius: 1.15rem; }
        .client-payments-page .navbar-main {
            backdrop-filter: blur(8px);
            background: rgba(255, 255, 255, 0.9) !important;
            border: 1px solid rgba(255, 255, 255, 0.6) !important;
            box-shadow: 0 0.35rem 1.25rem rgba(52, 71, 103, 0.08) !important;
            margin-top: 20px;
        }
        .client-payments-page .breadcrumb .text-dark { color: #344767 !important; }
=======
<link href="../assets/css/app-font-montserrat.css?v=4" rel="stylesheet" />
    <style>
        .client-payments-page { --cp-radius: 1.15rem; }
>>>>>>> f827a933538474659c1629f07f5a4af06a073209
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
        }
        .client-payments-page .cp-invoice-row:hover td,
        .client-payments-page .cp-payment-row:hover td {
            background: rgba(94, 114, 228, 0.04);
        }
        .client-payments-page .cp-row-icon {
            width: 2.35rem;
            height: 2.35rem;
        }
        .client-payments-page .min-width-0 { min-width: 0; }
        .client-payments-page .cp-empty-icon {
            width: 4rem;
            height: 4rem;
        }
    </style>
</head>
<<<<<<< HEAD
<body class="g-sidenav-show bg-gray-100 client-payments-page">
    <div class="min-height-300 bg-primary position-absolute w-100"></div>
=======
<body class="g-sidenav-show bg-gray-100 legalpro-lawyer-portal client-payments-page">
    <div class="min-height-300 bg-legalpro-lawyer position-absolute w-100"></div>
>>>>>>> f827a933538474659c1629f07f5a4af06a073209
    <aside class="sidenav bg-white navbar navbar-vertical navbar-expand-xs border-0 border-radius-xl my-3 fixed-start ms-4" id="sidenav-main">
        <div class="sidenav-header">
            <i class="fas fa-times p-3 cursor-pointer text-secondary opacity-5 position-absolute end-0 top-0 d-none d-xl-none" aria-hidden="true" id="iconSidenav"></i>
            <a class="navbar-brand m-0" href="client-dashboard.php">
            <img src="../assets/img/logo-ct-dark.png" width="26px" height="26px" class="navbar-brand-img h-100" alt="LegalPro logo">
            <span class="ms-1 font-weight-bold">LegalPro</span>
            </a>
        </div>
        <hr class="horizontal dark mt-0">
        <div class="collapse navbar-collapse w-auto" id="sidenav-collapse-main">
            <ul class="navbar-nav">
                <li class="nav-item">
                    <a class="nav-link" href="client-dashboard.php">
                        <div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center">
                            <i class="ni ni-tv-2 text-primary text-sm opacity-10"></i>
                        </div>
                        <span class="nav-link-text ms-1">Dashboard</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="client-cases.php">
                        <div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center">
                            <i class="ni ni-folder-17 text-warning text-sm opacity-10"></i>
                        </div>
                        <span class="nav-link-text ms-1">My Cases</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="client-appointments.php">
                        <div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center">
                            <i class="ni ni-calendar-grid-58 text-info text-sm opacity-10"></i>
                        </div>
                        <span class="nav-link-text ms-1">Appointments</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="client-court-tracking.php">
                        <div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center">
                            <i class="ni ni-collection text-success text-sm opacity-10"></i>
                        </div>
                        <span class="nav-link-text ms-1">Court Tracking</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link active" href="client-payments.php">
                        <div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center">
                            <i class="ni ni-credit-card text-info text-sm opacity-10"></i>
                        </div>
                        <span class="nav-link-text ms-1">Payments</span>
                    </a>
                </li>
            </ul>
        </div>
        <div class="sidenav-footer position-absolute bottom-0 w-100">
            <div class="text-center">
                <p class="text-xs text-muted mb-1">Logged in as</p>
                <p class="text-sm font-weight-bold mb-2">{CLIENT_NAME}</p>
                <a href="client-logout.php" class="btn btn-sm btn-outline-danger">Logout</a>
            </div>
        </div>
    </aside>
    <main class="main-content position-relative border-radius-lg">
        <nav class="navbar navbar-main navbar-expand-lg px-0 mx-4 shadow-none border-radius-xl" id="navbarBlur" navbar-scroll="true">
            <div class="container-fluid py-1 px-3">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb bg-transparent mb-0 pb-0 pt-1 px-0 me-sm-6 me-5">
<<<<<<< HEAD
                        <li class="breadcrumb-item text-sm"><a class="opacity-6 text-dark" href="client-dashboard.php">Client</a></li>
                        <li class="breadcrumb-item text-sm text-dark active" aria-current="page">Payments</li>
                    </ol>
                    <h5 class="font-weight-bolder mb-0 text-dark">Payments & invoices</h5>
=======
                        <li class="breadcrumb-item text-sm"><a class="opacity-6 text-white" href="client-dashboard.php">Client</a></li>
                        <li class="breadcrumb-item text-sm text-white active" aria-current="page">Payments</li>
                    </ol>
                    <h5 class="font-weight-bolder mb-0 text-white">Payments & invoices</h5>
>>>>>>> f827a933538474659c1629f07f5a4af06a073209
                </nav>
                <div class="collapse navbar-collapse mt-sm-0 mt-2 me-md-0 me-sm-4" id="navbar">
                    <form class="ms-md-auto pe-md-3 d-flex align-items-center legalpro-navbar-search" method="get" action="search.php" role="search">
                        <div class="input-group">
                            <span class="input-group-text text-body"><i class="fas fa-search" aria-hidden="true"></i></span>
                            <input type="search" name="q" class="form-control" placeholder="Search invoices & payments…" value="" autocomplete="off" maxlength="200" aria-label="Search">
                        </div>
                    </form>
                    <ul class="navbar-nav justify-content-end">
                        <li class="nav-item d-flex align-items-center">
<<<<<<< HEAD
                            <a href="javascript:;" class="nav-link text-body font-weight-bold px-0">
=======
                            <a href="javascript:;" class="nav-link text-white font-weight-bold px-0">
>>>>>>> f827a933538474659c1629f07f5a4af06a073209
                                <i class="fa fa-user me-sm-1"></i>
                                <span class="d-sm-inline d-none">Welcome, {CLIENT_NAME}</span>
                            </a>
                        </li>
                        <li class="nav-item d-xl-none ps-3 d-flex align-items-center">
                            <a href="javascript:;" class="nav-link text-body p-0" id="iconNavbarSidenav">
                                <div class="sidenav-toggler-inner">
                                    <i class="sidenav-toggler-line"></i>
                                    <i class="sidenav-toggler-line"></i>
                                    <i class="sidenav-toggler-line"></i>
                                </div>
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        </nav>
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
                                    <p class="cp-hero-pill-value font-weight-bolder mb-0" style="font-size: 1.1rem;">Rs {TOTAL_INVOICED}</p>
                                </div>
                                <div class="cp-hero-pill">
                                    <p class="cp-hero-pill-label text-xs mb-0">Paid</p>
                                    <p class="cp-hero-pill-value font-weight-bolder mb-0" style="font-size: 1.1rem;">Rs {TOTAL_PAID}</p>
                                </div>
                                <div class="cp-hero-pill">
                                    <p class="cp-hero-pill-label text-xs mb-0">Outstanding</p>
                                    <p class="cp-hero-pill-value font-weight-bolder mb-0" style="font-size: 1.1rem;">Rs {TOTAL_OUTSTANDING}</p>
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
                            <a href="client-cases.php" class="btn btn-sm btn-outline-primary mb-0">My cases</a>
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
                            <a href="client-dashboard.php" class="btn btn-sm btn-outline-primary mb-0">Dashboard</a>
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
$html = str_replace('{CLIENT_NAME}', htmlspecialchars($client_name), $html);
$html = str_replace('{TOTAL_INVOICED}', number_format($totalInvoiced, 2), $html);
$html = str_replace('{TOTAL_PAID}', number_format($totalPaid, 2), $html);
$html = str_replace('{TOTAL_OUTSTANDING}', number_format($totalOutstanding, 2), $html);
$html = str_replace('{INVOICES_ROWS}', $invoicesRows, $html);
$html = str_replace('{PAYMENTS_ROWS}', $paymentsRows, $html);
$html = str_replace('{INVOICE_COUNT}', (string) $invoiceCount, $html);
$html = str_replace('{PAYMENT_COUNT}', (string) $paymentCount, $html);
$html = str_replace('{OVERDUE_COUNT}', (string) $overdueInvoiceCount, $html);

echo $html;
?>
