<?php

require_once __DIR__ . '/smtp.php';

function legalpro_normalize_smtp_username(string $username): string
{
    return strtolower(trim($username));
}

function legalpro_normalize_smtp_password(string $password): string
{
    // BOM / espaces / tirets du collage Google "abcd efgh ijkl mnop"
    $password = trim($password, " \t\n\r\0\x0B\xEF\xBB\xBF");
    return preg_replace('/[^a-zA-Z0-9]/u', '', $password);
}

function legalpro_is_gmail_smtp(array $cfg): bool
{
    return stripos($cfg['host'] ?? '', 'gmail') !== false
        || stripos($cfg['username'] ?? '', '@gmail.') !== false
        || stripos($cfg['username'] ?? '', '@googlemail.') !== false;
}

function legalpro_gmail_app_password_length(string $password): int
{
    return strlen(legalpro_normalize_smtp_password($password));
}

function legalpro_get_app_base_url(): string
{
    $configured = trim((string) getSetting('app_base_url', ''));
    if ($configured !== '') {
        return rtrim($configured, '/');
    }
    if (php_sapi_name() === 'cli' || empty($_SERVER['HTTP_HOST'])) {
        return '';
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $script = $_SERVER['SCRIPT_NAME'] ?? '/pages/client-detail.php';
    $pagesDir = str_replace('\\', '/', dirname($script));
    $appRoot = dirname($pagesDir);
    if ($appRoot === '/' || $appRoot === '.') {
        $appRoot = '';
    }
    return $scheme . '://' . $_SERVER['HTTP_HOST'] . $appRoot;
}

function legalpro_get_client_login_url(): string
{
    $base = legalpro_get_app_base_url();
    return $base === '' ? '/pages/login.php' : $base . '/pages/login.php';
}

function legalpro_mail_from_address(): string
{
    $cfg = legalpro_get_smtp_config();
    if (legalpro_is_gmail_smtp($cfg) && $cfg['username'] !== '') {
        return $cfg['username'];
    }
    $from = trim((string) getSetting('mail_from_address', ''));
    if ($from !== '' && filter_var($from, FILTER_VALIDATE_EMAIL)) {
        return $from;
    }
    if ($cfg['username'] !== '' && filter_var($cfg['username'], FILTER_VALIDATE_EMAIL)) {
        return $cfg['username'];
    }
    $host = preg_replace('/^www\./i', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
    return 'noreply@' . $host;
}

function legalpro_get_smtp_config(): array
{
    return [
        'enabled' => (string) getSetting('smtp_enabled', '0') === '1',
        'host' => trim((string) getSetting('smtp_host', 'smtp.gmail.com')),
        'port' => (int) getSetting('smtp_port', '587') ?: 587,
        'encryption' => trim((string) getSetting('smtp_encryption', 'tls')) ?: 'tls',
        'username' => legalpro_normalize_smtp_username((string) getSetting('smtp_username', '')),
        'password' => legalpro_normalize_smtp_password((string) getSetting('smtp_password', '')),
    ];
}

/**
 * @return array{ready: bool, message: string}
 */
function legalpro_smtp_configuration_status(): array
{
    $cfg = legalpro_get_smtp_config();
    if ($cfg['username'] === '') {
        return ['ready' => false, 'message' => 'Email non envoyé : configurez Gmail dans Paramètres → Email.'];
    }
    if ($cfg['password'] === '') {
        return ['ready' => false, 'message' => 'Email non envoyé : ajoutez un mot de passe d\'application Google dans Paramètres → Email.'];
    }

    if (legalpro_is_gmail_smtp($cfg)) {
        $len = legalpro_gmail_app_password_length($cfg['password']);
        if ($len !== 16) {
            return [
                'ready' => false,
                'message' => 'Email non envoyé : le mot de passe d\'application enregistré a ' . $len . ' caractères (il en faut 16). '
                    . 'Paramètres → Email : créez un nouveau mot de passe sur https://myaccount.google.com/apppasswords, '
                    . 'collez les 4 groupes de 4 lettres (ex. abcd efgh ijkl mnop), puis Enregistrer et testez.',
            ];
        }
    }

    if (!$cfg['enabled']) {
        setSetting('smtp_enabled', '1');
    }
    return ['ready' => true, 'message' => ''];
}

/**
 * @return array{ok: bool, message: string}
 */
function legalpro_send_email(string $to, string $subject, string $htmlBody): array
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'Adresse email invalide.'];
    }
    $status = legalpro_smtp_configuration_status();
    if (!$status['ready']) {
        return ['ok' => false, 'message' => $status['message']];
    }
    return legalpro_smtp_send(
        legalpro_get_smtp_config(),
        $to,
        $subject,
        $htmlBody,
        legalpro_mail_from_address(),
        getCompanyName()
    );
}

/**
 * @return array{ok: bool, message: string}
 */
function legalpro_send_client_credentials_email(
    string $toEmail,
    string $firstName,
    string $lastName,
    string $username,
    string $plainPassword
): array {
    $company = htmlspecialchars(getCompanyName(), ENT_QUOTES, 'UTF-8');
    $fullName = htmlspecialchars(trim($firstName . ' ' . $lastName), ENT_QUOTES, 'UTF-8');
    $subject = getCompanyName() . ' — Vos identifiants portail client';
    $loginUrl = htmlspecialchars(legalpro_get_client_login_url(), ENT_QUOTES, 'UTF-8');

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="font-family:Arial,sans-serif;line-height:1.6;color:#344767;max-width:560px;margin:0 auto;padding:24px;">'
        . '<h2 style="color:#4e62d4;">' . $company . '</h2>'
        . '<p>Bonjour <strong>' . $fullName . '</strong>,</p>'
        . '<p>Votre compte portail client a été créé. Voici vos identifiants de connexion :</p>'
        . '<table style="width:100%;border-collapse:collapse;margin:16px 0;background:#f6f8fb;">'
        . '<tr><td style="padding:12px;border-bottom:1px solid #e9ecef;"><strong>URL</strong></td><td style="padding:12px;border-bottom:1px solid #e9ecef;"><a href="' . $loginUrl . '">' . $loginUrl . '</a></td></tr>'
        . '<tr><td style="padding:12px;border-bottom:1px solid #e9ecef;"><strong>Nom d\'utilisateur</strong></td><td style="padding:12px;border-bottom:1px solid #e9ecef;">' . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . '</td></tr>'
        . '<tr><td style="padding:12px;"><strong>Mot de passe</strong></td><td style="padding:12px;">' . htmlspecialchars($plainPassword, ENT_QUOTES, 'UTF-8') . '</td></tr>'
        . '</table>'
        . '<p style="font-size:14px;color:#67748e;">Conservez ce message en lieu sûr. En cas de question, contactez votre cabinet.</p>'
        . '<p>Cordialement,<br><strong>' . $company . '</strong></p></body></html>';

    return legalpro_send_email($toEmail, $subject, $html);
}
