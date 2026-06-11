<?php
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../lib/chatbot_assistant.php';
require_once __DIR__ . '/../lib/chatbot_ai.php';

$aiModeLabel = ChatbotAI::openAiConfigured() ? 'GPT-powered' : 'Smart mode';

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
    'client' => 'Ask anything — cases, billing, court prep. I can update your phone or request a callback.',
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
        <button type="button" class="cb-shortcut chat-shortcut" data-prompt="Give me a full summary of my cases and what I should focus on this week">Weekly summary</button>
        <button type="button" class="cb-shortcut chat-shortcut" data-prompt="What invoices do I owe and how can I pay?">Pay invoices</button>
        <button type="button" class="cb-shortcut chat-shortcut" data-prompt="How should I prepare for my next court date?">Court prep</button>
        <button type="button" class="cb-shortcut chat-shortcut" data-prompt="Please request a callback from my lawyer">Request callback</button>
        <button type="button" class="cb-shortcut chat-shortcut" data-prompt="How does billing work?">Billing help</button>
        <a href="client-help.php" class="cb-shortcut cb-shortcut--outline">Help center</a>';
}

$portalBodyClass = 'g-sidenav-show bg-gray-100';
if ($role === 'admin') {
    $portalBodyClass .= ' legalpro-admin-portal';
} elseif ($role === 'lawyer') {
    $portalBodyClass .= ' legalpro-lawyer-portal lawyer-dashboard-page';
} else {
    $portalBodyClass .= ' legalpro-client-portal client-portal-page client-chatbot-page';
}
$portalBodyClass .= legalpro_portal_theme_body_class();

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
    $topNavbarHtml = legalpro_render_client_page_navbar(
        'AI Assistant',
        'AI Assistant',
        'Search cases…',
        legalpro_client_page_search_options('client-cases.php')
    );
}

$welcomeHint = '<br><span class="cb-hint">' . htmlspecialchars($welcomeText) . '</span>';

