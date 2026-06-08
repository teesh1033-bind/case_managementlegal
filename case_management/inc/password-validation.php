<?php
/**
 * Password validation for LegalPro user accounts.
 */

define('LEGALPRO_PASSWORD_MIN_LENGTH', 8);
define('LEGALPRO_PASSWORD_MAX_LENGTH', 128);

/**
 * @return string[]
 */
function legalpro_password_requirements_list(): array
{
    return [
        'At least ' . LEGALPRO_PASSWORD_MIN_LENGTH . ' characters',
        'At least one uppercase letter (A–Z)',
        'At least one lowercase letter (a–z)',
    ];
}

/**
 * @param string|string[] $error
 */
function legalpro_password_field_error_html($error): string
{
    if (empty($error)) {
        return '';
    }

    if (is_array($error)) {
        $error = array_values(array_filter($error, static function ($item) {
            return $item !== null && $item !== '';
        }));
        if ($error === []) {
            return '';
        }
        if (count($error) === 1) {
            return '<div class="legalpro-field-error text-danger text-xs mt-1 d-block">'
                . htmlspecialchars($error[0], ENT_QUOTES, 'UTF-8')
                . '</div>';
        }

        $items = array_map(
            static function ($item) {
                return '<li>' . htmlspecialchars($item, ENT_QUOTES, 'UTF-8') . '</li>';
            },
            $error
        );

        return '<div class="legalpro-field-error text-danger text-xs mt-1 d-block"><ul class="mb-0 ps-3">'
            . implode('', $items)
            . '</ul></div>';
    }

    return '<div class="legalpro-field-error text-danger text-xs mt-1 d-block">'
        . htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8')
        . '</div>';
}

/**
 * @param string|string[] $error
 */
function legalpro_password_input_invalid_class($error): string
{
    if (empty($error)) {
        return '';
    }

    if (is_array($error)) {
        return $error === [] ? '' : ' is-invalid';
    }

    return ' is-invalid';
}

function legalpro_password_requirements_html(): string
{
    $parts = array_map(
        static function ($rule) {
            return htmlspecialchars($rule, ENT_QUOTES, 'UTF-8');
        },
        legalpro_password_requirements_list()
    );

    return '<div class="legalpro-password-requirements text-xs text-muted mb-3">'
        . '<span class="legalpro-password-requirements-label">Password rules:</span> '
        . implode(' · ', $parts)
        . '</div>';
}

/**
 * @return array{valid: bool, errors: string[]}
 */
function legalpro_validate_password(string $password): array
{
    $errors = [];
    $length = strlen($password);

    if ($length < LEGALPRO_PASSWORD_MIN_LENGTH) {
        $errors[] = 'Password must be at least ' . LEGALPRO_PASSWORD_MIN_LENGTH . ' characters long.';
    }

    if ($length > LEGALPRO_PASSWORD_MAX_LENGTH) {
        $errors[] = 'Password must not exceed ' . LEGALPRO_PASSWORD_MAX_LENGTH . ' characters.';
    }

    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = 'Password must include at least one uppercase letter.';
    }

    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = 'Password must include at least one lowercase letter.';
    }

    return [
        'valid' => empty($errors),
        'errors' => $errors,
    ];
}

/**
 * @return array{valid: bool, message: string, errors: string[], password_errors: string[], confirm_error: string}
 */
function legalpro_validate_password_pair(string $password, string $confirm): array
{
    $passwordResult = legalpro_validate_password($password);
    $confirmError = '';

    if ($password === '') {
        $passwordResult['errors'][] = 'Password is required.';
    }

    if ($confirm === '') {
        $confirmError = 'Please confirm your password.';
    } elseif ($password !== $confirm) {
        $confirmError = 'Passwords do not match.';
    }

    $valid = $passwordResult['valid'] && $password !== '' && $confirmError === '';

    $errors = $passwordResult['errors'];
    if ($confirmError !== '') {
        $errors[] = $confirmError;
    }

    return [
        'valid' => $valid,
        'message' => $valid ? '' : ($errors[0] ?? 'Password does not meet requirements.'),
        'errors' => $errors,
        'password_errors' => $passwordResult['errors'],
        'confirm_error' => $confirmError,
    ];
}

/**
 * Empty password + confirm = keep current password.
 *
 * @return array{valid: bool, message: string, errors: string[], password_errors: string[], confirm_error: string}
 */
function legalpro_validate_optional_password_update(string $password, string $confirm): array
{
    if ($password === '' && $confirm === '') {
        return [
            'valid' => true,
            'message' => '',
            'errors' => [],
            'password_errors' => [],
            'confirm_error' => '',
        ];
    }

    $passwordErrors = [];
    $confirmError = '';

    if ($password === '') {
        $passwordErrors[] = 'Enter a new password.';
    } else {
        $passwordErrors = legalpro_validate_password($password)['errors'];
    }

    if ($confirm === '') {
        $confirmError = 'Confirm your new password.';
    } elseif ($password !== '' && $password !== $confirm) {
        $confirmError = 'Passwords do not match.';
    }

    $errors = $passwordErrors;
    if ($confirmError !== '') {
        $errors[] = $confirmError;
    }

    $valid = $passwordErrors === [] && $confirmError === '';

    return [
        'valid' => $valid,
        'message' => $valid ? '' : ($errors[0] ?? 'Password does not meet requirements.'),
        'errors' => $errors,
        'password_errors' => $passwordErrors,
        'confirm_error' => $confirmError,
    ];
}

function legalpro_password_form_message(array $validation): string
{
    if (!empty($validation['password_errors']) || !empty($validation['confirm_error'])) {
        return 'Please fix the password errors shown below.';
    }

    return $validation['message'] !== ''
        ? $validation['message']
        : 'Password does not meet requirements.';
}
