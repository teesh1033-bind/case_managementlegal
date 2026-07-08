<?php

function getClientPortalLocales(): array
{
    // Native language names only — must not call client_t() (client_t loads locale via getClientPortalLocale).
    return [
        'en' => 'English',
    ];
}

function clientPortalLocaleSettingKey(int $clientId): string
{
    return 'client_portal_locale_' . max(0, $clientId);
}

function getClientPortalLocale(?int $clientId = null): string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $_SESSION['client_locale'] = 'en';

    return 'en';
}

function saveClientPortalLocale(int $clientId, string $locale): array
{
    if ($clientId <= 0) {
        return ['ok' => false, 'message' => client_t('locale.invalid_account')];
    }

    setSetting(clientPortalLocaleSettingKey($clientId), 'en');

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['client_locale'] = 'en';

    return ['ok' => true, 'message' => client_t('settings.saved')];
}

function client_t(string $key, array $replace = [], ?string $forceLocale = null): string
{
    static $cache = [];

    $locale = $forceLocale ?? getClientPortalLocale();
    if (!isset($cache[$locale])) {
    $path = __DIR__ . '/../lang/client/' . $locale . '.php';
    $cache[$locale] = is_file($path) ? (require $path) : [];
    $morePath = __DIR__ . '/../lang/client/more-' . $locale . '.php';
    if (is_file($morePath)) {
        $cache[$locale] = array_merge($cache[$locale], require $morePath);
    }
        if ($locale !== 'en' && !isset($cache['en'])) {
            $enPath = __DIR__ . '/../lang/client/en.php';
            $cache['en'] = is_file($enPath) ? (require $enPath) : [];
        }
    }

    $text = $cache[$locale][$key] ?? ($cache['en'][$key] ?? $key);

    foreach ($replace as $search => $value) {
        $text = str_replace(':' . $search, (string) $value, $text);
    }

    return $text;
}

if (is_readable(__DIR__ . '/client-portal-i18n.php')) {
    require_once __DIR__ . '/client-portal-i18n.php';
}

function client_portal_html_lang(): string
{
    $locale = getClientPortalLocale();
    return htmlspecialchars($locale, ENT_QUOTES, 'UTF-8');
}