$mainContentHtml = '';
if ($role === 'client') {
    $mainContentHtml = '
			<div class="cb-hero-card">
				<p class="cb-hero-kicker">AI Assistant</p>
				<h4 class="cb-hero-title">Chat with ' . htmlspecialchars($assistantName) . '</h4>
				<p class="cb-hero-sub">ChatGPT-style assistant with live access to your cases, invoices, appointments, and documents. I can advise you and update your profile or submit requests.</p>
				<p class="cb-hero-meta">Logged in as ' . $displayName . ' · <span class="cb-mode-badge">' . htmlspecialchars($aiModeLabel) . '</span></p>
			</div>
			<div class="cb-layout">
				<div class="cb-panel">
					<div class="cb-panel-hdr d-flex justify-content-between align-items-start flex-wrap gap-2">
						<div>
							<h5>Conversation</h5>
							<p>Long messages supported · Enter to send · Shift+Enter for new line</p>
						</div>
						<button type="button" id="clearChatBtn" class="btn btn-sm btn-outline-secondary">Clear chat</button>
					</div>
					<div class="cb-panel-body">
						<div id="chatWindow" class="chat-window mb-3">
							<div class="d-flex mb-3 chat-message-bot">
								<div class="cb-bot-avatar"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></div>
								<div class="chat-bubble">
									<span class="chat-bubble-label">' . htmlspecialchars($assistantName) . ':</span>
									<div class="chat-bubble-body">Hello ' . $displayName . '! I\'m your AI legal assistant. I analyze **live data** from your account and can help with cases, billing, court dates, and documents. I can also **update your phone**, **request a callback**, or **submit billing questions** when you ask.' . $welcomeHint . '</div>
								</div>
							</div>
						</div>
						<div class="chat-compose chat-compose--textarea">
							<textarea id="chatInput" class="form-control chat-input" rows="2" placeholder="Ask me anything… paste long notes, describe your situation, or request an update." autocomplete="off"></textarea>
							<button type="button" id="sendBtn" class="cb-send-btn">Send</button>
						</div>
						<div class="chat-compose-meta">
							<span id="tokenEstimate" class="text-xs text-muted">~0 tokens</span>
							<span class="text-xs text-muted">Max ~6,000 tokens per message</span>
						</div>
					</div>
				</div>
				<div class="cb-side">
					<div class="cb-panel mb-4">
						<div class="cb-panel-hdr"><h5>Quick prompts</h5></div>
						<div class="cb-panel-body chat-shortcut-grid">{SHORTCUTS_HTML}</div>
					</div>
					<div class="cb-panel">
						<div class="cb-panel-hdr"><h5>Tips</h5></div>
						<div class="cb-panel-body cb-tips chat-tips">
							<p>• Try: &ldquo;What should I do before my hearing?&rdquo;</p>
							<p>• Try: &ldquo;Update my phone to +230 5xxx xxxx&rdquo;</p>
							<p>• Try: &ldquo;Summarize my open invoices&rdquo;</p>
							<p class="mb-0">• I remember this conversation until you clear it.</p>
						</div>
					</div>
				</div>
			</div>';
} else {
    $mainContentHtml = '
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
						<div class="card-body chat-tips">
							<p class="mb-2">• Mention a case number like <strong>C-0007</strong> for details.</p>
							<p class="mb-2">• Say <strong>help</strong> for more examples.</p>
							<p class="mb-0">• Answers respect your role — you only see data you are allowed to access.</p>
						</div>
					</div>
				</div>
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
		.chat-message-user .chat-bubble { background: var(--legalpro-theme-primary, #5e72e4); color: #fff; border-radius: 1rem 1rem 0.25rem 1rem; }
		.chat-message-bot .chat-bubble { background: #f8f9fe; color: #344767; border-radius: 1rem 1rem 1rem 0.25rem; border: 1px solid #e9ecef; }
		.chat-bubble { max-width: 85%; padding: 0.75rem 1rem; font-size: 0.875rem; line-height: 1.5; white-space: normal; }
		.chat-bubble strong { font-weight: 700; }
		.chat-bubble-label { display: block; font-weight: 700; margin-bottom: 0.1rem; line-height: 1.25; }
		.chat-bubble-body { text-align: left; white-space: pre-wrap; margin: 0; }
		.chat-links { margin-top: 0.5rem; display: flex; flex-wrap: wrap; gap: 0.35rem; }
		.chat-links a { font-size: 0.75rem; }
		.chat-compose {
			--chat-compose-h: 3rem;
			display: grid;
			grid-template-columns: minmax(0, 1fr) auto;
			align-items: stretch;
			width: 100%;
			column-gap: 0;
		}
		.chat-compose .chat-input {
			min-width: 0; width: 100%; height: var(--chat-compose-h) !important;
			min-height: var(--chat-compose-h) !important; max-height: var(--chat-compose-h) !important;
			box-sizing: border-box !important; padding: 0 0.75rem !important; line-height: normal !important;
			border-top-right-radius: 0; border-bottom-right-radius: 0; border-right: 0; margin: 0;
		}
		.chat-compose #sendBtn,
		.chat-compose .cb-send-btn {
			height: var(--chat-compose-h) !important; min-height: var(--chat-compose-h) !important;
			max-height: var(--chat-compose-h) !important; box-sizing: border-box !important;
			display: inline-flex !important; align-items: center !important; justify-content: center !important;
			padding: 0 1.25rem !important; line-height: 1.2 !important;
			border-top-left-radius: 0; border-bottom-left-radius: 0; margin: 0 0 0 -1px; align-self: stretch;
		}
		.chat-shortcut-grid { display: grid; gap: 0.5rem; }

		/* Client portal chatbot */
		body.client-chatbot-page {
			background: #f0f2f8;
			--cb-primary: var(--legalpro-theme-primary, #5e72e4);
			--cb-primary-dark: var(--legalpro-theme-primary-dark, #825ee4);
			--cb-primary-soft: var(--lp-cases-accent-soft, rgba(94, 114, 228, 0.12));
			--cb-gradient: var(--legalpro-theme-gradient, linear-gradient(135deg, #5e72e4, #825ee4));
			--cb-shadow: 0 2px 12px rgba(0,0,0,0.07);
		}
		.client-chatbot-page .chat-message-user .chat-bubble { background: var(--cb-primary); }
		.client-chatbot-page .chat-message-bot .chat-bubble {
			background: var(--cb-primary-soft);
			border-color: rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.15);
		}
		.client-chatbot-page .chat-compose .chat-input:focus {
			border-color: var(--cb-primary);
			box-shadow: 0 0 0 3px rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.12);
		}
		.cb-hero-card {
			background: var(--cb-gradient);
			border-radius: 20px;
			padding: 2rem 2.5rem;
			color: #fff;
			margin-bottom: 1.5rem;
			position: relative;
			overflow: hidden;
		}
		.cb-hero-card::before {
			content: '';
			position: absolute;
			top: -50px; right: -50px;
			width: 180px; height: 180px;
			border-radius: 50%;
			background: rgba(255,255,255,.08);
		}
		.cb-hero-kicker {
			font-size: 11px; font-weight: 600;
			letter-spacing: .12em; text-transform: uppercase;
			opacity: .75; margin-bottom: .35rem;
		}
		.cb-hero-title { font-size: 22px; font-weight: 800; margin-bottom: .3rem; }
		.cb-hero-sub { font-size: 13px; opacity: .85; margin-bottom: .5rem; max-width: 36rem; }
		.cb-hero-meta { font-size: 12px; opacity: .7; margin: 0; }
		.cb-layout {
			display: grid;
			grid-template-columns: 1fr 320px;
			gap: 1.25rem;
			align-items: start;
		}
		@media (max-width: 900px) { .cb-layout { grid-template-columns: 1fr; } }
		.cb-panel {
			background: #fff;
			border-radius: 16px;
			border: 1px solid #e9ecf3;
			box-shadow: var(--cb-shadow);
			overflow: hidden;
		}
		.cb-panel-hdr {
			padding: 1.1rem 1.5rem;
			border-bottom: 1px solid #f1f5f9;
		}
		.cb-panel-hdr h5 { font-size: 15px; font-weight: 700; color: #1e293b; margin: 0; }
		.cb-panel-hdr p { font-size: 12px; color: #94a3b8; margin: 2px 0 0; }
		.cb-panel-body { padding: 1.25rem 1.5rem; }
		.cb-bot-avatar {
			width: 32px; height: 32px; border-radius: 10px;
			background: var(--cb-primary-soft); color: var(--cb-primary);
			display: flex; align-items: center; justify-content: center;
			flex-shrink: 0; margin-right: .5rem;
		}
		.cb-send-btn {
			background: var(--cb-gradient);
			color: #fff; border: none; border-radius: 0 .5rem .5rem 0;
			font-size: 13px; font-weight: 700; cursor: pointer;
		}
		.cb-send-btn:hover { opacity: .9; }
		.cb-send-btn:disabled { opacity: .5; cursor: not-allowed; }
		.cb-shortcut {
			display: block; width: 100%; text-align: left;
			padding: .55rem .85rem; border-radius: 10px;
			border: 1.5px solid var(--cb-primary); color: var(--cb-primary);
			background: #fff; font-size: 13px; font-weight: 600;
			cursor: pointer; text-decoration: none;
			transition: background .15s, color .15s;
		}
		.cb-shortcut:hover { background: var(--cb-primary); color: #fff; }
		.cb-shortcut--outline { text-align: center; }
		.chat-tips p,
		.cb-tips p {
			font-size: 13px;
			line-height: 1.55;
			color: #334155;
			font-weight: 500;
			margin-bottom: .55rem;
		}
		.chat-tips p strong,
		.cb-tips p strong {
			color: var(--legalpro-theme-primary, #5e72e4);
			font-weight: 700;
		}
		.client-chatbot-page .cb-tips {
			background: #f8fafc;
			border-radius: 12px;
			border: 1px solid #e9ecf3;
		}
		.cb-hint {
			display: block;
			margin-top: .35rem;
			font-size: 12px;
			line-height: 1.5;
			color: #475569;
			opacity: 1;
		}
		.client-chatbot-page .chat-message-bot .cb-hint {
			color: #334155;
		}
		.client-chatbot-page .chat-window { border-color: #e9ecf3; background: #fafbfc; height: min(52vh, 520px); }
		.cb-mode-badge {
			display: inline-block; padding: 2px 10px; border-radius: 99px;
			background: rgba(255,255,255,.2); font-size: 11px; font-weight: 700;
		}
		.chat-compose--textarea {
			grid-template-columns: minmax(0, 1fr) auto;
			align-items: end;
			--chat-compose-h: auto;
		}
		.chat-compose--textarea .chat-input {
			min-height: 3.25rem !important; max-height: 10rem !important;
			height: auto !important; resize: vertical; padding: 0.65rem 0.85rem !important;
			border-radius: 0.5rem 0 0 0.5rem !important; line-height: 1.45 !important;
		}
		.chat-compose--textarea .cb-send-btn {
			min-height: 3.25rem !important; height: auto !important; align-self: stretch;
			border-radius: 0 0.5rem 0.5rem 0 !important;
		}
		.chat-compose-meta {
			display: flex; justify-content: space-between; margin-top: 0.35rem; padding: 0 0.15rem;
		}
		.chat-bubble-body em { font-style: italic; opacity: 0.85; }
		.chat-bubble-body ul { margin: 0.35rem 0 0.35rem 1.1rem; padding: 0; }
		.chat-bubble-body li { margin-bottom: 0.2rem; }
	</style>
</head>
<body class="{PORTAL_BODY_CLASS}">
	<div class="min-height-300 {HEADER_BG_CLASS} position-absolute w-100"></div>
	<aside class="sidenav bg-white navbar navbar-vertical navbar-expand-xs border-0 border-radius-xl my-3 fixed-start ms-4 " id="sidenav-main"></aside>
	<main class="main-content position-relative border-radius-lg ">
		{TOP_NAVBAR}
		<div class="container-fluid py-4">
			{MAIN_CONTENT}
		</div>
	</main>
	<script>
		const chatWindow = document.getElementById('chatWindow');
		const chatInput = document.getElementById('chatInput');
		const sendBtn = document.getElementById('sendBtn');
		const clearChatBtn = document.getElementById('clearChatBtn');
		const tokenEstimateEl = document.getElementById('tokenEstimate');
		const assistantName = {ASSISTANT_NAME_JSON};
		const isTextarea = chatInput && chatInput.tagName === 'TEXTAREA';
		const welcomeHtml = chatWindow ? chatWindow.innerHTML : '';

		function escapeHtml(text) {
			const div = document.createElement('div');
			div.textContent = text;
			return div.innerHTML;
		}

		function estimateTokens(text) {
			const t = (text || '').trim();
			if (!t) return 0;
			return Math.max(1, Math.ceil(t.length / 3.8));
		}

		function updateTokenEstimate() {
			if (!tokenEstimateEl || !chatInput) return;
			const n = estimateTokens(chatInput.value);
			tokenEstimateEl.textContent = '~' + n.toLocaleString() + ' tokens';
			tokenEstimateEl.classList.toggle('text-danger', n > 6000);
		}

		function formatReply(text) {
			let safe = escapeHtml(text);
			safe = safe.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
			safe = safe.replace(/_(.+?)_/g, '<em>$1</em>');
			safe = safe.replace(/^[-•]\s+(.+)$/gm, '<li>$1</li>');
			safe = safe.replace(/(<li>.*<\/li>\n?)+/g, function(block) {
				return '<ul>' + block + '</ul>';
			});
			return safe;
		}

		function appendMessage(sender, html, links, meta) {
			const row = document.createElement('div');
			row.className = 'd-flex mb-3 ' + (sender === 'You' ? 'chat-message-user' : 'chat-message-bot');
			let linksHtml = '';
			if (links && links.length) {
				linksHtml = '<div class="chat-links">' + links.map(function(link) {
					return '<a class="btn btn-xs btn-outline-primary btn-sm" href="' + escapeHtml(link.url) + '">' + escapeHtml(link.label) + '</a>';
				}).join('') + '</div>';
			}
			let metaHtml = '';
			if (meta && (meta.mode || meta.tokens_used)) {
				const parts = [];
				if (meta.mode === 'ai') parts.push('GPT');
				else if (meta.mode === 'smart') parts.push('Smart');
				if (meta.tokens_used) parts.push('~' + meta.tokens_used + ' tokens');
				metaHtml = '<span class="cb-hint">' + parts.join(' · ') + '</span>';
			}
			if (sender === 'You') {
				row.innerHTML = '<div class="chat-bubble ms-auto"><span class="chat-bubble-label">You:</span><div class="chat-bubble-body">' + escapeHtml(html) + '</div></div>';
			} else {
				var botIcon = document.body.classList.contains('client-chatbot-page')
					? '<div class="cb-bot-avatar"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></div>'
					: '<div class="icon icon-shape icon-sm me-2 bg-gradient-dark shadow text-center"><i class="ni ni-chat-round text-white opacity-10"></i></div>';
				row.innerHTML = botIcon + '<div class="chat-bubble"><span class="chat-bubble-label">' + escapeHtml(assistantName) + ':</span><div class="chat-bubble-body">' + formatReply(html) + linksHtml + metaHtml + '</div></div>';
			}
			chatWindow.appendChild(row);
			chatWindow.scrollTop = chatWindow.scrollHeight;
		}

		function appendTyping() {
			const row = document.createElement('div');
			row.className = 'd-flex mb-3 chat-message-bot';
			row.id = 'chatTyping';
			var thinkIcon = document.body.classList.contains('client-chatbot-page')
				? '<div class="cb-bot-avatar"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></div>'
				: '<div class="icon icon-shape icon-sm me-2 bg-gradient-dark shadow text-center"><i class="ni ni-chat-round text-white opacity-10"></i></div>';
			row.innerHTML = thinkIcon + '<div class="chat-bubble text-muted">Analyzing your data…</div>';
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
			updateTokenEstimate();
			sendBtn.disabled = true;
			appendTyping();
			try {
				const response = await fetch('chatbot-api.php', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify({ action: 'chat', message: q })
				});
				const data = await response.json();
				removeTyping();
				if (!data.ok) {
					appendMessage(assistantName, data.error || 'Sorry, I could not process that request.');
					return;
				}
				appendMessage(assistantName, data.reply, data.links || [], {
					mode: data.mode,
					tokens_used: data.tokens_used
				});
			} catch (err) {
				removeTyping();
				appendMessage(assistantName, 'Network error. Please try again.');
			} finally {
				sendBtn.disabled = false;
				chatInput.focus();
			}
		}

		async function clearConversation() {
			if (!confirm('Clear this conversation? The assistant will forget prior messages.')) return;
			try {
				await fetch('chatbot-api.php', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify({ action: 'clear' })
				});
			} catch (e) { /* ignore */ }
			if (chatWindow && welcomeHtml) {
				chatWindow.innerHTML = welcomeHtml;
			}
		}

		if (sendBtn) sendBtn.addEventListener('click', function() { sendMessage(); });
		if (clearChatBtn) clearChatBtn.addEventListener('click', clearConversation);
		if (chatInput) {
			chatInput.addEventListener('input', updateTokenEstimate);
			chatInput.addEventListener('keydown', function(e) {
				if (e.key !== 'Enter') return;
				if (isTextarea && e.shiftKey) return;
				e.preventDefault();
				sendMessage();
			});
			updateTokenEstimate();
		}
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
$mainContentHtml = str_replace('{SHORTCUTS_HTML}', $shortcutsHtml, $mainContentHtml);
$html = str_replace('{MAIN_CONTENT}', $mainContentHtml, $html);
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
