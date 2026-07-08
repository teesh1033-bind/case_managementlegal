<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/admin-layout.php';

$html = <<<'HTML'
<!DOCTYPE html>
<html lang="en">

<head>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
	<link rel="apple-touch-icon" sizes="76x76" href="../assets/img/apple-icon.png">
	<link rel="icon" type="image/png" href="../assets/img/favicon.png">
	<title>
		Argon Dashboard
	</title>
	<!--     Fonts and icons     -->
	<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
	<!-- Nucleo Icons -->
	<link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-icons.css" rel="stylesheet" />
	<link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-svg.css" rel="stylesheet" />
	<!-- Font Awesome Icons -->
	<script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
	<!-- CSS Files -->
	<link id="pagestyle" href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
<link href="../assets/css/app-font-montserrat.css?v=1" rel="stylesheet" />
{ADMIN_PORTAL_HEAD}
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
				<li class="nav-item">
					<a class="nav-link" href="../pages/dashboard.html">
						<div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center">
							<i class="ni ni-tv-2 text-dark text-sm opacity-10"></i>
						</div>
						<span class="nav-link-text ms-1">Dashboard</span>
					</a>
				</li>
				<li class="nav-item">
					<a class="nav-link" href="../pages/tables.php">
						<div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center">
							<i class="ni ni-collection text-dark text-sm opacity-10"></i>
						</div>
						<span class="nav-link-text ms-1">Cases</span>
					</a>
				</li>
				<li class="nav-item">
					<a class="nav-link" href="../pages/clients.html">
						<div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center">
							<i class="ni ni-circle-08 text-dark text-sm opacity-10"></i>
						</div>
						<span class="nav-link-text ms-1">Clients</span>
					</a>
				</li>
				<li class="nav-item">
					<a class="nav-link" href="../pages/staff.html">
						<div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center">
							<i class="ni ni-badge text-dark text-sm opacity-10"></i>
						</div>
						<span class="nav-link-text ms-1">Staff</span>
					</a>
				</li>
				<li class="nav-item">
					<a class="nav-link active" href="../pages/billing.html">
						<div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center">
							<i class="ni ni-credit-card text-dark text-sm opacity-10"></i>
						</div>
						<span class="nav-link-text ms-1">Finance</span>
					</a>
				</li>
				<li class="nav-item">
					<a class="nav-link" href="../pages/documents.html">
						<div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center">
							<i class="ni ni-folder-17 text-dark text-sm opacity-10"></i>
						</div>
						<span class="nav-link-text ms-1">Documents</span>
					</a>
				</li>
				<li class="nav-item">
					<a class="nav-link" href="../pages/appointments.html">
						<div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center">
							<i class="ni ni-time-alarm text-dark text-sm opacity-10"></i>
						</div>
						<span class="nav-link-text ms-1">Appointments</span>
					</a>
				</li>
				<li class="nav-item">
					<a class="nav-link" href="../pages/reports.html">
						<div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center">
							<i class="ni ni-chart-bar-32 text-dark text-sm opacity-10"></i>
						</div>
						<span class="nav-link-text ms-1">Reports</span>
					</a>
				</li>
				<li class="nav-item">
					<a class="nav-link" href="../pages/settings.html">
						<div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center">
							<i class="ni ni-settings text-dark text-sm opacity-10"></i>
						</div>
						<span class="nav-link-text ms-1">Settings</span>
					</a>
				</li>
				<li class="nav-item">
					<a class="nav-link" href="../pages/chatbot.html">
						<div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center">
							<i class="ni ni-chat-round text-dark text-sm opacity-10"></i>
						</div>
						<span class="nav-link-text ms-1">Chatbot</span>
					</a>
				</li>
			</ul>
		</div>
		<div class="sidenav-footer mx-3 ">
			<div class="card card-plain shadow-none" id="sidenavCard">
				<img class="w-50 mx-auto" src="../assets/img/illustrations/icon-documentation.svg" alt="sidebar_illustration">
				<div class="card-body text-center p-3 w-100 pt-0">
					<div class="docs-info">
						<h6 class="mb-0">Need help?</h6>
						<p class="text-xs font-weight-bold mb-0">Please check our docs</p>
					</div>
				</div>
			</div>
			<a href="https://www.creative-tim.com/learning-lab/bootstrap/license/argon-dashboard" target="_blank" class="btn btn-dark btn-sm w-100 mb-3">Documentation</a>
			<a class="btn btn-primary btn-sm mb-0 w-100" href="https://www.creative-tim.com/product/argon-dashboard-pro?ref=sidebarfree" type="button">Upgrade to pro</a>
		</div>
	</aside>
	<main class="main-content position-relative border-radius-lg ">
		<!-- Navbar -->
		{PAGE_NAVBAR}
		<div class="container-fluid py-4">
			<div class="row">
				<div class="col-lg-8">
					<div class="row">
						<div class="col-xl-3 col-sm-6 mb-xl-0 mb-4">
							<div class="card">
								<div class="card-body p-3">
									<div class="row">
										<div class="col-8">
											<div class="numbers">
												<p class="text-sm mb-0 text-uppercase font-weight-bold">Total Income</p>
												<h5 class="font-weight-bolder">$24,300</h5>
												<p class="mb-0"><span class="text-success text-sm font-weight-bolder">+6%</span> vs last month</p>
											</div>
										</div>
										<div class="col-4 text-end">
											<div class="icon icon-shape bg-gradient-success shadow-success text-center rounded-circle">
												<i class="ni ni-money-coins text-lg opacity-10" aria-hidden="true"></i>
											</div>
										</div>
									</div>
								</div>
							</div>
						</div>
						<div class="col-xl-3 col-sm-6 mb-xl-0 mb-4">
							<div class="card">
								<div class="card-body p-3">
									<div class="row">
										<div class="col-8">
											<div class="numbers">
												<p class="text-sm mb-0 text-uppercase font-weight-bold">Expenses</p>
												<h5 class="font-weight-bolder">$9,120</h5>
												<p class="mb-0"><span class="text-danger text-sm font-weight-bolder">+3%</span> vs last month</p>
											</div>
										</div>
										<div class="col-4 text-end">
											<div class="icon icon-shape bg-gradient-danger shadow-danger text-center rounded-circle">
												<i class="ni ni-cart text-lg opacity-10" aria-hidden="true"></i>
											</div>
										</div>
									</div>
								</div>
							</div>
						</div>
						<div class="col-xl-3 col-sm-6 mb-xl-0 mb-4">
							<div class="card">
								<div class="card-body p-3">
									<div class="row">
										<div class="col-8">
											<div class="numbers">
												<p class="text-sm mb-0 text-uppercase font-weight-bold">Net Profit</p>
												<h5 class="font-weight-bolder">$15,180</h5>
												<p class="mb-0"><span class="text-success text-sm font-weight-bolder">+8%</span> margin</p>
											</div>
										</div>
										<div class="col-4 text-end">
											<div class="icon icon-shape bg-gradient-info shadow-info text-center rounded-circle">
												<i class="ni ni-chart-bar-32 text-lg opacity-10" aria-hidden="true"></i>
											</div>
										</div>
									</div>
								</div>
							</div>
						</div>
						<div class="col-xl-3 col-sm-6">
							<div class="card">
								<div class="card-body p-3">
									<div class="row">
										<div class="col-8">
											<div class="numbers">
												<p class="text-sm mb-0 text-uppercase font-weight-bold">Outstanding</p>
												<h5 class="font-weight-bolder">$3,740</h5>
												<p class="mb-0"><span class="text-warning text-sm font-weight-bolder">12</span> invoices due</p>
											</div>
										</div>
										<div class="col-4 text-end">
											<div class="icon icon-shape bg-gradient-warning shadow-warning text-center rounded-circle">
												<i class="ni ni-time-alarm text-lg opacity-10" aria-hidden="true"></i>
											</div>
										</div>
									</div>
								</div>
							</div>
						</div>
						<div class="col-md-12 mb-lg-0 mb-4">
							<div class="card mt-4">
								<div class="card-header pb-0 p-3 d-flex justify-content-between align-items-center">
									<h6 class="mb-0">Filters</h6>
									<div>
										<select class="form-select d-inline-block w-auto me-2">
											<option>This Month</option>
											<option>Last Month</option>
											<option>This Quarter</option>
										</select>
										<input type="date" class="form-control d-inline-block w-auto me-2">
										<input type="date" class="form-control d-inline-block w-auto">
									</div>
								</div>
								<div class="card-body p-3">
									<div class="row">
										<div class="col-md-6 mb-3">
											<a href="documents.html" class="btn btn-outline-dark w-100">Generate Invoice</a>
										</div>
										<div class="col-md-6">
											<a href="documents.html" class="btn btn-outline-dark w-100">Generate Receipt</a>
										</div>
									</div>
								</div>
							</div>
						</div>
					</div>
				</div>
				<div class="col-lg-4">
					<div class="card h-100">
						<div class="card-header pb-0 p-3">
							<div class="row">
								<div class="col-6 d-flex align-items-center">
									<h6 class="mb-0">Invoices</h6>
								</div>
								<div class="col-6 text-end">
									<button class="btn btn-outline-primary btn-sm mb-0">View All</button>
								</div>
							</div>
						</div>
						<div class="card-body p-3 pb-0">
							<ul class="list-group">
								<li class="list-group-item border-0 d-flex justify-content-between ps-0 mb-2 border-radius-lg">
									<div class="d-flex flex-column">
										<h6 class="mb-1 text-dark font-weight-bold text-sm">Jane Doe · C-1013</h6>
										<span class="text-xs">#INV-000234 · Due 15 Nov</span>
									</div>
									<div class="d-flex align-items-center text-sm">
										$1,200
										<button class="btn btn-link text-dark text-sm mb-0 px-0 ms-4"><i class="fas fa-file-pdf text-lg me-1"></i> PDF</button>
									</div>
								</li>
								<li class="list-group-item border-0 d-flex justify-content-between ps-0 mb-2 border-radius-lg">
									<div class="d-flex flex-column">
										<h6 class="text-dark mb-1 font-weight-bold text-sm">Acme Ltd. · C-1029</h6>
										<span class="text-xs">#INV-000235 · Due 20 Nov</span>
									</div>
									<div class="d-flex align-items-center text-sm">
										$2,540
										<button class="btn btn-link text-dark text-sm mb-0 px-0 ms-4"><i class="fas fa-file-pdf text-lg me-1"></i> PDF</button>
									</div>
								</li>
							</ul>
						</div>
					</div>
				</div>
			</div>
			<div class="row">
				<div class="col-md-7 mt-4">
					<div class="card">
						<div class="card-header pb-0 px-3 d-flex justify-content-between align-items-center">
							<h6 class="mb-0">Expenses</h6>
							<a class="btn btn-sm btn-outline-dark" href="javascript:;">Add Expense</a>
						</div>
						<div class="card-body pt-4 p-3">
							<div class="lp-admin-table-paginate" data-lp-admin-paginate data-lp-per-page="10" data-lp-row=".legalpro-admin-list-row">
							<div class="table-responsive">
								<table class="table align-items-center">
									<thead>
										<tr>
											<th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Date</th>
											<th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Category</th>
											<th class="text-uppercase text-secondary text-xxs font-weight-bolder text-center opacity-7">Amount</th>
											<th class="text-uppercase text-secondary text-xxs font-weight-bolder text-center opacity-7">Note</th>
											<th></th>
										</tr>
									</thead>
									<tbody>
										<tr class="legalpro-admin-list-row">
											<td>08/11/25</td>
											<td>Operational</td>
											<td class="text-center">$320</td>
											<td class="text-center">Court filing fees</td>
											<td class="text-end"><a href="javascript:;" class="text-secondary text-xs font-weight-bold">Edit</a></td>
										</tr>
										<tr class="legalpro-admin-list-row">
											<td>04/11/25</td>
											<td>Salary</td>
											<td class="text-center">$2,800</td>
											<td class="text-center">Monthly payroll</td>
											<td class="text-end"><a href="javascript:;" class="text-secondary text-xs font-weight-bold">Edit</a></td>
										</tr>
									</tbody>
								</table>
							</div>
							<nav class="lp-admin-pagination" data-lp-pagination-nav aria-label="Expenses pagination" hidden><p class="lp-admin-pagination__info" data-lp-range></p><div class="lp-admin-pagination__controls" data-lp-pages></div></nav>
							</div>
						</div>
					</div>
				</div>
				<div class="col-md-5 mt-4">
					<div class="card h-100 mb-4">
						<div class="card-header pb-0 px-3">
							<div class="row">
								<div class="col-md-6">
									<h6 class="mb-0">Payments & Receipts</h6>
								</div>
								<div class="col-md-6 d-flex justify-content-end align-items-center">
									<i class="far fa-calendar-alt me-2"></i>
									<small>23 - 30 March 2020</small>
								</div>
							</div>
						</div>
						<div class="card-body pt-4 p-3">
							<h6 class="text-uppercase text-body text-xs font-weight-bolder mb-3">Newest</h6>
							<ul class="list-group">
								<li class="list-group-item border-0 d-flex justify-content-between ps-0 mb-2 border-radius-lg">
									<div class="d-flex align-items-center">
										<button class="btn btn-icon-only btn-rounded btn-outline-success mb-0 me-3 btn-sm d-flex align-items-center justify-content-center"><i class="fas fa-arrow-up"></i></button>
										<div class="d-flex flex-column">
											<h6 class="mb-1 text-dark text-sm">Payment Received · Jane Doe</h6>
											<span class="text-xs">11 Nov 2025, at 12:30 PM</span>
										</div>
									</div>
									<div class="d-flex align-items-center text-success text-gradient text-sm font-weight-bold">
										+ $ 1,200
										<button class="btn btn-link text-dark text-sm mb-0 px-0 ms-3"><i class="fas fa-file-pdf text-lg me-1"></i> Receipt</button>
									</div>
								</li>
								<li class="list-group-item border-0 d-flex justify-content-between ps-0 mb-2 border-radius-lg">
									<div class="d-flex align-items-center">
										<button class="btn btn-icon-only btn-rounded btn-outline-success mb-0 me-3 btn-sm d-flex align-items-center justify-content-center"><i class="fas fa-arrow-up"></i></button>
										<div class="d-flex flex-column">
											<h6 class="mb-1 text-dark text-sm">Payment Received · Acme Ltd.</h6>
											<span class="text-xs">10 Nov 2025, at 04:30 PM</span>
										</div>
									</div>
									<div class="d-flex align-items-center text-success text-gradient text-sm font-weight-bold">
										+ $ 2,540
										<button class="btn btn-link text-dark text-sm mb-0 px-0 ms-3"><i class="fas fa-file-pdf text-lg me-1"></i> Receipt</button>
									</div>
								</li>
							</ul>
							<h6 class="text-uppercase text-body text-xs font-weight-bolder my-3">Yesterday</h6>
							<ul class="list-group">
								<li class="list-group-item border-0 d-flex justify-content-between ps-0 mb-2 border-radius-lg">
									<div class="d-flex align-items-center">
										<button class="btn btn-icon-only btn-rounded btn-outline-danger mb-0 me-3 btn-sm d-flex align-items-center justify-content-center"><i class="fas fa-arrow-down"></i></button>
										<div class="d-flex flex-column">
											<h6 class="mb-1 text-dark text-sm">Expense · Court Fees</h6>
											<span class="text-xs">09 Nov 2025, at 13:45 PM</span>
										</div>
									</div>
									<div class="d-flex align-items-center text-danger text-gradient text-sm font-weight-bold">
										- $ 320
									</div>
								</li>
								<li class="list-group-item border-0 d-flex justify-content-between ps-0 mb-2 border-radius-lg">
									<div class="d-flex align-items-center">
										<button class="btn btn-icon-only btn-rounded btn-outline-danger mb-0 me-3 btn-sm d-flex align-items-center justify-content-center"><i class="fas fa-arrow-down"></i></button>
										<div class="d-flex flex-column">
											<h6 class="mb-1 text-dark text-sm">Expense · Salary</h6>
											<span class="text-xs">09 Nov 2025, at 12:30 PM</span>
										</div>
									</div>
									<div class="d-flex align-items-center text-danger text-gradient text-sm font-weight-bold">
										- $ 2,800
									</div>
								</li>
								<li class="list-group-item border-0 d-flex justify-content-between ps-0 mb-2 border-radius-lg">
									<div class="d-flex align-items-center">
										<button class="btn btn-icon-only btn-rounded btn-outline-success mb-0 me-3 btn-sm d-flex align-items-center justify-content-center"><i class="fas fa-arrow-up"></i></button>
										<div class="d-flex flex-column">
											<h6 class="mb-1 text-dark text-sm">Payment Received · Retainer</h6>
											<span class="text-xs">09 Nov 2025, at 08:30 AM</span>
										</div>
									</div>
									<div class="d-flex align-items-center text-success text-gradient text-sm font-weight-bold">
										+ $ 750
									</div>
								</li>
								<li class="list-group-item border-0 d-flex justify-content-between ps-0 mb-2 border-radius-lg">
									<div class="d-flex align-items-center">
										<button class="btn btn-icon-only btn-rounded btn-outline-dark mb-0 me-3 btn-sm d-flex align-items-center justify-content-center"><i class="fas fa-exclamation"></i></button>
										<div class="d-flex flex-column">
											<h6 class="mb-1 text-dark text-sm">Pending · Bank Transfer</h6>
											<span class="text-xs">09 Nov 2025, at 05:00 AM</span>
										</div>
									</div>
									<div class="d-flex align-items-center text-dark text-sm font-weight-bold">
										Pending
									</div>
								</li>
							</ul>
						</div>
					</div>
				</div>
			</div>
			<footer class="footer pt-3  ">
				<div class="container-fluid">
					<div class="row align-items-center justify-content-lg-between">
						<div class="col-lg-6 mb-lg-0 mb-4">
							<div class="copyright text-center text-sm text-muted text-lg-start">
								{COPYRIGHT_LINE}
							</div>
						</div>
						<div class="col-lg-6">
							<ul class="nav nav-footer justify-content-center justify-content-lg-end">
								<li class="nav-item">
									<a href="https://www.creative-tim.com" class="nav-link text-muted" target="_blank">Creative Tim</a>
								</li>
								<li class="nav-item">
									<a href="https://www.creative-tim.com/presentation" class="nav-link text-muted" target="_blank">About Us</a>
								</li>
								<li class="nav-item">
									<a href="https://www.creative-tim.com/blog" class="nav-link text-muted" target="_blank">Blog</a>
								</li>
								<li class="nav-item">
									<a href="https://www.creative-tim.com/license" class="nav-link pe-0 text-muted" target="_blank">License</a>
								</li>
							</ul>
						</div>
					</div>
				</div>
			</footer>
		</div>
	</main>
	<div class="fixed-plugin">
		<a class="fixed-plugin-button text-dark position-fixed px-3 py-2">
			<i class="fa fa-cog py-2"> </i>
		</a>
		<div class="card shadow-lg">
			<div class="card-header pb-0 pt-3 ">
				<div class="float-start">
					<h5 class="mt-3 mb-0">Argon Configurator</h5>
					<p>See our dashboard options.</p>
				</div>
				<div class="float-end mt-4">
					<button class="btn btn-link text-dark p-0 fixed-plugin-close-button">
						<i class="fa fa-close"></i>
					</button>
				</div>
				<!-- End Toggle Button -->
			</div>
			<hr class="horizontal dark my-1">
			<div class="card-body pt-sm-3 pt-0 overflow-auto">
				<!-- Sidebar Backgrounds -->
				<div>
					<h6 class="mb-0">Sidebar Colors</h6>
				</div>
				<a href="javascript:void(0)" class="switch-trigger background-color">
					<div class="badge-colors my-2 text-start">
						<span class="badge filter bg-gradient-primary active" data-color="primary" onclick="sidebarColor(this)"></span>
						<span class="badge filter bg-gradient-dark" data-color="dark" onclick="sidebarColor(this)"></span>
						<span class="badge filter bg-gradient-info" data-color="info" onclick="sidebarColor(this)"></span>
						<span class="badge filter bg-gradient-success" data-color="success" onclick="sidebarColor(this)"></span>
						<span class="badge filter bg-gradient-warning" data-color="warning" onclick="sidebarColor(this)"></span>
						<span class="badge filter bg-gradient-danger" data-color="danger" onclick="sidebarColor(this)"></span>
					</div>
				</a>
				<!-- Sidenav Type -->
				<div class="mt-3">
					<h6 class="mb-0">Sidenav Type</h6>
					<p class="text-sm">Choose between 2 different sidenav types.</p>
				</div>
				<div class="d-flex">
					<button class="btn bg-gradient-primary w-100 px-3 mb-2 active me-2" data-class="bg-white" onclick="sidebarType(this)">White</button>
					<button class="btn bg-gradient-primary w-100 px-3 mb-2" data-class="bg-default" onclick="sidebarType(this)">Dark</button>
				</div>
				<p class="text-sm d-xl-none d-block mt-2">You can change the sidenav type just on desktop view.</p>
				<!-- Navbar Fixed -->
				<div class="d-flex my-3">
					<h6 class="mb-0">Navbar Fixed</h6>
					<div class="form-check form-switch ps-0 ms-auto my-auto">
						<input class="form-check-input mt-1 ms-auto" type="checkbox" id="navbarFixed" onclick="navbarFixed(this)">
					</div>
				</div>
				<hr class="horizontal dark my-sm-4">
				<div class="mt-2 mb-5 d-flex">
					<h6 class="mb-0">Light / Dark</h6>
					<div class="form-check form-switch ps-0 ms-auto my-auto">
						<input class="form-check-input mt-1 ms-auto" type="checkbox" id="dark-version" onclick="darkMode(this)">
					</div>
				</div>
				<a class="btn bg-gradient-dark w-100" href="https://www.creative-tim.com/product/argon-dashboard">Free Download</a>
				<a class="btn btn-outline-dark w-100" href="https://www.creative-tim.com/learning-lab/bootstrap/license/argon-dashboard">View documentation</a>
				<div class="w-100 text-center">
					<a class="github-button" href="https://github.com/creativetimofficial/argon-dashboard" data-icon="octicon-star" data-size="large" data-show-count="true" aria-label="Star creativetimofficial/argon-dashboard on GitHub">Star</a>
					<h6 class="mt-3">Thank you for sharing!</h6>
					<a href="https://twitter.com/intent/tweet?text=Check%20Argon%20Dashboard%20made%20by%20%40CreativeTim%20%23webdesign%20%23dashboard%20%23bootstrap5&amp;url=https%3A%2F%2Fwww.creative-tim.com%2Fproduct%2Fargon-dashboard" class="btn btn-dark mb-0 me-2" target="_blank">
						<i class="fab fa-twitter me-1" aria-hidden="true"></i> Tweet
					</a>
					<a href="https://www.facebook.com/sharer/sharer.php?u=https://www.creative-tim.com/product/argon-dashboard" class="btn btn-dark mb-0 me-2" target="_blank">
						<i class="fab fa-facebook-square me-1" aria-hidden="true"></i> Share
					</a>
				</div>
			</div>
		</div>
	</div>
	<!--   Core JS Files   -->
	<script src="../assets/js/core/popper.min.js"></script>
	<script src="../assets/js/core/bootstrap.min.js"></script>
	<script src="../assets/js/plugins/perfect-scrollbar.min.js"></script>
	<script src="../assets/js/plugins/smooth-scrollbar.min.js"></script>
	<script async defer src="https://buttons.github.io/buttons.js"></script>
	<script src="../assets/js/legalpro-sidenav-bootstrap.js?v=1"></script>
<script src="../assets/js/argon-dashboard.min.js?v=2.1.0"></script>
</body>

</html>
HTML;

$html = str_replace(
    [
        '$24,300', '$9,120', '$15,180', '$3,740',
        '$1,200', '$2,540', '$320', '$2,800',
    ],
    [
        formatCurrency(24300), formatCurrency(9120), formatCurrency(15180), formatCurrency(3740),
        formatCurrency(1200), formatCurrency(2540), formatCurrency(320), formatCurrency(2800),
    ],
    $html
);

// rewrite internal links from .html to .php
$html = preg_replace('/href="([^"\']+)\.html"/i', 'href="$1.php"', $html);
$html = legalpro_apply_admin_page_shell($html, 'Finance', 'Finance and billing');
ob_start(); include __DIR__ . '/../inc/menunav.php'; $sidebar = ob_get_clean();
$html = preg_replace('/<aside[\s\S]*?<\/aside>/', $sidebar, $html, 1);
ob_start(); include __DIR__ . '/../inc/footer.php'; $footer = ob_get_clean();
$html = preg_replace('/<\/body>\s*<\/html>$/i', $footer . "\n</body>\n</html>", $html);
echo legalpro_apply_copyright_line($html);
?>
