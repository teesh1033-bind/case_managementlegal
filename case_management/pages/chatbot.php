<?php
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../lib/chatbot_assistant.php';

$context = ChatbotAssistant::resolveContextFromSession();
if ($context['role'] === 'guest') {
    header('Location: login.php');
    exit;
}

$companyBranding = getCompanyBranding();
$assistantName = $companyBranding['name'];
$role = $context['role'];
$displayName = htmlspecialchars($context['display_name'], ENT_QUOTES, 'UTF-8');

$welcomeExamples = [
    'admin' => 'Try: "How many active cases?" · "Upcoming appointments" · "Case C-0001" · "Pending invoices"',
    'lawyer' => 'Try: "Show my active cases" · "My appointments" · "My tasks" · "Case C-0001"',
    'client' => 'Try: "Show my cases" · "My appointments" · "Payment balance" · "Court dates"',
];
$welcomeText = $welcomeExamples[$role] ?? $welcomeExamples['admin'];

$shortcutsHtml = '';
if ($role === 'admin') {
    $shortcutsHtml = '
        <button type="button" class="btn btn-dark btn-sm chat-shortcut" data-prompt="How many active cases do we have?">Active cases</button>
        <button type="button" class="btn btn-dark btn-sm chat-shortcut" data-prompt="Show upcoming appointments">Appointments</button>
        <button type="button" class="btn btn-dark btn-sm chat-shortcut" data-prompt="List recent clients">Clients</button>
        <button type="button" class="btn btn-dark btn-sm chat-shortcut" data-prompt="Any pending invoices?">Invoices</button>
        <a href="tables.php" class="btn btn-outline-dark btn-sm">Open cases</a>';
} elseif ($role === 'lawyer') {
    $shortcutsHtml = '
        <button type="button" class="btn btn-dark btn-sm chat-shortcut" data-prompt="Show my active cases">My cases</button>
        <button type="button" class="btn btn-dark btn-sm chat-shortcut" data-prompt="My upcoming appointments">Appointments</button>
        <button type="button" class="btn btn-dark btn-sm chat-shortcut" data-prompt="My pending tasks">Tasks</button>
        <button type="button" class="btn btn-dark btn-sm chat-shortcut" data-prompt="Upcoming court dates">Court dates</button>
        <a href="lawyer-cases.php" class="btn btn-outline-dark btn-sm">Open cases</a>';
} else {
    $shortcutsHtml = '
        <button type="button" class="btn btn-dark btn-sm chat-shortcut" data-prompt="Show my cases">My cases</button>
        <button type="button" class="btn btn-dark btn-sm chat-shortcut" data-prompt="My upcoming appointments">Appointments</button>
        <button type="button" class="btn btn-dark btn-sm chat-shortcut" data-prompt="What is my payment balance?">Payments</button>
        <button type="button" class="btn btn-dark btn-sm chat-shortcut" data-prompt="Documents on my cases">Documents</button>
        <a href="client-cases.php" class="btn btn-outline-dark btn-sm">Open cases</a>';
}

$portalBodyClass = 'g-sidenav-show bg-gray-100';
if ($role === 'admin') {
    $portalBodyClass .= ' legalpro-admin-portal';
} elseif ($role === 'lawyer') {
    $portalBodyClass .= ' legalpro-lawyer-portal lawyer-dashboard-page';
} else {
    $portalBodyClass .= ' legalpro-client-portal client-portal-page client-dashboard-page';
}

$headerBgClass = ($role === 'client') ? 'bg-primary' : 'bg-legalpro-admin';

$topNavbarHtml = '
		<nav class="navbar navbar-main navbar-expand-lg px-0 mx-4 shadow-none border-radius-xl " id="navbarBlur" data-scroll="false">
			<div class="container-fluid py-1 px-3">
				<nav aria-label="breadcrumb">
					<ol class="breadcrumb bg-transparent mb-0 pb-0 pt-1 px-0 me-sm-6 me-5">
						<li class="breadcrumb-item text-sm"><a class="opacity-5 text-white" href="javascript:;">Assistant</a></li>
						<li class="breadcrumb-item text-sm text-white active" aria-current="page">Chatbot</li>
					</ol>
					<h6 class="font-weight-bolder text-white mb-0">AI Assistant</h6>
				</nav>
			</div>
		</nav>';
