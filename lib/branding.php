<?php

function getDefaultCompanyName(): string
{
    return 'LegalPro';
}

function getDefaultCompanyLogoPath(): string
{
    return 'assets/img/logo-ct-dark.png';
}

/**
 * Resolve configured logo path to a file that exists on disk.
 */
function legalpro_resolve_company_logo_relative_path(): string
{
    $root = dirname(__DIR__);
    $candidates = [];

    $configured = trim((string) getSetting('company_logo', ''));
    if ($configured !== '') {
        $candidates[] = ltrim($configured, '/\\');
    }

    $candidates[] = getDefaultCompanyLogoPath();

    foreach ($candidates as $relative) {
        $absolute = $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
        if (is_file($absolute)) {
            return str_replace('\\', '/', $relative);
        }
    }

    $brandingDir = $root . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'branding';
    $brandingMatches = is_dir($brandingDir)
        ? (glob($brandingDir . DIRECTORY_SEPARATOR . 'company-logo.*') ?: [])
        : [];
    foreach ($brandingMatches as $absolute) {
        if (is_file($absolute)) {
            return 'uploads/branding/' . basename($absolute);
        }
    }

    return getDefaultCompanyLogoPath();
}

function getCompanyLogoRelativePath(): string
{
    return legalpro_resolve_company_logo_relative_path();
}

function getCompanyName(): string
{
    $name = trim((string) getSetting('company_name', ''));
    return $name !== '' ? $name : getDefaultCompanyName();
}

function getCompanyLogoUrl(): string
{
    return '../' . ltrim(getCompanyLogoRelativePath(), '/');
}

function getCompanyDetails(): string
{
    return trim((string) getSetting('company_details', ''));
}

function getCompanyBranding(): array
{
    return [
        'name' => getCompanyName(),
        'logo_url' => getCompanyLogoUrl(),
        'logo_path' => getCompanyLogoRelativePath(),
        'details' => getCompanyDetails(),
    ];
}

/**
 * Absolute filesystem path to the configured company logo (for PDF generation).
 */
function getCompanyLogoAbsolutePath(): string
{
    $relative = ltrim(getCompanyLogoRelativePath(), '/\\');

    return dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
}

/**
 * Base64 data URI for embedding the company logo in PDF/HTML documents.
 */
function legalpro_company_logo_data_uri(): ?string
{
    static $cached = false;
    static $value = null;

    if ($cached) {
        return $value;
    }

    $cached = true;
    $path = getCompanyLogoAbsolutePath();
    if (!is_readable($path)) {
        return null;
    }

    $mimeMap = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
    ];

    $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
    if (!isset($mimeMap[$ext])) {
        return null;
    }

    $data = file_get_contents($path);
    if ($data === false || $data === '') {
        return null;
    }

    $value = 'data:' . $mimeMap[$ext] . ';base64,' . base64_encode($data);

    return $value;
}

/**
 * Footer copyright line (year via JS). Uses company name from Settings, default LegalPro.
 */
function legalpro_copyright_line(): string
{
    return '© <script>document.write(new Date().getFullYear())</script>, '
        . htmlspecialchars(getCompanyName(), ENT_QUOTES, 'UTF-8') . '.';
}

/**
 * Full copyright block for portal footers.
 */
function legalpro_copyright_html(string $classes = 'text-center text-sm text-muted text-lg-start'): string
{
    return '<div class="copyright ' . htmlspecialchars($classes, ENT_QUOTES, 'UTF-8') . '">'
        . legalpro_copyright_line()
        . '</div>';
}

/**
 * Replace {COPYRIGHT_LINE} placeholders in rendered page HTML.
 */
function legalpro_apply_copyright_line(string $html): string
{
    if (strpos($html, '{COPYRIGHT_LINE}') !== false) {
        $html = str_replace('{COPYRIGHT_LINE}', legalpro_copyright_line(), $html);
    }

    if (function_exists('legalpro_apply_admin_i18n_for_page')) {
        $html = legalpro_apply_admin_i18n_for_page($html);
    } elseif (function_exists('legalpro_apply_admin_i18n')) {
        $html = legalpro_apply_admin_i18n($html);
    }

    return $html;
}

