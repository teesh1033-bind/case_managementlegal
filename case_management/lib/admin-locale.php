<?php

function getAdminPortalLocales(): array
{
    return [
        'en' => 'English',
        'fr' => 'Français',
    ];
}

function adminPortalLocaleSettingKey(int $adminId): string
{
    return 'admin_portal_locale_' . max(0, $adminId);
}

function getAdminPortalLocale(?int $adminId = null): string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $locales = getAdminPortalLocales();

    if (!empty($_SESSION['admin_locale'])) {
        $sessionLocale = strtolower(trim((string) $_SESSION['admin_locale']));
        if (isset($locales[$sessionLocale])) {
            return $sessionLocale;
        }
    }

    $adminId = $adminId ?? (int) ($_SESSION['admin_id'] ?? 0);
    if ($adminId > 0) {
        $stored = strtolower(trim((string) getSetting(adminPortalLocaleSettingKey($adminId), '')));
        if (isset($locales[$stored])) {
            $_SESSION['admin_locale'] = $stored;
            return $stored;
        }
    }

    return 'en';
}

function saveAdminPortalLocale(int $adminId, string $locale): array
{
    if ($adminId <= 0) {
        return ['ok' => false, 'message' => 'Invalid admin account.'];
    }

    $locale = strtolower(trim($locale));
    $locales = getAdminPortalLocales();
    if (!isset($locales[$locale])) {
        return ['ok' => false, 'message' => admin_t('settings.invalid_locale', [], 'en')];
    }

    setSetting(adminPortalLocaleSettingKey($adminId), $locale);

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['admin_locale'] = $locale;

    return ['ok' => true, 'message' => admin_t('settings.saved', [], $locale)];
}

function admin_t(string $key, array $replace = [], ?string $forceLocale = null): string
{
    static $cache = [];

    $locale = $forceLocale ?? getAdminPortalLocale();
    if (!isset($cache[$locale])) {
        $path = __DIR__ . '/../lang/admin/' . $locale . '.php';
        $cache[$locale] = is_file($path) ? (require $path) : [];
        if ($locale !== 'en' && !isset($cache['en'])) {
            $enPath = __DIR__ . '/../lang/admin/en.php';
            $cache['en'] = is_file($enPath) ? (require $enPath) : [];
        }
    }

    $text = $cache[$locale][$key] ?? ($cache['en'][$key] ?? $key);

    foreach ($replace as $search => $value) {
        $text = str_replace(':' . $search, (string) $value, $text);
    }

    return $text;
}

function admin_portal_html_lang(): string
{
    $locale = getAdminPortalLocale();

    return htmlspecialchars($locale, ENT_QUOTES, 'UTF-8');
}

/**
 * Replace remaining hardcoded English phrases in admin page HTML (French locale).
 * Script and style blocks are left untouched so calendar/JS code is not corrupted.
 */
function legalpro_apply_admin_i18n(string $html): string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['admin_id'])) {
        return $html;
    }

    $locale = getAdminPortalLocale();
    if ($locale === 'en') {
        return $html;
    }

    static $phraseMaps = [];
    if (!isset($phraseMaps[$locale])) {
        $path = __DIR__ . '/../lang/admin/phrases-' . $locale . '.php';
        $phraseMaps[$locale] = is_file($path) ? (require $path) : [];
    }

    $phrases = $phraseMaps[$locale];
    if ($phrases === []) {
        return $html;
    }

    // Standalone words that must not be replaced globally (break JS/CSS identifiers).
    foreach (legalpro_admin_i18n_blocked_phrase_keys() as $blockedKey) {
        unset($phrases[$blockedKey]);
    }

    return legalpro_apply_admin_phrase_map($html, $phrases);
}

/**
 * Apply admin i18n to page HTML without touching the scripts tail (calendars, charts).
 */
function legalpro_apply_admin_i18n_for_page(string $html): string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['admin_id'])) {
        return $html;
    }
    if (getAdminPortalLocale() === 'en') {
        return $html;
    }

    $markers = [
        '<!-- LEGALPRO_ADMIN_SCRIPTS -->',
        '<!-- ── SCRIPTS ──',
    ];

    foreach ($markers as $marker) {
        $pos = strpos($html, $marker);
        if ($pos !== false) {
            return legalpro_apply_admin_i18n(substr($html, 0, $pos)) . substr($html, $pos);
        }
    }

    return legalpro_apply_admin_i18n($html);
}

function legalpro_admin_i18n_blocked_phrase_keys(): array
{
    return [
        'Calendar',
        'View',
        'Edit',
        'Delete',
        'Close',
        'Cancel',
        'Light',
        'Dark',
        'Admin',
        'Client',
        'Lawyer',
        'Sent',
        'Draft',
        'Open',
    ];
}

