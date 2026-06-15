<?php
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../lib/client-portal-features.php';
require_once __DIR__ . '/../inc/admin-layout.php';
require_once __DIR__ . '/../inc/client-portal-navbar.php';

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

$caseOptions = '<option value="">All cases</option>';
foreach ($clientCases as $c) {
    $sel = $filterCaseId === (int) $c['id'] ? ' selected' : '';
    $caseOptions .= '<option value="' . (int) $c['id'] . '"' . $sel . '>' . htmlspecialchars($c['title']) . '</option>';
}

$rowsHtml = '';
if (empty($documents)) {
    $rowsHtml = '<div class="cdoc-empty">
        <div class="cdoc-empty__icon" aria-hidden="true">📁</div>
        <p class="cdoc-empty__title">No documents found</p>
        <p class="cdoc-empty__sub">Files shared by your legal team will appear here.</p>
    </div>';
} else {
    foreach ($documents as $doc) {
        $label = htmlspecialchars($doc['label'] ?: $doc['filename']);
        $caseTitle = htmlspecialchars($doc['case_title']);
        $uploaded = date('M j, Y', strtotime((string) $doc['uploaded_at']));
        $by = htmlspecialchars((string) ($doc['uploaded_by'] ?: 'Unknown'));
        $filepath = htmlspecialchars('../' . ltrim((string) $doc['filepath'], '/'));
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

        $docHay = strtolower(implode(' ', [
            $doc['label'] ?? '',
            $doc['filename'] ?? '',
            $doc['case_title'] ?? '',
            $doc['uploaded_by'] ?? '',
            $uploaded,
        ]));

        $rowsHtml .= '<article class="cdoc-row" data-search="' . htmlspecialchars($docHay, ENT_QUOTES, 'UTF-8') . '">
            <div class="cdoc-row__icon" aria-hidden="true">📄</div>
            <div class="cdoc-row__body">
                <div class="cdoc-row__title">' . $label . ' ' . $newBadge . '</div>
                <div class="cdoc-row__meta">' . $caseTitle . ' · ' . $by . ' · ' . $uploaded . '</div>
            </div>
            <div class="cdoc-row__actions">
                <a href="' . $filepath . '" target="_blank" rel="noopener" class="btn btn-sm btn-primary cdoc-touch-btn">View</a>
                <a href="' . $filepath . '" download class="btn btn-sm btn-outline-primary cdoc-touch-btn">Download</a>
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

$newBanner = $newCount > 0
    ? '<span class="cdoc-header-badge">' . (int) $newCount . ' new since last visit</span>'
    : '';

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
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-client-portal client-documents-page{PORTAL_THEME_BODY_CLASS}">
<div class="min-height-300 bg-legalpro-client position-absolute w-100"></div>
<?php include __DIR__ . '/../inc/client-menunav.php'; ?>
<main class="main-content position-relative border-radius-lg">
    {CLIENT_NAVBAR}
    <div class="container-fluid py-4 px-4">
        {MESSAGE}
        <div class="cdoc-header">
            <div>
                <h5 class="cdoc-header__title">{PAGE_TITLE}</h5>
                <p class="cdoc-header__sub">Contracts, court filings, and receipts in one place</p>
            </div>
            {NEW_BANNER}
        </div>
        <div class="cdoc-filters card mb-4">
            <div class="card-body">
                <form method="get" class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label text-sm font-weight-bold">Filter by case</label>
                        <select name="case_id" class="form-select cdoc-field" onchange="this.form.submit()">
                            {CASE_OPTIONS}
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-sm font-weight-bold">Search</label>
                        <input type="search" name="q" value="{SEARCH_Q}" class="form-control cdoc-field" placeholder="Search by filename or case…">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label text-sm font-weight-bold" for="cdoc-search-submit">Apply</label>
                        <button type="submit" id="cdoc-search-submit" class="btn btn-primary w-100 cdoc-touch-btn cdoc-search-btn">Search</button>
                    </div>
                </form>
            </div>
        </div>
        <div class="cdoc-list card">
            <div class="card-body p-0">
                {DOCUMENT_ROWS}
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
$html = str_replace('{NEW_BANNER}', $newBanner, $html);
$html = str_replace('{CASE_OPTIONS}', $caseOptions, $html);
$html = str_replace('{SEARCH_Q}', htmlspecialchars($searchQ), $html);
$html = str_replace('{DOCUMENT_ROWS}', $rowsHtml, $html);

require_once __DIR__ . '/../inc/client-sidebar.php';
$html = inject_client_sidebar($html);

markClientDocumentsVisited($clientId);
echo $html;