if ($role === 'client') {
    require_once __DIR__ . '/../inc/client-portal-navbar.php';
    $topNavbarHtml = legalpro_render_client_page_navbar('AI Assistant', 'AI Assistant', 'Search cases…', [
        'client_name' => $context['display_name'],
    ]);
}

$html = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
	<link rel="apple-touch-icon" sizes="76x76" href="../assets/img/apple-icon.png">
	<link rel="icon" type="image/png" href="../assets/img/favicon.png">
	<title>{ASSISTANT_NAME} · AI Assistant</title>
	<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
	<link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-icons.css" rel="stylesheet" />
	<link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-svg.css" rel="stylesheet" />
	<script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
	<link id="pagestyle" href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
	<link href="../assets/css/app-font-montserrat.css?v=1" rel="stylesheet" />
	{PORTAL_HEAD_CSS}
	<style>
		.chat-window { height: 460px; overflow-y: auto; background: transparent; border-radius: 0.75rem; border: 1px solid #e9ecef; padding: 1rem; }
		.chat-message-user { justify-content: flex-end; }
		.chat-message-user .chat-bubble { background: #5e72e4; color: #fff; border-radius: 1rem 1rem 0.25rem 1rem; }
		.chat-message-bot .chat-bubble { background: #f8f9fe; color: #344767; border-radius: 1rem 1rem 1rem 0.25rem; border: 1px solid #e9ecef; }
		.chat-bubble { max-width: 85%; padding: 0.75rem 1rem; font-size: 0.875rem; line-height: 1.5; white-space: normal; }
		.chat-bubble strong { font-weight: 700; }
		.chat-bubble-label { display: block; font-weight: 700; margin-bottom: 0.1rem; line-height: 1.25; }
		.chat-bubble-body { text-align: left; white-space: pre-wrap; margin: 0; }
		.chat-links { margin-top: 0.5rem; display: flex; flex-wrap: wrap; gap: 0.35rem; }
		.chat-links a { font-size: 0.75rem; }
		.card-body .chat-compose {
			--chat-compose-h: 3rem;
			display: grid;
			grid-template-columns: minmax(0, 1fr) auto;
			align-items: stretch;
			width: 100%;
			column-gap: 0;
		}
		.card-body .chat-compose .chat-input {
			min-width: 0; width: 100%; height: var(--chat-compose-h) !important;
			min-height: var(--chat-compose-h) !important; max-height: var(--chat-compose-h) !important;
			box-sizing: border-box !important; padding: 0 0.75rem !important; line-height: normal !important;
			border-top-right-radius: 0; border-bottom-right-radius: 0; border-right: 0; margin: 0;
		}
		.card-body .chat-compose #sendBtn {
			height: var(--chat-compose-h) !important; min-height: var(--chat-compose-h) !important;
			max-height: var(--chat-compose-h) !important; box-sizing: border-box !important;
			display: inline-flex !important; align-items: center !important; justify-content: center !important;
			padding: 0 1.25rem !important; line-height: 1.2 !important;
			border-top-left-radius: 0; border-bottom-left-radius: 0; margin: 0 0 0 -1px; align-self: stretch;
		}
		.chat-shortcut-grid { display: grid; gap: 0.5rem; }
	</style>
</head>
<body class="{PORTAL_BODY_CLASS}">
	<div class="min-height-300 {HEADER_BG_CLASS} position-absolute w-100"></div>
	<aside class="sidenav bg-white navbar navbar-vertical navbar-expand-xs border-0 border-radius-xl my-3 fixed-start ms-4 " id="sidenav-main"></aside>
	<main class="main-content position-relative border-radius-lg ">
		{TOP_NAVBAR}
		<div class="container-fluid py-4">
			<div class="row">
				<div class="col-lg-8">
					<div class="card">
						<div class="card-header pb-0 d-flex justify-content-between align-items-center flex-wrap gap-2">
							<div>
								<h6 class="mb-0">Chat with {ASSISTANT_NAME}</h6>
								<p class="text-sm text-muted mb-0">Logged in as {DISPLAY_NAME} ({ROLE_LABEL})</p>
							</div>
						</div>
						<div class="card-body">
							<div id="chatWindow" class="chat-window mb-3">
								<div class="d-flex mb-3 chat-message-bot">
									<div class="icon icon-shape icon-sm me-2 bg-gradient-dark shadow text-center"><i class="ni ni-chat-round text-white opacity-10"></i></div>
									<div class="chat-bubble">
										<span class="chat-bubble-label">{ASSISTANT_NAME}:</span>
										<div class="chat-bubble-body">Hello {DISPLAY_NAME}! I can answer questions using live data from your account — cases, appointments, documents, payments, and court dates.{WELCOME_TEXT}</div>
									</div>
								</div>
							</div>
							<div class="chat-compose">
								<input id="chatInput" type="text" class="form-control chat-input" placeholder="Ask about cases, appointments, payments..." autocomplete="off">
								<button type="button" id="sendBtn" class="btn btn-dark">Send</button>
							</div>
						</div>
					</div>
				</div>
				<div class="col-lg-4">
					<div class="card mb-4">
						<div class="card-header pb-0"><h6>Quick prompts</h6></div>
						<div class="card-body chat-shortcut-grid">{SHORTCUTS_HTML}</div>
					</div>
					<div class="card">
						<div class="card-header pb-0"><h6>Tips</h6></div>
						<div class="card-body">
							<p class="text-sm mb-2">• Mention a case number like <strong>C-0007</strong> for details.</p>
							<p class="text-sm mb-2">• Say <strong>help</strong> for more examples.</p>
							<p class="text-sm mb-0">• Answers respect your role — you only see data you are allowed to access.</p>
						</div>
					</div>
				</div>
			</div>
		</div>
	</main>
	<script>
		const chatWindow = document.getElementById('chatWindow');
		const chatInput = document.getElementById('chatInput');
		const sendBtn = document.getElementById('sendBtn');
		const assistantName = {ASSISTANT_NAME_JSON};

		function escapeHtml(text) {
			const div = document.createElement('div');
			div.textContent = text;
			return div.innerHTML;
		}

		function formatReply(text) {
			return escapeHtml(text).replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
		}

		function appendMessage(sender, html, links) {
			const row = document.createElement('div');
			row.className = 'd-flex mb-3 ' + (sender === 'You' ? 'chat-message-user' : 'chat-message-bot');
			let linksHtml = '';
			if (links && links.length) {
				linksHtml = '<div class="chat-links">' + links.map(function(link) {
					return '<a class="btn btn-xs btn-outline-primary btn-sm" href="' + escapeHtml(link.url) + '">' + escapeHtml(link.label) + '</a>';
				}).join('') + '</div>';
			}
			if (sender === 'You') {
				row.innerHTML = '<div class="chat-bubble ms-auto"><span class="chat-bubble-label">You:</span><div class="chat-bubble-body">' + escapeHtml(html) + '</div></div>';
			} else {
				row.innerHTML = '<div class="icon icon-shape icon-sm me-2 bg-gradient-dark shadow text-center"><i class="ni ni-chat-round text-white opacity-10"></i></div><div class="chat-bubble"><span class="chat-bubble-label">' + escapeHtml(assistantName) + ':</span><div class="chat-bubble-body">' + formatReply(html) + linksHtml + '</div></div>';
			}
			chatWindow.appendChild(row);
			chatWindow.scrollTop = chatWindow.scrollHeight;
		}

		function appendTyping() {
			const row = document.createElement('div');
			row.className = 'd-flex mb-3 chat-message-bot';
			row.id = 'chatTyping';
			row.innerHTML = '<div class="icon icon-shape icon-sm me-2 bg-gradient-dark shadow text-center"><i class="ni ni-chat-round text-white opacity-10"></i></div><div class="chat-bubble text-muted">Thinking...</div>';
			chatWindow.appendChild(row);
			chatWindow.scrollTop = chatWindow.scrollHeight;
		}

		function removeTyping() {
			const el = document.getElementById('chatTyping');
			if (el) el.remove();
		}

		async function sendMessage(text) {
			const q = (text || chatInput.value).trim();
			if (!q) return;
			appendMessage('You', q);
			chatInput.value = '';
			sendBtn.disabled = true;
			appendTyping();
			try {
				const response = await fetch('chatbot-api.php', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify({ message: q })
				});
				const data = await response.json();
				removeTyping();
				if (!data.ok) {
					appendMessage(assistantName, data.error || 'Sorry, I could not process that request.');
					return;
				}
				appendMessage(assistantName, data.reply, data.links || []);
			} catch (err) {
				removeTyping();
				appendMessage(assistantName, 'Network error. Please try again.');
			} finally {
				sendBtn.disabled = false;
				chatInput.focus();
			}
		}

		sendBtn.addEventListener('click', function() { sendMessage(); });
		chatInput.addEventListener('keydown', function(e) {
			if (e.key === 'Enter') {
				e.preventDefault();
				sendMessage();
			}
		});
		document.querySelectorAll('.chat-shortcut').forEach(function(btn) {
			btn.addEventListener('click', function() {
				sendMessage(btn.getAttribute('data-prompt') || '');
			});
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

$html = str_replace('{ASSISTANT_NAME}', htmlspecialchars($assistantName), $html);
$html = str_replace('{ASSISTANT_NAME_JSON}', json_encode($assistantName, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT), $html);
$html = str_replace('{DISPLAY_NAME}', $displayName, $html);
$html = str_replace('{ROLE_LABEL}', ucfirst($role), $html);
$html = str_replace('{WELCOME_TEXT}', '<br><span class="text-muted">' . htmlspecialchars($welcomeText) . '</span>', $html);
$html = str_replace('{SHORTCUTS_HTML}', $shortcutsHtml, $html);
$html = str_replace('{PORTAL_BODY_CLASS}', $portalBodyClass, $html);
$html = str_replace('{TOP_NAVBAR}', $topNavbarHtml, $html);
$html = str_replace('{HEADER_BG_CLASS}', $headerBgClass, $html);

$portalHeadCss = '';
if ($role === 'client') {
    ob_start();
    include __DIR__ . '/../inc/client-portal-head.php';
    $portalHeadCss = ob_get_clean();
} elseif ($role === 'lawyer') {
    ob_start();
    include __DIR__ . '/../inc/lawyer-portal-head.php';
    $portalHeadCss = ob_get_clean();
}
$html = str_replace('{PORTAL_HEAD_CSS}', $portalHeadCss, $html);

if ($role === 'lawyer') {
    ob_start();
    include __DIR__ . '/../inc/lawyer-menunav.php';
    $sidebar = ob_get_clean();
} elseif ($role === 'client') {
    ob_start();
    include __DIR__ . '/../inc/client-menunav.php';
    $sidebar = ob_get_clean();
} else {
    ob_start();
    include __DIR__ . '/../inc/menunav.php';
    $sidebar = ob_get_clean();
}

$html = preg_replace('/<aside[\s\S]*?<\/aside>/', $sidebar, $html, 1);

if ($role === 'admin') {
    ob_start();
    include __DIR__ . '/../inc/footer.php';
    $footer = ob_get_clean();
    $html = preg_replace('/<\/body>\s*<\/html>$/i', $footer . "\n</body>\n</html>", $html);
}

echo $html;
