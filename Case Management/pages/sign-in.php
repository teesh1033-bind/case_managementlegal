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
</head>

<body class="bg-gray-100">
	<main class="main-content  mt-0">
		<section>
			<div class="page-header min-vh-100">
				<div class="container">
					<div class="row">
						<div class="col-xl-4 col-lg-5 col-md-7 d-flex flex-column mx-lg-0 mx-auto">
							<div class="card card-plain">
								<div class="card-header pb-0 text-start">
									<h4 class="font-weight-bolder">Sign In</h4>
									<p class="mb-0">Enter your email and password to sign in</p>
								</div>
								<div class="card-body">
									<form role="form" onsubmit="return false;">
										<div class="mb-3">
											<input type="email" class="form-control form-control-lg" placeholder="Email" aria-label="Email">
										</div>
										<div class="mb-3">
											<input type="password" class="form-control form-control-lg" placeholder="Password" aria-label="Password">
										</div>
										<div class="mb-3">
											<label class="form-label text-sm mb-2">Sign in as</label>
											<div class="d-flex gap-3">
												<div class="form-check">
													<input class="form-check-input" type="radio" name="role" id="roleAdmin" value="admin" checked>
													<label class="form-check-label" for="roleAdmin">Admin</label>
												</div>
												<div class="form-check">
													<input class="form-check-input" type="radio" name="role" id="roleLawyer" value="lawyer">
													<label class="form-check-label" for="roleLawyer">Lawyer/Staff</label>
												</div>
												<div class="form-check">
													<input class="form-check-input" type="radio" name="role" id="roleClient" value="client">
													<label class="form-check-label" for="roleClient">Client</label>
												</div>
											</div>
										</div>
										<div class="form-check form-switch">
											<input class="form-check-input" type="checkbox" id="rememberMe">
											<label class="form-check-label" for="rememberMe">Remember me</label>
										</div>
										<div class="text-center">
											<button id="signinBtn" type="button" class="btn btn-lg btn-primary btn-lg w-100 mt-4 mb-0">Sign in</button>
										</div>
									</form>
								</div>
								<div class="card-footer text-center pt-0 px-lg-2 px-1">
									<p class="mb-4 text-sm mx-auto">
										Don't have an account?
										<a href="sign-up.php" class="text-primary text-gradient font-weight-bold">Sign up</a>
									</p>
									<p class="mb-0 text-xs"><a href="javascript:;" class="text-secondary">Forgot password?</a></p>
								</div>
							</div>
						</div>
						<div class="col-6 d-lg-flex d-none h-100 my-auto pe-0 position-absolute top-0 end-0 text-center justify-content-center flex-column">
							<div class="position-relative bg-gradient-primary h-100 m-3 px-7 border-radius-lg d-flex flex-column justify-content-center overflow-hidden" style="background-image: url('https://images.unsplash.com/photo-1589829545856-d10d557cf95f?auto=format&amp;fit=crop&amp;w=1800&amp;q=80');
					background-size: cover; background-position: center;">
								<span class="mask bg-gradient-primary opacity-6"></span>
								<h4 class="mt-5 text-white font-weight-bolder position-relative">"Justice, diligence, and order"</h4>
								<p class="text-white position-relative">Sign in to your legal workspace—matters, clients, and court dates aligned with how you practice the law.</p>
							</div>
						</div>
					</div>
				</div>
			</div>
		</section>
	</main>
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
	<script src="../assets/js/argon-dashboard.min.js?v=2.1.0"></script>
	<script>
		document.getElementById('signinBtn').addEventListener('click', function () {
			var role = (document.querySelector('input[name="role"]:checked') || {}).value;
			if (role === 'admin') window.location.href = '../pages/dashboard.html';
			else if (role === 'lawyer') window.location.href = '../pages/tables.php';
			else if (role === 'client') window.location.href = '../pages/appointments.html';
			else window.location.href = '../pages/dashboard.html';
		});
	</script>
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
