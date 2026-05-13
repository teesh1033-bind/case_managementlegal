<?php
require_once __DIR__ . '/inc/db.php';

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    try {
        if ($action === 'create_admin') {
            $username = trim($_POST['username']);
            $password = $_POST['password'];
            $email = trim($_POST['email']);
            $role = $_POST['role'];

            if (empty($username) || empty($password)) {
                $message = 'Username and password are required.';
                $messageType = 'danger';
            } else {
                // Check if user already exists
                $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
                $stmt->execute([$username]);
                if ($stmt->fetch()) {
                    $message = 'Username already exists. Please choose a different username.';
                    $messageType = 'danger';
                } else {
                    // Create the user
                    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("INSERT INTO users (username, password, email, role) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$username, $hashedPassword, $email, $role]);

                    $message = 'Admin user created successfully! You can now login.';
                    $messageType = 'success';
                }
            }
        } elseif ($action === 'reset_password') {
            $username = trim($_POST['reset_username']);
            $newPassword = $_POST['reset_password'];

            if (empty($username) || empty($newPassword)) {
                $message = 'Username and new password are required.';
                $messageType = 'danger';
            } else {
                // Check if user exists
                $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
                $stmt->execute([$username]);
                $user = $stmt->fetch();

                if (!$user) {
                    $message = 'User not found.';
                    $messageType = 'danger';
                } else {
                    // Reset password
                    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE username = ?");
                    $stmt->execute([$hashedPassword, $username]);

                    $message = 'Password reset successfully! You can now login with the new password.';
                    $messageType = 'success';
                }
            }
        } elseif ($action === 'update_admin') {
            $username = trim($_POST['existing_username']);
            $newPassword = $_POST['new_password'];
            $newRole = $_POST['new_role'];

            if (empty($username)) {
                $message = 'Please specify which user to update.';
                $messageType = 'danger';
            } else {
                // Check if user exists
                $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
                $stmt->execute([$username]);
                $user = $stmt->fetch();

                if (!$user) {
                    $message = 'User not found.';
                    $messageType = 'danger';
                } else {
                    // Update the user
                    $updateData = ['role' => $newRole];
                    $updateSql = "UPDATE users SET role = ?";
                    $params = [$newRole];

                    if (!empty($newPassword)) {
                        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                        $updateSql .= ", password = ?";
                        $params[] = $hashedPassword;
                    }

                    $updateSql .= " WHERE username = ?";
                    $params[] = $username;

                    $stmt = $pdo->prepare($updateSql);
                    $stmt->execute($params);

                    $message = 'User updated successfully!';
                    $messageType = 'success';
                }
            }
        }
    } catch (PDOException $e) {
        $message = 'Database error: ' . htmlspecialchars($e->getMessage());
        $messageType = 'danger';
    }
}

// Get existing users for display
$existingUsers = [];
try {
    $stmt = $pdo->query("SELECT username, email, role FROM users ORDER BY username");
    $existingUsers = $stmt->fetchAll();
} catch (PDOException $e) {
    $existingUsers = [];
}

$messageHtml = $message ? '<div class="alert alert-' . htmlspecialchars($messageType) . ' alert-dismissible fade show" role="alert">' . htmlspecialchars($message) . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>' : '';

$html = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>LexMate - Admin Setup</title>
    <link href="https://fonts.googleapis.com/css?family=Open+Sans:300,400,600,700" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-icons.css" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-svg.css" rel="stylesheet" />
    <script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
    <link id="pagestyle" href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
    <style>
        .setup-card { max-width: 800px; margin: 0 auto; }
        .user-table { font-size: 14px; }
    </style>
