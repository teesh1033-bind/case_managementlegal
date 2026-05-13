<?php
session_start();
require_once __DIR__ . '/../inc/db.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header('Location: admin-login.php');
    exit;
}

$message = '';
$messageType = '';
$editLawyer = null;

// Ensure lawyer tables exist
try {
    $pdo->query("ALTER TABLE lawyers ADD COLUMN IF NOT EXISTS user_id INT NOT NULL AFTER id");
    $pdo->query("ALTER TABLE lawyers ADD FOREIGN KEY IF NOT EXISTS (user_id) REFERENCES users(id) ON DELETE CASCADE");
} catch (PDOException $e) {
    // Continue if already exists
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formType = isset($_POST['form_type']) ? $_POST['form_type'] : '';

    if ($formType === 'save_lawyer') {
        $lawyerId = isset($_POST['lawyer_id']) ? (int)$_POST['lawyer_id'] : 0;
        $userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
        $firstName = trim(isset($_POST['first_name']) ? $_POST['first_name'] : '');
        $lastName = trim(isset($_POST['last_name']) ? $_POST['last_name'] : '');
        $email = trim(isset($_POST['email']) ? $_POST['email'] : '');
        $phone = trim(isset($_POST['phone']) ? $_POST['phone'] : '');
        $licenseNumber = trim(isset($_POST['license_number']) ? $_POST['license_number'] : '');
        $specialization = trim(isset($_POST['specialization']) ? $_POST['specialization'] : '');
        $experienceYears = isset($_POST['experience_years']) ? (int)$_POST['experience_years'] : 0;
        $bio = trim(isset($_POST['bio']) ? $_POST['bio'] : '');
        $officeAddress = trim(isset($_POST['office_address']) ? $_POST['office_address'] : '');
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        // Handle user account updates for existing lawyers
        $updateUsername = trim(isset($_POST['update_username']) ? $_POST['update_username'] : '');
        $updatePassword = isset($_POST['update_password']) ? $_POST['update_password'] : '';
        $confirmPassword = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';

        // Handle new user account creation
        $createNewUser = !empty($_POST['new_username']);
        if ($createNewUser) {
            $newUsername = trim($_POST['new_username']);
            $newEmail = trim($_POST['new_email']);
            $newPassword = $_POST['new_password'];
            $newPasswordConfirm = $_POST['new_password_confirm'];
            $newRole = isset($_POST['new_role']) ? $_POST['new_role'] : 'lawyer';

            // Validate new user data
            if (empty($newUsername) || empty($newPassword)) {
                $message = 'Username and password are required for new user account.';
                $messageType = 'danger';
            } elseif ($newPassword !== $newPasswordConfirm) {
                $message = 'Passwords do not match.';
                $messageType = 'danger';
            } elseif (strlen($newPassword) < 6) {
                $message = 'Password must be at least 6 characters long.';
                $messageType = 'danger';
            } else {
                // Check if username already exists
                $checkStmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
                $checkStmt->execute([$newUsername]);
                if ($checkStmt->fetch()) {
                    $message = 'Username already exists. Please choose a different username.';
                    $messageType = 'danger';
                } else {
                    // Create new user account
                    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                    $userStmt = $pdo->prepare("INSERT INTO users (username, password, email, role) VALUES (?, ?, ?, ?)");
                    $userStmt->execute([$newUsername, $hashedPassword, $newEmail ?: null, $newRole]);
                    $userId = $pdo->lastInsertId();
                    $createNewUser = false; // User created successfully
                }
            }
        }

        // Handle availability
        $availability = isset($_POST['availability']) ? $_POST['availability'] : [];

        if (empty($firstName) || empty($lastName) || empty($email)) {
            $message = 'First name, last name, and email are required.';
            $messageType = 'danger';
        } elseif (!$createNewUser && empty($userId) && !$lawyerId) {
            $message = 'Please select an existing user account or create a new one.';
            $messageType = 'danger';
        } elseif ($createNewUser && !empty($message)) {
            // Error message already set above
        } elseif (!empty($updatePassword) && $updatePassword !== $confirmPassword) {
            $message = 'Passwords do not match.';
            $messageType = 'danger';
        } elseif (!empty($updatePassword) && strlen($updatePassword) < 6) {
            $message = 'Password must be at least 6 characters long.';
            $messageType = 'danger';
        } else {
            try {
                if ($lawyerId) {
                    // Update existing lawyer
                    $stmt = $pdo->prepare("
                        UPDATE lawyers SET
                            first_name = ?, last_name = ?, email = ?,
                            phone = ?, license_number = ?, specialization = ?, experience_years = ?,
                            bio = ?, office_address = ?, is_active = ?, updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([$firstName, $lastName, $email, $phone, $licenseNumber, $specialization, $experienceYears, $bio, $officeAddress, $isActive, $lawyerId]);

                    // Update user account if username/password provided
                    if (!empty($updateUsername) || !empty($updatePassword)) {
                        // Get current user_id for this lawyer
                        $stmt = $pdo->prepare("SELECT user_id FROM lawyers WHERE id = ?");
                        $stmt->execute([$lawyerId]);
                        $lawyerData = $stmt->fetch();

                        if ($lawyerData && $lawyerData['user_id']) {
                            $updateFields = [];
                            $updateValues = [];

                            if (!empty($updateUsername)) {
                                // Check if username is already taken by another user
                                $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
                                $stmt->execute([$updateUsername, $lawyerData['user_id']]);
                                if ($stmt->fetch()) {
                                    $message = 'Username already exists. Please choose a different username.';
                                    $messageType = 'danger';
                                } else {
                                    $updateFields[] = "username = ?";
                                    $updateValues[] = $updateUsername;
                                }
                            }

                            if (!empty($updatePassword) && $messageType !== 'danger') {
                                $hashedPassword = password_hash($updatePassword, PASSWORD_DEFAULT);
                                $updateFields[] = "password = ?";
                                $updateValues[] = $hashedPassword;
                            }

                            if (!empty($updateFields) && $messageType !== 'danger') {
                                $updateValues[] = $lawyerData['user_id'];
                                $stmt = $pdo->prepare("UPDATE users SET " . implode(", ", $updateFields) . " WHERE id = ?");
                                $stmt->execute($updateValues);
                            }
                        }
                    }

                    // Update availability
                    $pdo->prepare("DELETE FROM lawyer_availability WHERE lawyer_id = ?")->execute([$lawyerId]);
                } else {
                    // Create new lawyer
                    $stmt = $pdo->prepare("
                        INSERT INTO lawyers (user_id, first_name, last_name, email, phone, license_number, specialization, experience_years, bio, office_address, is_active)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$userId, $firstName, $lastName, $email, $phone, $licenseNumber, $specialization, $experienceYears, $bio, $officeAddress, $isActive]);
                    $lawyerId = $pdo->lastInsertId();
                }

                // Insert availability
                $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
                foreach ($days as $day) {
                    if (isset($availability[$day])) {
                        $startTime = isset($availability[$day]['start']) ? $availability[$day]['start'] : '09:00';
                        $endTime = isset($availability[$day]['end']) ? $availability[$day]['end'] : '17:00';
                        $isAvailable = isset($availability[$day]['available']) ? 1 : 0;

                        $stmt = $pdo->prepare("INSERT INTO lawyer_availability (lawyer_id, day_of_week, start_time, end_time, is_available) VALUES (?, ?, ?, ?, ?)");
                        $stmt->execute([$lawyerId, $day, $startTime, $endTime, $isAvailable]);
                    }
                }

                $message = $lawyerId ? 'Lawyer updated successfully.' : 'Lawyer added successfully.';
                $messageType = 'success';

            } catch (PDOException $e) {
                $message = 'Error saving lawyer: ' . htmlspecialchars($e->getMessage());
                $messageType = 'danger';
            }
        }
    } elseif ($formType === 'delete_lawyer') {
        $lawyerId = isset($_POST['lawyer_id']) ? (int)$_POST['lawyer_id'] : 0;

        if ($lawyerId) {
            try {
                // Check if lawyer has active cases
                $caseCheck = $pdo->prepare("SELECT COUNT(*) FROM case_lawyers WHERE lawyer_id = ?");
                $caseCheck->execute([$lawyerId]);
                $caseCount = $caseCheck->fetchColumn();

                if ($caseCount > 0) {
                    $message = 'Cannot delete lawyer who is assigned to active cases. Please reassign cases first.';
                    $messageType = 'danger';
                } else {
                    $pdo->prepare("DELETE FROM lawyer_availability WHERE lawyer_id = ?")->execute([$lawyerId]);
                    $pdo->prepare("DELETE FROM lawyers WHERE id = ?")->execute([$lawyerId]);
                    $message = 'Lawyer deleted successfully.';
                    $messageType = 'success';
                }
            } catch (PDOException $e) {
                $message = 'Error deleting lawyer: ' . htmlspecialchars($e->getMessage());
                $messageType = 'danger';
            }
        }
    }
}

