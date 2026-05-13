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

$messageHtml = $message ? '<div class="alert alert-' . htmlspecialchars($messageType) . ' alert-dismissible fade show" role="alert">' . htmlspecialchars($message) . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>' : '';

// Build invoices table rows
$invoicesRows = '';
if (empty($invoices)) {
    $invoicesRows = '<tr><td colspan="6" class="text-center py-4"><p class="text-muted mb-0">No invoices found.</p></td></tr>';
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

        $invoicesRows .= '<tr>
            <td>
                <div class="d-flex px-2 py-1">
                    <div class="d-flex flex-column justify-content-center">
                        <h6 class="mb-0 text-sm">' . htmlspecialchars($invoice['invoice_number']) . '</h6>
                        <p class="text-xs text-secondary mb-0">' . htmlspecialchars($invoice['case_title']) . '</p>
                    </div>
                </div>
            </td>
            <td>
                <p class="text-xs font-weight-bold mb-0">' . date('M d, Y', strtotime($invoice['issue_date'])) . '</p>
            </td>
            <td>
                <p class="text-xs font-weight-bold mb-0">' . date('M d, Y', strtotime($invoice['due_date'])) . '</p>
            </td>
            <td class="text-end">
                <span class="text-xs font-weight-bold">Rs ' . number_format($invoice['amount'], 2) . '</span>
            </td>
            <td class="text-end">
                <span class="text-xs font-weight-bold">Rs ' . number_format($invoice['paid_amount'], 2) . '</span>
            </td>
            <td class="align-middle text-center">
                ' . $statusBadge . '
            </td>
        </tr>';
    }
}

