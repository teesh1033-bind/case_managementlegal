<?php
require_once __DIR__ . '/../inc/db.php';

$message = '';
$messageType = '';
if (isset($_GET['msg']) && isset($_GET['type'])) {
    $message = urldecode($_GET['msg']);
    $messageType = $_GET['type'];
}

$currencyOptionsList = getCurrencyOptions();
$currencyConfig = getCurrencyConfig();
$companyBranding = getCompanyBranding();
$portalThemeSettingsHtml = renderPortalThemeSettingsHtml();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formType = isset($_POST['form_type']) ? $_POST['form_type'] : '';
    if ($formType === 'branding') {
        $companyName = isset($_POST['company_name']) ? trim($_POST['company_name']) : '';
        $companyDetails = isset($_POST['company_details']) ? trim($_POST['company_details']) : '';
        $logoFile = isset($_FILES['company_logo']) ? $_FILES['company_logo'] : null;
        $result = saveCompanyBranding($companyName, $companyDetails, $logoFile);

        if (!$result['ok']) {
            $message = $result['message'];
            $messageType = 'danger';
        } else {
            header('Location: settings.php?msg=' . urlencode($result['message']) . '&type=success');
            exit;
        }
    } elseif ($formType === 'portal_theme') {
        $themeMode = isset($_POST['theme_mode']) ? (string) $_POST['theme_mode'] : 'light';
        $themeColor = isset($_POST['theme_color']) ? (string) $_POST['theme_color'] : 'primary';
        $customPrimary = isset($_POST['custom_primary']) ? (string) $_POST['custom_primary'] : null;
        $result = savePortalTheme($themeMode, $themeColor, $customPrimary);

        if (!$result['ok']) {
            $message = $result['message'];
            $messageType = 'danger';
        } else {
            header('Location: settings.php?msg=' . urlencode($result['message']) . '&type=success');
            exit;
        }
    } elseif ($formType === 'currency') {
        $selectedCurrency = isset($_POST['currency']) ? strtoupper(trim($_POST['currency'])) : '';
        if (!isset($currencyOptionsList[$selectedCurrency])) {
            $message = 'Invalid currency selection.';
            $messageType = 'danger';
        } else {
            setSetting('currency', $selectedCurrency);
            header('Location: settings.php?msg=' . urlencode('Currency updated successfully.') . '&type=success');
            exit;
        }
    } elseif ($formType === 'add_service') {
        $serviceName = isset($_POST['service_name']) ? trim($_POST['service_name']) : '';
        if ($serviceName === '') {
            $message = 'Service name is required.';
            $messageType = 'danger';
        } else {
            $services = getOfferedServices();
            if (in_array($serviceName, $services, true)) {
                $message = 'That service already exists.';
                $messageType = 'warning';
            } else {
                $services[] = $serviceName;
                setOfferedServices($services);
                header('Location: settings.php?msg=' . urlencode('Service added successfully.') . '&type=success');
                exit;
            }
        }
    } elseif ($formType === 'remove_service') {
        $serviceIndex = isset($_POST['service_index']) ? (int) $_POST['service_index'] : -1;
        $services = getOfferedServices();
        if (!isset($services[$serviceIndex])) {
            $message = 'Service not found.';
            $messageType = 'danger';
        } else {
            array_splice($services, $serviceIndex, 1);
            setOfferedServices($services);
            header('Location: settings.php?msg=' . urlencode('Service removed successfully.') . '&type=success');
            exit;
        }
    } elseif ($formType === 'add_case_category') {
        $categoryName = isset($_POST['category_name']) ? trim($_POST['category_name']) : '';
        if ($categoryName === '') {
            $message = 'Category name is required.';
            $messageType = 'danger';
        } else {
            $categories = getCaseCategoriesFromSettings();
            if (in_array($categoryName, $categories, true) || in_array($categoryName, getDefaultCaseCategories(), true)) {
                $message = 'That category already exists.';
                $messageType = 'warning';
            } else {
                $categories[] = $categoryName;
                sort($categories, SORT_NATURAL | SORT_FLAG_CASE);
                setSetting('case_categories', json_encode($categories, JSON_UNESCAPED_UNICODE));
                header('Location: settings.php?msg=' . urlencode('Category added successfully.') . '&type=success');
                exit;
            }
        }
    } elseif ($formType === 'remove_case_category') {
        $categoryIndex = isset($_POST['category_index']) ? (int) $_POST['category_index'] : -1;
        $categories = getCaseCategoriesFromSettings();
        if (!isset($categories[$categoryIndex])) {
            $message = 'Category not found.';
            $messageType = 'danger';
        } else {
            array_splice($categories, $categoryIndex, 1);
            setSetting('case_categories', json_encode($categories, JSON_UNESCAPED_UNICODE));
            header('Location: settings.php?msg=' . urlencode('Category removed successfully.') . '&type=success');
            exit;
        }
    } elseif ($formType === 'add_lawyer_specialization') {
        $specializationName = isset($_POST['specialization_name']) ? trim($_POST['specialization_name']) : '';
        if ($specializationName === '') {
            $message = 'Specialization name is required.';
            $messageType = 'danger';
        } else {
            $specializations = getLawyerSpecializationsFromSettings();
            if (in_array($specializationName, $specializations, true)) {
                $message = 'That specialization already exists.';
                $messageType = 'warning';
            } else {
                $specializations[] = $specializationName;
                sort($specializations, SORT_NATURAL | SORT_FLAG_CASE);
                setSetting('lawyer_specializations', json_encode($specializations, JSON_UNESCAPED_UNICODE));
                header('Location: settings.php?msg=' . urlencode('Specialization added successfully.') . '&type=success');
                exit;
            }
        }
    } elseif ($formType === 'remove_lawyer_specialization') {
        $specializationIndex = isset($_POST['specialization_index']) ? (int) $_POST['specialization_index'] : -1;
        $specializations = getLawyerSpecializationsFromSettings();
        if (!isset($specializations[$specializationIndex])) {
            $message = 'Specialization not found.';
            $messageType = 'danger';
        } else {
            array_splice($specializations, $specializationIndex, 1);
            setSetting('lawyer_specializations', json_encode($specializations, JSON_UNESCAPED_UNICODE));
            header('Location: settings.php?msg=' . urlencode('Specialization removed successfully.') . '&type=success');
            exit;
        }
    } elseif ($formType === 'chatbot_ai') {
        $aiEnabled = isset($_POST['chatbot_ai_enabled']) ? '1' : '0';
        $apiKey = isset($_POST['openai_api_key']) ? trim((string) $_POST['openai_api_key']) : '';
        $model = isset($_POST['openai_model']) ? trim((string) $_POST['openai_model']) : 'gpt-4o-mini';
        $allowedModels = ['gpt-4o-mini', 'gpt-4o', 'gpt-4-turbo', 'gpt-3.5-turbo'];
        if (!in_array($model, $allowedModels, true)) {
            $model = 'gpt-4o-mini';
        }
        setSetting('chatbot_ai_enabled', $aiEnabled);
        setSetting('openai_model', $model);
        if ($apiKey !== '') {
            setSetting('openai_api_key', $apiKey);
        }
        header('Location: settings.php?msg=' . urlencode('AI assistant settings saved.') . '&type=success');
        exit;
    }
}

