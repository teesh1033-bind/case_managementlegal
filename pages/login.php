<?php
session_start();
require_once __DIR__ . '/../inc/db.php';

$companyBranding = getCompanyBranding();
$loginPortalTitle = $companyBranding['name'] . ' Portal';
$allowedLoginTypes = ['admin', 'lawyer', 'client'];
$defaultLoginType = isset($_GET['portal']) ? strtolower(trim((string) $_GET['portal'])) : 'admin';
if (!in_array($defaultLoginType, $allowedLoginTypes, true)) {
    $defaultLoginType = 'admin';
}

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $loginType = isset($_POST['login_type']) ? strtolower(trim((string) $_POST['login_type'])) : '';
    $username = trim(isset($_POST['username']) ? $_POST['username'] : '');
    $password = isset($_POST['password']) ? $_POST['password'] : '';

    if (empty($username) || empty($password) || empty($loginType)) {
        $message = 'Please enter all required fields.';
        $messageType = 'danger';
    } elseif (!in_array($loginType, $allowedLoginTypes, true)) {
        $message = 'Invalid login type selected.';
        $messageType = 'danger';
    } elseif ($loginType === 'admin') {
        // Admin login logic
        try {
            $stmt = $pdo->prepare("
                SELECT u.*
                FROM users u
                WHERE u.username = ? AND u.role IN ('admin', 'staff')
            ");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if (!$user) {
                $message = 'Admin user not found. Please check your username.';
                $messageType = 'danger';
            } elseif (!in_array($user['role'], ['admin', 'staff'])) {
                $message = 'Access denied. This account does not have admin privileges.';
                $messageType = 'danger';
            } elseif (!password_verify($password, $user['password'])) {
                $message = 'Invalid password. Please check your password.';
                $messageType = 'danger';
            } else {
                // Set session variables for admin
                $_SESSION['admin_id'] = $user['id'];
                $_SESSION['admin_username'] = $user['username'];
                $_SESSION['admin_role'] = $user['role'];
                $_SESSION['admin_name'] = $user['username'];
                require_once __DIR__ . '/../lib/admin-locale.php';
                $_SESSION['admin_locale'] = getAdminPortalLocale((int) $user['id']);

                // Redirect to admin dashboard
                header('Location: dashboard.php');
                exit;
            }
        } catch (PDOException $e) {
            $message = 'Login error: ' . htmlspecialchars($e->getMessage());
            $messageType = 'danger';
        }
    } elseif ($loginType === 'lawyer') {
        // Lawyer login logic
        try {
            $stmt = $pdo->prepare("
                SELECT u.*, l.id as lawyer_id, l.first_name, l.last_name
                FROM users u
                LEFT JOIN lawyers l ON l.user_id = u.id
                WHERE u.username = ? AND l.id IS NOT NULL
            ");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if (!$user) {
                $message = 'Lawyer account not found. Please check your username or contact administrator.';
                $messageType = 'danger';
            } elseif (!password_verify($password, $user['password'])) {
                $message = 'Invalid password. Please check your password.';
                $messageType = 'danger';
            } else {
                // Set session variables for lawyer
                $_SESSION['lawyer_id'] = $user['lawyer_id'];
                $_SESSION['lawyer_user_id'] = $user['id'];
                $_SESSION['lawyer_name'] = $user['first_name'] . ' ' . $user['last_name'];
                $_SESSION['lawyer_username'] = $user['username'];

                // Redirect to lawyer dashboard
                header('Location: lawyer-dashboard.php');
                exit;
            }
        } catch (PDOException $e) {
            $message = 'Login error: ' . htmlspecialchars($e->getMessage());
            $messageType = 'danger';
        }
    } elseif ($loginType === 'client') {
        // Client login logic
        try {
            $stmt = $pdo->prepare("
                SELECT u.*, c.id as client_id, c.first_name, c.last_name
                FROM users u
                LEFT JOIN clients c ON c.user_id = u.id
                WHERE u.username = ? AND c.id IS NOT NULL
            ");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if (!$user) {
                $message = 'Client account not found. Please check your username or contact administrator.';
                $messageType = 'danger';
            } elseif (!password_verify($password, $user['password'])) {
                $message = 'Invalid password. Please check your password.';
                $messageType = 'danger';
            } else {
                // Set session variables for client
                $_SESSION['client_id'] = $user['client_id'];
                $_SESSION['client_user_id'] = $user['id'];
                $_SESSION['client_name'] = $user['first_name'] . ' ' . $user['last_name'];
                $_SESSION['client_username'] = $user['username'];
                $_SESSION['client_locale'] = getClientPortalLocale((int) $user['client_id']);

                // Redirect to client dashboard
                header('Location: client-dashboard.php');
                exit;
            }
        } catch (PDOException $e) {
            $message = 'Login error: ' . htmlspecialchars($e->getMessage());
            $messageType = 'danger';
        }
    }
}

