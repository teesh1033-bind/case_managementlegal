<?php
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/admin-layout.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header('Location: admin-login.php');
    exit;
}

$message = '';
$messageType = '';
if (isset($_GET['msg']) && isset($_GET['type'])) {
    $message = urldecode($_GET['msg']);
    $messageType = $_GET['type'];
}

// Handle delete request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_type']) && $_POST['form_type'] === 'delete') {
    $caseId = isset($_POST['case_id']) ? (int)$_POST['case_id'] : 0;
    if ($caseId > 0) {
        try {
            $stmt = $pdo->prepare("DELETE FROM cases WHERE id = ?");
            $stmt->execute([$caseId]);
            $msg = 'Case deleted successfully.';
            header('Location: tables.php?msg=' . urlencode($msg) . '&type=success');
            exit;
        } catch (PDOException $e) {
            $message = 'Error deleting case: ' . htmlspecialchars($e->getMessage());
            $messageType = 'danger';
        }
    } else {
        $message = 'Invalid case ID.';
        $messageType = 'danger';
    }
}

// Ensure cases table has all required columns (same as case-new.php)
try {
    $pdo->query("ALTER TABLE cases ADD COLUMN user_id INT NULL AFTER client_id");
} catch (PDOException $e) {
    if (stripos($e->getMessage(), 'duplicate column name') === false) {
        throw $e;
    }
}
try {
    $pdo->query("ALTER TABLE cases ADD COLUMN priority VARCHAR(50) DEFAULT 'Normal' AFTER status");
} catch (PDOException $e) {
    if (stripos($e->getMessage(), 'duplicate column name') === false) {
        throw $e;
    }
}
try {
    $pdo->query("ALTER TABLE cases ADD COLUMN category VARCHAR(50) DEFAULT 'Civil' AFTER priority");
} catch (PDOException $e) {
    if (stripos($e->getMessage(), 'duplicate column name') === false) {
        throw $e;
    }
}
try {
    $pdo->query("ALTER TABLE cases ADD COLUMN estimated_fees DECIMAL(10,2) DEFAULT 0.00 AFTER category");
} catch (PDOException $e) {
    if (stripos($e->getMessage(), 'duplicate column name') === false) {
        throw $e;
    }
}
try {
    $pdo->query("ALTER TABLE cases ADD COLUMN start_date DATE NULL AFTER estimated_fees");
} catch (PDOException $e) {
    if (stripos($e->getMessage(), 'duplicate column name') === false) {
        throw $e;
    }
}
try {
    $pdo->query("ALTER TABLE cases ADD COLUMN expected_completion DATE NULL AFTER start_date");
} catch (PDOException $e) {
    if (stripos($e->getMessage(), 'duplicate column name') === false) {
        throw $e;
    }
}

$hasCaseServicesTable = false;
try {
    $hasCaseServicesTable = (bool) $pdo->query("SHOW TABLES LIKE 'case_services'")->fetch();
} catch (PDOException $e) {
    $hasCaseServicesTable = false;
}

$serviceSelect = $hasCaseServicesTable
    ? "(
            SELECT cs.service_name
            FROM case_services cs
            WHERE cs.case_id = c.id
            ORDER BY cs.created_at ASC
            LIMIT 1
        ) AS primary_service"
    : 'NULL AS primary_service';

