<?php
/**
 * Admin portal role-based access (admin vs staff).
 */

require_once __DIR__ . '/../inc/db.php';

function legalpro_admin_permission_modules(): array
{
    return [
        'dashboard' => ['label_key' => 'nav.dashboard', 'label' => 'Dashboard'],
        'clients' => ['label_key' => 'nav.clients', 'label' => 'Clients'],
        'cases' => ['label_key' => 'nav.cases', 'label' => 'Cases'],
        'payments' => ['label_key' => 'nav.payments', 'label' => 'Payments'],
        'appointments' => ['label_key' => 'nav.appointments', 'label' => 'Appointments'],
        'court_tracking' => ['label_key' => 'nav.court_tracking', 'label' => 'Court Tracking'],
        'lawyers' => ['label_key' => 'nav.lawyers', 'label' => 'Lawyers'],
        'finance' => ['label_key' => 'nav.finance', 'label' => 'Finance'],
        'documents' => ['label_key' => 'nav.documents', 'label' => 'Documents'],
        'ai_assistant' => ['label_key' => 'nav.ai_assistant', 'label' => 'AI Assistant'],
        'settings' => ['label_key' => 'nav.settings', 'label' => 'Settings'],
    ];
}

function legalpro_admin_default_role_permissions(string $role): array
{
    $modules = array_keys(legalpro_admin_permission_modules());
    $defaults = [];
    foreach ($modules as $module) {
        $defaults[$module] = true;
    }

    if ($role === 'staff') {
        $defaults['lawyers'] = false;
        $defaults['settings'] = false;
    }

    return $defaults;
}

function legalpro_get_admin_role_permissions(): array
{
    $raw = getSetting('admin_role_permissions', '');
    if ($raw === '' || $raw === null) {
        return [
            'staff' => legalpro_admin_default_role_permissions('staff'),
        ];
    }

    $decoded = json_decode((string) $raw, true);
    if (!is_array($decoded)) {
        return [
            'staff' => legalpro_admin_default_role_permissions('staff'),
        ];
    }

    $modules = array_keys(legalpro_admin_permission_modules());
    $staff = isset($decoded['staff']) && is_array($decoded['staff']) ? $decoded['staff'] : [];
    $normalized = [];
    foreach ($modules as $module) {
        $normalized[$module] = !empty($staff[$module]);
    }

    return ['staff' => $normalized];
}

function legalpro_save_admin_role_permissions(array $staffPermissions): array
{
    $modules = array_keys(legalpro_admin_permission_modules());
    $normalized = [];
    foreach ($modules as $module) {
        $normalized[$module] = !empty($staffPermissions[$module]);
    }

    setSetting('admin_role_permissions', json_encode(['staff' => $normalized], JSON_UNESCAPED_UNICODE));

    return ['ok' => true, 'message' => function_exists('admin_t') ? admin_t('settings.role_access_saved') : 'Role access settings saved.'];
}

function legalpro_admin_current_role(): string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    return strtolower(trim((string) ($_SESSION['admin_role'] ?? 'staff')));
}

function legalpro_admin_is_super_admin(): bool
{
    return legalpro_admin_current_role() === 'admin';
}

function legalpro_admin_can_manage_role_access(): bool
{
    return legalpro_admin_is_super_admin();
}

function legalpro_admin_has_permission(string $module): bool
{
    if (legalpro_admin_is_super_admin()) {
        return true;
    }

    $role = legalpro_admin_current_role();
    if ($role !== 'staff') {
        return false;
    }

    $perms = legalpro_get_admin_role_permissions();
    $staffPerms = $perms['staff'] ?? legalpro_admin_default_role_permissions('staff');

    return !empty($staffPerms[$module]);
}

function legalpro_admin_menu_id_to_permission(string $menuId): string
{
    $map = [
        'dashboard' => 'dashboard',
        'clients' => 'clients',
        'tables' => 'cases',
        'payments' => 'payments',
        'appointments' => 'appointments',
        'court-tracking' => 'court_tracking',
        'lawyers' => 'lawyers',
        'financial-summary' => 'finance',
        'documents' => 'documents',
        'chatbot' => 'ai_assistant',
        'settings' => 'settings',
    ];

    return $map[$menuId] ?? $menuId;
}