// Check if already logged in (redirect appropriately)
if (isset($_SESSION['admin_id'])) {
    header('Location: dashboard.php');
    exit;
} elseif (isset($_SESSION['lawyer_id'])) {
    header('Location: lawyer-dashboard.php');
    exit;
} elseif (isset($_SESSION['client_id'])) {
    header('Location: client-dashboard.php');
    exit;
}

$messageHtml = $message ? '<div class="alert alert-' . htmlspecialchars($messageType) . ' alert-dismissible fade show mb-3" role="alert">' . htmlspecialchars($message) . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>' : '';

$html = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="apple-touch-icon" sizes="76x76" href="../assets/img/apple-icon.png">
    <link rel="icon" type="image/png" href="../assets/img/favicon.png">
    <title>{COMPANY_NAME} - Login Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-icons.css" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-svg.css" rel="stylesheet" />
    <script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
    <link id="pagestyle" href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
    <link href="../assets/css/app-font-montserrat.css?v=1" rel="stylesheet" />
    <style>
        :root {
            --login-primary: #023e8a;
            --login-primary-dark: #001845;
            --login-accent: #0353a4;
            --login-ink: #0f172a;
            --login-muted: #64748b;
            --login-border: rgba(15, 23, 42, 0.08);
            --login-surface: #ffffff;
            --login-radius: 1.25rem;
        }
        html, body {
            min-height: 100%;
            margin: 0;
        }
        body.login-page {
            color: var(--login-ink);
            font-family: 'Montserrat', sans-serif;
            -webkit-font-smoothing: antialiased;
        }
        .login-layout {
            min-height: 100vh;
            display: grid;
            grid-template-columns: 1.1fr 0.9fr;
        }
        .login-brand-panel {
            position: relative;
            background:
                radial-gradient(circle at 15% 20%, rgba(53, 166, 255, 0.22), transparent 45%),
                linear-gradient(160deg, #06285a 0%, #0b3f8e 45%, #1463c9 100%);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 3.5rem;
            overflow: hidden;
        }
        .login-brand-panel::before,
        .login-brand-panel::after {
            content: "";
            position: absolute;
            border-radius: 0;
            pointer-events: none;
        }
        .login-brand-panel::before {
            inset: 0;
            background-image: radial-gradient(rgba(255, 255, 255, 0.08) 1px, transparent 1px);
            background-size: 18px 18px;
            opacity: 0.6;
        }
        .login-brand-panel::after {
            width: 360px;
            height: 360px;
            right: -120px;
            bottom: -130px;
            background: radial-gradient(circle, rgba(53, 166, 255, 0.42), rgba(53, 166, 255, 0));
        }
        .login-brand-content {
            position: relative;
            max-width: 440px;
            z-index: 1;
        }
        .login-brand-logo {
            width: 56px;
            height: 56px;
            object-fit: contain;
            border-radius: 14px;
            background: rgba(255, 255, 255, 0.96);
            padding: 8px;
            margin-bottom: 1.5rem;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
        }
        .login-brand-content h1 {
            color: #fff;
            font-size: clamp(1.75rem, 3vw, 2.25rem);
            font-weight: 800;
            letter-spacing: -0.03em;
            margin-bottom: 0.75rem;
            line-height: 1.15;
        }
        .login-brand-content > p {
            color: rgba(255, 255, 255, 0.82);
            line-height: 1.7;
            margin-bottom: 2rem;
            font-size: 0.95rem;
        }
        .login-feature {
            display: flex;
            align-items: center;
            gap: 0.85rem;
            color: rgba(255, 255, 255, 0.94);
            font-size: 0.9rem;
            margin-bottom: 0.85rem;
            font-weight: 500;
        }
        .login-feature i {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(8px);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.95rem;
        }
        .login-form-panel {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
            background:
                radial-gradient(ellipse 70% 60% at 10% 10%, rgba(43, 111, 255, 0.09), transparent 50%),
                #f5f8ff;
        }
        .auth-shell {
            width: 100%;
            max-width: 430px;
        }
        .auth-intro {
            margin-bottom: 1.1rem;
            padding: 0;
        }
        .auth-intro h3 {
            font-size: 1.95rem;
            line-height: 1.2;
            font-weight: 800;
            letter-spacing: -0.03em;
            color: var(--login-ink);
            margin-bottom: 0.35rem;
        }
        .auth-intro p {
            margin: 0;
            color: var(--login-muted);
            font-size: 0.92rem;
            font-weight: 500;
        }
        .auth-body {
            padding: 0;
        }
        .login-type-selector {
            display: flex;
            gap: 0.35rem;
            padding: 0.35rem;
            margin-bottom: 1.5rem;
            background: #eef3ff;
            border-radius: 14px;
            border: 1px solid var(--login-border);
        }
        .login-type-option {
            flex: 1;
            border: none;
            border-radius: 10px;
            padding: 0.7rem 0.35rem;
            cursor: pointer;
            transition: background 0.2s ease, box-shadow 0.2s ease, transform 0.15s ease;
            background: transparent;
            text-align: center;
        }
        .login-type-option:hover:not(.active) {
            background: rgba(255, 255, 255, 0.55);
        }
        .login-type-option.active {
            background: #fff;
            box-shadow: 0 2px 10px rgba(15, 23, 42, 0.08);
            transform: translateY(-1px);
        }
        .login-type-option .login-type-icon-wrap {
            width: 2.1rem;
            height: 2.1rem;
            margin: 0 auto 0.35rem;
            border-radius: 8px;
            background: rgba(44, 169, 164, 0.12);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--login-primary);
            transition: background 0.2s ease, color 0.2s ease;
        }
        .login-type-option.active .login-type-icon-wrap {
            background: linear-gradient(135deg, var(--login-primary), var(--login-accent));
            color: #fff;
        }
        .login-type-option i {
            font-size: 0.95rem;
            color: inherit;
        }
        .login-type-option h6 {
            margin: 0;
            font-size: 0.68rem;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: var(--login-muted);
            font-weight: 700;
        }
        .login-type-option small {
            display: block;
            margin-top: 0.12rem;
            font-size: 0.66rem;
            color: #8a98ad;
            font-weight: 500;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .login-type-option.active h6 {
            color: var(--login-ink);
        }
        .login-type-option.active small {
            color: #6d7f98;
        }
        .login-page .form-control-label {
            font-size: 0.72rem;
            color: var(--login-muted);
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            margin-bottom: 0.45rem;
            display: block;
        }
        .login-page .input-group {
            border-radius: 12px;
            border: 1.5px solid #e2e8f0;
            overflow: hidden;
            background: #f8fafc;
            transition: border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
        }
        .login-page .input-group:focus-within {
            border-color: var(--login-primary);
            background: #fff;
            box-shadow: 0 0 0 4px rgba(43, 111, 255, 0.14);
        }
        .login-page .input-group .input-group-text {
            border: none;
            background: transparent;
            min-width: 2.75rem;
            color: #94a3b8;
            padding-left: 1rem;
        }
        .login-page .input-group:focus-within .input-group-text {
            color: var(--login-primary);
        }
        .login-page .input-group .form-control {
            border: none;
            box-shadow: none;
            background: transparent;
            padding: 0.85rem 1rem 0.85rem 0.25rem;
            font-size: 0.94rem;
            font-weight: 500;
            color: var(--login-ink);
        }
        .login-page .input-group .form-control::placeholder {
            color: #94a3b8;
            font-weight: 400;
        }
        .login-page .password-toggle {
            border: none;
            border-left: 1px solid #e2e8f0;
            background: transparent;
            color: #94a3b8;
            min-width: 2.75rem;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: color 0.15s ease, background 0.15s ease;
        }
        .login-page .password-toggle:hover,
        .login-page .password-toggle:focus {
            background: rgba(43, 111, 255, 0.08);
            color: var(--login-primary);
            outline: none;
        }
        .login-page .password-toggle .eye-icon {
            width: 18px;
            height: 18px;
            color: currentColor;
        }
        .login-page .password-toggle .eye-icon.is-hidden {
            display: none;
        }
        .login-meta {
            margin-top: 0.25rem;
            margin-bottom: 1.25rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 0.84rem;
            color: var(--login-muted);
        }
        .login-meta a {
            color: var(--login-primary);
            text-decoration: none;
            font-weight: 600;
            transition: color 0.15s ease;
        }
        .login-meta a:hover {
            color: var(--login-primary-dark);
        }
        .login-page .login-remember {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
            user-select: none;
            margin: 0;
            font-weight: 500;
        }
        .login-page .login-remember-checkbox {
            width: 1rem;
            height: 1rem;
            margin: 0;
            accent-color: var(--login-primary);
            cursor: pointer;
        }
        .login-page .btn-primary {
            border: none;
            border-radius: 12px;
            font-weight: 700;
            font-size: 0.92rem;
            letter-spacing: 0.01em;
            padding: 0.85rem 1rem;
            background: linear-gradient(135deg, var(--login-primary) 0%, var(--login-accent) 100%);
            box-shadow: 0 4px 14px rgba(43, 111, 255, 0.32);
            transition: transform 0.15s ease, box-shadow 0.15s ease, filter 0.15s ease;
        }
        .login-page .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 22px rgba(43, 111, 255, 0.38);
            filter: brightness(1.03);
        }
        .login-page .btn-primary:active {
            transform: translateY(0);
        }
        .login-security {
            margin-top: 1.25rem;
            padding-top: 1.25rem;
            border-top: 1px solid #f1f5f9;
            font-size: 0.78rem;
            color: #94a3b8;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.4rem;
        }
        .login-security::before {
            content: "";
            width: 14px;
            height: 14px;
            background: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%2394a3b8' stroke-width='2'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' d='M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z'/%3E%3C/svg%3E") center/contain no-repeat;
        }
        .login-page .alert {
            border-radius: 12px;
            border: none;
            font-size: 0.88rem;
            font-weight: 500;
        }
        @media (max-width: 991.98px) {
            .login-layout {
                grid-template-columns: 1fr;
            }
            .login-brand-panel {
                min-height: 240px;
                padding: 2rem 1.5rem;
            }
            .login-brand-content > p,
            .login-feature:nth-child(n+3) {
                display: none;
            }
            .login-form-panel {
                padding: 1.25rem;
            }
            .auth-body {
                padding: 0;
            }
            .auth-intro h3 {
                font-size: 1.7rem;
            }
        }
    </style>
