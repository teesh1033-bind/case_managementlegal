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
    $message     = 'Error loading cases: ' . htmlspecialchars($e->getMessage());
    $messageType = 'danger';
    $cases       = [];
}

// ── Stat helpers ──────────────────────────────────────────────────────────────
$caseCount  = count($cases);
$activeCount = 0;
$reviewCount = 0;
foreach ($cases as $c) {
    $s = strtolower($c['status'] ?? '');
    if ($s === 'active')                     $activeCount++;
    if (str_contains($s, 'review'))          $reviewCount++;
}

// ── Badge helpers ─────────────────────────────────────────────────────────────
function modern_status_badge(string $status): string {
    $map = [
        'active'        => ['cls' => 'badge-active',  'dot' => '#16a34a', 'label' => 'Active'],
        'pending'       => ['cls' => 'badge-pending', 'dot' => '#ca8a04', 'label' => 'Pending'],
        'under review'  => ['cls' => 'badge-review',  'dot' => 'currentColor', 'label' => 'Under review'],
        'closed'        => ['cls' => 'badge-closed',  'dot' => '#94a3b8', 'label' => 'Closed'],
    ];
    $key  = strtolower(trim($status));
    $cfg  = $map[$key] ?? ['cls' => 'badge-closed', 'dot' => '#94a3b8', 'label' => ucfirst($status)];
    return '<span class="badge ' . $cfg['cls'] . '">'
         . '<span class="badge-dot" style="background:' . $cfg['dot'] . '"></span>'
         . htmlspecialchars($cfg['label'])
         . '</span>';
}

function modern_priority_badge(string $priority): string {
    $map = [
        'high'   => 'pri-high',
        'medium' => 'pri-med',
        'normal' => 'pri-med',
        'low'    => 'pri-low',
    ];
    $key = strtolower(trim($priority));
    $cls = $map[$key] ?? 'pri-low';
    return '<span class="' . $cls . '">' . htmlspecialchars(ucfirst($priority)) . '</span>';
}

