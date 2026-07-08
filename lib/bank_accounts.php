<?php
/**
 * Bank accounts for invoices / quotations.
 */

if (!defined('LEGALPRO_BANK_ACCOUNT_MAX_SLOTS')) {
    define('LEGALPRO_BANK_ACCOUNT_MAX_SLOTS', 5);
}

function bank_account_empty_slot(int $slot): array
{
    return [
        'slot' => $slot,
        'bank_name' => '',
        'account_name' => '',
        'account_number' => '',
        'sort_code' => '',
        'iban' => '',
        'bic_swift' => '',
        'reference' => '',
    ];
}

function getBankAccounts(): array
{
    $raw = getSetting('bank_accounts', '');
    if ($raw === '' || $raw === null) {
        $defaults = [];
        for ($i = 1; $i <= LEGALPRO_BANK_ACCOUNT_MAX_SLOTS; $i++) {
            $defaults[] = bank_account_empty_slot($i);
        }
        return $defaults;
    }

    $decoded = json_decode((string) $raw, true);
    if (!is_array($decoded)) {
        $defaults = [];
        for ($i = 1; $i <= LEGALPRO_BANK_ACCOUNT_MAX_SLOTS; $i++) {
            $defaults[] = bank_account_empty_slot($i);
        }
        return $defaults;
    }

    $bySlot = [];
    foreach ($decoded as $row) {
        if (!is_array($row)) {
            continue;
        }
        $slot = (int) ($row['slot'] ?? 0);
        if ($slot >= 1 && $slot <= LEGALPRO_BANK_ACCOUNT_MAX_SLOTS) {
            $bySlot[$slot] = array_merge(bank_account_empty_slot($slot), $row);
        }
    }

    $accounts = [];
    for ($i = 1; $i <= LEGALPRO_BANK_ACCOUNT_MAX_SLOTS; $i++) {
        $accounts[] = $bySlot[$i] ?? bank_account_empty_slot($i);
    }

    return $accounts;
}

function getDefaultBankAccountSlot(): int
{
    $slot = (int) getSetting('default_bank_account_slot', 1);

    return ($slot >= 1 && $slot <= LEGALPRO_BANK_ACCOUNT_MAX_SLOTS) ? $slot : 1;
}

function getBankAccountBySlot(int $slot): ?array
{
    if ($slot < 1 || $slot > LEGALPRO_BANK_ACCOUNT_MAX_SLOTS) {
        return null;
    }

    foreach (getBankAccounts() as $account) {
        if ((int) ($account['slot'] ?? 0) === $slot) {
            return $account;
        }
    }

    return bank_account_empty_slot($slot);
}

function bank_account_is_configured(array $account): bool
{
    foreach (['bank_name', 'account_name', 'account_number', 'sort_code', 'iban', 'bic_swift'] as $field) {
        if (trim((string) ($account[$field] ?? '')) !== '') {
            return true;
        }
    }

    return false;
}

function bank_account_display_label(array $account, int $slot): string
{
    $name = trim((string) ($account['account_name'] ?? ''));
    if ($name !== '') {
        return $name;
    }

    $bank = trim((string) ($account['bank_name'] ?? ''));
    if ($bank !== '') {
        return $bank;
    }

    return 'Bank account ' . $slot;
}

function bank_account_option_label(array $account, int $slot): string
{
    $label = bank_account_display_label($account, $slot);
    if (!bank_account_is_configured($account)) {
        return 'Bank account ' . $slot . ' (not configured)';
    }

    return $label;
}

function getCompanyVatNumber(): string
{
    return trim((string) getSetting('company_vat_number', ''));
}

function getDefaultPaymentTerms(): string
{
    $value = trim((string) getSetting('default_payment_terms', ''));

    return $value !== '' ? $value : 'Payment due within 14 days';
}

function getDefaultPaymentInstructions(): string
{
    return trim((string) getSetting('default_payment_instructions', ''));
}

