<?php
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/admin-layout.php';

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

$totalClientsCount = count($clients);
$clientsSubtitle = $totalClientsCount === 1 ? '1 total client' : $totalClientsCount . ' total clients';
$addClientBtn = '<a href="client-detail.php" class="btn btn-sm btn-legalpro-clients-new mb-0">' . legalpro_icon('plus', 'me-1') . ' Add Client</a>';
$clientsSearchHtml = legalpro_render_admin_list_search('clientsSearchInput', 'Search clients...');
$clientsSearchScript = legalpro_admin_list_search_script('clientsSearchInput', 'clientsTableBody', 'clientsFilterEmpty');

$html = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
	<link rel="apple-touch-icon" sizes="76x76" href="../assets/img/apple-icon.png">
	<link rel="icon" type="image/png" href="../assets/img/favicon.png">
	<title>LegalPro Case Manager - Clients</title>
	<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
	<link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-icons.css" rel="stylesheet" />
	<link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-svg.css" rel="stylesheet" />
	<script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
	<link id="pagestyle" href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
<link href="../assets/css/app-font-montserrat.css?v=1" rel="stylesheet" />
{ADMIN_PORTAL_HEAD}
	<link href="../assets/css/legalpro-admin-portal.css?v=26" rel="stylesheet" />
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-admin-portal admin-clients-page{PORTAL_THEME_BODY_CLASS}">
	<div class="min-height-300 bg-legalpro-admin position-absolute w-100"></div>
	<aside class="sidenav navbar navbar-vertical navbar-expand-xs" id="sidenav-main"></aside>
	<main class="main-content position-relative border-radius-lg ">
		{PAGE_NAVBAR}
		<div class="container-fluid py-4">
			{MESSAGE}

			<div class="row">
				<div class="col-12">
					<div class="card mb-4 legalpro-clients-hub">
						<div class="legalpro-clients-hub__head">
							<div>
								<h5 class="legalpro-clients-hub__title">Client Directory</h5>
								<p class="legalpro-clients-hub__count">{CLIENTS_SUBTITLE}</p>
							</div>
							{ADD_CLIENT_BTN}
						</div>
						<div class="legalpro-clients-filters">
							{CLIENTS_SEARCH}
						</div>
						<div class="card-body px-0 pt-0 pb-2 legalpro-clients-table-wrap">
							<div class="lp-admin-table-paginate" data-lp-admin-paginate data-lp-per-page="10" data-lp-row=".legalpro-clients-row">
							<div class="table-responsive">
								<table class="table legalpro-clients-table mb-0" id="clientsTable">
									<thead>
										<tr>
											<th>Client</th>
											<th>Contact</th>
											<th class="text-center">Active Cases</th>
											<th class="text-center">Last Activity</th>
											<th class="text-end">Actions</th>
										</tr>
									</thead>
									<tbody id="clientsTableBody">
										{CLIENTS_ROWS}
										<tr id="clientsFilterEmpty" class="d-none">
											<td colspan="5" class="text-center text-muted text-sm py-4 border-0">No clients match your search.</td>
										</tr>
									</tbody>
								</table>
							</div>
							<nav class="lp-admin-pagination" data-lp-pagination-nav aria-label="Clients pagination" hidden><p class="lp-admin-pagination__info" data-lp-range></p><div class="lp-admin-pagination__controls" data-lp-pages></div></nav>
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
</body>
</html>
HTML;

