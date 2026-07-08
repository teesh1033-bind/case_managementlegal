<?php
/**
 * Admin payments portal — tabbed sections (overview, record, ledger, recent, outstanding).
 */

require_once __DIR__ . '/../inc/admin-layout.php';
require_once __DIR__ . '/admin-payments-portal-data.php';

function legalpro_payments_portal_pages(): array
{
    return [
        'payments' => [
            'file' => 'payments.php',
            'title' => 'Overview',
            'tab' => 'Overview',
            'nav' => 'Payments',
            'desc' => 'Financial summary at a glance',
        ],
        'payments-record' => [
            'file' => 'payments-record.php',
            'title' => 'Record Payment',
            'tab' => 'Record Payment',
            'nav' => 'Record Payment',
            'desc' => 'Log cash, bank, or installment payments for any case',
        ],
        'payments-ledger' => [
            'file' => 'payments-ledger.php',
            'title' => 'Case Ledger',
            'tab' => 'Case Ledger',
            'nav' => 'Case Ledger',
            'desc' => 'View fee, paid amount, and balance by case',
        ],
        'payments-recent' => [
            'file' => 'payments-recent.php',
            'title' => 'Recent Payments',
            'tab' => 'Recent',
            'nav' => 'Recent Payments',
            'desc' => 'Latest payment activity across all cases',
        ],
        'payments-outstanding' => [
            'file' => 'payments-outstanding.php',
            'title' => 'Outstanding Balances',
            'tab' => 'Outstanding',
            'nav' => 'Outstanding',
            'desc' => 'Cases still on a payment plan',
        ],
    ];
}

function legalpro_payments_allowed_methods(): array
{
    return [
        'cash' => 'Cash',
        'bank_transfer' => 'Bank Transfer',
        'card' => 'Card',
        'cheque' => 'Cheque',
        'mobile' => 'Mobile Payment',
        'invoice' => 'Invoice',
    ];
}

function legalpro_payments_activity_pages(): array
{
    return legalpro_payments_portal_pages();
}

function legalpro_payments_activity_message_html(array $state): string
{
    if (empty($state['message'])) {
        return '';
    }

    $type = !empty($state['messageType']) ? $state['messageType'] : 'info';

    return '<div class="alert alert-' . htmlspecialchars($type) . ' alert-dismissible fade show" role="alert">'
        . htmlspecialchars($state['message'])
        . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>'
        . '</div>';
}

function legalpro_payments_activity_init_state(): array
{
    $state = ['message' => '', 'messageType' => ''];
    if (isset($_GET['msg']) && isset($_GET['type'])) {
        $state['message'] = urldecode((string) $_GET['msg']);
        $state['messageType'] = (string) $_GET['type'];
    }

    return $state;
}

