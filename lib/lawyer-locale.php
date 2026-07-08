<?php

function getLawyerPortalLocales(): array
{
    return [
        'en' => 'English',
    ];
}

function lawyerPortalLocaleSettingKey(int $lawyerId): string
{
    return 'lawyer_portal_locale_' . max(0, $lawyerId);
}

function getLawyerPortalLocale(?int $lawyerId = null): string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $_SESSION['lawyer_locale'] = 'en';

    return 'en';
}

function saveLawyerPortalLocale(int $lawyerId, string $locale): array
{
    if ($lawyerId <= 0) {
        return ['ok' => false, 'message' => 'Invalid lawyer account.'];
    }

    setSetting(lawyerPortalLocaleSettingKey($lawyerId), 'en');

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['lawyer_locale'] = 'en';

    return ['ok' => true, 'message' => lawyer_t('settings.saved')];
}

function lawyer_t(string $key, array $replace = [], ?string $forceLocale = null): string
{
    static $cache = [];

    $locale = $forceLocale ?? getLawyerPortalLocale();
    if (!isset($cache[$locale])) {
        $path = __DIR__ . '/../lang/lawyer/' . $locale . '.php';
        $cache[$locale] = is_file($path) ? (require $path) : [];
        if ($locale !== 'en' && !isset($cache['en'])) {
            $enPath = __DIR__ . '/../lang/lawyer/en.php';
            $cache['en'] = is_file($enPath) ? (require $enPath) : [];
        }
    }

    $text = $cache[$locale][$key] ?? ($cache['en'][$key] ?? $key);

    foreach ($replace as $search => $value) {
        $text = str_replace(':' . $search, (string) $value, $text);
    }

    return $text;
}

function lawyer_portal_html_lang(): string
{
    $locale = getLawyerPortalLocale();
    return htmlspecialchars($locale, ENT_QUOTES, 'UTF-8');
}

/**
 * Resolve a UI label for the active portal (lawyer or client).
 */
function legalpro_portal_translate(string $key, string $fallback): string
{
    if (!empty($_SESSION['lawyer_id'])) {
        $value = lawyer_t($key);
        if ($value !== $key) {
            return $value;
        }
    }

    if (!empty($_SESSION['client_id']) && function_exists('client_t')) {
        $value = client_t($key);
        if ($value !== $key) {
            return $value;
        }
    }

    return $fallback;
}
