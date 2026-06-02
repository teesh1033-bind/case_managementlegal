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
        html {
            height: 100%;
            background-color: var(--bs-primary);
        }
        body.login-page {
            margin: 0;
            min-height: 100%;
            background-color: var(--bs-primary);
            color: var(--bs-body-color);
        }
        .login-page .login-shell {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
        }
        .auth-card {
            width: 100%;
            max-width: 440px;
            margin: 0 auto;
            border: none;
            border-radius: 0.75rem;
            overflow: hidden;
            box-shadow: var(--bs-box-shadow);
            background: var(--bs-body-bg);
        }
        .login-page .auth-card > .card-header {
            background: transparent;
            color: var(--bs-heading-color);
            padding: 1.5rem 1.5rem 0.25rem;
        }
        .login-page .auth-card > .card-header h3 {
            font-weight: 700;
            letter-spacing: -0.02em;
            color: var(--bs-heading-color);
            font-size: 1.5rem;
        }
        .login-page .auth-card > .card-header p {
            margin: 0.5rem 0 0;
            font-size: 0.875rem;
            color: var(--bs-secondary-color);
            line-height: 1.5;
        }
        .auth-body {
            padding: 1.75rem 1.5rem 1.5rem;
        }
        .login-type-selector {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
        }
        .login-type-option {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 0.35rem;
            padding: 0.65rem 0.4rem;
            border: 1px solid var(--bs-border-color);
            border-radius: 0.65rem;
            cursor: pointer;
            transition: border-color 0.2s ease, background 0.2s ease, box-shadow 0.2s ease;
            background: var(--bs-body-bg);
        }
        .login-type-option:hover {
            border-color: rgba(var(--bs-primary-rgb), 0.35);
            background: rgba(var(--bs-primary-rgb), 0.04);
        }
        .login-type-option.active {
            border-color: var(--bs-primary);
            background-color: rgba(var(--bs-primary-rgb), 0.08);
            box-shadow: 0 0 0 1px rgba(var(--bs-primary-rgb), 0.2);
        }
        .login-type-option .login-type-icon-wrap {
            width: 2.25rem;
            height: 2.25rem;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--bs-body-bg);
            border: 1px solid var(--bs-border-color);
            transition: inherit;
        }
        .login-type-option.active .login-type-icon-wrap {
            border-color: var(--bs-primary);
            background: var(--bs-body-bg);
            color: var(--bs-primary);
        }
        .login-type-option i {
            font-size: 1rem;
            line-height: 1;
            margin: 0;
            color: var(--bs-secondary-color);
        }
        .login-type-option.active i {
            color: var(--bs-primary);
        }
        .login-type-option h6 {
            margin: 0;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--bs-secondary-color);
            line-height: 1.25;
            text-align: center;
        }
        .login-type-option.active h6 {
            color: var(--bs-primary);
        }
        .login-page .input-group {
            border-radius: 0.5rem;
            overflow: hidden;
            border: 1px solid var(--bs-border-color);
            transition: box-shadow 0.15s ease, border-color 0.15s ease;
        }
        .login-page .input-group:focus-within {
            border-color: rgba(var(--bs-primary-rgb), 0.55);
            box-shadow: 0 0 0 0.2rem rgba(var(--bs-primary-rgb), 0.15);
        }
        .login-page .input-group .input-group-text {
            border: none;
            background: var(--bs-gray-100);
            color: var(--bs-body-color);
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 3rem;
            padding: 0.75rem 0.85rem;
        }
        .login-page .input-group .input-group-text i {
            font-size: 1.1rem;
            line-height: 1;
            vertical-align: middle;
        }
        .login-page .input-group .form-control {
            border: none;
            box-shadow: none;
            padding-top: 0.75rem;
            padding-bottom: 0.75rem;
        }
        .login-page .input-group .form-control:focus {
            box-shadow: none;
        }
        .login-page .password-toggle {
            border: none;
            background: var(--bs-gray-100);
            color: var(--bs-secondary-color);
            min-width: 3rem;
            padding: 0.75rem 0.85rem;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            border-left: 1px solid var(--bs-border-color);
            transition: color 0.15s ease, background-color 0.15s ease, transform 0.15s ease;
        }
        .login-page .password-toggle:hover,
        .login-page .password-toggle:focus {
            color: var(--bs-primary);
            background: rgba(var(--bs-primary-rgb), 0.08);
            outline: none;
        }
        .login-page .password-toggle:active {
            transform: scale(0.97);
        }
        .login-page .password-toggle .eye-icon {
            width: 18px;
            height: 18px;
            display: inline-block;
            color: currentColor;
        }
        .login-page .password-toggle .eye-icon.is-hidden {
            display: none;
        }
        .login-page .password-toggle.is-visible {
            color: var(--bs-primary);
            background: rgba(var(--bs-primary-rgb), 0.12);
        }
        .login-help {
            margin-top: 1.5rem;
            padding-top: 1.25rem;
            border-top: 1px solid var(--bs-border-color);
        }
        .login-help-title {
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--bs-secondary-color);
            margin-bottom: 0.75rem;
            text-align: center;
        }
        .login-help dl {
            margin: 0;
            font-size: 0.8125rem;
            color: var(--bs-secondary-color);
            line-height: 1.5;
        }
        .login-help dt {
            font-weight: 600;
            color: var(--bs-heading-color);
            margin-top: 0.65rem;
        }
        .login-help dt:first-child {
            margin-top: 0;
        }
        .login-help dd {
            margin: 0.2rem 0 0 0;
            padding-left: 0;
        }
        .login-page .form-control-label {
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--bs-heading-color);
            margin-bottom: 0.35rem;
        }
        .login-page .btn-primary {
            border-radius: 0.5rem;
            font-weight: 600;
            padding-top: 0.75rem;
            padding-bottom: 0.75rem;
        }
    </style>
</head>
<body class="login-page">
    <div class="login-shell">
        <div class="w-100" style="max-width: 440px;">
                <div class="card auth-card">
                    <div class="card-header pb-0 text-center border-0">
                        <img src="{COMPANY_LOGO_URL}" alt="{COMPANY_NAME} logo" style="width: 48px; height: 48px; object-fit: contain; margin-bottom: 0.75rem;">
                        <h3 class="mb-0 font-weight-bolder">{LOGIN_PORTAL_TITLE}</h3>
                        <p class="mb-0">Choose how you sign in, then enter your credentials.</p>
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
                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary btn-lg" id="loginButton">
                                    <span id="loginText">Sign in as Admin</span>
                                </button>
                            </div>
                        </form>

                        <div class="login-help">
                            <div class="login-help-title">Who uses each portal</div>
                            <dl>
                                <dt>Admin</dt>
                                <dd>Administrators and staff who manage the system.</dd>
                                <dt>Lawyer</dt>
                                <dd>Attorneys with assigned matters and firm tools.</dd>
                                <dt>Client</dt>
                                <dd>Clients viewing cases, billing, and appointments.</dd>
                            </dl>
                        </div>
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
