<?php
require_once __DIR__ . '/../inc/db.php';

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

$statusOptions = [
    'draft' => 'Draft',
    'sent' => 'Sent',
    'paid' => 'Paid',
    'overdue' => 'Overdue'
];

// Generate next invoice number
try {
    $stmt = $pdo->query("SELECT MAX(CAST(SUBSTRING(invoice_number, 9) AS UNSIGNED)) as max_num FROM invoices WHERE invoice_number LIKE 'Invoice %'");
    $result = $stmt->fetch();
    $nextNumber = (isset($result['max_num']) ? $result['max_num'] : 0) + 1;
} catch (PDOException $e) {
    $nextNumber = 1;
}

$formData = [
    'invoice_id' => '',
    'invoice_number' => 'Invoice ' . $nextNumber,
    'client_id' => '',
    'case_id' => '',
    'amount' => '',
    'issue_date' => date('Y-m-d'),
    'due_date' => date('Y-m-d', strtotime('+14 days')),
    'status' => 'draft',
    'notes' => ''
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formType = isset($_POST['form_type']) ? $_POST['form_type'] : '';
    if ($formType === 'save') {
        $invoiceId = isset($_POST['invoice_id']) ? (int)$_POST['invoice_id'] : 0;
        $invoiceNumber = trim(isset($_POST['invoice_number']) ? $_POST['invoice_number'] : '');
        $clientId = isset($_POST['client_id']) ? (int)$_POST['client_id'] : 0;
        $caseId = isset($_POST['case_id']) ? (int)$_POST['case_id'] : 0;
        $amount = isset($_POST['amount']) ? (float)$_POST['amount'] : 0;
        $issueDate = isset($_POST['issue_date']) ? $_POST['issue_date'] : '';
        $dueDate = isset($_POST['due_date']) ? $_POST['due_date'] : '';
        $status = isset($_POST['status']) ? strtolower(trim($_POST['status'])) : 'draft';
        $notes = trim(isset($_POST['notes']) ? $_POST['notes'] : '');

        $formData = [
            'invoice_id' => $invoiceId ?: '',
            'invoice_number' => $invoiceNumber,
            'client_id' => $clientId,
            'case_id' => $caseId,
            'amount' => $amount,
            'issue_date' => $issueDate,
            'due_date' => $dueDate,
            'status' => $status,
            'notes' => $notes
        ];

        $previousStatus = null;
        if ($invoiceId) {
            try {
                $stmt = $pdo->prepare("SELECT status FROM invoices WHERE id = ?");
                $stmt->execute([$invoiceId]);
                $previousStatus = $stmt->fetchColumn();
            } catch (PDOException $e) {
                // ignore, fall back to default behavior
            }
        }

        if (empty($invoiceNumber) || empty($clientId) || $amount <= 0 || empty($issueDate)) {
            $message = 'Invoice number, client, issue date, and a positive amount are required.';
            $messageType = 'danger';
        } elseif (!isset($statusOptions[$status])) {
            $message = 'Invalid status selected.';
            $messageType = 'danger';
        } else {
            try {
                $paymentNotice = '';
                if ($invoiceId) {
                    $stmt = $pdo->prepare("
                        UPDATE invoices 
                        SET invoice_number = ?, client_id = ?, case_id = ?, amount = ?, status = ?, issue_date = ?, due_date = ?, notes = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$invoiceNumber, $clientId, $caseId ?: null, $amount, $status, $issueDate ?: null, $dueDate ?: null, $notes, $invoiceId]);
                    $msg = 'Invoice updated successfully.';
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO invoices (invoice_number, client_id, case_id, amount, status, issue_date, due_date, notes)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$invoiceNumber, $clientId, $caseId ?: null, $amount, $status, $issueDate ?: null, $dueDate ?: null, $notes]);
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
                'issue_date' => $invoice['issue_date'],
                'due_date' => $invoice['due_date'],
                'status' => $invoice['status'],
                'notes' => $invoice['notes']
            ];
        } else {
            $message = 'Invoice not found.';
            $messageType = 'danger';
        }
    } catch (PDOException $e) {
        $message = 'Unable to load invoice: ' . htmlspecialchars($e->getMessage());
        $messageType = 'danger';
    }
}

try {
    $clients = $pdo->query("SELECT id, CONCAT(first_name, ' ', last_name) AS name FROM clients ORDER BY first_name, last_name")->fetchAll();
} catch (PDOException $e) {
    $clients = [];
}

