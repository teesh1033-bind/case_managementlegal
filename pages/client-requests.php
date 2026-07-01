<?php
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../lib/client-self-service.php';
require_once __DIR__ . '/../lib/client-locale.php';
require_once __DIR__ . '/../lib/client-portal-i18n.php';
require_once __DIR__ . '/../inc/client-portal-navbar.php';

if (!isset($_SESSION['client_id'])) {
    header('Location: login.php');
    exit;
}

$clientId = (int) $_SESSION['client_id'];
$clientName = $_SESSION['client_name'] ?? 'Client';
$requests = [];

legalpro_client_requests_ensure_tables($pdo);

try {
    $stmt = $pdo->prepare("
        SELECT r.*, c.title AS case_title
        FROM client_requests r
        LEFT JOIN cases c ON c.id = r.case_id
        WHERE r.client_id = ?
        ORDER BY r.created_at DESC
        LIMIT 50
    ");
    $stmt->execute([$clientId]);
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('client-requests: ' . $e->getMessage());
}

$clientPageNavbar = legalpro_render_client_page_navbar(
    client_t('requests.navbar'),
    client_t('requests.navbar'),
    '',
    [
        'client_name' => $clientName,
        'include_search' => false,
    ]
);

$assistantLink = '<a href="chatbot.php">' . htmlspecialchars(client_t('requests.open_assistant')) . '</a>';
$rowsHtml = '';
if (!$requests) {
    $rowsHtml = '<tr><td colspan="5" class="text-center text-muted py-4">'
        . client_t('requests.empty', ['assistant' => $assistantLink])
        . '</td></tr>';
} else {
    foreach ($requests as $r) {
        $rowsHtml .= '<tr class="cr-request-row">'
            . '<td>' . htmlspecialchars(ucfirst((string) $r['request_type'])) . '</td>'
            . '<td>' . htmlspecialchars((string) ($r['subject'] ?? '')) . '</td>'
            . '<td>' . htmlspecialchars((string) ($r['case_title'] ?? '—')) . '</td>'
            . '<td><span class="badge bg-secondary">' . htmlspecialchars((string) $r['status']) . '</span></td>'
            . '<td>' . htmlspecialchars(date('M j, Y g:i A', strtotime((string) $r['created_at']))) . '</td>'
            . '</tr>';
    }
}

$html = <<<'HTML'
<!DOCTYPE html>
<html lang="{HTML_LANG}">
<head>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
	<title>{PAGE_TITLE} · LegalPro</title>
	<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700&display=swap" rel="stylesheet" />
	<link id="pagestyle" href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
	<link href="../assets/css/app-font-montserrat.css?v=1" rel="stylesheet" />
	{CLIENT_PORTAL_HEAD}
	<link href="../assets/css/client-portal-pages.css?v=6" rel="stylesheet" />
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-client-portal client-requests-page">
	<div class="min-height-300 bg-primary position-absolute w-100"></div>
	<aside class="sidenav bg-white navbar navbar-vertical navbar-expand-xs border-0 border-radius-xl my-3 fixed-start ms-4" id="sidenav-main"></aside>
	<main class="main-content position-relative border-radius-lg">
		{CLIENT_NAVBAR}
		<div class="container-fluid py-4">
			<div class="card">
				<div class="card-header pb-0 d-flex justify-content-between align-items-center">
					<div>
						<h6 class="mb-0">{LBL_TITLE}</h6>
						<p class="text-sm text-muted mb-0">{LBL_SUBTITLE}</p>
					</div>
					<a href="chatbot.php" class="btn btn-sm btn-primary mb-0">{LBL_ASK_AI}</a>
				</div>
				<div class="card-body px-0 pt-0 pb-2">
					<div class="cp-portal-table-wrap" data-portal-table-wrap data-portal-row=".cr-request-row" data-portal-per-page="10" data-portal-show-page-global="crRequestShowPage">
					<div class="table-responsive p-0">
						<table class="table align-items-center mb-0">
							<thead>
								<tr>
									<th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">{COL_TYPE}</th>
									<th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">{COL_SUBJECT}</th>
									<th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">{COL_CASE}</th>
									<th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">{COL_STATUS}</th>
									<th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">{COL_DATE}</th>
								</tr>
							</thead>
							<tbody>{ROWS}</tbody>
						</table>
					</div>
					<nav class="cp-portal-pagination" data-portal-pagination aria-label="{PAGINATION_ARIA}" hidden>
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
	<script src="../assets/js/legalpro-sidenav-bootstrap.js?v=1"></script>
<script src="../assets/js/argon-dashboard.min.js?v=2.1.0"></script>
</body>
</html>
HTML;

$html = str_replace('{HTML_LANG}', client_portal_html_lang(), $html);
$html = str_replace('{PAGE_TITLE}', htmlspecialchars(client_t('requests.page_title')), $html);
$html = str_replace('{LBL_TITLE}', htmlspecialchars(client_t('requests.title')), $html);
$html = str_replace('{LBL_SUBTITLE}', htmlspecialchars(client_t('requests.subtitle')), $html);
$html = str_replace('{LBL_ASK_AI}', htmlspecialchars(client_t('requests.ask_ai')), $html);
$html = str_replace('{COL_TYPE}', htmlspecialchars(client_t('requests.col_type')), $html);
$html = str_replace('{COL_SUBJECT}', htmlspecialchars(client_t('requests.col_subject')), $html);
$html = str_replace('{COL_CASE}', htmlspecialchars(client_t('requests.col_case')), $html);
$html = str_replace('{COL_STATUS}', htmlspecialchars(client_t('requests.col_status')), $html);
$html = str_replace('{COL_DATE}', htmlspecialchars(client_t('requests.col_date')), $html);
$html = str_replace('{PAGINATION_ARIA}', htmlspecialchars(client_t('requests.pagination_aria')), $html);

ob_start();
include __DIR__ . '/../inc/client-portal-head.php';
$head = ob_get_clean();
ob_start();
include __DIR__ . '/../inc/client-menunav.php';
$sidebar = ob_get_clean();

$html = str_replace('{CLIENT_PORTAL_HEAD}', $head, $html);
$html = str_replace('{CLIENT_NAVBAR}', $clientPageNavbar, $html);
$html = str_replace('{ROWS}', $rowsHtml, $html);
$html = preg_replace('/<aside[\s\S]*?<\/aside>/', $sidebar, $html, 1);

require_once __DIR__ . '/../inc/client-sidebar.php';
$html = inject_client_sidebar($html);

echo $html;
