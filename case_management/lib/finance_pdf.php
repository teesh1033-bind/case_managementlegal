<?php

use Dompdf\Dompdf;
use Dompdf\Options;

function legalpro_finance_pdf_autoload(): ?string
{
    $candidates = [
        dirname(__DIR__) . '/vendor/autoload.php',
        dirname(__DIR__, 2) . '/vendor/autoload.php',
    ];
    foreach ($candidates as $path) {
        if (is_readable($path)) {
            return $path;
        }
    }

    return null;
}

function legalpro_finance_pdf_available(): bool
{
    $autoload = legalpro_finance_pdf_autoload();
    if ($autoload === null) {
        return false;
    }

    require_once $autoload;

    return class_exists(Options::class) && class_exists(Dompdf::class);
}

function legalpro_output_finance_pdf(string $html, string $fileName): void
{
    $autoload = legalpro_finance_pdf_autoload();
    if ($autoload === null) {
        http_response_code(500);
        echo 'PDF library is not installed. Run: composer install';
        exit;
    }

    require_once $autoload;

    if (!class_exists(Options::class) || !class_exists(Dompdf::class)) {
        http_response_code(500);
        echo 'PDF library is incomplete. Run: composer install';
        exit;
    }

    $options = new Options();
    $options->set('isRemoteEnabled', false);
    $options->set('isHtml5ParserEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');
    $projectRoot = dirname(__DIR__);
    if (is_dir($projectRoot)) {
        $options->set('chroot', $projectRoot);
    }

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $safeName = preg_replace('/[^A-Za-z0-9_\-\.]/', '', $fileName) ?: 'document.pdf';
    if (!preg_match('/\.pdf$/i', $safeName)) {
        $safeName .= '.pdf';
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $safeName . '"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');

    echo $dompdf->output();
    exit;
}

function legalpro_finance_document_request_mode(): string
{
    if (isset($_GET['view']) || isset($_GET['preview'])) {
        return 'view';
    }
    if (isset($_GET['print'])) {
        return 'print';
    }

    return 'pdf';
}
