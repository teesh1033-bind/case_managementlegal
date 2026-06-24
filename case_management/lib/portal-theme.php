<?php

function getPortalThemeColorPresets(): array
{
    return [
        'primary' => [
            'label' => 'Purple',
            'primary' => '#5e72e4',
            'primary_dark' => '#825ee4',
            'sidebar_bg' => '#1e2a44',
            'sidebar_deep' => '#151d30',
            'badge_class' => 'bg-gradient-primary',
        ],
        'dark' => [
            'label' => 'Slate',
            'primary' => '#344767',
            'primary_dark' => '#2d3748',
            'sidebar_bg' => '#1a2035',
            'sidebar_deep' => '#111525',
            'badge_class' => 'bg-gradient-dark',
        ],
        'info' => [
            'label' => 'Cyan',
            'primary' => '#11cdef',
            'primary_dark' => '#1171ef',
            'sidebar_bg' => '#1a2f44',
            'sidebar_deep' => '#122333',
            'badge_class' => 'bg-gradient-info',
        ],
        'success' => [
            'label' => 'Green',
            'primary' => '#2dce89',
            'primary_dark' => '#2dcecc',
            'sidebar_bg' => '#1a352f',
            'sidebar_deep' => '#122820',
            'badge_class' => 'bg-gradient-success',
        ],
        'warning' => [
            'label' => 'Orange',
            'primary' => '#fb6340',
            'primary_dark' => '#fbb140',
            'sidebar_bg' => '#3d2a20',
            'sidebar_deep' => '#2a1c15',
            'badge_class' => 'bg-gradient-warning',
        ],
        'danger' => [
            'label' => 'Red',
            'primary' => '#f5365c',
            'primary_dark' => '#f56036',
            'sidebar_bg' => '#3d1f2a',
            'sidebar_deep' => '#2a141c',
            'badge_class' => 'bg-gradient-danger',
        ],
    ];
}

function portalThemeNormalizeHex(string $hex): ?string
{
    $hex = strtolower(trim($hex));
    if (preg_match('/^#([0-9a-f]{3})$/', $hex, $matches)) {
        return '#' . $matches[1][0] . $matches[1][0] . $matches[1][1] . $matches[1][1] . $matches[1][2] . $matches[1][2];
    }
    if (preg_match('/^#([0-9a-f]{6})$/', $hex)) {
        return $hex;
    }

    return null;
}

function portalThemeRgbFromHex(string $hex): array
{
    $hex = ltrim(portalThemeNormalizeHex($hex) ?? '#5e72e4', '#');
    return [
        hexdec(substr($hex, 0, 2)),
        hexdec(substr($hex, 2, 2)),
        hexdec(substr($hex, 4, 2)),
    ];
}

function portalThemeHexFromRgb(int $r, int $g, int $b): string
{
    return sprintf('#%02x%02x%02x', max(0, min(255, $r)), max(0, min(255, $g)), max(0, min(255, $b)));
}

function portalThemeMixHex(string $hex1, string $hex2, float $ratio): string
{
    $ratio = max(0.0, min(1.0, $ratio));
    $rgb1 = portalThemeRgbFromHex($hex1);
    $rgb2 = portalThemeRgbFromHex($hex2);

    return portalThemeHexFromRgb(
        (int) round($rgb1[0] + (($rgb2[0] - $rgb1[0]) * $ratio)),
        (int) round($rgb1[1] + (($rgb2[1] - $rgb1[1]) * $ratio)),
        (int) round($rgb1[2] + (($rgb2[2] - $rgb1[2]) * $ratio))
    );
}

function portalThemeBuildCustomPreset(string $primaryHex): array
{
    $primary = portalThemeNormalizeHex($primaryHex) ?? '#5e72e4';
    $rgb = portalThemeRgbFromHex($primary);
    $primaryDark = portalThemeHexFromRgb(
        min(255, $rgb[0] + 36),
        min(255, max(0, $rgb[1] - 1)),
        min(255, $rgb[2])
    );

    return [
        'label' => 'Custom',
        'primary' => $primary,
        'primary_dark' => $primaryDark,
        'sidebar_bg' => portalThemeMixHex($primary, '#1a2035', 0.82),
        'sidebar_deep' => portalThemeMixHex($primary, '#111525', 0.88),
        'badge_class' => 'bg-gradient-primary',
    ];
}

function getPortalThemeColorOptions(): array
{
    return array_merge(getPortalThemeColorPresets(), [
        'custom' => portalThemeBuildCustomPreset((string) getSetting('portal_theme_custom_primary', '#5e72e4')),
    ]);
}

function getPortalTheme(): array
{
    $presets = getPortalThemeColorOptions();
    $mode = strtolower(trim((string) getSetting('portal_theme_mode', 'light')));
    $color = strtolower(trim((string) getSetting('portal_theme_color', 'primary')));

    if (!in_array($mode, ['light', 'dark'], true)) {
        $mode = 'light';
    }
    if (!isset($presets[$color])) {
        $color = 'primary';
    }

    return [
        'mode' => $mode,
        'color' => $color,
        'preset' => $presets[$color],
        'custom_primary' => portalThemeNormalizeHex((string) getSetting('portal_theme_custom_primary', '#5e72e4')) ?? '#5e72e4',
    ];
}

function legalpro_portal_theme_body_class(): string
{
    return getEffectivePortalThemeMode() === 'dark' ? ' legalpro-dark-mode' : '';
}

function savePortalTheme(string $mode, string $color, ?string $customPrimary = null): array
{
    $presets = getPortalThemeColorOptions();
    $mode = strtolower(trim($mode));
    $color = strtolower(trim($color));

    if (!in_array($mode, ['light', 'dark'], true)) {
        return ['ok' => false, 'message' => 'Invalid theme mode selected.'];
    }
    if (!isset($presets[$color])) {
        return ['ok' => false, 'message' => 'Invalid theme color selected.'];
    }

    if ($color === 'custom') {
        $normalized = portalThemeNormalizeHex((string) $customPrimary);
        if ($normalized === null) {
            return ['ok' => false, 'message' => 'Please choose a valid custom color.'];
        }
        setSetting('portal_theme_custom_primary', $normalized);
    }

    setSetting('portal_theme_mode', $mode);
    setSetting('portal_theme_color', $color);

    return ['ok' => true, 'message' => 'Appearance settings updated successfully.'];
}

function lawyerPortalThemeModeSettingKey(int $lawyerId): string
{
    return 'lawyer_portal_theme_mode_' . max(0, $lawyerId);
}

function getLawyerPortalThemeMode(int $lawyerId): string
{
    if ($lawyerId <= 0) {
        return (string) (getPortalTheme()['mode'] ?? 'light');
    }

    $stored = strtolower(trim((string) getSetting(lawyerPortalThemeModeSettingKey($lawyerId), '')));
    if (in_array($stored, ['light', 'dark'], true)) {
        return $stored;
    }

    return (string) (getPortalTheme()['mode'] ?? 'light');
}

function saveLawyerPortalThemeMode(int $lawyerId, string $mode): array
{
    if ($lawyerId <= 0) {
        return ['ok' => false, 'message' => 'Invalid lawyer account.'];
    }

    $mode = strtolower(trim($mode));
    if (!in_array($mode, ['light', 'dark'], true)) {
        return ['ok' => false, 'message' => 'Invalid theme mode selected.'];
    }

    setSetting(lawyerPortalThemeModeSettingKey($lawyerId), $mode);

    return ['ok' => true, 'message' => 'Appearance updated successfully.'];
}

function clientPortalThemeModeSettingKey(int $clientId): string
{
    return 'client_portal_theme_mode_' . max(0, $clientId);
}

function getClientPortalThemeMode(int $clientId): string
{
    if ($clientId <= 0) {
        return (string) (getPortalTheme()['mode'] ?? 'light');
    }

    $stored = strtolower(trim((string) getSetting(clientPortalThemeModeSettingKey($clientId), '')));
    if (in_array($stored, ['light', 'dark'], true)) {
        return $stored;
    }

    return (string) (getPortalTheme()['mode'] ?? 'light');
}

function saveClientPortalThemeMode(int $clientId, string $mode): array
{
    if ($clientId <= 0) {
        return ['ok' => false, 'message' => 'Invalid client account.'];
    }

    $mode = strtolower(trim($mode));
    if (!in_array($mode, ['light', 'dark'], true)) {
        return ['ok' => false, 'message' => 'Invalid theme mode selected.'];
    }

    setSetting(clientPortalThemeModeSettingKey($clientId), $mode);

    return ['ok' => true, 'message' => 'Appearance updated successfully.'];
}

function getEffectivePortalThemeMode(): string
{
    if (!empty($_SESSION['client_id'])) {
        return getClientPortalThemeMode((int) $_SESSION['client_id']);
    }

    if (!empty($_SESSION['lawyer_id'])) {
        return getLawyerPortalThemeMode((int) $_SESSION['lawyer_id']);
    }

    return (string) (getPortalTheme()['mode'] ?? 'light');
}

function isEffectivePortalThemeDark(): bool
{
    return getEffectivePortalThemeMode() === 'dark';
}

function renderLawyerPortalThemeSettingsHtml(int $lawyerId): string
{
    global $pdo;

    return renderLawyerPortalSettingsFullHtml($pdo instanceof PDO ? $pdo : null, $lawyerId);
}

function renderLawyerPortalSettingsFullHtml(?PDO $pdo, int $lawyerId): string
{
    if (!function_exists('legalpro_lawyer_settings_snapshot')) {
        $featuresPath = __DIR__ . '/lawyer-portal-features.php';
        if (is_file($featuresPath)) {
            require_once $featuresPath;
        }
    }

    if (!function_exists('legalpro_icon')) {
        $iconsPath = dirname(__DIR__) . '/inc/legalpro-icons.php';
        if (is_file($iconsPath)) {
            require_once $iconsPath;
        }
    }

    if (!function_exists('getCompanyBranding')) {
        $brandingPath = __DIR__ . '/branding.php';
        if (is_file($brandingPath)) {
            require_once $brandingPath;
        }
    }

    if (!function_exists('legalpro_lawyer_notification_count') && $pdo instanceof PDO) {
        $layoutPath = dirname(__DIR__) . '/inc/admin-layout.php';
        if (is_file($layoutPath)) {
            require_once $layoutPath;
        }
    }

    $snapshot = legalpro_lawyer_settings_snapshot($pdo, $lawyerId);
    $currentMode = getLawyerPortalThemeMode($lawyerId);
    $lightChecked = $currentMode === 'light' ? ' checked' : '';
    $darkChecked = $currentMode === 'dark' ? ' checked' : '';

    $displayName = htmlspecialchars(trim((string) ($snapshot['display_name'] ?? '')) ?: 'Lawyer');
    $email = htmlspecialchars(trim((string) ($snapshot['email'] ?? '')) ?: '—');
    $phone = htmlspecialchars(trim((string) ($snapshot['phone'] ?? '')) ?: '—');
    $specialization = htmlspecialchars(trim((string) ($snapshot['specialization'] ?? '')) ?: '—');
    $memberSince = htmlspecialchars(trim((string) ($snapshot['member_since'] ?? '')) ?: '—');
    $themeLabel = htmlspecialchars(ucfirst((string) ($snapshot['theme_mode'] ?? 'light')));

    $firm = function_exists('getCompanyBranding') ? getCompanyBranding() : ['name' => 'LegalPro', 'details' => ''];
    $firmName = htmlspecialchars((string) ($firm['name'] ?? 'LegalPro'));
    $firmDetails = trim((string) ($firm['details'] ?? ''));
    $firmDetailsHtml = $firmDetails !== ''
        ? '<p class="text-sm text-muted mb-0">' . nl2br(htmlspecialchars($firmDetails)) . '</p>'
        : '<p class="text-sm text-muted mb-0">Contact your firm administrator for office details and support.</p>';

    $quickLinks = [
        ['url' => 'lawyer-profile.php', 'icon' => 'user', 'label' => 'Profile'],
        ['url' => 'lawyer-dashboard.php', 'icon' => 'layout-dashboard', 'label' => 'Dashboard'],
        ['url' => 'tasks.php', 'icon' => 'list-checks', 'label' => 'My Tasks'],
        ['url' => 'lawyer-cases.php', 'icon' => 'briefcase', 'label' => 'My Cases'],
        ['url' => 'lawyer-clients.php', 'icon' => 'users', 'label' => 'My Clients'],
        ['url' => 'lawyer-appointments.php', 'icon' => 'calendar', 'label' => 'Appointments'],
        ['url' => 'lawyer-court-tracking.php', 'icon' => 'landmark', 'label' => 'Court Tracking'],
        ['url' => 'lawyer-availability.php', 'icon' => 'clock', 'label' => 'Availability'],
        ['url' => 'chatbot.php', 'icon' => 'bot', 'label' => 'AI Assistant'],
    ];

    $quickLinksHtml = '';
    foreach ($quickLinks as $link) {
        $icon = function_exists('legalpro_icon') ? legalpro_icon($link['icon']) : '';
        $quickLinksHtml .= '<a class="cs-quick-link" href="' . htmlspecialchars($link['url']) . '">'
            . '<span class="cs-quick-link__icon">' . $icon . '</span>'
            . '<span class="cs-quick-link__label">' . htmlspecialchars($link['label']) . '</span>'
            . '</a>';
    }

    $stat = static function (string $value, string $label): string {
        return '<div class="cs-stat"><span class="cs-stat__num">' . htmlspecialchars($value) . '</span><span class="cs-stat__lbl">' . htmlspecialchars($label) . '</span></div>';
    };

    $statsHtml = $stat((string) (int) ($snapshot['total_cases'] ?? 0), 'Cases')
        . $stat((string) (int) ($snapshot['active_cases'] ?? 0), 'Active')
        . $stat((string) (int) ($snapshot['total_clients'] ?? 0), 'Clients')
        . $stat((string) (int) ($snapshot['upcoming_appointments'] ?? 0), 'Upcoming')
        . $stat((string) (int) ($snapshot['open_tasks'] ?? 0), 'Open tasks')
        . $stat((string) (int) ($snapshot['unread_notifications'] ?? 0), 'Alerts');

    return '
    <div class="cs-hero mb-4">
        <div class="cs-hero__body">
            <p class="cs-hero__kicker">Settings</p>
            <h4 class="cs-hero__title">Personalize your portal</h4>
            <p class="cs-hero__sub">Manage appearance, review your account, and jump to common areas of the lawyer portal.</p>
        </div>
        <div class="cs-hero__meta">
            <span>Member since ' . $memberSince . '</span>
            <span>Theme: ' . $themeLabel . '</span>
        </div>
    </div>
    <div class="row g-4">
        <div class="col-lg-8">
            <form method="post" class="settings-theme-form">
                <input type="hidden" name="action" value="save_appearance">
                <div class="card mb-4">
                    <div class="card-header pb-0"><h6>Appearance</h6></div>
                    <div class="card-body">
                        <p class="text-sm text-muted mb-4">Choose light or dark mode for your lawyer portal. This applies only to your account.</p>
                        <div class="mb-0">
                            <label class="form-control-label d-block mb-2">Theme mode</label>
                            <div class="settings-theme-mode">
                                <label class="settings-theme-mode__option"><input type="radio" name="theme_mode" value="light"' . $lightChecked . '> Light</label>
                                <label class="settings-theme-mode__option"><input type="radio" name="theme_mode" value="dark"' . $darkChecked . '> Dark</label>
                            </div>
                        </div>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary mb-4">Save appearance</button>
            </form>
            <div class="card mb-4">
                <div class="card-header pb-0"><h6>Notifications</h6></div>
                <div class="card-body">
                    <p class="text-sm text-muted mb-3">Stay on top of case activity from the bell menu in the top navigation bar.</p>
                    <ul class="cs-tip-list text-sm text-muted mb-0">
                        <li>New appointments, documents, and case updates appear in your notification dropdown.</li>
                        <li>Click a notification to open the related case or page.</li>
                        <li>Use <strong>Mark all read</strong> to clear your unread count when you are caught up.</li>
                    </ul>
                </div>
            </div>
            <div class="card">
                <div class="card-header pb-0"><h6>Privacy &amp; security</h6></div>
                <div class="card-body">
                    <ul class="cs-tip-list text-sm text-muted mb-3">
                        <li>Never share your portal password with anyone, including colleagues or clients.</li>
                        <li>Sign out when using a shared or public device.</li>
                        <li>Keep your profile contact details current so clients and staff can reach you.</li>
                    </ul>
                    <a href="lawyer-profile.php" class="btn btn-outline-primary btn-sm mb-0">Manage profile &amp; password</a>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card mb-4">
                <div class="card-header pb-0"><h6>Your account</h6></div>
                <div class="card-body">
                    <p class="text-sm text-muted mb-3">Details tied to your lawyer portal login.</p>
                    <dl class="cs-account-dl mb-3">
                        <dt>Name</dt><dd>' . $displayName . '</dd>
                        <dt>Email</dt><dd>' . $email . '</dd>
                        <dt>Phone</dt><dd>' . $phone . '</dd>
                        <dt>Specialization</dt><dd>' . $specialization . '</dd>
                        <dt>Member since</dt><dd>' . $memberSince . '</dd>
                    </dl>
                    <a href="lawyer-profile.php" class="btn btn-outline-primary btn-sm mb-0 w-100">Edit profile</a>
                </div>
            </div>
            <div class="card mb-4">
                <div class="card-header pb-0"><h6>Portal overview</h6></div>
                <div class="card-body">
                    <div class="cs-stats-grid">' . $statsHtml . '</div>
                </div>
            </div>
            <div class="card mb-4">
                <div class="card-header pb-0"><h6>Quick links</h6></div>
                <div class="card-body">
                    <p class="text-sm text-muted mb-3">Jump to common areas of your lawyer portal.</p>
                    <div class="cs-quick-links">' . $quickLinksHtml . '</div>
                </div>
            </div>
            <div class="card">
                <div class="card-header pb-0"><h6>Your firm</h6></div>
                <div class="card-body">
                    <h6 class="mb-2">' . $firmName . '</h6>
                    ' . $firmDetailsHtml . '
                </div>
            </div>
        </div>
    </div>';
}

function renderClientPortalPreferencesHtml(int $clientId): string
{
    global $pdo;

    return renderClientPortalSettingsFullHtml($pdo instanceof PDO ? $pdo : null, $clientId);
}