// Fetch cases with client, lawyer, and primary service
try {
    $stmt = $pdo->query("
        SELECT 
            c.*,
            cl.first_name AS client_first_name,
            cl.last_name AS client_last_name,
            GROUP_CONCAT(DISTINCT CONCAT(l.first_name, ' ', l.last_name) SEPARATOR ', ') AS lawyer_names,
            {$serviceSelect}
        FROM cases c
        LEFT JOIN clients cl ON cl.id = c.client_id
        LEFT JOIN case_lawyers clw ON clw.case_id = c.id
        LEFT JOIN lawyers l ON l.id = clw.lawyer_id AND l.is_active = 1
        GROUP BY c.id
        ORDER BY c.created_at DESC
    ");
    $cases = $stmt->fetchAll();
} catch (PDOException $e) {
    $cases = [];
    if (!$message) {
        $message = 'Error loading cases: ' . htmlspecialchars($e->getMessage());
        $messageType = 'danger';
    }
}

$iconCaseEmpty = legalpro_icon('briefcase');

// Build cases table rows
$casesRows = '';
if (empty($cases)) {
    $casesRows = '<tr class="legalpro-cases-empty"><td colspan="9" class="text-center">
        <div class="legalpro-cases-empty-icon dashboard-stat-icon-wrap dashboard-stat-icon-wrap--primary d-inline-flex align-items-center justify-content-center">' . $iconCaseEmpty . '</div>
        <p class="text-muted mb-2 font-weight-bold">No cases found.</p>
        <p class="text-xs text-muted mb-3">Create a case to start managing clients, documents, and billing.</p>
        <a href="case-new.php" class="btn btn-sm btn-legalpro-cases-new">+ New Case</a>
    </td></tr>';
} else {
    foreach ($cases as $case) {
        $caseId = (int) $case['id'];
        $createdAt = isset($case['created_at']) ? $case['created_at'] : null;
        $caseNumber = legalpro_format_case_number($caseId, $createdAt);
        $title = htmlspecialchars((string) ($case['title'] ?? ''));
        $clientFirstName = isset($case['client_first_name']) ? $case['client_first_name'] : '';
        $clientLastName = isset($case['client_last_name']) ? $case['client_last_name'] : '';
        $clientName = trim($clientFirstName . ' ' . $clientLastName) ?: 'Unassigned';
        $clientDisplay = htmlspecialchars(strtolower($clientName));

        $category = isset($case['category']) && $case['category'] !== '' ? $case['category'] : 'General';
        $titleSub = htmlspecialchars($category);

        $serviceName = !empty($case['primary_service'])
            ? $case['primary_service']
            : ($case['title'] ?? '—');
        $serviceDisplay = htmlspecialchars((string) $serviceName);

        $priority = isset($case['priority']) ? (string) $case['priority'] : 'Normal';
        $status = isset($case['status']) ? strtolower((string) $case['status']) : 'open';

        $feeDisplay = legalpro_format_case_fee($case['estimated_fees'] ?? 0);

        $deadline = '—';
        if (!empty($case['expected_completion'])) {
            $deadline = date('M j, Y', strtotime($case['expected_completion']));
        }

        $searchBlob = strtolower($caseNumber . ' ' . ($case['title'] ?? '') . ' ' . $clientName . ' ' . $serviceName . ' ' . $category);
        $priorityFilter = strtolower($priority);
        $statusFilter = $status;

        $casesRows .= '
        <tr class="legalpro-cases-row" data-search="' . htmlspecialchars($searchBlob, ENT_QUOTES) . '" data-status="' . htmlspecialchars($statusFilter, ENT_QUOTES) . '" data-priority="' . htmlspecialchars($priorityFilter, ENT_QUOTES) . '">
            <td><a class="legalpro-case-number" href="case-view.php?id=' . $caseId . '">' . htmlspecialchars($caseNumber) . '</a></td>
            <td>
                <div class="min-width-0 py-1">
                    <p class="legalpro-case-title__main mb-0">' . $title . '</p>
                    <p class="legalpro-case-title__sub">' . $titleSub . '</p>
                </div>
            </td>
            <td><span class="legalpro-case-client">' . $clientDisplay . '</span></td>
            <td>' . $serviceDisplay . '</td>
            <td class="legalpro-case-fee">' . htmlspecialchars($feeDisplay) . '</td>
            <td>' . legalpro_case_priority_badge($priority) . '</td>
            <td>' . htmlspecialchars($deadline) . '</td>
            <td>' . legalpro_case_status_badge($status) . '</td>
            <td class="text-end">
                <a class="btn btn-sm btn-legalpro-case-open mb-0" href="case-view.php?id=' . $caseId . '">Open</a>
            </td>
        </tr>';
    }
}

// Render message block
$messageHtml = '';
if ($message) {
    $messageHtml = '<div class="alert alert-' . htmlspecialchars($messageType) . ' alert-dismissible fade show" role="alert">
        ' . htmlspecialchars($message) . '
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>';
}

$totalCasesCount = count($cases);
$casesSubtitle = $totalCasesCount === 1 ? '1 total case' : $totalCasesCount . ' total cases';

$newCaseBtn = '<a href="case-new.php" class="btn btn-sm btn-legalpro-cases-new mb-0">' . legalpro_icon('plus', 'me-1') . ' New Case</a>';
$casesSearchIcon = legalpro_icon('search');

$html = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
	<link rel="apple-touch-icon" sizes="76x76" href="../assets/img/apple-icon.png">
	<link rel="icon" type="image/png" href="../assets/img/favicon.png">
	<title>LegalPro Case Manager - Cases</title>
	<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
	<link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-icons.css" rel="stylesheet" />
	<link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-svg.css" rel="stylesheet" />
	<script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
	<link id="pagestyle" href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
	<link href="../assets/css/app-font-montserrat.css?v=2" rel="stylesheet" />
	<link href="../assets/css/legalpro-admin-portal.css?v=27" rel="stylesheet" />
	<?php legalpro_icons_asset_links(); ?>
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-admin-portal admin-cases-page">
	<div class="min-height-300 bg-legalpro-admin position-absolute w-100"></div>
	<aside class="sidenav navbar navbar-vertical navbar-expand-xs" id="sidenav-main"></aside>
	<main class="main-content position-relative border-radius-lg ">
		<nav class="navbar navbar-main navbar-expand-lg px-0 shadow-none border-radius-xl" id="navbarBlur" data-scroll="false">
			<div class="container-fluid py-1 px-3">
				<div>
					<h6 class="font-weight-bolder mb-0">Cases</h6>
					<p class="dashboard-welcome-sub mb-0 mt-1">Legal case workspaces — manage clients, documents, billing &amp; more</p>
				</div>
			</div>
		</nav>
		<div class="container-fluid py-4">
			{MESSAGE}

			<div class="row">
				<div class="col-12">
					<div class="card mb-4 legalpro-cases-hub">
						<div class="legalpro-cases-hub__head">
							<div>
								<h5 class="legalpro-cases-hub__title">Case Management</h5>
								<p class="legalpro-cases-hub__count">{CASES_SUBTITLE}</p>
							</div>
							{NEW_CASE_BTN}
						</div>
						<div class="legalpro-cases-filters">
							<div class="legalpro-cases-search">
								{CASES_SEARCH_ICON}
								<input type="search" class="form-control" id="casesSearchInput" placeholder="Search cases..." autocomplete="off" aria-label="Search cases">
							</div>
							<select class="form-select" id="casesStatusFilter" aria-label="Filter by status">
								<option value="">All statuses</option>
								<option value="open">Pending</option>
								<option value="in_progress">In Progress</option>
								<option value="waiting_for_client">Waiting For Client</option>
								<option value="closed">Closed</option>
							</select>
							<select class="form-select" id="casesPriorityFilter" aria-label="Filter by priority">
								<option value="">All priorities</option>
								<option value="high">High</option>
								<option value="normal">Medium</option>
								<option value="urgent">Urgent</option>
							</select>
						</div>
						<div class="card-body px-0 pt-0 pb-2 legalpro-cases-table-wrap">
							<div class="lp-admin-table-paginate" data-lp-admin-paginate data-lp-per-page="10" data-lp-row=".legalpro-cases-row">
							<div class="table-responsive">
								<table class="table legalpro-cases-table mb-0" id="casesTable">
									<thead>
										<tr>
											<th>Case #</th>
											<th>Title</th>
											<th>Client</th>
											<th>Service</th>
											<th>Fee</th>
											<th>Priority</th>
											<th>Deadline</th>
											<th>Status</th>
											<th class="text-end"></th>
										</tr>
									</thead>
									<tbody id="casesTableBody">
										{CASES_ROWS}
										<tr id="casesFilterEmpty" class="d-none">
											<td colspan="9" class="text-center text-muted text-sm py-4 border-0">No cases match your filters.</td>
										</tr>
									</tbody>
								</table>
							</div>
							<nav class="lp-admin-pagination" data-lp-pagination-nav aria-label="Cases pagination" hidden><p class="lp-admin-pagination__info" data-lp-range></p><div class="lp-admin-pagination__controls" data-lp-pages></div></nav>
							</div>
						</div>
					</div>
				</div>
			</div>


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
	<script src="../assets/js/core/popper.min.js"></script>
	<script src="../assets/js/core/bootstrap.min.js"></script>
	<script src="../assets/js/plugins/perfect-scrollbar.min.js"></script>
	<script src="../assets/js/plugins/smooth-scrollbar.min.js"></script>
	<script src="../assets/js/argon-dashboard.min.js?v=2.1.0"></script>
	<script src="../assets/js/spa-nav.js"></script>
	<script>
	(function () {
		var searchInput = document.getElementById('casesSearchInput');
		var statusFilter = document.getElementById('casesStatusFilter');
		var priorityFilter = document.getElementById('casesPriorityFilter');
		var tbody = document.getElementById('casesTableBody');
		var emptyNote = document.getElementById('casesFilterEmpty');
		if (!tbody) {
			return;
		}

		function applyCasesFilters() {
			var q = (searchInput && searchInput.value ? searchInput.value : '').trim().toLowerCase();
			var status = statusFilter ? statusFilter.value : '';
			var priority = priorityFilter ? priorityFilter.value : '';
			var rows = tbody.querySelectorAll('.legalpro-cases-row');
			var visible = 0;

			rows.forEach(function (row) {
				var match = true;
				if (q && row.getAttribute('data-search').indexOf(q) === -1) {
					match = false;
				}
				if (status && row.getAttribute('data-status') !== status) {
					match = false;
				}
				if (priority && row.getAttribute('data-priority') !== priority) {
					match = false;
				}
				row.classList.toggle('lp-admin-row-filtered', !match);
				if (match) {
					visible++;
				}
			});

			if (emptyNote) {
				emptyNote.classList.toggle('d-none', visible > 0 || rows.length === 0);
			}

			var paginateWrap = tbody.closest('[data-lp-admin-paginate]');
			if (paginateWrap && window.LegalproAdminTablePagination) {
				window.LegalproAdminTablePagination.refresh(paginateWrap);
			}
		}

		if (searchInput) {
			searchInput.addEventListener('input', applyCasesFilters);
		}
		if (statusFilter) {
			statusFilter.addEventListener('change', applyCasesFilters);
		}
		if (priorityFilter) {
			priorityFilter.addEventListener('change', applyCasesFilters);
		}
	})();
	</script>
</body>
</html>
HTML;


$html = str_replace('{MESSAGE}', $messageHtml, $html);
$html = str_replace('{NEW_CASE_BTN}', $newCaseBtn, $html);
$html = str_replace('{CASES_SEARCH_ICON}', $casesSearchIcon, $html);
$html = str_replace('{CASES_SUBTITLE}', htmlspecialchars($casesSubtitle), $html);
$html = str_replace('{CASES_ROWS}', $casesRows, $html);

// rewrite internal links from .html to .php
$html = preg_replace('/href="([^"\']+)\.html"/i', 'href="$1.php"', $html);
ob_start(); include __DIR__ . '/../inc/menunav.php'; $sidebar = ob_get_clean();
$html = preg_replace('/<aside[\s\S]*?<\/aside>/', $sidebar, $html, 1);
ob_start(); include __DIR__ . '/../inc/footer.php'; $footer = ob_get_clean();
$html = preg_replace('/<\/body>\s*<\/html>$/i', $footer . "\n</body>\n</html>", $html);

echo legalpro_apply_copyright_line($html);
?>
