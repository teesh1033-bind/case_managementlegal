<?php
session_start();
require_once __DIR__ . '/../inc/db.php';

$companyBranding = getCompanyBranding();
$loginPortalTitle = $companyBranding['name'] . ' Portal';

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $loginType = isset($_POST['login_type']) ? $_POST['login_type'] : '';
    $username = trim(isset($_POST['username']) ? $_POST['username'] : '');
    $password = isset($_POST['password']) ? $_POST['password'] : '';

    if (empty($username) || empty($password) || empty($loginType)) {
        $message = 'Please enter all required fields.';
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

                // Redirect to client dashboard
                header('Location: client-dashboard.php');
                exit;
            }
        } catch (PDOException $e) {
            $message = 'Login error: ' . htmlspecialchars($e->getMessage());
            $messageType = 'danger';
        }
    } else {
        $message = 'Invalid login type selected.';
        $messageType = 'danger';
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
        html, body {
            min-height: 100%;
            margin: 0;
            background: #f3f4f7;
        }
        body.login-page {
            color: #344767;
        }
        .login-layout {
            min-height: 100vh;
            display: grid;
            grid-template-columns: 1.15fr 0.85fr;
        }
        .login-brand-panel {
            position: relative;
            background: linear-gradient(140deg, #2d3f6f 0%, #4a5fa8 44%, #6f7fd2 100%);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 3rem;
            overflow: hidden;
        }
        .login-brand-panel::before {
            content: "";
            position: absolute;
            inset: 0;
            background-image: radial-gradient(rgba(255, 255, 255, 0.12) 1px, transparent 1px);
            background-size: 18px 18px;
            opacity: 0.2;
            pointer-events: none;
        }
        .login-brand-content {
            position: relative;
            max-width: 420px;
            z-index: 1;
        }
        .login-brand-logo {
            width: 52px;
            height: 52px;
            object-fit: contain;
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.95);
            padding: 6px;
            margin-bottom: 1.25rem;
        }
        .login-brand-content h1 {
            color: #fff;
            font-size: 2rem;
            font-weight: 800;
            letter-spacing: -0.02em;
            margin-bottom: 0.85rem;
        }
        .login-brand-content p {
            color: rgba(255, 255, 255, 0.9);
            line-height: 1.65;
            margin-bottom: 1.35rem;
        }
        .login-feature {
            display: flex;
            align-items: center;
            gap: 0.7rem;
            color: rgba(255, 255, 255, 0.96);
            font-size: 0.92rem;
            margin-bottom: 0.75rem;
        }
        .login-feature i {
            width: 28px;
            height: 28px;
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.15);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.82rem;
        }
        .login-form-panel {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
            background: #f3f4f7;
        }
        .auth-card {
            width: 100%;
            max-width: 430px;
            border: 1px solid #e8e9ef;
            border-radius: 1rem;
            background: #fff;
            box-shadow: 0 12px 30px rgba(52, 71, 103, 0.08);
            overflow: hidden;
        }
        .login-page .auth-card > .card-header {
            background: transparent;
            color: #344767;
            padding: 1.6rem 1.5rem 0.5rem;
            border: 0;
        }
        .login-page .auth-card > .card-header h3 {
            font-size: 2rem;
            line-height: 1;
            font-weight: 800;
            margin-bottom: 0.5rem;
        }
        .login-page .auth-card > .card-header p {
            margin: 0;
            color: #8392ab;
            font-size: 0.9rem;
        }
        .auth-body {
            padding: 1.5rem;
        }
        .login-type-selector {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.55rem;
            margin-bottom: 1.25rem;
        }
        .login-type-option {
            border: 1px solid #e2e6ef;
            border-radius: 0.75rem;
            padding: 0.65rem 0.45rem;
            cursor: pointer;
            transition: all 0.18s ease;
            background: #fff;
            text-align: center;
        }
        .login-type-option:hover {
            border-color: rgba(94, 114, 228, 0.55);
            background: rgba(94, 114, 228, 0.04);
        }
        .login-type-option.active {
            border-color: #5e72e4;
            box-shadow: 0 0 0 2px rgba(94, 114, 228, 0.12);
            background: rgba(94, 114, 228, 0.08);
        }
        .login-type-option .login-type-icon-wrap {
            width: 2rem;
            height: 2rem;
            margin: 0 auto 0.4rem;
            border-radius: 999px;
            background: #f7f8fb;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #5e72e4;
        }
        .login-type-option i {
            font-size: 0.9rem;
            color: inherit;
        }
        .login-type-option h6 {
            margin: 0;
            font-size: 0.74rem;
            letter-spacing: 0.02em;
            text-transform: uppercase;
            color: #67748e;
            font-weight: 700;
        }
        .login-page .input-group {
            border-radius: 0.65rem;
            border: 1px solid #dde2ec;
            overflow: hidden;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }
        .login-page .input-group:focus-within {
            border-color: #5e72e4;
            box-shadow: 0 0 0 3px rgba(94, 114, 228, 0.12);
        }
        .login-page .input-group .input-group-text {
            border: none;
            background: #f7f8fb;
            min-width: 2.6rem;
            color: #8392ab;
        }
        .login-page .input-group .form-control {
            border: none;
            box-shadow: none;
            padding-top: 0.75rem;
            padding-bottom: 0.75rem;
            font-size: 0.92rem;
        }
        .login-page .password-toggle {
            border: none;
            border-left: 1px solid #e4e7ef;
            background: #f7f8fb;
            color: #8392ab;
            min-width: 2.6rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-page .password-toggle:hover,
        .login-page .password-toggle:focus {
            background: rgba(94, 114, 228, 0.09);
            color: #5e72e4;
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
            margin-top: 0.6rem;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 0.84rem;
            color: #8392ab;
        }
        .login-meta a {
            color: #5e72e4;
            text-decoration: none;
            font-weight: 600;
        }
        .login-meta a:hover {
            text-decoration: underline;
        }
        .login-page .login-remember {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
            user-select: none;
            margin: 0;
        }
        .login-page .login-remember-checkbox {
            margin: 0;
        }
        .login-page .btn-primary {
            border: none;
            border-radius: 0.6rem;
            font-weight: 700;
            padding: 0.72rem 1rem;
            background: linear-gradient(135deg, #5e72e4, #825ee4);
            box-shadow: 0 8px 20px rgba(94, 114, 228, 0.25);
        }
        .login-security {
            margin-top: 1rem;
            font-size: 0.8rem;
            color: #8392ab;
            text-align: center;
        }
        .login-page .form-control-label {
            font-size: 0.78rem;
            color: #67748e;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            margin-bottom: 0.3rem;
        }
        @media (max-width: 991.98px) {
            .login-layout {
                grid-template-columns: 1fr;
            }
            .login-brand-panel {
                min-height: 280px;
                padding: 2rem 1.5rem;
            }
            .login-form-panel {
                padding: 1.25rem;
            }
            .login-page .auth-card > .card-header h3 {
                font-size: 1.65rem;
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
                <p>Secure portal for managing legal operations, clients, cases, and appointments.</p>
                <div class="login-feature"><i class="ni ni-lock-circle-open"></i><span>Enterprise-grade security</span></div>
                <div class="login-feature"><i class="ni ni-chart-bar-32"></i><span>Real-time analytics</span></div>
                <div class="login-feature"><i class="ni ni-single-copy-04"></i><span>Client and case management</span></div>
            </div>
        </section>
        <section class="login-form-panel">
            <div class="card auth-card">
                <div class="card-header border-0">
                    <h3 class="mb-0">Welcome back</h3>
                    <p>Sign in to your account</p>
                </div>
                <div class="card-body auth-body">
                    {MESSAGE}

                        <div class="login-type-selector mb-4" role="tablist" aria-label="Login type">
                            <div class="login-type-option active" role="button" tabindex="0" onclick="selectLoginType('admin')" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();selectLoginType('admin');}">
                                <span class="login-type-icon-wrap"><i class="ni ni-settings" aria-hidden="true"></i></span>
                                <h6>Admin</h6>
                            </div>
                            <div class="login-type-option" role="button" tabindex="0" onclick="selectLoginType('lawyer')" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();selectLoginType('lawyer');}">
                                <span class="login-type-icon-wrap"><i class="ni ni-single-02" aria-hidden="true"></i></span>
                                <h6>Lawyer</h6>
                            </div>
                            <div class="login-type-option" role="button" tabindex="0" onclick="selectLoginType('client')" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();selectLoginType('client');}">
                                <span class="login-type-icon-wrap"><i class="ni ni-circle-08" aria-hidden="true"></i></span>
                                <h6>Client</h6>
                            </div>
                        </div>

                        <form method="post" id="loginForm">
                            <input type="hidden" name="login_type" id="login_type" value="admin">

                            <div class="mb-3">
                                <label class="form-control-label" for="login_username">Username</label>
                                <div class="input-group input-group-lg">
                                    <span class="input-group-text"><i class="ni ni-single-02" aria-hidden="true"></i></span>
                                    <input type="text" class="form-control" id="login_username" name="username" placeholder="Username" autocomplete="username" required>
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
                                    <span id="loginText">Sign in as Admin</span>
                                </button>
                            </div>
                        </form>

                    <div class="login-security">Protected by secure authentication and encryption</div>
                </div>
            </div>
        </div>
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

        // Set initial state
        selectLoginType('admin');

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
echo $html;
?>
