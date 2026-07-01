<?php
/**
 * Admin payments activity — hub + section pages (recent / outstanding).
 */

require_once __DIR__ . '/../inc/admin-layout.php';

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
    return [
        'payments' => [
            'file' => 'payments.php',
            'title' => 'Overview',
            'nav' => 'Payments',
        ],
        'payments-recent' => [
            'file' => 'payments-recent.php',
            'title' => 'Recent payments',
            'nav' => 'Recent payments',
        ],
        'payments-outstanding' => [
            'file' => 'payments-outstanding.php',
            'title' => 'Outstanding',
            'nav' => 'Outstanding balances',
        ],
    ];
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
        return '<tr><td colspan="5" class="text-center py-4 text-muted">No payments recorded yet.</td></tr>';
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
        <tr class="legalpro-admin-list-row" data-search="' . htmlspecialchars($searchBlob, ENT_QUOTES, 'UTF-8') . '">
            <td class="ps-4">
                <div class="d-flex flex-column">
                    <span class="text-sm font-weight-bold mb-0">' . htmlspecialchars($clientName) . '</span>
                    <small class="text-muted">' . htmlspecialchars($caseSubtitle) . '</small>
                </div>
            </td>
            <td class="text-center text-sm font-weight-bold">' . formatCurrency($payment['amount']) . '</td>
            <td class="text-center"><span class="lp-pill lp-pill--status-default">' . htmlspecialchars($methodLabel) . '</span></td>
            <td class="text-center text-sm">' . htmlspecialchars((string) $payment['payment_date']) . '</td>
            <td class="text-end text-xs pe-4">' . $notesPreview . '</td>
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

        $rows .= '
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
            <td class="text-end text-xs pe-4">' . ($lastPayment !== '—' ? htmlspecialchars((string) $lastPayment) : '<span class="text-muted">No payments</span>') . '</td>
        </tr>';
    }

    if ($rows === '') {
        return '<tr><td colspan="5" class="text-center py-4 text-muted">All cases are fully paid.</td></tr>';
    }

    return $rows;
}

function legalpro_payments_activity_hub_cards_html(array $counts): string
{
    require_once __DIR__ . '/../inc/legalpro-icons.php';
    $pages = legalpro_payments_activity_pages();
    $cards = [
        'payments-recent' => [
            'desc' => 'Latest payment activity across all cases.',
            'icon' => 'banknote',
            'accent' => 'success',
            'count' => $counts['recent'],
            'countLabel' => 'payments',
        ],
        'payments-outstanding' => [
            'desc' => 'Cases still on a payment plan with a balance due.',
            'icon' => 'clock',
            'accent' => 'warning',
            'count' => $counts['outstanding'],
            'countLabel' => 'cases',
        ],
    ];

    $html = '<div class="row g-3">';
    foreach ($cards as $key => $meta) {
        $page = $pages[$key];
        $html .= '
        <div class="col-md-6">
            <a href="' . htmlspecialchars($page['file']) . '" class="card legalpro-doc-hub-card h-100 text-decoration-none">
                <div class="card-body p-3">
                    <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
                        <div class="dashboard-stat-icon-wrap dashboard-stat-icon-wrap--' . $meta['accent'] . '">' . legalpro_icon($meta['icon']) . '</div>
                        <span class="badge lp-payments-hub-card__badge">' . (int) $meta['count'] . ' ' . htmlspecialchars($meta['countLabel']) . '</span>
                    </div>
                    <h6 class="mb-1 lp-payments-hub-card__title">' . htmlspecialchars($page['nav']) . '</h6>
                    <p class="text-sm text-muted mb-0">' . htmlspecialchars($meta['desc']) . '</p>
                </div>
            </a>
        </div>';
    }
    $html .= '</div>';

    return $html;
}

