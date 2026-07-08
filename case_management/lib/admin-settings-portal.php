<?php
/**
 * Admin settings hub — sectioned layout (like Documents).
 */

require_once __DIR__ . '/../inc/bank-accounts-settings.php';
require_once __DIR__ . '/../inc/admin-layout.php';
require_once __DIR__ . '/admin-locale.php';
require_once __DIR__ . '/admin-role-access.php';

function legalpro_settings_page_subtitle(string $pageKey): string
{
    $map = [
        'settings' => 'Manage branding, appearance, finance, and more',
        'settings-branding' => 'Company name, logo, and contact details',
        'settings-appearance' => 'Theme, accent colors, and language',
        'settings-finance' => 'Currency, bank accounts, and invoices',
        'settings-catalog' => 'Services, categories, and specializations',
        'settings-ai' => 'OpenAI key and assistant model',
        'settings-role-access' => 'Control staff portal access',
    ];

    return $map[$pageKey] ?? '';
}

function legalpro_settings_portal_pages(): array
{
    return [
        'settings' => [
            'file' => 'settings.php',
            'title' => 'Overview',
            'nav' => 'Settings',
        ],
        'settings-branding' => [
            'file' => 'settings-branding.php',
            'title' => 'Branding',
            'nav' => 'Branding & Company',
        ],
        'settings-appearance' => [
            'file' => 'settings-appearance.php',
            'title' => 'Appearance',
            'nav' => 'Appearance',
        ],
        'settings-finance' => [
            'file' => 'settings-finance.php',
            'title' => 'Finance',
            'nav' => 'Finance & Invoices',
        ],
        'settings-catalog' => [
            'file' => 'settings-catalog.php',
            'title' => 'Practice catalog',
            'nav' => 'Practice Catalog',
        ],
        'settings-ai' => [
            'file' => 'settings-ai.php',
            'title' => 'AI Assistant',
            'nav' => 'AI Assistant',
        ],
        'settings-role-access' => [
            'file' => 'settings-role-access.php',
            'title' => 'Role Access',
            'nav' => 'Role Access',
            'admin_only' => true,
        ],
    ];
}

function legalpro_settings_redirect_for_form(string $formType): string
{
    $map = [
        'branding' => 'settings-branding.php',
        'portal_theme' => 'settings-appearance.php',
        'currency' => 'settings-finance.php',
        'bank_accounts' => 'settings-finance.php',
        'add_service' => 'settings-catalog.php',
        'remove_service' => 'settings-catalog.php',
        'add_case_category' => 'settings-catalog.php',
        'remove_case_category' => 'settings-catalog.php',
        'add_lawyer_specialization' => 'settings-catalog.php',
        'remove_lawyer_specialization' => 'settings-catalog.php',
        'chatbot_ai' => 'settings-ai.php',
        'role_access' => 'settings-role-access.php',
    ];

    return $map[$formType] ?? 'settings.php';
}

function legalpro_settings_message_html(array $state): string
{
    if (empty($state['message'])) {
        return '';
    }

    $type = !empty($state['messageType']) ? $state['messageType'] : 'info';

    return '<div class="alert alert-' . htmlspecialchars($type) . ' alert-dismissible fade show" role="alert">'
        . htmlspecialchars($state['message'])
        . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>'
        . '</div>';
}

