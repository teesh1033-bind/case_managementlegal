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
{ADMIN_PORTAL_HEAD}
</head>

<body class="g-sidenav-show  bg-gray-100 virtual-reality">
	<div>
		<!-- Navbar -->
		{PAGE_NAVBAR}
	<div class="border-radius-xl mt-4 mx-4 position-relative" style="background-image: url('../assets/img/vr-bg.jpg') ; background-size: cover;">
		<aside class="sidenav bg-white navbar navbar-vertical navbar-expand-xs border-0 border-radius-xl my-3 fixed-start ms-4 " id="sidenav-main">
			<div class="sidenav-header">
				<i class="fas fa-times p-3 cursor-pointer text-secondary opacity-5 position-absolute end-0 top-0 d-none d-xl-none" aria-hidden="true" id="iconSidenav"></i>
				<a class="navbar-brand m-0" href=" https://demos.creative-tim.com/argon-dashboard/pages/dashboard.html " target="_blank">
					<img src="../assets/img/logo-ct-dark.png" width="26px" height="26px" class="navbar-brand-img h-100" alt="main_logo">
					<span class="ms-1 font-weight-bold">Creative Tim</span>
				</a>
			</div>
			<hr class="horizontal dark mt-0">
			<div class="collapse navbar-collapse  w-auto " id="sidenav-collapse-main">
				<ul class="navbar-nav">
					<li class="nav-item">
						<a class="nav-link " href="../pages/dashboard.html">
							<div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center">
								<i class="ni ni-tv-2 text-dark text-sm opacity-10"></i>
							</div>
							<span class="nav-link-text ms-1">Dashboard</span>
						</a>
					</li>
					<li class="nav-item">
						<a class="nav-link " href="../pages/tables.html">
							<div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center">
								<i class="ni ni-calendar-grid-58 text-dark text-sm opacity-10"></i>
							</div>
							<span class="nav-link-text ms-1">Tables</span>
						</a>
					</li>
					<li class="nav-item">
						<a class="nav-link " href="../pages/billing.html">
							<div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center">
								<i class="ni ni-credit-card text-dark text-sm opacity-10"></i>
							</div>
							<span class="nav-link-text ms-1">Billing</span>
						</a>
					</li>
					<li class="nav-item">
						<a class="nav-link active" href="../pages/virtual-reality.html">
							<div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center">
								<i class="ni ni-app text-dark text-sm opacity-10"></i>
							</div>
							<span class="nav-link-text ms-1">Virtual Reality</span>
						</a>
					</li>
					<li class="nav-item">
						<a class="nav-link " href="../pages/rtl.html">
							<div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center">
								<i class="ni ni-world-2 text-dark text-sm opacity-10"></i>
							</div>
							<span class="nav-link-text ms-1">RTL</span>
						</a>
					</li>
					<li class="nav-item mt-3">
						<h6 class="ps-4 ms-2 text-uppercase text-xs font-weight-bolder opacity-6">Account pages</h6>
					</li>
					<li class="nav-item">
						<a class="nav-link " href="../pages/profile.html">
							<div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center">
								<i class="ni ni-single-02 text-dark text-sm opacity-10"></i>
							</div>
							<span class="nav-link-text ms-1">Profile</span>
						</a>
					</li>
					<li class="nav-item">
						<a class="nav-link " href="../pages/sign-in.html">
							<div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center">
								<i class="ni ni-single-copy-04 text-dark text-sm opacity-10"></i>
							</div>
							<span class="nav-link-text ms-1">Sign In</span>
						</a>
					</li>
					<li class="nav-item">
						<a class="nav-link " href="../pages/sign-up.html">
							<div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center">
								<i class="ni ni-collection text-dark text-sm opacity-10"></i>
							</div>
							<span class="nav-link-text ms-1">Sign Up</span>
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
		<main class="main-content mt-1 border-radius-lg">
			<div class="section min-vh-85 position-relative transform-scale-0 transform-scale-md-7">
				<div class="container">
					<div class="row pt-10">
						<div class="col-lg-1 col-md-1 pt-5 pt-lg-0 ms-lg-5 text-center">
							<a href="javascript:;" class="avatar avatar-md border-0 d-block mb-2" data-bs-toggle="tooltip" data-bs-placement="left" title="My Profile">
								<img class="border-radius-lg" alt="Image placeholder" src="../assets/img/team-1.jpg">
							</a>
							<button class="btn btn-white border-radius-lg p-2 mt-0 mt-md-2 d-block mx-2 mx-md-0" type="button" data-bs-toggle="tooltip" data-bs-placement="left" title="Home">
								<i class="fas fa-home p-2"></i>
							</button>
							<button class="btn btn-white border-radius-lg p-2 d-block" type="button" data-bs-toggle="tooltip" data-bs-placement="left" title="Search">
								<i class="fas fa-search p-2"></i>
							</button>
							<button class="btn btn-white border-radius-lg p-2 d-block ms-2 ms-md-0" type="button" data-bs-toggle="tooltip" data-bs-placement="left" title="Minimize">
								<i class="fas fa-ellipsis-h p-2"></i>
							</button>
						</div>
						<!-- rest of virtual reality content -->
					</div>
				</div>
			</div>
		</main>
	</div>
	<footer class="footer pt-3  ">
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
	<!--   Core JS Files   -->
	<script src="../assets/js/core/popper.min.js"></script>
	<script src="../assets/js/core/bootstrap.min.js"></script>
	<script src="../assets/js/plugins/perfect-scrollbar.min.js"></script>
	<script src="../assets/js/plugins/smooth-scrollbar.min.js"></script>
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
	<script src="../assets/js/legalpro-sidenav-bootstrap.js?v=1"></script>
<script src="../assets/js/argon-dashboard.min.js?v=2.1.0"></script>
</body>

</html>
HTML;

// rewrite internal links from .html to .php
$html = preg_replace('/href="([^"\']+)\.html"/i', 'href="$1.php"', $html);
$html = legalpro_apply_admin_page_shell($html, 'Virtual Reality', '');
ob_start(); include __DIR__ . '/../inc/sidebar.php'; $sidebar = ob_get_clean();
$html = preg_replace('/<aside[\s\S]*?<\/aside>/', $sidebar, $html, 1);
ob_start(); include __DIR__ . '/../inc/footer.php'; $footer = ob_get_clean();
$html = preg_replace('/<\/body>\s*<\/html>$/i', $footer . "\n</body>\n</html>", $html);
echo legalpro_apply_copyright_line($html);
?>
