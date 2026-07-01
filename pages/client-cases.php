<?php
session_start();
require_once __DIR__ . '/../inc/db.php';

if (!isset($_SESSION['client_id'])) {
    header('Location: login.php');
    exit;
}

$client_id   = $_SESSION['client_id'];
$client_name = $_SESSION['client_name'];

$message     = '';
$messageType = '';

try {
    $stmt = $pdo->prepare("
        SELECT
            c.*,
            GROUP_CONCAT(DISTINCT CONCAT(l.first_name, ' ', l.last_name) SEPARATOR ', ') as lawyer_names
        FROM cases c
        LEFT JOIN case_lawyers cl ON cl.case_id = c.id
        LEFT JOIN lawyers l ON l.id = cl.lawyer_id
        WHERE c.client_id = ?
        GROUP BY c.id
        ORDER BY c.updated_at DESC
    ");
    $stmt->execute([$client_id]);
    $cases = $stmt->fetchAll();
} catch (PDOException $e) {
    $message     = client_t('cases.error_load') . ' ' . htmlspecialchars($e->getMessage());
    $messageType = 'danger';
    $cases       = [];
}

// ── Stat helpers ──────────────────────────────────────────────────────────────
$caseCount    = count($cases);
$activeCount  = 0;
$openCount    = 0;
$pendingCount = 0;
$closedCount  = 0;
$reviewCount  = 0;
foreach ($cases as $c) {
    $s = strtolower(str_replace([' ', '-'], '_', trim((string) ($c['status'] ?? ''))));
    if (in_array($s, ['active', 'open', 'in_progress'], true)) {
        $activeCount++;
    }
    if ($s === 'open') {
        $openCount++;
    }
    if ($s === 'pending') {
        $pendingCount++;
    }
    if ($s === 'closed') {
        $closedCount++;
    }
    if (str_contains($s, 'review')) {
        $reviewCount++;
    }
}

require_once __DIR__ . '/../inc/legalpro-icons.php';
require_once __DIR__ . '/../lib/client-portal-page-ui.php';
require_once __DIR__ . '/../lib/client-locale.php';
require_once __DIR__ . '/../lib/client-portal-i18n.php';

// ── Lawyer avatar stack ───────────────────────────────────────────────────────
function lawyer_stack(string $names): string {
    if (!$names || $names === 'Unassigned') {
        return '<span class="lawyer-stack__unassigned">' . htmlspecialchars(client_t('common.unassigned')) . '</span>';
    }
    $people  = array_map('trim', explode(',', $names));
    $colors  = [
        ['bg'=>'#ede9fe','fg'=>'#5b21b6'],
        ['bg'=>'#fce7f3','fg'=>'#9d174d'],
        ['bg'=>'#e0f2fe','fg'=>'#0c4a6e'],
        ['bg'=>'#ecfdf5','fg'=>'#065f46'],
    ];
    $html    = '<div class="lawyer-stack">';
    $shown   = min(3, count($people));
    $extra   = count($people) - $shown;
    foreach (array_slice($people, 0, $shown) as $i => $name) {
        $initials = implode('', array_map(fn($w) => strtoupper($w[0]), explode(' ', $name)));
        $initials = substr($initials, 0, 2);
        $c        = $colors[$i % count($colors)];
        $offset   = $i > 0 ? ' style="margin-left:-8px"' : '';
        $html    .= '<div class="avatar" style="background:' . $c['bg'] . ';color:' . $c['fg'] . '"' . $offset . ' title="' . htmlspecialchars($name) . '">' . htmlspecialchars($initials) . '</div>';
    }
    if ($extra > 0) {
        $html .= '<span class="lawyer-stack__more">+' . $extra . '</span>';
    } else {
        // Show first name only when single lawyer
        $html .= '<span class="lawyer-stack__name">' . htmlspecialchars($people[0]) . '</span>';
    }
    $html .= '</div>';
    return $html;
}

// ── Category pill colour map ──────────────────────────────────────────────────
function category_pill(string $cat): string {
    $map = [
        'civil'       => 'background:#f0f9ff;color:#0369a1',
        'criminal'    => 'background:#fef2f2;color:#991b1b',
        'estate'      => 'background:#f0fdf4;color:#166534',
        'commercial'  => 'background:#fff7ed;color:#9a3412',
        'employment'  => 'background:#fdf4ff;color:#86198f',
        'family'      => 'background:#fef9c3;color:#854d0e',
        'corporate'   => 'background:#ede9fe;color:#5b21b6',
    ];
    $key   = strtolower(trim($cat));
    $style = $map[$key] ?? 'background:#f1f5f9;color:#475569';
    return '<span class="cat-pill" style="' . $style . '">' . htmlspecialchars(ucfirst($cat)) . '</span>';
}

// ── Build rows ────────────────────────────────────────────────────────────────
$casesRows = '';
if (empty($cases)) {
    $casesRows = '<tr><td colspan="6">
        <div class="cp-empty">
            <div class="cp-empty-icon cp-empty-icon--primary">' . legalpro_icon('briefcase') . '</div>
            <h5>' . htmlspecialchars(client_t('cases.empty_title')) . '</h5>
            <p>' . htmlspecialchars(client_t('cases.empty_sub')) . '</p>
            <a href="client-dashboard.php" class="btn-action">' . legalpro_icon('layout-dashboard') . ' ' . htmlspecialchars(client_t('common.go_dashboard')) . '</a>
        </div>
    </td></tr>';
} else {
    foreach ($cases as $case) {
        $id         = (int) $case['id'];
        $caseNumber = 'C-' . str_pad((string) $id, 4, '0', STR_PAD_LEFT);
        $title      = htmlspecialchars($case['title']);
        $lawyers    = lawyer_stack($case['lawyer_names'] ?: 'Unassigned');
        $status     = client_modern_status_badge((string) ($case['status'] ?? ''));
        $priority   = client_modern_priority_badge((string) ($case['priority'] ?? 'Normal'));
        $category   = category_pill((string) ($case['category'] ?? ''));
        $updated    = isset($case['updated_at']) ? date('M j, Y', strtotime($case['updated_at'])) : '';

        $searchHay = strtolower($caseNumber . ' ' . ($case['title'] ?? '') . ' ' . ($case['category'] ?? '')
            . ' ' . ($case['status'] ?? '') . ' ' . ($case['priority'] ?? '') . ' ' . ($case['lawyer_names'] ?? ''));

        $casesRows .= '<tr class="cc-case-row" data-status="' . htmlspecialchars($case['status'] ?? '') . '"
                            data-priority="' . htmlspecialchars($case['priority'] ?? '') . '"
                            data-title="' . strtolower($title) . '"
                            data-category="' . strtolower($case['category'] ?? '') . '"
                            data-search="' . htmlspecialchars($searchHay, ENT_QUOTES, 'UTF-8') . '">
            <td>
                <div class="cc-case-cell">
                    <div class="case-icon">' . legalpro_icon('briefcase') . '</div>
                    <div>
                        <p class="case-num">' . $caseNumber . '</p>
                        <p class="case-title">' . $title . '</p>
                        <p class="case-date">' . htmlspecialchars(client_t('common.updated')) . ' ' . htmlspecialchars($updated) . '</p>
                    </div>
                </div>
            </td>
            <td>' . $category . '</td>
            <td style="text-align:center">' . $status . '</td>
            <td style="text-align:center">' . $priority . '</td>
            <td>' . $lawyers . '</td>
            <td><a href="client-case-view.php?id=' . $id . '" class="btn-view">' . legalpro_icon('arrow-right') . ' ' . htmlspecialchars(client_t('common.view')) . '</a></td>
        </tr>';
    }
}

// ── Message HTML ──────────────────────────────────────────────────────────────
$messageHtml = $message
    ? '<div class="alert alert-' . htmlspecialchars($messageType) . ' alert-dismissible fade show" role="alert">'
        . htmlspecialchars($message)
        . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="' . htmlspecialchars(client_t('common.close')) . '"></button></div>'
    : '';

$heroHtml = client_portal_render_hero([
    'kicker' => client_t('cases.kicker'),
    'title' => client_t('cases.title'),
    'subtitle' => client_t('cases.subtitle'),
    'show_date' => true,
    'aria_label' => client_t('cases.aria'),
    'stats' => [
        ['num' => (string) $caseCount, 'lbl' => client_t('common.total')],
        ['num' => (string) $activeCount, 'lbl' => client_t('status.active')],
        ['num' => (string) $pendingCount, 'lbl' => client_t('status.pending')],
        ['num' => (string) $closedCount, 'lbl' => client_t('status.closed')],
    ],
    'actions' => [
        ['url' => 'client-dashboard.php', 'label' => client_t('nav.dashboard'), 'primary' => true, 'icon' => 'layout-dashboard'],
        ['url' => 'client-documents.php', 'label' => client_t('nav.documents'), 'icon' => 'folder-open'],
    ],
]);

$panelHeaderHtml = client_portal_render_panel_header([
    'title' => client_t('cases.list_title'),
    'subtitle' => client_t('cases.list_sub_sorted'),
    'icon' => 'briefcase',
    'badge' => $caseCount . ' ' . client_plural('common.case', 'common.cases', $caseCount),
    'badge_id' => 'ccRowCount',
]);

require_once __DIR__ . '/../inc/admin-layout.php';
require_once __DIR__ . '/../inc/client-portal-navbar.php';
$clientPageNavbar = legalpro_render_client_page_navbar(
    client_t('cases.navbar'),
    client_t('cases.navbar'),
    client_t('cases.search_placeholder'),
    legalpro_client_page_search_options('client-cases.php')
);

ob_start(); ?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(client_portal_html_lang()) ?>">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="apple-touch-icon" sizes="76x76" href="../assets/img/apple-icon.png">
    <link rel="icon" type="image/png" href="../assets/img/favicon.png">
    <title>LegalPro – <?= htmlspecialchars(client_t('cases.navbar')) ?></title>
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-icons.css" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-svg.css" rel="stylesheet" />
    <script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
    <link id="pagestyle" href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
    <link href="../assets/css/app-font-montserrat.css?v=4" rel="stylesheet" />
    <?php include __DIR__ . '/../inc/client-portal-head.php'; ?>
    <link href="../assets/css/client-portal-pages.css?v=6" rel="stylesheet" />
    <link href="../assets/css/client-cases.css?v=3" rel="stylesheet" />
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-client-portal client-cases-page<?php echo legalpro_portal_theme_body_class(); ?>">
    <div class="min-height-300 bg-legalpro-client position-absolute w-100"></div>

    <?php include __DIR__ . '/../inc/client-menunav.php'; ?>

    <main class="main-content position-relative border-radius-lg">
        <?= $clientPageNavbar ?>

        <div class="container-fluid py-4 px-4">
            <div class="cp-page">

            <?= $messageHtml ?>

            <?= $heroHtml ?>

            <section class="cp-filters-card" aria-label="<?= htmlspecialchars(client_t('cases.filter_aria')) ?>">
                <div class="cp-filters">
                    <div class="cp-search-wrap">
                        <?= legalpro_icon('search') ?>
                        <input id="ccSearch" class="cp-search-input" type="text"
                               placeholder="<?= htmlspecialchars(client_t('cases.search_placeholder')) ?>" oninput="ccFilter()"
                               value="<?= htmlspecialchars(legalpro_client_page_search_query(), ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <select id="ccStatus" class="cp-filter-select" onchange="ccFilter()">
                        <option value=""><?= htmlspecialchars(client_t('cases.filter_all_statuses')) ?></option>
                        <option value="active"><?= htmlspecialchars(client_t('status.active')) ?></option>
                        <option value="pending"><?= htmlspecialchars(client_t('status.pending')) ?></option>
                        <option value="under review"><?= htmlspecialchars(client_t('cases.filter_under_review')) ?></option>
                        <option value="closed"><?= htmlspecialchars(client_t('status.closed')) ?></option>
                    </select>
                    <select id="ccPriority" class="cp-filter-select" onchange="ccFilter()">
                        <option value=""><?= htmlspecialchars(client_t('cases.filter_all_priorities')) ?></option>
                        <option value="high"><?= htmlspecialchars(client_t('priority.high')) ?></option>
                        <option value="urgent"><?= htmlspecialchars(client_t('priority.urgent')) ?></option>
                        <option value="normal"><?= htmlspecialchars(client_t('priority.normal')) ?></option>
                    </select>
                </div>
            </section>

            <section class="cp-panel">
                <?= $panelHeaderHtml ?>
                <div class="cp-portal-table-wrap" data-portal-table-wrap data-portal-row=".cc-case-row" data-portal-per-page="10" data-portal-show-page-global="ccShowPage">
                <div class="table-responsive">
                    <table class="cc-table" id="ccTable">
                        <thead>
                            <tr>
                                <th><?= htmlspecialchars(client_t('cases.col_case')) ?></th>
                                <th><?= htmlspecialchars(client_t('cases.col_category')) ?></th>
                                <th style="text-align:center"><?= htmlspecialchars(client_t('cases.col_status')) ?></th>
                                <th style="text-align:center"><?= htmlspecialchars(client_t('cases.col_priority')) ?></th>
                                <th><?= htmlspecialchars(client_t('cases.col_lawyers')) ?></th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="ccBody">
                            <?= $casesRows ?>
                        </tbody>
                    </table>
                </div>
                <nav class="cp-portal-pagination" data-portal-pagination aria-label="<?= htmlspecialchars(client_t('cases.pagination_aria')) ?>" hidden>
                    <p class="cp-portal-pagination__info" data-portal-range></p>
                    <div class="cp-portal-pagination__controls" data-portal-pages></div>
                </nav>
                </div>
            </section>

            </div>
        </div><!-- /container -->
    </main>

    <!-- Scripts -->
    <script src="../assets/js/core/popper.min.js"></script>
    <script src="../assets/js/core/bootstrap.min.js"></script>
    <script src="../assets/js/plugins/perfect-scrollbar.min.js"></script>
    <script src="../assets/js/plugins/smooth-scrollbar.min.js"></script>
    <script src="../assets/js/legalpro-sidenav-bootstrap.js?v=1"></script>
<script src="../assets/js/argon-dashboard.min.js?v=2.1.0"></script>

    <script>
    var ccCaseLabel = <?= json_encode(client_t('common.case'), JSON_UNESCAPED_UNICODE) ?>;
    var ccCasesLabel = <?= json_encode(client_t('common.cases'), JSON_UNESCAPED_UNICODE) ?>;
    function ccStatusKey(s) {
        s = (s || '').toLowerCase().replace(/_/g, ' ').trim();
        if (s === 'open' || s === 'in progress') return 'active';
        return s;
    }
    function ccFilter() {
        var q  = document.getElementById('ccSearch').value.toLowerCase();
        var st = document.getElementById('ccStatus').value.toLowerCase();
        var pr = document.getElementById('ccPriority').value.toLowerCase();
        var rows = document.querySelectorAll('#ccBody tr[data-title]');
        var visible = 0;
        rows.forEach(function(r) {
            var hay = r.dataset.search || (r.dataset.title + ' ' + r.dataset.category);
            var rowStatus = (r.dataset.status || '').toLowerCase().replace(/_/g, ' ');
            var titleMatch    = !q  || hay.includes(q);
            var statusMatch   = !st || ccStatusKey(rowStatus) === ccStatusKey(st);
            var priorityMatch = !pr || (r.dataset.priority || '').toLowerCase() === pr;
            var show = titleMatch && statusMatch && priorityMatch;
            r.style.display = show ? '' : 'none';
            if (show) visible++;
        });
        var el = document.getElementById('ccRowCount');
        if (el) el.textContent = visible + ' ' + (visible === 1 ? ccCaseLabel : ccCasesLabel);
        if (typeof window.ccShowPage === 'function') {
            window.ccShowPage(1);
        }
    }
    document.addEventListener('DOMContentLoaded', function() {
        if (document.getElementById('ccSearch').value) ccFilter();
    });
    </script>
</body>
</html>
<?php
$html = ob_get_clean();
require_once __DIR__ . '/../inc/client-sidebar.php';
$html = inject_client_sidebar($html);
echo $html;