function legalpro_apply_admin_phrase_map(string $html, array $phrases): string
{
    [$html, $skipProtected] = legalpro_i18n_extract_marked_regions(
        $html,
        '<!-- LEGALPRO_I18N_SKIP -->',
        '<!-- LEGALPRO_I18N_SKIP_END -->',
        'LP_I18N_SKIP_'
    );
    [$html, $blockProtected] = legalpro_i18n_extract_html_blocks($html, ['script', 'style']);
    [$html, $attrProtected] = legalpro_i18n_extract_sensitive_attributes($html);

    $html = str_replace(array_keys($phrases), array_values($phrases), $html);

    if ($attrProtected !== []) {
        $html = strtr($html, $attrProtected);
    }
    if ($blockProtected !== []) {
        $html = strtr($html, $blockProtected);
    }
    if ($skipProtected !== []) {
        $html = strtr($html, $skipProtected);
    }

    return $html;
}

/**
 * @return array{0: string, 1: array<string, string>}
 */
function legalpro_i18n_extract_marked_regions(string $html, string $startMarker, string $endMarker, string $tokenPrefix): array
{
    $protected = [];
    $index = 0;
    $startLen = strlen($startMarker);
    $endLen = strlen($endMarker);

    while (($startPos = strpos($html, $startMarker)) !== false) {
        $endPos = strpos($html, $endMarker, $startPos + $startLen);
        if ($endPos === false) {
            break;
        }

        $endPos += $endLen;
        $token = "\x1E" . $tokenPrefix . $index . "\x1E";
        $protected[$token] = substr($html, $startPos, $endPos - $startPos);
        $html = substr($html, 0, $startPos) . $token . substr($html, $endPos);
        $index++;
    }

    return [$html, $protected];
}

/**
 * Keep URLs, notification keys, and control ids out of phrase replacement.
 *
 * @return array{0: string, 1: array<string, string>}
 */
function legalpro_i18n_extract_sensitive_attributes(string $html): array
{
    $protected = [];
    $index = 0;
    $attrs = [
        'href',
        'action',
        'src',
        'data-notif-key',
        'data-admin-notif-api',
        'data-notif-count',
        'data-notif-id',
        'aria-controls',
        'id',
    ];

    foreach ($attrs as $attr) {
        $pattern = '/\s' . preg_quote($attr, '/') . '\s*=\s*("|\')((?:\\\\.|(?!\1).)*)\1/i';
        $html = preg_replace_callback(
            $pattern,
            static function (array $match) use (&$protected, &$index): string {
                $token = "\x1E" . 'LP_I18N_ATTR_' . $index . "\x1E";
                $protected[$token] = $match[0];
                $index++;

                return $token;
            },
            $html
        );
    }

    return [$html, $protected];
}

/**
 * @return array{0: string, 1: array<string, string>}
 */
function legalpro_i18n_extract_html_blocks(string $html, array $tags): array
{
    $protected = [];
    $index = 0;
    $offset = 0;
    $result = '';
    $len = strlen($html);

    while ($offset < $len) {
        $nextTag = null;
        $nextPos = $len;

        foreach ($tags as $tag) {
            if (preg_match('/<' . preg_quote($tag, '/') . '\b/i', $html, $match, PREG_OFFSET_CAPTURE, $offset)) {
                $pos = (int) $match[0][1];
                if ($pos < $nextPos) {
                    $nextPos = $pos;
                    $nextTag = strtolower($tag);
                }
            }
        }

        if ($nextTag === null) {
            $result .= substr($html, $offset);
            break;
        }

        $result .= substr($html, $offset, $nextPos - $offset);

        if (preg_match('/<\/' . preg_quote($nextTag, '/') . '\s*>/i', $html, $endMatch, PREG_OFFSET_CAPTURE, $nextPos)) {
            $endPos = (int) $endMatch[0][1] + strlen($endMatch[0][0]);
            $token = "\x1E" . 'LP_I18N_BLOCK_' . $index . "\x1E";
            $protected[$token] = substr($html, $nextPos, $endPos - $nextPos);
            $result .= $token;
            $offset = $endPos;
            $index++;
            continue;
        }

        $result .= $html[$nextPos] ?? '';
        $offset = $nextPos + 1;
    }

    return [$result, $protected];
}

function admin_badge_t(string $key, string $fallback = ''): string
{
    if (!function_exists('admin_t') || empty($_SESSION['admin_id'])) {
        return $fallback !== '' ? $fallback : $key;
    }

    $translated = admin_t($key);
    if ($translated !== $key) {
        return $translated;
    }

    return $fallback !== '' ? $fallback : $key;
}
