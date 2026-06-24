<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/admin-layout.php';
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

$prefillAmount = isset($_GET['amount']) ? (float) $_GET['amount'] : 0;
$selectedInvoiceId = isset($_GET['invoice_id']) ? (int) $_GET['invoice_id'] : 0;

$caseInvoices = [];
try {
    $invoiceRows = $pdo->query("
        SELECT
            i.id,
            i.case_id,
            i.invoice_number,
            i.amount,
            COALESCE(SUM(p.amount), 0) AS paid_total
        FROM invoices i
        LEFT JOIN payments p ON p.invoice_id = i.id
        WHERE i.case_id IS NOT NULL
        GROUP BY i.id, i.case_id, i.invoice_number, i.amount
        ORDER BY i.id ASC
    ")->fetchAll();
    foreach ($invoiceRows as $row) {
        $caseIdKey = (int) $row['case_id'];
        $invoiceAmount = (float) $row['amount'];
        $paidTotal = (float) $row['paid_total'];
        $balance = max($invoiceAmount - $paidTotal, 0);
        $invoiceNumber = !empty($row['invoice_number'])
            ? $row['invoice_number']
            : 'INV-' . str_pad((string) $row['id'], 4, '0', STR_PAD_LEFT);

        if (!isset($caseInvoices[$caseIdKey])) {
            $caseInvoices[$caseIdKey] = [];
        }

        $caseInvoices[$caseIdKey][] = [
            'id' => (int) $row['id'],
            'number' => $invoiceNumber,
            'amount_raw' => $invoiceAmount,
            'paid_raw' => $paidTotal,
            'balance_raw' => $balance,
            'amount' => formatCurrency($invoiceAmount),
            'balance' => formatCurrency($balance),
            'label' => $balance <= 0.01
                ? $invoiceNumber . ' · ' . formatCurrency($invoiceAmount) . ' (paid in full)'
                : $invoiceNumber . ' · ' . formatCurrency($invoiceAmount) . ' (' . formatCurrency($balance) . ' remaining)',
            'is_paid' => $balance <= 0.01,
        ];
    }
} catch (PDOException $e) {
    $caseInvoices = [];
}

$formData = [
    'case_id' => $selectedCaseId ?: '',
    'invoice_id' => $selectedInvoiceId ?: '',
    'amount' => $prefillAmount > 0 ? $prefillAmount : '',
    'method' => 'cash',
    'reference' => '',
    'notes' => '',
    'payment_date' => date('Y-m-d'),
    'recorded_by' => 'admin'
];

if ($selectedCaseId > 0 && $_SERVER['REQUEST_METHOD'] !== 'POST' && $prefillAmount <= 0) {
    $prefilled = false;

    if ($selectedInvoiceId > 0) {
        foreach ($caseInvoices[$selectedCaseId] ?? [] as $invoiceOption) {
            if ($invoiceOption['id'] === $selectedInvoiceId && $invoiceOption['balance_raw'] > 0) {
                $formData['amount'] = $invoiceOption['balance_raw'];
                $prefilled = true;
                break;
            }
        }
    }

    if (!$prefilled && !empty($caseInvoices[$selectedCaseId])) {
        foreach ($caseInvoices[$selectedCaseId] as $invoiceOption) {
            if ($invoiceOption['balance_raw'] <= 0.01) {
                continue;
            }
            if (!$selectedInvoiceId) {
                $selectedInvoiceId = $invoiceOption['id'];
                $formData['invoice_id'] = $invoiceOption['id'];
            }
            if ((int) $formData['invoice_id'] === $invoiceOption['id']) {
                $formData['amount'] = $invoiceOption['balance_raw'];
                $prefilled = true;
                break;
            }
        }
    }

    if (!$prefilled) {
        try {
            $stmt = $pdo->prepare("
                SELECT COALESCE(c.estimated_fees, 0) AS estimated_fees,
                       COALESCE(SUM(p.amount), 0) AS paid_total
                FROM cases c
                LEFT JOIN payments p ON p.case_id = c.id
                WHERE c.id = ?
                GROUP BY c.id, c.estimated_fees
            ");
            $stmt->execute([$selectedCaseId]);
            $casePrefill = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($casePrefill) {
                $remaining = max((float) $casePrefill['estimated_fees'] - (float) $casePrefill['paid_total'], 0);
                if ($remaining > 0) {
                    $formData['amount'] = $remaining;
                }
            }
        } catch (PDOException $e) {
            // Continue without amount prefill
        }
    }
}

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
    $invoiceId = isset($_POST['invoice_id']) && $_POST['invoice_id'] !== '' ? (int) $_POST['invoice_id'] : 0;
    $amount = isset($_POST['amount']) ? (float)$_POST['amount'] : 0;
    $method = isset($_POST['method']) ? trim($_POST['method']) : 'cash';
    $reference = isset($_POST['reference']) ? trim($_POST['reference']) : '';
    $notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';
    $paymentDate = isset($_POST['payment_date']) ? trim($_POST['payment_date']) : date('Y-m-d');
    $recordedBy = isset($_POST['recorded_by']) ? trim($_POST['recorded_by']) : 'admin';

    $formData = [
        'case_id' => $caseId ?: '',
        'invoice_id' => $invoiceId ?: '',
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
                $unpaidInvoices = [];
                foreach ($caseInvoices[$caseId] ?? [] as $invoiceOption) {
                    if ($invoiceOption['balance_raw'] > 0.01) {
                        $unpaidInvoices[] = $invoiceOption;
                    }
                }

                if ($invoiceId <= 0 && count($unpaidInvoices) === 1) {
                    $invoiceId = $unpaidInvoices[0]['id'];
                    $formData['invoice_id'] = $invoiceId;
                } elseif ($invoiceId <= 0 && count($unpaidInvoices) > 1) {
                    $message = 'Please select which invoice this payment applies to.';
                    $messageType = 'danger';
                    goto render_page;
                }

                if ($invoiceId > 0) {
                    $invStmt = $pdo->prepare("
                        SELECT i.id, i.case_id, i.amount, i.invoice_number,
                               COALESCE(SUM(p.amount), 0) AS paid_total
                        FROM invoices i
                        LEFT JOIN payments p ON p.invoice_id = i.id
                        WHERE i.id = ?
                        GROUP BY i.id, i.case_id, i.amount, i.invoice_number
                    ");
                    $invStmt->execute([$invoiceId]);
                    $invoiceRow = $invStmt->fetch();

                    if (!$invoiceRow || (int) $invoiceRow['case_id'] !== (int) $caseRow['id']) {
                        $message = 'Selected invoice does not belong to this case.';
                        $messageType = 'danger';
                        goto render_page;
                    }

                    $invoiceBalance = max((float) $invoiceRow['amount'] - (float) $invoiceRow['paid_total'], 0);
                    if ($invoiceBalance <= 0.01) {
                        $invoiceLabel = !empty($invoiceRow['invoice_number'])
                            ? $invoiceRow['invoice_number']
                            : 'This invoice';
                        $message = $invoiceLabel . ' has already been paid.';
                        $messageType = 'warning';
                        goto render_page;
                    }
                    if ($amount > $invoiceBalance + 0.001) {
                        $message = 'Payment exceeds the remaining invoice balance of ' . formatCurrency($invoiceBalance) . '.';
                        $messageType = 'danger';
                        goto render_page;
                    }
                } else {
                    $estimatedFees = isset($caseRow['estimated_fees']) ? (float) $caseRow['estimated_fees'] : 0;
                    if ($estimatedFees > 0) {
                        $sumStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) AS total_paid FROM payments WHERE case_id = ?");
                        $sumStmt->execute([$caseRow['id']]);
                        $paidTotal = (float) $sumStmt->fetchColumn();
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
                }

                try {
                    $insert = $pdo->prepare("
                        INSERT INTO payments (case_id, client_id, invoice_id, amount, method, reference, notes, payment_date, recorded_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $insert->execute([
                        $caseRow['id'],
                        $caseRow['client_id'],
                        $invoiceId > 0 ? $invoiceId : null,
                        $amount,
                        $method,
                        $reference,
                        $notes,
                        $dateObj->format('Y-m-d'),
                        $recordedBy
                    ]);

                    if ($invoiceId > 0) {
                        $checkPaid = $pdo->prepare("
                            SELECT i.amount, COALESCE(SUM(p.amount), 0) AS paid_total
                            FROM invoices i
                            LEFT JOIN payments p ON p.invoice_id = i.id
                            WHERE i.id = ?
                            GROUP BY i.id, i.amount
                        ");
                        $checkPaid->execute([$invoiceId]);
                        $invoicePaid = $checkPaid->fetch();
                        if ($invoicePaid && (float) $invoicePaid['paid_total'] >= (float) $invoicePaid['amount'] - 0.01) {
                            $pdo->prepare("UPDATE invoices SET status = 'paid' WHERE id = ?")->execute([$invoiceId]);
                        }
                    }

                    CaseEvents::trackPaymentAdded($caseRow['id'], [
                        'amount' => $amount,
                        'method' => $method,
                        'reference' => $reference,
                        'invoice_id' => $invoiceId > 0 ? $invoiceId : null,
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

    $isFullyPaid = $estimated > 0 && $balance <= 0.01;

    if (!$isFullyPaid) {
        $selectedAttr = $formData['case_id'] == $caseId ? ' selected' : '';
        $caseOptions .= '<option value="' . $caseId . '"' . $selectedAttr . '>' . htmlspecialchars($caseNumber . ' · ' . $case['title'] . ' (' . $clientName . ')') . '</option>';
    }

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

        $searchBlob = strtolower(
            $caseNumber . ' ' . ($case['title'] ?? '') . ' ' . $clientName . ' '
            . formatCurrency($estimated) . ' ' . formatCurrency($paid) . ' '
            . formatCurrency($balance) . ' ' . $lastPayment
        );

        $outstandingRows .= '
        <tr class="legalpro-admin-list-row" data-search="' . htmlspecialchars($searchBlob, ENT_QUOTES, 'UTF-8') . '">
            <td class="ps-4">
                <div class="d-flex flex-column">
                    <span class="text-sm font-weight-bold mb-0">' . htmlspecialchars($caseNumber . ' · ' . $case['title']) . '</span>
                    <small class="text-muted">' . htmlspecialchars($clientName) . '</small>
                </div>
            </td>
            <td class="text-center text-sm">' . formatCurrency($estimated) . '</td>
            <td class="text-center text-sm text-success font-weight-bold">' . formatCurrency($paid) . '</td>
            <td class="text-center text-sm font-weight-bold text-warning">' . formatCurrency($balance) . '</td>
            <td class="text-end text-xs pe-4">' . ($lastPayment !== '—' ? htmlspecialchars($lastPayment) : '<span class="text-muted">No payments</span>') . '</td>
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
            CONCAT(cl.first_name, ' ', cl.last_name) AS client_name,
            i.invoice_number
        FROM payments p
        LEFT JOIN cases c ON c.id = p.case_id
        LEFT JOIN clients cl ON cl.id = p.client_id
        LEFT JOIN invoices i ON i.id = p.invoice_id
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
        $notesRaw = isset($payment['notes']) ? trim($payment['notes']) : '';
        $notesPreview = $notesRaw !== ''
            ? '<span class="text-xs text-secondary d-inline-block text-truncate payments-notes-cell" title="' . htmlspecialchars($notesRaw) . '">' . htmlspecialchars($notesRaw) . '</span>'
            : '<span class="text-muted">—</span>';

        $invoiceLabel = !empty($payment['invoice_number']) ? $payment['invoice_number'] : '';
        $searchBlob = strtolower(
            $clientName . ' ' . $caseNumber . ' ' . ($payment['case_title'] ?? '') . ' '
            . $invoiceLabel . ' ' . $methodLabel . ' ' . ($payment['payment_date'] ?? '') . ' '
            . formatCurrency($payment['amount']) . ' ' . $notesRaw
        );
        $caseSubtitle = $caseNumber . ' · ' . ($payment['case_title'] ?: 'No case');
        if ($invoiceLabel !== '') {
            $caseSubtitle .= ' · ' . $invoiceLabel;
        }

        $recentPaymentsRows .= '
        <tr class="legalpro-admin-list-row" data-search="' . htmlspecialchars($searchBlob, ENT_QUOTES, 'UTF-8') . '">
            <td class="ps-4">
                <div class="d-flex flex-column">
                    <span class="text-sm font-weight-bold mb-0">' . htmlspecialchars($clientName) . '</span>
                    <small class="text-muted">' . htmlspecialchars($caseSubtitle) . '</small>
                </div>
            </td>
            <td class="text-center text-sm font-weight-bold">' . formatCurrency($payment['amount']) . '</td>
            <td class="text-center"><span class="lp-pill lp-pill--status-default">' . htmlspecialchars($methodLabel) . '</span></td>
            <td class="text-center text-sm">' . htmlspecialchars($payment['payment_date']) . '</td>
            <td class="text-end text-xs pe-4">' . $notesPreview . '</td>
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

require_once __DIR__ . '/../inc/legalpro-icons.php';
$iconStatCollected = legalpro_icon('banknote');
$iconStatOutstanding = legalpro_icon('clock');
$iconStatPaidOff = legalpro_icon('circle-check');
$iconStatInstallments = legalpro_icon('briefcase');

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
    <?php include __DIR__ . '/../inc/admin-portal-head.php'; ?>
    <link href="../assets/css/legalpro-finance-pages.css?v=4" rel="stylesheet" />
    <style>
        .payments-summary-card .card-header {
            padding: 1.25rem 1.5rem 0.75rem;
        }
        .payments-summary-card .card-header h6 {
            margin-bottom: 0;
            font-weight: 700;
        }
        .payments-summary-card .table thead th {
            font-size: 0.65rem;
            letter-spacing: 0.04em;
            padding-top: 0.75rem;
            padding-bottom: 0.75rem;
            background: rgba(248, 249, 250, 0.9);
            border-bottom: 1px solid rgba(0, 0, 0, 0.06);
        }
        .payments-summary-card .table tbody td {
            vertical-align: middle;
            border-bottom: 1px solid rgba(0, 0, 0, 0.04);
        }
        .payments-summary-card .table tbody tr:last-child td {
            border-bottom: 0;
        }
        .payments-notes-cell {
            max-width: 16rem;
        }
    </style>
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-admin-portal legalpro-dashboard-page legalpro-finance-page<?php echo legalpro_portal_theme_body_class(); ?>">
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
            <div class="fin-hero-card">
                <p class="fin-hero-kicker">Finance</p>
                <h4 class="fin-hero-title">Payment intake</h4>
                <p class="fin-hero-sub">Record client payments, track outstanding balances, and link receipts to invoices and cases.</p>
            </div>
            <div class="row">
                <div class="col-xl-3 col-sm-6 mb-xl-0 mb-4">
                    <div class="card dashboard-stat-card">
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
                                    <div class="dashboard-stat-icon-wrap dashboard-stat-icon-wrap--success">{ICON_STAT_COLLECTED}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-sm-6 mb-xl-0 mb-4">
                    <div class="card dashboard-stat-card">
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
                                    <div class="dashboard-stat-icon-wrap dashboard-stat-icon-wrap--warning">{ICON_STAT_OUTSTANDING}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-sm-6 mb-xl-0 mb-4">
                    <div class="card dashboard-stat-card">
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
                                    <div class="dashboard-stat-icon-wrap dashboard-stat-icon-wrap--info">{ICON_STAT_PAID_OFF}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-sm-6">
                    <div class="card dashboard-stat-card">
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
                                    <div class="dashboard-stat-icon-wrap dashboard-stat-icon-wrap--primary">{ICON_STAT_INSTALLMENTS}</div>
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
                                <div class="mb-3" id="invoice-select-wrap" style="display: none;">
                                    <label class="form-label">Apply to Invoice</label>
                                    <select class="form-select" name="invoice_id" id="invoice_id">
                                        <option value="">General payment (not tied to invoice)</option>
                                    </select>
                                    <p class="text-xs text-muted mb-0 mt-1" id="invoice-balance-hint"></p>
                                    <div class="alert alert-warning py-2 px-3 mb-0 mt-2 d-none" id="invoice-paid-alert" role="alert">
                                        <span class="text-sm mb-0" id="invoice-paid-alert-text">This invoice has already been paid.</span>
                                    </div>
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
                                <button type="submit" class="btn btn-dark w-100" id="payment-submit-btn">Save Payment</button>
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
                                        <h5 class="mb-0" id="ledger-fee">{CURRENCY_ZERO}</h5>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="border rounded p-3 text-center mb-3">
                                        <p class="text-xs text-muted mb-1">Paid</p>
                                        <h5 class="mb-0 text-success" id="ledger-paid">{CURRENCY_ZERO}</h5>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="border rounded p-3 text-center mb-3">
                                        <p class="text-xs text-muted mb-1">Balance</p>
                                        <h5 class="mb-0 text-warning" id="ledger-balance">{CURRENCY_ZERO}</h5>
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
            <div class="row mt-4 g-4">
                <div class="col-12">
                    <div class="card payments-summary-card mb-0">
                        <div class="card-header pb-0">
                            <h6>Recent Payments</h6>
                            <p class="text-sm text-muted mb-0">Latest payment activity across all cases.</p>
                        </div>
                        <div class="card-body px-0 pt-2 pb-2">
                            {PAYMENTS_SEARCH}
                            <div class="table-responsive">
                                <table class="table align-items-center mb-0">
                                    <thead>
                                        <tr>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-4">Client / Case</th>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder text-center opacity-7">Amount</th>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder text-center opacity-7">Method</th>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder text-center opacity-7">Date</th>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder text-end opacity-7 pe-4">Notes</th>
                                        </tr>
                                    </thead>
                                    <tbody id="paymentsTableBody">
                                        {RECENT_PAYMENTS}
                                        <tr id="paymentsFilterEmpty" class="d-none">
                                            <td colspan="5" class="text-center text-muted text-sm py-4">No payments match your search.</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-12">
                    <div class="card payments-summary-card mb-0">
                        <div class="card-header pb-0">
                            <h6>Outstanding Balances</h6>
                            <p class="text-sm text-muted mb-0">Track cases still on a payment plan.</p>
                        </div>
                        <div class="card-body px-0 pt-2 pb-2">
                            {OUTSTANDING_SEARCH}
                            <div class="table-responsive">
                                <table class="table align-items-center mb-0">
                                    <thead>
                                        <tr>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-4">Case</th>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder text-center opacity-7">Fee</th>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder text-center opacity-7">Paid</th>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder text-center opacity-7">Balance</th>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder text-end opacity-7 pe-4">Last Payment</th>
                                        </tr>
                                    </thead>
                                    <tbody id="outstandingTableBody">
                                        {OUTSTANDING_ROWS}
                                        <tr id="outstandingFilterEmpty" class="d-none">
                                            <td colspan="5" class="text-center text-muted text-sm py-4">No outstanding balances match your search.</td>
                                        </tr>
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
            var invoiceData = {CASE_INVOICES_JSON};
            var initialInvoiceId = '{FORM_INVOICE_ID}';
            var currencyZero = '{CURRENCY_ZERO}';
            var caseSelect = document.getElementById('case_id');
            var invoiceSelect = document.getElementById('invoice_id');
            var invoiceWrap = document.getElementById('invoice-select-wrap');
            var invoiceHint = document.getElementById('invoice-balance-hint');
            var amountInput = document.querySelector('input[name="amount"]');
            var ledgerSelect = document.getElementById('ledger_case_select');
            var feeEl = document.getElementById('ledger-fee');
            var paidEl = document.getElementById('ledger-paid');
            var balanceEl = document.getElementById('ledger-balance');
            var progressEl = document.getElementById('ledger-progress');
            var statusEl = document.getElementById('ledger-status');
            var lastEl = document.getElementById('ledger-last-payment');
            var labelEl = document.getElementById('selected-case-label');
            var amountTouched = false;
            var paidAlert = document.getElementById('invoice-paid-alert');
            var paidAlertText = document.getElementById('invoice-paid-alert-text');
            var submitBtn = document.getElementById('payment-submit-btn');
            var paymentForm = document.querySelector('form[method="post"]');

            if (amountInput) {
                amountInput.addEventListener('input', function() {
                    amountTouched = true;
                });
            }

            function invoiceIsPaid(invoice) {
                return !!(invoice && invoice.balance_raw <= 0.01);
            }

            function updateInvoicePaidState(invoice) {
                var isPaid = invoiceIsPaid(invoice);
                if (paidAlert) {
                    paidAlert.classList.toggle('d-none', !isPaid);
                }
                if (paidAlertText) {
                    paidAlertText.textContent = isPaid && invoice
                        ? invoice.number + ' has already been paid.'
                        : 'This invoice has already been paid.';
                }
                if (submitBtn) {
                    submitBtn.disabled = isPaid;
                }
                if (amountInput) {
                    amountInput.readOnly = isPaid;
                }
            }

            function findInvoice(caseId, invoiceId) {
                var invoices = invoiceData[caseId] || [];
                for (var i = 0; i < invoices.length; i++) {
                    if (String(invoices[i].id) === String(invoiceId)) {
                        return invoices[i];
                    }
                }
                return null;
            }

            function updateInvoiceHint(invoice) {
                if (!invoiceHint) {
                    return;
                }
                if (!invoice) {
                    invoiceHint.textContent = '';
                    return;
                }
                invoiceHint.textContent = invoice.balance_raw > 0.01
                    ? invoice.number + ' has ' + invoice.balance + ' remaining.'
                    : '';
            }

            function updateInvoiceSelect(caseId, preferredInvoiceId) {
                if (!invoiceSelect || !invoiceWrap) {
                    return;
                }

                var invoices = invoiceData[caseId] || [];
                var unpaidInvoices = invoices.filter(function(invoice) {
                    return invoice.balance_raw > 0.01;
                });

                if (!invoices.length) {
                    invoiceWrap.style.display = 'none';
                    updateInvoiceHint(null);
                    updateInvoicePaidState(null);
                    return;
                }

                invoiceWrap.style.display = 'block';
                invoiceSelect.innerHTML = unpaidInvoices.length > 1
                    ? ''
                    : '<option value="">General payment (not tied to invoice)</option>';
                var firstUnpaidId = '';

                invoices.forEach(function(invoice) {
                    var option = document.createElement('option');
                    option.value = invoice.id;
                    option.textContent = invoice.label;
                    if (invoice.balance_raw > 0.01 && !firstUnpaidId) {
                        firstUnpaidId = String(invoice.id);
                    }
                    invoiceSelect.appendChild(option);
                });

                var targetId = preferredInvoiceId || initialInvoiceId || firstUnpaidId || '';
                if (targetId && findInvoice(caseId, targetId) && findInvoice(caseId, targetId).balance_raw > 0.01) {
                    invoiceSelect.value = targetId;
                } else if (firstUnpaidId) {
                    invoiceSelect.value = firstUnpaidId;
                } else {
                    invoiceSelect.value = '';
                }

                var selectedInvoice = findInvoice(caseId, invoiceSelect.value);
                updateInvoiceHint(selectedInvoice);
                updateInvoicePaidState(selectedInvoice);

                if (!amountTouched && selectedInvoice && selectedInvoice.balance_raw > 0.01 && amountInput) {
                    amountInput.value = selectedInvoice.balance_raw.toFixed(2);
                }
            }

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
                    amountTouched = false;
                    initialInvoiceId = '';
                    updateLedger(this.value, { skipSyncForm: true });
                    updateInvoiceSelect(this.value, '');
                });
            }
            if (invoiceSelect) {
                invoiceSelect.addEventListener('change', function() {
                    var caseId = caseSelect ? caseSelect.value : '';
                    var selectedInvoice = findInvoice(caseId, this.value);
                    updateInvoiceHint(selectedInvoice);
                    updateInvoicePaidState(selectedInvoice);
                    if (!amountTouched && selectedInvoice && selectedInvoice.balance_raw > 0.01 && amountInput) {
                        amountInput.value = selectedInvoice.balance_raw.toFixed(2);
                    }
                });
            }
            if (paymentForm) {
                paymentForm.addEventListener('submit', function(event) {
                    var caseId = caseSelect ? caseSelect.value : '';
                    var selectedInvoice = findInvoice(caseId, invoiceSelect ? invoiceSelect.value : '');
                    if (invoiceIsPaid(selectedInvoice)) {
                        event.preventDefault();
                        updateInvoicePaidState(selectedInvoice);
                    }
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
                updateInvoiceSelect(initialCaseId, initialInvoiceId);
            } else {
                setProgress(0);
            }
        })();
    </script>
    {PAYMENTS_SEARCH_SCRIPT}
    {OUTSTANDING_SEARCH_SCRIPT}
</body>
</html>
HTML;

$paymentsSearchHtml = legalpro_render_admin_list_search('paymentsSearchInput', 'Search payments...');
$paymentsSearchScript = legalpro_admin_list_search_script('paymentsSearchInput', 'paymentsTableBody', 'paymentsFilterEmpty');
$outstandingSearchHtml = legalpro_render_admin_list_search('outstandingSearchInput', 'Search outstanding balances...');
$outstandingSearchScript = legalpro_admin_list_search_script('outstandingSearchInput', 'outstandingTableBody', 'outstandingFilterEmpty');

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
$html = str_replace('{FORM_INVOICE_ID}', htmlspecialchars((string) ($formData['invoice_id'] ?? '')), $html);
$html = str_replace('{FORM_AMOUNT}', htmlspecialchars($formData['amount']), $html);
$html = str_replace('{FORM_REFERENCE}', htmlspecialchars($formData['reference']), $html);
$html = str_replace('{FORM_NOTES}', htmlspecialchars($formData['notes']), $html);
$html = str_replace('{FORM_RECORDED_BY}', htmlspecialchars($formData['recorded_by']), $html);
$html = str_replace('{METHOD_OPTIONS}', $methodsOptions, $html);
$html = str_replace('{PAYMENTS_SEARCH}', $paymentsSearchHtml, $html);
$html = str_replace('{PAYMENTS_SEARCH_SCRIPT}', $paymentsSearchScript, $html);
$html = str_replace('{RECENT_PAYMENTS}', $recentPaymentsRows, $html);
$html = str_replace('{OUTSTANDING_SEARCH}', $outstandingSearchHtml, $html);
$html = str_replace('{OUTSTANDING_SEARCH_SCRIPT}', $outstandingSearchScript, $html);
$html = str_replace('{OUTSTANDING_ROWS}', $outstandingRows, $html);
$html = str_replace('{TOTAL_COLLECTED}', formatCurrency($totalCollected), $html);
$html = str_replace('{TOTAL_OUTSTANDING}', formatCurrency($totalOutstanding), $html);
$html = str_replace('{ACTIVE_PLANS}', $activePaymentPlans, $html);
$html = str_replace('{CASES_PAID_OFF}', $casesPaidOff, $html);
$html = str_replace('{PAST_30}', formatCurrency($paymentsThisMonth), $html);
$html = str_replace('{CASE_DATA_JSON}', json_encode($caseLedger), $html);
$html = str_replace('{CASE_INVOICES_JSON}', json_encode($caseInvoices), $html);
$html = str_replace('{CURRENCY_ZERO}', formatCurrency(0), $html);
$html = str_replace('{ICON_STAT_COLLECTED}', $iconStatCollected, $html);
$html = str_replace('{ICON_STAT_OUTSTANDING}', $iconStatOutstanding, $html);
$html = str_replace('{ICON_STAT_PAID_OFF}', $iconStatPaidOff, $html);
$html = str_replace('{ICON_STAT_INSTALLMENTS}', $iconStatInstallments, $html);

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

