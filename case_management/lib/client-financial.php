<?php

require_once __DIR__ . '/../inc/admin-layout.php';

/**
 * Client-level financial summary (fees, payments, invoices).
 */
function legalpro_get_client_financial_summary(PDO $pdo, int $clientId): array
{
    $summary = [
        'total_fees' => 0.0,
        'total_paid' => 0.0,
        'total_balance' => 0.0,
        'total_invoiced' => 0.0,
        'invoice_count' => 0,
        'payment_count' => 0,
        'cases_with_balance' => 0,
        'cases_fully_paid' => 0,
        'avg_realized' => 0,
        'last_payment_date' => null,
        'cases' => [],
        'recent_payments' => [],
    ];

    if ($clientId <= 0) {
        return $summary;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT
                c.id,
                c.title,
                c.status,
                c.category,
                COALESCE(c.estimated_fees, 0) AS estimated_fees,
                COALESCE(inv.invoiced_total, 0) AS invoiced_total,
                COUNT(p.id) AS payment_count,
                COALESCE(SUM(p.amount), 0) AS paid_total,
                MAX(p.payment_date) AS last_payment
            FROM cases c
            LEFT JOIN payments p ON p.case_id = c.id
            LEFT JOIN (
                SELECT case_id, COALESCE(SUM(amount), 0) AS invoiced_total
                FROM invoices
                WHERE LOWER(COALESCE(status, '')) NOT IN ('cancelled', 'void')
                GROUP BY case_id
            ) inv ON inv.case_id = c.id
            WHERE c.client_id = ?
            GROUP BY c.id, c.title, c.status, c.category, c.estimated_fees, inv.invoiced_total
            ORDER BY c.created_at DESC
        ");
        $stmt->execute([$clientId]);
        $cases = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $cases = [];
    }

    foreach ($cases as $case) {
        $estimated = (float) ($case['estimated_fees'] ?? 0);
        $invoiced = (float) ($case['invoiced_total'] ?? 0);
        $totalDue = legalpro_case_fee_due($estimated, $invoiced);
        $paid = (float) ($case['paid_total'] ?? 0);
        $balance = max($totalDue - $paid, 0);
        $paymentCount = (int) ($case['payment_count'] ?? 0);

        $summary['total_fees'] += $totalDue;
        $summary['total_paid'] += $paid;
        $summary['total_balance'] += $balance;
        $summary['payment_count'] += $paymentCount;

        if ($balance > 0.01) {
            $summary['cases_with_balance']++;
        }
        if ($paid >= $totalDue - 0.01 && ($totalDue > 0.01 || $paid > 0.01)) {
            $summary['cases_fully_paid']++;
        }

        $summary['cases'][] = [
            'id' => (int) $case['id'],
            'title' => (string) ($case['title'] ?? ''),
            'status' => (string) ($case['status'] ?? 'open'),
            'category' => (string) ($case['category'] ?? 'General'),
            'estimated' => $totalDue,
            'paid' => $paid,
            'balance' => $balance,
            'payment_count' => $paymentCount,
            'last_payment' => $case['last_payment'] ?? null,
            'percent_paid' => $totalDue > 0 ? min(100, (int) round(($paid / $totalDue) * 100)) : ($paid > 0 ? 100 : 0),
            'payment_status' => legalpro_case_payment_status_label($totalDue, $paid),
        ];
    }

    $summary['avg_realized'] = $summary['total_fees'] > 0
        ? (int) round(($summary['total_paid'] / $summary['total_fees']) * 100)
        : 0;

    try {
        $stmt = $pdo->prepare("
            SELECT
                COALESCE(SUM(amount), 0) AS total_invoiced,
                COUNT(*) AS invoice_count
            FROM invoices
            WHERE client_id = ?
              AND LOWER(COALESCE(status, '')) NOT IN ('cancelled', 'void')
        ");
        $stmt->execute([$clientId]);
        $invoiceRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $summary['total_invoiced'] = (float) ($invoiceRow['total_invoiced'] ?? 0);
        $summary['invoice_count'] = (int) ($invoiceRow['invoice_count'] ?? 0);
    } catch (PDOException $e) {
        // invoices table may not exist on older installs
    }

    try {
        $stmt = $pdo->prepare("
            SELECT
                p.id,
                p.amount,
                p.method,
                p.reference,
                p.payment_date,
                p.created_at,
                c.title AS case_title,
                c.id AS case_id
            FROM payments p
            LEFT JOIN cases c ON c.id = p.case_id
            WHERE p.client_id = ?
            ORDER BY COALESCE(p.payment_date, p.created_at) DESC, p.id DESC
            LIMIT 5
        ");
        $stmt->execute([$clientId]);
        $summary['recent_payments'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($summary['recent_payments'][0])) {
            $first = $summary['recent_payments'][0];
            $summary['last_payment_date'] = $first['payment_date'] ?: $first['created_at'];
        }
    } catch (PDOException $e) {
        $summary['recent_payments'] = [];
    }

    return $summary;
}

function legalpro_render_client_financial_summary_html(array $summary, int $clientId): string
{
    if ($clientId <= 0) {
        return '<div class="card mt-4 client-fin-summary">'
            . '<div class="card-header pb-0"><h6 class="mb-0">Financial Summary</h6></div>'
            . '<div class="card-body"><p class="text-sm text-muted mb-0">Save the client first to view financial data.</p></div>'
            . '</div>';
    }

    require_once __DIR__ . '/../inc/legalpro-icons.php';

    $totalFees = formatCurrency($summary['total_fees']);
    $totalPaid = formatCurrency($summary['total_paid']);
    $totalBalance = formatCurrency($summary['total_balance']);
    $totalInvoiced = formatCurrency($summary['total_invoiced']);
    $avgRealized = (int) ($summary['avg_realized'] ?? 0);
    $casesWithBalance = (int) ($summary['cases_with_balance'] ?? 0);
    $casesFullyPaid = (int) ($summary['cases_fully_paid'] ?? 0);
    $invoiceCount = (int) ($summary['invoice_count'] ?? 0);
    $lastPayment = $summary['last_payment_date']
        ? htmlspecialchars(date('M j, Y', strtotime((string) $summary['last_payment_date'])))
        : '—';

    $iconFees = legalpro_icon('briefcase');
    $iconCollected = legalpro_icon('banknote');
    $iconOutstanding = legalpro_icon('clock');
    $iconInvoiced = legalpro_icon('file-text');

    $caseRows = '';
    $cases = $summary['cases'] ?? [];
    if (empty($cases)) {
        $caseRows = '<tr><td colspan="6" class="text-center py-3 text-muted text-sm">No cases linked to this client yet.</td></tr>';
    } else {
        foreach ($cases as $case) {
            $caseId = (int) $case['id'];
            $caseNumber = 'C-' . str_pad((string) $caseId, 4, '0', STR_PAD_LEFT);
            $title = htmlspecialchars($case['title']);
            $category = htmlspecialchars(ucfirst((string) $case['category']));
            $percent = (int) ($case['percent_paid'] ?? 0);
            $paymentStatus = legalpro_case_payment_status_badge((float) $case['estimated'], (float) $case['paid']);

            $caseRows .= '<tr>'
                . '<td><div class="d-flex flex-column">'
                . '<a href="case-view.php?id=' . $caseId . '" class="text-sm fw-bold text-dark">' . htmlspecialchars($caseNumber) . ' · ' . $title . '</a>'
                . '<small class="text-muted">' . $category . '</small></div></td>'
                . '<td class="text-center text-sm">' . formatCurrency((float) $case['estimated']) . '</td>'
                . '<td class="text-center text-sm text-success fw-semibold">' . formatCurrency((float) $case['paid']) . '</td>'
                . '<td class="text-center text-sm text-warning fw-semibold">' . formatCurrency((float) $case['balance']) . '</td>'
                . '<td class="text-center"><div class="progress-wrapper">'
                . '<div class="progress" style="height: 5px;"><div class="progress-bar bg-gradient-primary" style="width: ' . $percent . '%;"></div></div>'
                . '<small class="text-xs text-muted">' . $percent . '% paid</small></div></td>'
                . '<td class="text-center">' . $paymentStatus . '</td>'
                . '</tr>';
        }
    }

    $paymentRows = '';
    $recentPayments = $summary['recent_payments'] ?? [];
    if (empty($recentPayments)) {
        $paymentRows = '<li class="list-group-item text-center text-muted text-sm py-3">No payments recorded yet.</li>';
    } else {
        foreach ($recentPayments as $payment) {
            $payDate = $payment['payment_date'] ?: $payment['created_at'];
            $payDateLabel = $payDate ? date('M j, Y', strtotime((string) $payDate)) : '—';
            $caseTitle = !empty($payment['case_title']) ? htmlspecialchars($payment['case_title']) : 'General';
            $method = htmlspecialchars(ucfirst((string) ($payment['method'] ?? 'cash')));
            $paymentRows .= '<li class="list-group-item px-0 py-2 border-0 border-bottom">'
                . '<div class="d-flex justify-content-between align-items-start gap-2">'
                . '<div><p class="text-sm mb-0 fw-semibold">' . formatCurrency((float) $payment['amount']) . '</p>'
                . '<p class="text-xs text-muted mb-0">' . $caseTitle . ' · ' . $method . '</p></div>'
                . '<span class="text-xs text-muted">' . htmlspecialchars($payDateLabel) . '</span>'
                . '</div></li>';
        }
    }

    $paymentsUrl = 'payments.php';
    $invoicesUrl = 'invoices.php?client_id=' . $clientId;

    return '<div class="card mt-4 client-fin-summary">'
        . '<div class="card-header pb-0 d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2">'
        . '<div><h6 class="mb-0">Financial Summary</h6>'
        . '<p class="text-xs text-muted mb-0">Fees, collections, and outstanding balances for this client.</p></div>'
        . '<div class="d-flex flex-wrap gap-2">'
        . '<a href="' . htmlspecialchars($paymentsUrl) . '" class="btn btn-sm btn-primary mb-0">Record Payment</a>'
        . '<a href="' . htmlspecialchars($invoicesUrl) . '" class="btn btn-sm btn-outline-primary mb-0">View Invoices</a>'
        . '</div></div>'
        . '<div class="card-body pt-3">'
        . '<div class="row g-3 mb-4">'
        . '<div class="col-sm-6 col-xl-3"><div class="card dashboard-stat-card mb-0 h-100"><div class="card-body p-3">'
        . '<div class="d-flex justify-content-between align-items-start"><div>'
        . '<p class="text-xs text-uppercase fw-bold text-muted mb-1">Total Fees</p>'
        . '<h5 class="font-weight-bolder mb-0">' . $totalFees . '</h5>'
        . '<p class="text-xs text-muted mb-0 mt-1">Across ' . count($cases) . ' case' . (count($cases) === 1 ? '' : 's') . '</p>'
        . '</div><div class="dashboard-stat-icon-wrap dashboard-stat-icon-wrap--primary">' . $iconFees . '</div></div></div></div></div>'
        . '<div class="col-sm-6 col-xl-3"><div class="card dashboard-stat-card mb-0 h-100"><div class="card-body p-3">'
        . '<div class="d-flex justify-content-between align-items-start"><div>'
        . '<p class="text-xs text-uppercase fw-bold text-muted mb-1">Collected</p>'
        . '<h5 class="font-weight-bolder mb-0">' . $totalPaid . '</h5>'
        . '<p class="text-xs text-success mb-0 mt-1">' . $avgRealized . '% realized</p>'
        . '</div><div class="dashboard-stat-icon-wrap dashboard-stat-icon-wrap--success">' . $iconCollected . '</div></div></div></div></div>'
        . '<div class="col-sm-6 col-xl-3"><div class="card dashboard-stat-card mb-0 h-100"><div class="card-body p-3">'
        . '<div class="d-flex justify-content-between align-items-start"><div>'
        . '<p class="text-xs text-uppercase fw-bold text-muted mb-1">Outstanding</p>'
        . '<h5 class="font-weight-bolder mb-0">' . $totalBalance . '</h5>'
        . '<p class="text-xs text-warning mb-0 mt-1">' . $casesWithBalance . ' case' . ($casesWithBalance === 1 ? '' : 's') . ' with balance</p>'
        . '</div><div class="dashboard-stat-icon-wrap dashboard-stat-icon-wrap--warning">' . $iconOutstanding . '</div></div></div></div></div>'
        . '<div class="col-sm-6 col-xl-3"><div class="card dashboard-stat-card mb-0 h-100"><div class="card-body p-3">'
        . '<div class="d-flex justify-content-between align-items-start"><div>'
        . '<p class="text-xs text-uppercase fw-bold text-muted mb-1">Invoiced</p>'
        . '<h5 class="font-weight-bolder mb-0">' . $totalInvoiced . '</h5>'
        . '<p class="text-xs text-muted mb-0 mt-1">' . $invoiceCount . ' invoice' . ($invoiceCount === 1 ? '' : 's') . '</p>'
        . '</div><div class="dashboard-stat-icon-wrap dashboard-stat-icon-wrap--info">' . $iconInvoiced . '</div></div></div></div></div>'
        . '</div>'
        . '<div class="row g-4">'
        . '<div class="col-12"><p class="text-uppercase text-xs fw-bold text-muted mb-2">Case Financials</p>'
        . '<div class="table-responsive"><table class="table align-items-center mb-0">'
        . '<thead><tr>'
        . '<th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Case</th>'
        . '<th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Fees</th>'
        . '<th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Paid</th>'
        . '<th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Balance</th>'
        . '<th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Progress</th>'
        . '<th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Payment</th>'
        . '</tr></thead><tbody>' . $caseRows . '</tbody></table></div></div>'
        . '</div>'
        . '<div class="row g-4 mt-1">'
        . '<div class="col-12"><p class="text-uppercase text-xs fw-bold text-muted mb-2">Recent Payments</p>'
        . '<ul class="list-group list-group-flush client-fin-summary__payments">' . $paymentRows . '</ul>'
        . '<p class="text-xs text-muted mt-3 mb-0">Last payment: <strong>' . $lastPayment . '</strong>'
        . ($casesFullyPaid > 0 ? ' · ' . $casesFullyPaid . ' case' . ($casesFullyPaid === 1 ? '' : 's') . ' fully paid' : '')
        . '</p></div></div></div></div>';
}