function legalpro_payments_activity_subnav_html(string $activeKey): string
{
    $pages = legalpro_payments_activity_pages();
    $html = '<nav class="legalpro-doc-subnav" aria-label="Payment activity sections">';
    $overviewActive = $activeKey === 'payments' ? ' is-active' : '';
    $html .= '<a class="legalpro-doc-subnav__link' . $overviewActive . '" href="payments.php">Overview</a>';
    foreach ($pages as $key => $page) {
        if ($key === 'payments') {
            continue;
        }
        $active = $key === $activeKey ? ' is-active' : '';
        $html .= '<a class="legalpro-doc-subnav__link' . $active . '" href="' . htmlspecialchars($page['file']) . '">'
            . htmlspecialchars($page['title']) . '</a>';
    }
    $html .= '</nav>';

    return $html;
}

function legalpro_payments_activity_table_styles(): string
{
    return <<<'CSS'
        .payments-summary-card .card-header { padding: 1.25rem 1.5rem 0.75rem; }
        .payments-summary-card .card-header h6 { margin-bottom: 0; font-weight: 700; }
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
        .payments-summary-card .table tbody tr:last-child td { border-bottom: 0; }
        .payments-notes-cell { max-width: 16rem; }
        .legalpro-doc-hub-card { transition: transform 0.15s ease, box-shadow 0.15s ease; border: 1px solid #e9ecf3; }
        .legalpro-doc-hub-card:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(15, 23, 42, 0.08); }
        .legalpro-doc-hub-card .lp-payments-hub-card__title { color: #1e293b; }
        .legalpro-doc-hub-card .lp-payments-hub-card__badge {
            background: #eef2ff;
            color: #3730a3;
            border: 1px solid #c7d2fe;
            font-weight: 700;
        }
        body.legalpro-dark-mode .legalpro-doc-hub-card { border-color: var(--lp-dark-border); }
        body.legalpro-dark-mode .legalpro-doc-hub-card h6,
        body.legalpro-dark-mode .legalpro-doc-hub-card .lp-payments-hub-card__title { color: var(--lp-dark-text) !important; }
        body.legalpro-dark-mode .legalpro-doc-hub-card .lp-payments-hub-card__badge {
            background: rgba(255, 255, 255, 0.12);
            color: var(--lp-dark-text) !important;
            border-color: rgba(255, 255, 255, 0.24);
        }
CSS;
}

function legalpro_payments_activity_recent_content_html(array $state): string
{
    $searchHtml = legalpro_render_admin_list_search('paymentsSearchInput', 'Search payments...');
    $searchScript = legalpro_admin_list_search_script('paymentsSearchInput', 'paymentsTableBody', 'paymentsFilterEmpty');

    return '<div class="card payments-summary-card mb-0">
        <div class="card-header pb-0">
            <h6>Recent payments</h6>
            <p class="text-sm text-muted mb-0">Latest payment activity across all cases.</p>
        </div>
        <div class="card-body px-0 pt-2 pb-2">
            ' . $searchHtml . '
            <div class="lp-admin-table-paginate" data-lp-admin-paginate data-lp-per-page="10" data-lp-row=".legalpro-admin-list-row">
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
                        ' . $state['recentRowsHtml'] . '
                        <tr id="paymentsFilterEmpty" class="d-none">
                            <td colspan="5" class="text-center text-muted text-sm py-4">No payments match your search.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <nav class="lp-admin-pagination" data-lp-pagination-nav aria-label="Payments pagination" hidden><p class="lp-admin-pagination__info" data-lp-range></p><div class="lp-admin-pagination__controls" data-lp-pages></div></nav>
            </div>
        </div>
    </div>' . $searchScript;
}

function legalpro_payments_activity_outstanding_content_html(array $state): string
{
    $searchHtml = legalpro_render_admin_list_search('outstandingSearchInput', 'Search outstanding balances...');
    $searchScript = legalpro_admin_list_search_script('outstandingSearchInput', 'outstandingTableBody', 'outstandingFilterEmpty');

    return '<div class="card payments-summary-card mb-0">
        <div class="card-header pb-0">
            <h6>Outstanding balances</h6>
            <p class="text-sm text-muted mb-0">Track cases still on a payment plan.</p>
        </div>
        <div class="card-body px-0 pt-2 pb-2">
            ' . $searchHtml . '
            <div class="lp-admin-table-paginate" data-lp-admin-paginate data-lp-per-page="10" data-lp-row=".legalpro-admin-list-row">
            <div class="table-responsive">
                <table class="table align-items-center mb-0">
                    <thead>
                        <tr>
                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-4">Case</th>
                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder text-center opacity-7">Fee</th>
                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder text-center opacity-7">Paid</th>
                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder text-center opacity-7">Balance</th>
                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder text-end opacity-7 pe-4">Last payment</th>
                        </tr>
                    </thead>
                    <tbody id="outstandingTableBody">
                        ' . $state['outstandingRowsHtml'] . '
                        <tr id="outstandingFilterEmpty" class="d-none">
                            <td colspan="5" class="text-center text-muted text-sm py-4">No outstanding balances match your search.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <nav class="lp-admin-pagination" data-lp-pagination-nav aria-label="Outstanding balances pagination" hidden><p class="lp-admin-pagination__info" data-lp-range></p><div class="lp-admin-pagination__controls" data-lp-pages></div></nav>
            </div>
        </div>
    </div>' . $searchScript;
}

function legalpro_payments_activity_render_page(string $pageKey, string $contentHtml, array $state): void
{
    $pages = legalpro_payments_activity_pages();
    $page = $pages[$pageKey] ?? $pages['payments'];
    $navTitle = $page['nav'];
    $bodyClass = legalpro_portal_theme_body_class();
    $subnav = $pageKey === 'payments' ? '' : legalpro_payments_activity_subnav_html($pageKey);

    $html = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>LegalPro · ' . htmlspecialchars($navTitle) . '</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-icons.css" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-svg.css" rel="stylesheet" />
    <script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
    <link href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
    <link href="../assets/css/app-font-montserrat.css?v=1" rel="stylesheet" />
    ';
    ob_start();
    include dirname(__DIR__) . '/inc/admin-portal-head.php';
    $html .= ob_get_clean();
    $html .= '<link href="../assets/css/legalpro-finance-pages.css?v=5" rel="stylesheet" />'
        . '<link href="../assets/css/legalpro-documents-hub.css?v=5" rel="stylesheet" />'
        . '<style>' . legalpro_payments_activity_table_styles() . '</style>
</head>
<body class="g-sidenav-show g-sidenav-pinned bg-gray-100 legalpro-admin-portal legalpro-finance-page legalpro-payments-activity-page' . $bodyClass . '">
    <div class="min-height-300 bg-legalpro-admin position-absolute w-100"></div>
    <aside class="sidenav bg-white navbar navbar-vertical navbar-expand-xs border-0 border-radius-xl my-3 fixed-start ms-4" id="sidenav-main"></aside>
    <main class="main-content position-relative border-radius-lg">
        <nav class="navbar navbar-main navbar-expand-lg px-0 mx-4 shadow-none border-radius-xl" id="navbarBlur" data-scroll="false">
            <div class="container-fluid py-1 px-3">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb bg-transparent mb-0 pb-0 pt-1 px-0 me-sm-6 me-5">
                        <li class="breadcrumb-item text-sm"><a class="opacity-5 text-white" href="payments.php">Payments</a></li>'
        . ($pageKey !== 'payments'
            ? '<li class="breadcrumb-item text-sm text-white active" aria-current="page">' . htmlspecialchars($page['title']) . '</li>'
            : '')
        . '</ol>
                    <h6 class="font-weight-bolder text-white mb-0">' . htmlspecialchars($navTitle) . '</h6>
                </nav>
            </div>
        </nav>
        <div class="container-fluid py-4">
            ' . legalpro_payments_activity_message_html($state) . '
            ' . $subnav . '
            ' . $contentHtml . '
        </div>
    </main>
    <script src="../assets/js/core/popper.min.js"></script>
    <script src="../assets/js/core/bootstrap.min.js"></script>
    <script src="../assets/js/plugins/perfect-scrollbar.min.js"></script>
    <script src="../assets/js/argon-dashboard.min.js?v=2.1.0"></script>
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
