<?php
/**
 * Embeddable quotations panel (form + list). Expects $quotationsView from case_quotations_build_view().
 */
if (!isset($quotationsView) || !is_array($quotationsView)) {
    return;
}
?>
<div class="d-flex justify-content-end mb-3">
    <button type="button" class="btn btn-sm bg-gradient-dark mb-0" id="case-quotation-add-btn">Add Quotation</button>
</div>

<div class="card case-detail-form-card border-0 mb-4" id="case-quotation-form-card" hidden>
    <div class="card-header border-0 d-flex justify-content-between align-items-center">
        <h6 class="mb-0" id="case-quotation-form-title">New Quotation</h6>
        <button type="button" class="btn btn-link text-secondary btn-sm mb-0 p-0" id="case-quotation-cancel-btn">Cancel</button>
    </div>
    <div class="card-body pt-0">
        <form method="POST" action="" id="case-quotation-form">
            <input type="hidden" name="form_type" value="save_quotation">
            <input type="hidden" name="quotation_id" id="quotation_id" value="">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label text-sm">Quotation Number</label>
                    <input type="text" class="form-control bg-light" id="quotation_number_display" value="<?php echo htmlspecialchars($quotationsView['next_number']); ?>" readonly tabindex="-1">
                    <small class="text-muted" id="quotation_number_hint">Assigned automatically when you save.</small>
                </div>
                <div class="col-md-3">
                    <label class="form-label text-sm">Amount</label>
                    <input type="number" class="form-control" name="quotation_amount" id="quotation_amount" min="0" step="0.01" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label text-sm">Valid</label>
                    <input type="date" class="form-control" name="quotation_valid_until" value="<?php echo htmlspecialchars($quotationsView['default_valid_until']); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label text-sm">Tax Rate (%)</label>
                    <input type="number" class="form-control" name="quotation_tax_rate" id="quotation_tax_rate" min="0" step="0.01" value="0">
                </div>
            </div>
            <button type="submit" class="btn btn-dark btn-sm mt-3 mb-0" id="case-quotation-submit-btn">Save Quotation</button>
        </form>
    </div>
</div>

<?php echo $quotationsView['html']; ?>
