<?php

function getDefaultCompanyName(): string
{
    return 'LegalPro';
}

function getDefaultCompanyLogoPath(): string
{
    return 'assets/img/logo-ct-dark.png';
}

function getCompanyName(): string
{
    $name = trim((string) getSetting('company_name', ''));
    return $name !== '' ? $name : getDefaultCompanyName();
}

function getCompanyLogoRelativePath(): string
{
    $path = trim((string) getSetting('company_logo', ''));
    return $path !== '' ? $path : getDefaultCompanyLogoPath();
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

function saveCompanyBranding(string $companyName, string $companyDetails, ?array $logoFile = null): array
{
    $companyName = trim($companyName);
    if ($companyName === '') {
        return ['ok' => false, 'message' => 'Company name is required.'];
    }

    setSetting('company_name', $companyName);
    setSetting('company_details', $companyDetails);

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

        $uploadDir = dirname(__DIR__) . '/uploads/branding';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            return ['ok' => false, 'message' => 'Unable to create branding upload folder.'];
        }

        $extension = $allowedTypes[$mimeType];
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

        if (!move_uploaded_file($logoFile['tmp_name'], $targetPath)) {
            return ['ok' => false, 'message' => 'Unable to save uploaded logo.'];
        }

        setSetting('company_logo', $relativePath);
    }

    return ['ok' => true, 'message' => 'Branding updated successfully.'];
}
