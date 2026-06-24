<?php
/**
 * Admin settings UI — bank accounts for invoices.
 */

function renderBankAccountsSettingsHtml(): string
{
    legalpro_bank_ensure_icons();
    $accounts = getBankAccounts();
    $defaultSlot = getDefaultBankAccountSlot();
    $vatNumber = getCompanyVatNumber();
    $paymentTerms = getDefaultPaymentTerms();
    $paymentInstructions = getDefaultPaymentInstructions();
    $fields = legalpro_bank_field_definitions();

    $tabs = '';
    $panes = '';
    foreach ($accounts as $index => $account) {
        $slot = (int) ($account['slot'] ?? ($index + 1));
        $isActive = $slot === $defaultSlot;
        $configured = bank_account_is_configured($account);
        $dot = $configured
            ? '<span class="lp-bank-tab__dot lp-bank-tab__dot--on" aria-hidden="true"></span>'
            : '<span class="lp-bank-tab__dot" aria-hidden="true"></span>';

        $tabs .= '<button type="button" class="lp-bank-tab' . ($isActive ? ' active' : '') . '" data-bank-tab="' . $slot . '" role="tab" aria-selected="' . ($isActive ? 'true' : 'false') . '">'
            . '<span class="lp-bank-tab__num">' . $slot . '</span> Bank account ' . $slot . $dot . '</button>';

        $fieldsHtml = '';
        foreach ($fields as $key => $meta) {
            $name = 'bank_' . $slot . '_' . $key;
            $fieldsHtml .= legalpro_render_bank_icon_field($name, $meta, (string) ($account[$key] ?? ''));
        }

        $panes .= '<div class="lp-bank-pane" data-bank-pane="' . $slot . '" role="tabpanel"' . ($isActive ? '' : ' hidden') . '>'
            . '<div class="lp-bank-pane__head"><span class="lp-bank-pane__badge">' . $slot . '</span>'
            . '<div><p class="lp-bank-pane__title mb-0">Bank account ' . $slot . '</p>'
            . '<p class="lp-bank-pane__subtitle mb-0">Shown on invoices when this account is selected</p></div></div>'
            . '<div class="row">' . $fieldsHtml . '</div></div>';
    }

    $defaultOptions = '';
    for ($i = 1; $i <= 3; $i++) {
        $defaultOptions .= '<option value="' . $i . '"' . ($i === $defaultSlot ? ' selected' : '') . '>Bank account ' . $i . '</option>';
    }

    return '<div class="lp-bank-ui mt-4 pt-2" id="bank-accounts-settings">'
        . '<hr class="horizontal dark my-4">'
        . '<div class="lp-bank-ui__head">'
        . '<span class="lp-bank-ui__head-icon">' . legalpro_icon('landmark') . '</span>'
        . '<div><h6 class="lp-bank-ui__title">Bank accounts for invoices</h6>'
        . '<p class="lp-bank-ui__subtitle">Set up to three accounts. The default is pre-selected on new invoices; you can change it per invoice when generating.</p></div>'
        . '</div>'
        . '<div class="lp-bank-preview"><div class="lp-bank-preview__title">How this appears on invoices</div>'
        . '<p class="lp-bank-preview__text mb-0">Bank name: … · Account name: … · Account number: … · Sort code: … · IBAN: … · BIC / SWIFT: … · Reference: …</p></div>'
        . '<form method="post" id="bankAccountsForm">'
        . '<input type="hidden" name="form_type" value="bank_accounts">'
        . '<div class="lp-bank-tabs" role="tablist" aria-label="Bank accounts">' . $tabs . '</div>'
        . '<div class="lp-bank-panes">' . $panes . '</div>'
        . '<div class="lp-bank-default"><label class="lp-bank-default__label" for="default_bank_account_slot">'
        . legalpro_icon('star') . ' Default account on invoices</label>'
        . '<select class="form-control lp-bank-default__select" name="default_bank_account_slot" id="default_bank_account_slot">'
        . $defaultOptions . '</select></div>'
        . '<hr class="horizontal dark my-4">'
        . '<div class="row">'
        . '<div class="col-md-4 mb-3"><label class="lp-bank-field__label">VAT Number</label>'
        . '<input type="text" class="form-control" name="company_vat_number" value="' . htmlspecialchars($vatNumber, ENT_QUOTES, 'UTF-8') . '" placeholder="961 8518 89"></div>'
        . '<div class="col-md-4 mb-3"><label class="lp-bank-field__label">Default payment terms</label>'
        . '<input type="text" class="form-control" name="default_payment_terms" value="' . htmlspecialchars($paymentTerms, ENT_QUOTES, 'UTF-8') . '"></div>'
        . '<div class="col-md-4 mb-3"><label class="lp-bank-field__label">Default payment instructions</label>'
        . '<input type="text" class="form-control" name="default_payment_instructions" value="' . htmlspecialchars($paymentInstructions, ENT_QUOTES, 'UTF-8') . '" placeholder="Optional"></div>'
        . '</div>'
        . '<button type="submit" class="btn btn-dark">Save bank accounts</button>'
        . '</form></div>'
        . '<script>document.addEventListener("DOMContentLoaded",function(){'
        . 'var root=document.getElementById("bank-accounts-settings");if(!root)return;'
        . 'function showBankPane(slot){root.querySelectorAll(".lp-bank-tab").forEach(function(t){var on=t.getAttribute("data-bank-tab")===String(slot);t.classList.toggle("active",on);t.setAttribute("aria-selected",on?"true":"false");});'
        . 'root.querySelectorAll(".lp-bank-pane").forEach(function(p){p.hidden=p.getAttribute("data-bank-pane")!==String(slot);});}'
        . 'root.querySelectorAll(".lp-bank-tab").forEach(function(tab){tab.addEventListener("click",function(){showBankPane(tab.getAttribute("data-bank-tab"));});});'
        . 'var defaultSelect=root.querySelector("#default_bank_account_slot");'
        . 'if(defaultSelect){defaultSelect.addEventListener("change",function(){showBankPane(defaultSelect.value);});}'
        . 'var active=root.querySelector(".lp-bank-tab.active");if(active){showBankPane(active.getAttribute("data-bank-tab"));}'
        . 'if(typeof legalproInitIcons==="function"){legalproInitIcons(root);}'
        . '});</script>';
}