// Get edit lawyer data if editing
if (isset($_GET['edit']) && ctype_digit($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    try {
        $stmt = $pdo->prepare("SELECT l.*, u.username FROM lawyers l LEFT JOIN users u ON u.id = l.user_id WHERE l.id = ?");
        $stmt->execute([$editId]);
        $editLawyer = $stmt->fetch();

        if ($editLawyer) {
            // Get availability
            $stmt = $pdo->prepare("SELECT * FROM lawyer_availability WHERE lawyer_id = ?");
            $stmt->execute([$editId]);
            $editLawyer['availability'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        // Continue without edit data
    }
}

// Fetch lawyers
$lawyers = [];
try {
    $stmt = $pdo->query("
        SELECT l.*, u.username,
               COUNT(DISTINCT cl.case_id) as active_cases,
               GROUP_CONCAT(DISTINCT la.day_of_week ORDER BY FIELD(la.day_of_week, 'monday','tuesday','wednesday','thursday','friday','saturday','sunday')) as available_days
        FROM lawyers l
        LEFT JOIN users u ON u.id = l.user_id
        LEFT JOIN case_lawyers cl ON cl.lawyer_id = l.id
        LEFT JOIN lawyer_availability la ON la.lawyer_id = l.id AND la.is_available = 1
        GROUP BY l.id
        ORDER BY l.last_name, l.first_name
    ");
    $lawyers = $stmt->fetchAll();
} catch (PDOException $e) {
    $lawyers = [];
}

// Fetch available user accounts for lawyer assignment
$availableUsers = [];
try {
    // Show all users that are not assigned to other lawyers, or the current lawyer being edited
    $stmt = $pdo->query("
        SELECT u.*, CASE WHEN l.id IS NOT NULL THEN '(Assigned to Lawyer)' ELSE '' END as status
        FROM users u
        LEFT JOIN lawyers l ON l.user_id = u.id
        WHERE l.id IS NULL OR l.id = " . (isset($editLawyer['id']) ? (int)$editLawyer['id'] : 0) . "
        ORDER BY u.username
    ");
    $availableUsers = $stmt->fetchAll();
} catch (PDOException $e) {
    $availableUsers = [];
}

// Build HTML
$messageHtml = $message ? '<div class="alert alert-' . htmlspecialchars($messageType) . ' alert-dismissible fade show" role="alert">' . htmlspecialchars($message) . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>' : '';

$userOptions = '<option value="">Select user account</option>';
foreach ($availableUsers as $user) {
    $selected = (isset($editLawyer['user_id']) && (int)$editLawyer['user_id'] === (int)$user['id']) ? ' selected' : '';
    $userOptions .= '<option value="' . (int)$user['id'] . '"' . $selected . '>' . htmlspecialchars($user['username']) . ' (' . htmlspecialchars($user['email']) . ')</option>';
}

$lawyersTable = '';
if (empty($lawyers)) {
    $lawyersTable = '<tr><td colspan="6" class="text-center text-muted py-4">No lawyers added yet.</td></tr>';
} else {
    foreach ($lawyers as $lawyer) {
        $statusBadge = $lawyer['is_active'] ? '<span class="badge bg-gradient-success">Active</span>' : '<span class="badge bg-gradient-secondary">Inactive</span>';
        $activeCases = (int)$lawyer['active_cases'];

        $lawyersTable .= '
        <tr>
            <td>
                <div class="d-flex align-items-center">
                    <div class="icon icon-shape icon-sm bg-gradient-primary shadow text-center border-radius-md me-3">
                        <i class="ni ni-single-02 text-white text-xs opacity-10"></i>
                    </div>
                    <div>
                        <h6 class="mb-0 text-sm">' . htmlspecialchars($lawyer['first_name'] . ' ' . $lawyer['last_name']) . '</h6>
                        <p class="text-xs text-muted mb-0">' . htmlspecialchars($lawyer['email']) . '</p>
                    </div>
                </div>
            </td>
            <td>
                <p class="text-sm mb-0">' . htmlspecialchars($lawyer['specialization'] ?: 'Not specified') . '</p>
                <p class="text-xs text-muted mb-0">' . htmlspecialchars($lawyer['experience_years']) . ' years experience</p>
            </td>
            <td class="text-center">' . $statusBadge . '</td>
            <td class="text-center">
                <span class="text-sm font-weight-bold">' . $activeCases . '</span>
                <p class="text-xs text-muted mb-0">active cases</p>
            </td>
            <td class="text-center">
                <p class="text-sm mb-0">' . htmlspecialchars($lawyer['available_days'] ?: 'Not set') . '</p>
            </td>
            <td class="text-end">
                <div class="d-flex gap-1 justify-content-end">
                    <a href="lawyers.php?edit=' . (int)$lawyer['id'] . '" class="btn btn-sm btn-outline-dark">Edit</a>
                    <form method="post" class="d-inline" onsubmit="return confirm(\'Are you sure you want to delete ' . htmlspecialchars($lawyer['first_name'] . ' ' . $lawyer['last_name']) . '? This action cannot be undone.\');">
                        <input type="hidden" name="form_type" value="delete_lawyer">
                        <input type="hidden" name="lawyer_id" value="' . (int)$lawyer['id'] . '">
                        <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                    </form>
                </div>
            </td>
        </tr>';
    }
}

// Form data for editing
$formData = [
    'lawyer_id' => isset($editLawyer['id']) ? $editLawyer['id'] : '',
    'user_id' => isset($editLawyer['user_id']) ? $editLawyer['user_id'] : '',
    'first_name' => isset($editLawyer['first_name']) ? $editLawyer['first_name'] : '',
    'last_name' => isset($editLawyer['last_name']) ? $editLawyer['last_name'] : '',
    'email' => isset($editLawyer['email']) ? $editLawyer['email'] : '',
    'phone' => isset($editLawyer['phone']) ? $editLawyer['phone'] : '',
    'license_number' => isset($editLawyer['license_number']) ? $editLawyer['license_number'] : '',
    'specialization' => isset($editLawyer['specialization']) ? $editLawyer['specialization'] : '',
    'experience_years' => isset($editLawyer['experience_years']) ? $editLawyer['experience_years'] : '',
    'bio' => isset($editLawyer['bio']) ? $editLawyer['bio'] : '',
    'office_address' => isset($editLawyer['office_address']) ? $editLawyer['office_address'] : '',
    'is_active' => isset($editLawyer['is_active']) ? $editLawyer['is_active'] : 1,
    'username' => isset($editLawyer['username']) ? $editLawyer['username'] : '',
    'availability' => isset($editLawyer['availability']) ? $editLawyer['availability'] : []
];

$isEditing = !empty($formData['lawyer_id']);
$formTitle = $isEditing ? 'Edit Lawyer' : 'Add New Lawyer';
$submitLabel = $isEditing ? 'Update Lawyer' : 'Add Lawyer';

$html = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="apple-touch-icon" sizes="76x76" href="../assets/img/apple-icon.png">
    <link rel="icon" type="image/png" href="../assets/img/favicon.png">
    <title>LexMate Case Manager - Lawyers</title>
    <link href="https://fonts.googleapis.com/css?family=Open+Sans:300,400,600,700" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-icons.css" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-svg.css" rel="stylesheet" />
    <script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
    <link id="pagestyle" href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
    <style>
        .availability-row { margin-bottom: 10px; }
        .day-checkbox { margin-right: 10px; }
        .time-inputs { display: inline-block; margin-left: 20px; }
    </style>
</head>
<body class="g-sidenav-show bg-gray-100">
    <div class="min-height-300 bg-dark position-absolute w-100"></div>
    <aside class="sidenav bg-white navbar navbar-vertical navbar-expand-xs border-0 border-radius-xl my-3 fixed-start ms-4 " id="sidenav-main">
    </aside>
    <main class="main-content position-relative border-radius-lg ">
        <nav class="navbar navbar-main navbar-expand-lg px-0 mx-4 shadow-none border-radius-xl " id="navbarBlur" data-scroll="false">
            <div class="container-fluid py-1 px-3">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb bg-transparent mb-0 pb-0 pt-1 px-0 me-sm-6 me-5">
                        <li class="breadcrumb-item text-sm"><a class="opacity-5 text-white" href="javascript:;">Pages</a></li>
                        <li class="breadcrumb-item text-sm text-white active" aria-current="page">Lawyers</li>
                    </ol>
                    <h6 class="font-weight-bolder text-white mb-0">Lawyer Management</h6>
                </nav>
            </div>
        </nav>
        <div class="container-fluid py-4">
            {MESSAGE}

            <!-- Page Header -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-body p-3">
                            <div class="row align-items-center">
                                <div class="col-lg-8">
                                    <h5 class="mb-0">Lawyer Management</h5>
                                    <p class="text-sm text-muted mb-0">Manage lawyers, their information, and availability</p>
                                </div>
                                <div class="col-lg-4 text-end">
                                    <button class="btn btn-dark btn-sm mb-0" onclick="showLawyerForm()">
                                        <i class="ni ni-fat-add me-1"></i> Add Lawyer
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <!-- Lawyers Table -->
                <div class="col-12">
                    <div class="card mb-4">
                        <div class="card-header pb-0 pt-3">
                            <div class="d-flex align-items-center">
                                <div class="icon icon-shape icon-md bg-gradient-primary shadow text-center border-radius-md me-3">
                                    <i class="ni ni-single-02 text-white text-lg opacity-10"></i>
                                </div>
                                <div>
                                    <h6 class="mb-0">All Lawyers</h6>
                                    <p class="text-xs text-muted mb-0">Manage lawyer accounts and information</p>
                                </div>
                            </div>
                        </div>
                        <div class="card-body px-0 pt-0 pb-2">
                            <div class="table-responsive">
                                <table class="table align-items-center mb-0">
                                    <thead>
                                        <tr>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Lawyer</th>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Specialization</th>
                                            <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Status</th>
                                            <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Cases</th>
                                            <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Availability</th>
                                            <th class="text-secondary opacity-7"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {LAWYERS_TABLE}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <footer class="footer pt-3">
                <div class="container-fluid">
                    <div class="row align-items-center justify-content-lg-between">
                        <div class="col-lg-6 mb-lg-0 mb-4">
                            <div class="copyright text-center text-sm text-muted text-lg-start">
                                © <script>document.write(new Date().getFullYear())</script>, LexMate Case Manager.
                            </div>
                        </div>
                    </div>
                </div>
            </footer>
        </div>
    </main>

    <!-- Lawyer Form Modal -->
    <div class="modal fade" id="lawyerModal" tabindex="-1" aria-labelledby="lawyerModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="lawyerModalLabel">{FORM_TITLE}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post">
                    <div class="modal-body">
                        <input type="hidden" name="form_type" value="save_lawyer">
                        <input type="hidden" name="lawyer_id" value="{LAWYER_ID}">

                        <!-- User Account Section -->
                        <div id="user_account_section">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">User Account <span class="text-danger">*</span></label>
                                    <select class="form-control" name="user_id" id="user_select" required>
                                        <option value="">Select existing user account</option>
                                        {USER_OPTIONS}
                                    </select>
                                    <small class="text-muted mt-1">
                                        <strong>Important:</strong> Each lawyer needs their own user account.
                                        <a href="#" onclick="showCreateUserForm(); return false;">Create new user account</a> for this lawyer.
                                    </small>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Status</label>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="is_active" value="1" {IS_ACTIVE_CHECKED}>
                                        <label class="form-check-label">Active</label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- User Account Update Section (for existing lawyers) -->
                        <div id="user_update_section" style="display: none;">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Username</label>
                                    <input type="text" class="form-control" name="update_username" id="update_username" value="{CURRENT_USERNAME}">
                                    <small class="text-muted">Current: {CURRENT_USERNAME_DISPLAY}</small>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">New Password</label>
                                    <input type="password" class="form-control" name="update_password" id="update_password">
                                    <small class="text-muted">Leave empty to keep current password</small>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Confirm New Password</label>
                                    <input type="password" class="form-control" name="confirm_password" id="confirm_password">
                                </div>
                            </div>
                            <div class="alert alert-warning">
                                <strong>Important:</strong> Updating username or password will invalidate the lawyer's current login credentials. They will need to use the new credentials to login.
                            </div>
                        </div>

                        <!-- New User Account Creation Form (hidden by default) -->
                        <div id="create_user_form" style="display: none;" class="border rounded p-3 mb-3 bg-light">
                            <h6 class="mb-3">Create New User Account</h6>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Username <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="new_username" id="new_username">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Email</label>
                                    <input type="email" class="form-control" name="new_email" id="new_email">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Password <span class="text-danger">*</span></label>
                                    <input type="password" class="form-control" name="new_password" id="new_password">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Confirm Password <span class="text-danger">*</span></label>
                                    <input type="password" class="form-control" name="new_password_confirm" id="new_password_confirm">
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Role</label>
                                <select class="form-control" name="new_role" id="new_role">
                                    <option value="lawyer">Lawyer</option>
                                    <option value="admin">Admin</option>
                                    <option value="staff">Staff</option>
                                </select>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="hideCreateUserForm()">Cancel</button>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">First Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="first_name" value="{FIRST_NAME}" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Last Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="last_name" value="{LAST_NAME}" required>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Email <span class="text-danger">*</span></label>
                                <input type="email" class="form-control" name="email" value="{EMAIL}" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Phone</label>
                                <input type="tel" class="form-control" name="phone" value="{PHONE}">
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">License Number</label>
                                <input type="text" class="form-control" name="license_number" value="{LICENSE_NUMBER}">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Experience (Years)</label>
                                <input type="number" class="form-control" name="experience_years" value="{EXPERIENCE_YEARS}" min="0">
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Specialization</label>
                                <input type="text" class="form-control" name="specialization" value="{SPECIALIZATION}" placeholder="e.g., Criminal Law, Corporate Law">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Office Address</label>
                            <textarea class="form-control" name="office_address" rows="2">{OFFICE_ADDRESS}</textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Bio</label>
                            <textarea class="form-control" name="bio" rows="3" placeholder="Brief professional biography...">{BIO}</textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Availability Schedule</label>
                            <div class="border rounded p-3">
                                <div class="availability-row">
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input day-checkbox" type="checkbox" name="availability[monday][available]" value="1" {MONDAY_CHECKED}>
                                        <label class="form-check-label">Monday</label>
                                    </div>
                                    <div class="time-inputs">
                                        <input type="time" class="form-control form-control-sm d-inline-block w-auto" name="availability[monday][start]" value="{MONDAY_START}" style="width: 120px;">
                                        <span class="mx-2">to</span>
                                        <input type="time" class="form-control form-control-sm d-inline-block w-auto" name="availability[monday][end]" value="{MONDAY_END}" style="width: 120px;">
                                    </div>
                                </div>
                                <div class="availability-row">
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input day-checkbox" type="checkbox" name="availability[tuesday][available]" value="1" {TUESDAY_CHECKED}>
                                        <label class="form-check-label">Tuesday</label>
                                    </div>
                                    <div class="time-inputs">
                                        <input type="time" class="form-control form-control-sm d-inline-block w-auto" name="availability[tuesday][start]" value="{TUESDAY_START}" style="width: 120px;">
                                        <span class="mx-2">to</span>
                                        <input type="time" class="form-control form-control-sm d-inline-block w-auto" name="availability[tuesday][end]" value="{TUESDAY_END}" style="width: 120px;">
                                    </div>
                                </div>
                                <div class="availability-row">
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input day-checkbox" type="checkbox" name="availability[wednesday][available]" value="1" {WEDNESDAY_CHECKED}>
                                        <label class="form-check-label">Wednesday</label>
                                    </div>
                                    <div class="time-inputs">
                                        <input type="time" class="form-control form-control-sm d-inline-block w-auto" name="availability[wednesday][start]" value="{WEDNESDAY_START}" style="width: 120px;">
                                        <span class="mx-2">to</span>
                                        <input type="time" class="form-control form-control-sm d-inline-block w-auto" name="availability[wednesday][end]" value="{WEDNESDAY_END}" style="width: 120px;">
                                    </div>
                                </div>
                                <div class="availability-row">
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input day-checkbox" type="checkbox" name="availability[thursday][available]" value="1" {THURSDAY_CHECKED}>
                                        <label class="form-check-label">Thursday</label>
                                    </div>
                                    <div class="time-inputs">
                                        <input type="time" class="form-control form-control-sm d-inline-block w-auto" name="availability[thursday][start]" value="{THURSDAY_START}" style="width: 120px;">
                                        <span class="mx-2">to</span>
                                        <input type="time" class="form-control form-control-sm d-inline-block w-auto" name="availability[thursday][end]" value="{THURSDAY_END}" style="width: 120px;">
                                    </div>
                                </div>
                                <div class="availability-row">
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input day-checkbox" type="checkbox" name="availability[friday][available]" value="1" {FRIDAY_CHECKED}>
                                        <label class="form-check-label">Friday</label>
                                    </div>
                                    <div class="time-inputs">
                                        <input type="time" class="form-control form-control-sm d-inline-block w-auto" name="availability[friday][start]" value="{FRIDAY_START}" style="width: 120px;">
                                        <span class="mx-2">to</span>
                                        <input type="time" class="form-control form-control-sm d-inline-block w-auto" name="availability[friday][end]" value="{FRIDAY_END}" style="width: 120px;">
                                    </div>
                                </div>
                                <div class="availability-row">
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input day-checkbox" type="checkbox" name="availability[saturday][available]" value="1" {SATURDAY_CHECKED}>
                                        <label class="form-check-label">Saturday</label>
                                    </div>
                                    <div class="time-inputs">
                                        <input type="time" class="form-control form-control-sm d-inline-block w-auto" name="availability[saturday][start]" value="{SATURDAY_START}" style="width: 120px;">
                                        <span class="mx-2">to</span>
                                        <input type="time" class="form-control form-control-sm d-inline-block w-auto" name="availability[saturday][end]" value="{SATURDAY_END}" style="width: 120px;">
                                    </div>
                                </div>
                                <div class="availability-row">
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input day-checkbox" type="checkbox" name="availability[sunday][available]" value="1" {SUNDAY_CHECKED}>
                                        <label class="form-check-label">Sunday</label>
                                    </div>
                                    <div class="time-inputs">
                                        <input type="time" class="form-control form-control-sm d-inline-block w-auto" name="availability[sunday][start]" value="{SUNDAY_START}" style="width: 120px;">
                                        <span class="mx-2">to</span>
                                        <input type="time" class="form-control form-control-sm d-inline-block w-auto" name="availability[sunday][end]" value="{SUNDAY_END}" style="width: 120px;">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">{SUBMIT_LABEL}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="../assets/js/core/popper.min.js"></script>
    <script src="../assets/js/core/bootstrap.min.js"></script>
    <script src="../assets/js/plugins/perfect-scrollbar.min.js"></script>
    <script src="../assets/js/plugins/smooth-scrollbar.min.js"></script>
    <script src="../assets/js/argon-dashboard.min.js?v=2.1.0"></script>
    <script>
        function showLawyerForm() {
            document.getElementById('lawyerModalLabel').textContent = 'Add New Lawyer';
            document.querySelector('#lawyerModal input[name="lawyer_id"]').value = '';
            document.getElementById('lawyerModal').querySelector('form').reset();
            // Reset form display for new lawyer
            document.getElementById('user_account_section').style.display = 'block';
            document.getElementById('user_update_section').style.display = 'none';
            document.getElementById('user_select').required = true;
            new bootstrap.Modal(document.getElementById('lawyerModal')).show();
        }

        // Show modal if editing
        {SHOW_EDIT_MODAL}

        // Show create user form if needed
        {SHOW_CREATE_USER_FORM}

        // Handle availability checkboxes
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.day-checkbox').forEach(function(checkbox) {
                checkbox.addEventListener('change', function() {
                    var timeInputs = this.parentElement.nextElementSibling;
                    if (this.checked) {
                        timeInputs.style.display = 'inline-block';
                    } else {
                        timeInputs.style.display = 'none';
                    }
                });

                // Set initial state
                var timeInputs = checkbox.parentElement.nextElementSibling;
                if (checkbox.checked) {
                    timeInputs.style.display = 'inline-block';
                } else {
                    timeInputs.style.display = 'none';
                }
            });
        });

        function showCreateUserForm() {
            document.getElementById('create_user_form').style.display = 'block';
            document.getElementById('user_select').value = '';
            document.getElementById('user_select').required = false;
        }

        function hideCreateUserForm() {
            document.getElementById('create_user_form').style.display = 'none';
            document.getElementById('user_select').required = true;
            // Clear the form fields
            document.getElementById('new_username').value = '';
            document.getElementById('new_email').value = '';
            document.getElementById('new_password').value = '';
            document.getElementById('new_password_confirm').value = '';
            document.getElementById('new_role').value = 'lawyer';
        }

        function showEditSections() {
            // For editing existing lawyers, show the update section and hide the user selection
            const lawyerId = document.querySelector('#lawyerModal input[name="lawyer_id"]').value;
            if (lawyerId) {
                document.getElementById('user_account_section').style.display = 'none';
                document.getElementById('user_update_section').style.display = 'block';
                document.getElementById('user_select').required = false;
            }
        }
    </script>
</body>
</html>
HTML;

// Prepare availability data for form
$availability = isset($formData['availability']) ? $formData['availability'] : [];
$availabilityData = [];
foreach ($availability as $day) {
    $availabilityData[$day['day_of_week']] = $day;
}

// Handle form display for errors
$showCreateUserForm = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_type']) && $_POST['form_type'] === 'save_lawyer' && !empty($_POST['new_username'])) {
    $showCreateUserForm = true;
}