function legalpro_admin_settings_handle_post(array &$state): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    $formType = isset($_POST['form_type']) ? $_POST['form_type'] : '';
    $redirect = legalpro_settings_redirect_for_form($formType);

    if ($formType === 'branding') {
        $companyName = isset($_POST['company_name']) ? trim($_POST['company_name']) : '';
        $companyDetails = isset($_POST['company_details']) ? trim($_POST['company_details']) : '';
        $logoFile = isset($_FILES['company_logo']) ? $_FILES['company_logo'] : null;
        $croppedLogoData = isset($_POST['company_logo_cropped']) ? (string) $_POST['company_logo_cropped'] : '';
        $result = saveCompanyBranding($companyName, $companyDetails, $logoFile, $croppedLogoData);

        if (!$result['ok']) {
            $state['message'] = $result['message'];
            $state['messageType'] = 'danger';
            return;
        }

        header('Location: ' . $redirect . '?msg=' . urlencode($result['message']) . '&type=success');
        exit;
    }

    if ($formType === 'portal_theme') {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $themeMode = isset($_POST['theme_mode']) ? (string) $_POST['theme_mode'] : 'light';
        $themeColor = isset($_POST['theme_color']) ? (string) $_POST['theme_color'] : 'navy';
        $customPrimary = isset($_POST['custom_primary']) ? (string) $_POST['custom_primary'] : null;
        $result = savePortalTheme($themeMode, $themeColor, $customPrimary);

        if (!$result['ok']) {
            $state['message'] = $result['message'];
            $state['messageType'] = 'danger';
            return;
        }

        $adminId = (int) ($_SESSION['admin_id'] ?? 0);
        if ($adminId > 0 && isset($_POST['locale'])) {
            $localeResult = saveAdminPortalLocale($adminId, (string) $_POST['locale']);
            if (!$localeResult['ok']) {
                $state['message'] = $localeResult['message'];
                $state['messageType'] = 'danger';
                return;
            }
        }

        header('Location: ' . $redirect . '?msg=' . urlencode(admin_t('settings.saved')) . '&type=success');
        exit;
    }

    if ($formType === 'currency') {
        $currencyOptionsList = getCurrencyOptions();
        $selectedCurrency = isset($_POST['currency']) ? strtoupper(trim($_POST['currency'])) : '';
        if (!isset($currencyOptionsList[$selectedCurrency])) {
            $state['message'] = 'Invalid currency selection.';
            $state['messageType'] = 'danger';
            return;
        }

        setSetting('currency', $selectedCurrency);
        header('Location: ' . $redirect . '?msg=' . urlencode('Currency updated successfully.') . '&type=success');
        exit;
    }

    if ($formType === 'bank_accounts') {
        $result = saveBankAccountsSettings($_POST);
        if (!$result['ok']) {
            $state['message'] = $result['message'];
            $state['messageType'] = 'danger';
            return;
        }

        header('Location: ' . $redirect . '?msg=' . urlencode($result['message']) . '&type=success');
        exit;
    }

    if ($formType === 'add_service') {
        $serviceName = isset($_POST['service_name']) ? trim($_POST['service_name']) : '';
        if ($serviceName === '') {
            $state['message'] = 'Service name is required.';
            $state['messageType'] = 'danger';
            return;
        }

        $services = getOfferedServices();
        if (in_array($serviceName, $services, true)) {
            $state['message'] = 'That service already exists.';
            $state['messageType'] = 'warning';
            return;
        }

        $services[] = $serviceName;
        setOfferedServices($services);
        header('Location: ' . $redirect . '?msg=' . urlencode('Service added successfully.') . '&type=success');
        exit;
    }

    if ($formType === 'remove_service') {
        $serviceIndex = isset($_POST['service_index']) ? (int) $_POST['service_index'] : -1;
        $services = getOfferedServices();
        if (!isset($services[$serviceIndex])) {
            $state['message'] = 'Service not found.';
            $state['messageType'] = 'danger';
            return;
        }

        array_splice($services, $serviceIndex, 1);
        setOfferedServices($services);
        header('Location: ' . $redirect . '?msg=' . urlencode('Service removed successfully.') . '&type=success');
        exit;
    }

    if ($formType === 'add_case_category') {
        $categoryName = isset($_POST['category_name']) ? trim($_POST['category_name']) : '';
        if ($categoryName === '') {
            $state['message'] = 'Category name is required.';
            $state['messageType'] = 'danger';
            return;
        }

        $categories = getCaseCategoriesFromSettings();
        if (in_array($categoryName, $categories, true) || in_array($categoryName, getDefaultCaseCategories(), true)) {
            $state['message'] = 'That category already exists.';
            $state['messageType'] = 'warning';
            return;
        }

        $categories[] = $categoryName;
        sort($categories, SORT_NATURAL | SORT_FLAG_CASE);
        setSetting('case_categories', json_encode($categories, JSON_UNESCAPED_UNICODE));
        header('Location: ' . $redirect . '?msg=' . urlencode('Category added successfully.') . '&type=success');
        exit;
    }

    if ($formType === 'remove_case_category') {
        $categoryIndex = isset($_POST['category_index']) ? (int) $_POST['category_index'] : -1;
        $categories = getCaseCategoriesFromSettings();
        if (!isset($categories[$categoryIndex])) {
            $state['message'] = 'Category not found.';
            $state['messageType'] = 'danger';
            return;
        }

        array_splice($categories, $categoryIndex, 1);
        setSetting('case_categories', json_encode($categories, JSON_UNESCAPED_UNICODE));
        header('Location: ' . $redirect . '?msg=' . urlencode('Category removed successfully.') . '&type=success');
        exit;
    }

    if ($formType === 'add_lawyer_specialization') {
        $specializationName = isset($_POST['specialization_name']) ? trim($_POST['specialization_name']) : '';
        if ($specializationName === '') {
            $state['message'] = 'Specialization name is required.';
            $state['messageType'] = 'danger';
            return;
        }

        $specializations = getLawyerSpecializationsFromSettings();
        if (in_array($specializationName, $specializations, true)) {
            $state['message'] = 'That specialization already exists.';
            $state['messageType'] = 'warning';
            return;
        }

        $specializations[] = $specializationName;
        sort($specializations, SORT_NATURAL | SORT_FLAG_CASE);
        setSetting('lawyer_specializations', json_encode($specializations, JSON_UNESCAPED_UNICODE));
        header('Location: ' . $redirect . '?msg=' . urlencode('Specialization added successfully.') . '&type=success');
        exit;
    }

    if ($formType === 'remove_lawyer_specialization') {
        $specializationIndex = isset($_POST['specialization_index']) ? (int) $_POST['specialization_index'] : -1;
        $specializations = getLawyerSpecializationsFromSettings();
        if (!isset($specializations[$specializationIndex])) {
            $state['message'] = 'Specialization not found.';
            $state['messageType'] = 'danger';
            return;
        }

        array_splice($specializations, $specializationIndex, 1);
        setSetting('lawyer_specializations', json_encode($specializations, JSON_UNESCAPED_UNICODE));
        header('Location: ' . $redirect . '?msg=' . urlencode('Specialization removed successfully.') . '&type=success');
        exit;
    }

    if ($formType === 'chatbot_ai') {
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
        header('Location: ' . $redirect . '?msg=' . urlencode('AI assistant settings saved.') . '&type=success');
        exit;
    }

    if ($formType === 'role_access') {
        if (!legalpro_admin_can_manage_role_access()) {
            $state['message'] = 'Access denied. Only administrators can manage role access.';
            $state['messageType'] = 'danger';
            return;
        }

        $posted = isset($_POST['perm_staff']) && is_array($_POST['perm_staff']) ? $_POST['perm_staff'] : [];
        $result = legalpro_save_admin_role_permissions($posted);
        header('Location: ' . $redirect . '?msg=' . urlencode($result['message']) . '&type=success');
        exit;
    }
}