</head>
<body class="login-page">
    <div class="login-layout">
        <section class="login-brand-panel">
            <div class="login-brand-content">
                <img src="{COMPANY_LOGO_URL}" alt="{COMPANY_NAME} logo" class="login-brand-logo">
                <h1>{COMPANY_NAME}</h1>
                <p>Secure portal for managing notary/legal operations, clients, cases, and documents.</p>
                <div class="login-feature"><i class="ni ni-lock-circle-open"></i><span>Enterprise-grade security</span></div>
                <div class="login-feature"><i class="ni ni-chart-bar-32"></i><span>Real-time analytics</span></div>
                <div class="login-feature"><i class="ni ni-single-copy-04"></i><span>Client and case management</span></div>
            </div>
        </section>
        <section class="login-form-panel">
            <div class="auth-shell">
                <div class="auth-intro">
                    <h3 class="mb-0">Welcome back</h3>
                    <p>Sign in to your portal account</p>
                </div>
                <div class="card-body auth-body">
                    {MESSAGE}

                        <div class="login-type-selector mb-4" role="tablist" aria-label="Login type">
                            <div class="login-type-option active" role="button" tabindex="0" onclick="selectLoginType('admin')" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();selectLoginType('admin');}">
                                <span class="login-type-icon-wrap"><i class="ni ni-settings" aria-hidden="true"></i></span>
                                <h6>Admin</h6>
                                <small>Manage operations</small>
                            </div>
                            <div class="login-type-option" role="button" tabindex="0" onclick="selectLoginType('lawyer')" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();selectLoginType('lawyer');}">
                                <span class="login-type-icon-wrap"><i class="ni ni-single-02" aria-hidden="true"></i></span>
                                <h6>Lawyer</h6>
                                <small>Handle legal matters</small>
                            </div>
                            <div class="login-type-option" role="button" tabindex="0" onclick="selectLoginType('client')" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();selectLoginType('client');}">
                                <span class="login-type-icon-wrap"><i class="ni ni-circle-08" aria-hidden="true"></i></span>
                                <h6>Client</h6>
                                <small>View your cases</small>
                            </div>
                        </div>

                        <form method="post" id="loginForm">
                            <input type="hidden" name="login_type" id="login_type" value="{DEFAULT_LOGIN_TYPE}">

                            <div class="mb-3">
                                <label class="form-control-label" for="login_username">Email / Username</label>
                                <div class="input-group input-group-lg">
                                    <span class="input-group-text"><i class="ni ni-single-02" aria-hidden="true"></i></span>
                                    <input type="text" class="form-control" id="login_username" name="username" placeholder="Email address or username" autocomplete="username" required>
                                </div>
                            </div>
                            <div class="mb-4">
                                <label class="form-control-label" for="login_password">Password</label>
                                <div class="input-group input-group-lg">
                                    <span class="input-group-text"><i class="ni ni-lock-circle-open" aria-hidden="true"></i></span>
                                    <input type="password" class="form-control" id="login_password" name="password" placeholder="Password" autocomplete="current-password" required>
                                    <button type="button" class="password-toggle" id="toggle_login_password" aria-label="Show password" aria-controls="login_password" aria-pressed="false">
                                        <svg class="eye-icon eye-open" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                            <path d="M2.5 12C4.3 8.5 7.7 6.25 12 6.25C16.3 6.25 19.7 8.5 21.5 12C19.7 15.5 16.3 17.75 12 17.75C7.7 17.75 4.3 15.5 2.5 12Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                                            <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.8"/>
                                        </svg>
                                        <svg class="eye-icon eye-closed is-hidden" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                            <path d="M3 3L21 21" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                                            <path d="M2.5 12C3.35 10.35 4.55 8.96 6 7.9M9.2 6.5C10.08 6.33 11.02 6.25 12 6.25C16.3 6.25 19.7 8.5 21.5 12C20.75 13.46 19.75 14.73 18.55 15.72M14.8 17.5C13.93 17.67 12.99 17.75 12 17.75C7.7 17.75 4.3 15.5 2.5 12" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                                        </svg>
                                    </button>
                                </div>
                            </div>
                            <div class="login-meta">
                                <label class="login-remember mb-0">
                                    <input type="checkbox" class="login-remember-checkbox" id="login_remember" name="remember" value="1" aria-label="Remember me">
                                    <span>Remember me</span>
                                </label>
                                <a href="javascript:void(0)">Forgot password?</a>
                            </div>
                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary" id="loginButton">
                                    <span id="loginText">Sign in</span>
                                </button>
                            </div>
                        </form>

                    <div class="login-security">Protected by secure authentication and encryption</div>
                </div>
            </div>
        </section>
    </div>

    <script src="../assets/js/core/popper.min.js"></script>
    <script src="../assets/js/core/bootstrap.min.js"></script>

    <script>
        function selectLoginType(type) {
            // Update hidden input
            document.getElementById('login_type').value = type;

            // Update UI
            const options = document.querySelectorAll('.login-type-option');
            options.forEach(option => option.classList.remove('active'));

            if (type === 'admin') {
                options[0].classList.add('active');
                options[1].classList.remove('active');
                options[2].classList.remove('active');
                document.getElementById('loginText').textContent = 'Sign in as Admin';
            } else if (type === 'lawyer') {
                options[1].classList.add('active');
                options[0].classList.remove('active');
                options[2].classList.remove('active');
                document.getElementById('loginText').textContent = 'Sign in as Lawyer';
            } else if (type === 'client') {
                options[2].classList.add('active');
                options[0].classList.remove('active');
                options[1].classList.remove('active');
                document.getElementById('loginText').textContent = 'Sign in as Client';
            }
        }

        // Set initial state from query/default selection
        selectLoginType('{DEFAULT_LOGIN_TYPE}');

        const passwordInput = document.getElementById('login_password');
        const passwordToggle = document.getElementById('toggle_login_password');
        if (passwordInput && passwordToggle) {
            passwordToggle.addEventListener('click', function () {
                const isPassword = passwordInput.type === 'password';
                passwordInput.type = isPassword ? 'text' : 'password';
                passwordToggle.setAttribute('aria-pressed', isPassword ? 'true' : 'false');
                passwordToggle.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
                passwordToggle.classList.toggle('is-visible', isPassword);
                const openIcon = passwordToggle.querySelector('.eye-open');
                const closedIcon = passwordToggle.querySelector('.eye-closed');
                if (openIcon && closedIcon) {
                    openIcon.classList.toggle('is-hidden', isPassword);
                    closedIcon.classList.toggle('is-hidden', !isPassword);
                }
            });
        }
    </script>
</body>
</html>
HTML;

$html = str_replace('{MESSAGE}', $messageHtml, $html);
$html = str_replace('{COMPANY_NAME}', htmlspecialchars($companyBranding['name']), $html);
$html = str_replace('{COMPANY_LOGO_URL}', htmlspecialchars($companyBranding['logo_url']), $html);
$html = str_replace('{LOGIN_PORTAL_TITLE}', htmlspecialchars($loginPortalTitle), $html);
$html = str_replace('{DEFAULT_LOGIN_TYPE}', htmlspecialchars($defaultLoginType), $html);
echo $html;
?>
