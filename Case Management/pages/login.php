<?php
session_start();
require_once __DIR__ . '/../inc/db.php';

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

$messageHtml = $message ? '<div class="alert alert-' . htmlspecialchars($messageType) . ' alert-dismissible fade show" role="alert">' . htmlspecialchars($message) . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>' : '';

$html = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="apple-touch-icon" sizes="76x76" href="../assets/img/apple-icon.png">
    <link rel="icon" type="image/png" href="../assets/img/favicon.png">
    <title>LexMate - Login Portal</title>
    <link href="https://fonts.googleapis.com/css?family=Open+Sans:300,400,600,700" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-icons.css" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-svg.css" rel="stylesheet" />
    <script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
    <link id="pagestyle" href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
    <style>
        .auth-card {
            max-width: 420px;
            margin: 0 auto;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            border: none;
            border-radius: 10px;
        }
        .auth-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 10px 10px 0 0;
            padding: 2rem;
            text-align: center;
        }
        .auth-body {
            padding: 2rem;
        }
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        .login-type-selector {
            display: flex;
            margin-bottom: 1.5rem;
        }
        .login-type-option {
            flex: 1;
            text-align: center;
            padding: 0.75rem;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            background: white;
        }
        .login-type-option.active {
            border-color: #667eea;
            background: #f8f9ff;
        }
        .login-type-option:hover {
            border-color: #667eea;
        }
        .login-type-option i {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
            color: #6c757d;
        }
        .login-type-option.active i {
            color: #667eea;
        }
        .login-type-option h6 {
            margin: 0;
            font-size: 0.9rem;
            color: #6c757d;
        }
        .login-type-option.active h6 {
            color: #667eea;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row justify-content-center align-items-center" style="min-height: 100vh;">
            <div class="col-md-6 col-lg-4">
                <div class="card auth-card">
                    <div class="card-header auth-header">
                        <h3 class="mb-0">LexMate Portal</h3>
                        <p class="mb-0 opacity-8">Select your login type and enter your credentials</p>
                    </div>
                    <div class="card-body auth-body">
                        {MESSAGE}

                        <!-- Login Type Selector -->
                        <div class="login-type-selector mb-4">
                            <div class="login-type-option active" onclick="selectLoginType('admin')" style="flex: 1;">
                                <i class="ni ni-settings"></i>
                                <h6>Admin Portal</h6>
                            </div>
                            <div class="login-type-option" onclick="selectLoginType('lawyer')" style="flex: 1;">
                                <i class="ni ni-single-02"></i>
                                <h6>Lawyer Portal</h6>
                            </div>
                            <div class="login-type-option" onclick="selectLoginType('client')" style="flex: 1;">
                                <i class="ni ni-circle-08"></i>
                                <h6>Client Portal</h6>
                            </div>
                        </div>

                        <form method="post" id="loginForm">
                            <input type="hidden" name="login_type" id="login_type" value="admin">

                            <div class="form-group mb-3">
                                <label class="form-control-label">Username</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text"><i class="ni ni-single-02"></i></span>
                                    </div>
                                    <input type="text" class="form-control" name="username" placeholder="Enter your username" required>
                                </div>
                            </div>
                            <div class="form-group mb-4">
                                <label class="form-control-label">Password</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text"><i class="ni ni-lock-circle-open"></i></span>
                                    </div>
                                    <input type="password" class="form-control" name="password" placeholder="Enter your password" required>
                                </div>
                            </div>
                            <div class="text-center">
                                <button type="submit" class="btn btn-primary btn-lg w-100" id="loginButton">
                                    <span id="loginText">Sign In as Admin</span>
                                </button>
                            </div>
                        </form>

                        <div class="text-center mt-4">
                            <small class="text-muted">
                                <strong>Admin Portal:</strong> For administrators and staff members<br>
                                <strong>Lawyer Portal:</strong> For registered lawyers with assigned cases<br>
                                <strong>Client Portal:</strong> For clients to view cases, payments, and appointments
                            </small>
                        </div>
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
                document.getElementById('loginText').textContent = 'Sign In as Admin';
            } else if (type === 'lawyer') {
                options[1].classList.add('active');
                options[0].classList.remove('active');
                options[2].classList.remove('active');
                document.getElementById('loginText').textContent = 'Sign In as Lawyer';
            } else if (type === 'client') {
                options[2].classList.add('active');
                options[0].classList.remove('active');
                options[1].classList.remove('active');
                document.getElementById('loginText').textContent = 'Sign In as Client';
            }
        }

        // Set initial state
        selectLoginType('admin');
    </script>
</body>
</html>
HTML;

$html = str_replace('{MESSAGE}', $messageHtml, $html);
echo $html;
?>
