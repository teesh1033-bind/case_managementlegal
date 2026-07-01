<?php

require_once __DIR__ . '/php-qrcode/QRCode.php';

use splitbrain\phpQRCode\QRCode;

/**
 * Build QR payload for invoice payment or viewing.
 */
function legalpro_invoice_qr_payload(
    array $invoice,
    int $invoiceId,
    array $bankAccount,
    float $amountDue,
    bool $isPaid
): string {
    $invoiceNumber = $invoice['invoice_number'] ?: ('INV-' . str_pad((string) $invoiceId, 4, '0', STR_PAD_LEFT));
    $baseUrl = function_exists('legalpro_get_app_base_url') ? legalpro_get_app_base_url() : '';

    if ($isPaid && $baseUrl !== '') {
        return $baseUrl . '/pages/invoice-download.php?id=' . $invoiceId . '&view=1';
    }

    $iban = preg_replace('/\s+/', '', (string) ($bankAccount['iban'] ?? ''));
    $beneficiary = trim((string) ($bankAccount['account_name'] ?? ''));
    if ($beneficiary === '' && function_exists('getCompanyName')) {
        $beneficiary = getCompanyName();
    }

    $reference = trim((string) ($bankAccount['reference'] ?? ''));
    if ($reference === '') {
        $reference = $invoiceNumber;
    }

    $currencyCode = 'EUR';
    if (function_exists('getCurrencyConfig')) {
        $currencyCode = strtoupper((string) (getCurrencyConfig()['code'] ?? 'EUR'));
    }

    if ($iban !== '' && $amountDue > 0 && $currencyCode === 'EUR') {
        $lines = [
            'BCD',
            '002',
            '1',
            'SCT',
            mb_substr($beneficiary, 0, 70),
            mb_substr($iban, 0, 34),
            'EUR' . number_format($amountDue, 2, '.', ''),
            '',
            mb_substr($reference, 0, 35),
            'Invoice ' . $invoiceNumber,
        ];

        return implode("\n", $lines);
    }

    if ($baseUrl !== '') {
        return $baseUrl . '/pages/invoice-download.php?id=' . $invoiceId . '&view=1';
    }

    $lines = [
        'INVOICE: ' . $invoiceNumber,
        'AMOUNT DUE: ' . (function_exists('formatCurrency') ? formatCurrency($amountDue) : number_format($amountDue, 2)),
        'REFERENCE: ' . $reference,
    ];
    if ($beneficiary !== '') {
        $lines[] = 'PAY TO: ' . $beneficiary;
    }
    if ($iban !== '') {
        $lines[] = 'IBAN: ' . $iban;
    }

    return implode("\n", $lines);
}

/**
 * PNG data URI for Dompdf (preferred when GD is available).
 */
function legalpro_finance_qr_png_data_uri(string $payload, int $scale = 4): ?string
{
    if (!extension_loaded('gd')) {
        return null;
    }

    $payload = trim($payload);
    if ($payload === '') {
        return null;
    }

    $code = (new QRCode($payload, ['s' => 'qrm']))->getEncodedMatrix();
    $modules = (int) ($code['s'][0] ?? 0);
    if ($modules <= 0) {
        return null;
    }

    $quiet = 2;
    $pixels = ($modules + ($quiet * 2)) * $scale;
    $img = imagecreatetruecolor($pixels, $pixels);
    if ($img === false) {
        return null;
    }

    $white = imagecolorallocate($img, 255, 255, 255);
    $black = imagecolorallocate($img, 0, 0, 0);
    imagefill($img, 0, 0, $white);

    foreach ($code['b'] as $y => $row) {
        foreach ($row as $x => $val) {
            if (!$val) {
                continue;
            }
            $px = ($x + $quiet) * $scale;
            $py = ($y + $quiet) * $scale;
            imagefilledrectangle($img, $px, $py, $px + $scale - 1, $py + $scale - 1, $black);
        }
    }

    ob_start();
    imagepng($img);
    $png = ob_get_clean();
    imagedestroy($img);

    if ($png === false || $png === '') {
        return null;
    }

    return 'data:image/png;base64,' . base64_encode($png);
}

/**
 * HTML table QR fallback — reliable in Dompdf when GD is unavailable.
 */
function legalpro_finance_qr_table_html(string $payload, int $cellPx = 3): string
{
    $payload = trim($payload);
    if ($payload === '') {
        return '';
    }

    $code = (new QRCode($payload, ['s' => 'qrm']))->getEncodedMatrix();
    $rows = '';
    foreach ($code['b'] as $row) {
        $cells = '';
        foreach ($row as $val) {
            $bg = $val ? '#000000' : '#ffffff';
            $cells .= '<td style="width:' . $cellPx . 'px;height:' . $cellPx . 'px;background:'
                . $bg . ';padding:0;margin:0;line-height:0;font-size:0;border:none;"></td>';
        }
        $rows .= '<tr>' . $cells . '</tr>';
    }

    return '<table class="fin-doc-qr-table" cellpadding="0" cellspacing="0" border="0"><tbody>'
        . $rows . '</tbody></table>';
}

/**
 * QR block for finance documents — PNG image for PDF, table/SVG for browser fallback.
 */
function legalpro_finance_qr_html(string $payload, string $caption = 'Scan to pay'): string
{
    $payload = trim($payload);
    if ($payload === '') {
        return '';
    }

    $png = legalpro_finance_qr_png_data_uri($payload);
    if ($png !== null) {
        $imageHtml = '<img class="fin-doc-qr-img" src="' . $png . '" alt="QR code" width="80" height="80">';
    } else {
        $imageHtml = legalpro_finance_qr_table_html($payload);
    }

    $captionHtml = $caption !== ''
        ? '<div class="fin-doc-qr-caption">' . htmlspecialchars($caption, ENT_QUOTES, 'UTF-8') . '</div>'
        : '';

    return '<div class="fin-doc-qr">'
        . '<div class="fin-doc-qr-frame">' . $imageHtml . '</div>'
        . $captionHtml
        . '</div>';
}
