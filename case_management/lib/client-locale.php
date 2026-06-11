<?php

function getClientPortalLocales(): array
{
    return [
        'en' => 'English',
        'fr' => 'Français',
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

    $locales = getClientPortalLocales();

    if (!empty($_SESSION['client_locale'])) {
        $sessionLocale = strtolower(trim((string) $_SESSION['client_locale']));
        if (isset($locales[$sessionLocale])) {
            return $sessionLocale;
        }
    }

    $clientId = $clientId ?? (int) ($_SESSION['client_id'] ?? 0);
    if ($clientId > 0) {
        $stored = strtolower(trim((string) getSetting(clientPortalLocaleSettingKey($clientId), '')));
        if (isset($locales[$stored])) {
            $_SESSION['client_locale'] = $stored;
            return $stored;
        }
    }

    return 'en';
}

function saveClientPortalLocale(int $clientId, string $locale): array
{
    if ($clientId <= 0) {
        return ['ok' => false, 'message' => 'Invalid client account.'];
    }

    $locale = strtolower(trim($locale));
    $locales = getClientPortalLocales();
    if (!isset($locales[$locale])) {
        return ['ok' => false, 'message' => 'Invalid language selected.'];
    }

    setSetting(clientPortalLocaleSettingKey($clientId), $locale);

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['client_locale'] = $locale;

    return ['ok' => true, 'message' => client_t('settings.saved', [], $locale)];
}

function client_t(string $key, array $replace = [], ?string $forceLocale = null): string
{
    static $cache = [];

    $locale = $forceLocale ?? getClientPortalLocale();
    if (!isset($cache[$locale])) {
        $path = __DIR__ . '/../lang/client/' . $locale . '.php';
        $cache[$locale] = is_file($path) ? (require $path) : [];
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

function client_portal_html_lang(): string
{
    $locale = getClientPortalLocale();
    return htmlspecialchars($locale, ENT_QUOTES, 'UTF-8');
}
