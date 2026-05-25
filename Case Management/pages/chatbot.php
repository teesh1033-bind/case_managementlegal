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
	<title>Argon Dashboard - Chatbot</title>
	<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
	<link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-icons.css" rel="stylesheet" />
	<link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-svg.css" rel="stylesheet" />
	<script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
	<link id="pagestyle" href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
<link href="../assets/css/app-font-montserrat.css?v=1" rel="stylesheet" />
	<style>
		.chat-window { height: 420px; overflow-y: auto; background: #fff; border-radius: 0.75rem; border: 1px solid #e9ecef; padding: 1rem; }
		/* Theme uses different padding/line-height on .form-control vs .btn — force one row height */
		.card-body .chat-compose {
			--chat-compose-h: 3rem;
			display: grid;
			grid-template-columns: minmax(0, 1fr) auto;
			align-items: stretch;
			width: 100%;
			column-gap: 0;
		}
		.card-body .chat-compose .chat-input {
			min-width: 0;
			width: 100%;
			height: var(--chat-compose-h) !important;
			min-height: var(--chat-compose-h) !important;
			max-height: var(--chat-compose-h) !important;
			box-sizing: border-box !important;
			padding-top: 0 !important;
			padding-bottom: 0 !important;
			padding-left: 0.75rem;
			padding-right: 0.75rem;
			line-height: normal !important;
			border-top-right-radius: 0;
			border-bottom-right-radius: 0;
			border-right: 0;
			margin: 0;
		}
		.card-body .chat-compose .chat-input:focus {
			position: relative;
			z-index: 1;
		}
		.card-body .chat-compose #sendBtn {
			height: var(--chat-compose-h) !important;
			min-height: var(--chat-compose-h) !important;
			max-height: var(--chat-compose-h) !important;
			box-sizing: border-box !important;
			display: inline-flex !important;
			align-items: center !important;
			justify-content: center !important;
			padding-top: 0 !important;
			padding-bottom: 0 !important;
			padding-left: 1.25rem !important;
			padding-right: 1.25rem !important;
			line-height: 1.2 !important;
			border-top-left-radius: 0;
			border-bottom-left-radius: 0;
			margin: 0 0 0 -1px;
			align-self: stretch;
		}
	</style>
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
				<li class="nav-item"><a class="nav-link" href="../pages/staff.html"><div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center"><i class="ni ni-badge text-dark text-sm opacity-10"></i></div><span class="nav-link-text ms-1">Staff</span></a></li>
				<li class="nav-item"><a class="nav-link" href="../pages/billing.html"><div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center"><i class="ni ni-credit-card text-dark text-sm opacity-10"></i></div><span class="nav-link-text ms-1">Finance</span></a></li>
				<li class="nav-item"><a class="nav-link" href="../pages/documents.html"><div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center"><i class="ni ni-folder-17 text-dark text-sm opacity-10"></i></div><span class="nav-link-text ms-1">Documents</span></a></li>
				<li class="nav-item"><a class="nav-link" href="../pages/appointments.html"><div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center"><i class="ni ni-time-alarm text-dark text-sm opacity-10"></i></div><span class="nav-link-text ms-1">Appointments</span></a></li>
				<li class="nav-item"><a class="nav-link" href="../pages/reports.html"><div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center"><i class="ni ni-chart-bar-32 text-dark text-sm opacity-10"></i></div><span class="nav-link-text ms-1">Reports</span></a></li>
				<li class="nav-item"><a class="nav-link active" href="../pages/chatbot.html"><div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center"><i class="ni ni-chat-round text-dark text-sm opacity-10"></i></div><span class="nav-link-text ms-1">Chatbot</span></a></li>
			</ul>
		</div>
	</aside>
	<main class="main-content position-relative border-radius-lg ">
		<nav class="navbar navbar-main navbar-expand-lg px-0 mx-4 shadow-none border-radius-xl " id="navbarBlur" data-scroll="false">
			<div class="container-fluid py-1 px-3">
				<nav aria-label="breadcrumb">
					<ol class="breadcrumb bg-transparent mb-0 pb-0 pt-1 px-0 me-sm-6 me-5">
						<li class="breadcrumb-item text-sm"><a class="opacity-5 text-white" href="javascript:;">Pages</a></li>
						<li class="breadcrumb-item text-sm text-white active" aria-current="page">Chatbot</li>
					</ol>
					<h6 class="font-weight-bolder text-white mb-0">AI Assistant</h6>
				</nav>
			</div>
		</nav>
		<div class="container-fluid py-4">
			<div class="row">
				<div class="col-lg-8">
					<div class="card">
						<div class="card-header pb-0 d-flex justify-content-between align-items-center">
							<h6>Chat</h6>
							<div class="form-check form-switch ps-0">
								<input class="form-check-input mt-1 ms-auto" type="checkbox" id="voiceToggle">
								<label class="form-check-label ms-2" for="voiceToggle">Voice</label>
							</div>
						</div>
						<div class="card-body">
							<div id="chatWindow" class="chat-window mb-3">
								<div class="d-flex mb-3">
									<div class="icon icon-shape icon-sm me-2 bg-gradient-dark shadow text-center"><i class="ni ni-chat-round text-white opacity-10"></i></div>
									<div>
										<p class="text-sm mb-0"><strong>LegalPro:</strong> How can I help? Try: “Show my active cases” or “Generate an invoice for C-1029”.</p>
									</div>
								</div>
							</div>
							<div class="chat-compose">
								<input id="chatInput" type="text" class="form-control chat-input" placeholder="Ask anything...">
								<button type="button" id="sendBtn" class="btn btn-dark">Send</button>
							</div>
						</div>
					</div>
				</div>
				<div class="col-lg-4">
					<div class="card">
						<div class="card-header pb-0">
							<h6>Shortcuts</h6>
						</div>
						<div class="card-body">
							<div class="d-grid gap-2">
								<a href="tables.php" class="btn btn-dark">Show Active Cases</a>
								<a href="reports.html" class="btn btn-dark">Revenue Summary</a>
								<a href="documents.html" class="btn btn-dark">Generate Retainer</a>
							</div>
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
	<script>
		const chatWindow = document.getElementById('chatWindow');
		const chatInput = document.getElementById('chatInput');
		const sendBtn = document.getElementById('sendBtn');
		function appendMessage(sender, text) {
			const row = document.createElement('div');
			row.className = 'd-flex mb-3';
			row.innerHTML = sender === 'You'
				? '<div><p class="text-sm mb-0"><strong>You:</strong> ' + text + '</p></div>'
				: '<div class="icon icon-shape icon-sm me-2 bg-gradient-dark shadow text-center"><i class="ni ni-chat-round text-white opacity-10"></i></div><div><p class="text-sm mb-0"><strong>LegalPro:</strong> ' + text + '</p></div>';
			chatWindow.appendChild(row);
			chatWindow.scrollTop = chatWindow.scrollHeight;
		}
		sendBtn.addEventListener('click', () => {
			const q = chatInput.value.trim();
			if (!q) return;
			appendMessage('You', q);
			// Placeholder response
			if (/active cases/i.test(q)) appendMessage('LegalPro', 'You have 64 active cases.');
			else if (/invoice/i.test(q)) appendMessage('LegalPro', 'Opening Finance → Invoices. You can generate a PDF there.');
			else appendMessage('LegalPro', 'I will help with that. This is a placeholder for AI integration.');
			chatInput.value = '';
		});
	</script>
	<script src="../assets/js/core/popper.min.js"></script>
	<script src="../assets/js/core/bootstrap.min.js"></script>
	<script src="../assets/js/plugins/perfect-scrollbar.min.js"></script>
	<script src="../assets/js/plugins/smooth-scrollbar.min.js"></script>
	<script src="../assets/js/argon-dashboard.min.js?v=2.1.0"></script>
</body>
</html>
HTML;

// rewrite internal links from .html to .php
$html = preg_replace('/href="([^"\']+)\.html"/i', 'href="$1.php"', $html);
ob_start(); include __DIR__ . '/../inc/menunav.php'; $sidebar = ob_get_clean();
$html = preg_replace('/<aside[\s\S]*?<\/aside>/', $sidebar, $html, 1);
ob_start(); include __DIR__ . '/../inc/footer.php'; $footer = ob_get_clean();
$html = preg_replace('/<\/body>\s*<\/html>$/i', $footer . "\n</body>\n</html>", $html);
echo $html;
?>