function renderClientPortalSettingsFullHtml(?PDO $pdo, int $clientId): string
{
    if (!function_exists('getClientEmailDigest')) {
        $featuresPath = dirname(__DIR__, 2) . '/lib/client-portal-features.php';
        if (!is_file($featuresPath)) {
            $featuresPath = __DIR__ . '/client-portal-features.php';
        }
        if (is_file($featuresPath)) {
            require_once $featuresPath;
        }
    }

    if (!function_exists('legalpro_icon')) {
        $iconsPath = dirname(__DIR__) . '/inc/legalpro-icons.php';
        if (is_file($iconsPath)) {
            require_once $iconsPath;
        }
    }

    if (!function_exists('client_portal_render_hero')) {
        require_once __DIR__ . '/client-portal-page-ui.php';
    }

    if (!function_exists('getCompanyBranding')) {
        $brandingPath = __DIR__ . '/branding.php';
        if (is_file($brandingPath)) {
            require_once $brandingPath;
        }
    }

    $snapshot = function_exists('legalpro_client_settings_snapshot')
        ? legalpro_client_settings_snapshot($pdo, $clientId)
        : [];

    $currentMode = getClientPortalThemeMode($clientId);
    $lightChecked = $currentMode === 'light' ? ' checked' : '';
    $darkChecked = $currentMode === 'dark' ? ' checked' : '';
    $currentLocale = function_exists('getClientPortalLocale')
        ? getClientPortalLocale($clientId)
        : 'en';
    $locales = function_exists('getClientPortalLocales')
        ? getClientPortalLocales()
        : ['en' => 'English'];

    $localeOptions = '';
    foreach ($locales as $code => $label) {
        $selected = $code === $currentLocale ? ' selected' : '';
        $localeOptions .= '<option value="' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '"' . $selected . '>'
            . htmlspecialchars($label) . '</option>';
    }

    $t = static function (string $key, string $fallback): string {
        if (!function_exists('client_t')) {
            return $fallback;
        }
        $value = client_t($key);
        return $value !== $key ? $value : $fallback;
    };

    $displayName = htmlspecialchars(trim((string) ($snapshot['display_name'] ?? '')) ?: 'Client');
    $email = htmlspecialchars(trim((string) ($snapshot['email'] ?? '')) ?: '—');
    $phone = htmlspecialchars(trim((string) ($snapshot['phone'] ?? '')) ?: '—');
    $memberSince = htmlspecialchars(trim((string) ($snapshot['member_since'] ?? '')) ?: '—');
    $firm = function_exists('getCompanyBranding') ? getCompanyBranding() : ['name' => 'LegalPro', 'details' => ''];
    $firmName = htmlspecialchars((string) ($firm['name'] ?? 'LegalPro'));
    $firmDetails = trim((string) ($firm['details'] ?? ''));
    $firmDetailsHtml = $firmDetails !== ''
        ? '<p class="text-sm text-muted mb-0">' . nl2br(htmlspecialchars($firmDetails)) . '</p>'
        : '<p class="text-sm text-muted mb-0">' . htmlspecialchars($t('settings.firm_default', 'Contact your legal team for office hours and support.')) . '</p>';

    $outstanding = (float) ($snapshot['outstanding_balance'] ?? 0);
    $outstandingLabel = function_exists('formatCurrency')
        ? formatCurrency($outstanding)
        : '$' . number_format($outstanding, 2);

    $quickLinks = [
        ['url' => 'client-profile.php', 'icon' => 'user', 'label' => $t('nav.profile', 'Profile')],
        ['url' => 'client-cases.php', 'icon' => 'briefcase', 'label' => $t('nav.my_cases', 'My Cases')],
        ['url' => 'client-documents.php', 'icon' => 'file-text', 'label' => $t('nav.documents', 'Documents')],
        ['url' => 'client-appointments.php', 'icon' => 'calendar', 'label' => $t('nav.appointments', 'Appointments')],
        ['url' => 'client-payments.php', 'icon' => 'credit-card', 'label' => $t('nav.payments', 'Payments')],
        ['url' => 'client-court-tracking.php', 'icon' => 'landmark', 'label' => $t('nav.court_tracking', 'Court Tracking')],
        ['url' => 'chatbot.php', 'icon' => 'bot', 'label' => $t('nav.ai_assistant', 'AI Assistant')],
        ['url' => 'client-requests.php', 'icon' => 'message-circle', 'label' => $t('nav.my_requests', 'My requests')],
    ];

    $quickLinksHtml = '';
    foreach ($quickLinks as $link) {
        $icon = function_exists('legalpro_icon') ? legalpro_icon($link['icon']) : '';
        $quickLinksHtml .= '<a class="cs-quick-link" href="' . htmlspecialchars($link['url']) . '">'
            . '<span class="cs-quick-link__icon">' . $icon . '</span>'
            . '<span class="cs-quick-link__label">' . htmlspecialchars($link['label']) . '</span>'
            . '</a>';
    }

    $stat = static function (string $value, string $label): string {
        return '<div class="cs-stat"><span class="cs-stat__num">' . htmlspecialchars($value) . '</span><span class="cs-stat__lbl">' . htmlspecialchars($label) . '</span></div>';
    };

    $statsHtml = $stat((string) (int) ($snapshot['total_cases'] ?? 0), $t('settings.stat_cases', 'Cases'))
        . $stat((string) (int) ($snapshot['open_cases'] ?? 0), $t('settings.stat_open_cases', 'Active'))
        . $stat((string) (int) ($snapshot['unread_notifications'] ?? 0), $t('settings.stat_unread', 'Unread alerts'))
        . $stat((string) (int) ($snapshot['new_documents'] ?? 0), $t('settings.stat_new_docs', 'New docs'))
        . $stat((string) (int) ($snapshot['upcoming_appointments'] ?? 0), $t('settings.stat_upcoming_appts', 'Upcoming'))
        . $stat($outstandingLabel, $t('settings.stat_outstanding', 'Outstanding'));

    $heroHtml = client_portal_render_hero([
        'kicker' => $t('settings.title', 'Settings'),
        'title' => $t('settings.hero_title', 'Personalize your portal'),
        'subtitle' => $t('settings.hero_sub', 'Manage appearance and shortcuts for your client account.'),
        'meta' => $t('settings.member_since', 'Member since') . ' ' . $memberSince,
        'show_date' => true,
        'aria_label' => $t('settings.title', 'Settings'),
        'stats' => [
            ['num' => (string) (int) ($snapshot['total_cases'] ?? 0), 'lbl' => $t('settings.stat_cases', 'Cases')],
            ['num' => (string) (int) ($snapshot['open_cases'] ?? 0), 'lbl' => $t('settings.stat_open_cases', 'Active')],
            ['num' => (string) (int) ($snapshot['new_documents'] ?? 0), 'lbl' => $t('settings.stat_new_docs', 'New docs')],
            ['num' => $outstandingLabel, 'lbl' => $t('settings.stat_outstanding', 'Outstanding')],
        ],
        'actions' => [
            ['url' => 'client-profile.php', 'label' => $t('settings.edit_profile', 'Edit profile'), 'primary' => true, 'icon' => 'user'],
            ['url' => 'client-dashboard.php', 'label' => $t('nav.dashboard', 'Dashboard'), 'icon' => 'layout-dashboard'],
        ],
    ]);

    $appearanceBody = '
        <p class="text-sm text-muted mb-4">' . htmlspecialchars($t('settings.appearance_help', 'Choose light or dark mode and your preferred language.')) . '</p>
        <div class="mb-4">
            <label class="form-label d-block mb-2">' . htmlspecialchars($t('settings.theme_mode', 'Theme mode')) . '</label>
            <div class="settings-theme-mode">
                <label class="settings-theme-mode__option"><input type="radio" name="theme_mode" value="light"' . $lightChecked . '> ' . htmlspecialchars($t('settings.light', 'Light')) . '</label>
                <label class="settings-theme-mode__option"><input type="radio" name="theme_mode" value="dark"' . $darkChecked . '> ' . htmlspecialchars($t('settings.dark', 'Dark')) . '</label>
            </div>
        </div>
        <div class="mb-0">
            <label class="form-label d-block mb-2" for="client_locale">' . htmlspecialchars($t('settings.language_label', 'Display language')) . '</label>
            <select class="form-select" name="locale" id="client_locale" required>' . $localeOptions . '</select>
            <p class="text-xs text-muted mt-2 mb-0">' . htmlspecialchars($t('settings.language_help', 'Updates navigation labels and settings across the client portal.')) . '</p>
        </div>';

    $privacyBody = '
        <ul class="cs-tip-list mb-3">
            <li>' . htmlspecialchars($t('settings.privacy_tip_1', 'Never share your portal password with anyone, including firm staff.')) . '</li>
            <li>' . htmlspecialchars($t('settings.privacy_tip_2', 'Sign out when using a shared or public device.')) . '</li>
            <li>' . htmlspecialchars($t('settings.privacy_tip_3', 'Update your contact details on your profile so your firm can reach you.')) . '</li>
        </ul>
        <a href="client-profile.php" class="btn btn-outline-primary btn-sm mb-0">' . htmlspecialchars($t('settings.manage_profile', 'Manage profile & password')) . '</a>';

    $accountBody = '
        <p class="text-sm text-muted mb-3">' . htmlspecialchars($t('settings.account_help', 'Basic details tied to your client portal login.')) . '</p>
        <dl class="cs-account-dl mb-3">
            <dt>' . htmlspecialchars($t('settings.account_name', 'Name')) . '</dt><dd>' . $displayName . '</dd>
            <dt>' . htmlspecialchars($t('settings.account_email', 'Email')) . '</dt><dd>' . $email . '</dd>
            <dt>' . htmlspecialchars($t('settings.account_phone', 'Phone')) . '</dt><dd>' . $phone . '</dd>
            <dt>' . htmlspecialchars($t('settings.member_since', 'Member since')) . '</dt><dd>' . $memberSince . '</dd>
        </dl>
        <a href="client-profile.php" class="btn btn-outline-primary btn-sm mb-0 w-100">' . htmlspecialchars($t('settings.edit_profile', 'Edit profile')) . '</a>';

    $overviewBody = '<div class="cs-stats-grid">' . $statsHtml . '</div>';

    $quickLinksBody = '
        <p class="text-sm text-muted mb-3">' . htmlspecialchars($t('settings.quick_links_help', 'Jump to common areas of your client portal.')) . '</p>
        <div class="cs-quick-links">' . $quickLinksHtml . '</div>';

    $firmBody = '<h6 class="mb-2">' . $firmName . '</h6>' . $firmDetailsHtml;

    return '
    <div class="cp-page">
        ' . $heroHtml . '
        <div class="cp-account-layout">
            <div class="cp-panel-stack">
                <form method="post" class="client-settings-form cp-panel-stack">
                    <input type="hidden" name="action" value="save_preferences">
                    ' . client_portal_render_panel([
                        'title' => $t('settings.appearance', 'Appearance & language'),
                        'subtitle' => $t('settings.appearance_help', 'Choose light or dark mode and your preferred language.'),
                        'icon' => 'palette',
                    ], $appearanceBody) . '
                    <div class="cp-form-actions">
                        <button type="submit" class="btn btn-primary">' . htmlspecialchars($t('settings.save_preferences', 'Save preferences')) . '</button>
                    </div>
                </form>
                ' . client_portal_render_panel([
                    'title' => $t('settings.privacy_security', 'Privacy & security'),
                    'subtitle' => $t('settings.privacy_tip_1', 'Never share your portal password with anyone, including firm staff.'),
                    'icon' => 'shield',
                ], $privacyBody) . '
            </div>
            <div class="cp-panel-stack">
                ' . client_portal_render_panel([
                    'title' => $t('settings.account', 'Your account'),
                    'subtitle' => $t('settings.account_help', 'Basic details tied to your client portal login.'),
                    'icon' => 'user',
                ], $accountBody) . '
                ' . client_portal_render_panel([
                    'title' => $t('settings.portal_overview', 'Portal overview'),
                    'subtitle' => $t('settings.quick_links_help', 'Jump to common areas of your client portal.'),
                    'icon' => 'layout-grid',
                ], $overviewBody) . '
                ' . client_portal_render_panel([
                    'title' => $t('settings.quick_links', 'Quick links'),
                    'subtitle' => $t('settings.quick_links_help', 'Jump to common areas of your client portal.'),
                    'icon' => 'link',
                ], $quickLinksBody) . '
                ' . client_portal_render_panel([
                    'title' => $t('settings.your_firm', 'Your firm'),
                    'subtitle' => $t('settings.firm_default', 'Contact your legal team for office hours and support.'),
                    'icon' => 'building-2',
                ], $firmBody) . '
            </div>
        </div>
    </div>';
}