// Generate client rows
$clientsRows = '';
if (empty($clients)) {
    $clientsRows = '<tr><td colspan="5" class="text-center py-4 text-muted">No clients found. <a href="client-detail.php">Add your first client</a></td></tr>';
} else {
    foreach ($clients as $client) {
        $clientFullName = trim($client['first_name'] . ' ' . $client['last_name']);
        $fullName = htmlspecialchars($clientFullName);
        $initials = htmlspecialchars(legalpro_portal_initials($clientFullName, 'CL'));
        $email = htmlspecialchars(isset($client['email']) && $client['email'] ? $client['email'] : 'N/A');
        $phone = htmlspecialchars(isset($client['phone']) && $client['phone'] ? $client['phone'] : 'N/A');
        $activeCases = (int)$client['active_cases'];
        $lastActivity = $client['last_activity'] ? date('m/d/y', strtotime($client['last_activity'])) : 'N/A';
        $clientId = $client['id'];
        $clientType = !empty($client['client_type']) ? $client['client_type'] : 'Individual';
        $typeLabel = $clientType === 'Corporate' && !empty($client['business_name'])
            ? htmlspecialchars($client['business_name'])
            : htmlspecialchars($clientType);

        $activeCasesHtml = $activeCases > 0
            ? '<span class="legalpro-client-cases-count legalpro-client-cases-count--active">' . $activeCases . '</span>'
            : '<span class="legalpro-client-cases-count">0</span>';

        $searchBlob = strtolower(
            $client['first_name'] . ' ' . $client['last_name'] . ' '
            . ($client['email'] ?? '') . ' ' . ($client['phone'] ?? '') . ' '
            . $clientType . ' ' . ($client['business_name'] ?? '')
        );

        $clientsRows .= '<tr class="legalpro-clients-row legalpro-admin-list-row" data-search="' . htmlspecialchars($searchBlob, ENT_QUOTES, 'UTF-8') . '">
            <td>
                <div class="legalpro-client-cell">
                    <span class="legalpro-client-initials" aria-hidden="true">' . $initials . '</span>
                    <div class="legalpro-client-cell__text">
                        <a href="client-detail.php?id=' . $clientId . '" class="legalpro-client-name">' . $fullName . '</a>
                        <span class="legalpro-client-type">' . $typeLabel . '</span>
                    </div>
                </div>
            </td>
            <td>
                <div class="legalpro-client-contact">
                    <span class="legalpro-client-contact__email">' . $email . '</span>
                    <span class="legalpro-client-contact__phone">' . $phone . '</span>
                </div>
            </td>
            <td class="text-center">' . $activeCasesHtml . '</td>
            <td class="text-center"><span class="legalpro-client-activity">' . $lastActivity . '</span></td>
            <td class="text-end">
                <div class="legalpro-admin-list-row__actions">
                    <a href="client-detail.php?id=' . $clientId . '" class="' . legalpro_portal_accent_action_btn_class() . '">View</a>
                    <button type="button" class="btn btn-sm btn-danger mb-0" onclick="deleteClient(' . $clientId . ', \'' . addslashes($fullName) . '\')">Delete</button>
                </div>
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

    $messageHtml = '<div class="alert alert-' . htmlspecialchars($messageType) . ' alert-dismissible fade show" role="alert">
        ' . $displayMessage . '
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>';
}

$html = str_replace('{CLIENTS_ROWS}', $clientsRows, $html);
$html = str_replace('{MESSAGE}', $messageHtml, $html);
$html = str_replace('{CLIENTS_SEARCH}', $clientsSearchHtml, $html);
$html = str_replace('{CLIENTS_SUBTITLE}', htmlspecialchars($clientsSubtitle), $html);
$html = str_replace('{ADD_CLIENT_BTN}', $addClientBtn, $html);
$html = str_replace('{PORTAL_THEME_BODY_CLASS}', legalpro_portal_theme_body_class(), $html);

// rewrite internal links from .html to .php
$html = preg_replace('/href="([^"\']+)\.html"/i', 'href="$1.php"', $html);
$html = legalpro_apply_admin_page_shell($html, 'Clients', 'Manage your client directory');
ob_start(); include __DIR__ . '/../inc/menunav.php'; $sidebar = ob_get_clean();
$html = preg_replace('/<aside[\s\S]*?<\/aside>/', $sidebar, $html, 1);
ob_start(); include __DIR__ . '/../inc/footer.php'; $footer = ob_get_clean();
$html = preg_replace('/<\/body>\s*<\/html>$/i', $clientsSearchScript . $footer . '

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
echo legalpro_apply_copyright_line($html);
?>
