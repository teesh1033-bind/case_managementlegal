<?php
session_start();
require_once __DIR__ . '/../inc/db.php';

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

// Fetch cases with client and lawyer info
try {
    $stmt = $pdo->query("
        SELECT 
            c.*,
            cl.first_name AS client_first_name,
            cl.last_name AS client_last_name,
            GROUP_CONCAT(CONCAT(l.first_name, ' ', l.last_name) SEPARATOR ', ') AS lawyer_names
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

// Build cases table rows
$casesRows = '';
if (empty($cases)) {
    $casesRows = '<tr><td colspan="5" class="text-center py-5">
        <div class="text-center">
            <i class="ni ni-collection text-muted" style="font-size: 3rem;"></i>
            <p class="text-muted mt-3 mb-0">No cases found.</p>
            <p class="text-xs text-muted mb-0"><a href="case-new.php">Create your first case</a></p>
        </div>
    </td></tr>';
} else {
    foreach ($cases as $case) {
        $caseId = (int)$case['id'];
        $caseNumber = 'C-' . str_pad($caseId, 4, '0', STR_PAD_LEFT);
        $title = htmlspecialchars($case['title']);
        $clientFirstName = isset($case['client_first_name']) ? $case['client_first_name'] : '';
        $clientLastName = isset($case['client_last_name']) ? $case['client_last_name'] : '';
        $clientName = trim($clientFirstName . ' ' . $clientLastName) ?: 'Unassigned';
        $lawyerName = isset($case['lawyer_names']) && $case['lawyer_names'] ? $case['lawyer_names'] : 'Unassigned';
        
        $status = isset($case['status']) ? strtolower($case['status']) : 'open';
        $statusLabel = ucfirst(str_replace('_', ' ', $status));
        $badgeClass = 'bg-gradient-info';
        if ($status === 'in_progress') {
            $badgeClass = 'bg-gradient-warning';
        } elseif ($status === 'closed') {
            $badgeClass = 'bg-gradient-success';
        }
        
        $dueDate = '';
        if (isset($case['expected_completion']) && $case['expected_completion']) {
            $dueDate = date('m/d/y', strtotime($case['expected_completion']));
        } else {
            $dueDate = 'N/A';
        }
        
        // JSON encode case data for modal
        $caseDataJson = htmlspecialchars(json_encode([
            'id' => $caseId,
            'case_number' => $caseNumber,
            'title' => $case['title'],
            'client_id' => $case['client_id'],
            'client_name' => $clientName,
            'user_id' => isset($case['user_id']) ? $case['user_id'] : '',
            'lawyer_name' => $lawyerName,
            'description' => isset($case['description']) ? $case['description'] : '',
            'status' => $status,
            'priority' => isset($case['priority']) ? $case['priority'] : 'Normal',
            'category' => isset($case['category']) ? $case['category'] : 'Civil',
            'estimated_fees' => isset($case['estimated_fees']) ? $case['estimated_fees'] : '',
            'start_date' => isset($case['start_date']) && $case['start_date'] ? date('Y-m-d', strtotime($case['start_date'])) : '',
            'expected_completion' => isset($case['expected_completion']) && $case['expected_completion'] ? date('Y-m-d', strtotime($case['expected_completion'])) : ''
        ]), ENT_QUOTES);
        
        $casesRows .= '
        <tr style="cursor: pointer;" onclick="window.location.href=\'case-view.php?id=' . $caseId . '\'">
            <td>
                <div class="d-flex px-2 py-1">
                    <div class="icon icon-shape icon-sm bg-gradient-primary shadow text-center border-radius-md me-3">
                        <i class="ni ni-collection text-white text-xs opacity-10"></i>
                    </div>
                    <div class="d-flex flex-column justify-content-center">
                        <h6 class="mb-0 text-sm">' . $caseNumber . ' · ' . $title . '</h6>
                        <p class="text-xs text-secondary mb-0">' . htmlspecialchars($clientName) . '</p>
                    </div>
                </div>
            </td>
            <td>
                <p class="text-xs font-weight-bold mb-0">Client: ' . htmlspecialchars($clientName) . '</p>
                <p class="text-xs text-secondary mb-0">Lawyer: ' . htmlspecialchars($lawyerName) . '</p>
            </td>
            <td class="align-middle text-center text-sm">
                <span class="badge badge-sm ' . $badgeClass . '">' . $statusLabel . '</span>
            </td>
            <td class="align-middle text-center">
                <span class="text-secondary text-xs font-weight-bold">' . $dueDate . '</span>
            </td>
            <td class="align-middle">
                <div class="d-flex gap-1">
                    <a class="btn btn-sm btn-outline-primary mb-0" href="case-view.php?id=' . $caseId . '" title="View Details">
                        <i class="ni ni-zoom-split-in"></i> View
                    </a>
                    <a class="btn btn-sm btn-outline-dark mb-0" href="case-edit.php?id=' . $caseId . '" title="Edit Case">
                        <i class="ni ni-settings"></i> Edit
                    </a>
                    <form method="post" class="d-inline" onsubmit="return confirm(\'Are you sure you want to delete case ' . $caseNumber . '? This action cannot be undone.\');" onclick="event.stopPropagation();">
                        <input type="hidden" name="form_type" value="delete">
                        <input type="hidden" name="case_id" value="' . $caseId . '">
                        <button class="btn btn-sm btn-outline-danger mb-0" type="submit" title="Delete Case">
                            <i class="ni ni-fat-remove"></i>
                        </button>
                    </form>
                    <a class="btn btn-sm btn-outline-info mb-0" href="case-contract.php?id=' . $caseId . '" target="_blank" onclick="event.stopPropagation();" title="Generate Contract">
                        <i class="ni ni-single-copy-04"></i> Contract
                    </a>
                </div>
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
<link href="../assets/css/app-font-montserrat.css?v=1" rel="stylesheet" />
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-admin-portal">
	<div class="min-height-300 bg-legalpro-admin position-absolute w-100"></div>
	<aside class="sidenav bg-white navbar navbar-vertical navbar-expand-xs border-0 border-radius-xl my-3 fixed-start ms-4 " id="sidenav-main">
	</aside>
	<main class="main-content position-relative border-radius-lg ">
		<nav class="navbar navbar-main navbar-expand-lg px-0 mx-4 shadow-none border-radius-xl" id="navbarBlur" data-scroll="false">
			<div class="container-fluid py-1 px-3">
				<nav aria-label="breadcrumb">
					<ol class="breadcrumb bg-transparent mb-0 pb-0 pt-1 px-0 me-sm-6 me-5">
						<li class="breadcrumb-item text-sm"><a class="opacity-5 text-white" href="javascript:;">Pages</a></li>
						<li class="breadcrumb-item text-sm text-white active" aria-current="page">Cases</li>
					</ol>
					<h6 class="font-weight-bolder text-white mb-0">Cases</h6>
				</nav>
			</div>
		</nav>
		<div class="container-fluid py-4">
			{MESSAGE}
			
			<!-- Page Header -->
			<div class="row mb-4">
				<div class="col-12">
					<div class="card">
						<div class="card-body p-3">
							<div class="row align-items-center">
								<div class="col-lg-8">
									<h5 class="mb-0">Case Management</h5>
									<p class="text-sm text-muted mb-0">View and manage all cases</p>
								</div>
								<div class="col-lg-4 text-end">
									<a href="case-new.php" class="btn btn-dark btn-sm mb-0">
										<i class="ni ni-fat-add me-1"></i> New Case
									</a>
								</div>
							</div>
						</div>
					</div>
				</div>
			</div>

			<!-- Cases Table -->
			<div class="row">
				<div class="col-12">
					<div class="card mb-4">
						<div class="card-header pb-0">
							<h6>Cases Overview</h6>
						</div>
						<div class="card-body px-0 pt-0 pb-2">
							<div class="table-responsive p-0">
								<table class="table align-items-center mb-0">
									<thead>
										<tr>
											<th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Case</th>
											<th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Client / Lawyer</th>
											<th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Status</th>
											<th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Due</th>
											<th class="text-secondary opacity-7"></th>
										</tr>
									</thead>
									<tbody>
										{CASES_ROWS}
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
							<div class="copyright text-center text-sm text-muted text-lg-start">
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
$html = str_replace('{CASES_ROWS}', $casesRows, $html);

// rewrite internal links from .html to .php
$html = preg_replace('/href="([^"\']+)\.html"/i', 'href="$1.php"', $html);
ob_start(); include __DIR__ . '/../inc/menunav.php'; $sidebar = ob_get_clean();
$html = preg_replace('/<aside[\s\S]*?<\/aside>/', $sidebar, $html, 1);
ob_start(); include __DIR__ . '/../inc/footer.php'; $footer = ob_get_clean();
$html = preg_replace('/<\/body>\s*<\/html>$/i', $footer . "\n</body>\n</html>", $html);

echo $html;
?>