function legalpro_admin_page_to_permission(string $page): ?string
{
    static $map = [
        'dashboard' => 'dashboard',
        'clients' => 'clients',
        'client-detail' => 'clients',
        'tables' => 'cases',
        'case-detail' => 'cases',
        'case-view' => 'cases',
        'case-edit' => 'cases',
        'case-new' => 'cases',
        'payments' => 'payments',
        'payments-outstanding' => 'payments',
        'payments-recent' => 'payments',
        'appointments' => 'appointments',
        'new_appointment' => 'appointments',
        'court-tracking' => 'court_tracking',
        'lawyers' => 'lawyers',
        'financial-summary' => 'finance',
        'invoices' => 'finance',
        'billing' => 'finance',
        'documents' => 'documents',
        'document-upload' => 'documents',
        'document-templates' => 'documents',
        'document-generate' => 'documents',
        'document-browse' => 'documents',
        'document-download' => 'documents',
        'chatbot' => 'ai_assistant',
        'chatbot-api' => 'ai_assistant',
        'settings' => 'settings',
        'settings-branding' => 'settings',
        'settings-appearance' => 'settings',
        'settings-finance' => 'settings',
        'settings-catalog' => 'settings',
        'settings-ai' => 'settings',
        'settings-role-access' => 'settings',
        'admin-notifications' => 'dashboard',
        'admin-notifications-api' => 'dashboard',
        'admin-theme-api' => 'settings',
        'search' => 'dashboard',
        'profile' => null,
    ];

    return array_key_exists($page, $map) ? $map[$page] : null;
}

function legalpro_admin_guard_current_page(string $page): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (empty($_SESSION['admin_id'])) {
        return;
    }

    if ($page === 'settings-role-access' && !legalpro_admin_can_manage_role_access()) {
        header('Location: dashboard.php?msg=' . urlencode('Access denied. Only administrators can manage role access.') . '&type=danger');
        exit;
    }

    $permission = legalpro_admin_page_to_permission($page);
    if ($permission === null) {
        return;
    }

    if (!legalpro_admin_has_permission($permission)) {
        header('Location: dashboard.php?msg=' . urlencode('You do not have access to that section.') . '&type=danger');
        exit;
    }
}

function legalpro_filter_admin_menu_items(array $items): array
{
    return array_values(array_filter($items, static function (array $item): bool {
        $permission = legalpro_admin_menu_id_to_permission((string) ($item['id'] ?? ''));

        return legalpro_admin_has_permission($permission);
    }));
}

function legalpro_admin_permission_label(string $module): string
{
    $modules = legalpro_admin_permission_modules();
    if (!isset($modules[$module])) {
        return ucwords(str_replace('_', ' ', $module));
    }

    $meta = $modules[$module];
    if (function_exists('admin_t') && !empty($meta['label_key'])) {
        $translated = admin_t($meta['label_key']);
        if ($translated !== $meta['label_key']) {
            return $translated;
        }
    }

    return (string) ($meta['label'] ?? $module);
}

function legalpro_settings_role_access_content_html(array $state): string
{
    $staffPerms = $state['roleAccessStaffPermissions'] ?? legalpro_admin_default_role_permissions('staff');
    $modules = legalpro_admin_permission_modules();

    $rows = '';
    foreach ($modules as $module => $meta) {
        $checked = !empty($staffPerms[$module]) ? ' checked' : '';
        $label = legalpro_admin_permission_label($module);
        $rows .= '<tr>
            <td class="align-middle"><span class="text-sm font-weight-bold">' . htmlspecialchars($label) . '</span></td>
            <td class="align-middle text-center">
                <div class="form-check form-switch d-inline-flex justify-content-center mb-0">
                    <input class="form-check-input" type="checkbox" name="perm_staff[' . htmlspecialchars($module, ENT_QUOTES, 'UTF-8') . ']" value="1"' . $checked . ' id="perm_staff_' . htmlspecialchars($module, ENT_QUOTES, 'UTF-8') . '">
                </div>
            </td>
        </tr>';
    }

    return '<div class="card mb-4">
        <div class="card-header pb-0">
            <h6 class="mb-0">' . htmlspecialchars(admin_t('settings.role_access_title')) . '</h6>
            <p class="text-sm text-muted mb-0">' . htmlspecialchars(admin_t('settings.role_access_help')) . '</p>
        </div>
        <div class="card-body">
            <div class="alert alert-info text-sm mb-4" role="status">
                ' . htmlspecialchars(admin_t('settings.role_access_admin_note')) . '
            </div>
            <form method="post">
                <input type="hidden" name="form_type" value="role_access">
                <div class="table-responsive">
                    <table class="table align-items-center mb-0 legalpro-role-access-table">
                        <thead>
                            <tr>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Module</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-center">Staff</th>
                            </tr>
                        </thead>
                        <tbody>' . $rows . '</tbody>
                    </table>
                </div>
                <button type="submit" class="btn btn-dark mt-4">' . htmlspecialchars(admin_t('settings.save_role_access')) . '</button>
            </form>
        </div>
    </div>';
}
