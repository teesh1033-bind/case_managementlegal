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
        try {
            $stmt = $pdo->prepare("SELECT u.* FROM users u WHERE u.username = ? AND u.role IN ('admin', 'staff')");
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
                $_SESSION['admin_id'] = $user['id'];
                $_SESSION['admin_username'] = $user['username'];
                $_SESSION['admin_role'] = $user['role'];
                $_SESSION['admin_name'] = $user['username'];
                require_once __DIR__ . '/../lib/admin-locale.php';
                $_SESSION['admin_locale'] = getAdminPortalLocale((int) $user['id']);
                header('Location: dashboard.php');
                exit;
            }
        } catch (PDOException $e) {
            $message = 'Login error: ' . htmlspecialchars($e->getMessage());
            $messageType = 'danger';
        }
    } elseif ($loginType === 'lawyer') {
        try {
            $stmt = $pdo->prepare("
                SELECT u.*, l.id as lawyer_id, l.first_name, l.last_name
                FROM users u LEFT JOIN lawyers l ON l.user_id = u.id
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
                $_SESSION['lawyer_id'] = $user['lawyer_id'];
                $_SESSION['lawyer_user_id'] = $user['id'];
                $_SESSION['lawyer_name'] = $user['first_name'] . ' ' . $user['last_name'];
                $_SESSION['lawyer_username'] = $user['username'];
                header('Location: lawyer-dashboard.php');
                exit;
            }
        } catch (PDOException $e) {
            $message = 'Login error: ' . htmlspecialchars($e->getMessage());
            $messageType = 'danger';
        }
    } elseif ($loginType === 'client') {
        try {
            $stmt = $pdo->prepare("
                SELECT u.*, c.id as client_id, c.first_name, c.last_name
                FROM users u LEFT JOIN clients c ON c.user_id = u.id
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
                $_SESSION['client_id'] = $user['client_id'];
                $_SESSION['client_user_id'] = $user['id'];
                $_SESSION['client_name'] = $user['first_name'] . ' ' . $user['last_name'];
                $_SESSION['client_username'] = $user['username'];
                $_SESSION['client_locale'] = getClientPortalLocale((int) $user['client_id']);
                header('Location: client-dashboard.php');
                exit;
            }
        } catch (PDOException $e) {
            $message = 'Login error: ' . htmlspecialchars($e->getMessage());
            $messageType = 'danger';
        }
    }
}

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

$messageHtml = $message ? '<div class="alert" role="alert">' . htmlspecialchars($message) . '</div>' : '';

