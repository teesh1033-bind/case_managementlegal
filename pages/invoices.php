<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/admin-layout.php';

ensure_invoice_bank_columns($pdo);

$message = '';
$messageType = '';
if (isset($_GET['msg']) && isset($_GET['type'])) {
    $message = urldecode($_GET['msg']);
    $messageType = $_GET['type'];
}

$alterStatements = [
    "ADD COLUMN case_id INT NULL AFTER client_id",
    "ADD COLUMN invoice_number VARCHAR(100) NULL AFTER case_id",
    "ADD COLUMN issue_date DATE NULL AFTER amount",
    "ADD COLUMN due_date DATE NULL AFTER issue_date",
    "ADD COLUMN notes TEXT NULL AFTER due_date",
];

foreach ($alterStatements as $statement) {
    try {
        $pdo->query("ALTER TABLE invoices " . $statement);
    } catch (PDOException $e) {
        if (stripos($e->getMessage(), 'duplicate column') === false) {
            throw $e;
        }
    }
}

try {
    $pdo->query("ALTER TABLE cases ADD COLUMN estimated_fees DECIMAL(12,2) DEFAULT 0.00 AFTER category");
} catch (PDOException $e) {
    if (stripos($e->getMessage(), 'duplicate column') === false) {
        throw $e;
    }
}

function getNextInvoiceNumber(PDO $pdo): string
{
    $maxNum = 0;
    try {
        $stmt = $pdo->query("SELECT invoice_number FROM invoices WHERE invoice_number IS NOT NULL AND invoice_number != ''");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $num = $row['invoice_number'];
            if (preg_match('/^INV\s+(\d+)/i', $num, $matches)) {
                $maxNum = max($maxNum, (int)$matches[1]);
            } elseif (preg_match('/^Invoice\s+(\d+)/i', $num, $matches)) {
                $maxNum = max($maxNum, (int)$matches[1]);
            }
        }
    } catch (PDOException $e) {
        // default to INV 001
    }
    return 'INV ' . str_pad((string)($maxNum + 1), 3, '0', STR_PAD_LEFT);
}

$statusOptions = [
    'draft' => 'Draft',
    'sent' => 'Sent',
    'paid' => 'Paid',
    'overdue' => 'Overdue'
];

$selectedCaseId = isset($_GET['case_id']) ? (int) $_GET['case_id'] : 0;
$selectedClientId = isset($_GET['client_id']) ? (int) $_GET['client_id'] : 0;
$prefillAmount = isset($_GET['amount']) ? (float) $_GET['amount'] : 0;

