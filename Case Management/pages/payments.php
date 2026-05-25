<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../lib/case_events.php';

$message = '';
$messageType = '';
if (isset($_GET['msg']) && isset($_GET['type'])) {
    $message = urldecode($_GET['msg']);
    $messageType = $_GET['type'];
}

$selectedCaseId = isset($_GET['case_id']) ? (int)$_GET['case_id'] : 0;

// Ensure cases table has all required columns used in finance screens
$caseMigrations = [
    "ADD COLUMN user_id INT NULL AFTER client_id",
    "ADD COLUMN priority VARCHAR(50) DEFAULT 'Normal' AFTER status",
    "ADD COLUMN category VARCHAR(50) DEFAULT 'Civil' AFTER priority",
    "ADD COLUMN estimated_fees DECIMAL(12,2) DEFAULT 0.00 AFTER category",
    "ADD COLUMN start_date DATE NULL AFTER estimated_fees",
    "ADD COLUMN expected_completion DATE NULL AFTER start_date"
];

foreach ($caseMigrations as $migration) {
    try {
        $pdo->query("ALTER TABLE cases " . $migration);
    } catch (PDOException $e) {
        if (stripos($e->getMessage(), 'duplicate column') === false) {
            throw $e;
        }
    }
}

// Ensure payments table exists
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            case_id INT NOT NULL,
            client_id INT NOT NULL,
            invoice_id INT NULL,
            amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            method VARCHAR(50) DEFAULT 'cash',
            reference VARCHAR(100),
            notes TEXT,
            payment_date DATE DEFAULT NULL,
            recorded_by VARCHAR(100),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE,
            FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
            FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (PDOException $e) {
    $message = 'Unable to prepare payments table: ' . htmlspecialchars($e->getMessage());
    $messageType = 'danger';
}

try {
    $pdo->query("ALTER TABLE payments ADD COLUMN invoice_id INT NULL AFTER client_id");
} catch (PDOException $e) {
    if (stripos($e->getMessage(), 'duplicate column') === false) {
        throw $e;
    }
}

try {
    $pdo->query("ALTER TABLE payments ADD CONSTRAINT fk_payments_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL");
} catch (PDOException $e) {
    $msgText = $e->getMessage();
    if (stripos($msgText, 'Duplicate') === false && stripos($msgText, 'already exists') === false && stripos($msgText, 'errno: 150') === false) {
        throw $e;
    }
}

$formData = [
    'case_id' => $selectedCaseId ?: '',
    'amount' => '',
    'method' => 'cash',
    'reference' => '',
    'notes' => '',
    'payment_date' => date('Y-m-d'),
    'recorded_by' => 'admin'
];