require_once __DIR__ . '/../lib/portal-theme.php';
$portalThemeCss = renderPortalThemeCss();
$loginThemePreset = getPortalTheme()['preset'];
$loginAccentPrimary = $loginThemePreset['primary'];
$loginAccentDark = $loginThemePreset['primary_dark'];
$loginAccentRgb = portalThemePrimaryRgb($loginAccentPrimary);
$loginAccentMap = [
    'admin' => [
        'primary' => $loginAccentPrimary,
        'dark' => $loginAccentDark,
        'rgb' => $loginAccentRgb,
    ],
    'lawyer' => [
        'primary' => $loginAccentPrimary,
        'dark' => $loginAccentDark,
        'rgb' => $loginAccentRgb,
    ],
    'client' => [
        'primary' => $loginAccentPrimary,
        'dark' => $loginAccentDark,
        'rgb' => $loginAccentRgb,
    ],
];
$loginAccentMapJson = json_encode($loginAccentMap, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

$html = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="apple-touch-icon" sizes="76x76" href="../assets/img/apple-icon.png">
    <link rel="icon" type="image/png" href="../assets/img/favicon.png">
    <title>{COMPANY_NAME} - Login Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
    <style>{PORTAL_THEME_CSS}</style>
    <style>
        body.login-page {
            --lp-accent: {LOGIN_ACCENT_PRIMARY};
            --lp-accent-dark: {LOGIN_ACCENT_DARK};
            --lp-accent-rgb: {LOGIN_ACCENT_RGB};
        }
    </style>
    <style>
        * { box-sizing: border-box; }
        html, body { height: 100%; margin: 0; overflow: hidden; }

        body.login-page {
            font-family: 'Outfit', sans-serif;
            background: #000000;
            --lp-glass: rgba(45, 43, 66, 0.72);
            --lp-glass-border: rgba(255, 255, 255, 0.14);
            --lp-text: #ffffff;
            --lp-text-muted: #b0b0cc;
            --lp-input-bg: rgba(0, 0, 0, 0.28);
            --lp-input-border: rgba(255, 255, 255, 0.12);
            color: var(--lp-text);
        }

        .login-scene {
            width: 100%;
            height: 100vh;
            height: 100dvh;
            position: relative;
            overflow: hidden;
        }

        .login-scene::before {
            content: '';
            position: absolute;
            inset: 0;
            background:
                linear-gradient(rgba(var(--lp-accent-rgb), 0.58), rgba(var(--lp-accent-rgb), 0.58)),
                url("../assets/img/login-custom.png?v=6") center center / cover no-repeat;
            background-blend-mode: color, normal;
            filter: grayscale(0.15) contrast(1.08) brightness(0.82);
            opacity: 1;
            z-index: 0;
            pointer-events: none;
        }

        .login-scene::after {
            content: '';
            position: absolute;
            inset: 0;
            pointer-events: none;
            z-index: 1;
            opacity: 0.45;
            background:
                repeating-radial-gradient(
                    ellipse at 20% 58%,
                    rgba(120, 220, 255, 0.32) 0px,
                    rgba(120, 220, 255, 0.32) 1px,
                    rgba(120, 220, 255, 0) 5px,
                    rgba(120, 220, 255, 0) 14px
                ),
                repeating-radial-gradient(
                    ellipse at 80% 42%,
                    rgba(120, 220, 255, 0.28) 0px,
                    rgba(120, 220, 255, 0.28) 1px,
                    rgba(120, 220, 255, 0) 6px,
                    rgba(120, 220, 255, 0) 15px
                );
            mix-blend-mode: screen;
        }

        .login-stage {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            height: 100%;
            position: relative;
            z-index: 2;
        }

        .login-stage::before,
        .login-stage::after {
            content: none;
        }

        .login-plate {
            flex: 0 0 auto;
            width: min(640px, 94vw);
            min-width: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: clamp(1rem, 2.5vh, 2rem) clamp(1.25rem, 3vw, 2.5rem);
            position: relative;
            z-index: 3;
            background: transparent;
        }

        .login-plate::after {
            content: none;
        }

        .login-plate::before {
            content: none;
        }

        .glass-card {
            --lp-corner-size: clamp(52px, 8vh, 72px);
            --lp-corner-outset: clamp(20px, 3.4vh, 28px);
            width: 100%;
            max-width: min(600px, 96%);
            max-height: calc(100dvh - 2rem);
            overflow: visible;
            padding: clamp(1.75rem, 3.8vh, 2.75rem) clamp(2rem, 3.6vw, 2.9rem);
            border-radius: 28px;
            background: var(--lp-glass);
            border: 1px solid color-mix(in srgb, var(--lp-accent) 72%, white 28%);
            backdrop-filter: blur(24px) saturate(1.3);
            -webkit-backdrop-filter: blur(24px) saturate(1.3);
            box-shadow: 0 24px 64px rgba(0, 0, 0, 0.35), 0 0 0 1px color-mix(in srgb, var(--lp-accent) 60%, white 40%), inset 0 1px 0 rgba(255, 255, 255, 0.08);
            position: relative;
            z-index: 2;
        }

        .glass-card::before,
        .glass-card::after {
            content: '';
            position: absolute;
            width: var(--lp-corner-size);
            height: var(--lp-corner-size);
            pointer-events: none;
            z-index: 3;
            border-color: color-mix(in srgb, var(--lp-accent) 72%, white 28%);
            border-style: solid;
            border-width: 0;
        }

        .glass-card::before {
            top: calc(-1 * var(--lp-corner-outset));
            left: calc(-1 * var(--lp-corner-outset));
            border-top-width: 2px;
            border-left-width: 2px;
        }

        .glass-card::after {
            right: calc(-1 * var(--lp-corner-outset));
            bottom: calc(-1 * var(--lp-corner-outset));
            border-right-width: 2px;
            border-bottom-width: 2px;
        }

        .login-art {
            position: absolute;
            top: 0;
            right: 0;
            width: 58%;
            height: 100%;
            min-width: 0;
            padding: 0;
            overflow: hidden;
            z-index: 1;
            background: transparent;
            -webkit-mask-image: linear-gradient(90deg, transparent 0%, rgba(0, 0, 0, 0.55) 22%, #000 42%, #000 100%);
            mask-image: linear-gradient(90deg, transparent 0%, rgba(0, 0, 0, 0.55) 22%, #000 42%, #000 100%);
        }

        .login-art.login-art--left {
            right: auto;
            left: 0;
            -webkit-mask-image: linear-gradient(90deg, #000 0%, #000 58%, rgba(0, 0, 0, 0.55) 78%, transparent 100%);
            mask-image: linear-gradient(90deg, #000 0%, #000 58%, rgba(0, 0, 0, 0.55) 78%, transparent 100%);
        }

        .login-art--left .login-art__frame img {
            transform: scaleY(-1);
            transform-origin: center;
            object-position: 80% center;
        }

        .login-art::before {
            content: none;
        }

        .login-art__frame {
            position: absolute;
            inset: 0;
            overflow: hidden;
            isolation: isolate;
        }

        .login-art__frame img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: 14% center;
            display: block;
            filter: grayscale(1) contrast(1.12) brightness(0.9);
        }

        .login-art__frame::before {
            content: '';
            position: absolute;
            inset: 0;
            background: rgba(var(--lp-accent-rgb), 0.62);
            mix-blend-mode: color;
            pointer-events: none;
            z-index: 1;
        }

        .login-art__frame::after {
            content: none;
        }

        .login-transition-gate {
            position: fixed;
            top: 0;
            right: -42vw;
            width: 42vw;
            height: 100dvh;
            pointer-events: none;
            z-index: 20;
            opacity: 0;
            background:
                linear-gradient(100deg, rgba(var(--lp-accent-rgb), 0) 0%, rgba(var(--lp-accent-rgb), 0.2) 34%, rgba(var(--lp-accent-rgb), 0.82) 56%, rgba(255, 255, 255, 0.92) 64%, rgba(var(--lp-accent-rgb), 0.14) 74%, rgba(0, 0, 0, 0) 100%);
            filter: blur(0.2px);
            transform: translateX(0);
        }

        .login-transition-gate::after {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 38%;
            height: 100%;
            background: linear-gradient(90deg, rgba(255, 255, 255, 0.55) 0%, rgba(255, 255, 255, 0) 100%);
            opacity: 0.85;
        }

        body.login-page.login-entering .login-stage {
            animation: lpPortalJump 55ms linear forwards;
        }
        body.login-page.login-entering .login-plate {
            animation: lpPlateSnap 55ms linear forwards;
        }
        body.login-page.login-entering .login-art {
            animation: lpArtRush 55ms linear forwards;
        }
        body.login-page.login-entering .login-art--left {
            animation: lpLeftRush 55ms linear forwards;
        }
        body.login-page.login-entering .login-transition-gate {
            animation: lpGateSweep 55ms linear forwards;
        }

        @keyframes lpPortalJump {
            0% { transform: translateX(0); opacity: 1; }
            100% { transform: translateX(-8%); opacity: 0.97; }
        }

        @keyframes lpPlateSnap {
            0% { transform: translateX(0) scale(1); opacity: 1; }
            100% { transform: translateX(-5%) scale(0.988); opacity: 0.96; }
        }

        @keyframes lpArtRush {
            0% { transform: translateX(0) scale(1); opacity: 1; }
            100% { transform: translateX(-12%) scale(1.02); opacity: 0.97; }
        }

        @keyframes lpLeftRush {
            0% { transform: translateX(0); opacity: 1; }
            100% { transform: translateX(-6%); opacity: 0.95; }
        }

        @keyframes lpGateSweep {
            0% { transform: translateX(0); opacity: 0; }
            20% { opacity: 1; }
            100% { transform: translateX(-145vw); opacity: 0.2; }
        }

        .card-head {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            margin-bottom: clamp(1rem, 2.4vh, 1.5rem);
        }
        .card-head img {
            width: clamp(52px, 8vh, 64px);
            height: clamp(52px, 8vh, 64px);
            object-fit: contain;
            border-radius: 50%;
            padding: 10px;
            margin-bottom: clamp(0.5rem, 1.2vh, 0.75rem);
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.15);
            align-self: center;
        }
        .card-head .company-name {
            margin: 0 0 clamp(0.65rem, 1.5vh, 0.85rem);
            padding-bottom: clamp(0.4rem, 1vh, 0.55rem);
            width: 100%;
            font-size: clamp(1.05rem, 2.1vh, 1.25rem);
            font-weight: 700;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            text-align: center;
            align-self: center;
            color: #ffffff;
            text-shadow: 0 1px 12px rgba(var(--lp-accent-rgb), 0.25);
            border-bottom: 2px solid var(--lp-accent);
            line-height: 1.2;
            transition: border-color 0.2s ease, text-shadow 0.2s ease;
        }

        .glass-card h2 {
            margin: 0 0 0.35rem;
            font-size: clamp(1.05rem, 2vh, 1.2rem);
            font-weight: 500;
            letter-spacing: 0.01em;
            text-align: left;
            color: var(--lp-text-muted);
        }
        .glass-card .subtitle {
            margin: 0;
            font-size: clamp(0.84rem, 1.6vh, 0.95rem);
            line-height: 1.5;
            font-weight: 400;
            text-align: left;
            color: rgba(176, 176, 204, 0.85);
        }

        .alert {
            margin-bottom: clamp(0.75rem, 1.8vh, 1rem);
            padding: 0.65rem 0.85rem;
            border-radius: 12px;
            font-size: clamp(0.8rem, 1.5vh, 0.88rem);
            background: rgba(200, 50, 65, 0.2);
            color: #ffc8ce;
            border: 1px solid rgba(255, 100, 110, 0.25);
        }

        .field { margin-bottom: clamp(0.8rem, 1.9vh, 1.1rem); }
        .field label {
            display: block;
            margin-bottom: 0.5rem;
            font-size: clamp(0.84rem, 1.6vh, 0.95rem);
            font-weight: 500;
            color: var(--lp-text-muted);
        }
        .field-input { position: relative; }
        .field-input input {
            width: 100%;
            padding: clamp(0.9rem, 2vh, 1.05rem) 2.75rem clamp(0.9rem, 2vh, 1.05rem) 1.05rem;
            font-family: inherit;
            font-size: clamp(0.95rem, 1.8vh, 1.05rem);
            color: var(--lp-text);
            background: var(--lp-input-bg);
            border: 1px solid var(--lp-input-border);
            border-radius: 12px;
            outline: none;
            transition: border-color 0.15s;
        }
        .field-input input::placeholder { color: var(--lp-text-muted); opacity: 0.7; }
        .field-input input:focus { border-color: rgba(var(--lp-accent-rgb), 0.55); }
        .field-input input:-webkit-autofill,
        .field-input input:-webkit-autofill:hover,
        .field-input input:-webkit-autofill:focus {
            -webkit-text-fill-color: var(--lp-text);
            -webkit-box-shadow: 0 0 0 1000px var(--lp-input-bg) inset;
            transition: background-color 9999s ease-in-out 0s;
        }
        .password-toggle {
            position: absolute;
            right: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            padding: 0;
            border: none;
            background: transparent;
            color: var(--lp-text-muted);
            cursor: pointer;
            display: flex;
        }
        .password-toggle:hover { color: var(--lp-accent); }
        .password-toggle svg { width: 18px; height: 18px; }
        .eye-icon.is-hidden { display: none; }

        .form-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin: 0.1rem 0 clamp(0.9rem, 2vh, 1.15rem);
            font-size: clamp(0.8rem, 1.5vh, 0.9rem);
        }
        .form-remember input { accent-color: var(--lp-accent); width: 16px; height: 16px; }
        .form-remember {
            display: flex;
            align-items: center;
            gap: 0.45rem;
            color: var(--lp-text-muted);
            cursor: pointer;
        }
        .form-forgot {
            color: var(--lp-text-muted);
            text-decoration: none;
        }
        .form-forgot:hover { color: var(--lp-accent); }

        .btn-login {
            width: 100%;
            padding: clamp(0.95rem, 2.2vh, 1.15rem);
            border: none;
            border-radius: 999px;
            font-family: inherit;
            font-size: clamp(1rem, 2vh, 1.12rem);
            font-weight: 700;
            color: var(--lp-accent-dark);
            background: #ffffff;
            cursor: pointer;
            transition: transform 0.1s, box-shadow 0.15s;
        }
        .btn-login:hover { box-shadow: 0 6px 20px rgba(0, 0, 0, 0.2); }
        .btn-login:active { transform: scale(0.99); }

        .divider {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin: clamp(0.85rem, 2vh, 1.15rem) 0;
            font-size: clamp(0.78rem, 1.4vh, 0.88rem);
            color: var(--lp-text-muted);
        }
        .divider::before,
        .divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: rgba(255, 255, 255, 0.12);
        }

        .role-picker {
            display: flex;
            gap: 0.4rem;
        }
        .role-btn {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.35rem;
            padding: clamp(0.68rem, 1.6vh, 0.82rem) 0.25rem;
            border: 1px solid var(--lp-input-border);
            border-radius: 12px;
            background: var(--lp-input-bg);
            font-family: inherit;
            font-size: clamp(0.72rem, 1.4vh, 0.82rem);
            font-weight: 600;
            color: var(--lp-text-muted);
            cursor: pointer;
            transition: border-color 0.15s, background 0.15s, color 0.15s;
        }
        .role-btn svg { width: 15px; height: 15px; }
        .role-btn.active {
            border-color: var(--lp-accent);
            background: rgba(var(--lp-accent-rgb), 0.22);
            color: #fff;
            box-shadow: inset 0 0 0 1px rgba(var(--lp-accent-rgb), 0.18);
        }

        .card-footer {
            margin-top: 1.1rem;
            text-align: center;
            font-size: 0.76rem;
            color: var(--lp-text-muted);
        }
        .card-footer a {
            color: #a8c4f0;
            text-decoration: none;
            font-weight: 600;
        }
        .card-footer a:hover { text-decoration: underline; }

        @media (max-height: 720px) {
            .glass-card { padding: 1.35rem 1.65rem; }
            .card-head { margin-bottom: 0.85rem; }
            .card-head img { width: 46px; height: 46px; padding: 8px; margin-bottom: 0.5rem; }
            .field { margin-bottom: 0.7rem; }
            .divider { margin: 0.7rem 0; }
        }

        @media (max-width: 820px) {
            html, body { overflow: auto; }
            .login-scene {
                height: auto;
                min-height: 100dvh;
                overflow: auto;
            }
            .login-stage {
                flex-direction: column;
                height: auto;
                min-height: 100dvh;
            }
            .login-art--left,
            .login-scene::after {
                display: none;
            }
            .login-plate {
                flex: 1 1 auto;
                max-width: none;
                min-height: 0;
                padding: 1.5rem;
            }
            .glass-card {
                --lp-corner-size: 56px;
                --lp-corner-outset: 20px;
            }
            .glass-card { max-height: none; overflow: visible; }
            .login-art {
                position: relative;
                width: 100%;
                min-height: 42vh;
                height: auto;
            }
            .login-art__frame img {
                object-position: center;
            }
        }

        @media (max-width: 480px) {
            .role-btn { font-size: 0.62rem; }
        }
    </style>
</head>
<body class="login-page" data-login-portal="{DEFAULT_LOGIN_TYPE}">
    <div class="login-transition-gate" aria-hidden="true"></div>
    <div class="login-scene">
        <div class="login-stage">
            <aside class="login-art login-art--left" aria-hidden="true">
                <div class="login-art__frame">
                    <img src="../assets/img/login-custom.png?v=6" alt="">
                </div>
            </aside>
            <div class="login-plate">
                <div class="glass-card">
                <div class="card-head">
                    <img src="{COMPANY_LOGO_URL}" alt="{COMPANY_NAME}">
                    <p class="company-name">{COMPANY_NAME}</p>
                    <h2>Welcome back</h2>
                    <p class="subtitle">Enter your credentials to continue to the portal.</p>
                </div>

                {MESSAGE}

                <form method="post" id="loginForm">
                <input type="hidden" name="login_type" id="login_type" value="{DEFAULT_LOGIN_TYPE}">

                <div class="field">
                    <label for="login_username">Username</label>
                    <div class="field-input">
                        <input type="text" id="login_username" name="username" placeholder="Enter your username" autocomplete="username" required>
                    </div>
                </div>

                <div class="field">
                    <label for="login_password">Password</label>
                    <div class="field-input">
                        <input type="password" id="login_password" name="password" placeholder="Enter your password" autocomplete="current-password" required>
                        <button type="button" class="password-toggle" id="toggle_login_password" aria-label="Show password" aria-pressed="false">
                            <svg class="eye-icon eye-open" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <path d="M2.5 12C4.3 8.5 7.7 6.25 12 6.25C16.3 6.25 19.7 8.5 21.5 12C19.7 15.5 16.3 17.75 12 17.75C7.7 17.75 4.3 15.5 2.5 12Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                                <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.8"/>
                            </svg>
                            <svg class="eye-icon eye-closed is-hidden" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <path d="M3 3L21 21" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                                <path d="M2.5 12C3.35 10.35 4.55 8.96 6 7.9M14.8 17.5C13.93 17.67 12.99 17.75 12 17.75C7.7 17.75 4.3 15.5 2.5 12" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <div class="form-row">
                    <label class="form-remember">
                        <input type="checkbox" id="login_remember" name="remember" value="1">
                        <span>Remember me</span>
                    </label>
                    <a class="form-forgot" href="javascript:void(0)">Forgot password?</a>
                </div>

                <button type="submit" class="btn-login" id="loginButton">Log in</button>
            </form>

            <div class="divider">AS</div>

            <div class="role-picker" role="tablist" aria-label="Portal role">
                <button type="button" class="role-btn active" data-role="admin" onclick="selectLoginType('admin')">
                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 3l8 4v6c0 4.5-3.5 8-8 9-4.5-1-8-4.5-8-9V7l8-4z" stroke="currentColor" stroke-width="1.6"/></svg>
                    Admin
                </button>
                <button type="button" class="role-btn" data-role="lawyer" onclick="selectLoginType('lawyer')">
                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M8 20h8M10 20V10l-3-6h10l-3 6v10" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                    Lawyer
                </button>
                <button type="button" class="role-btn" data-role="client" onclick="selectLoginType('client')">
                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="8" r="3.5" stroke="currentColor" stroke-width="1.6"/><path d="M5 20c0-3.5 3-6 7-6s7 2.5 7 6" stroke="currentColor" stroke-width="1.6"/></svg>
                    Client
                </button>
            </div>

                </div>
            </div>

            <aside class="login-art" aria-hidden="true">
                <div class="login-art__frame">
                    <img src="../assets/img/login-custom.png?v=6" alt="">
                </div>
            </aside>
        </div>
    </div>

    <script>
        var loginAccents = {LOGIN_ACCENT_MAP};

        function applyLoginAccent(type) {
            var accent = loginAccents[type] || loginAccents.admin;
            if (!accent) {
                return;
            }
            document.body.style.setProperty('--lp-accent', accent.primary);
            document.body.style.setProperty('--lp-accent-dark', accent.dark);
            document.body.style.setProperty('--lp-accent-rgb', accent.rgb);
            document.body.setAttribute('data-login-portal', type);
        }

        function selectLoginType(type) {
            document.getElementById('login_type').value = type;
            document.querySelectorAll('.role-btn').forEach(function (btn) {
                btn.classList.toggle('active', btn.dataset.role === type);
            });
            applyLoginAccent(type);
        }
        selectLoginType('{DEFAULT_LOGIN_TYPE}');

        var passwordInput = document.getElementById('login_password');
        var passwordToggle = document.getElementById('toggle_login_password');
        var loginForm = document.getElementById('loginForm');
        var loginButton = document.getElementById('loginButton');
        var loginTransitionMs = 55;
        var isSubmittingWithTransition = false;
        if (passwordInput && passwordToggle) {
            passwordToggle.addEventListener('click', function () {
                var isPassword = passwordInput.type === 'password';
                passwordInput.type = isPassword ? 'text' : 'password';
                passwordToggle.setAttribute('aria-pressed', isPassword ? 'true' : 'false');
                passwordToggle.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
                var openIcon = passwordToggle.querySelector('.eye-open');
                var closedIcon = passwordToggle.querySelector('.eye-closed');
                if (openIcon && closedIcon) {
                    openIcon.classList.toggle('is-hidden', isPassword);
                    closedIcon.classList.toggle('is-hidden', !isPassword);
                }
            });
        }

        if (loginForm) {
            loginForm.addEventListener('submit', function (event) {
                if (isSubmittingWithTransition) {
                    return;
                }
                if (typeof loginForm.checkValidity === 'function' && !loginForm.checkValidity()) {
                    return;
                }

                event.preventDefault();
                isSubmittingWithTransition = true;
                document.body.classList.add('login-entering');
                if (loginButton) {
                    loginButton.disabled = true;
                    loginButton.textContent = 'Entering...';
                }
                setTimeout(function () {
                    loginForm.submit();
                }, loginTransitionMs);
            });
        }
    </script>
</body>
</html>
HTML;

$html = str_replace('{MESSAGE}', $messageHtml, $html);
$html = str_replace('{PORTAL_THEME_CSS}', $portalThemeCss, $html);
$html = str_replace('{LOGIN_ACCENT_PRIMARY}', htmlspecialchars($loginAccentPrimary), $html);
$html = str_replace('{LOGIN_ACCENT_DARK}', htmlspecialchars($loginAccentDark), $html);
$html = str_replace('{LOGIN_ACCENT_RGB}', htmlspecialchars($loginAccentRgb), $html);
$html = str_replace('{LOGIN_ACCENT_MAP}', $loginAccentMapJson, $html);
$html = str_replace('{COMPANY_NAME}', htmlspecialchars($companyBranding['name']), $html);
$html = str_replace('{COMPANY_LOGO_URL}', htmlspecialchars($companyBranding['logo_url']), $html);
$html = str_replace('{LOGIN_PORTAL_TITLE}', htmlspecialchars($loginPortalTitle), $html);
$html = str_replace('{DEFAULT_LOGIN_TYPE}', htmlspecialchars($defaultLoginType), $html);
echo $html;
?>