$replacements = [
    '{MESSAGE}' => $messageHtml,
    '{LAWYERS_TABLE}' => $lawyersTable,
    '{USER_OPTIONS}' => $userOptions,
    '{FORM_TITLE}' => htmlspecialchars($formTitle),
    '{SUBMIT_LABEL}' => htmlspecialchars($submitLabel),
    '{LAWYER_ID}' => htmlspecialchars($formData['lawyer_id']),
    '{FIRST_NAME}' => htmlspecialchars($formData['first_name']),
    '{LAST_NAME}' => htmlspecialchars($formData['last_name']),
    '{EMAIL}' => htmlspecialchars($formData['email']),
    '{PHONE}' => htmlspecialchars($formData['phone']),
    '{LICENSE_NUMBER}' => htmlspecialchars($formData['license_number']),
    '{SPECIALIZATION}' => htmlspecialchars($formData['specialization']),
    '{EXPERIENCE_YEARS}' => htmlspecialchars($formData['experience_years']),
    '{BIO}' => htmlspecialchars($formData['bio']),
    '{OFFICE_ADDRESS}' => htmlspecialchars($formData['office_address']),
    '{IS_ACTIVE_CHECKED}' => $formData['is_active'] ? 'checked' : '',
    '{CURRENT_USERNAME}' => htmlspecialchars($formData['username']),
    '{CURRENT_USERNAME_DISPLAY}' => htmlspecialchars($formData['username']) ?: 'Not set',
    '{MONDAY_CHECKED}' => isset($availabilityData['monday']) ? 'checked' : '',
    '{MONDAY_START}' => isset($availabilityData['monday']['start_time']) ? $availabilityData['monday']['start_time'] : '09:00',
    '{MONDAY_END}' => isset($availabilityData['monday']['end_time']) ? $availabilityData['monday']['end_time'] : '17:00',
    '{TUESDAY_CHECKED}' => isset($availabilityData['tuesday']) ? 'checked' : '',
    '{TUESDAY_START}' => isset($availabilityData['tuesday']['start_time']) ? $availabilityData['tuesday']['start_time'] : '09:00',
    '{TUESDAY_END}' => isset($availabilityData['tuesday']['end_time']) ? $availabilityData['tuesday']['end_time'] : '17:00',
    '{WEDNESDAY_CHECKED}' => isset($availabilityData['wednesday']) ? 'checked' : '',
    '{WEDNESDAY_START}' => isset($availabilityData['wednesday']['start_time']) ? $availabilityData['wednesday']['start_time'] : '09:00',
    '{WEDNESDAY_END}' => isset($availabilityData['wednesday']['end_time']) ? $availabilityData['wednesday']['end_time'] : '17:00',
    '{THURSDAY_CHECKED}' => isset($availabilityData['thursday']) ? 'checked' : '',
    '{THURSDAY_START}' => isset($availabilityData['thursday']['start_time']) ? $availabilityData['thursday']['start_time'] : '09:00',
    '{THURSDAY_END}' => isset($availabilityData['thursday']['end_time']) ? $availabilityData['thursday']['end_time'] : '17:00',
    '{FRIDAY_CHECKED}' => isset($availabilityData['friday']) ? 'checked' : '',
    '{FRIDAY_START}' => isset($availabilityData['friday']['start_time']) ? $availabilityData['friday']['start_time'] : '09:00',
    '{FRIDAY_END}' => isset($availabilityData['friday']['end_time']) ? $availabilityData['friday']['end_time'] : '17:00',
    '{SATURDAY_CHECKED}' => isset($availabilityData['saturday']) ? 'checked' : '',
    '{SATURDAY_START}' => isset($availabilityData['saturday']['start_time']) ? $availabilityData['saturday']['start_time'] : '09:00',
    '{SATURDAY_END}' => isset($availabilityData['saturday']['end_time']) ? $availabilityData['saturday']['end_time'] : '17:00',
    '{SUNDAY_CHECKED}' => isset($availabilityData['sunday']) ? 'checked' : '',
    '{SUNDAY_START}' => isset($availabilityData['sunday']['start_time']) ? $availabilityData['sunday']['start_time'] : '09:00',
    '{SUNDAY_END}' => isset($availabilityData['sunday']['end_time']) ? $availabilityData['sunday']['end_time'] : '17:00',
    '{SHOW_EDIT_MODAL}' => $isEditing ? 'setTimeout(function() { new bootstrap.Modal(document.getElementById("lawyerModal")).show(); showEditSections(); }, 100);' : '',
    '{SHOW_CREATE_USER_FORM}' => $showCreateUserForm ? 'setTimeout(function() { showCreateUserForm(); }, 100);' : '',
];

$html = str_replace(array_keys($replacements), array_values($replacements), $html);

// rewrite internal links from .html to .php
$html = preg_replace('/href="([^"\']+)\.html"/i', 'href="$1.php"', $html);
ob_start();
include __DIR__ . '/../inc/menunav.php';
$sidebar = ob_get_clean();
$html = preg_replace('/<aside[\s\S]*?<\/aside>/', $sidebar, $html, 1);

ob_start();
include __DIR__ . '/../inc/footer.php';
$footer = ob_get_clean();
$html = preg_replace('/<\/body>\s*<\/html>$/i', $footer . "\n</body>\n</html>", $html);

echo $html;
?>
