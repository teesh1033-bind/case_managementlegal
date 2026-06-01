<?php

function legalpro_smtp_read($socket): string
{
    $data = '';
    while ($line = @fgets($socket, 515)) {
        $data .= $line;
        if (strlen($line) >= 4 && $line[3] === ' ') {
            break;
        }
    }
    return $data;
}

function legalpro_smtp_expect($socket, array $codes, string $step): void
{
    $response = legalpro_smtp_read($socket);
    $code = (int) substr($response, 0, 3);
    if (!in_array($code, $codes, true)) {
        throw new RuntimeException($step . ': ' . trim(preg_replace('/\s+/', ' ', $response)));
    }
}

function legalpro_smtp_enable_tls($socket): void
{
    $methods = STREAM_CRYPTO_METHOD_TLS_CLIENT;
    if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
        $methods |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
    }
    if (!@stream_socket_enable_crypto($socket, true, $methods)) {
        throw new RuntimeException('TLS negotiation failed (vérifiez extension openssl dans PHP/Laragon).');
    }
}

function legalpro_smtp_authenticate($socket, string $username, string $password): void
{
    $username = legalpro_normalize_smtp_username($username);
    $password = legalpro_normalize_smtp_password($password);

    // Gmail : AUTH LOGIN après STARTTLS (plus fiable que PLAIN sur certains hébergeurs)
    fwrite($socket, "AUTH LOGIN\r\n");
    legalpro_smtp_expect($socket, [334], 'AUTH LOGIN');
    fwrite($socket, base64_encode($username) . "\r\n");
    legalpro_smtp_expect($socket, [334], 'AUTH user');
    fwrite($socket, base64_encode($password) . "\r\n");
    legalpro_smtp_expect($socket, [235], 'AUTH password');
}

function legalpro_smtp_gmail_credentials_hint(string $username, string $password): string
{
    if (!function_exists('legalpro_gmail_app_password_length')) {
        require_once __DIR__ . '/mail.php';
    }
    $len = legalpro_gmail_app_password_length($password);
    $email = legalpro_normalize_smtp_username($username);

    $hint = 'Gmail refuse la connexion (mot de passe incorrect). ';
    if ($len !== 16) {
        $hint .= 'Mot de passe enregistré : ' . $len . ' caractères (il en faut 16). ';
    }
    $hint .= 'Compte configuré : ' . $email . '. ';
    $hint .= 'Étapes : (1) https://myaccount.google.com/apppasswords — créez un NOUVEAU mot de passe (supprimez les anciens). ';
    $hint .= '(2) Paramètres → Email — collez les 16 lettres, même adresse Gmail que le compte Google. ';
    $hint .= '(3) Enregistrer puis « Envoyer un email de test » avant d\'ajouter un client. ';
    $hint .= 'N\'utilisez pas votre mot de passe Gmail habituel.';

    return $hint;
}

/**
 * @return resource
 */
function legalpro_smtp_connect(array $config)
{
    $host = $config['host'];
    $port = (int) $config['port'];
    $encryption = strtolower($config['encryption'] ?? 'tls');

    $remote = $encryption === 'ssl' ? 'ssl://' . $host . ':' . $port : 'tcp://' . $host . ':' . $port;
    $errno = 0;
    $errstr = '';
    $socket = @stream_socket_client($remote, $errno, $errstr, 30, STREAM_CLIENT_CONNECT);
    if (!$socket) {
        throw new RuntimeException('Connexion SMTP impossible (' . $host . ':' . $port . ') : ' . $errstr);
    }

    stream_set_timeout($socket, 30);
    legalpro_smtp_expect($socket, [220], 'Connection');

    fwrite($socket, "EHLO legalpro.local\r\n");
    legalpro_smtp_expect($socket, [250], 'EHLO');

    if ($encryption === 'tls') {
        fwrite($socket, "STARTTLS\r\n");
        legalpro_smtp_expect($socket, [220], 'STARTTLS');
        legalpro_smtp_enable_tls($socket);
        fwrite($socket, "EHLO legalpro.local\r\n");
        legalpro_smtp_expect($socket, [250], 'EHLO after TLS');
    }

    return $socket;
}