$offeredServices = getOfferedServices();
$servicesListHtml = '';
if (empty($offeredServices)) {
    $servicesListHtml = '<li class="list-group-item text-center text-muted">No services added yet</li>';
} else {
    foreach ($offeredServices as $index => $serviceName) {
        $servicesListHtml .= '<li class="list-group-item d-flex justify-content-between align-items-center">'
            . htmlspecialchars($serviceName)
            . '<form method="post" class="d-inline mb-0" onsubmit="return confirm(\'Remove this service?\');">'
            . '<input type="hidden" name="form_type" value="remove_service">'
            . '<input type="hidden" name="service_index" value="' . (int) $index . '">'
            . '<button type="submit" class="btn btn-sm btn-danger">Remove</button>'
            . '</form></li>';
    }
}

$caseCategories = getCaseCategoriesFromSettings();
$categoriesListHtml = '';
if (empty($caseCategories)) {
    $categoriesListHtml = '<li class="list-group-item text-center text-muted">No custom categories added yet</li>';
} else {
    foreach ($caseCategories as $index => $categoryName) {
        $categoriesListHtml .= '<li class="list-group-item d-flex justify-content-between align-items-center">'
            . htmlspecialchars($categoryName)
            . '<form method="post" class="d-inline mb-0" onsubmit="return confirm(\'Remove this category?\');">'
            . '<input type="hidden" name="form_type" value="remove_case_category">'
            . '<input type="hidden" name="category_index" value="' . (int) $index . '">'
            . '<button type="submit" class="btn btn-sm btn-danger">Remove</button>'
            . '</form></li>';
    }
}