// ── Lawyer avatar stack ───────────────────────────────────────────────────────
function lawyer_stack(string $names): string {
    if (!$names || $names === 'Unassigned') {
        return '<span style="font-size:12px;color:#94a3b8">Unassigned</span>';
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
        $html .= '<span style="font-size:11px;font-weight:600;color:#64748b;margin-left:6px">+' . $extra . '</span>';
    } else {
        // Show first name only when single lawyer
        $html .= '<span style="font-size:12px;font-weight:500;color:#475569;margin-left:8px">' . htmlspecialchars($people[0]) . '</span>';
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
        <div class="empty">
            <div class="empty-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                    <rect x="2" y="7" width="20" height="14" rx="2"/>
                    <path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/>
                </svg>
            </div>
            <h5 style="font-size:15px;font-weight:700;color:#1e293b;margin-bottom:.4rem">No cases yet</h5>
            <p style="font-size:13px;color:#94a3b8;max-width:22rem;margin:0 auto 1.25rem">When your legal team opens a matter for you, it will appear here with status, priority, and assigned counsel.</p>
            <a href="client-dashboard.php" class="btn-action">Go to dashboard</a>
        </div>
    </td></tr>';
} else {
    foreach ($cases as $case) {
        $id         = (int) $case['id'];
        $caseNumber = 'C-' . str_pad((string) $id, 4, '0', STR_PAD_LEFT);
        $title      = htmlspecialchars($case['title']);
        $lawyers    = lawyer_stack($case['lawyer_names'] ?: 'Unassigned');
        $status     = modern_status_badge((string) ($case['status'] ?? ''));
        $priority   = modern_priority_badge((string) ($case['priority'] ?? 'Normal'));
        $category   = category_pill((string) ($case['category'] ?? ''));
        $updated    = isset($case['updated_at']) ? date('M j, Y', strtotime($case['updated_at'])) : '';

        $searchHay = strtolower($caseNumber . ' ' . ($case['title'] ?? '') . ' ' . ($case['category'] ?? '')
            . ' ' . ($case['status'] ?? '') . ' ' . ($case['priority'] ?? '') . ' ' . ($case['lawyer_names'] ?? ''));

        $casesRows .= '<tr data-status="' . htmlspecialchars($case['status'] ?? '') . '"
                            data-priority="' . htmlspecialchars($case['priority'] ?? '') . '"
                            data-title="' . strtolower($title) . '"
                            data-category="' . strtolower($case['category'] ?? '') . '"
                            data-search="' . htmlspecialchars($searchHay, ENT_QUOTES, 'UTF-8') . '">
            <td>
                <div style="display:flex;align-items:center;gap:10px">
                    <div class="case-icon">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <rect x="2" y="7" width="20" height="14" rx="2"/>
                            <path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/>
                        </svg>
                    </div>
                    <div>
                        <p class="case-num">' . $caseNumber . '</p>
                        <p class="case-title">' . $title . '</p>
                        <p class="case-date">Updated ' . htmlspecialchars($updated) . '</p>
                    </div>
                </div>
            </td>
            <td>' . $category . '</td>
            <td style="text-align:center">' . $status . '</td>
            <td style="text-align:center">' . $priority . '</td>
            <td>' . $lawyers . '</td>
            <td><a href="client-case-view.php?id=' . $id . '" class="btn-view">View</a></td>
        </tr>';
    }
}

// ── Message HTML ──────────────────────────────────────────────────────────────
$messageHtml = $message
    ? '<div class="alert-bar alert-' . htmlspecialchars($messageType) . '">' . htmlspecialchars($message) . '</div>'
    : '';

require_once __DIR__ . '/../inc/admin-layout.php';
require_once __DIR__ . '/../inc/client-portal-navbar.php';
$clientPageNavbar = legalpro_render_client_page_navbar(
    'My Cases',
    'My Cases',
    'Search cases…',
    legalpro_client_page_search_options('client-cases.php')
);

ob_start(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="apple-touch-icon" sizes="76x76" href="../assets/img/apple-icon.png">
    <link rel="icon" type="image/png" href="../assets/img/favicon.png">
    <title>LegalPro – My Cases</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-icons.css" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-svg.css" rel="stylesheet" />
    <script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
    <link id="pagestyle" href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
    <link href="../assets/css/app-font-montserrat.css?v=4" rel="stylesheet" />
    <?php include __DIR__ . '/../inc/client-portal-head.php'; ?>

    <style>
        /* ── Reset / base ───────────────────────────────────────── */
        *, *::before, *::after { box-sizing: border-box; }
        body.client-cases-page {
            font-family: 'Inter', system-ui, sans-serif;
            background: #f0f2f8;
            --cc-primary: var(--legalpro-theme-primary, #5e72e4);
            --cc-primary-dark: var(--legalpro-theme-primary-dark, #825ee4);
            --cc-primary-soft: var(--lp-cases-accent-soft, rgba(94, 114, 228, 0.12));
            --cc-primary-border: var(--lp-cases-accent-border, rgba(94, 114, 228, 0.35));
            --cc-gradient: var(--legalpro-theme-gradient, linear-gradient(135deg, #5e72e4, #825ee4));
        }

        /* ── Alert bar ──────────────────────────────────────────── */
        .alert-bar {
            border-radius: 10px;
            padding: .75rem 1rem;
            font-size: 13px;
            margin-bottom: 1rem;
        }
        .alert-danger  { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
        .alert-success { background: #dcfce7; color: #166534; border: 1px solid #86efac; }

        /* ── Hero card ──────────────────────────────────────────── */
        .cc-hero-card {
            background: var(--cc-gradient);
            border-radius: 20px;
            padding: 2rem 2.5rem;
            color: #fff;
            margin-bottom: 1.5rem;
            position: relative;
            overflow: hidden;
        }
        .cc-hero-card::before {
            content: '';
            position: absolute;
            top: -60px; right: -60px;
            width: 200px; height: 200px;
            border-radius: 50%;
            background: rgba(255,255,255,.08);
        }
        .cc-hero-card::after {
            content: '';
            position: absolute;
            bottom: -80px; right: 80px;
            width: 160px; height: 160px;
            border-radius: 50%;
            background: rgba(255,255,255,.06);
        }
        .cc-hero-kicker {
            font-size: 11px;
            font-weight: 600;
            letter-spacing: .12em;
            text-transform: uppercase;
            opacity: .75;
            margin-bottom: .35rem;
        }
        .cc-hero-title {
            font-size: 22px;
            font-weight: 800;
            margin-bottom: .3rem;
        }
        .cc-hero-sub {
            font-size: 13px;
            opacity: .75;
            margin-bottom: 1.5rem;
        }
        .cc-hero-stats {
            display: flex;
            gap: .85rem;
            flex-wrap: wrap;
            position: relative;
            z-index: 1;
        }
        .cc-stat-pill {
            background: rgba(255,255,255,.15);
            border: 1px solid rgba(255,255,255,.2);
            border-radius: 12px;
            padding: .6rem 1.1rem;
            backdrop-filter: blur(10px);
        }
        .cc-stat-pill .num {
            font-size: 20px;
            font-weight: 700;
            line-height: 1;
        }
        .cc-stat-pill .lbl {
            font-size: 11px;
            opacity: .75;
            margin-top: 2px;
        }

        /* ── Filters ────────────────────────────────────────────── */
        .cc-filters {
            display: flex;
            gap: .75rem;
            margin-bottom: 1.25rem;
            flex-wrap: wrap;
            align-items: center;
        }
        .cc-search-wrap {
            position: relative;
            flex: 1;
            min-width: 200px;
        }
        .cc-search-wrap svg {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            pointer-events: none;
        }
        .cc-search-input {
            width: 100%;
            padding: .55rem .75rem .55rem 2.25rem;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            font-size: 13px;
            background: #fff;
            color: #1e293b;
            outline: none;
            transition: border-color .15s, box-shadow .15s;
        }
        .cc-search-input:focus {
            border-color: var(--cc-primary);
            box-shadow: 0 0 0 3px rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.12);
        }
        .cc-filter-select {
            padding: .52rem .75rem;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            font-size: 13px;
            background: #fff;
            color: #1e293b;
            outline: none;
            cursor: pointer;
        }

        /* ── Table card ─────────────────────────────────────────── */
        .cc-panel {
            background: #fff;
            border-radius: 16px;
            border: 1px solid #e9ecf3;
            overflow: hidden;
            margin-bottom: 2rem;
        }
        .cc-panel-header {
            padding: 1.1rem 1.5rem;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: .75rem;
            flex-wrap: wrap;
        }
        .cc-panel-header h5 {
            font-size: 15px;
            font-weight: 700;
            color: #1e293b;
            margin: 0;
        }
        .cc-panel-header p {
            font-size: 12px;
            color: #94a3b8;
            margin: 2px 0 0;
        }
        .cc-row-count {
            background: var(--cc-primary-soft);
            color: var(--cc-primary);
            font-size: 11px;
            font-weight: 600;
            padding: .2rem .65rem;
            border-radius: 99px;
        }
        .cc-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        .cc-table thead th {
            background: #f8fafc;
            color: #94a3b8;
            font-size: 10.5px;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
            padding: .7rem 1rem;
            border-bottom: 1px solid #f1f5f9;
            white-space: nowrap;
        }
        .cc-table thead th:first-child { padding-left: 1.5rem; }
        .cc-table thead th:last-child  { padding-right: 1.5rem; text-align: right; }
        .cc-table tbody tr {
            border-bottom: 1px solid #f8fafc;
            transition: background .1s;
        }
        .cc-table tbody tr:hover   { background: rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.04); }
        .cc-table tbody tr:last-child { border-bottom: none; }
        .cc-table tbody td {
            padding: .85rem 1rem;
            vertical-align: middle;
        }
        .cc-table tbody td:first-child { padding-left: 1.5rem; }
        .cc-table tbody td:last-child  { padding-right: 1.5rem; text-align: right; }

        /* ── Case cell parts ────────────────────────────────────── */
        .case-icon {
            width: 36px; height: 36px;
            border-radius: 10px;
            background: var(--cc-primary-soft);
            color: var(--cc-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .case-num   { font-size: 11px; font-weight: 700; color: var(--cc-primary); margin: 0 0 1px; }
        .case-title {
            font-size: 13px; font-weight: 600; color: #1e293b;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
            max-width: 220px; margin: 0;
        }
        .case-date  { font-size: 11px; color: #94a3b8; margin: 1px 0 0; }

        /* ── Category pill ──────────────────────────────────────── */
        .cat-pill {
            display: inline-block;
            padding: .2rem .6rem;
            border-radius: 99px;
            font-size: 11px;
            font-weight: 600;
        }

        /* ── Status badges ──────────────────────────────────────── */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: .22rem .65rem;
            border-radius: 99px;
            font-size: 11px;
            font-weight: 600;
        }
        .badge-dot {
            width: 6px; height: 6px;
            border-radius: 50%;
            flex-shrink: 0;
        }
        .badge-active  { background: #dcfce7; color: #166534; }
        .badge-pending { background: #fef9c3; color: #854d0e; }
        .badge-closed  { background: #f1f5f9; color: #475569; }
        .badge-review  { background: var(--cc-primary-soft); color: var(--cc-primary); }
        .badge-review .badge-dot { background: var(--cc-primary) !important; }

        /* ── Priority badges ────────────────────────────────────── */
        .pri-high   { background: #fee2e2; color: #991b1b; padding: .2rem .6rem; border-radius: 6px; font-size: 11px; font-weight: 600; }
        .pri-med    { background: #fff7ed; color: #9a3412; padding: .2rem .6rem; border-radius: 6px; font-size: 11px; font-weight: 600; }
        .pri-low    { background: #f0fdf4; color: #166534; padding: .2rem .6rem; border-radius: 6px; font-size: 11px; font-weight: 600; }

        /* ── Lawyer stack ───────────────────────────────────────── */
        .lawyer-stack {
            display: flex;
            align-items: center;
        }
        .avatar {
            width: 26px; height: 26px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            font-weight: 700;
            flex-shrink: 0;
            border: 2px solid #fff;
        }

        /* ── View button ────────────────────────────────────────── */
        .btn-view {
            display: inline-block;
            padding: .35rem .9rem;
            border-radius: 8px;
            border: 1.5px solid var(--cc-primary);
            color: var(--cc-primary);
            font-size: 12px;
            font-weight: 600;
            background: none;
            cursor: pointer;
            text-decoration: none;
            transition: background .15s, color .15s;
        }
        .btn-view:hover { background: var(--cc-primary); color: #fff; }

        .btn-action {
            display: inline-block;
            padding: .5rem 1.25rem;
            border-radius: 10px;
            background: var(--cc-primary);
            color: #fff;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            transition: background .15s;
        }
        .btn-action:hover { background: var(--cc-primary-dark); color: #fff; }

        /* ── Empty state ────────────────────────────────────────── */
        .empty { padding: 3.5rem 1.5rem; text-align: center; }
        .empty-icon {
            width: 52px; height: 52px;
            border-radius: 14px;
            background: var(--cc-primary-soft);
            color: var(--cc-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
        }

        /* ── Responsive tweaks ──────────────────────────────────── */
        @media (max-width: 640px) {
            .cc-hero-card { padding: 1.5rem; }
            .cc-panel-header { flex-direction: column; align-items: flex-start; }
            .case-title { max-width: 140px; }
        }
    </style>
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-client-portal client-cases-page">
    <div class="min-height-300 bg-legalpro-client position-absolute w-100"></div>

    <?php include __DIR__ . '/../inc/client-menunav.php'; ?>

    <main class="main-content position-relative border-radius-lg">
        <?= $clientPageNavbar ?>

        <div class="container-fluid py-4">

            <?= $messageHtml ?>

            <!-- Hero -------------------------------------------------------->
            <div class="row mb-0">
                <div class="col-12">
                    <div class="cc-hero-card">
                        <p class="cc-hero-kicker">Client portal</p>
                        <h4 class="cc-hero-title">My cases</h4>
                        <p class="cc-hero-sub">Track status, priority, and the counsel assigned to each of your matters.</p>
                        <div class="cc-hero-stats">
                            <div class="cc-stat-pill">
                                <div class="num"><?= $caseCount ?></div>
                                <div class="lbl">Total cases</div>
                            </div>
                            <div class="cc-stat-pill">
                                <div class="num"><?= $activeCount ?></div>
                                <div class="lbl">Active</div>
                            </div>
                            <div class="cc-stat-pill">
                                <div class="num"><?= $reviewCount ?></div>
                                <div class="lbl">Under review</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filters ----------------------------------------------------->
            <div class="cc-filters">
                <div class="cc-search-wrap">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
                    </svg>
                    <input id="ccSearch" class="cc-search-input" type="text"
                           placeholder="Search cases…" oninput="ccFilter()"
                           value="<?= htmlspecialchars(legalpro_client_page_search_query(), ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <select id="ccStatus" class="cc-filter-select" onchange="ccFilter()">
                    <option value="">All statuses</option>
                    <option>Active</option>
                    <option>Pending</option>
                    <option>Under review</option>
                    <option>Closed</option>
                </select>
                <select id="ccPriority" class="cc-filter-select" onchange="ccFilter()">
                    <option value="">All priorities</option>
                    <option>High</option>
                    <option>Medium</option>
                    <option>Normal</option>
                    <option>Low</option>
                </select>
                <a href="client-dashboard.php" class="btn-action" style="white-space:nowrap">Dashboard</a>
            </div>

            <!-- Case table -------------------------------------------------->
            <div class="row">
                <div class="col-12">
                    <div class="cc-panel">
                        <div class="cc-panel-header">
                            <div>
                                <h5>Case list</h5>
                                <p>Sorted by most recently updated.</p>
                            </div>
                            <span class="cc-row-count" id="ccRowCount"><?= $caseCount ?> case<?= $caseCount !== 1 ? 's' : '' ?></span>
                        </div>
                        <div class="table-responsive">
                            <table class="cc-table" id="ccTable">
                                <thead>
                                    <tr>
                                        <th>Case</th>
                                        <th>Category</th>
                                        <th style="text-align:center">Status</th>
                                        <th style="text-align:center">Priority</th>
                                        <th>Lawyer(s)</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody id="ccBody">
                                    <?= $casesRows ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

        </div><!-- /container -->
    </main>

    <!-- Scripts -->
    <script src="../assets/js/core/popper.min.js"></script>
    <script src="../assets/js/core/bootstrap.min.js"></script>
    <script src="../assets/js/plugins/perfect-scrollbar.min.js"></script>
    <script src="../assets/js/plugins/smooth-scrollbar.min.js"></script>
    <script src="../assets/js/argon-dashboard.min.js?v=2.1.0"></script>

    <script>
    function ccFilter() {
        var q  = document.getElementById('ccSearch').value.toLowerCase();
        var st = document.getElementById('ccStatus').value.toLowerCase();
        var pr = document.getElementById('ccPriority').value.toLowerCase();
        var rows = document.querySelectorAll('#ccBody tr[data-title]');
        var visible = 0;
        rows.forEach(function(r) {
            var hay = r.dataset.search || (r.dataset.title + ' ' + r.dataset.category);
            var titleMatch    = !q  || hay.includes(q);
            var statusMatch   = !st || r.dataset.status.toLowerCase() === st;
            var priorityMatch = !pr || r.dataset.priority.toLowerCase() === pr;
            var show = titleMatch && statusMatch && priorityMatch;
            r.style.display = show ? '' : 'none';
            if (show) visible++;
        });
        var el = document.getElementById('ccRowCount');
        if (el) el.textContent = visible + ' case' + (visible === 1 ? '' : 's');
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