/**
 * @param array{host: string, port: int, encryption: string, username: string, password: string} $config
 * @return array{ok: bool, message: string}
 */
function legalpro_smtp_send(
    array $config,
    string $to,
    string $subject,
    string $htmlBody,
    string $fromEmail,
    string $fromName
): array {
    $username = legalpro_normalize_smtp_username($config['username']);
    $password = legalpro_normalize_smtp_password($config['password']);

    if ($config['host'] === '' || $username === '' || $password === '') {
        return ['ok' => false, 'message' => 'Configuration SMTP incomplète.'];
    }

    // Gmail exige que l'expéditeur soit le même compte que l'authentification
    if (function_exists('legalpro_is_gmail_smtp') && legalpro_is_gmail_smtp($config)) {
        $fromEmail = $username;
    }

    $socket = null;
    try {
        $socket = legalpro_smtp_connect($config);
        legalpro_smtp_authenticate($socket, $username, $password);

        fwrite($socket, 'MAIL FROM:<' . $fromEmail . ">\r\n");
        legalpro_smtp_expect($socket, [250], 'MAIL FROM');
        fwrite($socket, 'RCPT TO:<' . $to . ">\r\n");
        legalpro_smtp_expect($socket, [250, 251], 'RCPT TO');
        fwrite($socket, "DATA\r\n");
        legalpro_smtp_expect($socket, [354], 'DATA');

        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $encodedFromName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
        $headers = [
            'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
            'From: ' . $encodedFromName . ' <' . $fromEmail . '>',
            'To: <' . $to . '>',
            'Subject: ' . $encodedSubject,
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
        ];
        $body = str_replace(["\r\n", "\r"], "\n", $htmlBody);
        $body = str_replace("\n.", "\n..", $body);
        $body = str_replace("\n", "\r\n", $body);
        fwrite($socket, implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.\r\n");
        legalpro_smtp_expect($socket, [250], 'Message body');
        fwrite($socket, "QUIT\r\n");
        @fclose($socket);

        return ['ok' => true, 'message' => 'Email envoyé avec succès.'];
    } catch (Throwable $e) {
        if (is_resource($socket)) {
            @fclose($socket);
        }
        $msg = $e->getMessage();
        if (stripos($msg, '535') !== false || stripos($msg, 'BadCredentials') !== false || stripos($msg, 'AUTH password') !== false) {
            return ['ok' => false, 'message' => legalpro_smtp_gmail_credentials_hint($username, $password)];
        }
        return ['ok' => false, 'message' => $msg];
    }
}

/**
 * Test SMTP login only (for Settings diagnostic).
 *
 * @return array{ok: bool, message: string}
 */
function legalpro_smtp_test_connection(array $config): array
{
    $username = legalpro_normalize_smtp_username($config['username']);
    $password = legalpro_normalize_smtp_password($config['password']);
    $socket = null;

    try {
        $socket = legalpro_smtp_connect($config);
        legalpro_smtp_authenticate($socket, $username, $password);
        fwrite($socket, "QUIT\r\n");
        @fclose($socket);
        $len = strlen($password);
        return [
            'ok' => true,
            'message' => 'Connexion Gmail OK pour ' . $username . ' (mot de passe : ' . $len . ' caractères).',
        ];
    } catch (Throwable $e) {
        if (is_resource($socket)) {
            @fclose($socket);
        }
        $msg = $e->getMessage();
        if (stripos($msg, '535') !== false || stripos($msg, 'BadCredentials') !== false || stripos($msg, 'AUTH') !== false) {
            return ['ok' => false, 'message' => legalpro_smtp_gmail_credentials_hint($username, $password)];
        }
        return ['ok' => false, 'message' => $msg];
    }
}