$lawyerSpecializations = getLawyerSpecializationsFromSettings();
$specializationsListHtml = '';
if (empty($lawyerSpecializations)) {
    $specializationsListHtml = '<li class="list-group-item text-center text-muted">No custom specializations added yet</li>';
} else {
    foreach ($lawyerSpecializations as $index => $specializationName) {
        $specializationsListHtml .= '<li class="list-group-item d-flex justify-content-between align-items-center">'
            . htmlspecialchars($specializationName)
            . '<form method="post" class="d-inline mb-0" onsubmit="return confirm(\'Remove this specialization?\');">'
            . '<input type="hidden" name="form_type" value="remove_lawyer_specialization">'
            . '<input type="hidden" name="specialization_index" value="' . (int) $index . '">'
            . '<button type="submit" class="btn btn-sm btn-danger">Remove</button>'
            . '</form></li>';
    }
}

$currencyOptionsHtml = '';
foreach ($currencyOptionsList as $code => $meta) {
    $selected = $currencyConfig['code'] === $code ? ' selected' : '';
    $currencyOptionsHtml .= '<option value="' . htmlspecialchars($code) . '"' . $selected . '>' . htmlspecialchars($meta['label']) . '</option>';
}

$chatbotAiEnabled = getSetting('chatbot_ai_enabled', '1') !== '0';
$openaiModel = (string) getSetting('openai_model', 'gpt-4o-mini');
$openaiKeyStored = trim((string) getSetting('openai_api_key', '')) !== '';
$chatbotAiStatusHtml = $openaiKeyStored && $chatbotAiEnabled
    ? '<span class="badge bg-success">AI active</span>'
    : ($openaiKeyStored ? '<span class="badge bg-warning text-dark">Key saved — AI disabled</span>' : '<span class="badge bg-secondary">Local mode only</span>');
$chatbotAiEnabledChecked = $chatbotAiEnabled ? ' checked' : '';
$openaiModelOptions = [
    'gpt-4o-mini' => 'GPT-4o mini (recommended — fast & affordable)',
    'gpt-4o' => 'GPT-4o (most capable)',
    'gpt-4-turbo' => 'GPT-4 Turbo',
    'gpt-3.5-turbo' => 'GPT-3.5 Turbo (legacy)',
];
$openaiModelOptionsHtml = '';
foreach ($openaiModelOptions as $value => $label) {
    $sel = $openaiModel === $value ? ' selected' : '';
    $openaiModelOptionsHtml .= '<option value="' . htmlspecialchars($value) . '"' . $sel . '>' . htmlspecialchars($label) . '</option>';
}
$openaiKeyPlaceholder = $openaiKeyStored ? '•••••••••••••••• (saved — leave blank to keep)' : 'sk-...';

