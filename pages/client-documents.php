<?php
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../lib/client-portal-features.php';
require_once __DIR__ . '/../inc/admin-layout.php';
require_once __DIR__ . '/../inc/client-portal-navbar.php';
require_once __DIR__ . '/../lib/client-portal-page-ui.php';
require_once __DIR__ . '/../inc/legalpro-icons.php';

if (!isset($_SESSION['client_id'])) {
    header('Location: login.php');
    exit;
}

$clientId = (int) $_SESSION['client_id'];
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'acknowledge') {
    $docId = (int) ($_POST['document_id'] ?? 0);
    if (legalpro_client_acknowledge_document($pdo, $clientId, $docId)) {
        $message = function_exists('client_t') ? client_t('documents.acknowledged') : 'Receipt acknowledged.';
        $messageType = 'success';
    } else {
        $message = 'Could not acknowledge document.';
        $messageType = 'danger';
    }
}

$filterCaseId = isset($_GET['case_id']) ? (int) $_GET['case_id'] : 0;
$searchQ = trim((string) ($_GET['q'] ?? ''));
$caseFilter = $filterCaseId > 0 ? $filterCaseId : null;

$documents = legalpro_client_get_documents($pdo, $clientId, $caseFilter, $searchQ);
$newCount = legalpro_client_count_new_documents($pdo, $clientId);

try {
    $stmt = $pdo->prepare('SELECT id, title FROM cases WHERE client_id = ? ORDER BY title ASC');
    $stmt->execute([$clientId]);
    $clientCases = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $clientCases = [];
}

$docCount = count($documents);
$caseCount = count($clientCases);

$caseOptions = '<option value="">All cases</option>';
foreach ($clientCases as $c) {
    $sel = $filterCaseId === (int) $c['id'] ? ' selected' : '';
    $caseOptions .= '<option value="' . (int) $c['id'] . '"' . $sel . '>' . htmlspecialchars($c['title']) . '</option>';
}

$rowsHtml = '';
if (empty($documents)) {
    $rowsHtml = '<div class="cdoc-empty">
        <div class="cdoc-empty__icon" aria-hidden="true">' . legalpro_icon('folder-open') . '</div>
        <p class="cdoc-empty__title">No documents found</p>
        <p class="cdoc-empty__sub">Files shared by your legal team will appear here.</p>
    </div>';
} else {
    foreach ($documents as $doc) {
        $label = htmlspecialchars((string) ($doc['display_label'] ?? ($doc['label'] ?: $doc['filename'])));
        $caseTitle = htmlspecialchars($doc['case_title']);
        $uploaded = date('M j, Y', strtotime((string) $doc['uploaded_at']));
        $by = htmlspecialchars((string) ($doc['uploaded_by'] ?: 'Unknown'));
        $viewUrl = htmlspecialchars((string) ($doc['view_url'] ?? '../' . ltrim((string) $doc['filepath'], '/')));
        $downloadUrl = htmlspecialchars((string) ($doc['download_url'] ?? $doc['view_url'] ?? '../' . ltrim((string) $doc['filepath'], '/')));
        $downloadName = htmlspecialchars((string) ($doc['download_filename'] ?? $doc['filename']));
        $newBadge = !empty($doc['is_new']) ? '<span class="cdoc-new-badge">New</span>' : '';
        $ackBtn = '';
        if (!empty($doc['needs_ack'])) {
            $ackLabel = function_exists('client_t') ? client_t('documents.acknowledge') : 'Acknowledge receipt';
            $ackBtn = '<form method="post" class="d-inline">
                <input type="hidden" name="action" value="acknowledge">
                <input type="hidden" name="document_id" value="' . (int) $doc['id'] . '">
                <button type="submit" class="btn btn-sm btn-outline-success cdoc-touch-btn">' . htmlspecialchars($ackLabel) . '</button>
            </form>';
        } elseif (!empty($doc['is_acknowledged'])) {
            $ackBtn = '<span class="cdoc-ack-done">✓ Acknowledged</span>';
        }

        $filename = (string) ($doc['filename'] ?? 'document');
        $iconHtml = legalpro_document_file_icon_wrap($filename, 'cdoc-row__icon');

        $docHay = strtolower(implode(' ', [
            $doc['label'] ?? '',
            $doc['filename'] ?? '',
            $doc['case_title'] ?? '',
            $doc['uploaded_by'] ?? '',
            $uploaded,
        ]));

        $rowsHtml .= '<article class="cdoc-row" data-search="' . htmlspecialchars($docHay, ENT_QUOTES, 'UTF-8') . '">'
            . $iconHtml
            . '<div class="cdoc-row__body">
                <div class="cdoc-row__title">' . $label . ' ' . $newBadge . '</div>
                <div class="cdoc-row__meta">' . $caseTitle . ' · ' . $by . ' · ' . $uploaded . '</div>
            </div>
            <div class="cdoc-row__actions">
                <a href="' . $viewUrl . '" target="_blank" rel="noopener" class="btn btn-sm btn-primary cdoc-touch-btn">View</a>
                <a href="' . $downloadUrl . '" download="' . $downloadName . '" class="btn btn-sm btn-outline-primary cdoc-touch-btn">Download</a>
                ' . $ackBtn . '
            </div>
        </article>';
    }
}

