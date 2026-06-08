<?php
session_start();
require_once __DIR__ . '/../inc/db.php';

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim(isset($_POST['username']) ? $_POST['username'] : '');
    $password = isset($_POST['password']) ? $_POST['password'] : '';

    if (empty($username) || empty($password)) {
        $message = 'Please enter both username and password.';
        $messageType = 'danger';
    } else {
        try {
            // Check if user exists and has lawyer role
            $stmt = $pdo->prepare("
                SELECT u.*, l.id as lawyer_id, l.first_name, l.last_name
                FROM users u
                LEFT JOIN lawyers l ON l.user_id = u.id
                WHERE u.username = ? AND l.id IS NOT NULL
            ");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                // Set session variables for lawyer
                $_SESSION['lawyer_id'] = $user['lawyer_id'];
                $_SESSION['lawyer_user_id'] = $user['id'];
                $_SESSION['lawyer_name'] = $user['first_name'] . ' ' . $user['last_name'];
                $_SESSION['lawyer_username'] = $user['username'];

                // Redirect to lawyer dashboard
                header('Location: lawyer-dashboard.php');
                exit;
            } else {
                $message = 'Invalid username or password, or you are not registered as a lawyer. Please contact the administrator to ensure your lawyer account is properly set up.';
                $messageType = 'danger';
            }
        } catch (PDOException $e) {
            $message = 'Login error: ' . htmlspecialchars($e->getMessage());
            $messageType = 'danger';
        }
    }
}

// Redirect to unified login page
header('Location: login.php');
exit;

$messageHtml = $message ? '<div class="alert alert-' . htmlspecialchars($messageType) . ' alert-dismissible fade show" role="alert">' . htmlspecialchars($message) . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>' : '';

$html = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="apple-touch-icon" sizes="76x76" href="../assets/img/apple-icon.png">
    <link rel="icon" type="image/png" href="../assets/img/favicon.png">
    <title>LegalPro - Lawyer Portal Login</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-icons.css" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-svg.css" rel="stylesheet" />
    <script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
    <link id="pagestyle" href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
<link href="../assets/css/app-font-montserrat.css?v=1" rel="stylesheet" />
    <style>
        .auth-card {
            max-width: 400px;
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
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row justify-content-center align-items-center" style="min-height: 100vh;">
            <div class="col-md-6 col-lg-4">
                <div class="card auth-card">
                    <div class="card-header auth-header">
                        <h3 class="mb-0">Lawyer Portal</h3>
                        <p class="mb-0 opacity-8">Sign in to access your dashboard</p>
                    </div>
                    <div class="card-body auth-body">
                        {MESSAGE}
                        <form method="post">
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
                                <button type="submit" class="btn btn-primary btn-lg w-100">Sign In</button>
                            </div>
                        </form>
                        <hr class="my-4">
                        <div class="text-center">
                            <a href="../pages/sign-in.php" class="text-muted">Back to Admin Portal</a>
                            <br>
                            <small class="text-muted mt-2">
                                <strong>Need help?</strong> Make sure your lawyer account was created properly in the admin panel.
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="../assets/js/core/popper.min.js"></script>
    <script src="../assets/js/core/bootstrap.min.js"></script>
</body>
</html>
HTML;

$html = str_replace('{MESSAGE}', $messageHtml, $html);
echo $html;
?>