$messageHtml = '';
if (!empty($message)) {
    $messageHtml = '<div class="alert alert-' . htmlspecialchars($messageType ? $messageType : 'info') . ' alert-dismissible fade show" role="alert">
        ' . htmlspecialchars($message) . '
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
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
	<title>Argon Dashboard - Settings</title>
	<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
	<link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-icons.css" rel="stylesheet" />
	<link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-svg.css" rel="stylesheet" />
	<script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
	<link id="pagestyle" href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
<link href="../assets/css/app-font-montserrat.css?v=1" rel="stylesheet" />
	<style>
		.settings-brand-logo-preview {
			width: 52px;
			height: 52px;
			object-fit: contain;
			border: 1px solid #e9ecef;
			border-radius: 0.5rem;
			padding: 0.25rem;
			background: #fff;
		}
		.settings-theme-mode {
			display: flex;
			flex-wrap: wrap;
			gap: 0.75rem;
		}
		.settings-theme-mode__option {
			display: inline-flex;
			align-items: center;
			gap: 0.4rem;
			padding: 0.55rem 1rem;
			border: 1px solid #e9ecef;
			border-radius: 0.65rem;
			cursor: pointer;
			font-size: 0.875rem;
			font-weight: 600;
			margin: 0;
		}
		.settings-theme-mode__option:has(input:checked) {
			border-color: var(--legalpro-theme-primary, #5e72e4);
			background: rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.08);
		}
		.settings-theme-mode__option input {
			margin: 0;
		}
		.settings-theme-swatches {
			display: flex;
			flex-wrap: wrap;
			gap: 0.75rem;
		}
		.settings-theme-swatch {
			display: flex;
			flex-direction: column;
			align-items: center;
			gap: 0.35rem;
			cursor: pointer;
			margin: 0;
		}
		.settings-theme-swatch input {
			position: absolute;
			opacity: 0;
			pointer-events: none;
		}
		.settings-theme-swatch__dot {
			width: 2.25rem;
			height: 2.25rem;
			border-radius: 999px;
			border: 2px solid transparent;
			box-shadow: 0 2px 8px rgba(0, 0, 0, 0.12);
		}
		.settings-theme-swatch.active .settings-theme-swatch__dot,
		.settings-theme-swatch:has(input:checked) .settings-theme-swatch__dot {
			border-color: #344767;
			box-shadow: 0 0 0 3px rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.25);
		}
		.settings-theme-swatch__label {
			font-size: 0.75rem;
			font-weight: 600;
			color: #67748e;
		}
	</style>
	<?php include __DIR__ . '/../inc/portal-theme-head.php'; ?>
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-admin-portal">
	<div class="min-height-300 bg-legalpro-admin position-absolute w-100"></div>
	<aside class="sidenav bg-white navbar navbar-vertical navbar-expand-xs border-0 border-radius-xl my-3 fixed-start ms-4 " id="sidenav-main">
		<div class="sidenav-header">
			<i class="fas fa-times p-3 cursor-pointer text-secondary opacity-5 position-absolute end-0 top-0 d-none d-xl-none" aria-hidden="true" id="iconSidenav"></i>
			<a class="navbar-brand m-0" href="../pages/dashboard.html">
				<img src="../assets/img/logo-ct-dark.png" width="26px" height="26px" class="navbar-brand-img h-100" alt="Argon logo">
				<span class="ms-1 font-weight-bold">Argon Dashboard</span>
			</a>
		</div>
		<hr class="horizontal dark mt-0">
		<div class="collapse navbar-collapse  w-auto " id="sidenav-collapse-main">
			<ul class="navbar-nav">
			<li class="nav-item"><a class="nav-link" href="../pages/dashboard.php"><div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center"><i class="ni ni-tv-2 text-dark text-sm opacity-10"></i></div><span class="nav-link-text ms-1">Menu</span></a></li>
				<li class="nav-item"><a class="nav-link" href="../pages/tables.php"><div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center"><i class="ni ni-collection text-dark text-sm opacity-10"></i></div><span class="nav-link-text ms-1">Cases</span></a></li>
				<li class="nav-item"><a class="nav-link" href="../pages/clients.html"><div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center"><i class="ni ni-circle-08 text-dark text-sm opacity-10"></i></div><span class="nav-link-text ms-1">Clients</span></a></li>
				<li class="nav-item"><a class="nav-link" href="../pages/staff.html"><div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center"><i class="ni ni-badge text-dark text-sm opacity-10"></i></div><span class="nav-link-text ms-1">Staff</span></a></li>
				<li class="nav-item"><a class="nav-link" href="../pages/billing.html"><div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center"><i class="ni ni-credit-card text-dark text-sm opacity-10"></i></div><span class="nav-link-text ms-1">Finance</span></a></li>
				<li class="nav-item"><a class="nav-link" href="../pages/documents.html"><div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center"><i class="ni ni-folder-17 text-dark text-sm opacity-10"></i></div><span class="nav-link-text ms-1">Documents</span></a></li>
				<li class="nav-item"><a class="nav-link" href="../pages/appointments.html"><div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center"><i class="ni ni-time-alarm text-dark text-sm opacity-10"></i></div><span class="nav-link-text ms-1">Appointments</span></a></li>
				<li class="nav-item"><a class="nav-link" href="../pages/reports.html"><div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center"><i class="ni ni-chart-bar-32 text-dark text-sm opacity-10"></i></div><span class="nav-link-text ms-1">Reports</span></a></li>
				<li class="nav-item"><a class="nav-link active" href="../pages/settings.html"><div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center"><i class="ni ni-settings text-dark text-sm opacity-10"></i></div><span class="nav-link-text ms-1">Settings</span></a></li>
				<li class="nav-item"><a class="nav-link" href="../pages/chatbot.html"><div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center"><i class="ni ni-chat-round text-dark text-sm opacity-10"></i></div><span class="nav-link-text ms-1">Chatbot</span></a></li>
			</ul>
		</div>
	</aside>
	<main class="main-content position-relative border-radius-lg ">
		<nav class="navbar navbar-main navbar-expand-lg px-0 mx-4 shadow-none border-radius-xl " id="navbarBlur" data-scroll="false">
			<div class="container-fluid py-1 px-3">
				<nav aria-label="breadcrumb">
					<ol class="breadcrumb bg-transparent mb-0 pb-0 pt-1 px-0 me-sm-6 me-5">
						<li class="breadcrumb-item text-sm"><a class="opacity-5 text-white" href="javascript:;">Pages</a></li>
						<li class="breadcrumb-item text-sm text-white active" aria-current="page">Settings</li>
					</ol>
					<h6 class="font-weight-bolder text-white mb-0">Settings</h6>
				</nav>
			</div>
		</nav>
		<div class="container-fluid py-4">
            {MESSAGE}
			<div class="row">
				<div class="col-12">
					<div class="card mb-4">
						<div class="card-header pb-0">
							<h6>Branding</h6>
						</div>
						<div class="card-body">
							<form method="post" enctype="multipart/form-data">
								<input type="hidden" name="form_type" value="branding">
								<div class="row">
									<div class="col-md-6">
										<div class="form-group">
											<label class="form-control-label">Company Name</label>
											<input class="form-control" type="text" name="company_name" value="{COMPANY_NAME}" required>
										</div>
									</div>
									<div class="col-md-6">
										<div class="form-group">
											<label class="form-control-label">Logo</label>
											<input class="form-control" type="file" name="company_logo" accept="image/png,image/jpeg,image/gif,image/webp,image/svg+xml">
											<small class="text-muted">PNG, JPG, GIF, WEBP, or SVG. Max 2 MB.</small>
										</div>
									</div>
								</div>
								<div class="d-flex align-items-center gap-3 mb-3">
									<img src="{COMPANY_LOGO_URL}" alt="Current company logo" class="settings-brand-logo-preview">
									<div>
										<p class="text-sm mb-0 font-weight-bold">Current sidebar logo</p>
										<p class="text-xs text-muted mb-0">This appears at the top of every portal sidebar after saving.</p>
									</div>
								</div>
								<div class="form-group">
									<label class="form-control-label">Company Details</label>
									<textarea class="form-control" rows="3" name="company_details" placeholder="Address, contact details...">{COMPANY_DETAILS}</textarea>
								</div>
								<button type="submit" class="btn btn-dark">Save Branding</button>
							</form>
                            <hr class="horizontal dark my-4">
                            <form method="post" class="mt-3">
                                <input type="hidden" name="form_type" value="currency">
                                <div class="form-group">
                                    <div class="row g-2 align-items-end">
                                        <div class="col-md-6">
                                            <label class="form-control-label">Default Currency</label>
                                            <select class="form-control" name="currency">
                                                {CURRENCY_OPTIONS}
                                            </select>
                                        </div>
                                        <div class="col-auto">
                                            <button type="submit" class="btn btn-dark mb-0">Save</button>
                                        </div>
                                    </div>
                                    <small class="text-muted d-block mt-2">Applies across admin, lawyer, and client portals — invoices, payments, dashboards, and documents.</small>
                                </div>
                            </form>
						</div>
					</div>
                    {PORTAL_THEME_SETTINGS}
					<div class="card">
						<div class="card-header pb-0 d-flex justify-content-between align-items-center">
							<h6>Services Offered</h6>
							<button type="button" class="btn btn-sm btn-dark" data-bs-toggle="modal" data-bs-target="#addServiceModal">Add Service</button>
						</div>
						<div class="card-body">
							<ul class="list-group">
								{SERVICES_LIST}
							</ul>
						</div>
					</div>
					<div class="modal fade" id="addServiceModal" tabindex="-1" aria-labelledby="addServiceModalLabel" aria-hidden="true">
						<div class="modal-dialog">
							<div class="modal-content">
								<form method="post">
									<input type="hidden" name="form_type" value="add_service">
									<div class="modal-header">
										<h5 class="modal-title" id="addServiceModalLabel">Add Service</h5>
										<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
									</div>
									<div class="modal-body">
										<label class="form-control-label">Service Name</label>
										<input type="text" class="form-control" name="service_name" required placeholder="e.g. Contract Law">
									</div>
									<div class="modal-footer">
										<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
										<button type="submit" class="btn btn-dark">Add Service</button>
									</div>
								</form>
							</div>
						</div>
					</div>
                    <div class="card mt-4">
                        <div class="card-header pb-0 d-flex justify-content-between align-items-center">
                            <h6>Case Categories</h6>
                            <button type="button" class="btn btn-sm btn-dark" data-bs-toggle="modal" data-bs-target="#addCategoryModal">Add Category</button>
                        </div>
                        <div class="card-body">
                            <p class="text-xs text-muted mb-2">Default categories (Civil, Criminal, Corporate, Family) are always available. Add extra categories below.</p>
                            <ul class="list-group">
                                {CATEGORIES_LIST}
                            </ul>
                        </div>
                    </div>
                    <div class="modal fade" id="addCategoryModal" tabindex="-1" aria-labelledby="addCategoryModalLabel" aria-hidden="true">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <form method="post">
                                    <input type="hidden" name="form_type" value="add_case_category">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="addCategoryModalLabel">Add Case Category</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        <label class="form-control-label">Category Name</label>
                                        <input type="text" class="form-control" name="category_name" required placeholder="e.g. Immigration">
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                        <button type="submit" class="btn btn-dark">Add Category</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    <div class="card mt-4">
                        <div class="card-header pb-0 d-flex justify-content-between align-items-center">
                            <h6>Lawyer Specializations</h6>
                            <button type="button" class="btn btn-sm btn-dark" data-bs-toggle="modal" data-bs-target="#addSpecializationModal">Add Specialization</button>
                        </div>
                        <div class="card-body">
                            <p class="text-xs text-muted mb-2">Manage specialization options shown in the lawyer form dropdown.</p>
                            <ul class="list-group">
                                {SPECIALIZATIONS_LIST}
                            </ul>
                        </div>
                    </div>
                    <div class="modal fade" id="addSpecializationModal" tabindex="-1" aria-labelledby="addSpecializationModalLabel" aria-hidden="true">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <form method="post">
                                    <input type="hidden" name="form_type" value="add_lawyer_specialization">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="addSpecializationModalLabel">Add Specialization</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        <label class="form-control-label">Specialization Name</label>
                                        <input type="text" class="form-control" name="specialization_name" required placeholder="e.g. Criminal Law">
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                        <button type="submit" class="btn btn-dark">Add Specialization</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    <div class="card mt-4">
                        <div class="card-header pb-0 d-flex justify-content-between align-items-center">
                            <h6>AI Assistant</h6>
                            {CHATBOT_AI_STATUS}
                        </div>
                        <div class="card-body">
                            <p class="text-sm text-muted mb-3">Connect OpenAI to power natural-language answers with your live case data. Booking, navigation, and profile updates still run locally for reliability.</p>
                            <form method="post">
                                <input type="hidden" name="form_type" value="chatbot_ai">
                                <div class="form-check form-switch legalpro-status-switch mb-3">
                                    <input class="form-check-input" type="checkbox" name="chatbot_ai_enabled" id="chatbotAiEnabled" value="1"{CHATBOT_AI_ENABLED_CHECKED}>
                                    <label class="form-check-label" for="chatbotAiEnabled">Enable OpenAI-powered responses</label>
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label class="form-control-label">OpenAI API key</label>
                                            <input class="form-control" type="password" name="openai_api_key" autocomplete="off" placeholder="{OPENAI_KEY_PLACEHOLDER}">
                                            <small class="text-muted">Get a key at platform.openai.com. You can also set the OPENAI_API_KEY environment variable on the server.</small>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label class="form-control-label">Model</label>
                                            <select class="form-control" name="openai_model">
                                                {OPENAI_MODEL_OPTIONS}
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-dark btn-sm mt-2">Save AI settings</button>
                            </form>
                        </div>
                    </div>
				</div>
			</div>
			<footer class="footer pt-3  ">
				<div class="container-fluid">
					<div class="row align-items-center justify-content-lg-between">
						<div class="col-lg-6 mb-lg-0 mb-4">
							<div class="copyright text-center text-sm text-muted text-lg-start">
								{COPYRIGHT_LINE}
							</div>
						</div>
					</div>
				</div>
			</footer>
		</div>
	</main>
	<script src="../assets/js/core/popper.min.js"></script>
	<script src="../assets/js/core/bootstrap.min.js"></script>
	<script src="../assets/js/plugins/perfect-scrollbar.min.js"></script>
	<script src="../assets/js/plugins/smooth-scrollbar.min.js"></script>
	<script src="../assets/js/argon-dashboard.min.js?v=2.1.0"></script>