$pageTitle = function_exists('client_t') ? client_t('documents.title') : 'Document Center';
$clientPageNavbar = legalpro_render_client_page_navbar(
    $pageTitle,
    $pageTitle,
    function_exists('client_t') ? client_t('documents.search_placeholder') : 'Search documents…',
    legalpro_client_page_search_options('client-documents.php')
);

$messageHtml = $message !== ''
    ? '<div class="alert alert-' . htmlspecialchars($messageType) . ' alert-dismissible fade show" role="alert">'
        . htmlspecialchars($message)
        . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>'
    : '';

$heroMeta = $newCount > 0
    ? (int) $newCount . ' new since your last visit'
    : $docCount . ' files across your matters';

$heroHtml = client_portal_render_hero([
    'kicker' => 'Client portal',
    'title' => $pageTitle,
    'subtitle' => 'Contracts, court filings, and receipts shared by your legal team.',
    'meta' => $heroMeta,
    'show_date' => true,
    'aria_label' => 'Document center overview',
    'stats' => [
        ['num' => $docCount, 'lbl' => 'Documents'],
        ['num' => $newCount, 'lbl' => 'New'],
        ['num' => $caseCount, 'lbl' => 'Cases'],
    ],
    'actions' => [
        ['url' => 'client-dashboard.php', 'label' => 'Dashboard', 'primary' => true, 'icon' => 'layout-dashboard'],
        ['url' => 'client-cases.php', 'label' => 'My cases', 'icon' => 'briefcase'],
    ],
]);

$panelHeaderHtml = client_portal_render_panel_header([
    'title' => 'Shared files',
    'subtitle' => 'View or download documents for your matters.',
    'icon' => 'folder-open',
    'badge' => $docCount . ' total',
]);

$html = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>LegalPro - {PAGE_TITLE}</title>
    <link id="pagestyle" href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
    <link href="../assets/css/app-font-montserrat.css?v=7" rel="stylesheet" />
    <?php include __DIR__ . '/../inc/client-portal-head.php'; ?>
    <link href="../assets/css/client-portal-pages.css?v=6" rel="stylesheet" />
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-client-portal client-documents-page{PORTAL_THEME_BODY_CLASS}">
<div class="min-height-300 bg-legalpro-client position-absolute w-100"></div>
<?php include __DIR__ . '/../inc/client-menunav.php'; ?>
<main class="main-content position-relative border-radius-lg">
    {CLIENT_NAVBAR}
    <div class="container-fluid py-4 px-4">
        <div class="cp-page">
        {MESSAGE}
        {HERO}
        <div class="cp-filters-card">
            <form method="get" class="cp-filters-grid">
                <div class="cp-filter-field">
                    <label class="form-label" for="cdoc-case-filter">Filter by case</label>
                    <select id="cdoc-case-filter" name="case_id" class="form-select cp-field cp-filter-select" onchange="this.form.submit()">
                        {CASE_OPTIONS}
                    </select>
                </div>
                <div class="cp-filter-field">
                    <label class="form-label" for="cdoc-search-input">Search</label>
                    <input type="search" id="cdoc-search-input" name="q" value="{SEARCH_Q}" class="form-control cp-field" placeholder="Search by filename or case…">
                </div>
                <div class="cp-filter-field cp-filter-field--action">
                    <label class="form-label" for="cdoc-search-submit">Apply</label>
                    <button type="submit" id="cdoc-search-submit" class="btn btn-primary w-100 cp-filter-submit">Search</button>
                </div>
            </form>
        </div>
        <div class="cp-panel">
            {PANEL_HEADER}
            <div class="cp-portal-table-wrap" data-portal-table-wrap data-portal-row=".cdoc-row" data-portal-per-page="10" data-portal-show-page-global="cdocShowPage">
            <div>{DOCUMENT_ROWS}</div>
            <nav class="cp-portal-pagination" data-portal-pagination aria-label="Documents pagination" hidden>
                <p class="cp-portal-pagination__info" data-portal-range></p>
                <div class="cp-portal-pagination__controls" data-portal-pages></div>
            </nav>
            </div>
        </div>
        </div>
    </div>
</main>
<script src="../assets/js/core/popper.min.js"></script>
<script src="../assets/js/core/bootstrap.min.js"></script>
<script src="../assets/js/argon-dashboard.min.js?v=2.1.0"></script>
</body>
</html>
HTML;

$html = str_replace('{PAGE_TITLE}', htmlspecialchars($pageTitle), $html);
$html = str_replace('{CLIENT_NAVBAR}', $clientPageNavbar, $html);
$html = str_replace('{MESSAGE}', $messageHtml, $html);
$html = str_replace('{HERO}', $heroHtml, $html);
$html = str_replace('{PANEL_HEADER}', $panelHeaderHtml, $html);
$html = str_replace('{CASE_OPTIONS}', $caseOptions, $html);
$html = str_replace('{SEARCH_Q}', htmlspecialchars($searchQ), $html);
$html = str_replace('{DOCUMENT_ROWS}', $rowsHtml, $html);

require_once __DIR__ . '/../inc/client-sidebar.php';
$html = inject_client_sidebar($html);

markClientDocumentsVisited($clientId);
echo $html;
