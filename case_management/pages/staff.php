<?php
require_once __DIR__ . '/../inc/db.php';

$html = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
	<link rel="apple-touch-icon" sizes="76x76" href="../assets/img/apple-icon.png">
	<link rel="icon" type="image/png" href="../assets/img/favicon.png">
	<title>Argon Dashboard - Staff</title>
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
				<li class="nav-item"><a class="nav-link" href="../pages/clients.html"><div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center"><i class="ni ni-circle-08 text-dark text-sm opacity-10"></i></div><span class="nav-link-text ms-1">Clients</span></a></li>
				<li class="nav-item"><a class="nav-link active" href="../pages/staff.html"><div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center"><i class="ni ni-badge text-dark text-sm opacity-10"></i></div><span class="nav-link-text ms-1">Staff</span></a></li>
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
						<li class="breadcrumb-item text-sm text-white active" aria-current="page">Staff</li>
					</ol>
					<h6 class="font-weight-bolder text-white mb-0">Staff</h6>
				</nav>
			</div>
		</nav>
		<div class="container-fluid py-4">
			<div class="row">
				<div class="col-lg-8">
					<div class="card mb-4">
						<div class="card-header pb-0 d-flex justify-content-between align-items-center">
							<h6>Team Members</h6>
							<a href="profile.html" class="btn btn-sm btn-dark">Add Staff</a>
						</div>
						<div class="card-body px-0 pt-0 pb-2">
							<div class="table-responsive p-0">
								<table class="table align-items-center mb-0">
									<thead>
										<tr>
											<th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Name</th>
											<th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Designation</th>
											<th class="text-uppercase text-secondary text-xxs font-weight-bolder text-center opacity-7">Join Date</th>
											<th></th>
										</tr>
									</thead>
									<tbody>
										<tr>
											<td>
												<div class="d-flex px-2 py-1">
													<div>
														<img src="../assets/img/team-2.jpg" class="avatar avatar-sm me-3" alt="staff">
													</div>
													<div class="d-flex flex-column justify-content-center">
														<h6 class="mb-0 text-sm">A. Smith</h6>
														<p class="text-xs text-secondary mb-0">asmith@firm.com</p>
													</div>
												</div>
											</td>
											<td><p class="text-xs font-weight-bold mb-0">Senior Lawyer</p></td>
											<td class="text-center"><span class="text-secondary text-xs font-weight-bold">01/02/22</span></td>
											<td class="text-end"><a href="profile.html" class="text-secondary text-xs font-weight-bold">Profile</a></td>
										</tr>
										<tr>
											<td>
												<div class="d-flex px-2 py-1">
													<div>
														<img src="../assets/img/team-3.jpg" class="avatar avatar-sm me-3" alt="staff">
													</div>
													<div class="d-flex flex-column justify-content-center">
														<h6 class="mb-0 text-sm">M. Levi</h6>
														<p class="text-xs text-secondary mb-0">mlevi@firm.com</p>
													</div>
												</div>
											</td>
											<td><p class="text-xs font-weight-bold mb-0">Associate</p></td>
											<td class="text-center"><span class="text-secondary text-xs font-weight-bold">15/05/24</span></td>
											<td class="text-end"><a href="profile.html" class="text-secondary text-xs font-weight-bold">Profile</a></td>
										</tr>
									</tbody>
								</table>
							</div>
						</div>
					</div>
					<div class="card">
						<div class="card-header pb-0 d-flex justify-content-between align-items-center">
							<h6>Assigned Tasks</h6>
							<a href="tables.php#tasks" class="btn btn-sm btn-outline-dark">View All</a>
						</div>
						<div class="card-body">
							<ul class="list-group">
								<li class="list-group-item d-flex justify-content-between align-items-center">
									Draft reply to notice · A. Smith
									<span class="badge bg-gradient-warning">Due Today</span>
								</li>
								<li class="list-group-item d-flex justify-content-between align-items-center">
									Client meeting · M. Levi
									<span class="badge bg-gradient-info">Tomorrow</span>
								</li>
							</ul>
						</div>
					</div>
				</div>
				<div class="col-lg-4">
					<div class="card mb-4">
						<div class="card-header pb-0">
							<h6>Clock In / Clock Out</h6>
						</div>
						<div class="card-body">
							<div class="d-grid gap-2">
								<button class="btn btn-dark">Clock In</button>
								<button class="btn btn-outline-dark">Clock Out</button>
							</div>
							<hr class="horizontal dark">
							<p class="text-sm mb-0">Today: 6h 20m</p>
							<p class="text-sm mb-0">This month: 112h</p>
						</div>
					</div>
					<div class="card">
						<div class="card-header pb-0">
							<h6>Payment Summary</h6>
						</div>
						<div class="card-body">
							<p class="text-sm mb-1">Salary: <strong>$3,200</strong></p>
							<p class="text-sm mb-1">Bonuses: <strong>$400</strong></p>
							<p class="text-sm mb-3">Deductions: <strong>$120</strong></p>
							<a href="#" class="btn btn-sm btn-outline-primary">Download Salary Slip (PDF)</a>
						</div>
					</div>
				</div>
			</div>
			<footer class="footer pt-3  ">
				<div class="container-fluid">
					<div class="row align-items-center justify-content-lg-between">
						<div class="col-lg-6 mb-lg-0 mb-4">
							<div class="copyright text-center text-sm text-muted text-lg-start">
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

$html = str_replace(
    ['$3,200', '$400', '$120'],
    [formatCurrency(3200), formatCurrency(400), formatCurrency(120)],
    $html
);

// rewrite internal links from .html to .php
$html = preg_replace('/href="([^"\']+)\.html"/i', 'href="$1.php"', $html);
// change visible 'Dashboard' text to 'Menu' in navigation
$html = preg_replace('/>\s*Dashboard\s*</i', '> Menu <', $html);
ob_start(); include __DIR__ . '/../inc/menunav.php'; $sidebar = ob_get_clean();
$html = preg_replace('/<aside[\s\S]*?<\/aside>/', $sidebar, $html, 1);
ob_start(); include __DIR__ . '/../inc/footer.php'; $footer = ob_get_clean();
$html = preg_replace('/<\/body>\s*<\/html>$/i', $footer . "\n</body>\n</html>", $html);
echo $html;
?>
