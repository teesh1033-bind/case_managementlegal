<?php
/**
 * Admin settings hub — sectioned layout (like Documents).
 */

require_once __DIR__ . '/../inc/bank-accounts-settings.php';

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
        $result = saveCompanyBranding($companyName, $companyDetails, $logoFile);

        if (!$result['ok']) {
            $state['message'] = $result['message'];
            $state['messageType'] = 'danger';
            return;
        }

        header('Location: ' . $redirect . '?msg=' . urlencode($result['message']) . '&type=success');
        exit;
    }

    if ($formType === 'portal_theme') {
        $themeMode = isset($_POST['theme_mode']) ? (string) $_POST['theme_mode'] : 'light';
        $themeColor = isset($_POST['theme_color']) ? (string) $_POST['theme_color'] : 'primary';
        $customPrimary = isset($_POST['custom_primary']) ? (string) $_POST['custom_primary'] : null;
        $result = savePortalTheme($themeMode, $themeColor, $customPrimary);

        if (!$result['ok']) {
            $state['message'] = $result['message'];
            $state['messageType'] = 'danger';
            return;
        }

        header('Location: ' . $redirect . '?msg=' . urlencode($result['message']) . '&type=success');
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
}

function legalpro_admin_settings_init_state(): array
{
    $state = [
        'message' => '',
        'messageType' => '',
    ];

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
}

function legalpro_settings_hub_cards_html(): string
{
    require_once __DIR__ . '/../inc/legalpro-icons.php';
    $pages = legalpro_settings_portal_pages();
    $cards = [
        'settings-branding' => ['desc' => 'Company name, logo, and contact details.', 'icon' => 'building-2', 'accent' => 'primary'],
        'settings-appearance' => ['desc' => 'Theme mode and accent colors for all portals.', 'icon' => 'palette', 'accent' => 'info'],
        'settings-finance' => ['desc' => 'Currency, bank accounts, and invoice defaults.', 'icon' => 'landmark', 'accent' => 'success'],
        'settings-catalog' => ['desc' => 'Services, case categories, and lawyer specializations.', 'icon' => 'clipboard-list', 'accent' => 'dark'],
        'settings-ai' => ['desc' => 'OpenAI key and model for the AI assistant.', 'icon' => 'bot', 'accent' => 'warning'],
    ];

    $html = '<div class="row g-3">';
    foreach ($cards as $key => $meta) {
        if (!isset($pages[$key])) {
            continue;
        }
        $page = $pages[$key];
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
                            <input class="form-control" type="file" name="company_logo" accept="image/png,image/jpeg,image/gif,image/webp,image/svg+xml">
                            <small class="text-muted">PNG, JPG, GIF, WEBP, or SVG. Max 2 MB.</small>
                        </div>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-3 mb-3">
                    <img src="' . htmlspecialchars($branding['logo_url']) . '" alt="Current company logo" class="settings-brand-logo-preview">
                    <div>
                        <p class="text-sm mb-0 font-weight-bold">Current sidebar logo</p>
                        <p class="text-xs text-muted mb-0">This appears at the top of every portal sidebar after saving.</p>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-control-label">Company Details</label>
                    <textarea class="form-control" rows="3" name="company_details" placeholder="Address, contact details...">' . htmlspecialchars($branding['details']) . '</textarea>
                </div>
                <button type="submit" class="btn btn-dark">Save Branding</button>
            </form>
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
        .settings-theme-mode__option input { margin: 0; }
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
        body.legalpro-dark-mode .settings-brand-logo-preview {
            background: var(--lp-dark-surface, #1a2035);
            border-color: var(--lp-dark-border, #3d4660);
        }
        .legalpro-doc-hub-card { transition: transform 0.15s ease, box-shadow 0.15s ease; border: 1px solid #e9ecf3; }
        .legalpro-doc-hub-card:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(15, 23, 42, 0.08); }
        body.legalpro-dark-mode .legalpro-doc-hub-card { border-color: var(--lp-dark-border); }
        body.legalpro-dark-mode .legalpro-doc-hub-card h6 { color: var(--lp-dark-text) !important; }
CSS;
}

function legalpro_settings_render_page(string $pageKey, string $contentHtml, array $state): void
{
    $pages = legalpro_settings_portal_pages();
    $page = $pages[$pageKey] ?? $pages['settings'];
    $navTitle = $page['nav'];
    $bodyClass = legalpro_portal_theme_body_class();
    $subnav = $pageKey === 'settings' ? '' : legalpro_settings_subnav_html($pageKey);

    $html = '<!DOCTYPE html>
<html lang="en">
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
</head>
<body class="g-sidenav-show g-sidenav-pinned bg-gray-100 legalpro-admin-portal legalpro-settings-page admin-settings-page' . $bodyClass . '">
    <div class="min-height-300 bg-legalpro-admin position-absolute w-100"></div>
    <aside class="sidenav bg-white navbar navbar-vertical navbar-expand-xs border-0 border-radius-xl my-3 fixed-start ms-4" id="sidenav-main"></aside>
    <main class="main-content position-relative border-radius-lg">
        <nav class="navbar navbar-main navbar-expand-lg px-0 mx-4 shadow-none border-radius-xl" id="navbarBlur" data-scroll="false">
            <div class="container-fluid py-1 px-3">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb bg-transparent mb-0 pb-0 pt-1 px-0 me-sm-6 me-5">
                        <li class="breadcrumb-item text-sm"><a class="opacity-5 text-white" href="settings.php">Settings</a></li>'
        . ($pageKey !== 'settings'
            ? '<li class="breadcrumb-item text-sm text-white active" aria-current="page">' . htmlspecialchars($page['title']) . '</li>'
            : '')
        . '</ol>
                    <h6 class="font-weight-bolder text-white mb-0">' . htmlspecialchars($navTitle) . '</h6>
                </nav>
            </div>
        </nav>
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
