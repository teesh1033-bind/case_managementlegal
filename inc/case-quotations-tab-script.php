<?php

function case_quotations_render_tab_script(string $editJson, string $nextQuotationNumber = ''): string
{
    $script = <<<'JS'
<script>
document.addEventListener('DOMContentLoaded', function () {
    var quotationsData = {QUOTATIONS_EDIT_JSON};
    var nextQuotationNumber = {NEXT_QUOTATION_NUMBER_JSON};
    var formCard = document.getElementById('case-quotation-form-card');
    var form = document.getElementById('case-quotation-form');
    var addBtn = document.getElementById('case-quotation-add-btn');
    var cancelBtn = document.getElementById('case-quotation-cancel-btn');

    function setQuotationNumberDisplay(value, isNew) {
        var numberEl = document.getElementById('quotation_number_display');
        var hintEl = document.getElementById('quotation_number_hint');
        if (numberEl) numberEl.value = value || '';
        if (hintEl) hintEl.hidden = !isNew;
    }

    function resetQuotationForm() {
        if (!form) return;
        form.reset();
        var idInput = document.getElementById('quotation_id');
        if (idInput) idInput.value = '';
        setQuotationNumberDisplay(nextQuotationNumber, true);
        var titleEl = document.getElementById('case-quotation-form-title');
        var submitBtn = document.getElementById('case-quotation-submit-btn');
        if (titleEl) titleEl.textContent = 'New Quotation';
        if (submitBtn) submitBtn.textContent = 'Save Quotation';
    }

    window.loadCaseQuotationForEdit = function (quotationId) {
        var data = quotationsData && quotationsData[String(quotationId)];
        if (!data || !form) return;
        window.showCaseQuotationForm();
        document.getElementById('quotation_id').value = String(data.id || '');
        setQuotationNumberDisplay(data.quotation_number || '', false);
        var amountInput = document.getElementById('quotation_amount');
        if (amountInput) amountInput.value = data.amount != null ? data.amount : '';
        form.querySelector('[name="quotation_valid_until"]').value = data.valid_until || '';
        document.getElementById('quotation_tax_rate').value = data.tax_rate != null ? data.tax_rate : 0;
        var bankSelect = document.getElementById('quotation_bank_account_slot');
        if (bankSelect) bankSelect.value = data.bank_account_slot != null ? String(data.bank_account_slot) : bankSelect.value;
        var termsInput = form.querySelector('[name="payment_terms"]');
        if (termsInput) termsInput.value = data.payment_terms || termsInput.value;
        var instructionsInput = form.querySelector('[name="payment_instructions"]');
        if (instructionsInput) instructionsInput.value = data.payment_instructions || '';
        var titleEl = document.getElementById('case-quotation-form-title');
        var submitBtn = document.getElementById('case-quotation-submit-btn');
        if (titleEl) titleEl.textContent = 'Edit Quotation';
        if (submitBtn) submitBtn.textContent = 'Update Quotation';
        if (formCard) formCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };

    document.querySelectorAll('.case-quotation-edit-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            window.loadCaseQuotationForEdit(btn.getAttribute('data-quotation-id'));
        });
    });

    window.showCaseQuotationForm = function () {
        if (formCard) formCard.hidden = false;
    };
    window.hideCaseQuotationForm = function () {
        if (formCard) formCard.hidden = true;
        resetQuotationForm();
    };
    window.focusCaseQuotationForm = function () {
        resetQuotationForm();
        window.showCaseQuotationForm();
        if (formCard) formCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
        var firstInput = document.getElementById('quotation_amount');
        if (firstInput) firstInput.focus();
    };

    setQuotationNumberDisplay(nextQuotationNumber, true);
    if (addBtn) addBtn.addEventListener('click', window.focusCaseQuotationForm);
    if (cancelBtn) cancelBtn.addEventListener('click', window.hideCaseQuotationForm);
});
</script>
JS;

    $script = str_replace('{QUOTATIONS_EDIT_JSON}', $editJson, $script);
    $script = str_replace('{NEXT_QUOTATION_NUMBER_JSON}', json_encode($nextQuotationNumber, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), $script);

    return $script;
}