function renderPortalThemeDarkCss(string $primary, string $rgb): string
{
    $soft12 = portalThemeHexToRgba($primary, 0.12);
    $soft20 = portalThemeHexToRgba($primary, 0.2);
    $soft35 = portalThemeHexToRgba($primary, 0.35);
    $primaryOnDark = portalThemeMixHex($primary, '#ffffff', 0.55);
    $rgbParts = array_map('intval', explode(',', $rgb));
    $primaryDark = portalThemeHexFromRgb(
        min(255, $rgbParts[0] + 36),
        min(255, max(0, $rgbParts[1] - 1)),
        min(255, $rgbParts[2])
    );
    $gradient310 = 'linear-gradient(310deg, ' . $primary . ' 0%, ' . $primaryDark . ' 100%)';

    $bodies = 'body.legalpro-dark-mode,'
        . 'body.legalpro-dark-mode.legalpro-admin-portal,'
        . 'body.legalpro-dark-mode.legalpro-client-portal,'
        . 'body.legalpro-dark-mode.legalpro-lawyer-portal,'
        . 'body.legalpro-dark-mode.client-dashboard-page,'
        . 'body.legalpro-dark-mode.client-cases-page,'
        . 'body.legalpro-dark-mode.client-appointments-page,'
        . 'body.legalpro-dark-mode.client-court-tracking-page,'
        . 'body.legalpro-dark-mode.client-payments-page,'
        . 'body.legalpro-dark-mode.client-portal-page,'
        . 'body.legalpro-dark-mode.client-profile-page,'
        . 'body.legalpro-dark-mode.client-chatbot-page,'
        . 'body.legalpro-dark-mode.lawyer-dashboard-page,'
        . 'body.legalpro-dark-mode.lawyer-cases-page,'
        . 'body.legalpro-dark-mode.lawyer-clients-page,'
        . 'body.legalpro-dark-mode.lawyer-appointments-page,'
        . 'body.legalpro-dark-mode.lawyer-availability-page,'
        . 'body.legalpro-dark-mode.lawyer-case-view-page,'
        . 'body.legalpro-dark-mode.admin-case-view-page,'
        . 'body.legalpro-dark-mode.lawyer-client-view-page,'
        . 'body.legalpro-dark-mode.lawyer-court-tracking-page,'
        . 'body.legalpro-dark-mode.lawyer-tasks-page,'
        . 'body.legalpro-dark-mode.lawyer-profile-page,'
        . 'body.legalpro-dark-mode.lawyer-settings-page,'
        . 'body.legalpro-dark-mode.admin-court-tracking-page';

    $css = 'html.legalpro-theme-dark { background: #2a3040; }';

    $css .= ':root {'
        . '--lp-dark-bg: #2a3040;'
        . '--lp-dark-surface: #343b4f;'
        . '--lp-dark-surface-raised: #3d455c;'
        . '--lp-dark-surface-hover: #464f68;'
        . '--lp-dark-border: rgba(255, 255, 255, 0.1);'
        . '--lp-dark-border-strong: rgba(255, 255, 255, 0.16);'
        . '--lp-dark-text: #f8f9fc;'
        . '--lp-dark-text-secondary: #e2e8f2;'
        . '--lp-dark-text-muted: #d4dcea;'
        . '--lp-dark-text-subtle: #aeb9cb;'
        . '--lp-dark-input-bg: #2f3547;'
        . '--lp-admin-content-bg: #2a3040;'
        . '--lp-portal-content-bg: #2a3040;'
        . '--client-portal-content-bg: #2a3040;'
        . '--lawyer-portal-content-bg: #2a3040;'
        . '--lp-task-card-bg: #3d455c;'
        . '--lp-task-card-border: rgba(255, 255, 255, 0.1);'
        . '--lp-task-card-body-bg: #3d455c;'
        . '}';

    $css .= $bodies . ' {'
        . 'background: linear-gradient(180deg, #2e3446 0%, #2a3040 45%, #272c3c 100%) !important;'
        . 'background-color: var(--lp-dark-bg) !important;'
        . 'color: var(--lp-dark-text-secondary) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.bg-gray-100 { background: var(--lp-dark-bg) !important; }';

    $css .= 'body.legalpro-dark-mode .main-content .card,'
        . 'body.legalpro-dark-mode .card,'
        . 'body.legalpro-dark-mode .dashboard-calendar-hub,'
        . 'body.legalpro-dark-mode .dashboard-stat-card,'
        . 'body.legalpro-dark-mode .dashboard-glance__item,'
        . 'body.legalpro-dark-mode .modal-content,'
        . 'body.legalpro-dark-mode .swal2-popup {'
        . 'background-color: var(--lp-dark-surface) !important;'
        . 'border-color: var(--lp-dark-border) !important;'
        . 'box-shadow: 0 4px 20px rgba(15, 20, 35, 0.18) !important;'
        . 'color: var(--lp-dark-text-secondary) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .card .card-header,'
        . 'body.legalpro-dark-mode .card-header {'
        . 'background: transparent !important;'
        . 'border-bottom-color: var(--lp-dark-border) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .card h1, body.legalpro-dark-mode .card h2,'
        . 'body.legalpro-dark-mode .card h3, body.legalpro-dark-mode .card h4,'
        . 'body.legalpro-dark-mode .card h5, body.legalpro-dark-mode .card h6,'
        . 'body.legalpro-dark-mode .card .h1, body.legalpro-dark-mode .card .h2,'
        . 'body.legalpro-dark-mode .card .h3, body.legalpro-dark-mode .card .h4,'
        . 'body.legalpro-dark-mode .card .h5, body.legalpro-dark-mode .card .h6,'
        . 'body.legalpro-dark-mode h1, body.legalpro-dark-mode h2,'
        . 'body.legalpro-dark-mode h3, body.legalpro-dark-mode h4,'
        . 'body.legalpro-dark-mode h5, body.legalpro-dark-mode h6,'
        . 'body.legalpro-dark-mode .h1, body.legalpro-dark-mode .h2,'
        . 'body.legalpro-dark-mode .h3, body.legalpro-dark-mode .h4,'
        . 'body.legalpro-dark-mode .h5, body.legalpro-dark-mode .h6,'
        . 'body.legalpro-dark-mode .text-dark,'
        . 'body.legalpro-dark-mode .font-weight-bolder,'
        . 'body.legalpro-dark-mode .font-weight-bold,'
        . 'body.legalpro-dark-mode .legalpro-page-toolbar__title,'
        . 'body.legalpro-dark-mode .cc-comment-author,'
        . 'body.legalpro-dark-mode .dashboard-cal-event__text {'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .text-muted,'
        . 'body.legalpro-dark-mode .text-secondary,'
        . 'body.legalpro-dark-mode .text-xs.text-muted,'
        . 'body.legalpro-dark-mode .text-sm.text-muted,'
        . 'body.legalpro-dark-mode .legalpro-page-toolbar__subtitle,'
        . 'body.legalpro-dark-mode .cc-comment-time,'
        . 'body.legalpro-dark-mode .footer .copyright,'
        . 'body.legalpro-dark-mode .form-control-label,'
        . 'body.legalpro-dark-mode label {'
        . 'color: var(--lp-dark-text-muted) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .text-body,'
        . 'body.legalpro-dark-mode .card p,'
        . 'body.legalpro-dark-mode .card li,'
        . 'body.legalpro-dark-mode .card span:not(.badge):not(.ca-status-pill):not(.lp-pill),'
        . 'body.legalpro-dark-mode .cc-comment-text,'
        . 'body.legalpro-dark-mode .table td,'
        . 'body.legalpro-dark-mode .table tbody td {'
        . 'color: var(--lp-dark-text-secondary) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .text-success,'
        . 'body.legalpro-dark-mode td.text-success,'
        . 'body.legalpro-dark-mode .text-success.fw-bold,'
        . 'body.legalpro-dark-mode .text-success.fw-semibold {'
        . 'color: #6ee7b7 !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .text-warning,'
        . 'body.legalpro-dark-mode td.text-warning,'
        . 'body.legalpro-dark-mode .text-warning.fw-bold,'
        . 'body.legalpro-dark-mode .text-warning.fw-semibold {'
        . 'color: #fdba74 !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .text-danger,'
        . 'body.legalpro-dark-mode td.text-danger {'
        . 'color: #fca5a5 !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .text-info,'
        . 'body.legalpro-dark-mode td.text-info {'
        . 'color: #7dd3fc !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .table tbody td strong,'
        . 'body.legalpro-dark-mode .table tbody td .fw-bold,'
        . 'body.legalpro-dark-mode .table tbody td .fw-semibold,'
        . 'body.legalpro-dark-mode .card strong:not(.lp-pill):not(.badge),'
        . 'body.legalpro-dark-mode .card .fw-bold:not(.lp-pill):not(.badge),'
        . 'body.legalpro-dark-mode .card .fw-semibold:not(.lp-pill):not(.badge),'
        . 'body.legalpro-dark-mode .list-group-item .fw-bold,'
        . 'body.legalpro-dark-mode .list-group-item .fw-semibold,'
        . 'body.legalpro-dark-mode .list-group-item strong {'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .opacity-7 {'
        . 'opacity: 1 !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .navbar-main,'
        . 'body.legalpro-dark-mode #navbarBlur,'
        . 'body.legalpro-dark-mode .legalpro-page-navbar {'
        . 'background: var(--lp-dark-surface) !important;'
        . 'background-color: var(--lp-dark-surface) !important;'
        . 'box-shadow: 0 1px 0 var(--lp-dark-border) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .navbar-main h6,'
        . 'body.legalpro-dark-mode .navbar-main .font-weight-bolder,'
        . 'body.legalpro-dark-mode .navbar-main .text-white,'
        . 'body.legalpro-dark-mode .legalpro-page-navbar h6,'
        . 'body.legalpro-dark-mode .legalpro-page-navbar .font-weight-bolder {'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .navbar-main .dashboard-welcome-sub,'
        . 'body.legalpro-dark-mode .legalpro-page-navbar .text-muted {'
        . 'color: var(--lp-dark-text-muted) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .form-control,'
        . 'body.legalpro-dark-mode .form-select,'
        . 'body.legalpro-dark-mode textarea.form-control,'
        . 'body.legalpro-dark-mode input.form-control,'
        . 'body.legalpro-dark-mode .input-group-text {'
        . 'background-color: var(--lp-dark-input-bg) !important;'
        . 'border-color: var(--lp-dark-border-strong) !important;'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .form-control::placeholder,'
        . 'body.legalpro-dark-mode textarea::placeholder {'
        . 'color: var(--lp-dark-text-subtle) !important;'
        . 'opacity: 1 !important;'
        . '}';

    $css .= '.legalpro-form-panel {'
        . 'background: rgba(103, 116, 142, 0.06);'
        . 'border-color: rgba(0, 0, 0, 0.08) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .legalpro-form-panel {'
        . 'background: var(--lp-dark-surface-raised) !important;'
        . 'border-color: var(--lp-dark-border-strong) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .legalpro-form-panel h6,'
        . 'body.legalpro-dark-mode .legalpro-form-panel .form-label,'
        . 'body.legalpro-dark-mode .legalpro-form-panel .form-control-label,'
        . 'body.legalpro-dark-mode .legalpro-form-panel .form-check-label,'
        . 'body.legalpro-dark-mode .legalpro-form-panel p:not(.text-muted) {'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .legalpro-form-panel .text-muted,'
        . 'body.legalpro-dark-mode .legalpro-form-panel small,'
        . 'body.legalpro-dark-mode .legalpro-form-panel p.text-muted {'
        . 'color: var(--lp-dark-text-muted) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .legalpro-password-requirements,'
        . 'body.legalpro-dark-mode .legalpro-password-requirements-label {'
        . 'color: var(--lp-dark-text-muted) !important;'
        . '}';

    $css .= '.legalpro-client-user-check.form-check-input::after {'
        . 'content: none !important;'
        . 'display: none !important;'
        . '}';

    $css .= '.legalpro-client-user-check.form-check-input:not(:checked) {'
        . 'background-color: #fff !important;'
        . 'background-image: none !important;'
        . 'border: 1px solid #d2d6da !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .legalpro-client-user-check.form-check-input:not(:checked) {'
        . 'background-color: var(--lp-dark-input-bg) !important;'
        . 'border-color: var(--lp-dark-border-strong) !important;'
        . '}';

    $css .= '.legalpro-client-user-check.form-check-input:checked {'
        . 'background-color: ' . $primary . ' !important;'
        . 'background-image: url("data:image/svg+xml,%3csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 20 20\'%3e%3cpath fill=\'none\' stroke=\'%23fff\' stroke-linecap=\'round\' stroke-linejoin=\'round\' stroke-width=\'3\' d=\'M6 10l3 3 8-8\'/%3e%3c/svg%3e") !important;'
        . 'background-size: 75% 75% !important;'
        . 'background-position: center !important;'
        . 'background-repeat: no-repeat !important;'
        . 'border: 0 !important;'
        . '}';

    $clientSearch = 'body.legalpro-dark-mode.legalpro-client-portal .navbar-main .legalpro-navbar-search .input-group,'
        . 'body.legalpro-dark-mode.legalpro-client-portal .search-hero-field,'
        . 'body.legalpro-dark-mode .legalpro-navbar-search .input-group,'
        . 'body.legalpro-dark-mode .search-portal-page .search-hero-field';

    $css .= $clientSearch . ' {'
        . 'background: var(--lp-dark-input-bg) !important;'
        . 'border-color: var(--lp-dark-border-strong) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.legalpro-client-portal .navbar-main .legalpro-navbar-search .input-group:hover,'
        . 'body.legalpro-dark-mode.legalpro-client-portal .search-hero-field:hover,'
        . 'body.legalpro-dark-mode .legalpro-navbar-search .input-group:hover,'
        . 'body.legalpro-dark-mode .search-portal-page .search-hero-field:hover {'
        . 'border-color: ' . $soft20 . ' !important;'
        . 'background: var(--lp-dark-surface-hover) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.legalpro-client-portal .navbar-main .legalpro-navbar-search .input-group:focus-within,'
        . 'body.legalpro-dark-mode.legalpro-client-portal .search-hero-field:focus-within,'
        . 'body.legalpro-dark-mode .legalpro-navbar-search .input-group:focus-within,'
        . 'body.legalpro-dark-mode .search-portal-page .search-hero-field:focus-within {'
        . 'border-color: ' . $primary . ' !important;'
        . 'box-shadow: 0 0 0 0.2rem rgba(' . $rgb . ', 0.18) !important;'
        . 'background: var(--lp-dark-input-bg) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.legalpro-client-portal .navbar-main .legalpro-navbar-search .input-group-text,'
        . 'body.legalpro-dark-mode.legalpro-client-portal .navbar-main .legalpro-navbar-search .form-control,'
        . 'body.legalpro-dark-mode.legalpro-client-portal .search-hero-field .input-group-text,'
        . 'body.legalpro-dark-mode.legalpro-client-portal .search-hero-field .form-control,'
        . 'body.legalpro-dark-mode .legalpro-navbar-search .input-group-text,'
        . 'body.legalpro-dark-mode .legalpro-navbar-search .form-control,'
        . 'body.legalpro-dark-mode .search-portal-page .search-hero-field .input-group-text,'
        . 'body.legalpro-dark-mode .search-portal-page .search-hero-field .form-control {'
        . 'background: transparent !important;'
        . 'border-color: transparent !important;'
        . 'box-shadow: none !important;'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.admin-cases-page .legalpro-cases-search .form-control:hover {'
        . 'border-color: ' . $soft20 . ' !important;'
        . 'background: var(--lp-dark-surface-hover) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.admin-cases-page .legalpro-cases-search .form-control:focus {'
        . 'border-color: ' . $primary . ' !important;'
        . 'box-shadow: 0 0 0 0.2rem rgba(' . $rgb . ', 0.18) !important;'
        . 'background: var(--lp-dark-input-bg) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .legalpro-admin-list-search .form-control {'
        . 'background: var(--lp-dark-input-bg) !important;'
        . 'border-color: var(--lp-dark-border) !important;'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .legalpro-admin-list-search .form-control:hover {'
        . 'border-color: ' . $soft20 . ' !important;'
        . 'background: var(--lp-dark-surface-hover) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .legalpro-admin-list-search .form-control:focus {'
        . 'border-color: ' . $primary . ' !important;'
        . 'box-shadow: 0 0 0 0.2rem rgba(' . $rgb . ', 0.18) !important;'
        . 'background: var(--lp-dark-input-bg) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .table thead th,'
        . 'body.legalpro-dark-mode .table thead td {'
        . 'background: var(--lp-dark-surface-raised) !important;'
        . 'color: var(--lp-dark-text-muted) !important;'
        . 'border-color: var(--lp-dark-border) !important;'
        . 'opacity: 1 !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .table thead .text-secondary,'
        . 'body.legalpro-dark-mode .table thead .text-uppercase {'
        . 'color: var(--lp-dark-text-muted) !important;'
        . 'opacity: 1 !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .table tbody td .font-weight-bold,'
        . 'body.legalpro-dark-mode .table tbody td .text-sm.font-weight-bold,'
        . 'body.legalpro-dark-mode .table tbody td .text-sm.fw-bold {'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .table > :not(caption) > * > * {'
        . 'border-color: var(--lp-dark-border) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .list-group-item {'
        . 'background: var(--lp-dark-surface) !important;'
        . 'border-color: var(--lp-dark-border) !important;'
        . 'color: var(--lp-dark-text-secondary) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .cc-comment-item-inner {'
        . 'background: var(--lp-dark-surface-raised) !important;'
        . 'border-color: var(--lp-dark-border) !important;'
        . 'box-shadow: none !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .cc-comment-item--yours .cc-comment-item-inner {'
        . 'background: ' . $soft12 . ' !important;'
        . 'border-color: ' . $soft20 . ' !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .dropdown-menu {'
        . 'background: var(--lp-dark-surface-raised) !important;'
        . 'border-color: var(--lp-dark-border) !important;'
        . 'box-shadow: 0 12px 40px rgba(0, 0, 0, 0.45) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .dropdown-item {'
        . 'color: var(--lp-dark-text-secondary) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .dropdown-item:hover,'
        . 'body.legalpro-dark-mode .dropdown-item:focus {'
        . 'background: var(--lp-dark-surface-hover) !important;'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .modal-header,'
        . 'body.legalpro-dark-mode .modal-footer {'
        . 'border-color: var(--lp-dark-border) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .modal-title {'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .btn-close {'
        . 'filter: invert(1) grayscale(100%) brightness(200%);'
        . '}';

    $css .= 'body.legalpro-dark-mode .btn-white,'
        . 'body.legalpro-dark-mode .btn-light {'
        . 'background: var(--lp-dark-surface-raised) !important;'
        . 'border-color: var(--lp-dark-border-strong) !important;'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .btn-secondary,'
        . 'body.legalpro-dark-mode .btn-outline-secondary {'
        . 'color: var(--lp-dark-text) !important;'
        . 'background-color: var(--lp-dark-input-bg) !important;'
        . 'border-color: var(--lp-dark-border-strong) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .btn-secondary:hover,'
        . 'body.legalpro-dark-mode .btn-secondary:focus,'
        . 'body.legalpro-dark-mode .btn-outline-secondary:hover,'
        . 'body.legalpro-dark-mode .btn-outline-secondary:focus {'
        . 'background-color: var(--lp-dark-surface-hover) !important;'
        . 'color: #fff !important;'
        . 'border-color: var(--lp-dark-border-strong) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .legalpro-form-panel .btn-secondary,'
        . 'body.legalpro-dark-mode .modal-content .btn-secondary,'
        . 'body.legalpro-dark-mode .modal-footer .btn-secondary {'
        . 'background-color: #252b3d !important;'
        . 'border: 1px solid rgba(255, 255, 255, 0.2) !important;'
        . 'color: #f8f9fc !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .legalpro-form-panel .btn-secondary:hover,'
        . 'body.legalpro-dark-mode .legalpro-form-panel .btn-secondary:focus,'
        . 'body.legalpro-dark-mode .modal-content .btn-secondary:hover,'
        . 'body.legalpro-dark-mode .modal-content .btn-secondary:focus,'
        . 'body.legalpro-dark-mode .modal-footer .btn-secondary:hover,'
        . 'body.legalpro-dark-mode .modal-footer .btn-secondary:focus {'
        . 'background-color: var(--lp-dark-surface-hover) !important;'
        . 'color: #fff !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .btn-dark,'
        . 'body.legalpro-dark-mode .btn-outline-dark {'
        . 'color: var(--lp-dark-text) !important;'
        . 'background: var(--lp-dark-surface-raised) !important;'
        . 'border: 1px solid var(--lp-dark-border-strong) !important;'
        . 'box-shadow: none !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .btn-dark:hover,'
        . 'body.legalpro-dark-mode .btn-dark:focus,'
        . 'body.legalpro-dark-mode .btn-outline-dark:hover,'
        . 'body.legalpro-dark-mode .btn-outline-dark:focus {'
        . 'background: var(--lp-dark-surface-hover) !important;'
        . 'color: #fff !important;'
        . 'border-color: var(--lp-dark-border-strong) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.legalpro-admin-portal .btn-dark,'
        . 'body.legalpro-dark-mode.legalpro-admin-portal .btn-outline-dark {'
        . 'background: var(--lp-dark-surface-raised) !important;'
        . 'border: 1px solid var(--lp-dark-border-strong) !important;'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.legalpro-admin-portal .btn-dark:hover,'
        . 'body.legalpro-dark-mode.legalpro-admin-portal .btn-dark:focus,'
        . 'body.legalpro-dark-mode.legalpro-admin-portal .btn-outline-dark:hover,'
        . 'body.legalpro-dark-mode.legalpro-admin-portal .btn-outline-dark:focus {'
        . 'background: var(--lp-dark-surface-hover) !important;'
        . 'color: #fff !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .btn-primary,'
        . 'body.legalpro-dark-mode .btn-danger,'
        . 'body.legalpro-dark-mode .btn-success,'
        . 'body.legalpro-dark-mode .btn-info,'
        . 'body.legalpro-dark-mode .btn-warning,'
        . 'body.legalpro-dark-mode .btn.bg-gradient-primary,'
        . 'body.legalpro-dark-mode .btn.bg-gradient-danger,'
        . 'body.legalpro-dark-mode .btn.bg-gradient-success,'
        . 'body.legalpro-dark-mode .btn.bg-gradient-info,'
        . 'body.legalpro-dark-mode .btn.bg-gradient-warning,'
        . 'body.legalpro-dark-mode .btn.bg-gradient-dark {'
        . 'color: #fff !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .btn-outline-primary,'
        . 'body.legalpro-dark-mode .btn-outline-danger,'
        . 'body.legalpro-dark-mode .btn-outline-success,'
        . 'body.legalpro-dark-mode .btn-outline-info,'
        . 'body.legalpro-dark-mode .btn-outline-warning {'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .fc .fc-toolbar.fc-header-toolbar .fc-button,'
        . 'body.legalpro-dark-mode #dashboardCalendar .fc .fc-toolbar.fc-header-toolbar .fc-button,'
        . 'body.legalpro-dark-mode #courtTrackingCalendar .fc .fc-toolbar.fc-header-toolbar .fc-button {'
        . 'color: #fff !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.legalpro-admin-portal .dashboard-quick-actions .btn-outline-white {'
        . 'background: transparent !important;'
        . 'border-color: ' . portalThemeHexToRgba($primary, 0.55) . ' !important;'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.legalpro-admin-portal .dashboard-quick-actions .btn-outline-white:hover,'
        . 'body.legalpro-dark-mode.legalpro-admin-portal .dashboard-quick-actions .btn-outline-white:focus {'
        . 'background: ' . portalThemeHexToRgba($primary, 0.18) . ' !important;'
        . 'border-color: ' . portalThemeHexToRgba($primary, 0.7) . ' !important;'
        . 'color: #fff !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.legalpro-admin-portal .dashboard-quick-actions .btn-white {'
        . 'background: ' . $primary . ' !important;'
        . 'border-color: ' . $primary . ' !important;'
        . 'color: #fff !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode hr,'
        . 'body.legalpro-dark-mode hr.horizontal,'
        . 'body.legalpro-dark-mode .border-top,'
        . 'body.legalpro-dark-mode .border-bottom {'
        . 'border-color: var(--lp-dark-border) !important;'
        . 'opacity: 1 !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .legalpro-sidebar-nav .nav-link:not(.active) {'
        . 'color: rgba(255, 255, 255, 0.72) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .legalpro-sidebar-nav .nav-link:hover:not(.active) {'
        . 'color: #fff !important;'
        . 'background: rgba(255, 255, 255, 0.06) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .fc .fc-scrollgrid,'
        . 'body.legalpro-dark-mode .fc-theme-standard td,'
        . 'body.legalpro-dark-mode .fc-theme-standard th {'
        . 'background: var(--lp-dark-surface) !important;'
        . 'border-color: var(--lp-dark-border) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .fc .fc-daygrid-day-number,'
        . 'body.legalpro-dark-mode .fc .fc-col-header-cell-cushion,'
        . 'body.legalpro-dark-mode .fc .fc-list-day-text,'
        . 'body.legalpro-dark-mode .fc .fc-list-day-side-text {'
        . 'color: var(--lp-dark-text-secondary) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .fc .fc-day-other .fc-daygrid-day-number {'
        . 'color: var(--lp-dark-text-subtle) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .fc .fc-list-event-title,'
        . 'body.legalpro-dark-mode .fc .fc-list-event-time,'
        . 'body.legalpro-dark-mode .fc .fc-list-event-graphic + td {'
        . 'color: var(--lp-dark-text-secondary) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .navbar-main .breadcrumb-item,'
        . 'body.legalpro-dark-mode .navbar-main .breadcrumb-item a,'
        . 'body.legalpro-dark-mode .navbar-main .breadcrumb-item.active {'
        . 'color: var(--lp-dark-text-secondary) !important;'
        . 'opacity: 1 !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .modal-body,'
        . 'body.legalpro-dark-mode .modal-body p,'
        . 'body.legalpro-dark-mode .modal-body span:not(.badge):not(.lp-pill):not(.ca-status-pill):not(.ca-badge) {'
        . 'color: var(--lp-dark-text-secondary) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .modal-body strong {'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .fc .fc-day-today {'
        . 'background: ' . portalThemeHexToRgba($primary, 0.1) . ' !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .alert {'
        . 'border-color: var(--lp-dark-border-strong) !important;'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .alert-success {'
        . 'background: rgba(45, 206, 137, 0.16) !important;'
        . 'color: #b8f5d8 !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .alert-danger {'
        . 'background: rgba(245, 54, 92, 0.16) !important;'
        . 'color: #ffc9d4 !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .alert-warning {'
        . 'background: rgba(251, 140, 64, 0.16) !important;'
        . 'color: #ffe0b8 !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .alert-info {'
        . 'background: rgba(17, 205, 239, 0.16) !important;'
        . 'color: #b8efff !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .settings-theme-mode__option {'
        . 'background: var(--lp-dark-surface-raised) !important;'
        . 'border-color: var(--lp-dark-border) !important;'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .settings-theme-swatch__label {'
        . 'color: var(--lp-dark-text-muted) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .ps__thumb-y {'
        . 'background: #4a5568 !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode a:not(.btn):not(.nav-link):not(.dropdown-item):not(.badge):not(.legalpro-doc-subnav__link) {'
        . 'color: ' . $primary . ';'
        . '}';

    $css .= 'body.legalpro-dark-mode .text-muted a:not(.btn):not(.legalpro-doc-subnav__link),'
        . 'body.legalpro-dark-mode .modal-content a:not(.btn):not(.nav-link):not(.dropdown-item):not(.legalpro-doc-subnav__link) {'
        . 'color: ' . $primary . ' !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .text-primary,'
        . 'body.legalpro-dark-mode a.text-primary {'
        . 'color: ' . $primary . ' !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .bg-white,'
        . 'body.legalpro-dark-mode .dashboard-upcoming-panel,'
        . 'body.legalpro-dark-mode .legalpro-header-search .form-control {'
        . 'background-color: var(--lp-dark-surface-raised) !important;'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .dashboard-stat-card,'
        . 'body.legalpro-dark-mode .dashboard-glance__item {'
        . 'border: none !important;'
        . 'outline: none !important;'
        . 'background-color: var(--lp-dark-surface) !important;'
        . 'background: var(--lp-dark-surface) !important;'
        . 'box-shadow: 0 4px 18px rgba(0, 0, 0, 0.22) !important;'
        . 'overflow: hidden;'
        . '}';

    $css .= 'body.legalpro-dark-mode .dashboard-stat-card .card-body {'
        . 'border: none !important;'
        . 'background-color: var(--lp-dark-surface) !important;'
        . 'background: var(--lp-dark-surface) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .dashboard-glance {'
        . 'background: transparent !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .dashboard-glance__value {'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .dashboard-glance__label {'
        . 'color: var(--lp-dark-text-muted) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.legalpro-dashboard-page .lp-section-hd,'
        . 'body.legalpro-dark-mode.legalpro-dashboard-page .dashboard-upcoming-panel__title,'
        . 'body.legalpro-dark-mode.legalpro-dashboard-page .dashboard-upcoming-panel__title > span {'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.legalpro-dashboard-page .lp-section-sub,'
        . 'body.legalpro-dark-mode.legalpro-dashboard-page .dashboard-upcoming-item__sub,'
        . 'body.legalpro-dark-mode.legalpro-dashboard-page .dashboard-upcoming-empty,'
        . 'body.legalpro-dark-mode.legalpro-dashboard-page .dashboard-upcoming-item__time small {'
        . 'color: var(--lp-dark-text-muted) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.legalpro-dashboard-page .dashboard-upcoming-item__title {'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.legalpro-dashboard-page .dashboard-upcoming-panel__title {'
        . 'border-bottom-color: var(--lp-dark-border) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.legalpro-dashboard-page .dashboard-upcoming-panel__title a {'
        . 'color: ' . $primaryOnDark . ' !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.legalpro-dashboard-page .dashboard-upcoming-item:hover {'
        . 'background: var(--lp-dark-surface-hover) !important;'
        . 'border-color: var(--lp-dark-border) !important;'
        . 'box-shadow: none !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .dashboard-upcoming-panel__title,'
        . 'body.legalpro-dark-mode .dashboard-upcoming-panel__title > span {'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .dashboard-upcoming-item {'
        . 'background: var(--lp-dark-surface-raised) !important;'
        . 'border-color: var(--lp-dark-border) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .dashboard-upcoming-item__time {'
        . 'color: #9aaeff !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .dashboard-upcoming-item__time small {'
        . 'color: var(--lp-dark-text-muted) !important;'
        . 'opacity: 1 !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .dashboard-upcoming-item__title {'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .dashboard-upcoming-item__sub {'
        . 'color: var(--lp-dark-text-muted) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .dashboard-upcoming-item:hover {'
        . 'background: var(--lp-dark-surface-hover) !important;'
        . 'border-color: var(--lp-dark-border) !important;'
        . 'box-shadow: none !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .dashboard-upcoming-item--scheduled .dashboard-upcoming-item__time {'
        . 'color: #7dd3fc !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .dashboard-upcoming-item--completed .dashboard-upcoming-item__time {'
        . 'color: #6ee7b7 !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .dashboard-upcoming-item--postponed .dashboard-upcoming-item__time {'
        . 'color: #fbbf24 !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .dashboard-upcoming-item--cancelled .dashboard-upcoming-item__time,'
        . 'body.legalpro-dark-mode .dashboard-upcoming-item--rejected .dashboard-upcoming-item__time {'
        . 'color: #f87171 !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .dashboard-upcoming-item--pending .dashboard-upcoming-item__time,'
        . 'body.legalpro-dark-mode .dashboard-upcoming-item--accepted .dashboard-upcoming-item__time,'
        . 'body.legalpro-dark-mode .dashboard-upcoming-item--approved .dashboard-upcoming-item__time {'
        . 'color: #6ee7b7 !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.legalpro-dashboard-page .dashboard-calendar-hub__head {'
        . 'border-bottom-color: var(--lp-dark-border) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.legalpro-dashboard-page .dashboard-stat-card .numbers p.text-sm,'
        . 'body.legalpro-dark-mode.legalpro-dashboard-page .dashboard-stat-card .numbers h5 {'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.legalpro-dashboard-page .dashboard-stat-card .numbers p.mb-0 {'
        . 'color: var(--lp-dark-text-secondary) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.legalpro-dashboard-page .dashboard-stat-card .text-info {'
        . 'color: #7dd3fc !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.legalpro-dashboard-page .dashboard-recent-item h6.text-dark,'
        . 'body.legalpro-dark-mode.legalpro-dashboard-page .lp-top-client .text-dark {'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.legalpro-dashboard-page .dashboard-recent-item:hover {'
        . 'background: var(--lp-dark-surface-raised) !important;'
        . 'border-color: var(--lp-dark-border) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.legalpro-dashboard-page .lp-collection-badge {'
        . 'background: rgba(45, 206, 137, 0.16) !important;'
        . 'color: #86efac !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.legalpro-dashboard-page .lp-progress-bar {'
        . 'background: var(--lp-dark-border) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.legalpro-dashboard-page .cat-legend-label {'
        . 'color: var(--lp-dark-text-muted) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.legalpro-dashboard-page .cat-legend-pct {'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.legalpro-dashboard-page .lp-avatar {'
        . 'background: ' . $soft12 . ' !important;'
        . 'color: ' . $primaryOnDark . ' !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.legalpro-dashboard-page .dashboard-upcoming-empty .lp-icon svg {'
        . 'stroke: var(--lp-dark-text-muted) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .chat-window {'
        . 'background: transparent !important;'
        . 'border-color: var(--lp-dark-border) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .chat-message-bot .chat-bubble {'
        . 'background: var(--lp-dark-surface-raised) !important;'
        . 'border-color: var(--lp-dark-border) !important;'
        . 'color: var(--lp-dark-text-secondary) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .timeline-content .text-dark,'
        . 'body.legalpro-dark-mode .timeline-content h6 {'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $dangerSoft = 'rgba(245, 54, 92, 0.16)';
    $dangerBorder = 'rgba(245, 54, 92, 0.28)';
    $infoSoft = portalThemeHexToRgba($primary, 0.14);
    $infoBorder = portalThemeHexToRgba($primary, 0.28);

    $css .= 'body.legalpro-dark-mode tr.table-danger > td,'
        . 'body.legalpro-dark-mode tr.table-danger > th {'
        . 'background-color: ' . $dangerSoft . ' !important;'
        . '--bs-table-bg: ' . $dangerSoft . ';'
        . '--bs-table-color: var(--lp-dark-text-secondary);'
        . 'color: var(--lp-dark-text-secondary) !important;'
        . 'border-color: ' . $dangerBorder . ' !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode tr.table-danger h6,'
        . 'body.legalpro-dark-mode tr.table-danger .text-sm,'
        . 'body.legalpro-dark-mode tr.table-danger p,'
        . 'body.legalpro-dark-mode tr.table-danger span:not(.ca-status-pill):not(.badge) {'
        . 'color: var(--lp-dark-text-secondary) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode tr.table-danger .text-muted {'
        . 'color: var(--lp-dark-text-muted) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode tr.table-info > td,'
        . 'body.legalpro-dark-mode tr.table-info > th {'
        . 'background-color: ' . $infoSoft . ' !important;'
        . '--bs-table-bg: ' . $infoSoft . ';'
        . '--bs-table-color: var(--lp-dark-text-secondary);'
        . 'color: var(--lp-dark-text-secondary) !important;'
        . 'border-color: ' . $infoBorder . ' !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode tr.table-info h6,'
        . 'body.legalpro-dark-mode tr.table-info .text-sm,'
        . 'body.legalpro-dark-mode tr.table-info p {'
        . 'color: var(--lp-dark-text-secondary) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode tr.table-info .text-muted {'
        . 'color: var(--lp-dark-text-muted) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .ca-status-pill--scheduled {'
        . 'background: ' . portalThemeHexToRgba($primary, 0.22) . ' !important;'
        . 'color: ' . $primaryOnDark . ' !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .ca-status-pill--pending {'
        . 'background: rgba(251, 140, 0, 0.2) !important;'
        . 'color: #ffc978 !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .ca-status-pill--declined {'
        . 'background: rgba(245, 54, 92, 0.22) !important;'
        . 'color: #ff9eb5 !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .lp-pill--status-active {'
        . 'background: rgba(45, 206, 137, 0.2) !important;'
        . 'color: #8ce8c0 !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .lp-pill--status-progress {'
        . 'background: ' . portalThemeHexToRgba($primary, 0.22) . ' !important;'
        . 'color: ' . $primaryOnDark . ' !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .lp-pill--status-pending {'
        . 'background: rgba(251, 140, 0, 0.2) !important;'
        . 'color: #ffc978 !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .lp-pill--status-waiting {'
        . 'background: rgba(130, 94, 228, 0.22) !important;'
        . 'color: #c4b5fd !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .lp-pill--status-declined {'
        . 'background: rgba(245, 54, 92, 0.22) !important;'
        . 'color: #ff9eb5 !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .lp-pill--status-default {'
        . 'background: rgba(255, 255, 255, 0.08) !important;'
        . 'color: var(--lp-dark-text-muted) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .lp-pill--status-closed,'
        . 'body.legalpro-dark-mode .ca-status-pill--done {'
        . 'background: rgba(255, 255, 255, 0.08) !important;'
        . 'color: var(--lp-dark-text-muted) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .lp-pill--priority-high {'
        . 'background: ' . portalThemeHexToRgba($primary, 0.22) . ' !important;'
        . 'color: ' . $primaryOnDark . ' !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .lp-pill--priority-urgent {'
        . 'background: rgba(245, 54, 92, 0.22) !important;'
        . 'color: #ff9eb5 !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .lp-pill--priority-medium {'
        . 'background: rgba(130, 94, 228, 0.22) !important;'
        . 'color: #c4b5fd !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .ca-status-pill--muted {'
        . 'background: rgba(255, 255, 255, 0.06) !important;'
        . 'color: var(--lp-dark-text-subtle) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .btn-outline-primary {'
        . 'color: ' . $primaryOnDark . ' !important;'
        . 'border-color: ' . portalThemeHexToRgba($primary, 0.5) . ' !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .btn-outline-primary:hover,'
        . 'body.legalpro-dark-mode .btn-outline-primary:focus {'
        . 'background: ' . portalThemeHexToRgba($primary, 0.18) . ' !important;'
        . 'color: #fff !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .btn-outline-dark {'
        . 'color: var(--lp-dark-text) !important;'
        . 'border-color: var(--lp-dark-border-strong) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .btn-outline-dark:hover,'
        . 'body.legalpro-dark-mode .btn-outline-dark:focus {'
        . 'background: var(--lp-dark-surface-hover) !important;'
        . 'border-color: ' . portalThemeHexToRgba($primary, 0.45) . ' !important;'
        . 'color: ' . $primaryOnDark . ' !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.lawyer-appointments-page .table thead th {'
        . 'color: var(--lp-dark-text-muted) !important;'
        . 'opacity: 1 !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.lawyer-appointments-page .table tbody td {'
        . 'vertical-align: middle;'
        . '}';

    $css .= 'body.legalpro-dark-mode .dashboard-calendar-hub .fc-list-event:hover td {'
        . 'background: rgba(' . $rgb . ', 0.1) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .fc .fc-list-event:hover td {'
        . 'background: var(--lp-dark-surface-hover) !important;'
        . '}';

    $clientCardSurfaces = 'body.legalpro-dark-mode.legalpro-client-portal .main-content .card,'
        . 'body.legalpro-dark-mode.client-dashboard-page .main-content .card,'
        . 'body.legalpro-dark-mode.client-cases-page .main-content .card,'
        . 'body.legalpro-dark-mode.client-appointments-page .main-content .card,'
        . 'body.legalpro-dark-mode.client-court-tracking-page .main-content .card,'
        . 'body.legalpro-dark-mode.client-payments-page .main-content .card,'
        . 'body.legalpro-dark-mode.client-portal-page .main-content .card,'
        . 'body.legalpro-dark-mode.client-profile-page .main-content .card,'
        . 'body.legalpro-dark-mode.client-chatbot-page .main-content .card,'
        . 'body.legalpro-dark-mode .cd-hero,'
        . 'body.legalpro-dark-mode .cc-hero,'
        . 'body.legalpro-dark-mode .ca-hero,'
        . 'body.legalpro-dark-mode .cp-hero,'
        . 'body.legalpro-dark-mode .cct-hero,'
        . 'body.legalpro-dark-mode .cd-panel,'
        . 'body.legalpro-dark-mode .cc-panel,'
        . 'body.legalpro-dark-mode .ca-panel,'
        . 'body.legalpro-dark-mode .cp-panel,'
        . 'body.legalpro-dark-mode .cct-panel,'
        . 'body.legalpro-dark-mode .cc-comments-panel,'
        . 'body.legalpro-dark-mode .cd-stat-card,'
        . 'body.legalpro-dark-mode .cd-kpi,'
        . 'body.legalpro-dark-mode .dashboard-calendar-hub';

    $css .= $clientCardSurfaces . ' {'
        . 'background-color: var(--lp-dark-surface) !important;'
        . 'background: var(--lp-dark-surface) !important;'
        . 'border-color: var(--lp-dark-border) !important;'
        . 'box-shadow: 0 4px 20px rgba(15, 20, 35, 0.16) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .cc-hero-stat,'
        . 'body.legalpro-dark-mode .ca-hero-pill,'
        . 'body.legalpro-dark-mode .cp-hero-pill,'
        . 'body.legalpro-dark-mode .cct-hero-pill,'
        . 'body.legalpro-dark-mode .cd-list-item {'
        . 'background: var(--lp-dark-surface-raised) !important;'
        . 'border-color: var(--lp-dark-border) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .cd-hero-title,'
        . 'body.legalpro-dark-mode .cc-hero-title,'
        . 'body.legalpro-dark-mode .ca-hero-title,'
        . 'body.legalpro-dark-mode .cp-hero-title,'
        . 'body.legalpro-dark-mode .cct-hero-title,'
        . 'body.legalpro-dark-mode .cc-hero-stat-value,'
        . 'body.legalpro-dark-mode .ca-hero-pill-value,'
        . 'body.legalpro-dark-mode .cp-hero-pill-value,'
        . 'body.legalpro-dark-mode .cct-hero-pill-value,'
        . 'body.legalpro-dark-mode .cd-panel-title,'
        . 'body.legalpro-dark-mode .cd-kpi__val,'
        . 'body.legalpro-dark-mode .cd-list-row__title,'
        . 'body.legalpro-dark-mode .cd-appt-row__title,'
        . 'body.legalpro-dark-mode .cd-panel .card-header h6,'
        . 'body.legalpro-dark-mode .cc-panel .card-header h5,'
        . 'body.legalpro-dark-mode .ca-panel .card-header h5,'
        . 'body.legalpro-dark-mode .cp-panel .card-header h5,'
        . 'body.legalpro-dark-mode .cct-panel .card-header h5 {'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .cd-hero-sub,'
        . 'body.legalpro-dark-mode .cd-hero-text,'
        . 'body.legalpro-dark-mode .cc-hero-text,'
        . 'body.legalpro-dark-mode .ca-hero-text,'
        . 'body.legalpro-dark-mode .cp-hero-text,'
        . 'body.legalpro-dark-mode .cp-hero-meta,'
        . 'body.legalpro-dark-mode .cct-hero-text,'
        . 'body.legalpro-dark-mode .cc-hero-stat-label,'
        . 'body.legalpro-dark-mode .ca-hero-pill-label,'
        . 'body.legalpro-dark-mode .cp-hero-pill-label,'
        . 'body.legalpro-dark-mode .cct-hero-pill-label,'
        . 'body.legalpro-dark-mode .cd-panel .cd-panel-sub,'
        . 'body.legalpro-dark-mode .cd-kpi__lbl,'
        . 'body.legalpro-dark-mode .cd-list-row__meta,'
        . 'body.legalpro-dark-mode .cd-appt-row__meta,'
        . 'body.legalpro-dark-mode .cd-appt-row__notes,'
        . 'body.legalpro-dark-mode .cd-appt-row__time,'
        . 'body.legalpro-dark-mode .cd-empty-title,'
        . 'body.legalpro-dark-mode .cd-empty-sub {'
        . 'color: var(--lp-dark-text-muted) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .cd-list-row:hover,'
        . 'body.legalpro-dark-mode .cd-appt-row:hover {'
        . 'background: ' . $soft12 . ' !important;'
        . 'border-color: ' . $soft20 . ' !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .cd-next-appt {'
        . 'background: ' . $soft12 . ' !important;'
        . 'border-color: ' . $soft20 . ' !important;'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .cd-appt-row {'
        . 'border-color: var(--lp-dark-border) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .cd-list-item:hover {'
        . 'background: ' . $soft12 . ' !important;'
        . 'border-color: ' . $soft20 . ' !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .cc-panel .table thead th,'
        . 'body.legalpro-dark-mode .ca-panel .table thead th,'
        . 'body.legalpro-dark-mode .cp-panel .table thead th,'
        . 'body.legalpro-dark-mode .cct-panel .table thead th {'
        . 'background: var(--lp-dark-surface-raised) !important;'
        . 'border-color: var(--lp-dark-border) !important;'
        . 'color: var(--lp-dark-text-muted) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .cc-panel .card-header,'
        . 'body.legalpro-dark-mode .ca-panel .card-header,'
        . 'body.legalpro-dark-mode .cp-panel .card-header,'
        . 'body.legalpro-dark-mode .cct-panel .card-header,'
        . 'body.legalpro-dark-mode .cd-panel .card-header,'
        . 'body.legalpro-dark-mode .cc-comments-panel .card-header {'
        . 'border-bottom-color: var(--lp-dark-border) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.legalpro-client-portal .navbar-main,'
        . 'body.legalpro-dark-mode.client-dashboard-page .navbar-main,'
        . 'body.legalpro-dark-mode.client-cases-page .navbar-main,'
        . 'body.legalpro-dark-mode.client-appointments-page .navbar-main,'
        . 'body.legalpro-dark-mode.client-court-tracking-page .navbar-main,'
        . 'body.legalpro-dark-mode.client-payments-page .navbar-main,'
        . 'body.legalpro-dark-mode.client-portal-page .navbar-main,'
        . 'body.legalpro-dark-mode.client-profile-page .navbar-main,'
        . 'body.legalpro-dark-mode.client-chatbot-page .navbar-main {'
        . 'background: var(--lp-dark-surface) !important;'
        . 'background-color: var(--lp-dark-surface) !important;'
        . 'box-shadow: 0 1px 0 var(--lp-dark-border) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.legalpro-client-portal .navbar-main h5,'
        . 'body.legalpro-dark-mode.legalpro-client-portal .navbar-main h6,'
        . 'body.legalpro-dark-mode.legalpro-client-portal .navbar-main .font-weight-bolder,'
        . 'body.legalpro-dark-mode.legalpro-client-portal .navbar-main .text-white,'
        . 'body.legalpro-dark-mode.legalpro-client-portal .navbar-main .nav-link.text-white,'
        . 'body.legalpro-dark-mode.legalpro-client-portal .navbar-main .nav-link.text-white span,'
        . 'body.legalpro-dark-mode.legalpro-client-portal .navbar-main .breadcrumb-item.active,'
        . 'body.legalpro-dark-mode.client-dashboard-page .navbar-main .nav-link.text-white,'
        . 'body.legalpro-dark-mode.client-dashboard-page .navbar-main .nav-link.text-white span,'
        . 'body.legalpro-dark-mode.client-cases-page .navbar-main .nav-link.text-white span,'
        . 'body.legalpro-dark-mode.client-appointments-page .navbar-main .nav-link.text-white span,'
        . 'body.legalpro-dark-mode.client-court-tracking-page .navbar-main .nav-link.text-white span,'
        . 'body.legalpro-dark-mode.client-payments-page .navbar-main .nav-link.text-white span,'
        . 'body.legalpro-dark-mode.client-profile-page .navbar-main .nav-link.text-white span {'
        . 'color: #fff !important;'
        . 'opacity: 1 !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.legalpro-client-portal .navbar-main .breadcrumb-item a,'
        . 'body.legalpro-dark-mode.client-dashboard-page .navbar-main .breadcrumb-item a {'
        . 'color: rgba(255, 255, 255, 0.82) !important;'
        . 'opacity: 1 !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .cd-hero .cd-hero-title {'
        . 'color: #fff !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .cd-hero .cd-hero-text,'
        . 'body.legalpro-dark-mode .cd-hero .cd-hero-sub {'
        . 'color: rgba(255, 255, 255, 0.88) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .cd-hero .cd-hero-kicker {'
        . 'color: ' . $primary . ' !important;'
        . 'opacity: 1 !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .legalpro-header-user__toggle {'
        . 'background: var(--lp-dark-surface-raised) !important;'
        . 'border: 1px solid var(--lp-dark-border) !important;'
        . 'box-shadow: none !important;'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .legalpro-header-user__toggle:hover,'
        . 'body.legalpro-dark-mode .legalpro-header-user__toggle:focus,'
        . 'body.legalpro-dark-mode .legalpro-header-user__toggle.show {'
        . 'background: var(--lp-dark-surface-hover) !important;'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .legalpro-header-user__name {'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .legalpro-header-user__role {'
        . 'color: var(--lp-dark-text-muted) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .legalpro-header-user__caret {'
        . 'color: var(--lp-dark-text-muted) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .legalpro-header-user__menu .dropdown-item:hover,'
        . 'body.legalpro-dark-mode .legalpro-header-user__menu .dropdown-item:focus {'
        . 'background-color: var(--lp-dark-surface-hover) !important;'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .legalpro-header-notif {'
        . 'background: var(--lp-dark-surface-raised) !important;'
        . 'border: 1px solid var(--lp-dark-border) !important;'
        . 'box-shadow: none !important;'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .legalpro-header-notif:hover,'
        . 'body.legalpro-dark-mode .legalpro-header-notif:focus,'
        . 'body.legalpro-dark-mode .legalpro-header-notif.show {'
        . 'background: var(--lp-dark-surface-hover) !important;'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .legalpro-notif-panel {'
        . 'background: var(--lp-dark-surface-raised) !important;'
        . 'border-color: var(--lp-dark-border) !important;'
        . 'box-shadow: 0 12px 40px rgba(0, 0, 0, 0.45) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .legalpro-notif-panel__head,'
        . 'body.legalpro-dark-mode .legalpro-notif-panel__foot {'
        . 'background: var(--lp-dark-surface-raised) !important;'
        . 'border-color: var(--lp-dark-border) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .legalpro-notif-panel__title {'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .legalpro-notif-panel__count {'
        . 'background: ' . $soft12 . ' !important;'
        . 'color: ' . $primaryOnDark . ' !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .legalpro-notif-item {'
        . 'border-color: var(--lp-dark-border) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .legalpro-notif-item:hover,'
        . 'body.legalpro-dark-mode .legalpro-notif-item:focus {'
        . 'background: var(--lp-dark-surface-hover) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .legalpro-notif-item__title {'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .legalpro-notif-item__message,'
        . 'body.legalpro-dark-mode .legalpro-notif-item__time,'
        . 'body.legalpro-dark-mode .legalpro-notif-panel__empty,'
        . 'body.legalpro-dark-mode .legalpro-notif-panel__empty span {'
        . 'color: var(--lp-dark-text-muted) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .legalpro-notif-panel__empty p {'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .legalpro-notif-panel__view-all {'
        . 'color: ' . $primaryOnDark . ' !important;'
        . '}';

    // Unread hover badge uses --legalpro-theme-primary; client dark mode remaps that to a light accent.
    $css .= 'body.legalpro-dark-mode .legalpro-notif-item__hover-caption {'
        . 'background: ' . $primary . ' !important;'
        . 'color: #ffffff !important;'
        . 'box-shadow: 0 4px 14px rgba(0, 0, 0, 0.4) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .cc-pill {'
        . 'background: ' . $soft12 . ' !important;'
        . 'color: ' . $primary . ' !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.admin-cases-page .legalpro-cases-hub__head {'
        . 'background: linear-gradient(135deg, ' . $soft12 . ' 0%, var(--lp-dark-surface-raised) 100%) !important;'
        . 'border-bottom-color: var(--lp-dark-border) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.admin-cases-page .legalpro-cases-hub__title,'
        . 'body.legalpro-dark-mode.admin-cases-page .legalpro-case-title__main {'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.admin-cases-page .legalpro-cases-hub__count,'
        . 'body.legalpro-dark-mode.admin-cases-page .legalpro-case-title__sub,'
        . 'body.legalpro-dark-mode.admin-cases-page .legalpro-case-client {'
        . 'color: var(--lp-dark-text-muted) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.admin-cases-page .legalpro-cases-table tbody td {'
        . 'color: var(--lp-dark-text-secondary) !important;'
        . 'border-bottom-color: var(--lp-dark-border) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.admin-cases-page .legalpro-cases-table tbody tr:hover {'
        . 'background: ' . $soft12 . ' !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.admin-cases-page .legalpro-case-number {'
        . 'color: ' . $primaryOnDark . ' !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.admin-cases-page .legalpro-case-number:hover {'
        . 'color: #fff !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.admin-cases-page .legalpro-case-fee {'
        . 'color: var(--lp-dark-text-secondary) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.admin-cases-page .btn-legalpro-case-open {'
        . 'background: ' . portalThemeHexToRgba($primary, 0.18) . ' !important;'
        . 'border-color: ' . portalThemeHexToRgba($primary, 0.55) . ' !important;'
        . 'color: ' . $primaryOnDark . ' !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.admin-cases-page .btn-legalpro-case-open:hover,'
        . 'body.legalpro-dark-mode.admin-cases-page .btn-legalpro-case-open:focus {'
        . 'background: ' . $primary . ' !important;'
        . 'border-color: ' . $primary . ' !important;'
        . 'color: #fff !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.admin-court-tracking-page .card h6,'
        . 'body.legalpro-dark-mode.admin-court-tracking-page .dashboard-calendar-hub__title {'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.admin-court-tracking-page .table tbody td,'
        . 'body.legalpro-dark-mode.admin-court-tracking-page .table tbody td span:not(.lp-pill):not(.badge) {'
        . 'color: var(--lp-dark-text-secondary) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.admin-court-tracking-page .court-actions .btn {'
        . 'color: #fff !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.admin-court-tracking-page .court-actions .btn-secondary {'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $lawyerCardSurfaces = 'body.legalpro-dark-mode.legalpro-lawyer-portal .main-content .card,'
        . 'body.legalpro-dark-mode.lawyer-dashboard-page .main-content .card,'
        . 'body.legalpro-dark-mode.lawyer-cases-page .main-content .card,'
        . 'body.legalpro-dark-mode.lawyer-clients-page .main-content .card,'
        . 'body.legalpro-dark-mode.lawyer-appointments-page .main-content .card,'
        . 'body.legalpro-dark-mode.lawyer-availability-page .main-content .card,'
        . 'body.legalpro-dark-mode.lawyer-case-view-page .main-content .card,'
        . 'body.legalpro-dark-mode.lawyer-client-view-page .main-content .card,'
        . 'body.legalpro-dark-mode.lawyer-court-tracking-page .main-content .card,'
        . 'body.legalpro-dark-mode.lawyer-tasks-page .main-content .card,'
        . 'body.legalpro-dark-mode.lawyer-profile-page .main-content .card';

    $css .= $lawyerCardSurfaces . ','
        . 'body.legalpro-dark-mode.lawyer-settings-page .main-content .card {'
        . 'background-color: var(--lp-dark-surface) !important;'
        . 'background: var(--lp-dark-surface) !important;'
        . 'border-color: var(--lp-dark-border) !important;'
        . 'box-shadow: 0 4px 20px rgba(15, 20, 35, 0.16) !important;'
        . '}';

    $lawyerShellPages = 'body.legalpro-dark-mode.legalpro-lawyer-portal,'
        . 'body.legalpro-dark-mode.lawyer-dashboard-page,'
        . 'body.legalpro-dark-mode.lawyer-cases-page,'
        . 'body.legalpro-dark-mode.lawyer-clients-page,'
        . 'body.legalpro-dark-mode.lawyer-appointments-page,'
        . 'body.legalpro-dark-mode.lawyer-availability-page,'
        . 'body.legalpro-dark-mode.lawyer-case-view-page,'
        . 'body.legalpro-dark-mode.lawyer-client-view-page,'
        . 'body.legalpro-dark-mode.lawyer-court-tracking-page,'
        . 'body.legalpro-dark-mode.lawyer-tasks-page,'
        . 'body.legalpro-dark-mode.lawyer-profile-page,'
        . 'body.legalpro-dark-mode.lawyer-settings-page';

    $css .= $lawyerShellPages . ' {'
        . 'background: var(--lawyer-portal-content-bg) !important;'
        . 'background-color: var(--lawyer-portal-content-bg) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.lawyer-tasks-page .task-card-themed,'
        . 'body.legalpro-dark-mode.lawyer-tasks-page .task-card-themed .card-body {'
        . 'background: var(--lp-task-card-body-bg) !important;'
        . 'background-color: var(--lp-task-card-bg) !important;'
        . 'border-color: var(--lp-task-card-border) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.lawyer-tasks-page .task-card-themed h6 {'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.lawyer-tasks-page .task-card-themed .text-sm:not(.badge) {'
        . 'color: var(--lp-dark-text-secondary) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.lawyer-tasks-page .task-card-themed .text-muted {'
        . 'color: var(--lp-dark-text-muted) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .nav-tabs {'
        . 'border-bottom-color: var(--lp-dark-border) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .card-header .nav-tabs {'
        . 'border-bottom-color: var(--lp-dark-border) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .nav-tabs .nav-link {'
        . 'color: var(--lp-dark-text-muted) !important;'
        . 'border-color: transparent !important;'
        . 'background: transparent !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .nav-tabs .nav-link:hover,'
        . 'body.legalpro-dark-mode .nav-tabs .nav-link:focus {'
        . 'color: var(--lp-dark-text) !important;'
        . 'background: var(--lp-dark-surface-hover) !important;'
        . 'border-color: var(--lp-dark-border) var(--lp-dark-border) transparent !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .nav-tabs .nav-link.active,'
        . 'body.legalpro-dark-mode .nav-tabs .nav-item.show .nav-link {'
        . 'color: var(--lp-dark-text) !important;'
        . 'background-color: var(--lp-dark-surface-raised) !important;'
        . 'border-color: var(--lp-dark-border) var(--lp-dark-border) var(--lp-dark-surface-raised) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .table-striped > tbody > tr:nth-of-type(odd) > * {'
        . 'background-color: var(--lp-dark-surface-raised) !important;'
        . '--bs-table-accent-bg: var(--lp-dark-surface-raised) !important;'
        . 'color: var(--lp-dark-text-secondary) !important;'
        . 'border-color: var(--lp-dark-border) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .table-striped > tbody > tr:nth-of-type(even) > * {'
        . 'background-color: var(--lp-dark-surface) !important;'
        . '--bs-table-accent-bg: var(--lp-dark-surface) !important;'
        . 'color: var(--lp-dark-text-secondary) !important;'
        . 'border-color: var(--lp-dark-border) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .table tbody tr.table-active > td,'
        . 'body.legalpro-dark-mode .table tbody tr.table-active > th {'
        . 'background-color: ' . $soft12 . ' !important;'
        . '--bs-table-accent-bg: ' . $soft12 . ' !important;'
        . 'color: var(--lp-dark-text) !important;'
        . 'border-color: var(--lp-dark-border) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .table tbody tr.table-active strong {'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .tab-content .table thead th {'
        . 'color: var(--lp-dark-text-muted) !important;'
        . 'font-weight: 600;'
        . '}';

    $css .= 'body.legalpro-dark-mode .tab-content .table tbody td,'
        . 'body.legalpro-dark-mode .tab-content .table tbody td strong {'
        . 'color: var(--lp-dark-text-secondary) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .tab-content .text-center.text-muted {'
        . 'color: var(--lp-dark-text-subtle) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .availability-hero {'
        . 'background: linear-gradient(140deg, ' . $soft12 . ' 0%, var(--lp-dark-surface-raised) 100%) !important;'
        . 'border-color: var(--lp-dark-border) !important;'
        . 'color: var(--lp-dark-text-secondary) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .availability-hero h6,'
        . 'body.legalpro-dark-mode .availability-hero .font-weight-bolder {'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .availability-hero .text-muted {'
        . 'color: var(--lp-dark-text-muted) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .availability-fallback-calendar {'
        . 'border-color: var(--lp-dark-border) !important;'
        . 'background: var(--lp-dark-surface) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .availability-fallback-toolbar {'
        . 'background: var(--lp-dark-surface-raised) !important;'
        . 'border-bottom-color: var(--lp-dark-border) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .availability-fallback-header > div {'
        . 'background: var(--lp-dark-surface-raised) !important;'
        . 'border-color: var(--lp-dark-border) !important;'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .availability-fallback-day-name {'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .availability-fallback-day-date {'
        . 'color: var(--lp-dark-text-muted) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .availability-fallback-week-label {'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .availability-fallback-day {'
        . 'background: var(--lp-dark-surface) !important;'
        . 'border-color: var(--lp-dark-border) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .availability-fallback-day:hover {'
        . 'background: var(--lp-dark-surface-hover) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .availability-fallback-day .text-muted,'
        . 'body.legalpro-dark-mode .availability-fallback-day .text-sm.text-muted {'
        . 'color: var(--lp-dark-text-muted) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .availability-week-nav .btn-outline-primary {'
        . 'color: ' . $primaryOnDark . ' !important;'
        . 'border-color: ' . portalThemeHexToRgba($primary, 0.5) . ' !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .cct-panel .card-header .text-muted,'
        . 'body.legalpro-dark-mode .cct-panel .card-header p.text-sm {'
        . 'color: var(--lp-dark-text-muted) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .cct-panel .card-header .text-dark,'
        . 'body.legalpro-dark-mode .cct-panel .card-header h5 {'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .cct-panel .table .text-secondary,'
        . 'body.legalpro-dark-mode .cct-panel .table thead th.text-secondary {'
        . 'color: var(--lp-dark-text-muted) !important;'
        . 'opacity: 1 !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .cct-row td {'
        . 'color: var(--lp-dark-text-secondary) !important;'
        . 'border-bottom-color: var(--lp-dark-border) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .cct-row:hover td {'
        . 'background: ' . portalThemeHexToRgba($primary, 0.08) . ' !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .cct-panel .card-body h5.font-weight-bolder {'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .cct-panel .card-body .text-muted {'
        . 'color: var(--lp-dark-text-muted) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .case-detail-hub {'
        . 'background: var(--lp-dark-surface) !important;'
        . 'border-color: var(--lp-dark-border) !important;'
        . 'box-shadow: 0 4px 20px rgba(15, 20, 35, 0.16) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .case-detail-hub__header {'
        . 'background: linear-gradient(135deg, ' . $soft12 . ' 0%, var(--lp-dark-surface-raised) 100%) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .case-detail-hub__body {'
        . 'background: var(--lp-dark-surface) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .case-detail-tabs .nav-link {'
        . 'background: var(--lp-dark-surface-raised) !important;'
        . 'color: var(--lp-dark-text-muted) !important;'
        . 'box-shadow: none !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .case-detail-tabs .nav-link:hover {'
        . 'background: var(--lp-dark-surface-hover) !important;'
        . 'color: ' . $primaryOnDark . ' !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .case-detail-tabs .nav-link.active {'
        . 'background: ' . $gradient310 . ' !important;'
        . 'color: #fff !important;'
        . 'box-shadow: 0 6px 16px ' . portalThemeHexToRgba($primary, 0.35) . ' !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .case-detail-tabs__icon {'
        . 'background: ' . $soft12 . ' !important;'
        . 'color: ' . $primaryOnDark . ' !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .case-detail-tabs__icon .lp-icon svg {'
        . 'stroke: currentColor !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .case-feed-item__icon.dashboard-stat-icon-wrap .lp-icon svg,'
        . 'body.legalpro-dark-mode .case-feed-empty__icon.dashboard-stat-icon-wrap .lp-icon svg {'
        . 'stroke: ' . $primaryOnDark . ' !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .case-detail-tabs .nav-link.active .case-detail-tabs__icon {'
        . 'background: rgba(255, 255, 255, 0.22) !important;'
        . 'color: #fff !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .case-detail-tabs__count {'
        . 'background: ' . $soft12 . ' !important;'
        . 'color: ' . $primaryOnDark . ' !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .case-detail-tabs .nav-link.active .case-detail-tabs__count {'
        . 'background: rgba(255, 255, 255, 0.25) !important;'
        . 'color: #fff !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .case-feed-item {'
        . 'background: var(--lp-dark-surface-raised) !important;'
        . 'border-color: var(--lp-dark-border) !important;'
        . 'box-shadow: none !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .case-feed-item:hover {'
        . 'background: var(--lp-dark-surface-hover) !important;'
        . 'box-shadow: 0 4px 14px rgba(0, 0, 0, 0.2) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .case-feed-item__title {'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .case-feed-item__subtitle {'
        . 'color: var(--lp-dark-text-muted) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .case-feed-empty {'
        . 'background: var(--lp-dark-surface-raised) !important;'
        . 'border-color: ' . $soft35 . ' !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .case-feed-empty__icon {'
        . 'background: ' . $soft12 . ' !important;'
        . 'color: ' . $primaryOnDark . ' !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .case-feed-empty p {'
        . 'color: var(--lp-dark-text-muted) !important;'
        . '}';

    $caseDetailSurfaces = 'body.legalpro-dark-mode .case-detail-table-wrap,'
        . 'body.legalpro-dark-mode .case-detail-form-card,'
        . 'body.legalpro-dark-mode .case-detail-hub .timeline-block,'
        . 'body.legalpro-dark-mode .case-detail-hub .chat-messages,'
        . 'body.legalpro-dark-mode .case-detail-hub .card.mb-3';

    $css .= $caseDetailSurfaces . ' {'
        . 'background: var(--lp-dark-surface-raised) !important;'
        . 'border-color: var(--lp-dark-border) !important;'
        . 'box-shadow: none !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .case-detail-form-card .card-header,'
        . 'body.legalpro-dark-mode .case-detail-hub .card.mb-3 .card-header {'
        . 'background: var(--lp-dark-surface-hover) !important;'
        . 'border-bottom-color: var(--lp-dark-border) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .case-detail-table-wrap .table td,'
        . 'body.legalpro-dark-mode .case-detail-table-wrap .table th {'
        . 'color: var(--lp-dark-text-secondary) !important;'
        . 'border-color: var(--lp-dark-border) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .case-detail-hub .chat-message.bg-light {'
        . 'background: var(--lp-dark-surface-hover) !important;'
        . 'color: var(--lp-dark-text-secondary) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .case-detail-hub .chat-message.text-dark,'
        . 'body.legalpro-dark-mode .case-detail-hub .chat-message strong {'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .case-detail-hub .chat-message .text-muted {'
        . 'color: var(--lp-dark-text-muted) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.admin-case-view-page .card .card {'
        . 'background: var(--lp-dark-surface-raised) !important;'
        . 'border-color: var(--lp-dark-border) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.admin-case-view-page .services-list .border-bottom,'
        . 'body.legalpro-dark-mode.admin-case-view-page .services-list .border-top {'
        . 'border-color: var(--lp-dark-border) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.admin-case-view-page hr.horizontal.dark {'
        . 'border-color: var(--lp-dark-border) !important;'
        . 'opacity: 1 !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.admin-case-view-page .btn-outline-primary {'
        . 'color: ' . $primaryOnDark . ' !important;'
        . 'border-color: ' . $soft35 . ' !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.admin-case-view-page .btn-outline-primary:hover,'
        . 'body.legalpro-dark-mode.admin-case-view-page .btn-outline-primary:focus {'
        . 'background: ' . $soft12 . ' !important;'
        . 'color: #fff !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.admin-case-view-page .case-detail-form-card input[type="file"].form-control {'
        . 'background-color: var(--lp-dark-input-bg) !important;'
        . 'border-color: var(--lp-dark-border-strong) !important;'
        . 'color: var(--lp-dark-text-secondary) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.admin-case-view-page .case-detail-form-card input[type="file"].form-control::file-selector-button {'
        . 'background: var(--lp-dark-surface-hover) !important;'
        . 'border-color: var(--lp-dark-border-strong) !important;'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    // Client portal dark mode — neutral slate accents (no purple / theme-primary tint)
    $clientDark = 'body.legalpro-dark-mode.legalpro-client-portal';
    $clientPages = $clientDark . ','
        . 'body.legalpro-dark-mode.client-dashboard-page,'
        . 'body.legalpro-dark-mode.client-cases-page,'
        . 'body.legalpro-dark-mode.client-appointments-page,'
        . 'body.legalpro-dark-mode.client-court-tracking-page,'
        . 'body.legalpro-dark-mode.client-payments-page,'
        . 'body.legalpro-dark-mode.client-portal-page,'
        . 'body.legalpro-dark-mode.client-profile-page,'
        . 'body.legalpro-dark-mode.client-chatbot-page';
    $clientAccent = '#e2e8f2';
    $clientAccentMuted = '#c5cede';
    $clientAccentRgb = '226, 232, 242';
    $clientSoft = 'rgba(255, 255, 255, 0.08)';
    $clientSoftBorder = 'rgba(255, 255, 255, 0.14)';
    $clientIconSoft = portalThemeHexToRgba($primary, 0.14);
    $clientIconSoftMid = portalThemeHexToRgba($primary, 0.2);
    $clientHeroGradient = 'linear-gradient(135deg, #464f68 0%, #3d455c 100%)';

    // Apply as soon as html.legalpro-theme-dark is set (before body.legalpro-dark-mode) to prevent accent flash on navigation.
    $clientEarly = 'html.legalpro-theme-dark body.legalpro-client-portal';
    $css .= $clientEarly . ' {'
        . '--legalpro-theme-gradient: ' . $clientHeroGradient . ';'
        . '--client-portal-gradient: ' . $clientHeroGradient . ';'
        . '--client-portal-content-bg: var(--lp-dark-bg);'
        . '--cp-gradient: ' . $clientHeroGradient . ';'
        . '--cc-gradient: ' . $clientHeroGradient . ';'
        . '--ca-gradient: ' . $clientHeroGradient . ';'
        . '--cct-gradient: ' . $clientHeroGradient . ';'
        . '--cp-pay-gradient: ' . $clientHeroGradient . ';'
        . '--cb-gradient: ' . $clientHeroGradient . ';'
        . 'background: var(--lp-dark-bg) !important;'
        . 'background-color: var(--lp-dark-bg) !important;'
        . '}';
    $css .= $clientEarly . ' [class*="-hero-card"] {'
        . 'background: ' . $clientHeroGradient . ' !important;'
        . 'background-image: none !important;'
        . 'border-color: var(--lp-dark-border) !important;'
        . '}';
    $css .= $clientEarly . ' .cd-panel,'
        . $clientEarly . ' .cc-panel,'
        . $clientEarly . ' .ca-panel,'
        . $clientEarly . ' .ca-book-card,'
        . $clientEarly . ' .cct-panel,'
        . $clientEarly . ' .cp-panel,'
        . $clientEarly . ' .cb-panel,'
        . $clientEarly . ' .cd-kpi,'
        . $clientEarly . ' .search-panel {'
        . 'background: var(--lp-dark-surface) !important;'
        . 'background-color: var(--lp-dark-surface) !important;'
        . 'border-color: var(--lp-dark-border) !important;'
        . '}';

    $css .= $clientPages . ' {'
        . '--lp-client-icon-primary: ' . $primary . ';'
        . '--lp-client-icon-soft: ' . $clientIconSoft . ';'
        . '--lp-client-icon-rgb: ' . $rgb . ';'
        . '--legalpro-theme-primary: ' . $clientAccent . ';'
        . '--legalpro-theme-primary-dark: ' . $clientAccentMuted . ';'
        . '--legalpro-theme-primary-rgb: ' . $clientAccentRgb . ';'
        . '--legalpro-theme-gradient: ' . $clientHeroGradient . ';'
        . '--lp-portal-primary: ' . $clientAccent . ';'
        . '--lp-cases-accent-soft: ' . $clientSoft . ';'
        . '--lp-cases-accent-border: ' . $clientSoftBorder . ';'
        . '--cp-primary: ' . $clientAccent . ';'
        . '--cp-primary-dark: ' . $clientAccentMuted . ';'
        . '--cp-gradient: ' . $clientHeroGradient . ';'
        . '--cp-primary-soft: ' . $clientSoft . ';'
        . '--cp-primary-light: ' . $clientSoft . ';'
        . '--cp-primary-border: ' . $clientSoftBorder . ';'
        . '--cc-primary: ' . $clientAccent . ';'
        . '--cc-gradient: ' . $clientHeroGradient . ';'
        . '--cc-primary-soft: ' . $clientSoft . ';'
        . '--cc-primary-border: ' . $clientSoftBorder . ';'
        . '--ca-primary: ' . $clientAccent . ';'
        . '--ca-gradient: ' . $clientHeroGradient . ';'
        . '--ca-primary-soft: ' . $clientSoft . ';'
        . '--ca-primary-border: ' . $clientSoftBorder . ';'
        . '--cct-primary: ' . $clientAccent . ';'
        . '--cct-gradient: ' . $clientHeroGradient . ';'
        . '--cct-primary-soft: ' . $clientSoft . ';'
        . '--cct-primary-border: ' . $clientSoftBorder . ';'
        . '--cp-pay-primary: ' . $clientAccent . ';'
        . '--cp-pay-gradient: ' . $clientHeroGradient . ';'
        . '--cp-pay-primary-soft: ' . $clientSoft . ';'
        . '--cb-primary: ' . $clientAccent . ';'
        . '--cb-gradient: ' . $clientHeroGradient . ';'
        . '--cb-primary-soft: ' . $clientSoft . ';'
        . 'background: var(--lp-dark-bg) !important;'
        . 'background-color: var(--lp-dark-bg) !important;'
        . '}';

    $clientHeroCards = $clientDark . ' .cd-hero-card,'
        . $clientDark . ' .cc-hero-card,'
        . $clientDark . ' .ca-hero-card,'
        . $clientDark . ' .cct-hero-card,'
        . $clientDark . ' .cp-hero-card,'
        . $clientDark . ' .cb-hero-card';

    $css .= $clientHeroCards . ' {'
        . 'background: ' . $clientHeroGradient . ' !important;'
        . 'border: 1px solid var(--lp-dark-border) !important;'
        . 'box-shadow: 0 4px 20px rgba(15, 20, 35, 0.16) !important;'
        . '}';

    $css .= $clientDark . ' .cd-hero-actions .btn-primary-solid {'
        . 'background: #fff !important;'
        . 'color: var(--lp-dark-surface) !important;'
        . 'box-shadow: 0 4px 14px rgba(0, 0, 0, 0.2) !important;'
        . '}';

    $css .= $clientDark . ' .cd-hero-kicker,'
        . $clientDark . ' .cc-hero-kicker,'
        . $clientDark . ' .ca-hero-kicker,'
        . $clientDark . ' .cct-hero-kicker,'
        . $clientDark . ' .cp-hero-kicker,'
        . $clientDark . ' .cb-hero-kicker,'
        . $clientDark . ' .cd-hero .cd-hero-kicker {'
        . 'color: rgba(255, 255, 255, 0.75) !important;'
        . '}';

    $clientHeroCardText = $clientDark . ' .cd-hero-card .cd-hero-title,'
        . $clientDark . ' .cd-hero-card .cd-hero-sub,'
        . $clientDark . ' .cc-hero-card .cc-hero-title,'
        . $clientDark . ' .cc-hero-card .cc-hero-sub,'
        . $clientDark . ' .ca-hero-card .ca-hero-title,'
        . $clientDark . ' .ca-hero-card .ca-hero-sub,'
        . $clientDark . ' .cct-hero-card .cct-hero-title,'
        . $clientDark . ' .cct-hero-card .cct-hero-sub,'
        . $clientDark . ' .cct-hero-card .cct-hero-kicker,'
        . $clientDark . ' .cp-hero-card .cp-hero-title,'
        . $clientDark . ' .cp-hero-card .cp-hero-sub,'
        . $clientDark . ' .cb-hero-card .cb-hero-title,'
        . $clientDark . ' .cb-hero-card .cb-hero-sub,'
        . $clientDark . ' [class*="-hero-card"] h4,'
        . $clientDark . ' [class*="-hero-card"] p,'
        . $clientDark . ' [class*="-hero-card"] strong,'
        . $clientDark . ' .cct-stat-pill .num,'
        . $clientDark . ' .cct-stat-pill .lbl,'
        . $clientDark . ' .cc-stat-pill .num,'
        . $clientDark . ' .cc-stat-pill .lbl,'
        . $clientDark . ' .ca-stat-pill .num,'
        . $clientDark . ' .ca-stat-pill .lbl,'
        . $clientDark . ' .cp-stat-pill .num,'
        . $clientDark . ' .cp-stat-pill .lbl {'
        . 'color: #fff !important;'
        . '}';

    $css .= $clientHeroCardText;

    $css .= $clientDark . ' .cct-empty h5,'
        . $clientDark . ' .client-court-tracking-page .cct-empty h5 {'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= $clientDark . ' .cct-empty p,'
        . $clientDark . ' .client-court-tracking-page .cct-empty p {'
        . 'color: var(--lp-dark-text-muted) !important;'
        . '}';

    $clientPanels = $clientDark . ' .cd-panel,'
        . $clientDark . ' .cc-panel,'
        . $clientDark . ' .ca-panel,'
        . $clientDark . ' .ca-book-card,'
        . $clientDark . ' .cct-panel,'
        . $clientDark . ' .cp-panel,'
        . $clientDark . ' .cb-panel';

    $css .= $clientPanels . ' {'
        . 'background: var(--lp-dark-surface) !important;'
        . 'border-color: var(--lp-dark-border) !important;'
        . '}';

    $css .= $clientDark . ' .cd-panel-hdr,'
        . $clientDark . ' .cc-panel-header,'
        . $clientDark . ' .ca-panel-hdr,'
        . $clientDark . ' .cct-panel-hdr,'
        . $clientDark . ' .cp-panel-hdr,'
        . $clientDark . ' .cb-panel-hdr,'
        . $clientDark . ' .ca-book-hdr {'
        . 'border-bottom-color: var(--lp-dark-border) !important;'
        . '}';

    $css .= $clientDark . ' .cd-panel-title,'
        . $clientDark . ' .cc-panel-header h5,'
        . $clientDark . ' .ca-panel-hdr h5,'
        . $clientDark . ' .cct-panel-hdr h5,'
        . $clientDark . ' .cp-panel-hdr h5,'
        . $clientDark . ' .cb-panel-hdr h5,'
        . $clientDark . ' .ca-book-hdr h5,'
        . $clientDark . ' .case-title,'
        . $clientDark . ' .apt-title,'
        . $clientDark . ' .case-num {'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= $clientDark . ' .btn-cd-link,'
        . $clientDark . ' .btn-view,'
        . $clientDark . ' .btn-det,'
        . $clientDark . ' .btn-cct-view,'
        . $clientDark . ' .btn-cp-link,'
        . $clientDark . ' .btn-action,'
        . $clientDark . ' .cb-shortcut,'
        . $clientDark . ' .btn-outline-primary {'
        . 'color: ' . $clientAccent . ' !important;'
        . 'border-color: ' . $clientSoftBorder . ' !important;'
        . 'background: transparent !important;'
        . '}';

    $css .= $clientDark . ' .btn-cd-link:hover,'
        . $clientDark . ' .btn-view:hover,'
        . $clientDark . ' .btn-det:hover,'
        . $clientDark . ' .btn-cct-view:hover,'
        . $clientDark . ' .btn-cp-link:hover,'
        . $clientDark . ' .btn-action:hover,'
        . $clientDark . ' .cb-shortcut:hover,'
        . $clientDark . ' .btn-outline-primary:hover {'
        . 'background: var(--lp-dark-surface-hover) !important;'
        . 'color: #fff !important;'
        . 'border-color: rgba(255, 255, 255, 0.22) !important;'
        . '}';

    $css .= $clientDark . ' .cb-send-btn,'
        . $clientDark . ' .ca-book-btn {'
        . 'background: var(--lp-dark-surface-raised) !important;'
        . 'border: 1px solid var(--lp-dark-border-strong) !important;'
        . 'color: #fff !important;'
        . '}';

    $clientIconSurfaces = $clientDark . ' .cd-list-row__icon,'
        . $clientDark . ' .cc-list-row__icon,'
        . $clientDark . ' .ca-apt-icon,'
        . $clientDark . ' .cct-row-icon,'
        . $clientDark . ' .cp-row-icon,'
        . $clientDark . ' .case-icon,'
        . $clientDark . ' .cb-bot-avatar,'
        . $clientDark . ' .cct-empty-icon,'
        . $clientDark . ' .cp-empty-icon,'
        . $clientDark . ' .ca-empty-icon,'
        . $clientDark . ' .empty-icon,'
        . $clientDark . ' .legalpro-icon-wrap--soft-primary,'
        . $clientDark . ' .dashboard-stat-icon-wrap--primary,'
        . $clientDark . ' .dashboard-glance-icon-wrap--primary,'
        . $clientDark . ' .cc-case-icon.dashboard-stat-icon-wrap,'
        . $clientDark . ' .cc-empty-icon.dashboard-stat-icon-wrap,'
        . $clientDark . ' .ccv-service-icon.dashboard-stat-icon-wrap,'
        . $clientDark . ' .ccv-appt-icon.dashboard-stat-icon-wrap,'
        . $clientDark . ' .cd-kpi__icon--primary';

    $css .= $clientIconSurfaces . ' {'
        . 'background: ' . $clientIconSoft . ' !important;'
        . 'color: ' . $primary . ' !important;'
        . '}';

    $css .= $clientDark . ' .dashboard-stat-icon-wrap--primary .lp-icon svg,'
        . $clientDark . ' .dashboard-glance-icon-wrap--primary .lp-icon svg,'
        . $clientDark . ' .legalpro-icon-wrap--soft-primary .lp-icon svg,'
        . $clientDark . ' .cc-case-icon.dashboard-stat-icon-wrap .lp-icon svg,'
        . $clientDark . ' .cc-empty-icon.dashboard-stat-icon-wrap .lp-icon svg,'
        . $clientDark . ' .ccv-service-icon.dashboard-stat-icon-wrap .lp-icon svg,'
        . $clientDark . ' .ccv-appt-icon.dashboard-stat-icon-wrap .lp-icon svg,'
        . $clientDark . ' .cct-row-icon .lp-icon svg,'
        . $clientDark . ' .cp-row-icon .lp-icon svg,'
        . $clientDark . ' .cct-empty-icon .lp-icon svg,'
        . $clientDark . ' .cp-empty-icon .lp-icon svg {'
        . 'stroke: ' . $primary . ' !important;'
        . '}';

    $clientSemanticIconWraps = [
        'info' => ['bg' => 'rgba(17, 205, 239, 0.14)', 'stroke' => '#11cdef'],
        'success' => ['bg' => 'rgba(45, 206, 137, 0.14)', 'stroke' => '#2dce89'],
        'warning' => ['bg' => 'rgba(251, 99, 64, 0.14)', 'stroke' => '#fb6340'],
        'danger' => ['bg' => 'rgba(245, 54, 92, 0.14)', 'stroke' => '#f5365c'],
        'dark' => ['bg' => 'rgba(103, 116, 142, 0.16)', 'stroke' => '#c5cede'],
    ];
    foreach ($clientSemanticIconWraps as $tone => $meta) {
        $css .= $clientDark . ' .dashboard-stat-icon-wrap--' . $tone . ':not(.legalpro-doc-icon),'
            . $clientDark . ' .dashboard-glance-icon-wrap--' . $tone . ' {'
            . 'background: ' . $meta['bg'] . ' !important;'
            . 'color: ' . $meta['stroke'] . ' !important;'
            . '}';
        $css .= $clientDark . ' .dashboard-stat-icon-wrap--' . $tone . ':not(.legalpro-doc-icon) .lp-icon svg,'
            . $clientDark . ' .dashboard-glance-icon-wrap--' . $tone . ' .lp-icon svg {'
            . 'stroke: ' . $meta['stroke'] . ' !important;'
            . '}';
    }

    $css .= $clientDark . ' .cc-row-count,'
        . $clientDark . ' .ca-count,'
        . $clientDark . ' .cct-count,'
        . $clientDark . ' .cp-count {'
        . 'background: ' . $clientSoft . ' !important;'
        . 'color: ' . $clientAccent . ' !important;'
        . '}';

    $css .= $clientDark . ' .lawyer-stack__name,'
        . $clientDark . ' .lawyer-name {'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= $clientDark . ' .lawyer-stack__unassigned,'
        . $clientDark . ' .lawyer-stack__more {'
        . 'color: var(--lp-dark-text-muted) !important;'
        . '}';

    $css .= $clientDark . ' .lawyer-stack .avatar {'
        . 'border-color: var(--lp-dark-surface) !important;'
        . '}';

    $css .= $clientDark . ' .ca-status-pill--scheduled,'
        . $clientDark . ' .badge-review {'
        . 'background: ' . $clientSoft . ' !important;'
        . 'color: ' . $clientAccent . ' !important;'
        . '}';

    $css .= $clientDark . ' .cd-list-row:hover,'
        . $clientDark . ' .cc-list-row:hover,'
        . $clientDark . ' .ca-appt-row:hover,'
        . $clientDark . ' .cd-appt-row:hover,'
        . $clientDark . ' .cc-table tbody tr:hover,'
        . $clientDark . ' .ca-table tbody tr:hover,'
        . $clientDark . ' .cct-table tbody tr:hover,'
        . $clientDark . ' .cp-table tbody tr:hover {'
        . 'background: ' . $clientSoft . ' !important;'
        . 'border-color: ' . $clientSoftBorder . ' !important;'
        . '}';

    $css .= $clientDark . ' .cc-search-input:focus,'
        . $clientDark . ' .ca-fld select:focus,'
        . $clientDark . ' .ca-fld input[type="date"]:focus,'
        . $clientDark . ' .ca-fld textarea:focus,'
        . $clientDark . ' .chat-compose .chat-input:focus {'
        . 'border-color: var(--lp-dark-border-strong) !important;'
        . 'box-shadow: 0 0 0 3px rgba(255, 255, 255, 0.08) !important;'
        . '}';

    $css .= $clientDark . ' .navbar-main .legalpro-navbar-search .input-group:focus-within,'
        . $clientDark . ' .search-hero-field:focus-within {'
        . 'border-color: var(--lp-dark-border-strong) !important;'
        . 'box-shadow: 0 0 0 0.2rem rgba(255, 255, 255, 0.1) !important;'
        . '}';

    $css .= $clientDark . ' .chat-message-user .chat-bubble {'
        . 'background: var(--lp-dark-surface-raised) !important;'
        . 'color: var(--lp-dark-text) !important;'
        . 'border: 1px solid var(--lp-dark-border) !important;'
        . '}';

    $css .= $clientDark . ' .chat-message-bot .chat-bubble {'
        . 'background: var(--lp-dark-surface-hover) !important;'
        . 'border-color: var(--lp-dark-border) !important;'
        . 'color: var(--lp-dark-text-secondary) !important;'
        . '}';

    $css .= $clientDark . ' .cd-appt-row__date,'
        . $clientDark . ' .ca-appt-row__date {'
        . 'background: ' . $clientSoft . ' !important;'
        . '}';

    $css .= $clientDark . ' .cd-appt-row__day,'
        . $clientDark . ' .ca-appt-row__day {'
        . 'color: ' . $clientAccent . ' !important;'
        . '}';

    $css .= $clientDark . ' .cd-kpi--primary {'
        . '--kpi-color: ' . $primary . ' !important;'
        . '}';

    $css .= $clientDark . ' .text-primary,'
        . $clientDark . ' a.text-primary {'
        . 'color: ' . $clientAccent . ' !important;'
        . '}';

    $css .= $clientDark . ' .cc-table thead th,'
        . $clientDark . ' .ca-table thead th,'
        . $clientDark . ' .cct-table thead th,'
        . $clientDark . ' .cp-table thead th {'
        . 'background: var(--lp-dark-surface-raised) !important;'
        . 'color: var(--lp-dark-text-muted) !important;'
        . 'border-bottom-color: var(--lp-dark-border) !important;'
        . '}';

    $css .= $clientDark . ' .ca-time-btn.selected {'
        . 'background: var(--lp-dark-surface-raised) !important;'
        . 'border-color: ' . $clientAccent . ' !important;'
        . 'color: #fff !important;'
        . '}';

    $css .= $clientDark . ' .ca-book-hdr {'
        . 'background: var(--lp-dark-surface-raised) !important;'
        . 'border-bottom-color: var(--lp-dark-border) !important;'
        . '}';

    $css .= $clientDark . ' .ca-book-hdr p,'
        . $clientDark . ' .ca-avail-hint {'
        . 'color: var(--lp-dark-text-muted) !important;'
        . '}';

    $css .= $clientDark . ' .ca-fld label {'
        . 'color: var(--lp-dark-text-muted) !important;'
        . '}';

    $css .= $clientDark . ' .ca-fld label span {'
        . 'color: var(--lp-dark-text-muted) !important;'
        . '}';

    $css .= $clientDark . ' .ca-fld select,'
        . $clientDark . ' .ca-fld input[type="date"],'
        . $clientDark . ' .ca-fld textarea {'
        . 'background: var(--lp-dark-input-bg) !important;'
        . 'border-color: var(--lp-dark-border-strong) !important;'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= $clientDark . ' .ca-fld input[type="date"] {'
        . 'color-scheme: dark;'
        . '}';

    $css .= $clientDark . ' .ca-fld select option {'
        . 'background: var(--lp-dark-surface) !important;'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= $clientDark . ' .ca-fld textarea::placeholder {'
        . 'color: var(--lp-dark-text-muted) !important;'
        . 'opacity: 1;'
        . '}';

    $css .= $clientDark . ' .ca-time-btn {'
        . 'background: var(--lp-dark-surface-hover) !important;'
        . 'border-color: var(--lp-dark-border) !important;'
        . 'color: var(--lp-dark-text-muted) !important;'
        . '}';

    $css .= $clientDark . ' .ca-time-btn.available {'
        . 'background: rgba(45, 206, 137, 0.14) !important;'
        . 'border-color: rgba(45, 206, 137, 0.4) !important;'
        . 'color: #86efac !important;'
        . '}';

    $css .= $clientDark . ' .ca-time-btn.available:hover {'
        . 'background: rgba(45, 206, 137, 0.22) !important;'
        . 'border-color: rgba(45, 206, 137, 0.55) !important;'
        . 'color: #bbf7d0 !important;'
        . '}';

    $css .= $clientDark . ' .ca-avail-alert.warning {'
        . 'background: rgba(251, 191, 36, 0.14) !important;'
        . 'color: #fcd34d !important;'
        . '}';

    $css .= $clientDark . ' .ca-avail-alert.info {'
        . 'background: ' . $clientSoft . ' !important;'
        . 'color: ' . $clientAccent . ' !important;'
        . 'border: 1px solid ' . $clientSoftBorder . ' !important;'
        . '}';

    $css .= $clientDark . ' .ca-book-btn:hover:not(:disabled),'
        . $clientDark . ' .ca-book-btn:focus:not(:disabled) {'
        . 'background: var(--lp-dark-surface-hover) !important;'
        . 'color: #fff !important;'
        . '}';

    $css .= $clientDark . ' .cd-next-appt,'
        . $clientDark . ' .cd-list-item:hover {'
        . 'background: ' . $clientSoft . ' !important;'
        . 'border-color: ' . $clientSoftBorder . ' !important;'
        . '}';

    $css .= $clientDark . ' .cc-pill {'
        . 'background: ' . $clientSoft . ' !important;'
        . 'color: ' . $clientAccent . ' !important;'
        . '}';

    $css .= $clientDark . ' .cc-comment-list::-webkit-scrollbar-thumb {'
        . 'background: rgba(255, 255, 255, 0.18) !important;'
        . '}';

    $css .= $clientDark . ' .cc-comment-item--yours .cc-comment-item-inner {'
        . 'background: var(--lp-dark-surface-raised) !important;'
        . 'border-color: var(--lp-dark-border) !important;'
        . '}';

    $css .= $clientDark . ' .navbar-main .legalpro-navbar-search .input-group:hover,'
        . $clientDark . ' .search-hero-field:hover {'
        . 'border-color: var(--lp-dark-border-strong) !important;'
        . 'background: var(--lp-dark-surface-hover) !important;'
        . '}';

    $css .= $clientDark . ' #sidenav-main.legalpro-admin-sidebar .legalpro-sidebar-nav .nav-link.active {'
        . 'background: var(--lp-dark-surface-raised) !important;'
        . 'background-image: none !important;'
        . 'border: 1px solid var(--lp-dark-border-strong) !important;'
        . 'box-shadow: none !important;'
        . 'color: #fff !important;'
        . '}';

    $css .= $clientDark . ' .btn.bg-gradient-primary {'
        . 'background: var(--lp-dark-surface-raised) !important;'
        . 'background-image: none !important;'
        . 'border: 1px solid var(--lp-dark-border-strong) !important;'
        . 'color: #fff !important;'
        . '}';

    $css .= $clientDark . ' .btn.bg-gradient-primary:hover,'
        . $clientDark . ' .btn.bg-gradient-primary:focus {'
        . 'background: var(--lp-dark-surface-hover) !important;'
        . 'background-image: none !important;'
        . 'color: #fff !important;'
        . '}';

    $css .= $clientDark . ' .client-chatbot-page .chat-window {'
        . 'background: var(--lp-dark-surface) !important;'
        . 'border-color: var(--lp-dark-border) !important;'
        . '}';

    $css .= $clientDark . ' .cb-tips,'
        . $clientDark . ' .client-chatbot-page .cb-tips {'
        . 'background: var(--lp-dark-surface-raised) !important;'
        . 'border-color: var(--lp-dark-border) !important;'
        . '}';

    $css .= $clientDark . ' .cb-tips p,'
        . $clientDark . ' .chat-tips p {'
        . 'color: var(--lp-dark-text-secondary) !important;'
        . '}';

    $css .= $clientDark . ' .cb-tips p strong,'
        . $clientDark . ' .chat-tips p strong {'
        . 'color: ' . $clientAccent . ' !important;'
        . '}';

    $css .= $clientDark . ' .cb-panel-hdr p,'
        . $clientDark . ' .client-chatbot-page .cb-hint,'
        . $clientDark . ' .client-chatbot-page .chat-message-bot .cb-hint {'
        . 'color: var(--lp-dark-text-muted) !important;'
        . 'opacity: 1 !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .chat-tips p {'
        . 'color: var(--lp-dark-text-secondary) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .chat-tips p strong {'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.client-court-tracking-page #courtTrackingCalendar .fc .fc-toolbar.fc-header-toolbar {'
        . 'background: var(--lp-dark-surface-raised) !important;'
        . 'background-image: none !important;'
        . 'border: 1px solid var(--lp-dark-border) !important;'
        . 'border-radius: 0.5rem !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.client-court-tracking-page #courtTrackingCalendar .fc .fc-toolbar.fc-header-toolbar .fc-button {'
        . 'background: var(--lp-dark-surface-hover) !important;'
        . 'background-image: none !important;'
        . 'border-color: var(--lp-dark-border-strong) !important;'
        . 'color: #fff !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.client-court-tracking-page #courtTrackingCalendar .fc .fc-toolbar.fc-header-toolbar .fc-button:hover,'
        . 'body.legalpro-dark-mode.client-court-tracking-page #courtTrackingCalendar .fc .fc-toolbar.fc-header-toolbar .fc-button:focus,'
        . 'body.legalpro-dark-mode.client-court-tracking-page #courtTrackingCalendar .fc .fc-toolbar.fc-header-toolbar .fc-button.fc-button-active {'
        . 'background: var(--lp-dark-surface) !important;'
        . 'background-image: none !important;'
        . 'border-color: ' . $clientAccent . ' !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.client-court-tracking-page #courtTrackingCalendar .fc-daygrid-more-link {'
        . 'color: ' . $clientAccent . ' !important;'
        . '}';

    return $css;
}

function getPortalThemeCalendarDarkCss(): string
{
    $theme = getPortalTheme();
    if ($theme['mode'] !== 'dark') {
        return '';
    }

    $primary = $theme['preset']['primary'];
    $rgb = portalThemePrimaryRgb($primary);

    $css = 'body.legalpro-dark-mode .dashboard-calendar-hub {'
        . 'background: var(--lp-dark-surface) !important;'
        . 'border: 1px solid var(--lp-dark-border) !important;'
        . 'border-radius: 1rem !important;'
        . 'overflow: visible !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.admin-court-tracking-page .dashboard-calendar-hub__head {'
        . 'padding: 1.35rem 1.75rem 1.15rem !important;'
        . 'border-bottom-color: var(--lp-dark-border) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .dashboard-calendar-hub__body {'
        . 'background: transparent !important;'
        . '}';

    $calRoots = 'body.legalpro-dark-mode #dashboardCalendar,'
        . 'body.legalpro-dark-mode #courtTrackingCalendar,'
        . 'body.legalpro-dark-mode #lawyerAppointmentsCalendar,'
        . 'body.legalpro-dark-mode #clientAppointmentsCalendar,'
        . 'body.legalpro-dark-mode #appointmentsCalendar';

    $css .= $calRoots . ','
        . 'body.legalpro-dark-mode.client-court-tracking-page #courtTrackingCalendar,'
        . 'body.legalpro-dark-mode.client-court-tracking-page #courtTrackingCalendar .fc,'
        . 'body.legalpro-dark-mode.client-court-tracking-page #courtTrackingCalendar .fc-view-harness,'
        . 'body.legalpro-dark-mode #lawyerAppointmentsCalendar .fc,'
        . 'body.legalpro-dark-mode #clientAppointmentsCalendar .fc,'
        . 'body.legalpro-dark-mode #appointmentsCalendar .fc,'
        . 'body.legalpro-dark-mode #lawyerAppointmentsCalendar .fc-view-harness,'
        . 'body.legalpro-dark-mode #clientAppointmentsCalendar .fc-view-harness,'
        . 'body.legalpro-dark-mode #appointmentsCalendar .fc-view-harness {'
        . 'background: var(--lp-dark-surface) !important;'
        . 'border-color: var(--lp-dark-border) !important;'
        . 'color: var(--lp-dark-text-secondary) !important;'
        . 'box-shadow: none !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode #dashboardCalendar .fc .fc-scrollgrid,'
        . 'body.legalpro-dark-mode #courtTrackingCalendar .fc .fc-scrollgrid,'
        . 'body.legalpro-dark-mode #courtTrackingCalendar .fc-theme-standard .fc-scrollgrid,'
        . 'body.legalpro-dark-mode #lawyerAppointmentsCalendar .fc .fc-scrollgrid,'
        . 'body.legalpro-dark-mode #clientAppointmentsCalendar .fc .fc-scrollgrid,'
        . 'body.legalpro-dark-mode #appointmentsCalendar .fc .fc-scrollgrid {'
        . 'background: var(--lp-dark-surface) !important;'
        . 'border-color: var(--lp-dark-border) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode #dashboardCalendar .fc .fc-col-header-cell,'
        . 'body.legalpro-dark-mode #dashboardCalendar .fc .fc-daygrid-day,'
        . 'body.legalpro-dark-mode #courtTrackingCalendar .fc .fc-daygrid-day,'
        . 'body.legalpro-dark-mode #lawyerAppointmentsCalendar .fc .fc-daygrid-day,'
        . 'body.legalpro-dark-mode #clientAppointmentsCalendar .fc .fc-daygrid-day,'
        . 'body.legalpro-dark-mode #appointmentsCalendar .fc .fc-daygrid-day {'
        . 'background: var(--lp-dark-surface) !important;'
        . 'border-color: var(--lp-dark-border) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode #dashboardCalendar .fc .fc-col-header-cell-cushion,'
        . 'body.legalpro-dark-mode #dashboardCalendar .fc .fc-daygrid-day-number,'
        . 'body.legalpro-dark-mode #courtTrackingCalendar .fc .fc-daygrid-day-number,'
        . 'body.legalpro-dark-mode #lawyerAppointmentsCalendar .fc .fc-daygrid-day-number,'
        . 'body.legalpro-dark-mode #clientAppointmentsCalendar .fc .fc-daygrid-day-number,'
        . 'body.legalpro-dark-mode #appointmentsCalendar .fc .fc-daygrid-day-number {'
        . 'color: var(--lp-dark-text-secondary) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode #dashboardCalendar .fc .fc-toolbar.fc-header-toolbar .fc-toolbar-title,'
        . 'body.legalpro-dark-mode #courtTrackingCalendar .fc .fc-toolbar.fc-header-toolbar .fc-toolbar-title,'
        . 'body.legalpro-dark-mode #lawyerAppointmentsCalendar .fc .fc-toolbar.fc-header-toolbar .fc-toolbar-title,'
        . 'body.legalpro-dark-mode #clientAppointmentsCalendar .fc .fc-toolbar.fc-header-toolbar .fc-toolbar-title,'
        . 'body.legalpro-dark-mode #appointmentsCalendar .fc .fc-toolbar.fc-header-toolbar .fc-toolbar-title {'
        . 'color: #fff !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode #dashboardCalendar .fc .fc-day-today,'
        . 'body.legalpro-dark-mode #courtTrackingCalendar .fc .fc-day-today,'
        . 'body.legalpro-dark-mode #lawyerAppointmentsCalendar .fc .fc-day-today,'
        . 'body.legalpro-dark-mode #clientAppointmentsCalendar .fc .fc-day-today,'
        . 'body.legalpro-dark-mode #appointmentsCalendar .fc .fc-day-today {'
        . 'background: rgba(' . $rgb . ', 0.12) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode #courtTrackingCalendar .fc-theme-standard td,'
        . 'body.legalpro-dark-mode #courtTrackingCalendar .fc-theme-standard th,'
        . 'body.legalpro-dark-mode #courtTrackingCalendar .fc .fc-scrollgrid-section > *,'
        . 'body.legalpro-dark-mode #lawyerAppointmentsCalendar .fc-theme-standard td,'
        . 'body.legalpro-dark-mode #lawyerAppointmentsCalendar .fc-theme-standard th,'
        . 'body.legalpro-dark-mode #clientAppointmentsCalendar .fc-theme-standard td,'
        . 'body.legalpro-dark-mode #clientAppointmentsCalendar .fc-theme-standard th,'
        . 'body.legalpro-dark-mode #appointmentsCalendar .fc-theme-standard td,'
        . 'body.legalpro-dark-mode #appointmentsCalendar .fc-theme-standard th {'
        . 'border-color: var(--lp-dark-border) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode #courtTrackingCalendar .fc .fc-col-header-cell,'
        . 'body.legalpro-dark-mode #lawyerAppointmentsCalendar .fc .fc-col-header-cell,'
        . 'body.legalpro-dark-mode #clientAppointmentsCalendar .fc .fc-col-header-cell,'
        . 'body.legalpro-dark-mode #appointmentsCalendar .fc .fc-col-header-cell {'
        . 'background: var(--lp-dark-surface-raised) !important;'
        . 'border-color: var(--lp-dark-border) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode #courtTrackingCalendar .fc .fc-col-header-cell-cushion,'
        . 'body.legalpro-dark-mode #lawyerAppointmentsCalendar .fc .fc-col-header-cell-cushion,'
        . 'body.legalpro-dark-mode #clientAppointmentsCalendar .fc .fc-col-header-cell-cushion,'
        . 'body.legalpro-dark-mode #appointmentsCalendar .fc .fc-col-header-cell-cushion {'
        . 'color: var(--lp-dark-text-muted) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode #courtTrackingCalendar .fc .fc-day-other .fc-daygrid-day-number,'
        . 'body.legalpro-dark-mode #lawyerAppointmentsCalendar .fc .fc-day-other .fc-daygrid-day-number,'
        . 'body.legalpro-dark-mode #clientAppointmentsCalendar .fc .fc-day-other .fc-daygrid-day-number,'
        . 'body.legalpro-dark-mode #appointmentsCalendar .fc .fc-day-other .fc-daygrid-day-number {'
        . 'color: var(--lp-dark-text-subtle) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .dashboard-calendar-hub .fc-daygrid-day.lp-cal-day-has-events:hover,'
        . 'body.legalpro-dark-mode .dashboard-calendar-hub .fc-daygrid-day.fc-day-has-events:not(.fc-day-today):hover {'
        . 'background: rgba(' . $rgb . ', 0.14) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .dashboard-calendar-hub .fc-daygrid-event:hover .dashboard-cal-event__text,'
        . 'body.legalpro-dark-mode .dashboard-calendar-hub .fc-daygrid-event:focus .dashboard-cal-event__text,'
        . 'body.legalpro-dark-mode .dashboard-calendar-hub .fc-list-event:hover .dashboard-cal-event__text {'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.client-court-tracking-page #courtTrackingCalendar .fc .fc-day-today {'
        . 'background: rgba(' . $rgb . ', 0.12) !important;'
        . '}';

    return $css;
}

function renderPortalThemeCalendarDarkHead(): void
{
    static $rendered = false;
    if ($rendered) {
        return;
    }

    $css = getPortalThemeCalendarDarkCss();
    if ($css === '') {
        return;
    }

    $rendered = true;
    echo '<style id="legalpro-portal-calendar-dark">' . $css . '</style>';
}

function portalThemePrimaryRgb(string $hex): string
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if (strlen($hex) !== 6) {
        return '94, 114, 228';
    }

    return hexdec(substr($hex, 0, 2)) . ', ' . hexdec(substr($hex, 2, 2)) . ', ' . hexdec(substr($hex, 4, 2));
}

function renderPortalThemeCss(): string
{
    $theme = getPortalTheme();
    $preset = $theme['preset'];
    $primary = $preset['primary'];
    $primaryDark = $preset['primary_dark'];
    $sidebarBg = $preset['sidebar_bg'];
    $sidebarDeep = $preset['sidebar_deep'];
    $rgb = portalThemePrimaryRgb($primary);
    $soft14 = portalThemeHexToRgba($primary, 0.14);
    $soft12 = portalThemeHexToRgba($primary, 0.12);
    $soft08 = portalThemeHexToRgba($primary, 0.08);
    $soft35 = portalThemeHexToRgba($primary, 0.35);
    $gradient = 'linear-gradient(135deg, ' . $primary . ' 0%, ' . $primaryDark . ' 100%)';
    $gradient310 = 'linear-gradient(310deg, ' . $primary . ' 0%, ' . $primaryDark . ' 100%)';
    $lawyerGradient = 'linear-gradient(140deg, ' . $sidebarDeep . ' 0%, ' . $primary . ' 44%, ' . $primaryDark . ' 100%)';
    $sidebarGradient = 'linear-gradient(180deg, ' . $sidebarBg . ' 0%, ' . $sidebarDeep . ' 100%)';

    $css = ':root {'
        . '--bs-primary: ' . $primary . ';'
        . '--bs-primary-rgb: ' . $rgb . ';'
        . '--bs-link-color: ' . $primary . ';'
        . '--bs-link-color-rgb: ' . $rgb . ';'
        . '--bs-focus-ring-color: rgba(' . $rgb . ', 0.25);'
        . '--lp-admin-primary: ' . $primary . ';'
        . '--lp-admin-primary-dark: ' . $primaryDark . ';'
        . '--lp-admin-gradient: ' . $gradient . ';'
        . '--lp-admin-sidebar-bg: ' . $sidebarBg . ';'
        . '--lp-admin-sidebar-bg-deep: ' . $sidebarDeep . ';'
        . '--lp-portal-primary: ' . $primary . ';'
        . '--lp-portal-primary-dark: ' . $primaryDark . ';'
        . '--lp-portal-sidebar-bg: ' . $sidebarBg . ';'
        . '--lp-portal-sidebar-bg-deep: ' . $sidebarDeep . ';'
        . '--lp-portal-gradient: ' . $gradient . ';'
        . '--client-portal-gradient: ' . $gradient . ';'
        . '--lawyer-portal-gradient: ' . $lawyerGradient . ';'
        . '--lawyer-portal-bg-fallback: ' . $sidebarDeep . ';'
        . '--lp-cases-accent-soft: ' . $soft12 . ';'
        . '--lp-cases-accent-border: ' . $soft35 . ';'
        . '--legalpro-theme-primary: ' . $primary . ';'
        . '--legalpro-theme-primary-dark: ' . $primaryDark . ';'
        . '--legalpro-theme-primary-rgb: ' . $rgb . ';'
        . '--legalpro-theme-gradient: ' . $gradient . ';'
        . '--legalpro-theme-gradient-310: ' . $gradient310 . ';'
        . '}';

    $primarySelectors = '.bg-gradient-primary,'
        . '.btn.bg-gradient-primary,'
        . '.lp-card-header-primary,'
        . '.modal-header.bg-gradient-primary,'
        . '.icon-shape.bg-gradient-primary';

    $css .= $primarySelectors . ' {'
        . 'background-color: ' . $primary . ' !important;'
        . 'background-image: ' . $gradient310 . ' !important;'
        . 'border-color: ' . $primary . ' !important;'
        . '}';

    $css .= '.btn-primary,'
        . '.btn.btn-primary {'
        . '--bs-btn-bg: ' . $primary . ';'
        . '--bs-btn-border-color: ' . $primary . ';'
        . '--bs-btn-hover-bg: ' . $primaryDark . ';'
        . '--bs-btn-hover-border-color: ' . $primaryDark . ';'
        . '--bs-btn-active-bg: ' . $primaryDark . ';'
        . '--bs-btn-active-border-color: ' . $primaryDark . ';'
        . '--bs-btn-disabled-bg: ' . $primary . ';'
        . '--bs-btn-disabled-border-color: ' . $primary . ';'
        . 'background-color: ' . $primary . ' !important;'
        . 'background-image: ' . $gradient310 . ' !important;'
        . 'border-color: ' . $primary . ' !important;'
        . '}';

    $css .= '.btn-outline-primary {'
        . '--bs-btn-color: ' . $primary . ';'
        . '--bs-btn-border-color: ' . $primary . ';'
        . '--bs-btn-hover-bg: ' . $primary . ';'
        . '--bs-btn-hover-border-color: ' . $primary . ';'
        . '--bs-btn-active-bg: ' . $primary . ';'
        . '--bs-btn-active-border-color: ' . $primary . ';'
        . 'color: ' . $primary . ' !important;'
        . 'border-color: ' . $primary . ' !important;'
        . '}';

    $css .= '.text-primary,'
        . 'a.text-primary,'
        . '.text-xs.text-primary,'
        . 'h6.text-primary,'
        . 'p.text-primary {'
        . 'color: ' . $primary . ' !important;'
        . '}';

    $css .= '.border-primary { border-color: ' . $primary . ' !important; }';

    $css .= '#sidenav-main.legalpro-admin-sidebar,'
        . '.legalpro-admin-sidebar {'
        . 'background: ' . $sidebarGradient . ' !important;'
        . '}';

    $css .= '#sidenav-main.legalpro-admin-sidebar .legalpro-sidebar-nav .nav-link.active,'
        . '.legalpro-admin-sidebar .legalpro-sidebar-nav .nav-link.active,'
        . '#sidenav-main .legalpro-sidebar-nav .nav-link.active {'
        . 'background: ' . $gradient . ' !important;'
        . 'color: #fff !important;'
        . 'box-shadow: 0 8px 18px ' . portalThemeHexToRgba($primary, 0.35) . ' !important;'
        . '}';

    $css .= '.dashboard-stat-icon-wrap--primary {'
        . 'background: ' . $soft12 . ' !important;'
        . '}';

    $css .= '.dashboard-stat-icon-wrap--primary .lp-icon svg,'
        . '.lp-icon--primary svg {'
        . 'stroke: ' . $primary . ' !important;'
        . '}';

    $css .= '.ca-status-pill--scheduled,'
        . '.lp-pill--status-progress {'
        . 'background: ' . $soft14 . ' !important;'
        . 'color: ' . $primary . ' !important;'
        . '}';

    $css .= '.form-control:focus,'
        . '.form-select:focus,'
        . 'textarea.form-control:focus {'
        . 'border-color: ' . $primary . ' !important;'
        . 'box-shadow: 0 0 0 0.2rem rgba(' . $rgb . ', 0.15) !important;'
        . '}';

    $css .= '.legalpro-navbar-search .input-group .form-control:focus,'
        . '.legalpro-navbar-search .input-group input[type="search"].form-control:focus,'
        . 'body.legalpro-client-portal .search-hero-field .form-control:focus,'
        . 'body.legalpro-client-portal .search-hero-field input[type="search"].form-control:focus,'
        . '.search-portal-page .search-hero-field .form-control:focus {'
        . 'border-color: transparent !important;'
        . 'box-shadow: none !important;'
        . '}';

    $css .= '.page-item.active .page-link,'
        . '.pagination .page-item.active .page-link {'
        . 'background-color: ' . $primary . ' !important;'
        . 'border-color: ' . $primary . ' !important;'
        . '}';

    $css .= '.progress-bar,'
        . '.progress .progress-bar {'
        . 'background-color: ' . $primary . ' !important;'
        . '}';

    $css .= '.settings-theme-mode__option:has(input:checked),'
        . '.settings-theme-swatch.active .settings-theme-swatch__dot,'
        . '.settings-theme-swatch:has(input:checked) .settings-theme-swatch__dot {'
        . 'border-color: ' . $primary . ' !important;'
        . '}';

    $css .= '.settings-theme-mode__option:has(input:checked) {'
        . 'background: ' . $soft08 . ' !important;'
        . '}';

    $css .= '.settings-theme-swatch.active .settings-theme-swatch__dot,'
        . '.settings-theme-swatch:has(input:checked) .settings-theme-swatch__dot {'
        . 'box-shadow: 0 0 0 3px ' . portalThemeHexToRgba($primary, 0.25) . ' !important;'
        . '}';

    $css .= '.cc-case-row:hover td,'
        . '.cct-row:hover td,'
        . '.cp-row:hover td {'
        . 'background-color: ' . $soft08 . ' !important;'
        . '}';

    $css .= '.chat-message-user .chat-bubble {'
        . 'background: ' . $primary . ' !important;'
        . '}';

    $fcToolbar = '.fc .fc-toolbar.fc-header-toolbar,'
        . '#dashboardCalendar .fc .fc-toolbar.fc-header-toolbar,'
        . '#courtTrackingCalendar .fc .fc-toolbar.fc-header-toolbar';

    $fcToolbarBtn = '.fc .fc-toolbar.fc-header-toolbar .fc-button,'
        . '#dashboardCalendar .fc .fc-toolbar.fc-header-toolbar .fc-button,'
        . '#courtTrackingCalendar .fc .fc-toolbar.fc-header-toolbar .fc-button';

    $fcToolbarBtnHover = '.fc .fc-toolbar.fc-header-toolbar .fc-button:hover,'
        . '.fc .fc-toolbar.fc-header-toolbar .fc-button:focus,'
        . '.fc .fc-toolbar.fc-header-toolbar .fc-button:active,'
        . '#dashboardCalendar .fc .fc-toolbar.fc-header-toolbar .fc-button:hover,'
        . '#dashboardCalendar .fc .fc-toolbar.fc-header-toolbar .fc-button:focus,'
        . '#dashboardCalendar .fc .fc-toolbar.fc-header-toolbar .fc-button:active,'
        . '#courtTrackingCalendar .fc .fc-toolbar.fc-header-toolbar .fc-button:hover,'
        . '#courtTrackingCalendar .fc .fc-toolbar.fc-header-toolbar .fc-button:focus,'
        . '#courtTrackingCalendar .fc .fc-toolbar.fc-header-toolbar .fc-button:active';

    $fcToolbarBtnActive = '.fc .fc-toolbar.fc-header-toolbar .fc-button-primary:not(:disabled).fc-button-active,'
        . '.fc .fc-toolbar.fc-header-toolbar .fc-button-primary:not(:disabled):active,'
        . '.fc .fc-toolbar.fc-header-toolbar .fc-button.fc-button-active,'
        . '#dashboardCalendar .fc .fc-toolbar.fc-header-toolbar .fc-button-primary:not(:disabled).fc-button-active,'
        . '#dashboardCalendar .fc .fc-toolbar.fc-header-toolbar .fc-button-primary:not(:disabled):active,'
        . '#dashboardCalendar .fc .fc-toolbar.fc-header-toolbar .fc-button.fc-button-active,'
        . '#courtTrackingCalendar .fc .fc-toolbar.fc-header-toolbar .fc-button-primary:not(:disabled).fc-button-active,'
        . '#courtTrackingCalendar .fc .fc-toolbar.fc-header-toolbar .fc-button-primary:not(:disabled):active,'
        . '#courtTrackingCalendar .fc .fc-toolbar.fc-header-toolbar .fc-button.fc-button-active';

    $css .= $fcToolbar . ' {'
        . 'background-color: ' . $primary . ' !important;'
        . 'background-image: ' . $gradient310 . ' !important;'
        . '}';

    $css .= '.fc .fc-toolbar-title,'
        . '#dashboardCalendar .fc .fc-toolbar-title,'
        . '#courtTrackingCalendar .fc .fc-toolbar-title {'
        . 'color: #fff !important;'
        . '}';

    $css .= $fcToolbarBtn . ' {'
        . 'background-color: rgba(255, 255, 255, 0.22) !important;'
        . 'background-image: none !important;'
        . 'border: 1.5px solid rgba(255, 255, 255, 0.92) !important;'
        . 'color: #fff !important;'
        . 'font-weight: 700 !important;'
        . 'text-shadow: 0 1px 2px rgba(0, 0, 0, 0.22) !important;'
        . '}';

    $css .= $fcToolbarBtnHover . ' {'
        . 'background-color: rgba(255, 255, 255, 0.38) !important;'
        . 'background-image: none !important;'
        . 'border-color: #fff !important;'
        . 'color: #fff !important;'
        . '}';

    $css .= $fcToolbarBtnActive . ' {'
        . 'background-color: #fff !important;'
        . 'background-image: none !important;'
        . 'border-color: #fff !important;'
        . 'color: ' . $primary . ' !important;'
        . 'text-shadow: none !important;'
        . '}';

    $css .= '.fc .fc-toolbar.fc-header-toolbar .fc-icon,'
        . '.fc .fc-toolbar.fc-header-toolbar .fc-icon-chevron-left,'
        . '.fc .fc-toolbar.fc-header-toolbar .fc-icon-chevron-right,'
        . '#dashboardCalendar .fc .fc-toolbar.fc-header-toolbar .fc-icon,'
        . '#courtTrackingCalendar .fc .fc-toolbar.fc-header-toolbar .fc-icon {'
        . 'color: #fff !important;'
        . '}';

    $css .= '.simple-calendar .calendar-header {'
        . 'background: ' . $gradient . ' !important;'
        . 'color: #fff !important;'
        . '}';

    $css .= '#dashboardCalendar .fc-daygrid-more-link,'
        . '#courtTrackingCalendar .fc-daygrid-more-link {'
        . 'color: ' . $primary . ' !important;'
        . '}';

    if (isEffectivePortalThemeDark()) {
        $css .= renderPortalThemeDarkCss($primary, $rgb);
    }

    $css .= renderModernSoftBadgeCss($primary);

    return $css;
}

function renderModernSoftBadgeCss(string $primary): string
{
    $primarySoft = portalThemeHexToRgba($primary, 0.14);
    $primarySoftDark = portalThemeHexToRgba($primary, 0.22);
    $primaryText = $primary;

    $css = '.badge.bg-gradient-primary:not(.filter),'
        . '.badge.bg-gradient-info:not(.filter) {'
        . 'background: ' . $primarySoft . ' !important;'
        . 'background-image: none !important;'
        . 'color: ' . $primaryText . ' !important;'
        . 'border: none !important;'
        . 'font-weight: 700 !important;'
        . 'border-radius: 999px !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .badge.bg-gradient-primary:not(.filter),'
        . 'body.legalpro-dark-mode .badge.bg-gradient-info:not(.filter) {'
        . 'background: ' . $primarySoftDark . ' !important;'
        . 'color: ' . portalThemeMixHex($primary, '#ffffff', 0.55) . ' !important;'
        . '}';

    return $css;
}

function portalThemeHexToRgba(string $hex, float $alpha): string
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if (strlen($hex) !== 6) {
        return 'rgba(94, 114, 228, ' . $alpha . ')';
    }

    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));

    return 'rgba(' . $r . ', ' . $g . ', ' . $b . ', ' . $alpha . ')';
}

/**
 * Sidebar-only theme CSS for inline paint (avoids purple flash before external stylesheets).
 */
function renderPortalSidebarPaintCss(): string
{
    $theme = getPortalTheme();
    $preset = $theme['preset'];
    $primary = $preset['primary'];
    $primaryDark = $preset['primary_dark'];
    $sidebarBg = $preset['sidebar_bg'];
    $sidebarDeep = $preset['sidebar_deep'];
    $gradient = 'linear-gradient(135deg, ' . $primary . ' 0%, ' . $primaryDark . ' 100%)';
    $sidebarGradient = 'linear-gradient(180deg, ' . $sidebarBg . ' 0%, ' . $sidebarDeep . ' 100%)';
    $shadow = portalThemeHexToRgba($primary, 0.35);

    return '#sidenav-main.legalpro-admin-sidebar,'
        . '#sidenav-main.lp-sidebar {'
        . 'background: ' . $sidebarGradient . ' !important;'
        . 'background-color: ' . $sidebarBg . ' !important;'
        . '}'
        . '#sidenav-main .legalpro-sidebar-nav .nav-link.active {'
        . 'background: ' . $gradient . ' !important;'
        . 'background-image: ' . $gradient . ' !important;'
        . 'color: #fff !important;'
        . 'box-shadow: 0 8px 18px ' . $shadow . ' !important;'
        . '}'
        . '#sidenav-main .legalpro-sidebar-nav__icon .lp-icon {'
        . 'display: inline-flex;'
        . 'width: 1.125rem;'
        . 'height: 1.125rem;'
        . 'min-width: 1.125rem;'
        . 'min-height: 1.125rem;'
        . '}';
}

function renderPortalSidebarPaintBlock(): string
{
    return '<style id="legalpro-sidebar-paint">' . renderPortalSidebarPaintCss() . '</style>';
}

function renderPortalThemeHeadEarly(): void
{
    static $done = false;
    if ($done || !isEffectivePortalThemeDark()) {
        return;
    }
    $done = true;

    echo '<style>html.legalpro-theme-dark{background:#2a3040;}</style>';
    echo '<script>(function(){var d=document;d.documentElement.classList.add("legalpro-theme-dark");var apply=function(){if(d.body&&!d.body.classList.contains("legalpro-dark-mode")){d.body.classList.add("legalpro-dark-mode");}};if(d.body){apply();}else{d.addEventListener("DOMContentLoaded",apply);}})();</script>';
}

function renderPortalThemeHead(): void
{
    static $rendered = false;
    if ($rendered) {
        return;
    }
    $rendered = true;

    $css = renderPortalThemeCss();
    echo '<style id="legalpro-portal-theme">' . $css . '</style>';
}

function renderPortalThemeSettingsHtml(): string
{
    $theme = getPortalTheme();
    $presets = getPortalThemeColorPresets();
    $currentMode = $theme['mode'];
    $currentColor = $theme['color'];
    $customPrimary = $theme['custom_primary'];

    $lightChecked = $currentMode === 'light' ? ' checked' : '';
    $darkChecked = $currentMode === 'dark' ? ' checked' : '';

    $swatches = '';
    foreach ($presets as $key => $preset) {
        $active = $currentColor === $key ? ' active' : '';
        $swatchGradient = 'linear-gradient(135deg, ' . $preset['primary'] . ' 0%, ' . $preset['primary_dark'] . ' 100%)';
        $swatches .= '<label class="settings-theme-swatch' . $active . '" title="' . htmlspecialchars($preset['label']) . '">'
            . '<input type="radio" name="theme_color" value="' . htmlspecialchars($key) . '"' . ($currentColor === $key ? ' checked' : '') . '>'
            . '<span class="settings-theme-swatch__dot" style="background: ' . htmlspecialchars($swatchGradient) . ';"></span>'
            . '<span class="settings-theme-swatch__label">' . htmlspecialchars($preset['label']) . '</span>'
            . '</label>';
    }

    $customPreset = portalThemeBuildCustomPreset($customPrimary);
    $customGradient = 'linear-gradient(135deg, ' . $customPreset['primary'] . ' 0%, ' . $customPreset['primary_dark'] . ' 100%)';
    $customActive = $currentColor === 'custom' ? ' active' : '';
    $customChecked = $currentColor === 'custom' ? ' checked' : '';
    $customPickerStyle = $currentColor === 'custom' ? '' : ' style="display:none;"';

    $swatches .= '<label class="settings-theme-swatch settings-theme-swatch--custom' . $customActive . '" title="Custom">'
        . '<input type="radio" name="theme_color" value="custom"' . $customChecked . '>'
        . '<span class="settings-theme-swatch__dot settings-theme-swatch__dot--custom" style="background: ' . htmlspecialchars($customGradient) . ';"></span>'
        . '<span class="settings-theme-swatch__label">Custom</span>'
        . '</label>';

    return '<div class="card mb-4">'
        . '<div class="card-header pb-0"><h6>Appearance</h6></div>'
        . '<div class="card-body">'
        . '<p class="text-sm text-muted mb-4">Choose the default theme and accent color for the admin, lawyer, and client portals.</p>'
        . '<form method="post" class="settings-theme-form">'
        . '<input type="hidden" name="form_type" value="portal_theme">'
        . '<div class="mb-4">'
        . '<label class="form-control-label d-block mb-2">Theme mode</label>'
        . '<div class="settings-theme-mode">'
        . '<label class="settings-theme-mode__option"><input type="radio" name="theme_mode" value="light"' . $lightChecked . '> Light</label>'
        . '<label class="settings-theme-mode__option"><input type="radio" name="theme_mode" value="dark"' . $darkChecked . '> Dark</label>'
        . '</div>'
        . '</div>'
        . '<div class="mb-4">'
        . '<label class="form-control-label d-block mb-2">Accent color</label>'
        . '<div class="settings-theme-swatches">' . $swatches . '</div>'
        . '</div>'
        . '<div class="settings-theme-custom-picker mb-4"' . $customPickerStyle . '>'
        . '<label class="form-control-label d-block mb-2">Custom color</label>'
        . '<div class="d-flex align-items-center gap-3 flex-wrap">'
        . '<input type="color" class="form-control form-control-color settings-theme-color-input" name="custom_primary" value="' . htmlspecialchars($customPrimary) . '" title="Pick a custom accent color">'
        . '<span class="text-sm text-muted">Pick any color for buttons, links, and sidebar highlights.</span>'
        . '</div>'
        . '</div>'
        . '<button type="submit" class="btn btn-dark">Save Appearance</button>'
        . '</form>'
        . '<script>(function(){var form=document.querySelector(".settings-theme-form");if(!form)return;var customInput=form.querySelector(\'input[name="theme_color"][value="custom"]\');var pickerWrap=form.querySelector(".settings-theme-custom-picker");var picker=form.querySelector(\'input[name="custom_primary"]\');var customDot=form.querySelector(".settings-theme-swatch--custom .settings-theme-swatch__dot");var sync=function(){if(pickerWrap)pickerWrap.style.display=customInput&&customInput.checked?"block":"none";};form.querySelectorAll(\'input[name="theme_color"]\').forEach(function(radio){radio.addEventListener("change",sync);});if(picker){picker.addEventListener("input",function(){if(customDot)customDot.style.background=picker.value;});}sync();})();</script>'
        . '</div>'
        . '</div>';
}