function legalpro_save_branding_logo_file(string $tmpPath, string $extension): array
{
    $uploadDir = dirname(__DIR__) . '/uploads/branding';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        return ['ok' => false, 'message' => 'Unable to create branding upload folder.'];
    }

    $targetPath = $uploadDir . '/company-logo.' . $extension;
    $relativePath = 'uploads/branding/company-logo.' . $extension;

    $oldLogo = getCompanyLogoRelativePath();
    if ($oldLogo !== getDefaultCompanyLogoPath() && strpos($oldLogo, 'uploads/branding/') === 0) {
        $oldAbsolute = dirname(__DIR__) . '/' . $oldLogo;
        if (is_file($oldAbsolute)) {
            @unlink($oldAbsolute);
        }
    }

    foreach (glob($uploadDir . '/company-logo.*') ?: [] as $existingLogo) {
        if (is_file($existingLogo)) {
            @unlink($existingLogo);
        }
    }

    if (!@rename($tmpPath, $targetPath)) {
        if (!@copy($tmpPath, $targetPath)) {
            return ['ok' => false, 'message' => 'Unable to save logo file.'];
        }
    }

    setSetting('company_logo', $relativePath);

    return ['ok' => true, 'message' => 'Branding updated successfully.'];
}

function legalpro_save_branding_logo_data_uri(string $dataUri): array
{
    $dataUri = trim($dataUri);
    if ($dataUri === '') {
        return ['ok' => false, 'message' => 'Logo image data is empty.'];
    }

    if (!preg_match('/^data:image\/(png|jpeg|jpg|webp);base64,([A-Za-z0-9+\/=]+)$/i', $dataUri, $m)) {
        return ['ok' => false, 'message' => 'Invalid logo image format. Please use PNG, JPG, or WEBP.'];
    }

    $type = strtolower($m[1]);
    $extension = $type === 'jpg' ? 'jpg' : ($type === 'jpeg' ? 'jpg' : $type);
    $decoded = base64_decode($m[2], true);
    if ($decoded === false || $decoded === '') {
        return ['ok' => false, 'message' => 'Unable to decode cropped logo image.'];
    }

    if (strlen($decoded) > 4 * 1024 * 1024) {
        return ['ok' => false, 'message' => 'Cropped logo is too large. Maximum size is 4 MB.'];
    }

    $tmpPath = tempnam(sys_get_temp_dir(), 'logo_');
    if ($tmpPath === false) {
        return ['ok' => false, 'message' => 'Unable to prepare logo file for saving.'];
    }
    file_put_contents($tmpPath, $decoded);

    $saved = legalpro_save_branding_logo_file($tmpPath, $extension);
    @unlink($tmpPath);

    return $saved;
}

function saveCompanyBranding(string $companyName, string $companyDetails, ?array $logoFile = null, string $croppedLogoData = ''): array
{
    $companyName = trim($companyName);
    if ($companyName === '') {
        return ['ok' => false, 'message' => 'Company name is required.'];
    }

    setSetting('company_name', $companyName);
    setSetting('company_details', $companyDetails);

    $croppedLogoData = trim($croppedLogoData);
    if ($croppedLogoData !== '') {
        $saved = legalpro_save_branding_logo_data_uri($croppedLogoData);
        if (!$saved['ok']) {
            return $saved;
        }

        return ['ok' => true, 'message' => 'Branding updated successfully.'];
    }

    if ($logoFile !== null && isset($logoFile['error']) && $logoFile['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($logoFile['error'] !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'message' => 'Logo upload failed. Please try again.'];
        }

        $allowedTypes = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
        ];

        $mimeType = '';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mimeType = (string) finfo_file($finfo, $logoFile['tmp_name']);
                finfo_close($finfo);
            }
        }
        if ($mimeType === '' && !empty($logoFile['type'])) {
            $mimeType = (string) $logoFile['type'];
        }

        if (!isset($allowedTypes[$mimeType])) {
            return ['ok' => false, 'message' => 'Logo must be a PNG, JPG, GIF, WEBP, or SVG image.'];
        }

        if (!empty($logoFile['size']) && (int) $logoFile['size'] > 2 * 1024 * 1024) {
            return ['ok' => false, 'message' => 'Logo file is too large. Maximum size is 2 MB.'];
        }

        $extension = $allowedTypes[$mimeType];
        $tmpPath = $logoFile['tmp_name'];
        if (!is_uploaded_file($tmpPath)) {
            return ['ok' => false, 'message' => 'Invalid uploaded logo file.'];
        }

        $tempCopy = tempnam(sys_get_temp_dir(), 'logo_up_');
        if ($tempCopy === false || !move_uploaded_file($tmpPath, $tempCopy)) {
            return ['ok' => false, 'message' => 'Unable to save uploaded logo.'];
        }

        $saved = legalpro_save_branding_logo_file($tempCopy, $extension);
        @unlink($tempCopy);
        if (!$saved['ok']) {
            return $saved;
        }
    }

    return ['ok' => true, 'message' => 'Branding updated successfully.'];
}