$formData = [
    'invoice_id' => '',
    'invoice_number' => getNextInvoiceNumber($pdo),
    'client_id' => $selectedClientId ?: '',
    'case_id' => $selectedCaseId ?: '',
    'amount' => $prefillAmount > 0 ? $prefillAmount : '',
    'tax_rate' => 0,
    'issue_date' => date('Y-m-d'),
    'due_date' => date('Y-m-d', strtotime('+14 days')),
    'status' => 'sent',
    'notes' => '',
    'bank_account_slot' => getDefaultBankAccountSlot(),
    'payment_terms' => getDefaultPaymentTerms(),
    'payment_instructions' => getDefaultPaymentInstructions(),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formType = isset($_POST['form_type']) ? $_POST['form_type'] : '';
    if ($formType === 'save') {
        $invoiceId = isset($_POST['invoice_id']) ? (int)$_POST['invoice_id'] : 0;
        $clientId = isset($_POST['client_id']) ? (int)$_POST['client_id'] : 0;
        $caseId = isset($_POST['case_id']) ? (int)$_POST['case_id'] : 0;
        $amount = isset($_POST['amount']) ? (float)$_POST['amount'] : 0;
        $issueDate = isset($_POST['issue_date']) ? $_POST['issue_date'] : '';
        $dueDate = isset($_POST['due_date']) ? $_POST['due_date'] : '';
        $status = isset($_POST['status']) ? strtolower(trim($_POST['status'])) : 'draft';
        $notes = trim(isset($_POST['notes']) ? $_POST['notes'] : '');
        $bankFields = bank_account_fields_from_post($_POST);
        $taxRate = isset($_POST['tax_rate']) ? max(0, (float) $_POST['tax_rate']) : 0;

        $formData = [
            'invoice_id' => $invoiceId ?: '',
            'client_id' => $clientId,
            'case_id' => $caseId,
            'amount' => $amount,
            'tax_rate' => $taxRate,
            'issue_date' => $issueDate,
            'due_date' => $dueDate,
            'status' => $status,
            'notes' => $notes,
            'bank_account_slot' => $bankFields['bank_account_slot'],
            'payment_terms' => $bankFields['payment_terms'],
            'payment_instructions' => $bankFields['payment_instructions'],
        ];

        $previousStatus = null;
        $invoiceNumber = $invoiceId ? '' : getNextInvoiceNumber($pdo);
        if ($invoiceId) {
            try {
                $stmt = $pdo->prepare("SELECT status, invoice_number FROM invoices WHERE id = ?");
                $stmt->execute([$invoiceId]);
                $existingInvoice = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($existingInvoice) {
                    $previousStatus = $existingInvoice['status'];
                    $invoiceNumber = $existingInvoice['invoice_number'];
                }
            } catch (PDOException $e) {
                // ignore, fall back to default behavior
            }
        }

        $formData['invoice_number'] = $invoiceNumber;

        if (empty($clientId) || $amount <= 0 || empty($issueDate)) {
            $message = 'Client, issue date, and a positive amount are required.';
            $messageType = 'danger';
        } elseif (!isset($statusOptions[$status])) {
            $message = 'Invalid status selected.';
            $messageType = 'danger';
        } elseif ($invoiceId && empty($invoiceNumber)) {
            $message = 'Invoice not found.';
            $messageType = 'danger';
        } else {
            try {
                $paymentNotice = '';
                if ($invoiceId) {
                    $stmt = $pdo->prepare("
                        UPDATE invoices 
                        SET client_id = ?, case_id = ?, amount = ?, tax_rate = ?, status = ?, issue_date = ?, due_date = ?, notes = ?,
                            bank_account_slot = ?, payment_terms = ?, payment_instructions = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $clientId, $caseId ?: null, $amount, $taxRate, $status, $issueDate ?: null, $dueDate ?: null, $notes,
                        $bankFields['bank_account_slot'], $bankFields['payment_terms'] ?: null, $bankFields['payment_instructions'] ?: null,
                        $invoiceId,
                    ]);
                    $msg = 'Invoice updated successfully.';
                } else {
                    $invoiceNumber = getNextInvoiceNumber($pdo);
                    $stmt = $pdo->prepare("
                        INSERT INTO invoices (invoice_number, client_id, case_id, amount, tax_rate, status, issue_date, due_date, notes, bank_account_slot, payment_terms, payment_instructions)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $invoiceNumber, $clientId, $caseId ?: null, $amount, $taxRate, $status, $issueDate ?: null, $dueDate ?: null, $notes,
                        $bankFields['bank_account_slot'], $bankFields['payment_terms'] ?: null, $bankFields['payment_instructions'] ?: null,
                    ]);
                    $msg = 'Invoice created successfully.';
                    $invoiceId = (int)$pdo->lastInsertId();
                }

                if ($status === 'paid' && $clientId && $caseId) {
                    try {
                        $checkPayment = $pdo->prepare("SELECT id FROM payments WHERE invoice_id = ? LIMIT 1");
                        $checkPayment->execute([$invoiceId]);
                        $existingPaymentId = $checkPayment->fetchColumn();
                        if (!$existingPaymentId) {
                            $paymentStmt = $pdo->prepare("
                                INSERT INTO payments (case_id, client_id, invoice_id, amount, method, reference, notes, payment_date, recorded_by)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                            ");
                            $paymentStmt->execute([
                                $caseId,
                                $clientId,
                                $invoiceId,
                                $amount,
                                'invoice',
                                $invoiceNumber,
                                'Auto-generated from invoice ' . $invoiceNumber,
                                $issueDate ?: date('Y-m-d'),
                                'system'
                            ]);
                            $paymentNotice = ' Linked payment recorded.';
                        }
                    } catch (PDOException $e) {
                        $paymentNotice = ' (Payment sync failed: ' . htmlspecialchars($e->getMessage()) . ')';
                    }
                } elseif ($status === 'paid' && (!$caseId || !$clientId)) {
                    $paymentNotice = ' (Payment not recorded: missing client or case.)';
                }

                header('Location: invoices.php?msg=' . urlencode($msg . $paymentNotice) . '&type=success');
                exit;
            } catch (PDOException $e) {
                $message = 'Error saving invoice: ' . htmlspecialchars($e->getMessage());
                $messageType = 'danger';
            }
        }
    } elseif ($formType === 'delete') {
        $invoiceId = isset($_POST['invoice_id']) ? (int)$_POST['invoice_id'] : 0;
        if ($invoiceId > 0) {
            try {
                // Check if invoice has any payments before deleting
                $paymentCheck = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE invoice_id = ?");
                $paymentCheck->execute([$invoiceId]);
                $paymentCount = $paymentCheck->fetchColumn();

                if ($paymentCount > 0) {
                    $message = 'Cannot delete invoice that has associated payments. Please remove payments first.';
                    $messageType = 'danger';
                } else {
                    $stmt = $pdo->prepare("DELETE FROM invoices WHERE id = ?");
                    $stmt->execute([$invoiceId]);
                    $msg = 'Invoice deleted successfully.';
                    header('Location: invoices.php?msg=' . urlencode($msg) . '&type=success');
                    exit;
                }
            } catch (PDOException $e) {
                $message = 'Error deleting invoice: ' . htmlspecialchars($e->getMessage());
                $messageType = 'danger';
            }
        } else {
            $message = 'Invalid invoice ID.';
            $messageType = 'danger';
        }
    }
}

