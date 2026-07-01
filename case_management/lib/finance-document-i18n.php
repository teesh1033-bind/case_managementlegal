<?php

require_once __DIR__ . '/client-locale.php';

function legalpro_finance_doc_begin(?int $clientId = null): string
{
    if ($clientId !== null && $clientId > 0) {
        $locale = getClientPortalLocale($clientId);
    } elseif (function_exists('getClientPortalLocale')) {
        $locale = getClientPortalLocale();
    } else {
        $locale = 'en';
    }

    $GLOBALS['legalpro_fin_doc_locale'] = $locale;

    return $locale;
}

function fin_doc_locale(): string
{
    return (string) ($GLOBALS['legalpro_fin_doc_locale'] ?? 'en');
}

function fin_doc_t(string $key, array $replace = []): string
{
    return client_t('pdf.' . $key, $replace, fin_doc_locale());
}

function fin_doc_format_date(?string $value, string $format = 'long'): string
{
    if ($value === null || trim($value) === '') {
        return fin_doc_t('na');
    }

    $ts = strtotime($value);
    if ($ts === false) {
        return fin_doc_t('na');
    }

    if (fin_doc_locale() === 'fr') {
        $months = [
            1 => 'janvier', 2 => 'février', 3 => 'mars', 4 => 'avril',
            5 => 'mai', 6 => 'juin', 7 => 'juillet', 8 => 'août',
            9 => 'septembre', 10 => 'octobre', 11 => 'novembre', 12 => 'décembre',
        ];
        $monthsShort = [
            1 => 'janv.', 2 => 'févr.', 3 => 'mars', 4 => 'avr.',
            5 => 'mai', 6 => 'juin', 7 => 'juil.', 8 => 'août',
            9 => 'sept.', 10 => 'oct.', 11 => 'nov.', 12 => 'déc.',
        ];
        $monthNum = (int) date('n', $ts);
        if ($format === 'short') {
            return date('j', $ts) . ' ' . $monthsShort[$monthNum] . ' ' . date('Y', $ts);
        }

        return (int) date('j', $ts) . ' ' . $months[$monthNum] . ' ' . date('Y', $ts);
    }

    if ($format === 'short') {
        return date('d M Y', $ts);
    }

    return date('F d, Y', $ts);
}

function fin_doc_invoice_status_label(string $status): string
{
    $key = strtolower(trim($status));
    $map = [
        'paid' => 'status_paid',
        'overdue' => 'status_overdue',
        'open' => 'status_open',
        'draft' => 'status_draft',
        'sent' => 'status_sent',
        'cancelled' => 'status_cancelled',
        'late' => 'status_overdue',
    ];

    if (isset($map[$key])) {
        return fin_doc_t($map[$key]);
    }

    return $status !== '' ? ucfirst($status) : fin_doc_t('status_open');
}
