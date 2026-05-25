<?php
session_start();
require_once __DIR__ . '/../inc/db.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header('Location: admin-login.php');
    exit;
}

// Initialize message variables
$message = '';
$messageType = '';

// Handle client deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['delete_client']) || isset($_POST['force_delete_client']))) {
    $clientId = (int)$_POST['client_id'];
    $forceDelete = isset($_POST['force_delete_client']);

    try {
        // Check if client has associated records
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM cases WHERE client_id = ?");
        $stmt->execute([$clientId]);
        $caseCount = $stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE client_id = ?");
        $stmt->execute([$clientId]);
        $appointmentCount = $stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE client_id = ?");
        $stmt->execute([$clientId]);
        $paymentCount = $stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE client_id = ?");
        $stmt->execute([$clientId]);
        $invoiceCount = $stmt->fetchColumn();

        $totalAssociations = $caseCount + $appointmentCount + $paymentCount + $invoiceCount;

        // Get client info for messages
        $stmt = $pdo->prepare("SELECT first_name, last_name FROM clients WHERE id = ?");
        $stmt->execute([$clientId]);
        $clientInfo = $stmt->fetch();
        $clientName = $clientInfo ? trim($clientInfo['first_name'] . ' ' . $clientInfo['last_name']) : 'Unknown Client';

        if ($totalAssociations > 0 && !$forceDelete) {
            // Show warning with force delete option
            $message = 'Cannot delete client "' . htmlspecialchars($clientName) . '". Client has associated records: ' . $caseCount . ' case(s), ' . $appointmentCount . ' appointment(s), ' . $paymentCount . ' payment(s), ' . $invoiceCount . ' invoice(s). ' .
                      '<br><br><strong>Force Delete:</strong> This will permanently delete all appointments and cases, but preserve payment/invoice records for financial tracking. ' .
                      '<form method="POST" style="display:inline;">' .
                      '<input type="hidden" name="client_id" value="' . $clientId . '">' .
                      '<button type="submit" name="force_delete_client" class="btn btn-danger btn-sm ms-2" onclick="return confirm(\'Are you sure you want to FORCE DELETE this client? This will permanently delete all associated appointments and cases!\')">Force Delete</button>' .
                      '</form>';
            $messageType = 'warning';
        } else {
            // Proceed with deletion (either no associations or force delete)

            if ($forceDelete) {
                // Delete all associated appointments
                if ($appointmentCount > 0) {
                    $stmt = $pdo->prepare("DELETE FROM appointments WHERE client_id = ?");
                    $stmt->execute([$clientId]);
                }

                // Delete all associated cases (this will cascade to case-related data)
                if ($caseCount > 0) {
                    // First, delete case-related data manually to be safe
                    $stmt = $pdo->prepare("DELETE FROM case_comments WHERE case_id IN (SELECT id FROM cases WHERE client_id = ?)");
                    $stmt->execute([$clientId]);

                    $stmt = $pdo->prepare("DELETE FROM case_services WHERE case_id IN (SELECT id FROM cases WHERE client_id = ?)");
                    $stmt->execute([$clientId]);

                    $stmt = $pdo->prepare("DELETE FROM case_stages WHERE case_id IN (SELECT id FROM cases WHERE client_id = ?)");
                    $stmt->execute([$clientId]);

                    $stmt = $pdo->prepare("DELETE FROM case_lawyers WHERE case_id IN (SELECT id FROM cases WHERE client_id = ?)");
                    $stmt->execute([$clientId]);

                    $stmt = $pdo->prepare("DELETE FROM tasks WHERE case_id IN (SELECT id FROM cases WHERE client_id = ?)");
                    $stmt->execute([$clientId]);

                    // Delete the cases
                    $stmt = $pdo->prepare("DELETE FROM cases WHERE client_id = ?");
                    $stmt->execute([$clientId]);
                }

                // For payments and invoices, we keep the records but mark them as deleted client
                if ($paymentCount > 0) {
                    $deletionNote = " [Client Deleted: {$clientName}]";
                    $stmt = $pdo->prepare("UPDATE payments SET notes = CONCAT(COALESCE(notes, ''), ?) WHERE client_id = ?");
                    $stmt->execute([$deletionNote, $clientId]);
                }

                if ($invoiceCount > 0) {
                    $deletionNote = " [Client Deleted: {$clientName}]";
                    $stmt = $pdo->prepare("UPDATE invoices SET notes = CONCAT(COALESCE(notes, ''), ?) WHERE client_id = ?");
                    $stmt->execute([$deletionNote, $clientId]);
                }
            }

            // Also delete the associated user account if it exists
            $stmt = $pdo->prepare("SELECT user_id FROM clients WHERE id = ?");
            $stmt->execute([$clientId]);
            $client = $stmt->fetch();

            if ($client && $client['user_id']) {
                // Delete the user account
                $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([$client['user_id']]);
            }

            // Delete the client
            $stmt = $pdo->prepare("DELETE FROM clients WHERE id = ?");
            $stmt->execute([$clientId]);

            $deleteType = $forceDelete ? 'force deleted' : 'deleted';
            $message = 'Client "' . htmlspecialchars($clientName) . '" ' . $deleteType . ' successfully.';
            if ($forceDelete && $totalAssociations > 0) {
                $message .= ' Associated appointments and cases were also deleted. Payment/invoice records preserved for financial tracking.';
            }
            $messageType = 'success';
        }
    } catch (PDOException $e) {
        $message = 'Error deleting client: ' . htmlspecialchars($e->getMessage());
        $messageType = 'danger';
    }
}