function saveBankAccountsSettings(array $post): array
{
    $accounts = [];
    for ($slot = 1; $slot <= LEGALPRO_BANK_ACCOUNT_MAX_SLOTS; $slot++) {
        $prefix = 'bank_' . $slot . '_';
        $accounts[] = [
            'slot' => $slot,
            'bank_name' => trim((string) ($post[$prefix . 'bank_name'] ?? '')),
            'account_name' => trim((string) ($post[$prefix . 'account_name'] ?? '')),
            'account_number' => trim((string) ($post[$prefix . 'account_number'] ?? '')),
            'sort_code' => trim((string) ($post[$prefix . 'sort_code'] ?? '')),
            'iban' => trim((string) ($post[$prefix . 'iban'] ?? '')),
            'bic_swift' => trim((string) ($post[$prefix . 'bic_swift'] ?? '')),
            'reference' => trim((string) ($post[$prefix . 'reference'] ?? '')),
        ];
    }

    $defaultSlot = (int) ($post['default_bank_account_slot'] ?? 1);
    if ($defaultSlot < 1 || $defaultSlot > LEGALPRO_BANK_ACCOUNT_MAX_SLOTS) {
        $defaultSlot = 1;
    }

    setSetting('bank_accounts', json_encode($accounts, JSON_UNESCAPED_UNICODE));
    setSetting('default_bank_account_slot', (string) $defaultSlot);
    setSetting('company_vat_number', trim((string) ($post['company_vat_number'] ?? '')));
    setSetting('default_payment_terms', trim((string) ($post['default_payment_terms'] ?? '')));
    setSetting('default_payment_instructions', trim((string) ($post['default_payment_instructions'] ?? '')));

    return ['ok' => true, 'message' => 'Bank accounts saved successfully.'];
}

function bank_account_slot_from_value($value): int
{
    $slot = (int) $value;
    if ($slot >= 1 && $slot <= LEGALPRO_BANK_ACCOUNT_MAX_SLOTS) {
        return $slot;
    }

    return getDefaultBankAccountSlot();
}

function bank_account_fields_from_post(array $post): array
{
    return [
        'bank_account_slot' => bank_account_slot_from_value($post['bank_account_slot'] ?? getDefaultBankAccountSlot()),
        'payment_terms' => trim((string) ($post['payment_terms'] ?? getDefaultPaymentTerms())),
        'payment_instructions' => trim((string) ($post['payment_instructions'] ?? getDefaultPaymentInstructions())),
    ];
}

function ensure_invoice_bank_columns(PDO $pdo): void
{
    foreach ([
        'ADD COLUMN bank_account_slot TINYINT UNSIGNED NULL AFTER notes',
        'ADD COLUMN payment_terms TEXT NULL AFTER bank_account_slot',
        'ADD COLUMN payment_instructions TEXT NULL AFTER payment_terms',
        'ADD COLUMN tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER amount',
    ] as $alter) {
        try {
            $pdo->exec('ALTER TABLE invoices ' . $alter);
        } catch (PDOException $e) {
            if (stripos($e->getMessage(), 'duplicate column') === false) {
                throw $e;
            }
        }
    }
}

function ensure_quotation_bank_columns(PDO $pdo): void
{
    foreach ([
        'ADD COLUMN bank_account_slot TINYINT UNSIGNED NULL AFTER notes',
        'ADD COLUMN payment_terms TEXT NULL AFTER bank_account_slot',
        'ADD COLUMN payment_instructions TEXT NULL AFTER payment_terms',
    ] as $alter) {
        try {
            $pdo->exec('ALTER TABLE case_quotations ' . $alter);
        } catch (PDOException $e) {
            if (stripos($e->getMessage(), 'duplicate column') === false) {
                throw $e;
            }
        }
    }
}

function legalpro_render_bank_account_select(string $fieldName, int $selectedSlot, string $id = 'bank_account_slot'): string
{
    $html = '<select class="form-select" name="' . htmlspecialchars($fieldName, ENT_QUOTES, 'UTF-8') . '" id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '">';
    foreach (getBankAccounts() as $account) {
        $slot = (int) ($account['slot'] ?? 0);
        if ($slot < 1) {
            continue;
        }
        $selected = $slot === $selectedSlot ? ' selected' : '';
        $html .= '<option value="' . $slot . '"' . $selected . '>'
            . htmlspecialchars(bank_account_option_label($account, $slot), ENT_QUOTES, 'UTF-8')
            . '</option>';
    }
    $html .= '</select>';

    return $html;
}

/**
 * @return array<int, array<string, string>>
 */
function legalpro_bank_accounts_json_for_js(): array
{
    $out = [];
    foreach (getBankAccounts() as $account) {
        $slot = (int) ($account['slot'] ?? 0);
        if ($slot < 1) {
            continue;
        }
        $out[$slot] = [
            'label' => bank_account_option_label($account, $slot),
            'configured' => bank_account_is_configured($account) ? '1' : '0',
            'bank_name' => (string) ($account['bank_name'] ?? ''),
            'account_name' => (string) ($account['account_name'] ?? ''),
            'account_number' => (string) ($account['account_number'] ?? ''),
            'sort_code' => (string) ($account['sort_code'] ?? ''),
            'iban' => (string) ($account['iban'] ?? ''),
            'bic_swift' => (string) ($account['bic_swift'] ?? ''),
            'reference' => (string) ($account['reference'] ?? ''),
        ];
    }

    return $out;
}