$allowedMethods = [
    'cash' => 'Cash',
    'bank_transfer' => 'Bank Transfer',
    'card' => 'Card',
    'cheque' => 'Cheque',
    'mobile' => 'Mobile Payment',
    'invoice' => 'Invoice'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $caseId = isset($_POST['case_id']) ? (int)$_POST['case_id'] : 0;
    $amount = isset($_POST['amount']) ? (float)$_POST['amount'] : 0;
    $method = isset($_POST['method']) ? trim($_POST['method']) : 'cash';
    $reference = isset($_POST['reference']) ? trim($_POST['reference']) : '';
    $notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';
    $paymentDate = isset($_POST['payment_date']) ? trim($_POST['payment_date']) : date('Y-m-d');
    $recordedBy = isset($_POST['recorded_by']) ? trim($_POST['recorded_by']) : 'admin';

    $formData = [
        'case_id' => $caseId ?: '',
        'amount' => $amount,
        'method' => $method,
        'reference' => $reference,
        'notes' => $notes,
        'payment_date' => $paymentDate,
        'recorded_by' => $recordedBy
    ];

    if (empty($caseId) || $amount <= 0) {
        $message = 'Case and a positive amount are required.';
        $messageType = 'danger';
    } elseif (!isset($allowedMethods[$method])) {
        $message = 'Invalid payment method selected.';
        $messageType = 'danger';
    } else {
        $dateObj = DateTime::createFromFormat('Y-m-d', $paymentDate);
        if (!$dateObj) {
            $message = 'Invalid payment date.';
            $messageType = 'danger';
        } else {
            $stmt = $pdo->prepare("
                SELECT c.id, c.client_id, COALESCE(c.estimated_fees, 0) AS estimated_fees,
                       c.title, cl.first_name, cl.last_name
                FROM cases c
                LEFT JOIN clients cl ON cl.id = c.client_id
                WHERE c.id = ?
            ");
            $stmt->execute([$caseId]);
            $caseRow = $stmt->fetch();

            if (!$caseRow) {
                $message = 'Case not found.';
                $messageType = 'danger';
            } else {
                // Calculate remaining balance if the case has an estimated fee
                $estimatedFees = isset($caseRow['estimated_fees']) ? (float)$caseRow['estimated_fees'] : 0;
                $paidTotal = 0;
                if ($estimatedFees > 0) {
                    $sumStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) AS total_paid FROM payments WHERE case_id = ?");
                    $sumStmt->execute([$caseRow['id']]);
                    $paidTotal = (float)$sumStmt->fetchColumn();
                    $remaining = max($estimatedFees - $paidTotal, 0);
                    if ($remaining <= 0.01) {
                        $message = 'This case is already fully paid.';
                        $messageType = 'warning';
                        goto render_page;
                    }
                    if ($amount > $remaining) {
                        $message = 'Payment exceeds the remaining balance of ' . formatCurrency($remaining) . '.';
                        $messageType = 'danger';
                        goto render_page;
                    }
                }

                try {
                    $insert = $pdo->prepare("
                        INSERT INTO payments (case_id, client_id, amount, method, reference, notes, payment_date, recorded_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $insert->execute([
                        $caseRow['id'],
                        $caseRow['client_id'],
                        $amount,
                        $method,
                        $reference,
                        $notes,
                        $dateObj->format('Y-m-d'),
                        $recordedBy
                    ]);

                    // Track payment addition
                    CaseEvents::trackPaymentAdded($caseRow['id'], [
                        'amount' => $amount,
                        'method' => $method,
                        'reference' => $reference
                    ]);

                    $msg = 'Payment recorded successfully.';
                    header('Location: payments.php?msg=' . urlencode($msg) . '&type=success');
                    exit;
                } catch (PDOException $e) {
                    $message = 'Unable to save payment: ' . htmlspecialchars($e->getMessage());
                    $messageType = 'danger';
                }
            }
        }
    }
}

render_page:

// Fetch case list with payment stats
$cases = [];
$caseOptions = '<option value="">Select case</option>';
$ledgerOptions = '<option value="">View case...</option>';
$caseLedger = [];
$outstandingRows = '';
$outstandingCount = 0;

try {
    $stmt = $pdo->query("
        SELECT 
            c.id,
            c.title,
            c.status,
            COALESCE(c.estimated_fees, 0) AS estimated_fees,
            CONCAT(cl.first_name, ' ', cl.last_name) AS client_name,
            COALESCE(SUM(p.amount), 0) AS paid_total,
            MAX(p.payment_date) AS last_payment
        FROM cases c
        LEFT JOIN clients cl ON cl.id = c.client_id
        LEFT JOIN payments p ON p.case_id = c.id
        GROUP BY c.id, c.title, c.status, c.estimated_fees, cl.first_name, cl.last_name
        ORDER BY c.created_at DESC
    ");
    $cases = $stmt->fetchAll();
} catch (PDOException $e) {
    $cases = [];
    if (!$message) {
        $message = 'Unable to load cases list: ' . htmlspecialchars($e->getMessage());
        $messageType = 'danger';
    }
}

$totalOutstanding = 0;

foreach ($cases as $case) {
    $caseId = (int)$case['id'];
    $caseNumber = 'C-' . str_pad($caseId, 4, '0', STR_PAD_LEFT);
    $estimated = isset($case['estimated_fees']) ? (float)$case['estimated_fees'] : 0;
    $paid = isset($case['paid_total']) ? (float)$case['paid_total'] : 0;
    $balance = max($estimated - $paid, 0);
    $lastPayment = isset($case['last_payment']) && $case['last_payment'] ? $case['last_payment'] : '—';
    $clientName = isset($case['client_name']) && $case['client_name'] ? $case['client_name'] : 'Unknown Client';

    $selectedAttr = $formData['case_id'] == $caseId ? ' selected' : '';
    $caseOptions .= '<option value="' . $caseId . '"' . $selectedAttr . '>' . htmlspecialchars($caseNumber . ' · ' . $case['title'] . ' (' . $clientName . ')') . '</option>';
    $ledgerOptions .= '<option value="' . $caseId . '">' . htmlspecialchars($caseNumber . ' · ' . $case['title']) . '</option>';

    $caseLedger[$caseId] = [
        'case_number' => $caseNumber,
        'title' => $case['title'],
        'client' => $clientName,
        'estimated' => formatCurrency($estimated),
        'estimated_raw' => $estimated,
        'paid' => formatCurrency($paid),
        'paid_raw' => $paid,
        'balance' => formatCurrency($balance),
        'balance_raw' => $balance,
        'status' => $case['status'],
        'last_payment' => $lastPayment
    ];

    if ($balance > 0.01) {
        $outstandingCount++;
        $totalOutstanding += $balance;
        $badgeClass = 'badge bg-gradient-warning';
        if ($balance <= ($estimated * 0.2)) {
            $badgeClass = 'badge bg-gradient-success';
        }

        $outstandingRows .= '
        <tr>
            <td>
                <div class="d-flex flex-column">
                    <span class="text-sm fw-bold">' . htmlspecialchars($caseNumber . ' · ' . $case['title']) . '</span>
                    <small class="text-muted">' . htmlspecialchars($clientName) . '</small>
                </div>
            </td>
            <td class="text-center">' . formatCurrency($estimated) . '</td>
            <td class="text-center text-success fw-bold">' . formatCurrency($paid) . '</td>
            <td class="text-center">
                <span class="' . $badgeClass . '">' . formatCurrency($balance) . '</span>
            </td>
            <td class="text-end text-xs">' . ($lastPayment !== '—' ? htmlspecialchars($lastPayment) : '<span class="text-muted">No payments</span>') . '</td>
        </tr>';
    }
}

if (!$outstandingRows) {
    $outstandingRows = '<tr><td colspan="5" class="text-center py-4 text-muted">All cases are fully paid.</td></tr>';
}

// Totals
try {
    $totalCollected = (float)$pdo->query("SELECT COALESCE(SUM(amount), 0) AS total FROM payments")->fetchColumn();
} catch (PDOException $e) {
    $totalCollected = 0;
}

$activePaymentPlans = $outstandingCount;

try {
    $paymentsThisMonth = (float)$pdo->query("
        SELECT COALESCE(SUM(amount), 0) FROM payments 
        WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    ")->fetchColumn();
} catch (PDOException $e) {
    $paymentsThisMonth = 0;
}

// Recent payments
$recentPaymentsRows = '';
try {
    $stmt = $pdo->query("
        SELECT 
            p.*,
            c.title AS case_title,
            CONCAT(cl.first_name, ' ', cl.last_name) AS client_name
        FROM payments p
        LEFT JOIN cases c ON c.id = p.case_id
        LEFT JOIN clients cl ON cl.id = p.client_id
        ORDER BY p.payment_date DESC, p.id DESC
        LIMIT 12
    ");
    $recentPayments = $stmt->fetchAll();
} catch (PDOException $e) {
    $recentPayments = [];
}

if (empty($recentPayments)) {
    $recentPaymentsRows = '<tr><td colspan="5" class="text-center py-4 text-muted">No payments recorded yet.</td></tr>';
} else {
    foreach ($recentPayments as $payment) {
        $caseNumber = 'C-' . str_pad($payment['case_id'], 4, '0', STR_PAD_LEFT);
        $clientName = isset($payment['client_name']) && $payment['client_name'] ? $payment['client_name'] : 'Unknown Client';
        $methodLabel = isset($allowedMethods[$payment['method']]) ? $allowedMethods[$payment['method']] : ucfirst($payment['method']);
        $notesPreview = isset($payment['notes']) && $payment['notes'] ? htmlspecialchars($payment['notes']) : '<span class="text-muted">—</span>';

        $recentPaymentsRows .= '
        <tr>
            <td>
                <div class="d-flex flex-column">
                    <span class="text-sm fw-bold">' . htmlspecialchars($clientName) . '</span>
                    <small class="text-muted">' . htmlspecialchars($caseNumber . ' · ' . $payment['case_title']) . '</small>
                </div>
            </td>
            <td class="text-center">' . formatCurrency($payment['amount']) . '</td>
            <td class="text-center"><span class="badge bg-gradient-dark">' . htmlspecialchars($methodLabel) . '</span></td>
            <td class="text-center">' . htmlspecialchars($payment['payment_date']) . '</td>
            <td class="text-end text-xs">' . $notesPreview . '</td>
        </tr>';
    }
}

$messageHtml = '';
if (!empty($message)) {
    $messageHtml = '<div class="alert alert-' . htmlspecialchars($messageType ? $messageType : 'info') . ' alert-dismissible fade show mx-3 mt-3" role="alert">
        ' . htmlspecialchars($message) . '
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>';
}

$methodsOptions = '';
foreach ($allowedMethods as $value => $label) {
    $selected = $formData['method'] === $value ? ' selected' : '';
    $methodsOptions .= '<option value="' . htmlspecialchars($value) . '"' . $selected . '>' . htmlspecialchars($label) . '</option>';
}

$html = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="apple-touch-icon" sizes="76x76" href="../assets/img/apple-icon.png">
    <link rel="icon" type="image/png" href="../assets/img/favicon.png">
    <title>LegalPro · Payments</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-icons.css" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-svg.css" rel="stylesheet" />
    <script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
    <link id="pagestyle" href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
<link href="../assets/css/app-font-montserrat.css?v=1" rel="stylesheet" />
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-admin-portal">
    <div class="min-height-300 bg-legalpro-admin position-absolute w-100"></div>
    <aside class="sidenav bg-white navbar navbar-vertical navbar-expand-xs border-0 border-radius-xl my-3 fixed-start ms-4" id="sidenav-main">
        <!-- replaced dynamically -->
    </aside>
    <main class="main-content position-relative border-radius-lg ">
        <nav class="navbar navbar-main navbar-expand-lg px-0 mx-4 shadow-none border-radius-xl " id="navbarBlur" data-scroll="false">
            <div class="container-fluid py-1 px-3">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb bg-transparent mb-0 pb-0 pt-1 px-0 me-sm-6 me-5">
                        <li class="breadcrumb-item text-sm"><a class="opacity-5 text-white" href="javascript:;">Finance</a></li>
                        <li class="breadcrumb-item text-sm text-white active" aria-current="page">Payments</li>
                    </ol>
                    <h6 class="font-weight-bolder text-white mb-0">Payment Intake</h6>
                </nav>
            </div>
        </nav>
        <div class="container-fluid py-4">
            {MESSAGE}
            <div class="row">
                <div class="col-xl-3 col-sm-6 mb-xl-0 mb-4">
                    <div class="card">
                        <div class="card-body p-3">
                            <div class="row">
                                <div class="col-8">
                                    <div class="numbers">
                                        <p class="text-sm mb-0 text-uppercase font-weight-bold">Collected (All time)</p>
                                        <h5 class="font-weight-bolder">{TOTAL_COLLECTED}</h5>
                                        <p class="mb-0 text-sm text-success">+{PAST_30} last 30 days</p>
                                    </div>
                                </div>
                                <div class="col-4 text-end">
                                    <div class="icon icon-shape bg-gradient-success shadow-success text-center rounded-circle">
                                        <i class="ni ni-money-coins text-lg opacity-10" aria-hidden="true"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-sm-6 mb-xl-0 mb-4">
                    <div class="card">
                        <div class="card-body p-3">
                            <div class="row">
                                <div class="col-8">
                                    <div class="numbers">
                                        <p class="text-sm mb-0 text-uppercase font-weight-bold">Outstanding</p>
                                        <h5 class="font-weight-bolder">{TOTAL_OUTSTANDING}</h5>
                                        <p class="mb-0"><span class="text-warning text-sm font-weight-bolder">{ACTIVE_PLANS}</span> active plans</p>
                                    </div>
                                </div>
                                <div class="col-4 text-end">
                                    <div class="icon icon-shape bg-gradient-warning shadow-warning text-center rounded-circle">
                                        <i class="ni ni-time-alarm text-lg opacity-10" aria-hidden="true"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-sm-6 mb-xl-0 mb-4">
                    <div class="card">
                        <div class="card-body p-3">
                            <div class="row">
                                <div class="col-8">
                                    <div class="numbers">
                                        <p class="text-sm mb-0 text-uppercase font-weight-bold">Cases Paid Off</p>
                                        <h5 class="font-weight-bolder">{CASES_PAID_OFF}</h5>
                                        <p class="mb-0 text-sm text-muted">Fully settled matters</p>
                                    </div>
                                </div>
                                <div class="col-4 text-end">
                                    <div class="icon icon-shape bg-gradient-info shadow-info text-center rounded-circle">
                                        <i class="ni ni-check-bold text-lg opacity-10" aria-hidden="true"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-sm-6">
                    <div class="card">
                        <div class="card-body p-3">
                            <div class="row">
                                <div class="col-8">
                                    <div class="numbers">
                                        <p class="text-sm mb-0 text-uppercase font-weight-bold">Active Installments</p>
                                        <h5 class="font-weight-bolder">{ACTIVE_PLANS}</h5>
                                        <p class="mb-0 text-sm text-muted">Cases with balance</p>
                                    </div>
                                </div>
                                <div class="col-4 text-end">
                                    <div class="icon icon-shape bg-gradient-primary shadow-primary text-center rounded-circle">
                                        <i class="ni ni-collection text-lg opacity-10" aria-hidden="true"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="row mt-4">
                <div class="col-lg-5">
                    <div class="card h-100">
                        <div class="card-header pb-0">
                            <h6 class="mb-0">Record Payment</h6>
                            <p class="text-sm text-muted mb-0">Log cash, bank or gradual payments for any case.</p>
                        </div>
                        <div class="card-body pt-0">
                            <form method="post" autocomplete="off">
                                <div class="mb-3">
                                    <label class="form-label">Select Case</label>
                                    <select class="form-select" name="case_id" id="case_id" required>
                                        {CASE_OPTIONS}
                                    </select>
                                </div>
                                <div class="row">
                                    <div class="col-6 mb-3">
                                        <label class="form-label">Payment Date</label>
                                        <input type="date" class="form-control" name="payment_date" value="{FORM_DATE}" required>
                                    </div>
                                    <div class="col-6 mb-3">
                                        <label class="form-label">Amount</label>
                                        <input type="number" step="0.01" min="0" class="form-control" name="amount" value="{FORM_AMOUNT}" placeholder="0.00" required>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Method</label>
                                    <select class="form-select" name="method">
                                        {METHOD_OPTIONS}
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Reference (optional)</label>
                                    <input type="text" class="form-control" name="reference" value="{FORM_REFERENCE}" placeholder="Receipt no., bank ref...">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Notes</label>
                                    <textarea class="form-control" name="notes" rows="3" placeholder="Add internal notes">{FORM_NOTES}</textarea>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Recorded By</label>
                                    <input type="text" class="form-control" name="recorded_by" value="{FORM_RECORDED_BY}" placeholder="Staff name">
                                </div>
                                <button type="submit" class="btn btn-dark w-100">Save Payment</button>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="col-lg-7 mt-4 mt-lg-0">
                    <div class="card h-100">
                        <div class="card-header pb-0 d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <div>
                                <h6 class="mb-0">Case Ledger</h6>
                                <p class="text-sm text-muted mb-0" id="selected-case-label">Select a case to view its balance.</p>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <select class="form-select form-select-sm" id="ledger_case_select">
                                    {LEDGER_OPTIONS}
                                </select>
                                <a href="financial-summary.php" class="btn btn-sm btn-outline-dark">Financial Summary</a>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="border rounded p-3 text-center mb-3">
                                        <p class="text-xs text-muted mb-1">Total Fee</p>
                                        <h5 class="mb-0" id="ledger-fee">$0.00</h5>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="border rounded p-3 text-center mb-3">
                                        <p class="text-xs text-muted mb-1">Paid</p>
                                        <h5 class="mb-0 text-success" id="ledger-paid">$0.00</h5>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="border rounded p-3 text-center mb-3">
                                        <p class="text-xs text-muted mb-1">Balance</p>
                                        <h5 class="mb-0 text-warning" id="ledger-balance">$0.00</h5>
                                    </div>
                                </div>
                            </div>
                            <div class="progress-wrapper mb-3">
                                <span class="text-xs text-muted">Progress</span>
                                <div class="progress">
                                    <div id="ledger-progress" class="progress-bar bg-gradient-dark" role="progressbar" style="width:0%;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
                                </div>
                            </div>
                            <div class="d-flex justify-content-between text-sm">
                                <div>
                                    <strong>Status:</strong> <span id="ledger-status">—</span>
                                </div>
                                <div>
                                    <strong>Last payment:</strong> <span id="ledger-last-payment">—</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="row mt-4">
                <div class="col-lg-7">
                    <div class="card">
                        <div class="card-header pb-0">
                            <h6>Recent Payments</h6>
                        </div>
                        <div class="card-body px-0 pt-0 pb-2">
                            <div class="table-responsive">
                                <table class="table align-items-center mb-0">
                                    <thead>
                                        <tr>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Client / Case</th>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder text-center opacity-7">Amount</th>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder text-center opacity-7">Method</th>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder text-center opacity-7">Date</th>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder text-end opacity-7">Notes</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {RECENT_PAYMENTS}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-5 mt-4 mt-lg-0">
                    <div class="card h-100">
                        <div class="card-header pb-0">
                            <h6>Outstanding Balances</h6>
                            <p class="text-sm text-muted mb-0">Track cases still on a payment plan.</p>
                        </div>
                        <div class="card-body px-0 pt-0 pb-2">
                            <div class="table-responsive">
                                <table class="table align-items-center mb-0">
                                    <thead>
                                        <tr>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Case</th>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder text-center opacity-7">Fee</th>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder text-center opacity-7">Paid</th>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder text-center opacity-7">Balance</th>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder text-end opacity-7">Last Payment</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {OUTSTANDING_ROWS}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
    <script>
        (function() {
            var ledgerData = {CASE_DATA_JSON};
            var currencyZero = '{CURRENCY_ZERO}';
            var caseSelect = document.getElementById('case_id');
            var ledgerSelect = document.getElementById('ledger_case_select');
            var feeEl = document.getElementById('ledger-fee');
            var paidEl = document.getElementById('ledger-paid');
            var balanceEl = document.getElementById('ledger-balance');
            var progressEl = document.getElementById('ledger-progress');
            var statusEl = document.getElementById('ledger-status');
            var lastEl = document.getElementById('ledger-last-payment');
            var labelEl = document.getElementById('selected-case-label');

            function setProgress(percent) {
                progressEl.style.width = percent + '%';
                progressEl.setAttribute('aria-valuenow', percent);
            }

            function updateLedger(caseId, opts) {
                opts = opts || {};
                if (ledgerData[caseId]) {
                    var data = ledgerData[caseId];
                    feeEl.textContent = data.estimated;
                    paidEl.textContent = data.paid;
                    balanceEl.textContent = data.balance;
                    statusEl.textContent = data.status ? data.status.toUpperCase() : '—';
                    lastEl.textContent = data.last_payment && data.last_payment !== '—' ? data.last_payment : 'No payments';
                    labelEl.textContent = data.case_number + ' · ' + data.title + ' (' + data.client + ')';
                    var fee = parseFloat(data.estimated_raw || 0);
                    var paid = parseFloat(data.paid_raw || 0);
                    var percent = fee ? Math.min(100, Math.round((paid / fee) * 100)) : 0;
                    setProgress(percent);
                } else {
                    feeEl.textContent = currencyZero;
                    paidEl.textContent = currencyZero;
                    balanceEl.textContent = currencyZero;
                    statusEl.textContent = '—';
                    lastEl.textContent = '—';
                    labelEl.textContent = 'Select a case to view its balance.';
                    setProgress(0);
                }
                if (!opts.skipSyncLedger && ledgerSelect) {
                    ledgerSelect.value = caseId || '';
                }
                if (!opts.skipSyncForm && caseSelect) {
                    caseSelect.value = caseId || '';
                }
            }

            if (caseSelect) {
                caseSelect.addEventListener('change', function() {
                    updateLedger(this.value, { skipSyncForm: true });
                });
            }
            if (ledgerSelect) {
                ledgerSelect.addEventListener('change', function() {
                    updateLedger(this.value, { skipSyncLedger: true });
                });
            }

            var initialCaseId = '';
            if (ledgerSelect && ledgerSelect.value) {
                initialCaseId = ledgerSelect.value;
            } else if (caseSelect && caseSelect.value) {
                initialCaseId = caseSelect.value;
            } else if (ledgerSelect && ledgerSelect.options.length > 1) {
                ledgerSelect.selectedIndex = 1;
                initialCaseId = ledgerSelect.value;
            }
            if (initialCaseId) {
                updateLedger(initialCaseId);
            } else {
                setProgress(0);
            }
        })();
    </script>
</body>
</html>
HTML;

$casesPaidOff = 0;
foreach ($cases as $case) {
    $estimated = isset($case['estimated_fees']) ? (float)$case['estimated_fees'] : 0;
    $paid = isset($case['paid_total']) ? (float)$case['paid_total'] : 0;
    if ($estimated > 0 && $paid >= $estimated) {
        $casesPaidOff++;
    }
}

$html = str_replace('{MESSAGE}', $messageHtml, $html);
$html = str_replace('{CASE_OPTIONS}', $caseOptions, $html);
$html = str_replace('{LEDGER_OPTIONS}', $ledgerOptions, $html);
$html = str_replace('{FORM_DATE}', htmlspecialchars($formData['payment_date']), $html);
$html = str_replace('{FORM_AMOUNT}', htmlspecialchars($formData['amount']), $html);
$html = str_replace('{FORM_REFERENCE}', htmlspecialchars($formData['reference']), $html);
$html = str_replace('{FORM_NOTES}', htmlspecialchars($formData['notes']), $html);
$html = str_replace('{FORM_RECORDED_BY}', htmlspecialchars($formData['recorded_by']), $html);
$html = str_replace('{METHOD_OPTIONS}', $methodsOptions, $html);
$html = str_replace('{RECENT_PAYMENTS}', $recentPaymentsRows, $html);
$html = str_replace('{OUTSTANDING_ROWS}', $outstandingRows, $html);
$html = str_replace('{TOTAL_COLLECTED}', formatCurrency($totalCollected), $html);
$html = str_replace('{TOTAL_OUTSTANDING}', formatCurrency($totalOutstanding), $html);
$html = str_replace('{ACTIVE_PLANS}', $activePaymentPlans, $html);
$html = str_replace('{CASES_PAID_OFF}', $casesPaidOff, $html);
$html = str_replace('{PAST_30}', formatCurrency($paymentsThisMonth), $html);
$html = str_replace('{CASE_DATA_JSON}', json_encode($caseLedger), $html);
$html = str_replace('{CURRENCY_ZERO}', formatCurrency(0), $html);

// rewrite internal links (if any) to .php
$html = preg_replace('/href="([^"\']+)\.html"/i', 'href="$1.php"', $html);

ob_start();
include __DIR__ . '/../inc/menunav.php';
$sidebar = ob_get_clean();
$html = preg_replace('/<aside[\s\S]*?<\/aside>/', $sidebar, $html, 1);

ob_start();
include __DIR__ . '/../inc/footer.php';
$footer = ob_get_clean();
$html = preg_replace('/<\/body>\s*<\/html>$/i', $footer . "\n</body>\n</html>", $html);

echo $html;
?>