// Get message from URL if redirected
if (isset($_GET['msg']) && isset($_GET['type'])) {
    $message = urldecode($_GET['msg']);
    $messageType = $_GET['type'];
}

// Fetch clients from database
try {
    $stmt = $pdo->query("
        SELECT 
            c.*,
            COUNT(DISTINCT cs.id) as active_cases,
            MAX(COALESCE(cs.created_at, c.created_at)) as last_activity
        FROM clients c
        LEFT JOIN cases cs ON cs.client_id = c.id AND cs.status != 'closed'
        GROUP BY c.id
        ORDER BY c.created_at DESC
    ");
    $clients = $stmt->fetchAll();
} catch (PDOException $e) {
    $clients = [];
    $message = 'Error loading clients: ' . htmlspecialchars($e->getMessage());
    $messageType = 'danger';
}

$html = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
	<link rel="apple-touch-icon" sizes="76x76" href="../assets/img/apple-icon.png">
	<link rel="icon" type="image/png" href="../assets/img/favicon.png">
	<title>Argon Dashboard - Clients</title>
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
		<div class="sidenav-header">
			<i class="fas fa-times p-3 cursor-pointer text-secondary opacity-5 position-absolute end-0 top-0 d-none d-xl-none" aria-hidden="true" id="iconSidenav"></i>
			<a class="navbar-brand m-0" href="../pages/dashboard.html">
				<img src="../assets/img/logo-ct-dark.png" width="26px" height="26px" class="navbar-brand-img h-100" alt="Argon logo">
				<span class="ms-1 font-weight-bold">Argon Dashboard</span>
			</a>
		</div>
		<hr class="horizontal dark mt-0">
		<div class="collapse navbar-collapse  w-auto " id="sidenav-collapse-main">
			<ul class="navbar-nav">
				<li class="nav-item"><a class="nav-link" href="../pages/dashboard.html"><div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center"><i class="ni ni-tv-2 text-dark text-sm opacity-10"></i></div><span class="nav-link-text ms-1">Dashboard</span></a></li>
				<li class="nav-item"><a class="nav-link" href="../pages/tables.php"><div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center"><i class="ni ni-collection text-dark text-sm opacity-10"></i></div><span class="nav-link-text ms-1">Cases</span></a></li>
				<li class="nav-item"><a class="nav-link active" href="../pages/clients.html"><div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center"><i class="ni ni-circle-08 text-dark text-sm opacity-10"></i></div><span class="nav-link-text ms-1">Clients</span></a></li>
				<li class="nav-item"><a class="nav-link" href="../pages/staff.html"><div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center"><i class="ni ni-badge text-dark text-sm opacity-10"></i></div><span class="nav-link-text ms-1">Staff</span></a></li>
				<li class="nav-item"><a class="nav-link" href="../pages/billing.html"><div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center"><i class="ni ni-credit-card text-dark text-sm opacity-10"></i></div><span class="nav-link-text ms-1">Finance</span></a></li>
				<li class="nav-item"><a class="nav-link" href="../pages/documents.html"><div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center"><i class="ni ni-folder-17 text-dark text-sm opacity-10"></i></div><span class="nav-link-text ms-1">Documents</span></a></li>
				<li class="nav-item"><a class="nav-link" href="../pages/appointments.html"><div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center"><i class="ni ni-time-alarm text-dark text-sm opacity-10"></i></div><span class="nav-link-text ms-1">Appointments</span></a></li>
				<li class="nav-item"><a class="nav-link" href="../pages/reports.html"><div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center"><i class="ni ni-chart-bar-32 text-dark text-sm opacity-10"></i></div><span class="nav-link-text ms-1">Reports</span></a></li>
				<li class="nav-item"><a class="nav-link" href="../pages/settings.html"><div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center"><i class="ni ni-settings text-dark text-sm opacity-10"></i></div><span class="nav-link-text ms-1">Settings</span></a></li>
				<li class="nav-item"><a class="nav-link" href="../pages/chatbot.html"><div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center"><i class="ni ni-chat-round text-dark text-sm opacity-10"></i></div><span class="nav-link-text ms-1">Chatbot</span></a></li>
			</ul>
		</div>
	</aside>
	<main class="main-content position-relative border-radius-lg ">
		<nav class="navbar navbar-main navbar-expand-lg px-0 mx-4 shadow-none border-radius-xl " id="navbarBlur" data-scroll="false">
			<div class="container-fluid py-1 px-3">
				<nav aria-label="breadcrumb">
					<ol class="breadcrumb bg-transparent mb-0 pb-0 pt-1 px-0 me-sm-6 me-5">
						<li class="breadcrumb-item text-sm"><a class="opacity-5 text-white" href="javascript:;">Pages</a></li>
						<li class="breadcrumb-item text-sm text-white active" aria-current="page">Clients</li>
					</ol>
					<h6 class="font-weight-bolder text-white mb-0">Clients</h6>
				</nav>
			</div>
		</nav>
		<div class="container-fluid py-4">
			<div class="row">
				<div class="col-12">
					<div class="card mb-4">
						<div class="card-header pb-0 d-flex justify-content-between align-items-center">
							<h6>Clients</h6>
							<a href="client-detail.php" class="btn btn-sm btn-dark">Add Client</a>
						</div>
						<div class="card-body px-0 pt-0 pb-2">
							{MESSAGE}
							<div class="table-responsive p-0">
								<table class="table align-items-center mb-0">
									<thead>
										<tr>
											<th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Client</th>
											<th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Contact</th>
											<th class="text-uppercase text-secondary text-xxs font-weight-bolder text-center opacity-7">Active Cases</th>
											<th class="text-uppercase text-secondary text-xxs font-weight-bolder text-center opacity-7">Last Activity</th>
											<th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Actions</th>
										</tr>
									</thead>
									<tbody>
										{CLIENTS_ROWS}
									</tbody>
								</table>
							</div>
						</div>
					</div>
				</div>
			</div>
			<footer class="footer pt-3  ">
				<div class="container-fluid">
					<div class="row align-items-center justify-content-lg-between">
						<div class="col-lg-6 mb-lg-0 mb-4">
							<div class="copyright text-center text-sm text-white text-lg-start">
								© <script>document.write(new Date().getFullYear())</script>, Argon Dashboard.
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
</body>
</html>
HTML;

// Generate client rows
$clientsRows = '';
if (empty($clients)) {
    $clientsRows = '<tr><td colspan="6" class="text-center py-4 text-muted">No clients found. <a href="client-detail.php">Add your first client</a></td></tr>';
} else {
    foreach ($clients as $client) {
        $fullName = htmlspecialchars($client['first_name'] . ' ' . $client['last_name']);
        $email = htmlspecialchars(isset($client['email']) && $client['email'] ? $client['email'] : 'N/A');
        $phone = htmlspecialchars(isset($client['phone']) && $client['phone'] ? $client['phone'] : 'N/A');
        $activeCases = (int)$client['active_cases'];
        $lastActivity = $client['last_activity'] ? date('m/d/y', strtotime($client['last_activity'])) : 'N/A';
        $clientId = $client['id'];
        
        // Use avatar image (you can customize this later)
        $avatarImg = '../assets/img/ivana-square.jpg';
        
        $clientsRows .= '<tr>
            <td>
                <div class="d-flex px-2 py-1">
                    <div>
                        <img src="' . $avatarImg . '" class="avatar avatar-sm me-3" alt="client">
                    </div>
                    <div class="d-flex flex-column justify-content-center">
                        <h6 class="mb-0 text-sm">' . $fullName . '</h6>
                        <p class="text-xs text-secondary mb-0">Individual</p>
                    </div>
                </div>
            </td>
            <td>
                <p class="text-xs font-weight-bold mb-0">' . $email . '</p>
                <p class="text-xs text-secondary mb-0">' . $phone . '</p>
            </td>
            <td class="align-middle text-center"><span class="text-secondary text-xs font-weight-bold">' . $activeCases . '</span></td>
            <td class="align-middle text-center"><span class="text-secondary text-xs font-weight-bold">' . $lastActivity . '</span></td>
            <td class="align-middle">
                <a href="client-detail.php?id=' . $clientId . '" class="btn btn-sm btn-primary me-2">View</a>
                <button type="button" class="btn btn-sm btn-danger" onclick="deleteClient(' . $clientId . ', \'' . addslashes($fullName) . '\')">Delete</button>
            </td>
        </tr>';
    }
}

// Add message display
$messageHtml = '';
if ($message) {
    // Check if message contains HTML (like the force delete form)
    $containsHtml = strpos($message, '<form') !== false || strpos($message, '<br>') !== false || strpos($message, '<strong>') !== false;

    if ($containsHtml) {
        // Display HTML content directly (trusted content)
        $displayMessage = $message;
    } else {
        // Escape regular text messages
        $displayMessage = htmlspecialchars($message);
    }

    $messageHtml = '<div class="alert alert-' . htmlspecialchars($messageType) . ' alert-dismissible fade show mx-3 mt-3" role="alert">
        ' . $displayMessage . '
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>';
}

$html = str_replace('{CLIENTS_ROWS}', $clientsRows, $html);
$html = str_replace('{MESSAGE}', $messageHtml, $html);

// rewrite internal links from .html to .php
$html = preg_replace('/href="([^"\']+)\.html"/i', 'href="$1.php"', $html);
ob_start(); include __DIR__ . '/../inc/menunav.php'; $sidebar = ob_get_clean();
$html = preg_replace('/<aside[\s\S]*?<\/aside>/', $sidebar, $html, 1);
ob_start(); include __DIR__ . '/../inc/footer.php'; $footer = ob_get_clean();
$html = preg_replace('/<\/body>\s*<\/html>$/i', $footer . '

<!-- Delete Client Modal -->
<div class="modal fade" id="deleteClientModal" tabindex="-1" aria-labelledby="deleteClientModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteClientModalLabel">Delete Client</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete client <strong id="clientNameToDelete"></strong>?</p>
                <div class="alert alert-warning">
                    <strong>Warning:</strong> This action cannot be undone. The client will be permanently removed from the system.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="client_id" id="clientIdToDelete">
                    <button type="submit" name="delete_client" class="btn btn-danger">Delete Client</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function deleteClient(clientId, clientName) {
    document.getElementById("clientIdToDelete").value = clientId;
    document.getElementById("clientNameToDelete").textContent = clientName;
    new bootstrap.Modal(document.getElementById("deleteClientModal")).show();
}
</script>

</body>
</html>', $html);
echo $html;
?>