function legalpro_bank_field_definitions(): array
{
    return [
        'bank_name' => ['label' => 'Bank name', 'placeholder' => 'Barclays Bank', 'icon' => 'landmark'],
        'account_name' => ['label' => 'Account name', 'placeholder' => 'YOUR COMPANY LTD', 'icon' => 'building-2'],
        'account_number' => ['label' => 'Account number', 'placeholder' => '52261440', 'icon' => 'credit-card'],
        'sort_code' => ['label' => 'Sort code', 'placeholder' => '50-15-01', 'icon' => 'hash'],
        'iban' => ['label' => 'IBAN', 'placeholder' => 'GB00 XXXX XXXX XXXX XXXX XX', 'icon' => 'globe'],
        'bic_swift' => ['label' => 'BIC / SWIFT', 'placeholder' => 'BARCGB22', 'icon' => 'shield-check'],
        'reference' => ['label' => 'Reference', 'placeholder' => 'Quote invoice number', 'icon' => 'bookmark', 'full' => true],
    ];
}

function legalpro_bank_ensure_icons(): void
{
    if (!function_exists('legalpro_icon')) {
        require_once __DIR__ . '/../inc/legalpro-icons.php';
    }
}

function legalpro_bank_field_icon_html(string $icon): string
{
    legalpro_bank_ensure_icons();

    return '<span class="lp-bank-field__icon-box" aria-hidden="true">' . legalpro_icon($icon) . '</span>';
}

function legalpro_render_bank_icon_field(string $name, array $meta, string $value): string
{
    $label = htmlspecialchars((string) ($meta['label'] ?? ''), ENT_QUOTES, 'UTF-8');
    $placeholder = htmlspecialchars((string) ($meta['placeholder'] ?? ''), ENT_QUOTES, 'UTF-8');
    $icon = (string) ($meta['icon'] ?? 'circle');
    $col = !empty($meta['full']) ? 'col-12' : 'col-md-6';

    return '<div class="' . $col . ' lp-bank-field">'
        . '<label class="lp-bank-field__label">' . $label . '</label>'
        . '<div class="lp-bank-field__wrap">'
        . legalpro_bank_field_icon_html($icon)
        . '<input type="text" class="lp-bank-field__input" name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '"'
        . ' value="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '" placeholder="' . $placeholder . '">'
        . '</div></div>';
}

function legalpro_bank_accounts_stylesheet_tag(): string
{
    return '<link href="../assets/css/bank-accounts-ui.css?v=4" rel="stylesheet" />';
}

function legalpro_render_invoice_bank_section(int $selectedSlot, string $paymentTerms, string $paymentInstructions): string
{
    legalpro_bank_ensure_icons();
    $select = legalpro_render_bank_account_select('bank_account_slot', $selectedSlot, 'invoice_bank_account_slot');
    $select = str_replace('class="form-select"', 'class="form-select lp-invoice-bank__select"', $select);

    return '<div class="lp-invoice-bank lp-bank-ui">'
        . '<div class="lp-bank-ui__head">'
        . '<span class="lp-bank-ui__head-icon">' . legalpro_icon('landmark') . '</span>'
        . '<div><h6 class="lp-bank-ui__title mb-0">Bank account on invoice</h6></div></div>'
        . $select
        . '<p class="lp-invoice-bank__hint">Configure accounts under <a href="settings.php#bank-accounts-settings">Settings → Branding → Bank accounts</a>.</p>'
        . '<div id="invoice-bank-preview" class="lp-invoice-bank__card" hidden>'
        . '<span class="lp-invoice-bank__card-icon">' . legalpro_icon('credit-card') . '</span>'
        . '<div><div class="lp-invoice-bank__card-kicker" id="invoice-bank-preview-kicker">Account number</div>'
        . '<div class="lp-invoice-bank__card-value" id="invoice-bank-preview-value">—</div>'
        . '<div class="lp-invoice-bank__card-meta" id="invoice-bank-preview-meta"></div></div></div>'
        . '<div class="row mt-3"><div class="col-md-6 mb-0">'
        . '<label class="form-label">Payment terms</label>'
        . '<input type="text" class="form-control" name="payment_terms" value="' . htmlspecialchars($paymentTerms, ENT_QUOTES, 'UTF-8') . '">'
        . '</div><div class="col-md-6 mb-0">'
        . '<label class="form-label">Payment instructions</label>'
        . '<input type="text" class="form-control" name="payment_instructions" value="' . htmlspecialchars($paymentInstructions, ENT_QUOTES, 'UTF-8') . '" placeholder="Optional">'
        . '</div></div></div>';
}