if (isset($_GET['id']) && ctype_digit($_GET['id'])) {
    $editId = (int)$_GET['id'];
    try {
        $stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
        $stmt->execute([$editId]);
        $invoice = $stmt->fetch();
        if ($invoice) {
            $formData = [
                'invoice_id' => $invoice['id'],
                'invoice_number' => $invoice['invoice_number'],
                'client_id' => $invoice['client_id'],
                'case_id' => $invoice['case_id'],
                'amount' => $invoice['amount'],
                'tax_rate' => $invoice['tax_rate'] ?? 0,
                'issue_date' => $invoice['issue_date'],
                'due_date' => $invoice['due_date'],
                'status' => $invoice['status'],
                'notes' => $invoice['notes'],
                'bank_account_slot' => (int) ($invoice['bank_account_slot'] ?? getDefaultBankAccountSlot()),
                'payment_terms' => (string) ($invoice['payment_terms'] ?? getDefaultPaymentTerms()),
                'payment_instructions' => (string) ($invoice['payment_instructions'] ?? getDefaultPaymentInstructions()),
            ];
        } else {
            $message = 'Invoice not found.';
            $messageType = 'danger';
        }
    } catch (PDOException $e) {
        $message = 'Unable to load invoice: ' . htmlspecialchars($e->getMessage());
        $messageType = 'danger';
    }
} elseif ($selectedCaseId > 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    try {
        $stmt = $pdo->prepare("
            SELECT c.client_id, COALESCE(c.estimated_fees, 0) AS estimated_fees,
                   COALESCE(SUM(p.amount), 0) AS paid_total
            FROM cases c
            LEFT JOIN payments p ON p.case_id = c.id
            WHERE c.id = ?
            GROUP BY c.id, c.client_id, c.estimated_fees
        ");
        $stmt->execute([$selectedCaseId]);
        $casePrefill = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($casePrefill) {
            $formData['case_id'] = $selectedCaseId;
            if ($selectedClientId <= 0 && !empty($casePrefill['client_id'])) {
                $formData['client_id'] = (int) $casePrefill['client_id'];
            }
            if ($prefillAmount <= 0) {
                $remaining = max((float) $casePrefill['estimated_fees'] - (float) $casePrefill['paid_total'], 0);
                if ($remaining > 0) {
                    $formData['amount'] = $remaining;
                }
            }
        }
    } catch (PDOException $e) {
        // Continue with basic case/client prefill from query string
    }
}

try {
    $clients = $pdo->query("SELECT id, CONCAT(first_name, ' ', last_name) AS name FROM clients ORDER BY first_name, last_name")->fetchAll();
} catch (PDOException $e) {
    $clients = [];
}

$caseFinancialData = [];
try {
    $casesList = $pdo->query("
        SELECT
            c.id,
            c.client_id,
            c.title,
            c.status,
            COALESCE(c.estimated_fees, 0) AS estimated_fees,
            CONCAT('C-', LPAD(c.id, 4, '0')) AS case_number,
            CONCAT(cl.first_name, ' ', cl.last_name) AS client_name,
            COALESCE(SUM(p.amount), 0) AS paid_total
        FROM cases c
        LEFT JOIN clients cl ON cl.id = c.client_id
        LEFT JOIN payments p ON p.case_id = c.id
        GROUP BY c.id, c.client_id, c.title, c.status, c.estimated_fees, cl.first_name, cl.last_name
        ORDER BY c.created_at DESC
    ")->fetchAll();
} catch (PDOException $e) {
    $casesList = [];
}

$clientOptions = '<option value="">Select client</option>';
foreach ($clients as $client) {
    $selected = $formData['client_id'] == $client['id'] ? ' selected' : '';
    $clientOptions .= '<option value="' . (int)$client['id'] . '"' . $selected . '>' . htmlspecialchars($client['name']) . '</option>';
}

$caseOptions = '<option value="">Linked case (optional)</option>';
foreach ($casesList as $caseRow) {
    $caseId = (int) $caseRow['id'];
    $estimated = (float) ($caseRow['estimated_fees'] ?? 0);
    $paid = (float) ($caseRow['paid_total'] ?? 0);
    $remaining = max($estimated - $paid, 0);

    $selected = $formData['case_id'] == $caseId ? ' selected' : '';
    $clientIdAttr = !empty($caseRow['client_id']) ? ' data-client-id="' . (int) $caseRow['client_id'] . '"' : '';
    $caseOptions .= '<option value="' . $caseId . '"' . $selected . $clientIdAttr . '>'
        . htmlspecialchars($caseRow['case_number'] . ' · ' . $caseRow['title'] . ' (' . $caseRow['client_name'] . ')')
        . '</option>';

    $caseFinancialData[$caseId] = [
        'case_number' => $caseRow['case_number'],
        'title' => $caseRow['title'],
        'client' => $caseRow['client_name'] ?: 'Unknown Client',
        'total' => formatCurrency($estimated),
        'total_raw' => $estimated,
        'paid' => formatCurrency($paid),
        'paid_raw' => $paid,
        'remaining' => formatCurrency($remaining),
        'remaining_raw' => $remaining,
    ];
}

try {
    $stmt = $pdo->query("
        SELECT 
            inv.*,
            CONCAT(cl.first_name, ' ', cl.last_name) AS client_name,
            c.title AS case_title
        FROM invoices inv
        LEFT JOIN clients cl ON cl.id = inv.client_id
        LEFT JOIN cases c ON c.id = inv.case_id
        ORDER BY COALESCE(inv.issue_date, inv.created_at) DESC, inv.id DESC
    ");
    $invoices = $stmt->fetchAll();
} catch (PDOException $e) {
    $invoices = [];
}

$invoiceRows = '';
if (empty($invoices)) {
    $invoiceRows = '<tr><td colspan="7" class="text-center text-muted py-4">No invoices recorded yet.</td></tr>';
} else {
    foreach ($invoices as $invoice) {
        $statusBadge = legalpro_invoice_status_badge((string) ($invoice['status'] ?? 'draft'));
        $searchBlob = strtolower(
            ($invoice['invoice_number'] ?? '') . ' '
            . ($invoice['client_name'] ?? '') . ' '
            . ($invoice['case_title'] ?? '') . ' '
            . ($invoice['status'] ?? '') . ' '
            . formatCurrency($invoice['amount'])
        );
        $invoiceRows .= '
        <tr class="legalpro-admin-list-row" data-search="' . htmlspecialchars($searchBlob, ENT_QUOTES, 'UTF-8') . '">
            <td class="align-middle">
                <div class="d-flex flex-column">
                    <strong>' . htmlspecialchars($invoice['invoice_number']) . '</strong>
                    <small class="text-muted">' . ($invoice['issue_date'] ? htmlspecialchars(date('d M Y', strtotime($invoice['issue_date']))) : 'N/A') . '</small>
                </div>
            </td>
            <td class="align-middle">
                <p class="text-sm mb-0">' . htmlspecialchars($invoice['client_name'] ?: 'Client') . '</p>
                <p class="text-xs text-muted mb-0">' . htmlspecialchars($invoice['case_title'] ?: 'No case linked') . '</p>
            </td>
            <td class="align-middle text-center">' . htmlspecialchars(formatCurrency($invoice['amount'])) . '</td>
            <td class="align-middle text-center">' . $statusBadge . '</td>
            <td class="align-middle text-center">' . ($invoice['due_date'] ? htmlspecialchars(date('d M Y', strtotime($invoice['due_date']))) : 'N/A') . '</td>
            <td class="align-middle text-end">
                <div class="legalpro-admin-list-row__actions">
                    <a href="invoices.php?id=' . (int)$invoice['id'] . '" class="btn btn-sm btn-dark mb-0" title="Edit Invoice">Edit</a>
                    <a href="invoice-download.php?id=' . (int)$invoice['id'] . '" class="btn btn-sm btn-secondary mb-0" title="Download invoice PDF" target="_blank">Download PDF</a>
                    <form method="post" onsubmit="return confirm(\'Are you sure you want to delete invoice ' . htmlspecialchars($invoice['invoice_number']) . '? This action cannot be undone.\');">
                        <input type="hidden" name="form_type" value="delete">
                        <input type="hidden" name="invoice_id" value="' . (int)$invoice['id'] . '">
                        <button class="btn btn-sm btn-danger mb-0" type="submit" title="Delete Invoice">Delete</button>
                    </form>
                </div>
            </td>
        </tr>';
    }
}

$statusOptionsHtml = '';
foreach ($statusOptions as $value => $label) {
    $selected = strtolower($formData['status']) === $value ? ' selected' : '';
    $statusOptionsHtml .= '<option value="' . htmlspecialchars($value) . '"' . $selected . '>' . htmlspecialchars($label) . '</option>';
}

$messageHtml = '';
if (!empty($message)) {
    $messageHtml = '<div class="alert alert-' . htmlspecialchars($messageType ? $messageType : 'info') . ' alert-dismissible fade show" role="alert">
        ' . htmlspecialchars($message) . '
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>';
}

$formTitle = $formData['invoice_id'] ? 'Edit Invoice' : 'Create Invoice';
$formButtonLabel = $formData['invoice_id'] ? 'Update Invoice' : 'Create Invoice';
$invoiceNumberHint = $formData['invoice_id']
    ? ''
    : '<small class="text-muted">Assigned automatically when you save.</small>';
$invoiceNumberField = '<input type="text" class="form-control bg-light" value="' . htmlspecialchars($formData['invoice_number']) . '" readonly tabindex="-1">' . $invoiceNumberHint;

$html = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="apple-touch-icon" sizes="76x76" href="../assets/img/apple-icon.png">
    <link rel="icon" type="image/png" href="../assets/img/favicon.png">
    <title>LegalPro · Invoices</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-icons.css" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-svg.css" rel="stylesheet" />
    <script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
    <link id="pagestyle" href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
<link href="../assets/css/app-font-montserrat.css?v=1" rel="stylesheet" />
    <?php include __DIR__ . '/../inc/admin-portal-head.php'; ?>
    <link href="../assets/css/legalpro-finance-pages.css?v=6" rel="stylesheet" />
    <?php echo legalpro_bank_accounts_stylesheet_tag(); ?>
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-admin-portal legalpro-finance-page<?php echo legalpro_portal_theme_body_class(); ?>">
    <div class="min-height-300 bg-legalpro-admin position-absolute w-100"></div>
    <aside class="sidenav bg-white navbar navbar-vertical navbar-expand-xs border-0 border-radius-xl my-3 fixed-start ms-4" id="sidenav-main"></aside>
    <main class="main-content position-relative border-radius-lg">
        <nav class="navbar navbar-main navbar-expand-lg px-0 mx-4 shadow-none border-radius-xl" id="navbarBlur" data-scroll="false">
            <div class="container-fluid py-1 px-3">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb bg-transparent mb-0 pb-0 pt-1 px-0 me-sm-6 me-5">
                        <li class="breadcrumb-item text-sm"><a class="opacity-5 text-white" href="javascript:;">Finance</a></li>
                        <li class="breadcrumb-item text-sm text-white active" aria-current="page">Invoices</li>
                    </ol>
                    <h6 class="font-weight-bolder text-white mb-0">Invoices</h6>
                </nav>
            </div>
        </nav>
        <div class="container-fluid py-4">
            {MESSAGE}
            <div class="fin-hero-card">
                <p class="fin-hero-kicker">Finance</p>
                <h4 class="fin-hero-title">Invoices</h4>
                <p class="fin-hero-sub">Generate clean invoices with linked cases and clients, then track status from draft to paid.</p>
            </div>
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header pb-0">
                            <h6 class="mb-0">{FORM_TITLE}</h6>
                            <p class="text-sm text-muted mb-0">Generate clean invoices with linked cases and clients.</p>
                        </div>
                        <div class="card-body pt-0">
                            <form method="post" autocomplete="off">
                                <input type="hidden" name="form_type" value="save">
                                <input type="hidden" name="invoice_id" value="{FORM_INVOICE_ID}">
                                <div class="mb-3">
                                    <label class="form-label">Invoice Number</label>
                                    {INVOICE_NUMBER_FIELD}
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Client</label>
                                    <select class="form-select" name="client_id" id="invoice_client_id" required>
                                        {CLIENT_OPTIONS}
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Linked Case (optional)</label>
                                    <select class="form-select" name="case_id" id="invoice_case_id">
                                        {CASE_OPTIONS}
                                    </select>
                                </div>
                                <div id="case-amount-summary" class="mb-3" style="display: none;">
                                    <div class="legalpro-form-panel border border-radius-lg p-3">
                                        <p class="text-xs text-uppercase text-muted font-weight-bold mb-2 mb-md-0" id="case-amount-summary-label">Case payment summary</p>
                                        <div class="row g-3 mt-0">
                                            <div class="col-sm-6">
                                                <div class="text-center text-sm-start">
                                                    <span class="text-xs text-muted d-block">Total amount for this case</span>
                                                    <span class="h6 mb-0 font-weight-bold" id="case-total-amount">{CURRENCY_ZERO}</span>
                                                </div>
                                            </div>
                                            <div class="col-sm-6">
                                                <div class="text-center text-sm-start">
                                                    <span class="text-xs text-muted d-block">Remaining to pay</span>
                                                    <span class="h6 mb-0 font-weight-bold text-warning" id="case-remaining-amount">{CURRENCY_ZERO}</span>
                                                </div>
                                            </div>
                                        </div>
                                        <p class="text-xs text-muted mb-0 mt-2" id="case-amount-summary-paid"></p>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Issue Date</label>
                                        <input type="date" class="form-control" name="issue_date" value="{FORM_ISSUE_DATE}">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Due Date</label>
                                        <input type="date" class="form-control" name="due_date" value="{FORM_DUE_DATE}">
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Amount</label>
                                        <input type="number" step="0.01" min="0" class="form-control" name="amount" value="{FORM_AMOUNT}" placeholder="0.00" required>
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label">VAT Rate (%)</label>
                                        <input type="number" step="0.01" min="0" class="form-control" name="tax_rate" value="{FORM_TAX_RATE}">
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label">Status</label>
                                        <select class="form-select" name="status">
                                            {STATUS_OPTIONS}
                                        </select>
                                    </div>
                                </div>
                                {INVOICE_BANK_SECTION}
                                <div class="mb-3">
                                    <label class="form-label">Notes</label>
                                    <textarea class="form-control" rows="3" name="notes" placeholder="Additional notes...">{FORM_NOTES}</textarea>
                                </div>
                                <button class="btn btn-dark w-100">{FORM_BUTTON}</button>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="col-12 mt-4">
                    <div class="card">
                        <div class="card-header pb-0">
                            <h6 class="mb-0">Invoice List</h6>
                        </div>
                        <div class="card-body px-0 pt-0 pb-2">
                            {INVOICES_SEARCH}
                            <div class="lp-admin-table-paginate" data-lp-admin-paginate data-lp-per-page="10" data-lp-row=".legalpro-admin-list-row">
                            <div class="table-responsive">
                                <table class="table align-items-center mb-0">
                                    <thead>
                                        <tr>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Invoice</th>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Client / Case</th>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder text-center opacity-7">Amount</th>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder text-center opacity-7">Status</th>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder text-center opacity-7">Due</th>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder text-end opacity-7">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody id="invoicesTableBody">
                                        {INVOICE_ROWS}
                                        <tr id="invoicesFilterEmpty" class="d-none">
                                            <td colspan="6" class="text-center text-muted text-sm py-4">No invoices match your search.</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <nav class="lp-admin-pagination" data-lp-pagination-nav aria-label="Invoices pagination" hidden><p class="lp-admin-pagination__info" data-lp-range></p><div class="lp-admin-pagination__controls" data-lp-pages></div></nav>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <footer class="footer pt-3">
                <div class="container-fluid">
                    <div class="row align-items-center justify-content-lg-between">
                        <div class="col-lg-6 mb-lg-0 mb-4">
                            <div class="text-center text-sm text-muted text-lg-start">
                                {COPYRIGHT_LINE}
                            </div>
                        </div>
                    </div>
                </div>
            </footer>
        </div>
    </main>
    <script src="../assets/js/core/popper.min.js"></script>
    <script src="../assets/js/core/bootstrap.min.js"></script>
    <script src="../assets/js/plugins/perfect-scrollbar.min.js"></script>
    <script src="../assets/js/plugins/smooth-scrollbar.min.js"></script>
    <script src="../assets/js/argon-dashboard.min.js?v=2.1.0"></script>
    <script src="../assets/js/spa-nav.js"></script>
    <script>
        (function() {
            var caseFinancialData = {CASE_FINANCIAL_JSON};
            var bankAccountsData = {BANK_ACCOUNTS_JSON};
            var currencyZero = '{CURRENCY_ZERO}';
            var caseSelect = document.getElementById('invoice_case_id');
            var clientSelect = document.getElementById('invoice_client_id');
            var summaryBox = document.getElementById('case-amount-summary');
            var summaryLabel = document.getElementById('case-amount-summary-label');
            var totalEl = document.getElementById('case-total-amount');
            var remainingEl = document.getElementById('case-remaining-amount');
            var paidNoteEl = document.getElementById('case-amount-summary-paid');

            function updateCaseAmountSummary(caseId) {
                if (!summaryBox || !totalEl || !remainingEl) {
                    return;
                }
                var data = caseId ? caseFinancialData[caseId] : null;
                if (!data) {
                    summaryBox.style.display = 'none';
                    return;
                }
                summaryBox.style.display = 'block';
                totalEl.textContent = data.total;
                remainingEl.textContent = data.remaining;
                if (parseFloat(data.remaining_raw || 0) <= 0.01 && parseFloat(data.total_raw || 0) > 0) {
                    remainingEl.classList.remove('text-warning');
                    remainingEl.classList.add('text-success');
                } else {
                    remainingEl.classList.remove('text-success');
                    remainingEl.classList.add('text-warning');
                }
                if (summaryLabel) {
                    summaryLabel.textContent = data.case_number + ' · ' + data.title;
                }
                if (paidNoteEl) {
                    paidNoteEl.textContent = 'Paid so far: ' + data.paid
                        + (parseFloat(data.total_raw || 0) > 0
                            ? ' (' + Math.min(100, Math.round((parseFloat(data.paid_raw || 0) / parseFloat(data.total_raw)) * 100)) + '% of total)'
                            : '');
                }
            }

            function syncInvoiceCasePrefill() {
                if (!caseSelect || !caseSelect.value) {
                    return;
                }
                var selectedOption = caseSelect.options[caseSelect.selectedIndex];
                if (clientSelect && selectedOption && selectedOption.getAttribute('data-client-id')) {
                    clientSelect.value = selectedOption.getAttribute('data-client-id');
                }
                updateCaseAmountSummary(caseSelect.value);
            }

            if (caseSelect) {
                caseSelect.addEventListener('change', syncInvoiceCasePrefill);
                syncInvoiceCasePrefill();
            }

            var bankSelect = document.getElementById('invoice_bank_account_slot');
            var bankPreview = document.getElementById('invoice-bank-preview');
            var bankPreviewKicker = document.getElementById('invoice-bank-preview-kicker');
            var bankPreviewValue = document.getElementById('invoice-bank-preview-value');
            var bankPreviewMeta = document.getElementById('invoice-bank-preview-meta');
            function renderBankPreview() {
                if (!bankSelect || !bankPreview) return;
                var account = bankAccountsData[bankSelect.value];
                if (!account || account.configured !== '1') {
                    bankPreview.hidden = true;
                    return;
                }
                bankPreview.hidden = false;
                if (bankPreviewKicker) bankPreviewKicker.textContent = 'Account number';
                if (bankPreviewValue) bankPreviewValue.textContent = account.account_number || '—';
                if (bankPreviewMeta) {
                    var parts = [];
                    if (account.account_name) parts.push(account.account_name);
                    if (account.bank_name) parts.push(account.bank_name);
                    if (account.sort_code) parts.push('Sort code ' + account.sort_code);
                    bankPreviewMeta.textContent = parts.join(' · ');
                }
            }
            if (bankSelect) {
                bankSelect.addEventListener('change', renderBankPreview);
                renderBankPreview();
            }
        })();
    </script>
    {INVOICES_SEARCH_SCRIPT}
</body>
</html>
HTML;

$invoicesSearchHtml = legalpro_render_admin_featured_list_search(
    'invoicesSearchInput',
    'Search invoices',
    'Search by invoice number, client, case, amount, or status…'
);
$invoicesSearchScript = legalpro_admin_list_search_script('invoicesSearchInput', 'invoicesTableBody', 'invoicesFilterEmpty');

$invoiceBankSectionHtml = legalpro_render_invoice_bank_section(
    (int) $formData['bank_account_slot'],
    (string) ($formData['payment_terms'] ?? ''),
    (string) ($formData['payment_instructions'] ?? '')
);

$html = str_replace('{MESSAGE}', $messageHtml, $html);
$html = str_replace('{FORM_TITLE}', htmlspecialchars($formTitle), $html);
$html = str_replace('{FORM_BUTTON}', htmlspecialchars($formButtonLabel), $html);
$html = str_replace('{FORM_INVOICE_ID}', htmlspecialchars($formData['invoice_id']), $html);
$html = str_replace('{INVOICE_NUMBER_FIELD}', $invoiceNumberField, $html);
$html = str_replace('{CLIENT_OPTIONS}', $clientOptions, $html);
$html = str_replace('{CASE_OPTIONS}', $caseOptions, $html);
$html = str_replace('{FORM_AMOUNT}', htmlspecialchars($formData['amount']), $html);
$html = str_replace('{FORM_TAX_RATE}', htmlspecialchars((string) ($formData['tax_rate'] ?? 0)), $html);
$html = str_replace('{FORM_PAYMENT_TERMS}', htmlspecialchars((string) ($formData['payment_terms'] ?? '')), $html);
$html = str_replace('{FORM_PAYMENT_INSTRUCTIONS}', htmlspecialchars((string) ($formData['payment_instructions'] ?? '')), $html);
$html = str_replace('{INVOICE_BANK_SECTION}', $invoiceBankSectionHtml, $html);
$html = str_replace('{BANK_ACCOUNTS_JSON}', json_encode(legalpro_bank_accounts_json_for_js()), $html);
$html = str_replace('{FORM_ISSUE_DATE}', htmlspecialchars($formData['issue_date']), $html);
$html = str_replace('{FORM_DUE_DATE}', htmlspecialchars($formData['due_date']), $html);
$html = str_replace('{FORM_NOTES}', htmlspecialchars($formData['notes']), $html);
$html = str_replace('{STATUS_OPTIONS}', $statusOptionsHtml, $html);
$html = str_replace('{INVOICES_SEARCH}', $invoicesSearchHtml, $html);
$html = str_replace('{INVOICES_SEARCH_SCRIPT}', $invoicesSearchScript, $html);
$html = str_replace('{INVOICE_ROWS}', $invoiceRows, $html);
$html = str_replace('{CASE_FINANCIAL_JSON}', json_encode($caseFinancialData), $html);
$html = str_replace('{CURRENCY_ZERO}', formatCurrency(0), $html);

$html = preg_replace('/href="([^"\']+)\.html"/i', 'href="$1.php"', $html);

ob_start();
include __DIR__ . '/../inc/menunav.php';
$sidebar = ob_get_clean();
$html = preg_replace('/<aside[\s\S]*?<\/aside>/', $sidebar, $html, 1);

ob_start();
include __DIR__ . '/../inc/footer.php';
$footer = ob_get_clean();
$html = preg_replace('/<\/body>\s*<\/html>$/i', $footer . "\n</body>\n</html>", $html);

echo legalpro_apply_copyright_line($html);

