<?php

if (!function_exists('client_t')) {
    require_once __DIR__ . '/../lib/client-locale.php';
}

function client_modal_t(string $key, string $fallback = ''): string
{
    if (isset($_SESSION['client_id']) && function_exists('client_t')) {
        return client_t($key);
    }

    return $fallback !== '' ? $fallback : $key;
}