function legalpro_admin_settings_init_state(): array
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $state = [
        'message' => '',
        'messageType' => '',
    ];

    if (empty($_SESSION['admin_id'])) {
        header('Location: login.php');
        exit;
    }

    if (isset($_GET['msg']) && isset($_GET['type'])) {
        $state['message'] = urldecode((string) $_GET['msg']);
        $state['messageType'] = (string) $_GET['type'];
    }

    return $state;
}

function legalpro_admin_settings_load(array &$state): void
{
    $currencyOptionsList = getCurrencyOptions();
    $currencyConfig = getCurrencyConfig();
    $companyBranding = getCompanyBranding();

    $currencyOptionsHtml = '';
    foreach ($currencyOptionsList as $code => $meta) {
        $selected = $currencyConfig['code'] === $code ? ' selected' : '';
        $currencyOptionsHtml .= '<option value="' . htmlspecialchars($code) . '"' . $selected . '>'
            . htmlspecialchars($meta['label']) . '</option>';
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

    $chatbotAiEnabled = getSetting('chatbot_ai_enabled', '1') !== '0';
    $openaiModel = (string) getSetting('openai_model', 'gpt-4o-mini');
    $openaiKeyStored = trim((string) getSetting('openai_api_key', '')) !== '';
    $chatbotAiStatusHtml = $openaiKeyStored && $chatbotAiEnabled
        ? '<span class="badge bg-success">AI active</span>'
        : ($openaiKeyStored ? '<span class="badge bg-warning text-dark">Key saved — AI disabled</span>' : '<span class="badge bg-secondary">Local mode only</span>');
    $openaiModelOptions = [
        'gpt-4o-mini' => 'GPT-4o mini (recommended — fast & affordable)',
        'gpt-4o' => 'GPT-4o (most capable)',
        'gpt-4-turbo' => 'GPT-4 Turbo',
        'gpt-3.5-turbo' => 'GPT-3.5 Turbo (legacy)',
    ];
    $openaiModelOptionsHtml = '';
    foreach ($openaiModelOptions as $value => $label) {
        $sel = $openaiModel === $value ? ' selected' : '';
        $openaiModelOptionsHtml .= '<option value="' . htmlspecialchars($value) . '"' . $sel . '>'
            . htmlspecialchars($label) . '</option>';
    }

    $state['currencyOptionsHtml'] = $currencyOptionsHtml;
    $state['servicesListHtml'] = $servicesListHtml;
    $state['categoriesListHtml'] = $categoriesListHtml;
    $state['specializationsListHtml'] = $specializationsListHtml;
    $state['companyBranding'] = $companyBranding;
    $state['portalThemeSettingsHtml'] = renderPortalThemeSettingsHtml();
    $state['bankAccountsSettingsHtml'] = renderBankAccountsSettingsHtml(false);
    $state['chatbotAiStatusHtml'] = $chatbotAiStatusHtml;
    $state['chatbotAiEnabledChecked'] = $chatbotAiEnabled ? ' checked' : '';
    $state['openaiKeyPlaceholder'] = $openaiKeyStored ? '•••••••••••••••• (saved — leave blank to keep)' : 'sk-...';
    $state['openaiModelOptionsHtml'] = $openaiModelOptionsHtml;
    $state['roleAccessStaffPermissions'] = legalpro_get_admin_role_permissions()['staff'] ?? legalpro_admin_default_role_permissions('staff');
}

function legalpro_settings_hub_cards_html(): string
{
    require_once __DIR__ . '/../inc/legalpro-icons.php';
    $pages = legalpro_settings_portal_pages();
    $cards = [
        'settings-branding' => ['desc' => 'Company name, logo, and contact details.', 'icon' => 'building-2', 'accent' => 'primary'],
        'settings-appearance' => ['desc' => admin_t('settings.hub_appearance_desc'), 'icon' => 'palette', 'accent' => 'info'],
        'settings-finance' => ['desc' => 'Currency, bank accounts, and invoice defaults.', 'icon' => 'landmark', 'accent' => 'success'],
        'settings-catalog' => ['desc' => 'Services, case categories, and lawyer specializations.', 'icon' => 'clipboard-list', 'accent' => 'dark'],
        'settings-ai' => ['desc' => 'OpenAI key and model for the AI assistant.', 'icon' => 'bot', 'accent' => 'warning'],
        'settings-role-access' => ['desc' => admin_t('settings.hub_role_access_desc'), 'icon' => 'shield-check', 'accent' => 'danger'],
    ];

    $html = '<div class="row g-3">';
    foreach ($cards as $key => $meta) {
        if (!isset($pages[$key])) {
            continue;
        }
        $page = $pages[$key];
        if (!empty($page['admin_only']) && !legalpro_admin_can_manage_role_access()) {
            continue;
        }
        $html .= '
        <div class="col-md-6 col-xl-4">
            <a href="' . htmlspecialchars($page['file']) . '" class="card legalpro-doc-hub-card h-100 text-decoration-none">
                <div class="card-body p-3">
                    <div class="dashboard-stat-icon-wrap dashboard-stat-icon-wrap--' . $meta['accent'] . ' mb-3">' . legalpro_icon($meta['icon']) . '</div>
                    <h6 class="mb-1 text-dark">' . htmlspecialchars($page['nav']) . '</h6>
                    <p class="text-sm text-muted mb-0">' . htmlspecialchars($meta['desc']) . '</p>
                </div>
            </a>
        </div>';
    }
    $html .= '</div>';

    return $html;
}

function legalpro_settings_subnav_html(string $activeKey): string
{
    $pages = legalpro_settings_portal_pages();
    $html = '<nav class="legalpro-doc-subnav" aria-label="Settings sections">';
    $overviewActive = $activeKey === 'settings' ? ' is-active' : '';
    $html .= '<a class="legalpro-doc-subnav__link' . $overviewActive . '" href="settings.php">Overview</a>';
    foreach ($pages as $key => $page) {
        if ($key === 'settings') {
            continue;
        }
        if (!empty($page['admin_only']) && !legalpro_admin_can_manage_role_access()) {
            continue;
        }
        $active = $key === $activeKey ? ' is-active' : '';
        $html .= '<a class="legalpro-doc-subnav__link' . $active . '" href="' . htmlspecialchars($page['file']) . '">'
            . htmlspecialchars($page['title']) . '</a>';
    }
    $html .= '</nav>';

    return $html;
}

function legalpro_settings_branding_content_html(array $state): string
{
    $branding = $state['companyBranding'];

    return '<div class="card mb-4">
        <div class="card-header pb-0">
            <h6 class="mb-0">Branding &amp; company information</h6>
            <p class="text-sm text-muted mb-0">Logo and details shown across admin, lawyer, and client portals.</p>
        </div>
        <div class="card-body">
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="form_type" value="branding">
                <input type="hidden" name="company_logo_cropped" id="companyLogoCroppedInput" value="">
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-control-label">Company Name</label>
                            <input class="form-control" type="text" name="company_name" value="' . htmlspecialchars($branding['name']) . '" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-control-label">Logo</label>
                            <input class="form-control" type="file" id="companyLogoUploadInput" name="company_logo" accept="image/png,image/jpeg,image/gif,image/webp,image/svg+xml">
                            <small class="text-muted">PNG, JPG, GIF, WEBP, or SVG. Max 2 MB. You can crop before saving.</small>
                        </div>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-3 mb-3 settings-brand-logo-row">
                    <img src="' . htmlspecialchars($branding['logo_url']) . '" alt="Current company logo" class="settings-brand-logo-preview" id="settingsBrandLogoPreview">
                    <div>
                        <p class="text-sm mb-0 font-weight-bold">Current sidebar logo</p>
                        <p class="text-xs text-muted mb-0">This appears at the top of every portal sidebar after saving.</p>
                    </div>
                    <div class="ms-auto d-flex gap-2">
                        <button type="button" class="btn btn-outline-primary btn-sm mb-0" id="settingsLogoEditBtn">Edit/Crop logo</button>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-control-label">Company Details</label>
                    <textarea class="form-control" rows="3" name="company_details" placeholder="Address, contact details...">' . htmlspecialchars($branding['details']) . '</textarea>
                </div>
                <button type="submit" class="btn btn-dark">Save Branding</button>
            </form>
        </div>
    </div>
    <div class="modal fade settings-logo-editor-modal" id="settingsLogoEditorModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered settings-logo-editor-modal-dialog">
            <div class="modal-content settings-logo-editor-modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit & crop logo</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="settings-logo-editor-wrap">
                        <img id="settingsLogoEditorImage" alt="Logo editor preview">
                    </div>
                    <p class="text-xs text-muted mt-2 mb-0">Drag to reposition, use mouse wheel or zoom buttons, then apply crop.</p>
                </div>
                <div class="modal-footer d-flex justify-content-between">
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-outline-secondary btn-sm mb-0" id="settingsLogoFreeCropBtn">Free crop</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm mb-0" id="settingsLogoSquareCropBtn">1:1</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm mb-0" id="settingsLogoWideCropBtn">3:1</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm mb-0" id="settingsLogoZoomOutBtn">Zoom -</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm mb-0" id="settingsLogoZoomInBtn">Zoom +</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm mb-0" id="settingsLogoFitBtn">Fit</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm mb-0" id="settingsLogoResetCropBtn">Reset</button>
                    </div>
                    <button type="button" class="btn btn-dark btn-sm mb-0" id="settingsLogoApplyCropBtn">Apply crop</button>
                </div>
            </div>
        </div>
    </div>';
}

function legalpro_settings_finance_content_html(array $state): string
{
    return '<div class="card mb-4">
        <div class="card-header pb-0">
            <h6 class="mb-0">Default currency</h6>
            <p class="text-sm text-muted mb-0">Used across invoices, payments, dashboards, and documents.</p>
        </div>
        <div class="card-body">
            <form method="post">
                <input type="hidden" name="form_type" value="currency">
                <div class="row g-2 align-items-end">
                    <div class="col-md-6">
                        <label class="form-control-label">Currency</label>
                        <select class="form-control" name="currency">' . $state['currencyOptionsHtml'] . '</select>
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-dark mb-0">Save currency</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    <div class="card mb-4">
        <div class="card-body">' . $state['bankAccountsSettingsHtml'] . '</div>
    </div>';
}

function legalpro_settings_catalog_content_html(array $state): string
{
    return '<div class="card mb-4">
        <div class="card-header pb-0 d-flex justify-content-between align-items-center">
            <div>
                <h6 class="mb-0">Services offered</h6>
                <p class="text-sm text-muted mb-0">Options shown when registering cases.</p>
            </div>
            <button type="button" class="btn btn-sm btn-dark" data-bs-toggle="modal" data-bs-target="#addServiceModal">Add service</button>
        </div>
        <div class="card-body">
            <ul class="list-group">' . $state['servicesListHtml'] . '</ul>
        </div>
    </div>
    <div class="card mb-4">
        <div class="card-header pb-0 d-flex justify-content-between align-items-center">
            <div>
                <h6 class="mb-0">Case categories</h6>
                <p class="text-sm text-muted mb-0">Default categories (Civil, Criminal, Corporate, Family) are always available.</p>
            </div>
            <button type="button" class="btn btn-sm btn-dark" data-bs-toggle="modal" data-bs-target="#addCategoryModal">Add category</button>
        </div>
        <div class="card-body">
            <ul class="list-group">' . $state['categoriesListHtml'] . '</ul>
        </div>
    </div>
    <div class="card mb-4">
        <div class="card-header pb-0 d-flex justify-content-between align-items-center">
            <div>
                <h6 class="mb-0">Lawyer specializations</h6>
                <p class="text-sm text-muted mb-0">Options shown in the lawyer form dropdown.</p>
            </div>
            <button type="button" class="btn btn-sm btn-dark" data-bs-toggle="modal" data-bs-target="#addSpecializationModal">Add specialization</button>
        </div>
        <div class="card-body">
            <ul class="list-group">' . $state['specializationsListHtml'] . '</ul>
        </div>
    </div>
    ' . legalpro_settings_catalog_modals_html();
}

function legalpro_settings_catalog_modals_html(): string
{
    return '<div class="modal fade" id="addServiceModal" tabindex="-1" aria-labelledby="addServiceModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="post">
                    <input type="hidden" name="form_type" value="add_service">
                    <div class="modal-header">
                        <h5 class="modal-title" id="addServiceModalLabel">Add service</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <label class="form-control-label">Service name</label>
                        <input type="text" class="form-control" name="service_name" required placeholder="e.g. Contract Law">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-dark">Add service</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="modal fade" id="addCategoryModal" tabindex="-1" aria-labelledby="addCategoryModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="post">
                    <input type="hidden" name="form_type" value="add_case_category">
                    <div class="modal-header">
                        <h5 class="modal-title" id="addCategoryModalLabel">Add case category</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <label class="form-control-label">Category name</label>
                        <input type="text" class="form-control" name="category_name" required placeholder="e.g. Immigration">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-dark">Add category</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="modal fade" id="addSpecializationModal" tabindex="-1" aria-labelledby="addSpecializationModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="post">
                    <input type="hidden" name="form_type" value="add_lawyer_specialization">
                    <div class="modal-header">
                        <h5 class="modal-title" id="addSpecializationModalLabel">Add specialization</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <label class="form-control-label">Specialization name</label>
                        <input type="text" class="form-control" name="specialization_name" required placeholder="e.g. Criminal Law">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-dark">Add specialization</button>
                    </div>
                </form>
            </div>
        </div>
    </div>';
}

function legalpro_settings_ai_content_html(array $state): string
{
    return '<div class="card mb-4">
        <div class="card-header pb-0 d-flex justify-content-between align-items-center">
            <h6 class="mb-0">AI assistant</h6>
            ' . $state['chatbotAiStatusHtml'] . '
        </div>
        <div class="card-body">
            <p class="text-sm text-muted mb-3">Connect OpenAI to power natural-language answers with your live case data. Booking, navigation, and profile updates still run locally for reliability.</p>
            <form method="post">
                <input type="hidden" name="form_type" value="chatbot_ai">
                <div class="form-check form-switch legalpro-status-switch mb-3">
                    <input class="form-check-input" type="checkbox" name="chatbot_ai_enabled" id="chatbotAiEnabled" value="1"' . $state['chatbotAiEnabledChecked'] . '>
                    <label class="form-check-label" for="chatbotAiEnabled">Enable OpenAI-powered responses</label>
                </div>
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-control-label">OpenAI API key</label>
                            <input class="form-control" type="password" name="openai_api_key" autocomplete="off" placeholder="' . htmlspecialchars($state['openaiKeyPlaceholder']) . '">
                            <small class="text-muted">Get a key at platform.openai.com. You can also set the OPENAI_API_KEY environment variable on the server.</small>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-control-label">Model</label>
                            <select class="form-control" name="openai_model">' . $state['openaiModelOptionsHtml'] . '</select>
                        </div>
                    </div>
                </div>
                <button type="submit" class="btn btn-dark btn-sm mt-2">Save AI settings</button>
            </form>
        </div>
    </div>';
}

function legalpro_settings_shared_styles(): string
{
    return <<<'CSS'
        .settings-brand-logo-preview {
            width: 52px;
            height: 52px;
            object-fit: contain;
            border: 1px solid #e9ecef;
            border-radius: 0.5rem;
            padding: 0.25rem;
            background: #fff;
        }
        .settings-brand-logo-row { flex-wrap: wrap; }
        .settings-logo-editor-wrap {
            position: relative;
            width: 100%;
            height: min(72vh, 720px);
            min-height: 560px;
            background:
                linear-gradient(45deg, #e2e8f0 25%, transparent 25%),
                linear-gradient(-45deg, #e2e8f0 25%, transparent 25%),
                linear-gradient(45deg, transparent 75%, #e2e8f0 75%),
                linear-gradient(-45deg, transparent 75%, #e2e8f0 75%);
            background-size: 20px 20px;
            background-position: 0 0, 0 10px, 10px -10px, -10px 0;
            background-color: #f8fafc;
            border: 1px solid #e9ecef;
            border-radius: 0.75rem;
            overflow: hidden;
        }
        .settings-logo-editor-wrap img {
            display: block;
            max-width: none !important;
            max-height: none !important;
        }
        .settings-logo-editor-wrap .cropper-container {
            width: 100% !important;
            height: 100% !important;
        }
        .settings-logo-editor-modal-dialog {
            width: min(98vw, 1480px);
            max-width: none;
        }
        .settings-logo-editor-modal-content {
            border-radius: 0.9rem;
        }
        .settings-logo-editor-modal .modal-header,
        .settings-logo-editor-modal .modal-footer {
            padding: 0.85rem 1rem;
        }
        .settings-logo-editor-modal .modal-body {
            padding: 1rem;
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
            border-color: var(--legalpro-theme-primary, #0077b6);
            background: rgba(var(--legalpro-theme-primary-rgb, 0, 119, 182), 0.08);
        }
        .settings-theme-mode__option input { margin: 0; }
        .lp-accent-palette__grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(152px, 1fr));
            gap: 0.75rem;
        }
        .lp-accent-card {
            position: relative;
            display: flex;
            flex-direction: column;
            margin: 0;
            border: 1px solid rgba(15, 23, 42, 0.1);
            border-radius: 0.85rem;
            overflow: hidden;
            cursor: pointer;
            background: #fff;
            transition: border-color 0.15s ease, box-shadow 0.15s ease, transform 0.15s ease;
        }
        .lp-accent-card:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 18px rgba(15, 23, 42, 0.08);
        }
        .lp-accent-card input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }
        .lp-accent-card__stripe {
            display: block;
            height: 3.35rem;
            width: 100%;
        }
        .lp-accent-card__stripe--custom {
            position: relative;
        }
        .lp-accent-card__body {
            padding: 0.65rem 0.75rem 0.8rem;
        }
        .lp-accent-card__name {
            display: block;
            font-size: 0.8125rem;
            font-weight: 700;
            color: #344767;
            line-height: 1.25;
        }
        .lp-accent-card__desc {
            display: block;
            margin-top: 0.2rem;
            font-size: 0.6875rem;
            font-weight: 500;
            color: #94a3b8;
            line-height: 1.35;
        }
        .lp-accent-card__check {
            position: absolute;
            top: 0.45rem;
            right: 0.45rem;
            width: 1.2rem;
            height: 1.2rem;
            border-radius: 999px;
            background: #fff;
            color: var(--legalpro-theme-primary, #023e8a);
            font-size: 0.62rem;
            font-weight: 800;
            line-height: 1;
            display: none;
            align-items: center;
            justify-content: center;
            box-shadow: 0 1px 4px rgba(15, 23, 42, 0.18);
        }
        .lp-accent-card:has(input:checked) .lp-accent-card__check {
            display: inline-flex;
        }
        .lp-accent-custom-panel {
            margin-top: 1rem;
            padding: 1rem 1.1rem;
            border-radius: 0.85rem;
            border: 1px dashed rgba(15, 23, 42, 0.14);
            background: rgba(248, 250, 252, 0.85);
        }
        .lp-accent-custom-panel__controls {
            display: grid;
            grid-template-columns: auto minmax(8rem, 10rem) 1fr;
            gap: 0.85rem;
            align-items: center;
        }
        .lp-accent-custom-panel__picker {
            width: 3rem;
            height: 3rem;
            padding: 0.2rem;
            border-radius: 0.65rem;
            cursor: pointer;
        }
        .lp-accent-custom-panel__hex {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 0.875rem;
            font-weight: 600;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }
        .lp-accent-custom-panel__help {
            grid-column: 1 / -1;
        }
        @media (max-width: 767.98px) {
            .lp-accent-custom-panel__controls {
                grid-template-columns: auto 1fr;
            }
            .lp-accent-custom-panel__help {
                grid-column: 1 / -1;
            }
        }
        body.legalpro-dark-mode .lp-accent-card {
            background: var(--lp-dark-surface-raised, #343b4f);
            border-color: rgba(255, 255, 255, 0.1);
        }
        body.legalpro-dark-mode .lp-accent-card__name {
            color: var(--lp-dark-text, #f8f9fc);
        }
        body.legalpro-dark-mode .lp-accent-card__desc {
            color: var(--lp-dark-text-muted, #94a3b8);
        }
        body.legalpro-dark-mode .lp-accent-custom-panel {
            background: rgba(255, 255, 255, 0.03);
            border-color: rgba(255, 255, 255, 0.12);
        }
        body.legalpro-dark-mode .lp-accent-custom-panel__hex {
            background: var(--lp-dark-surface, #2a3040);
            border-color: rgba(255, 255, 255, 0.12);
            color: var(--lp-dark-text, #f8f9fc);
        }
        body.legalpro-dark-mode .settings-brand-logo-preview {
            background: var(--lp-dark-surface, #1a2035);
            border-color: var(--lp-dark-border, #3d4660);
        }
        body.legalpro-dark-mode .settings-logo-editor-wrap {
            border-color: var(--lp-dark-border, rgba(255,255,255,0.12)) !important;
            background: var(--lp-dark-surface, #343b4f) !important;
        }
        body.legalpro-dark-mode .settings-logo-editor-modal-content {
            background: var(--lp-dark-surface, #343b4f);
            border-color: var(--lp-dark-border, rgba(255,255,255,0.12));
        }
        @media (max-width: 991.98px) {
            .settings-logo-editor-wrap {
                min-height: 62vh;
                max-height: 62vh;
            }
            .settings-logo-editor-modal-dialog {
                width: 98vw;
            }
        }
        .legalpro-doc-hub-card { transition: transform 0.15s ease, box-shadow 0.15s ease; border: 1px solid #e9ecf3; }
        .legalpro-doc-hub-card:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(15, 23, 42, 0.08); }
        body.legalpro-dark-mode .legalpro-doc-hub-card { border-color: var(--lp-dark-border); }
        body.legalpro-dark-mode .legalpro-doc-hub-card h6 { color: var(--lp-dark-text) !important; }
        .legalpro-role-access-table .form-check-input { cursor: pointer; }
        .legalpro-role-access-table tbody tr:hover { background: rgba(0, 119, 182, 0.04); }
CSS;
}

function legalpro_settings_branding_scripts(): string
{
    return <<<'HTML'
<script>
(function() {
    var fileInput = document.getElementById('companyLogoUploadInput');
    var preview = document.getElementById('settingsBrandLogoPreview');
    var hiddenCroppedInput = document.getElementById('companyLogoCroppedInput');
    var editBtn = document.getElementById('settingsLogoEditBtn');
    var editorImage = document.getElementById('settingsLogoEditorImage');
    var applyCropBtn = document.getElementById('settingsLogoApplyCropBtn');
    var freeCropBtn = document.getElementById('settingsLogoFreeCropBtn');
    var squareCropBtn = document.getElementById('settingsLogoSquareCropBtn');
    var wideCropBtn = document.getElementById('settingsLogoWideCropBtn');
    var zoomInBtn = document.getElementById('settingsLogoZoomInBtn');
    var zoomOutBtn = document.getElementById('settingsLogoZoomOutBtn');
    var fitBtn = document.getElementById('settingsLogoFitBtn');
    var resetCropBtn = document.getElementById('settingsLogoResetCropBtn');
    var modalEl = document.getElementById('settingsLogoEditorModal');
    if (!modalEl) return;
    var modal = new bootstrap.Modal(modalEl);
    var cropper = null;
    var workingImageDataUrl = '';
    var pendingEditorSrc = '';

    function destroyCropper() {
        if (cropper) {
            cropper.destroy();
            cropper = null;
        }
    }

    function fitImageToEditor() {
        if (!cropper) return;
        var imageData = cropper.getImageData();
        var containerData = cropper.getContainerData();
        if (!imageData || !containerData || !imageData.naturalWidth || !imageData.naturalHeight) return;
        var padding = 0.94;
        var ratioW = (containerData.width * padding) / imageData.naturalWidth;
        var ratioH = (containerData.height * padding) / imageData.naturalHeight;
        var ratio = Math.min(ratioW, ratioH);
        if (!isFinite(ratio) || ratio <= 0) return;
        cropper.zoomTo(ratio);
        cropper.center();
    }

    function initCropper() {
        destroyCropper();
        if (!editorImage || !editorImage.src) return;
        cropper = new Cropper(editorImage, {
            viewMode: 0,
            autoCropArea: 0.96,
            dragMode: 'move',
            responsive: true,
            restore: false,
            background: false,
            wheelZoomRatio: 0.1,
            toggleDragModeOnDblclick: false,
            ready: function() {
                requestAnimationFrame(function() {
                    fitImageToEditor();
                    requestAnimationFrame(fitImageToEditor);
                });
            }
        });
    }

    function prepareLogoForEditing(src, done) {
        var probe = new Image();
        probe.crossOrigin = 'anonymous';
        probe.onload = function() {
            var naturalW = probe.naturalWidth || probe.width;
            var naturalH = probe.naturalHeight || probe.height;
            var maxDim = Math.max(naturalW, naturalH);
            var minTarget = 960;
            if (!naturalW || !naturalH || maxDim >= minTarget) {
                done(src);
                return;
            }
            var scale = minTarget / maxDim;
            var canvas = document.createElement('canvas');
            canvas.width = Math.max(1, Math.round(naturalW * scale));
            canvas.height = Math.max(1, Math.round(naturalH * scale));
            var ctx = canvas.getContext('2d');
            if (!ctx) {
                done(src);
                return;
            }
            ctx.imageSmoothingEnabled = true;
            ctx.imageSmoothingQuality = 'high';
            ctx.drawImage(probe, 0, 0, canvas.width, canvas.height);
            done(canvas.toDataURL('image/png'));
        };
        probe.onerror = function() { done(src); };
        probe.src = src;
    }

    function loadEditorImage(src) {
        if (!editorImage || !src) return;
        editorImage.onload = function() { initCropper(); };
        editorImage.src = src;
        if (editorImage.complete) {
            initCropper();
        }
    }

    function openEditorWithSource(src) {
        if (!src || !editorImage) return;
        prepareLogoForEditing(src, function(preparedSrc) {
            pendingEditorSrc = preparedSrc;
            modal.show();
        });
    }

    modalEl.addEventListener('shown.bs.modal', function() {
        if (!pendingEditorSrc) return;
        var src = pendingEditorSrc;
        pendingEditorSrc = '';
        loadEditorImage(src);
    });

    function readFileAsDataUrl(file, done) {
        var reader = new FileReader();
        reader.onload = function(e) { done(e.target.result || ''); };
        reader.readAsDataURL(file);
    }

    if (fileInput) {
        fileInput.addEventListener('change', function() {
            var file = fileInput.files && fileInput.files[0];
            if (!file) return;
            readFileAsDataUrl(file, function(dataUrl) {
                if (!dataUrl) return;
                workingImageDataUrl = dataUrl;
                openEditorWithSource(dataUrl);
            });
        });
    }

    if (editBtn) {
        editBtn.addEventListener('click', function() {
            if (hiddenCroppedInput && hiddenCroppedInput.value) {
                openEditorWithSource(hiddenCroppedInput.value);
                return;
            }
            if (workingImageDataUrl) {
                openEditorWithSource(workingImageDataUrl);
                return;
            }
            if (preview && preview.src) {
                openEditorWithSource(preview.src);
            }
        });
    }

    if (applyCropBtn) {
        applyCropBtn.addEventListener('click', function() {
            if (!cropper || !hiddenCroppedInput) return;
            var cropData = cropper.getData(true);
            var targetWidth = Math.max(512, Math.min(2200, Math.round(cropData.width || 1024)));
            var targetHeight = Math.max(220, Math.min(2200, Math.round(cropData.height || 1024)));
            var canvas = cropper.getCroppedCanvas({
                width: targetWidth,
                height: targetHeight,
                fillColor: '#ffffff',
                imageSmoothingEnabled: true,
                imageSmoothingQuality: 'high'
            });
            var data = canvas.toDataURL('image/png', 0.95);
            hiddenCroppedInput.value = data;
            workingImageDataUrl = data;
            if (preview) preview.src = data;
            modal.hide();
        });
    }

    if (freeCropBtn) freeCropBtn.addEventListener('click', function() { if (cropper) cropper.setAspectRatio(NaN); });
    if (squareCropBtn) squareCropBtn.addEventListener('click', function() { if (cropper) cropper.setAspectRatio(1); });
    if (wideCropBtn) wideCropBtn.addEventListener('click', function() { if (cropper) cropper.setAspectRatio(3 / 1); });
    if (zoomInBtn) zoomInBtn.addEventListener('click', function() { if (cropper) cropper.zoom(0.1); });
    if (zoomOutBtn) zoomOutBtn.addEventListener('click', function() { if (cropper) cropper.zoom(-0.1); });
    if (fitBtn) fitBtn.addEventListener('click', function() { fitImageToEditor(); });
    if (resetCropBtn) resetCropBtn.addEventListener('click', function() {
        if (!cropper) return;
        cropper.reset();
        fitImageToEditor();
    });
    modalEl.addEventListener('hidden.bs.modal', function() {
        destroyCropper();
        pendingEditorSrc = '';
        if (editorImage) editorImage.removeAttribute('src');
    });
})();
</script>
HTML;
}

function legalpro_settings_render_page(string $pageKey, string $contentHtml, array $state): void
{
    $pages = legalpro_settings_portal_pages();
    $page = $pages[$pageKey] ?? $pages['settings'];
    $navTitle = $page['nav'];
    $bodyClass = legalpro_portal_theme_body_class();
    $subnav = $pageKey === 'settings' ? '' : legalpro_settings_subnav_html($pageKey);

    $html = '<!DOCTYPE html>
<html lang="' . admin_portal_html_lang() . '">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>LegalPro · ' . htmlspecialchars($navTitle) . '</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-icons.css" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-svg.css" rel="stylesheet" />
    <script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
    <link href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
    <link href="../assets/css/app-font-montserrat.css?v=1" rel="stylesheet" />
    <link href="../assets/css/legalpro-icons.css?v=2" rel="stylesheet" />
    <link href="../assets/css/bank-accounts-ui.css?v=4" rel="stylesheet" />
    ';
    ob_start();
    include dirname(__DIR__) . '/inc/admin-portal-head.php';
    $html .= ob_get_clean();
    $html .= '<link href="../assets/css/legalpro-documents-hub.css?v=5" rel="stylesheet" />'
        . '<style>' . legalpro_settings_shared_styles() . '</style>
<link href="https://cdn.jsdelivr.net/npm/cropperjs@1.6.2/dist/cropper.min.css" rel="stylesheet">
</head>
<body class="g-sidenav-show g-sidenav-pinned bg-gray-100 legalpro-admin-portal legalpro-settings-page admin-settings-page' . $bodyClass . '">
    <div class="min-height-300 bg-legalpro-admin position-absolute w-100"></div>
    <aside class="sidenav bg-white navbar navbar-vertical navbar-expand-xs border-0 border-radius-xl my-3 fixed-start ms-4" id="sidenav-main"></aside>
    <main class="main-content position-relative border-radius-lg">
        ' . legalpro_render_admin_page_navbar($navTitle, legalpro_settings_page_subtitle($pageKey)) . '
        <div class="container-fluid py-4">
            ' . legalpro_settings_message_html($state) . '
            ' . $subnav . '
            ' . $contentHtml . '
        </div>
    </main>
    <script src="../assets/js/core/popper.min.js"></script>
    <script src="../assets/js/core/bootstrap.min.js"></script>
    <script src="../assets/js/plugins/perfect-scrollbar.min.js"></script>
    <script src="../assets/js/argon-dashboard.min.js?v=2.1.0"></script>
    <script src="https://cdn.jsdelivr.net/npm/cropperjs@1.6.2/dist/cropper.min.js"></script>
    ' . legalpro_settings_branding_scripts() . '
</body>
</html>';

    $html = preg_replace('/href="([^"\']+)\.html"/i', 'href="$1.php"', $html);
    ob_start();
    include dirname(__DIR__) . '/inc/menunav.php';
    $sidebar = ob_get_clean();
    $html = preg_replace('/<aside[\s\S]*?<\/aside>/', $sidebar, $html, 1);
    ob_start();
    include dirname(__DIR__) . '/inc/footer.php';
    $footer = ob_get_clean();
    $html = preg_replace('/<\/body>\s*<\/html>$/i', $footer . "\n</body>\n</html>", $html);
    echo legalpro_apply_copyright_line($html);
}