// Build payments table rows
$paymentsRows = '';
if (empty($payments)) {
    $paymentsRows = '<tr><td colspan="5" class="text-center py-4"><p class="text-muted mb-0">No payments found.</p></td></tr>';
} else {
    foreach ($payments as $payment) {
        $paymentsRows .= '<tr>
            <td>
                <div class="d-flex px-2 py-1">
                    <div class="d-flex flex-column justify-content-center">
                        <h6 class="mb-0 text-sm">' . htmlspecialchars($payment['case_title']) . '</h6>
                        <p class="text-xs text-secondary mb-0">' . htmlspecialchars($payment['invoice_number'] ?: 'N/A') . '</p>
                    </div>
                </div>
            </td>
            <td>
                <p class="text-xs font-weight-bold mb-0">' . date('M d, Y', strtotime($payment['payment_date'])) . '</p>
            </td>
            <td>
                <span class="text-xs font-weight-bold">Rs ' . number_format($payment['amount'], 2) . '</span>
            </td>
            <td>
                <span class="text-xs font-weight-bold">' . htmlspecialchars(ucfirst($payment['method'])) . '</span>
            </td>
            <td>
                <p class="text-xs font-weight-bold mb-0">' . htmlspecialchars(substr($payment['reference'] ?: 'N/A', 0, 20)) . '</p>
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
    <title>LexMate - My Payments</title>
    <link href="https://fonts.googleapis.com/css?family=Open+Sans:300,400,600,700" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-icons.css" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-svg.css" rel="stylesheet" />
    <script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
    <link id="pagestyle" href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
</head>
<body class="g-sidenav-show bg-gray-100">
    <div class="min-height-300 bg-primary position-absolute w-100"></div>
    <aside class="sidenav bg-white navbar navbar-vertical navbar-expand-xs border-0 border-radius-xl my-3 fixed-start ms-4" id="sidenav-main">
        <div class="sidenav-header">
            <i class="fas fa-times p-3 cursor-pointer text-secondary opacity-5 position-absolute end-0 top-0 d-none d-xl-none" aria-hidden="true" id="iconSidenav"></i>
            <a class="navbar-brand m-0" href="#">
            <img src="../assets/img/logo-ct-dark.png" width="26px" height="26px" class="navbar-brand-img h-100" alt="LexMate logo">
            <span class="ms-1 font-weight-bold">LexMate</span>
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
                <a href="client-logout.php" class="btn btn-sm btn-outline-danger w-100">Logout</a>
            </div>
        </div>
    </aside>
    <main class="main-content position-relative border-radius-lg">
        <!-- Navbar -->
        <nav class="navbar navbar-main navbar-expand-lg px-0 mx-4 shadow-none border-radius-xl" id="navbarBlur" navbar-scroll="true">
            <div class="container-fluid py-1 px-3">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb bg-transparent mb-0 pb-0 pt-1 px-0 me-sm-6 me-5">
                        <li class="breadcrumb-item text-sm"><a class="opacity-5 text-dark" href="javascript:;">Pages</a></li>
                        <li class="breadcrumb-item text-sm text-dark active" aria-current="page">Payments</li>
                    </ol>
                    <h6 class="font-weight-bolder mb-0">Payment History & Invoices</h6>
                </nav>
                <div class="collapse navbar-collapse mt-sm-0 mt-2 me-md-0 me-sm-4" id="navbar">
                    <ul class="navbar-nav justify-content-end">
                        <li class="nav-item d-flex align-items-center">
                            <a href="javascript:;" class="nav-link text-body font-weight-bold px-0">
                                <i class="fa fa-user me-sm-1"></i>
                                <span class="d-sm-inline d-none">Welcome, {CLIENT_NAME}</span>
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        </nav>
        <!-- End Navbar -->
        <div class="container-fluid py-4">
            {MESSAGE}

            <!-- Payment Summary Cards -->
            <div class="row mb-4">
                <div class="col-xl-4 col-sm-6 mb-xl-0 mb-4">
                    <div class="card">
                        <div class="card-body p-3">
                            <div class="row">
                                <div class="col-8">
                                    <div class="numbers">
                                        <p class="text-sm mb-0 text-capitalize font-weight-bold">Total Invoiced</p>
                                        <h5 class="font-weight-bolder mb-0">Rs {TOTAL_INVOICED}</h5>
                                    </div>
                                </div>
                                <div class="col-4 text-end">
                                    <div class="icon icon-shape bg-gradient-primary shadow text-center border-radius-md">
                                        <i class="ni ni-money-coins text-lg opacity-10" aria-hidden="true"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-4 col-sm-6 mb-xl-0 mb-4">
                    <div class="card">
                        <div class="card-body p-3">
                            <div class="row">
                                <div class="col-8">
                                    <div class="numbers">
                                        <p class="text-sm mb-0 text-capitalize font-weight-bold">Total Paid</p>
                                        <h5 class="font-weight-bolder mb-0">Rs {TOTAL_PAID}</h5>
                                    </div>
                                </div>
                                <div class="col-4 text-end">
                                    <div class="icon icon-shape bg-gradient-success shadow text-center border-radius-md">
                                        <i class="ni ni-check-bold text-lg opacity-10" aria-hidden="true"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-4 col-sm-6">
                    <div class="card">
                        <div class="card-body p-3">
                            <div class="row">
                                <div class="col-8">
                                    <div class="numbers">
                                        <p class="text-sm mb-0 text-capitalize font-weight-bold">Outstanding Balance</p>
                                        <h5 class="font-weight-bolder mb-0">Rs {TOTAL_OUTSTANDING}</h5>
                                    </div>
                                </div>
                                <div class="col-4 text-end">
                                    <div class="icon icon-shape bg-gradient-warning shadow text-center border-radius-md">
                                        <i class="ni ni-credit-card text-lg opacity-10" aria-hidden="true"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <!-- Invoices -->
                <div class="col-lg-7 mb-4">
                    <div class="card">
                        <div class="card-header pb-0">
                            <h6>Invoices</h6>
                            <p class="text-sm text-muted">All invoices for your cases</p>
                        </div>
                        <div class="card-body px-0 pt-0 pb-2">
                            <div class="table-responsive p-0">
                                <table class="table align-items-center mb-0">
                                    <thead>
                                        <tr>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Invoice</th>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Issue Date</th>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Due Date</th>
                                            <th class="text-end text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Amount</th>
                                            <th class="text-end text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Paid</th>
                                            <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Status</th>
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

                <!-- Payment History -->
                <div class="col-lg-5 mb-4">
                    <div class="card">
                        <div class="card-header pb-0">
                            <h6>Payment History</h6>
                            <p class="text-sm text-muted">Your payment transactions</p>
                        </div>
                        <div class="card-body px-0 pt-0 pb-2">
                            <div class="table-responsive p-0">
                                <table class="table align-items-center mb-0">
                                    <thead>
                                        <tr>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Case</th>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Date</th>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Amount</th>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Method</th>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Ref</th>
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

echo $html;
?>