try {
    $casesList = $pdo->query("
        SELECT c.id, CONCAT('C-', LPAD(c.id, 4, '0')) AS case_number, c.title, CONCAT(cl.first_name, ' ', cl.last_name) AS client_name
        FROM cases c
        LEFT JOIN clients cl ON cl.id = c.client_id
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
    $selected = $formData['case_id'] == $caseRow['id'] ? ' selected' : '';
    $caseOptions .= '<option value="' . (int)$caseRow['id'] . '"' . $selected . '>' . htmlspecialchars($caseRow['case_number'] . ' · ' . $caseRow['title'] . ' (' . $caseRow['client_name'] . ')') . '</option>';
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
        $statusLabel = isset($statusOptions[strtolower($invoice['status'])]) ? $statusOptions[strtolower($invoice['status'])] : ucfirst($invoice['status']);
        $badgeClass = 'bg-gradient-secondary';
        switch (strtolower($invoice['status'])) {
            case 'paid':
                $badgeClass = 'bg-gradient-success';
                break;
            case 'overdue':
                $badgeClass = 'bg-gradient-danger';
                break;
            case 'sent':
                $badgeClass = 'bg-gradient-info';
                break;
            default:
                $badgeClass = 'bg-gradient-secondary';
        }
        $invoiceRows .= '
        <tr>
            <td>
                <div class="d-flex flex-column">
                    <strong>' . htmlspecialchars($invoice['invoice_number']) . '</strong>
                    <small class="text-muted">' . ($invoice['issue_date'] ? htmlspecialchars(date('d M Y', strtotime($invoice['issue_date']))) : 'N/A') . '</small>
                </div>
            </td>
            <td>
                <p class="text-sm mb-0">' . htmlspecialchars($invoice['client_name'] ?: 'Client') . '</p>
                <p class="text-xs text-muted mb-0">' . htmlspecialchars($invoice['case_title'] ?: 'No case linked') . '</p>
            </td>
            <td class="text-center">' . htmlspecialchars(formatCurrency($invoice['amount'])) . '</td>
            <td class="text-center">
                <span class="badge ' . $badgeClass . '">' . htmlspecialchars($statusLabel) . '</span>
            </td>
            <td class="text-center">' . ($invoice['due_date'] ? htmlspecialchars(date('d M Y', strtotime($invoice['due_date']))) : 'N/A') . '</td>
            <td class="text-end">
                <div class="d-flex gap-1 justify-content-end">
                    <a href="invoices.php?id=' . (int)$invoice['id'] . '" class="btn btn-sm btn-dark" title="Edit Invoice">Edit</a>
                    <a href="invoice-download.php?id=' . (int)$invoice['id'] . '" class="btn btn-sm btn-secondary" title="Download Invoice" target="_blank">Download</a>
                    <form method="post" class="d-inline" onsubmit="return confirm(\'Are you sure you want to delete invoice ' . htmlspecialchars($invoice['invoice_number']) . '? This action cannot be undone.\');">
                        <input type="hidden" name="form_type" value="delete">
                        <input type="hidden" name="invoice_id" value="' . (int)$invoice['id'] . '">
                        <button class="btn btn-sm btn-danger" type="submit" title="Delete Invoice">
                            <i class="ni ni-fat-remove"></i>
                        </button>
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
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-admin-portal">
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
            <div class="row">
                <div class="col-lg-5">
                    <div class="card h-100">
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
                                    <input type="text" class="form-control" name="invoice_number" value="{FORM_INVOICE_NUMBER}" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Client</label>
                                    <select class="form-select" name="client_id" required>
                                        {CLIENT_OPTIONS}
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Linked Case (optional)</label>
                                    <select class="form-select" name="case_id">
                                        {CASE_OPTIONS}
                                    </select>
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
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Status</label>
                                        <select class="form-select" name="status">
                                            {STATUS_OPTIONS}
                                        </select>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Notes</label>
                                    <textarea class="form-control" rows="3" name="notes" placeholder="Payment terms, highlights...">{FORM_NOTES}</textarea>
                                </div>
                                <button class="btn btn-dark w-100">{FORM_BUTTON}</button>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="col-lg-7 mt-4 mt-lg-0">
                    <div class="card h-100">
                        <div class="card-header pb-0">
                            <h6 class="mb-0">Invoice List</h6>
                        </div>
                        <div class="card-body px-0 pt-0 pb-2">
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
                                    <tbody>
                                        {INVOICE_ROWS}
                                    </tbody>
                                </table>
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
                                © <script>document.write(new Date().getFullYear())</script>, LegalPro Case Manager.
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
</body>
</html>
HTML;

$html = str_replace('{MESSAGE}', $messageHtml, $html);
$html = str_replace('{FORM_TITLE}', htmlspecialchars($formTitle), $html);
$html = str_replace('{FORM_BUTTON}', htmlspecialchars($formButtonLabel), $html);
$html = str_replace('{FORM_INVOICE_ID}', htmlspecialchars($formData['invoice_id']), $html);
$html = str_replace('{FORM_INVOICE_NUMBER}', htmlspecialchars($formData['invoice_number']), $html);
$html = str_replace('{CLIENT_OPTIONS}', $clientOptions, $html);
$html = str_replace('{CASE_OPTIONS}', $caseOptions, $html);
$html = str_replace('{FORM_AMOUNT}', htmlspecialchars($formData['amount']), $html);
$html = str_replace('{FORM_ISSUE_DATE}', htmlspecialchars($formData['issue_date']), $html);
$html = str_replace('{FORM_DUE_DATE}', htmlspecialchars($formData['due_date']), $html);
$html = str_replace('{FORM_NOTES}', htmlspecialchars($formData['notes']), $html);
$html = str_replace('{STATUS_OPTIONS}', $statusOptionsHtml, $html);
$html = str_replace('{INVOICE_ROWS}', $invoiceRows, $html);

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

