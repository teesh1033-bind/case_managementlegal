<?php
require_once __DIR__ . '/../inc/db.php';

$message = '';
$messageType = '';
if (isset($_GET['msg']) && isset($_GET['type'])) {
    $message = urldecode($_GET['msg']);
    $messageType = $_GET['type'];
}

// Ensure schema
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

// Fetch case level summary
$cases = [];
try {
    $stmt = $pdo->query("
        SELECT 
            c.id,
            c.title,
            c.status,
            c.category,
            c.priority,
            COALESCE(c.estimated_fees, 0) AS estimated_fees,
            CONCAT(cl.first_name, ' ', cl.last_name) AS client_name,
            COUNT(p.id) AS payment_count,
            COALESCE(SUM(p.amount), 0) AS paid_total,
            MAX(p.payment_date) AS last_payment,
            MIN(p.payment_date) AS first_payment
        FROM cases c
        LEFT JOIN clients cl ON cl.id = c.client_id
        LEFT JOIN payments p ON p.case_id = c.id
        GROUP BY c.id, c.title, c.status, c.category, c.priority, c.estimated_fees, cl.first_name, cl.last_name
        ORDER BY c.created_at DESC
    ");
    $cases = $stmt->fetchAll();
} catch (PDOException $e) {
    $cases = [];
    if (!$message) {
        $message = 'Unable to load financial data: ' . htmlspecialchars($e->getMessage());
        $messageType = 'danger';
    }
}

$totalFees = 0;
$totalPaid = 0;
$totalBalance = 0;
$casesFullyPaid = 0;
$caseRows = '';
$summaryData = [];

foreach ($cases as $case) {
    $caseId = (int)$case['id'];
    $caseNumber = 'C-' . str_pad($caseId, 4, '0', STR_PAD_LEFT);
    $estimated = isset($case['estimated_fees']) ? (float)$case['estimated_fees'] : 0;
    $paid = isset($case['paid_total']) ? (float)$case['paid_total'] : 0;
    $balance = max($estimated - $paid, 0);
    $status = isset($case['status']) && $case['status'] ? $case['status'] : 'open';
    $clientName = isset($case['client_name']) && $case['client_name'] ? $case['client_name'] : 'Unknown Client';
    $paymentCount = isset($case['payment_count']) ? (int)$case['payment_count'] : 0;
    $lastPayment = isset($case['last_payment']) && $case['last_payment'] ? $case['last_payment'] : '—';
    $category = isset($case['category']) && $case['category'] ? $case['category'] : 'General';

    $totalFees += $estimated;
    $totalPaid += $paid;
    $totalBalance += $balance;
    if ($balance <= 0.01 && $estimated > 0) {
        $casesFullyPaid++;
    }

    $percent = $estimated > 0 ? min(100, round(($paid / $estimated) * 100)) : 0;
    $badgeClass = 'bg-gradient-dark';
    if ($status === 'closed') {
        $badgeClass = 'bg-gradient-success';
    } elseif ($status === 'open') {
        $badgeClass = 'bg-gradient-info';
    } elseif ($status === 'on hold') {
        $badgeClass = 'bg-gradient-warning';
    }

    $caseRows .= '
        <tr>
            <td>
                <div class="d-flex flex-column">
                    <span class="text-sm fw-bold">' . htmlspecialchars($caseNumber . ' · ' . $case['title']) . '</span>
                    <small class="text-muted">' . htmlspecialchars($clientName) . ' · ' . htmlspecialchars(ucfirst($category)) . '</small>
                </div>
            </td>
            <td class="text-center">' . formatCurrency($estimated) . '</td>
            <td class="text-center text-success fw-bold">' . formatCurrency($paid) . '</td>
            <td class="text-center text-warning fw-bold">' . formatCurrency($balance) . '</td>
        <td class="text-center">
            <div class="progress-wrapper">
                <div class="progress" style="height: 6px;">
                    <div class="progress-bar bg-gradient-dark" role="progressbar" style="width: ' . $percent . '%;"></div>
                </div>
                <small class="text-xs text-muted">' . $percent . '% paid</small>
            </div>
        </td>
        <td class="text-center"><span class="badge ' . $badgeClass . '">' . htmlspecialchars(ucfirst($status)) . '</span></td>
        <td class="text-center">' . ($paymentCount ? $paymentCount : '—') . '</td>
        <td class="text-center">' . ($lastPayment !== '—' ? htmlspecialchars($lastPayment) : '<span class="text-muted">No payments</span>') . '</td>
        <td class="text-end">
            <button class="btn btn-sm btn-dark" data-case="' . $caseId . '" onclick="showPaymentHistory(' . $caseId . ')">History</button>
        </td>
    </tr>';

    $summaryData[$caseId] = [
        'case_number' => $caseNumber,
        'title' => $case['title'],
        'client' => $clientName,
        'estimated' => formatCurrency($estimated),
        'paid' => formatCurrency($paid),
        'balance' => formatCurrency($balance),
        'status' => ucfirst($status),
        'category' => $category,
        'priority' => isset($case['priority']) ? $case['priority'] : '',
        'last_payment' => $lastPayment,
        'first_payment' => isset($case['first_payment']) && $case['first_payment'] ? $case['first_payment'] : '—'
    ];
}

if (!$caseRows) {
    $caseRows = '<tr><td colspan="9" class="text-center py-4 text-muted">No cases found.</td></tr>';
}

// Payment history grouped per case
$historyData = [];
try {
    $stmt = $pdo->query("
        SELECT 
            p.id,
            p.case_id,
            p.amount,
            p.method,
            p.reference,
            p.notes,
            p.payment_date,
            p.recorded_by,
            c.title,
            CONCAT(cl.first_name, ' ', cl.last_name) AS client_name
        FROM payments p
        LEFT JOIN cases c ON c.id = p.case_id
        LEFT JOIN clients cl ON cl.id = p.client_id
        ORDER BY p.payment_date DESC, p.id DESC
    ");
    $allPayments = $stmt->fetchAll();
    foreach ($allPayments as $payment) {
        $caseId = (int)$payment['case_id'];
        if (!isset($historyData[$caseId])) {
            $historyData[$caseId] = [];
        }
        $historyData[$caseId][] = [
            'amount' => formatCurrency((float)$payment['amount']),
            'method' => ucfirst($payment['method']),
            'reference' => $payment['reference'] ? $payment['reference'] : '—',
            'notes' => $payment['notes'] ? $payment['notes'] : '',
            'date' => $payment['payment_date'],
            'recorded_by' => $payment['recorded_by'] ? $payment['recorded_by'] : '—',
            'payment_id' => isset($payment['id']) ? (int)$payment['id'] : null
        ];
    }
} catch (PDOException $e) {
    $historyData = [];
}

$avgRealized = $totalFees > 0 ? round(($totalPaid / $totalFees) * 100) : 0;

$messageHtml = '';
if (!empty($message)) {
    $messageHtml = '<div class="alert alert-' . htmlspecialchars($messageType ? $messageType : 'info') . ' alert-dismissible fade show mx-3 mt-3" role="alert">
        ' . htmlspecialchars($message) . '
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>';
}

$html = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="apple-touch-icon" sizes="76x76" href="../assets/img/apple-icon.png">
    <link rel="icon" type="image/png" href="../assets/img/favicon.png">
    <title>LegalPro · Financial Summary</title>
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
                        <li class="breadcrumb-item text-sm text-white active" aria-current="page">Financial Summary</li>
                    </ol>
                    <h6 class="font-weight-bolder text-white mb-0">Financial Summary</h6>
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
                                        <p class="text-sm mb-0 text-uppercase font-weight-bold">Total Fees</p>
                                        <h5 class="font-weight-bolder">{TOTAL_FEES}</h5>
                                        <p class="mb-0 text-sm text-muted">Total across cases</p>
                                    </div>
                                </div>
                                <div class="col-4 text-end">
                                    <div class="icon icon-shape bg-gradient-primary shadow-primary text-center rounded-circle">
                                        <i class="ni ni-briefcase-24 text-lg opacity-10" aria-hidden="true"></i>
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
                                        <p class="text-sm mb-0 text-uppercase font-weight-bold">Collected</p>
                                        <h5 class="font-weight-bolder">{TOTAL_PAID}</h5>
                                        <p class="mb-0 text-sm text-success">{AVG_REALIZED}% realized</p>
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
                                        <h5 class="font-weight-bolder">{TOTAL_BALANCE}</h5>
                                        <p class="mb-0 text-sm text-warning">{CASES_WITH_BALANCE} cases</p>
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
                <div class="col-xl-3 col-sm-6">
                    <div class="card">
                        <div class="card-body p-3">
                            <div class="row">
                                <div class="col-8">
                                    <div class="numbers">
                                        <p class="text-sm mb-0 text-uppercase font-weight-bold">Paid Off</p>
                                        <h5 class="font-weight-bolder">{CASES_PAID}</h5>
                                        <p class="mb-0 text-sm text-muted">Cases fully settled</p>
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
            </div>
            <div class="card mt-4">
                <div class="card-header pb-0 d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center">
                    <div>
                        <h6 class="mb-0">Case Financials</h6>
                        <p class="text-sm text-muted mb-0">Monitor totals, collected amounts, and remaining balances per case.</p>
                    </div>
                    <div class="mt-3 mt-md-0">
                        <a href="payments.php" class="btn btn-sm btn-dark me-2">Record Payment</a>
                        <a href="documents.php" class="btn btn-sm btn-dark">Generate Invoice</a>
                    </div>
                </div>
                <div class="card-body px-0 pt-0 pb-2">
                    <div class="table-responsive">
                        <table class="table align-items-center mb-0">
                            <thead>
                                <tr>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Case</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder text-center opacity-7">Total Fee</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder text-center opacity-7">Collected</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder text-center opacity-7">Remaining</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder text-center opacity-7">Progress</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder text-center opacity-7">Status</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder text-center opacity-7">Payments</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder text-center opacity-7">Last Payment</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                {CASE_ROWS}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </main>
    <div class="modal fade" id="paymentHistoryModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title">Payment History</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="paymentHistoryContent">
                        <p class="text-muted mb-0">Select a case to view its payment history.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script>
        (function() {
            var summaryData = {SUMMARY_DATA};
            var historyData = {HISTORY_DATA};
            window.showPaymentHistory = function(caseId) {
                var modalEl = document.getElementById('paymentHistoryModal');
                var bodyEl = document.getElementById('paymentHistoryContent');
                if (!modalEl || !bodyEl) { return; }
                var caseInfo = summaryData[caseId];
                var payments = historyData[caseId] || [];
                var header = '';
                if (caseInfo) {
                    header = '<div class="mb-3">' +
                        '<h6 class="mb-1">' + caseInfo.case_number + ' · ' + caseInfo.title + '</h6>' +
                        '<p class="text-sm text-muted mb-0">' + caseInfo.client + ' · ' + caseInfo.category + '</p>' +
                        '<div class="d-flex gap-3 text-sm mt-2">' +
                            '<span><strong>Total:</strong> ' + caseInfo.estimated + '</span>' +
                            '<span class="text-success"><strong>Paid:</strong> ' + caseInfo.paid + '</span>' +
                            '<span class="text-warning"><strong>Balance:</strong> ' + caseInfo.balance + '</span>' +
                        '</div>' +
                    '</div>';
                }
                if (!payments.length) {
                    bodyEl.innerHTML = header + '<p class="text-muted">No payments recorded yet.</p>';
                } else {
                    var list = '<div class="table-responsive"><table class="table table-sm"><thead><tr><th>Date</th><th>Amount</th><th>Method</th><th>Reference</th><th>Notes</th><th>Recorded By</th><th>Receipt</th></tr></thead><tbody>';
                    for (var i = 0; i < payments.length; i++) {
                        var p = payments[i];
                        var receiptLink = p.payment_id
                            ? '<a class="btn btn-sm btn-outline-dark" href="payment-receipt.php?id=' + encodeURIComponent(p.payment_id) + '" target="_blank" rel="noopener">Receipt</a>'
                            : '<span class="text-muted">—</span>';
                        list += '<tr>' +
                            '<td>' + p.date + '</td>' +
                            '<td>' + p.amount + '</td>' +
                            '<td>' + p.method + '</td>' +
                            '<td>' + (p.reference || '—') + '</td>' +
                            '<td>' + (p.notes ? p.notes : '—') + '</td>' +
                            '<td>' + (p.recorded_by || '—') + '</td>' +
                            '<td>' + receiptLink + '</td>' +
                        '</tr>';
                    }
                    list += '</tbody></table></div>';
                    bodyEl.innerHTML = header + list;
                }
                if (typeof bootstrap !== 'undefined') {
                    var modal = new bootstrap.Modal(modalEl);
                    modal.show();
                } else {
                    modalEl.style.display = 'block';
                }
            };
        })();
    </script>
</body>
</html>
HTML;

$casesWithBalance = count(array_filter($cases, function($case) {
    $estimated = isset($case['estimated_fees']) ? (float)$case['estimated_fees'] : 0;
    $paid = isset($case['paid_total']) ? (float)$case['paid_total'] : 0;
    return $estimated - $paid > 0.01;
}));

$html = str_replace('{MESSAGE}', $messageHtml, $html);
$html = str_replace('{CASE_ROWS}', $caseRows, $html);
$html = str_replace('{TOTAL_FEES}', formatCurrency($totalFees), $html);
$html = str_replace('{TOTAL_PAID}', formatCurrency($totalPaid), $html);
$html = str_replace('{TOTAL_BALANCE}', formatCurrency($totalBalance), $html);
$html = str_replace('{AVG_REALIZED}', $avgRealized, $html);
$html = str_replace('{CASES_WITH_BALANCE}', $casesWithBalance, $html);
$html = str_replace('{CASES_PAID}', $casesFullyPaid, $html);
$html = str_replace('{SUMMARY_DATA}', json_encode($summaryData), $html);
$html = str_replace('{HISTORY_DATA}', json_encode($historyData), $html);

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

