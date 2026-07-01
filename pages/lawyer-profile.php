<?php
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../lib/lawyer-portal-i18n.php';
require_once __DIR__ . '/../inc/password-validation.php';

if (!isset($_SESSION['lawyer_id'])) {
    header('Location: lawyer-login.php');
    exit;
}

$lawyerId = (int) $_SESSION['lawyer_id'];
$lawyerName = (string) ($_SESSION['lawyer_name'] ?? lawyer_tf('header.lawyer', 'Lawyer'));
$message = '';
$messageType = '';

$profile = [
    'first_name' => '',
    'last_name' => '',
    'email' => '',
    'phone' => '',
    'license_number' => '',
    'specialization' => '',
    'experience_years' => '',
    'bio' => '',
    'office_address' => '',
    'username' => '',
];

function loadLawyerProfile(PDO $pdo, int $lawyerId): array
{
    $stmt = $pdo->prepare("
        SELECT
            l.id,
            l.user_id,
            l.first_name,
            l.last_name,
            l.email,
            l.phone,
            l.license_number,
            l.specialization,
            l.experience_years,
            l.bio,
            l.office_address,
            u.username,
            u.password AS user_password_hash
        FROM lawyers l
        LEFT JOIN users u ON u.id = l.user_id
        WHERE l.id = ?
        LIMIT 1
    ");
    $stmt->execute([$lawyerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: [];
}

$passwordErrorHtml = '';
$confirmErrorHtml = '';
$passwordInvalidClass = '';
$confirmInvalidClass = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    try {
        $current = loadLawyerProfile($pdo, $lawyerId);
        if (!$current) {
            throw new RuntimeException(lawyer_tf('profile.error_not_found', 'Lawyer profile not found.'));
        }

        if ($action === 'update_profile') {
            $firstName = trim((string) ($_POST['first_name'] ?? ''));
            $lastName = trim((string) ($_POST['last_name'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $phone = trim((string) ($_POST['phone'] ?? ''));
            $licenseNumber = trim((string) ($_POST['license_number'] ?? ''));
            $specialization = trim((string) ($_POST['specialization'] ?? ''));
            $experienceYears = (int) ($_POST['experience_years'] ?? 0);
            $bio = trim((string) ($_POST['bio'] ?? ''));
            $officeAddress = trim((string) ($_POST['office_address'] ?? ''));

            if ($firstName === '' || $lastName === '') {
                $message = lawyer_tf('profile.error_name_required', 'First name and last name are required.');
                $messageType = 'danger';
            } elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $message = lawyer_tf('profile.error_invalid_email', 'Please enter a valid email address.');
                $messageType = 'danger';
            } elseif ($experienceYears < 0 || $experienceYears > 80) {
                $message = lawyer_tf('profile.error_experience_range', 'Experience years must be between 0 and 80.');
                $messageType = 'danger';
            } else {
                $stmt = $pdo->prepare("
                    UPDATE lawyers
                    SET first_name = ?, last_name = ?, email = ?, phone = ?,
                        license_number = ?, specialization = ?, experience_years = ?,
                        bio = ?, office_address = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $firstName,
                    $lastName,
                    $email,
                    $phone !== '' ? $phone : null,
                    $licenseNumber !== '' ? $licenseNumber : null,
                    $specialization !== '' ? $specialization : null,
                    $experienceYears,
                    $bio !== '' ? $bio : null,
                    $officeAddress !== '' ? $officeAddress : null,
                    $lawyerId,
                ]);

                if (!empty($current['user_id'])) {
                    $stmt = $pdo->prepare('UPDATE users SET email = ? WHERE id = ?');
                    $stmt->execute([$email, $current['user_id']]);
                }

                $_SESSION['lawyer_name'] = trim($firstName . ' ' . $lastName);
                $lawyerName = $_SESSION['lawyer_name'];
                $message = lawyer_tf('profile.success_updated', 'Profile updated successfully.');
                $messageType = 'success';
            }
        } elseif ($action === 'change_password') {
            if (empty($current['user_id'])) {
                $message = lawyer_tf('profile.error_no_portal_account', 'No linked portal account found for this lawyer.');
                $messageType = 'danger';
            } else {
                $currentPassword = (string) ($_POST['current_password'] ?? '');
                $newPassword = (string) ($_POST['new_password'] ?? '');
                $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

                if (!password_verify($currentPassword, (string) ($current['user_password_hash'] ?? ''))) {
                    $message = lawyer_tf('profile.error_wrong_password', 'Current password is incorrect.');
                    $messageType = 'danger';
                } else {
                    $passwordCheck = legalpro_validate_password_pair($newPassword, $confirmPassword);
                    if (!$passwordCheck['valid']) {
                        $message = legalpro_password_form_message($passwordCheck);
                        $messageType = 'danger';
                        $passwordErrorHtml = legalpro_password_field_error_html($passwordCheck['password_errors']);
                        $confirmErrorHtml = legalpro_password_field_error_html($passwordCheck['confirm_error']);
                        $passwordInvalidClass = legalpro_password_input_invalid_class($passwordCheck['password_errors']);
                        $confirmInvalidClass = legalpro_password_input_invalid_class($passwordCheck['confirm_error']);
                    } else {
                        $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
                        $stmt->execute([$newPasswordHash, $current['user_id']]);
                        $message = lawyer_tf('profile.success_password_updated', 'Password updated successfully.');
                        $messageType = 'success';
                    }
                }
            }
        }
    } catch (Throwable $e) {
        $message = lawyer_t('profile.error_update_prefix', ['message' => $e->getMessage()]);
        if ($message === 'profile.error_update_prefix') {
            $message = str_replace(':message', $e->getMessage(), lawyer_tf('profile.error_update_prefix', 'Error updating profile: :message'));
        }
        $messageType = 'danger';
    }
}

try {
    $row = loadLawyerProfile($pdo, $lawyerId);
    if (!$row) {
        throw new RuntimeException(lawyer_tf('profile.error_not_found', 'Lawyer profile not found.'));
    }
    $profile['first_name'] = (string) ($row['first_name'] ?? '');
    $profile['last_name'] = (string) ($row['last_name'] ?? '');
    $profile['email'] = (string) ($row['email'] ?? '');
    $profile['phone'] = (string) ($row['phone'] ?? '');
    $profile['license_number'] = (string) ($row['license_number'] ?? '');
    $profile['specialization'] = (string) ($row['specialization'] ?? '');
    $profile['experience_years'] = (string) ($row['experience_years'] ?? '0');
    $profile['bio'] = (string) ($row['bio'] ?? '');
    $profile['office_address'] = (string) ($row['office_address'] ?? '');
    $profile['username'] = (string) ($row['username'] ?? '');
} catch (Throwable $e) {
    $message = lawyer_t('profile.error_load_prefix', ['message' => $e->getMessage()]);
    if ($message === 'profile.error_load_prefix') {
        $message = str_replace(':message', $e->getMessage(), lawyer_tf('profile.error_load_prefix', 'Error loading profile: :message'));
    }
    $messageType = 'danger';
}

$closeLabel = lawyer_tf('common.close', 'Close');
$messageHtml = $message !== ''
    ? '<div class="alert alert-' . htmlspecialchars($messageType ?: 'info') . ' alert-dismissible fade show" role="alert">'
        . htmlspecialchars($message)
        . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="' . htmlspecialchars($closeLabel) . '"></button>'
    . '</div>'
    : '';

$usernameDisplay = htmlspecialchars($profile['username'] !== '' ? $profile['username'] : lawyer_tf('common.not_applicable', 'N/A'));
$usernamePrefix = lawyer_tf('profile.username_prefix', 'Username:');

$pageTitle = lawyer_tf('profile.page_title', 'My Profile');
$breadcrumbNavbar = legalpro_render_lawyer_breadcrumb_navbar($pageTitle);

$html = <<<'HTML'
<!DOCTYPE html>
<html lang="{HTML_LANG}">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="apple-touch-icon" sizes="76x76" href="../assets/img/apple-icon.png">
    <link rel="icon" type="image/png" href="../assets/img/favicon.png">
    <title>LegalPro - {PAGE_TITLE}</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-icons.css" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-svg.css" rel="stylesheet" />
    <script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
    <link id="pagestyle" href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
    <link href="../assets/css/app-font-montserrat.css?v=3" rel="stylesheet" />
    <?php include __DIR__ . '/../inc/lawyer-portal-head.php'; ?>
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-lawyer-portal lawyer-profile-page{PORTAL_THEME_BODY_CLASS}">
    <div class="min-height-300 bg-legalpro-lawyer position-absolute w-100"></div>
    <?php include __DIR__ . '/../inc/lawyer-menunav.php'; ?>

    <main class="main-content position-relative border-radius-lg">
        {BREADCRUMB_NAVBAR}

        <div class="container-fluid py-4">
            <p class="dashboard-welcome-sub text-sm text-muted mb-3">{PAGE_SUBTITLE}</p>
            {MESSAGE}
            <div class="row">
                <div class="col-lg-7 mb-4">
                    <div class="card">
                        <div class="card-header pb-0">
                            <h6 class="mb-0">{PROFESSIONAL_INFO}</h6>
                        </div>
                        <div class="card-body">
                            <form method="post">
                                <input type="hidden" name="action" value="update_profile">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label class="form-control-label">{FIRST_NAME_LABEL}</label>
                                            <input class="form-control" type="text" name="first_name" value="{FIRST_NAME}" required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label class="form-control-label">{LAST_NAME_LABEL}</label>
                                            <input class="form-control" type="text" name="last_name" value="{LAST_NAME}" required>
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label class="form-control-label">{EMAIL_LABEL}</label>
                                            <input class="form-control" type="email" name="email" value="{EMAIL}" required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label class="form-control-label">{PHONE_LABEL}</label>
                                            <input class="form-control" type="text" name="phone" value="{PHONE}">
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label class="form-control-label">{LICENSE_LABEL}</label>
                                            <input class="form-control" type="text" name="license_number" value="{LICENSE_NUMBER}">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label class="form-control-label">{SPECIALIZATION_LABEL}</label>
                                            <input class="form-control" type="text" name="specialization" value="{SPECIALIZATION}">
                                        </div>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="form-control-label">{EXPERIENCE_LABEL}</label>
                                    <input class="form-control" type="number" name="experience_years" value="{EXPERIENCE_YEARS}" min="0" max="80">
                                </div>
                                <div class="form-group">
                                    <label class="form-control-label">{OFFICE_ADDRESS_LABEL}</label>
                                    <textarea class="form-control" rows="2" name="office_address">{OFFICE_ADDRESS}</textarea>
                                </div>
                                <div class="form-group">
                                    <label class="form-control-label">{BIO_LABEL}</label>
                                    <textarea class="form-control" rows="3" name="bio">{BIO}</textarea>
                                </div>
                                <button class="btn bg-gradient-primary mb-0">{SAVE_PROFILE}</button>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="col-lg-5 mb-4">
                    <div class="card">
                        <div class="card-header pb-0">
                            <h6 class="mb-0">{SECURITY_TITLE}</h6>
                        </div>
                        <div class="card-body">
                            <p class="text-sm text-muted mb-2">{USERNAME_PREFIX} <strong>{USERNAME}</strong></p>
                            <hr class="horizontal dark mt-0">
                            <form method="post">
                                <input type="hidden" name="action" value="change_password">
                                <div class="form-group">
                                    <label class="form-control-label">{CURRENT_PASSWORD_LABEL}</label>
                                    <input class="form-control" type="password" name="current_password" required autocomplete="current-password">
                                </div>
                                <div class="form-group">
                                    <label class="form-control-label">{NEW_PASSWORD_LABEL}</label>
                                    <input class="form-control{NEW_PASSWORD_INVALID_CLASS}" type="password" name="new_password" required autocomplete="new-password" minlength="8" maxlength="128">
                                    {NEW_PASSWORD_ERROR}
                                </div>
                                <div class="form-group">
                                    <label class="form-control-label">{CONFIRM_PASSWORD_LABEL}</label>
                                    <input class="form-control{CONFIRM_PASSWORD_INVALID_CLASS}" type="password" name="confirm_password" required autocomplete="new-password" minlength="8" maxlength="128">
                                    {CONFIRM_PASSWORD_ERROR}
                                </div>
                                <div class="text-xs text-muted mb-3">{PASSWORD_RULES}</div>
                                <button class="btn btn-dark mb-0">{CHANGE_PASSWORD}</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script src="../assets/js/core/popper.min.js"></script>
    <script src="../assets/js/core/bootstrap.min.js"></script>
    <script src="../assets/js/plugins/perfect-scrollbar.min.js"></script>
    <script src="../assets/js/plugins/smooth-scrollbar.min.js"></script>
    <script src="../assets/js/legalpro-sidenav-bootstrap.js?v=1"></script>
<script src="../assets/js/argon-dashboard.min.js?v=2.1.0"></script>
</body>
</html>
HTML;

$replacements = [
    '{HTML_LANG}' => lawyer_portal_html_lang(),
    '{PAGE_TITLE}' => htmlspecialchars($pageTitle),
    '{PAGE_SUBTITLE}' => htmlspecialchars(lawyer_tf('profile.subtitle', 'Manage your professional details')),
    '{BREADCRUMB_NAVBAR}' => $breadcrumbNavbar,
    '{MESSAGE}' => $messageHtml,
    '{PROFESSIONAL_INFO}' => htmlspecialchars(lawyer_tf('profile.professional_info', 'Professional information')),
    '{FIRST_NAME_LABEL}' => htmlspecialchars(lawyer_tf('profile.first_name', 'First name')),
    '{LAST_NAME_LABEL}' => htmlspecialchars(lawyer_tf('profile.last_name', 'Last name')),
    '{EMAIL_LABEL}' => htmlspecialchars(lawyer_tf('profile.email', 'Email')),
    '{PHONE_LABEL}' => htmlspecialchars(lawyer_tf('profile.phone', 'Phone')),
    '{LICENSE_LABEL}' => htmlspecialchars(lawyer_tf('profile.license_number', 'License number')),
    '{SPECIALIZATION_LABEL}' => htmlspecialchars(lawyer_tf('profile.specialization', 'Specialization')),
    '{EXPERIENCE_LABEL}' => htmlspecialchars(lawyer_tf('profile.experience_years', 'Years of experience')),
    '{OFFICE_ADDRESS_LABEL}' => htmlspecialchars(lawyer_tf('profile.office_address', 'Office address')),
    '{BIO_LABEL}' => htmlspecialchars(lawyer_tf('profile.bio', 'Bio')),
    '{SAVE_PROFILE}' => htmlspecialchars(lawyer_tf('profile.save_profile', 'Save profile')),
    '{SECURITY_TITLE}' => htmlspecialchars(lawyer_tf('profile.security', 'Security')),
    '{USERNAME_PREFIX}' => htmlspecialchars($usernamePrefix),
    '{USERNAME}' => $usernameDisplay,
    '{CURRENT_PASSWORD_LABEL}' => htmlspecialchars(lawyer_tf('profile.current_password', 'Current password')),
    '{NEW_PASSWORD_LABEL}' => htmlspecialchars(lawyer_tf('profile.new_password', 'New password')),
    '{CONFIRM_PASSWORD_LABEL}' => htmlspecialchars(lawyer_tf('profile.confirm_password', 'Confirm new password')),
    '{PASSWORD_RULES}' => htmlspecialchars(lawyer_tf('profile.password_rules', 'Password rules: at least 8 chars, one uppercase and one lowercase letter.')),
    '{CHANGE_PASSWORD}' => htmlspecialchars(lawyer_tf('profile.change_password', 'Change password')),
    '{FIRST_NAME}' => htmlspecialchars($profile['first_name']),
    '{LAST_NAME}' => htmlspecialchars($profile['last_name']),
    '{EMAIL}' => htmlspecialchars($profile['email']),
    '{PHONE}' => htmlspecialchars($profile['phone']),
    '{LICENSE_NUMBER}' => htmlspecialchars($profile['license_number']),
    '{SPECIALIZATION}' => htmlspecialchars($profile['specialization']),
    '{EXPERIENCE_YEARS}' => htmlspecialchars($profile['experience_years']),
    '{BIO}' => htmlspecialchars($profile['bio']),
    '{OFFICE_ADDRESS}' => htmlspecialchars($profile['office_address']),
    '{NEW_PASSWORD_INVALID_CLASS}' => $passwordInvalidClass,
    '{CONFIRM_PASSWORD_INVALID_CLASS}' => $confirmInvalidClass,
    '{NEW_PASSWORD_ERROR}' => $passwordErrorHtml,
    '{CONFIRM_PASSWORD_ERROR}' => $confirmErrorHtml,
    '{PORTAL_THEME_BODY_CLASS}' => legalpro_portal_theme_body_class(),
];

$html = str_replace(array_keys($replacements), array_values($replacements), $html);

require_once __DIR__ . '/../inc/lawyer-sidebar.php';
$html = inject_lawyer_portal_head($html);
$html = inject_lawyer_sidebar($html);

echo $html;