</head>
<body class="bg-gray-100">
    <div class="container-fluid py-4">
        <div class="row justify-content-center">
            <div class="col-12 col-lg-10">
                <div class="card setup-card">
                    <div class="card-header">
                        <h3 class="mb-0">LexMate Admin Setup</h3>
                        <p class="text-muted mb-0">Create or update admin users for the system</p>
                    </div>
                    <div class="card-body">
                        {MESSAGE}

                        <!-- Existing Users -->
                        <div class="mb-4">
                            <h5>Existing Users</h5>
                            <div class="table-responsive">
                                <table class="table table-striped user-table">
                                    <thead>
                                        <tr>
                                            <th>Username</th>
                                            <th>Email</th>
                                            <th>Role</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {USER_ROWS}
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="row">
                            <!-- Create New Admin -->
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-header">
                                        <h6 class="mb-0">Create New Admin User</h6>
                                    </div>
                                    <div class="card-body">
                                        <form method="post">
                                            <input type="hidden" name="action" value="create_admin">
                                            <div class="mb-3">
                                                <label class="form-label">Username *</label>
                                                <input type="text" class="form-control" name="username" required>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Password *</label>
                                                <input type="password" class="form-control" name="password" required>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Email</label>
                                                <input type="email" class="form-control" name="email">
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Role</label>
                                                <select class="form-control" name="role">
                                                    <option value="admin">Admin</option>
                                                    <option value="staff">Staff</option>
                                                </select>
                                            </div>
                                            <button type="submit" class="btn btn-primary w-100">Create Admin User</button>
                                        </form>
                                    </div>
                                </div>
                            </div>

                            <!-- Update Existing User -->
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-header">
                                        <h6 class="mb-0">Update Existing User</h6>
                                    </div>
                                    <div class="card-body">
                                        <form method="post">
                                            <input type="hidden" name="action" value="update_admin">
                                            <div class="mb-3">
                                                <label class="form-label">Username to Update *</label>
                                                <input type="text" class="form-control" name="existing_username" required>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">New Password (leave empty to keep current)</label>
                                                <input type="password" class="form-control" name="new_password">
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">New Role</label>
                                                <select class="form-control" name="new_role">
                                                    <option value="admin">Admin</option>
                                                    <option value="staff">Staff</option>
                                                    <option value="user">User</option>
                                                    <option value="lawyer">Lawyer</option>
                                                </select>
                                            </div>
                                            <button type="submit" class="btn btn-warning w-100">Update User</button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="mt-4">
                            <div class="alert alert-info">
                                <strong>Quick Setup:</strong><br>
                                - Create a user with username "admin" and password "admin123"<br>
                                - Set role to "admin"<br>
                                - Then login at: <code>login.php</code>
                            </div>
                        </div>

                        <!-- Password Reset Section -->
                        <div class="row mt-4">
                            <div class="col-12">
                                <div class="card">
                                    <div class="card-header">
                                        <h6 class="mb-0">Reset Password (if login fails)</h6>
                                    </div>
                                    <div class="card-body">
                                        <form method="post">
                                            <input type="hidden" name="action" value="reset_password">
                                            <div class="row">
                                                <div class="col-md-4">
                                                    <label class="form-label">Username</label>
                                                    <input type="text" class="form-control" name="reset_username" placeholder="admin" required>
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label">New Password</label>
                                                    <input type="password" class="form-control" name="reset_password" placeholder="admin123" required>
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label">&nbsp;</label>
                                                    <button type="submit" class="btn btn-danger w-100">Reset Password</button>
                                                </div>
                                            </div>
                                        </form>
                                        <small class="text-muted mt-2">
                                            Use this if you forgot your password or if login is not working.
                                        </small>
                                    </div>
                                </div>
                            </div>
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

// Build user rows
$userRows = '';
if (empty($existingUsers)) {
    $userRows = '<tr><td colspan="3" class="text-center text-muted">No users found</td></tr>';
} else {
    foreach ($existingUsers as $user) {
        $userRows .= '<tr>';
        $userRows .= '<td>' . htmlspecialchars($user['username']) . '</td>';
        $userRows .= '<td>' . htmlspecialchars($user['email'] ?: 'No email') . '</td>';
        $userRows .= '<td><span class="badge bg-' . ($user['role'] === 'admin' ? 'primary' : 'secondary') . '">' . htmlspecialchars($user['role']) . '</span></td>';
        $userRows .= '</tr>';
    }
}

$html = str_replace(['{MESSAGE}', '{USER_ROWS}'], [$messageHtml, $userRows], $html);
echo $html;
?>