function legalpro_payments_activity_counts(PDO $pdo): array
{
    $recentCount = 0;
    $outstandingCount = 0;

    try {
        $recentCount = (int) $pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn();
    } catch (PDOException $e) {
        $recentCount = 0;
    }

    try {
        $stmt = $pdo->query("
            SELECT c.id, COALESCE(c.estimated_fees, 0) AS estimated_fees, COALESCE(SUM(p.amount), 0) AS paid_total
            FROM cases c
            LEFT JOIN payments p ON p.case_id = c.id
            GROUP BY c.id, c.estimated_fees
        ");
        foreach ($stmt->fetchAll() as $row) {
            $balance = max((float) $row['estimated_fees'] - (float) $row['paid_total'], 0);
            if ($balance > 0.01) {
                $outstandingCount++;
            }
        }
    } catch (PDOException $e) {
        $outstandingCount = 0;
    }

    return ['recent' => $recentCount, 'outstanding' => $outstandingCount];
}

function legalpro_payments_activity_build_recent_rows(PDO $pdo): string
{
    $allowedMethods = legalpro_payments_allowed_methods();
    $rows = '';

    try {
        $payments = $pdo->query("
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
        ")->fetchAll();
    } catch (PDOException $e) {
        $payments = [];
    }

    if (empty($payments)) {
        return '<tr><td colspan="6" class="text-center py-4 text-muted">No payments recorded yet.</td></tr>';
    }

    foreach ($payments as $payment) {
        $caseNumber = 'C-' . str_pad((string) $payment['case_id'], 4, '0', STR_PAD_LEFT);
        $clientName = !empty($payment['client_name']) ? $payment['client_name'] : 'Unknown Client';
        $methodLabel = $allowedMethods[$payment['method']] ?? ucfirst((string) $payment['method']);
        $notesRaw = isset($payment['notes']) ? trim((string) $payment['notes']) : '';
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

        $rows .= '
        <tr class="legalpro-payments-row legalpro-admin-list-row" data-search="' . htmlspecialchars($searchBlob, ENT_QUOTES, 'UTF-8') . '">
            <td>
                <div class="legalpro-payments-cell">
                    <span class="legalpro-payments-initials" aria-hidden="true">' . htmlspecialchars(legalpro_portal_initials($clientName, 'CL')) . '</span>
                    <div class="legalpro-payments-cell__text">
                        <span class="legalpro-payments-cell__title">' . htmlspecialchars($clientName) . '</span>
                        <span class="legalpro-payments-cell__sub">' . htmlspecialchars($caseSubtitle) . '</span>
                    </div>
                </div>
            </td>
            <td class="text-center"><span class="legalpro-payments-amount">' . formatCurrency($payment['amount']) . '</span></td>
            <td class="text-center"><span class="lp-pill lp-pill--status-success">Completed</span></td>
            <td class="text-center"><span class="lp-pill lp-pill--status-default">' . htmlspecialchars($methodLabel) . '</span></td>
            <td class="text-center"><span class="legalpro-payments-date">' . htmlspecialchars((string) $payment['payment_date']) . '</span></td>
            <td class="text-end">' . $notesPreview . '</td>
        </tr>';
    }

    return $rows;
}

function legalpro_payments_activity_build_outstanding_rows(PDO $pdo): string
{
    $rows = '';

    try {
        $cases = $pdo->query("
            SELECT
                c.id,
                c.title,
                COALESCE(c.estimated_fees, 0) AS estimated_fees,
                CONCAT(cl.first_name, ' ', cl.last_name) AS client_name,
                COALESCE(SUM(p.amount), 0) AS paid_total,
                MAX(p.payment_date) AS last_payment
            FROM cases c
            LEFT JOIN clients cl ON cl.id = c.client_id
            LEFT JOIN payments p ON p.case_id = c.id
            GROUP BY c.id, c.title, c.estimated_fees, cl.first_name, cl.last_name
            ORDER BY c.created_at DESC
        ")->fetchAll();
    } catch (PDOException $e) {
        $cases = [];
    }

    foreach ($cases as $case) {
        $caseId = (int) $case['id'];
        $caseNumber = 'C-' . str_pad((string) $caseId, 4, '0', STR_PAD_LEFT);
        $estimated = (float) $case['estimated_fees'];
        $paid = (float) $case['paid_total'];
        $balance = max($estimated - $paid, 0);
        if ($balance <= 0.01) {
            continue;
        }

        $lastPayment = !empty($case['last_payment']) ? $case['last_payment'] : '—';
        $clientName = !empty($case['client_name']) ? $case['client_name'] : 'Unknown Client';
        $searchBlob = strtolower(
            $caseNumber . ' ' . ($case['title'] ?? '') . ' ' . $clientName . ' '
            . formatCurrency($estimated) . ' ' . formatCurrency($paid) . ' '
            . formatCurrency($balance) . ' ' . $lastPayment
        );

        $statusBadge = legalpro_case_payment_status_badge($estimated, $paid);
        $paidClass = legalpro_payment_paid_amount_class($paid);
        $balanceClass = legalpro_payment_balance_amount_class($paid, $balance);

        $rows .= '
        <tr class="legalpro-payments-row legalpro-admin-list-row" data-search="' . htmlspecialchars($searchBlob, ENT_QUOTES, 'UTF-8') . '">
            <td>
                <div class="legalpro-payments-cell">
                    <span class="legalpro-payments-initials legalpro-payments-initials--case" aria-hidden="true">' . htmlspecialchars(legalpro_portal_initials((string) $case['title'], 'CS')) . '</span>
                    <div class="legalpro-payments-cell__text">
                        <span class="legalpro-payments-cell__title">' . htmlspecialchars($caseNumber . ' · ' . $case['title']) . '</span>
                        <span class="legalpro-payments-cell__sub">' . htmlspecialchars($clientName) . '</span>
                    </div>
                </div>
            </td>
            <td class="text-center"><span class="legalpro-payments-muted">' . formatCurrency($estimated) . '</span></td>
            <td class="text-center"><span class="' . $paidClass . '">' . formatCurrency($paid) . '</span></td>
            <td class="text-center"><span class="' . $balanceClass . '">' . formatCurrency($balance) . '</span></td>
            <td class="text-center">' . $statusBadge . '</td>
            <td class="text-end">' . ($lastPayment !== '—' ? '<span class="legalpro-payments-date">' . htmlspecialchars((string) $lastPayment) . '</span>' : '<span class="text-muted">No payments</span>') . '</td>
        </tr>';
    }

    if ($rows === '') {
        return '<tr><td colspan="6" class="text-center py-4 text-muted">All cases are fully paid.</td></tr>';
    }

    return $rows;
}

function legalpro_payments_portal_subnav_html(string $activeKey): string
{
    $pages = legalpro_payments_portal_pages();
    $html = '<nav class="legalpro-payments-tabs" aria-label="Payment sections">';
    foreach ($pages as $key => $page) {
        $active = $key === $activeKey ? ' is-active' : '';
        $label = $page['tab'] ?? $page['title'];
        $html .= '<a class="legalpro-payments-tabs__link' . $active . '" href="' . htmlspecialchars($page['file']) . '">'
            . htmlspecialchars($label) . '</a>';
    }
    $html .= '</nav>';

    return $html;
}

function legalpro_payments_activity_subnav_html(string $activeKey): string
{
    return legalpro_payments_portal_subnav_html($activeKey);
}

function legalpro_payments_portal_overview_html(array $state): string
{
    return '<div class="row g-3 legalpro-payments-stats">
        <div class="col-xl-3 col-sm-6">
            <div class="legalpro-payments-stat legalpro-payments-stat--success">
                <div class="legalpro-payments-stat__head">
                    <span class="legalpro-payments-stat__label">Collected</span>
                    <span class="legalpro-payments-stat__icon">' . $state['iconStatCollected'] . '</span>
                </div>
                <strong class="legalpro-payments-stat__value">' . formatCurrency($state['totalCollected']) . '</strong>
                <span class="legalpro-payments-stat__meta legalpro-payments-stat__meta--success">+' . formatCurrency($state['paymentsThisMonth']) . ' last 30 days</span>
            </div>
        </div>
        <div class="col-xl-3 col-sm-6">
            <div class="legalpro-payments-stat legalpro-payments-stat--warning">
                <div class="legalpro-payments-stat__head">
                    <span class="legalpro-payments-stat__label">Outstanding</span>
                    <span class="legalpro-payments-stat__icon">' . $state['iconStatOutstanding'] . '</span>
                </div>
                <strong class="legalpro-payments-stat__value">' . formatCurrency($state['totalOutstanding']) . '</strong>
                <span class="legalpro-payments-stat__meta">' . (int) $state['activePaymentPlans'] . ' active plans</span>
            </div>
        </div>
        <div class="col-xl-3 col-sm-6">
            <div class="legalpro-payments-stat legalpro-payments-stat--info">
                <div class="legalpro-payments-stat__head">
                    <span class="legalpro-payments-stat__label">Cases paid off</span>
                    <span class="legalpro-payments-stat__icon">' . $state['iconStatPaidOff'] . '</span>
                </div>
                <strong class="legalpro-payments-stat__value">' . (int) $state['casesPaidOff'] . '</strong>
                <span class="legalpro-payments-stat__meta">Fully settled matters</span>
            </div>
        </div>
        <div class="col-xl-3 col-sm-6">
            <div class="legalpro-payments-stat legalpro-payments-stat--primary">
                <div class="legalpro-payments-stat__head">
                    <span class="legalpro-payments-stat__label">Active installments</span>
                    <span class="legalpro-payments-stat__icon">' . $state['iconStatInstallments'] . '</span>
                </div>
                <strong class="legalpro-payments-stat__value">' . (int) $state['activePaymentPlans'] . '</strong>
                <span class="legalpro-payments-stat__meta">Cases with balance</span>
            </div>
        </div>
    </div>';
}

function legalpro_payments_portal_record_html(array $state): string
{
    $formData = $state['formData'];

    return '<div class="legalpro-payments-form-panel">
            <form method="post" autocomplete="off" class="legalpro-payments-form">
                <div class="row g-4">
                    <div class="col-12">
                        <label class="form-label">Select Case</label>
                        <select class="form-select" name="case_id" id="case_id" required>' . $state['caseOptions'] . '</select>
                    </div>
                    <div class="col-12" id="invoice-select-wrap" style="display: none;">
                        <label class="form-label">Apply to Invoice</label>
                        <select class="form-select" name="invoice_id" id="invoice_id">
                            <option value="">General payment (not tied to invoice)</option>
                        </select>
                        <p class="legalpro-payments-form-hint mb-0 mt-1" id="invoice-balance-hint"></p>
                        <div class="alert alert-warning py-2 px-3 mb-0 mt-2 d-none" id="invoice-paid-alert" role="alert">
                            <span class="text-sm mb-0" id="invoice-paid-alert-text">This invoice has already been paid.</span>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Payment Date</label>
                        <input type="date" class="form-control" name="payment_date" value="' . htmlspecialchars($formData['payment_date']) . '" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Amount</label>
                        <input type="number" step="0.01" min="0" class="form-control" name="amount" value="' . htmlspecialchars((string) $formData['amount']) . '" placeholder="0.00" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Method</label>
                        <select class="form-select" name="method">' . $state['methodsOptions'] . '</select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Reference <span class="text-muted fw-normal">(optional)</span></label>
                        <input type="text" class="form-control" name="reference" value="' . htmlspecialchars($formData['reference']) . '" placeholder="Receipt no., bank ref...">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Recorded By</label>
                        <input type="text" class="form-control" name="recorded_by" value="' . htmlspecialchars($formData['recorded_by']) . '" placeholder="Staff name">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" rows="3" placeholder="Add internal notes">' . htmlspecialchars($formData['notes']) . '</textarea>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-legalpro-payments-save" id="payment-submit-btn">Save Payment</button>
                    </div>
                </div>
            </form>
        </div>' . legalpro_payments_portal_record_script($state);
}

function legalpro_payments_portal_ledger_html(array $state): string
{
    return '<div class="legalpro-payments-ledger-panel">
        <div class="legalpro-payments-ledger-toolbar">
            <div class="legalpro-payments-ledger-toolbar__case">
                <label class="form-label mb-1" for="ledger_case_select">Select case</label>
                <select class="form-select" id="ledger_case_select">' . $state['ledgerOptions'] . '</select>
            </div>
            <a href="financial-summary.php" class="btn btn-sm btn-legalpro-payments-outline">Financial Summary</a>
        </div>
        <p class="legalpro-payments-ledger-case-label" id="selected-case-label">Select a case to view its balance.</p>
        <div class="legalpro-payments-ledger-stats">
            <div class="legalpro-payments-ledger-stat">
                <span class="legalpro-payments-ledger-stat__label">Total fee</span>
                <strong class="legalpro-payments-ledger-stat__value" id="ledger-fee">' . htmlspecialchars($state['currencyZero']) . '</strong>
            </div>
            <div class="legalpro-payments-ledger-stat legalpro-payments-ledger-stat--paid">
                <span class="legalpro-payments-ledger-stat__label">Paid</span>
                <strong class="legalpro-payments-ledger-stat__value" id="ledger-paid">' . htmlspecialchars($state['currencyZero']) . '</strong>
            </div>
            <div class="legalpro-payments-ledger-stat legalpro-payments-ledger-stat--balance">
                <span class="legalpro-payments-ledger-stat__label">Balance</span>
                <strong class="legalpro-payments-ledger-stat__value" id="ledger-balance">' . htmlspecialchars($state['currencyZero']) . '</strong>
            </div>
        </div>
        <div class="legalpro-payments-progress my-4">
            <div class="d-flex justify-content-between align-items-center mb-1">
                <span class="legalpro-payments-progress__label">Payment progress</span>
                <span class="legalpro-payments-progress__pct" id="ledger-progress-label">0%</span>
            </div>
            <div class="progress legalpro-payments-progress__bar">
                <div id="ledger-progress" class="progress-bar" role="progressbar" style="width:0%;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
            </div>
        </div>
        <div class="legalpro-payments-ledger-meta">
            <div><span>Status</span> <strong id="ledger-status">—</strong></div>
            <div><span>Last payment</span> <strong id="ledger-last-payment">—</strong></div>
        </div>
    </div>' . legalpro_payments_portal_ledger_script($state);
}

function legalpro_payments_portal_record_script(array $state): string
{
    $formData = $state['formData'];

    return '<script>
        (function() {
            var invoiceData = ' . $state['caseInvoicesJson'] . ';
            var initialInvoiceId = ' . json_encode((string) ($formData['invoice_id'] ?? '')) . ';
            var caseSelect = document.getElementById("case_id");
            var invoiceSelect = document.getElementById("invoice_id");
            var invoiceWrap = document.getElementById("invoice-select-wrap");
            var invoiceHint = document.getElementById("invoice-balance-hint");
            var amountInput = document.querySelector("input[name=amount]");
            var amountTouched = false;
            var paidAlert = document.getElementById("invoice-paid-alert");
            var paidAlertText = document.getElementById("invoice-paid-alert-text");
            var submitBtn = document.getElementById("payment-submit-btn");
            var paymentForm = document.querySelector("form.legalpro-payments-form");

            if (amountInput) {
                amountInput.addEventListener("input", function() { amountTouched = true; });
            }

            function invoiceIsPaid(invoice) {
                return !!(invoice && invoice.balance_raw <= 0.01);
            }

            function updateInvoicePaidState(invoice) {
                var isPaid = invoiceIsPaid(invoice);
                if (paidAlert) paidAlert.classList.toggle("d-none", !isPaid);
                if (paidAlertText) {
                    paidAlertText.textContent = isPaid && invoice
                        ? invoice.number + " has already been paid."
                        : "This invoice has already been paid.";
                }
                if (submitBtn) submitBtn.disabled = isPaid;
                if (amountInput) amountInput.readOnly = isPaid;
            }

            function findInvoice(caseId, invoiceId) {
                var invoices = invoiceData[caseId] || [];
                for (var i = 0; i < invoices.length; i++) {
                    if (String(invoices[i].id) === String(invoiceId)) return invoices[i];
                }
                return null;
            }

            function updateInvoiceHint(invoice) {
                if (!invoiceHint) return;
                invoiceHint.textContent = invoice && invoice.balance_raw > 0.01
                    ? invoice.number + " has " + invoice.balance + " remaining."
                    : "";
            }

            function updateInvoiceSelect(caseId, preferredInvoiceId) {
                if (!invoiceSelect || !invoiceWrap) return;
                var invoices = invoiceData[caseId] || [];
                var unpaidInvoices = invoices.filter(function(invoice) {
                    return invoice.balance_raw > 0.01;
                });
                if (!invoices.length) {
                    invoiceWrap.style.display = "none";
                    updateInvoiceHint(null);
                    updateInvoicePaidState(null);
                    return;
                }
                invoiceWrap.style.display = "block";
                invoiceSelect.innerHTML = unpaidInvoices.length > 1
                    ? ""
                    : "<option value=\"\">General payment (not tied to invoice)</option>";
                var firstUnpaidId = "";
                invoices.forEach(function(invoice) {
                    var option = document.createElement("option");
                    option.value = invoice.id;
                    option.textContent = invoice.label;
                    if (invoice.balance_raw > 0.01 && !firstUnpaidId) firstUnpaidId = String(invoice.id);
                    invoiceSelect.appendChild(option);
                });
                var targetId = preferredInvoiceId || initialInvoiceId || firstUnpaidId || "";
                if (targetId && findInvoice(caseId, targetId) && findInvoice(caseId, targetId).balance_raw > 0.01) {
                    invoiceSelect.value = targetId;
                } else if (firstUnpaidId) {
                    invoiceSelect.value = firstUnpaidId;
                } else {
                    invoiceSelect.value = "";
                }
                var selectedInvoice = findInvoice(caseId, invoiceSelect.value);
                updateInvoiceHint(selectedInvoice);
                updateInvoicePaidState(selectedInvoice);
                if (!amountTouched && selectedInvoice && selectedInvoice.balance_raw > 0.01 && amountInput) {
                    amountInput.value = selectedInvoice.balance_raw.toFixed(2);
                }
            }

            if (caseSelect) {
                caseSelect.addEventListener("change", function() {
                    amountTouched = false;
                    initialInvoiceId = "";
                    updateInvoiceSelect(this.value, "");
                });
            }
            if (invoiceSelect) {
                invoiceSelect.addEventListener("change", function() {
                    var caseId = caseSelect ? caseSelect.value : "";
                    var selectedInvoice = findInvoice(caseId, this.value);
                    updateInvoiceHint(selectedInvoice);
                    updateInvoicePaidState(selectedInvoice);
                    if (!amountTouched && selectedInvoice && selectedInvoice.balance_raw > 0.01 && amountInput) {
                        amountInput.value = selectedInvoice.balance_raw.toFixed(2);
                    }
                });
            }
            if (paymentForm) {
                paymentForm.addEventListener("submit", function(event) {
                    var caseId = caseSelect ? caseSelect.value : "";
                    var selectedInvoice = findInvoice(caseId, invoiceSelect ? invoiceSelect.value : "");
                    if (invoiceIsPaid(selectedInvoice)) {
                        event.preventDefault();
                        updateInvoicePaidState(selectedInvoice);
                    }
                });
            }

            if (caseSelect && caseSelect.value) {
                updateInvoiceSelect(caseSelect.value, initialInvoiceId);
            }
        })();
    </script>';
}

function legalpro_payments_portal_ledger_script(array $state): string
{
    return '<script>
        (function() {
            var ledgerData = ' . $state['caseDataJson'] . ';
            var currencyZero = ' . json_encode($state['currencyZero']) . ';
            var ledgerSelect = document.getElementById("ledger_case_select");
            var feeEl = document.getElementById("ledger-fee");
            var paidEl = document.getElementById("ledger-paid");
            var balanceEl = document.getElementById("ledger-balance");
            var progressEl = document.getElementById("ledger-progress");
            var progressLabelEl = document.getElementById("ledger-progress-label");
            var statusEl = document.getElementById("ledger-status");
            var lastEl = document.getElementById("ledger-last-payment");
            var labelEl = document.getElementById("selected-case-label");

            function setProgress(percent) {
                if (!progressEl) return;
                progressEl.style.width = percent + "%";
                progressEl.setAttribute("aria-valuenow", percent);
                if (progressLabelEl) progressLabelEl.textContent = percent + "%";
            }

            function updateLedger(caseId) {
                if (ledgerData[caseId]) {
                    var data = ledgerData[caseId];
                    feeEl.textContent = data.estimated;
                    paidEl.textContent = data.paid;
                    balanceEl.textContent = data.balance;
                    statusEl.textContent = data.status ? data.status.toUpperCase() : "—";
                    lastEl.textContent = data.last_payment && data.last_payment !== "—" ? data.last_payment : "No payments";
                    labelEl.textContent = data.case_number + " · " + data.title + " (" + data.client + ")";
                    var fee = parseFloat(data.estimated_raw || 0);
                    var paid = parseFloat(data.paid_raw || 0);
                    setProgress(fee ? Math.min(100, Math.round((paid / fee) * 100)) : 0);
                } else {
                    feeEl.textContent = currencyZero;
                    paidEl.textContent = currencyZero;
                    balanceEl.textContent = currencyZero;
                    statusEl.textContent = "—";
                    lastEl.textContent = "—";
                    labelEl.textContent = "Select a case to view its balance.";
                    setProgress(0);
                }
                if (ledgerSelect) ledgerSelect.value = caseId || "";
            }

            if (ledgerSelect) {
                ledgerSelect.addEventListener("change", function() {
                    updateLedger(this.value);
                });
            }

            var initialCaseId = "";
            if (ledgerSelect && ledgerSelect.value) {
                initialCaseId = ledgerSelect.value;
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
    </script>';
}

function legalpro_payments_portal_wrap_content(string $pageKey, string $innerHtml): string
{
    $pages = legalpro_payments_portal_pages();
    $page = $pages[$pageKey] ?? $pages['payments'];

    return '<div class="legalpro-payments-workspace mb-0">'
        . '<div class="legalpro-payments-workspace__header">'
        . '<div class="legalpro-payments-workspace__intro">'
        . '<h5 class="legalpro-payments-workspace__title">Payments</h5>'
        . '<p class="legalpro-payments-workspace__sub">Record client payments, track balances, and manage case ledgers</p>'
        . '</div>'
        . legalpro_payments_portal_subnav_html($pageKey)
        . '</div>'
        . '<div class="legalpro-payments-workspace__body">'
        . '<p class="legalpro-payments-workspace__section">' . htmlspecialchars($page['desc']) . '</p>'
        . '<div class="legalpro-payments-workspace__content">' . $innerHtml . '</div>'
        . '</div></div>';
}

function legalpro_payments_activity_table_styles(): string
{
    return '';
}

function legalpro_payments_activity_recent_content_html(array $state): string
{
    $searchHtml = legalpro_render_admin_list_search('paymentsSearchInput', 'Search payments...');
    $searchScript = legalpro_admin_list_search_script('paymentsSearchInput', 'paymentsTableBody', 'paymentsFilterEmpty');

    $inner = '<div class="legalpro-payments-filters">' . $searchHtml . '</div>
            <div class="lp-admin-table-paginate" data-lp-admin-paginate data-lp-per-page="10" data-lp-row=".legalpro-payments-row">
            <div class="table-responsive legalpro-payments-table-wrap">
                <table class="table legalpro-payments-table mb-0">
                    <thead>
                        <tr>
                            <th>Client / Case</th>
                            <th class="text-center">Amount</th>
                            <th class="text-center">Status</th>
                            <th class="text-center">Method</th>
                            <th class="text-center">Date</th>
                            <th class="text-end">Notes</th>
                        </tr>
                    </thead>
                    <tbody id="paymentsTableBody">
                        ' . $state['recentRowsHtml'] . '
                        <tr id="paymentsFilterEmpty" class="d-none">
                            <td colspan="6" class="text-center text-muted text-sm py-4 border-0">No payments match your search.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <nav class="lp-admin-pagination" data-lp-pagination-nav aria-label="Payments pagination" hidden><p class="lp-admin-pagination__info" data-lp-range></p><div class="lp-admin-pagination__controls" data-lp-pages></div></nav>
            </div>' . $searchScript;

    return legalpro_payments_portal_wrap_content('payments-recent', $inner);
}

function legalpro_payments_activity_outstanding_content_html(array $state): string
{
    $searchHtml = legalpro_render_admin_list_search('outstandingSearchInput', 'Search outstanding balances...');
    $searchScript = legalpro_admin_list_search_script('outstandingSearchInput', 'outstandingTableBody', 'outstandingFilterEmpty');

    $inner = '<div class="legalpro-payments-filters">' . $searchHtml . '</div>
            <div class="lp-admin-table-paginate" data-lp-admin-paginate data-lp-per-page="10" data-lp-row=".legalpro-payments-row">
            <div class="table-responsive legalpro-payments-table-wrap">
                <table class="table legalpro-payments-table mb-0">
                    <thead>
                        <tr>
                            <th>Case</th>
                            <th class="text-center">Fee</th>
                            <th class="text-center">Paid</th>
                            <th class="text-center">Balance</th>
                            <th class="text-center">Status</th>
                            <th class="text-end">Last payment</th>
                        </tr>
                    </thead>
                    <tbody id="outstandingTableBody">
                        ' . $state['outstandingRowsHtml'] . '
                        <tr id="outstandingFilterEmpty" class="d-none">
                            <td colspan="6" class="text-center text-muted text-sm py-4 border-0">No outstanding balances match your search.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <nav class="lp-admin-pagination" data-lp-pagination-nav aria-label="Outstanding balances pagination" hidden><p class="lp-admin-pagination__info" data-lp-range></p><div class="lp-admin-pagination__controls" data-lp-pages></div></nav>
            </div>' . $searchScript;

    return legalpro_payments_portal_wrap_content('payments-outstanding', $inner);
}

function legalpro_payments_activity_render_page(string $pageKey, string $contentHtml, array $state): void
{
    $pages = legalpro_payments_portal_pages();
    $page = $pages[$pageKey] ?? $pages['payments'];
    $bodyClass = legalpro_portal_theme_body_class();
    $navTitle = 'Payments';

    $html = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>LegalPro · ' . htmlspecialchars($page['title']) . '</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-icons.css" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-svg.css" rel="stylesheet" />
    <script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
    <link href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
    ';
    ob_start();
    include dirname(__DIR__) . '/inc/admin-portal-head.php';
    $html .= ob_get_clean();
    $html .= '<link href="../assets/css/legalpro-finance-pages.css?v=14" rel="stylesheet" />'
        . '<link href="../assets/css/legalpro-documents-hub.css?v=5" rel="stylesheet" />'
        . '<link href="../assets/css/app-font-montserrat.css?v=8" rel="stylesheet" />
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-admin-portal legalpro-finance-page admin-payments-page legalpro-payments-activity-page' . $bodyClass . '">
    <div class="min-height-300 bg-legalpro-admin position-absolute w-100"></div>
    <aside class="sidenav navbar navbar-vertical navbar-expand-xs" id="sidenav-main"></aside>
    <main class="main-content position-relative border-radius-lg">
        <nav class="navbar navbar-main navbar-expand-lg px-0 shadow-none border-radius-xl" id="navbarBlur" data-scroll="false">
            <div class="container-fluid py-1 px-3">
                <div>
                    <h6 class="font-weight-bolder mb-0">Payments</h6>
                </div>
            </div>
        </nav>
        <div class="container-fluid py-4">
            ' . legalpro_payments_activity_message_html($state) . '
            ' . $contentHtml . '
            <footer class="footer pt-3">
                <div class="container-fluid">
                    <div class="row align-items-center justify-content-lg-between">
                        <div class="col-lg-6 mb-lg-0 mb-4">
                            <div class="copyright text-center text-sm text-muted text-lg-start">
                                {COPYRIGHT_LINE}
                            </div>
                        </div>
                    </div>
                </div>
            </footer>
        </div>
    </main>
</body>
</html>';

    $html = preg_replace('/href="([^"\']+)\.html"/i', 'href="$1.php"', $html);
    ob_start();
    include dirname(__DIR__) . '/inc/menunav.php';
    $sidebar = ob_get_clean();
    $html = preg_replace('/<aside[\s\S]*?<\/aside>/', $sidebar, $html, 1);
    ob_start();
    include dirname(__DIR__) . '/inc/footer.php';
    $footer = ob_get_clean();
    $html = preg_replace('/<\/body>\s*<\/html>$/i', $footer . "\n</body>\n</html>", $html);
    echo legalpro_apply_copyright_line($html);
}