</body>
</html>
HTML;

// rewrite internal links from .html to .php
$html = preg_replace('/href="([^"\']+)\.html"/i', 'href="$1.php"', $html);
ob_start(); include __DIR__ . '/../inc/menunav.php'; $sidebar = ob_get_clean();
$html = preg_replace('/<aside[\s\S]*?<\/aside>/', $sidebar, $html, 1);
ob_start(); include __DIR__ . '/../inc/footer.php'; $footer = ob_get_clean();
$html = preg_replace('/<\/body>\s*<\/html>$/i', $footer . "\n</body>\n</html>", $html);
$html = str_replace('{MESSAGE}', $messageHtml, $html);
$html = str_replace('{CURRENCY_OPTIONS}', $currencyOptionsHtml, $html);
$html = str_replace('{SERVICES_LIST}', $servicesListHtml, $html);
$html = str_replace('{CATEGORIES_LIST}', $categoriesListHtml, $html);
$html = str_replace('{SPECIALIZATIONS_LIST}', $specializationsListHtml, $html);
$html = str_replace('{COMPANY_NAME}', htmlspecialchars($companyBranding['name']), $html);
$html = str_replace('{COMPANY_LOGO_URL}', htmlspecialchars($companyBranding['logo_url']), $html);
$html = str_replace('{COMPANY_DETAILS}', htmlspecialchars($companyBranding['details']), $html);
$html = str_replace('{PORTAL_THEME_SETTINGS}', $portalThemeSettingsHtml, $html);
$html = str_replace('{CHATBOT_AI_STATUS}', $chatbotAiStatusHtml, $html);
$html = str_replace('{CHATBOT_AI_ENABLED_CHECKED}', $chatbotAiEnabledChecked, $html);
$html = str_replace('{OPENAI_KEY_PLACEHOLDER}', htmlspecialchars($openaiKeyPlaceholder), $html);
$html = str_replace('{OPENAI_MODEL_OPTIONS}', $openaiModelOptionsHtml, $html);
echo legalpro_apply_copyright_line($html);
?>