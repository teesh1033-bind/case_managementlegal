<?php
require_once __DIR__ . '/../inc/db.php';

$html = <<<'HTML'
<!--
=========================================================
* Argon Dashboard 3 - v2.1.0
=========================================================

* Product Page: https://www.creative-tim.com/product/argon-dashboard
* Copyright 2024 Creative Tim (https://www.creative-tim.com)
* Licensed under MIT (https://www.creative-tim.com/license)
* Coded by Creative Tim

=========================================================

* The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.
-->
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
	<link rel="apple-touch-icon" sizes="76x76" href="../assets/img/apple-icon.png">
	<link rel="icon" type="image/png" href="../assets/img/favicon.png">
	<title>
		Argon Dashboard 3 by Creative Tim
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
</head>

<body class="g-sidenav-show rtl  bg-gray-100">
	<div class="min-height-300 bg-dark position-absolute w-100"></div>
	<aside class="sidenav bg-white navbar navbar-vertical navbar-expand-xs border-0 border-radius-xl my-3 fixed-end me-4 rotate-caret" id="sidenav-main">
		<div class="sidenav-header">
			<i class="fas fa-times p-3 cursor-pointer text-secondary opacity-5 position-absolute start-0 top-0 d-none d-xl-none" aria-hidden="true" id="iconSidenav"></i>
			<a class="navbar-brand m-0" href=" https://demos.creative-tim.com/argon-dashboard/pages/dashboard.html " target="_blank">
				<img src="../assets/img/logo-ct-dark.png" width="26px" height="26px" class="navbar-brand-img h-100" alt="main_logo">
				<span class="me-1 font-weight-bold">Creative Tim</span>
			</a>
		</div>
		<hr class="horizontal dark mt-0">
		<div class="collapse navbar-collapse px-0 w-auto " id="sidenav-collapse-main">
			<ul class="navbar-nav">
				<li class="nav-item">
					<a class="nav-link " href="../pages/dashboard.html">
						<div class="icon icon-shape icon-sm border-radius-md text-center ms-2 d-flex align-items-center justify-content-center">
							<i class="ni ni-tv-2 text-primary text-sm opacity-10"></i>
						</div>
						<span class="nav-link-text me-1">لوحة القيادة</span>
					</a>
				</li>
				<li class="nav-item">
					<a class="nav-link " href="../pages/tables.html">
						<div class="icon icon-shape icon-sm border-radius-md text-center ms-2 d-flex align-items-center justify-content-center">
							<i class="ni ni-calendar-grid-58 text-warning text-sm opacity-10"></i>
						</div>
						<span class="nav-link-text me-1">الجداول</span>
					</a>
				</li>
				<li class="nav-item">
					<a class="nav-link " href="../pages/billing.html">
						<div class="icon icon-shape icon-sm border-radius-md text-center ms-2 d-flex align-items-center justify-content-center">
							<i class="ni ni-credit-card text-success text-sm opacity-10"></i>
						</div>
						<span class="nav-link-text me-1">الفواتير</span>
					</a>
				</li>
				<li class="nav-item">
					<a class="nav-link " href="../pages/virtual-reality.html">
						<div class="icon icon-shape icon-sm border-radius-md text-center ms-2 d-flex align-items-center justify-content-center">
							<i class="ni ni-app text-info text-sm opacity-10"></i>
						</div>
						<span class="nav-link-text me-1">الواقع الافتراضي</span>
					</a>
				</li>
				<li class="nav-item">
					<a class="nav-link active" href="../pages/rtl.html">
						<div class="icon icon-shape icon-sm border-radius-md text-center ms-2 d-flex align-items-center justify-content-center">
							<i class="ni ni-world-2 text-danger text-sm opacity-10"></i>
						</div>
						<span class="nav-link-text me-1">RTL</span>
					</a>
				</li>
				<li class="nav-item mt-3">
					<h6 class="ps-4 me-4 pe-2 text-uppercase text-xs font-weight-bolder opacity-6">صفحات المرافق</h6>
				</li>
				<li class="nav-item">
					<a class="nav-link " href="../pages/profile.html">
						<div class="icon icon-shape icon-sm border-radius-md text-center ms-2 d-flex align-items-center justify-content-center">
							<i class="ni ni-single-02 text-dark text-sm opacity-10"></i>
						</div>
						<span class="nav-link-text me-1">حساب تعريفي</span>
					</a>
				</li>
				<li class="nav-item">
					<a class="nav-link " href="../pages/sign-in.html">
						<div class="icon icon-shape icon-sm border-radius-md text-center ms-2 d-flex align-items-center justify-content-center">
							<i class="ni ni-single-copy-04 text-warning text-sm opacity-10"></i>
						</div>
						<span class="nav-link-text me-1">تسجيل الدخول</span>
					</a>
				</li>
				<li class="nav-item">
					<a class="nav-link " href="../pages/sign-up.html">
						<div class="icon icon-shape icon-sm border-radius-md text-center ms-2 d-flex align-items-center justify-content-center">
							<i class="ni ni-collection text-info text-sm opacity-10"></i>
						</div>
						<span class="nav-link-text me-1">اشتراك</span>
					</a>
				</li>
			</ul>
		</div>
		<div class="sidenav-footer mx-3 ">
			<div class="card card-plain shadow-none" id="sidenavCard">
				<img class="w-50 mx-auto" src="../assets/img/illustrations/icon-documentation.svg" alt="sidebar_illustration">
				<div class="card-body text-center p-3 w-100 pt-0">
					<div class="docs-info">
						<h6 class="mb-0 text-center">تحتاج مساعدة?</h6>
						<p class="text-xs font-weight-bold text-center mb-0">يرجى التحقق من مستنداتنا</p>
					</div>
				</div>
			</div>
			<a href="https://www.creative-tim.com/learning-lab/bootstrap/license/argon-dashboard" target="_blank" class="btn btn-dark btn-sm w-100 mb-3">توثيق</a>
			<a class="btn btn-primary btn-sm mb-0 w-100" href="https://www.creative-tim.com/product/argon-dashboard-pro?ref=sidebarfree" type="button">التطور للاحترافية</a>
		</div>
	</aside>
	<main class="main-content position-relative border-radius-lg overflow-hidden">
		<!-- Navbar -->
		<nav class="navbar navbar-main navbar-expand-lg px-0 mx-4 shadow-none border-radius-xl " id="navbarBlur" data-scroll="false">
			<div class="container-fluid py-1 px-3">
				<nav aria-label="breadcrumb">
					<ol class="breadcrumb bg-transparent mb-0 pb-0 pt-1 px-0 ">
						<li class="breadcrumb-item text-sm ps-2"><a class="opacity-5 text-white" href="javascript:;">لوحات القيادة</a></li>
						<li class="breadcrumb-item text-sm text-white active" aria-current="page">RTL</li>
					</ol>
					<h6 class="font-weight-bolder text-white mb-0">RTL</h6>
				</nav>
				<div class="collapse navbar-collapse mt-sm-0 mt-2 px-0" id="navbar">
					<form class="ms-md-auto pe-md-3 d-flex align-items-center legalpro-navbar-search" method="get" action="search.php" role="search">
						<div class="input-group">
							<span class="input-group-text text-body"><i class="fas fa-search" aria-hidden="true"></i></span>
							<input type="search" name="q" class="form-control" placeholder="Search…" value="" autocomplete="off" maxlength="200" aria-label="Search">
						</div>
					</form>
					<ul class="navbar-nav me-auto ms-0 justify-content-end">
						<li class="nav-item d-flex align-items-center">
							<a href="javascript:;" class="nav-link text-white font-weight-bold px-0">
								<i class="fa fa-user me-sm-1"></i>
								<span class="d-sm-inline d-none">يسجل دخول</span>
							</a>
						</li>
						<li class="nav-item d-xl-none pe-3 d-flex align-items-center">
							<a href="javascript:;" class="nav-link text-white p-0" id="iconNavbarSidenav">
								<div class="sidenav-toggler-inner">
									<i class="sidenav-toggler-line bg-white"></i>
									<i class="sidenav-toggler-line bg-white"></i>
									<i class="sidenav-toggler-line bg-white"></i>
								</div>
							</a>
						</li>
						<li class="nav-item px-3 d-flex align-items-center">
							<a href="javascript:;" class="nav-link text-white p-0">
								<i class="fa fa-cog fixed-plugin-button-nav cursor-pointer"></i>
							</a>
						</li>
						<li class="nav-item dropdown ps-2 d-flex align-items-center">
							<a href="javascript:;" class="nav-link text-white p-0" id="dropdownMenuButton" data-bs-toggle="dropdown" aria-expanded="false">
								<i class="fa fa-bell cursor-pointer"></i>
							</a>
							<ul class="dropdown-menu  px-2 py-3 me-sm-n4" aria-labelledby="dropdownMenuButton">
								<li class="mb-2">
									<a class="dropdown-item border-radius-md" href="javascript:;">
										<div class="d-flex py-1">
											<div class="my-auto">
												<img src="../assets/img/team-2.jpg" class="avatar avatar-sm  ms-3 ">
											</div>
											<div class="d-flex flex-column justify-content-center">
												<h6 class="text-sm font-weight-normal mb-1">
													<span class="font-weight-bold">New message</span> from Laur
												</h6>
												<p class="text-xs text-secondary mb-0">
													<i class="fa fa-clock me-1"></i>
													13 minutes ago
												</p>
											</div>
										</div>
									</a>
								</li>
								<li class="mb-2">
									<a class="dropdown-item border-radius-md" href="javascript:;">
										<div class="d-flex py-1">
											<div class="my-auto">
												<img src="../assets/img/small-logos/logo-spotify.svg" class="avatar avatar-sm bg-gradient-dark  ms-3 ">
											</div>
											<div class="d-flex flex-column justify-content-center">
												<h6 class="text-sm font-weight-normal mb-1">
													<span class="font-weight-bold">New album</span> by Travis Scott
												</h6>
												<p class="text-xs text-secondary mb-0">
													<i class="fa fa-clock me-1"></i>
													1 day
												</p>
											</div>
										</div>
									</a>
								</li>
								<li>
									<a class="dropdown-item border-radius-md" href="javascript:;">
										<div class="d-flex py-1">
											<div class="avatar avatar-sm bg-gradient-secondary  ms-3  my-auto">
												<svg width="12px" height="12px" viewBox="0 0 43 36" version="1.1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">
													<title>credit-card</title>
													<g stroke="none" stroke-width="1" fill="none" fill-rule="evenodd">
														<g transform="translate(-2169.000000, -745.000000)" fill="#FFFFFF" fill-rule="nonzero">
															<g transform="translate(1716.000000, 291.000000)">
																<g transform="translate(453.000000, 454.000000)">
																	<path class="color-background" d="M43,10.7482083 L43,3.58333333 C43,1.60354167 41.3964583,0 39.4166667,0 L3.58333333,0 C1.60354167,0 0,1.60354167 0,3.58333333 L0,10.7482083 L43,10.7482083 Z" opacity="0.593633743"></path>
																	<path class="color-background" d="M0,16.125 L0,32.25 C0,34.2297917 1.60354167,35.8333333 3.58333333,35.8333333 L39.4166667,35.8333333 C41.3964583,35.8333333 43,34.2297917 43,32.25 L43,16.125 L0,16.125 Z M19.7083333,26.875 L7.16666667,26.875 L7.16666667,23.2916667 L19.7083333,23.2916667 L19.7083333,26.875 Z M35.8333333,26.875 L28.6666667,26.875 L28.6666667,23.2916667 L35.8333333,23.2916667 L35.8333333,26.875 Z"></path>
																</g>
															</g>
														</g>
													</g>
												</svg>
											</div>
											<div class="d-flex flex-column justify-content-center">
												<h6 class="text-sm font-weight-normal mb-1">
													Payment successfully completed
												</h6>
												<p class="text-xs text-secondary mb-0">
													<i class="fa fa-clock me-1"></i>
													2 days
												</p>
											</div>
										</div>
									</a>
								</li>
							</ul>
						</li>
					</ul>
				</div>
			</div>
		</nav>
		<!-- End Navbar -->
		<div class="container-fluid py-4">
			<div class="row">
				<div class="col-lg-3 col-sm-6 mb-lg-0 mb-4">
					<div class="card">
						<div class="card-body p-3">
							<div class="row">
								<div class="col-8">
									<div class="numbers">
										<p class="text-sm mb-0 text-capitalize font-weight-bold">أموال اليوم</p>
										<h5 class="font-weight-bolder mb-0">
											$53,000
											<span class="text-success text-sm font-weight-bolder">+55%</span>
										</h5>
									</div>
								</div>
								<div class="col-4 text-start">
									<div class="icon icon-shape bg-gradient-primary shadow text-center border-radius-md">
										<i class="ni ni-money-coins text-lg opacity-10" aria-hidden="true"></i>
									</div>
								</div>
							</div>
						</div>
					</div>
				</div>
				<!-- ... rest of RTL page ... -->
			</div>
		</div>
	</main>
	<!--   Core JS Files   -->
	<script src="../assets/js/core/popper.min.js"></script>
	<script src="../assets/js/core/bootstrap.min.js"></script>
	<script src="../assets/js/plugins/perfect-scrollbar.min.js"></script>
	<script src="../assets/js/plugins/smooth-scrollbar.min.js"></script>
	<script src="../assets/js/plugins/chartjs.min.js"></script>
	<script>
		var ctx1 = document.getElementById("chart-line").getContext("2d");
		// chart initialization omitted for brevity
	</script>
	<script>
		var win = navigator.platform.indexOf('Win') > -1;
		if (win && document.querySelector('#sidenav-scrollbar')) {
			var options = {
				damping: '0.5'
			}
			Scrollbar.init(document.querySelector('#sidenav-scrollbar'), options);
		}
	</script>
	<!-- Github buttons -->
	<script async defer src="https://buttons.github.io/buttons.js"></script>
	<!-- Control Center for Soft Dashboard: parallax effects, scripts for the example pages etc -->
	<script src="../assets/js/argon-dashboard.min.js?v=2.1.0"></script>
</body>

</html>
HTML;

// rewrite internal links from .html to .php
$html = preg_replace('/href="([^"\']+)\.html"/i', 'href="$1.php"', $html);
// change visible 'Dashboard' text to 'Menu' in navigation
$html = preg_replace('/>\s*Dashboard\s*</i', '> Menu <', $html);
ob_start(); include __DIR__ . '/../inc/sidebar.php'; $sidebar = ob_get_clean();
$html = preg_replace('/<aside[\s\S]*?<\/aside>/', $sidebar, $html, 1);
ob_start(); include __DIR__ . '/../inc/footer.php'; $footer = ob_get_clean();
$html = preg_replace('/<\/body>\s*<\/html>$/i', $footer . "\n</body>\n</html>", $html);
echo $html;
?>
