<?php
/**
 * Court date — view details modal (admin, lawyer, client).
 *
 * Before include, set optional flags:
 *   $courtDateViewShowClient = true|false  (default true)
 */
if (!function_exists('legalpro_icon')) {
    require_once __DIR__ . '/legalpro-icons.php';
}
$courtDateViewShowClient = isset($courtDateViewShowClient) ? (bool) $courtDateViewShowClient : true;
?>
<div class="modal fade" id="viewCourtDateModal" tabindex="-1" aria-labelledby="viewCourtDateModalLabel" aria-hidden="true">
    <div class="modal-dialog legalpro-court-detail-modal modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0">
            <div class="legalpro-court-detail__hero">
                <button type="button" class="btn-close legalpro-court-detail__close" data-bs-dismiss="modal" aria-label="Close"></button>
                <p class="legalpro-court-detail__eyebrow">Court hearing</p>
                <h2 class="legalpro-court-detail__title" id="viewCourtDateModalLabel"></h2>
                <div class="legalpro-court-detail__hero-meta">
                    <span class="legalpro-court-detail__datetime" id="view_datetime"></span>
                    <span id="view_status" class="legalpro-court-detail__status legalpro-court-detail__status--scheduled"></span>
                </div>
            </div>
            <div class="modal-body legalpro-court-detail__body">
                <div class="legalpro-court-detail__grid">
                    <div class="legalpro-court-detail__card">
                        <span class="legalpro-court-detail__card-icon" aria-hidden="true"><?php echo legalpro_icon('briefcase'); ?></span>
                        <div class="legalpro-court-detail__card-body">
                            <span class="legalpro-court-detail__label">Case</span>
                            <span class="legalpro-court-detail__value" id="view_case_title">—</span>
                        </div>
                    </div>
                    <?php if ($courtDateViewShowClient): ?>
                    <div class="legalpro-court-detail__card" id="view_client_row">
                        <span class="legalpro-court-detail__card-icon" aria-hidden="true"><?php echo legalpro_icon('user'); ?></span>
                        <div class="legalpro-court-detail__card-body">
                            <span class="legalpro-court-detail__label">Client</span>
                            <span class="legalpro-court-detail__value" id="view_client_name">—</span>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="legalpro-court-detail__card">
                        <span class="legalpro-court-detail__card-icon" aria-hidden="true"><?php echo legalpro_icon('map-pin'); ?></span>
                        <div class="legalpro-court-detail__card-body">
                            <span class="legalpro-court-detail__label">Location</span>
                            <span class="legalpro-court-detail__value" id="view_location">—</span>
                        </div>
                    </div>
                    <div class="legalpro-court-detail__card">
                        <span class="legalpro-court-detail__card-icon" aria-hidden="true"><?php echo legalpro_icon('shield'); ?></span>
                        <div class="legalpro-court-detail__card-body">
                            <span class="legalpro-court-detail__label">Created by</span>
                            <span class="legalpro-court-detail__value" id="view_created_by">—</span>
                            <span class="legalpro-court-detail__sub" id="view_creator_role"></span>
                        </div>
                    </div>
                </div>
                <div class="legalpro-court-detail__notes" id="view_description_wrap">
                    <div class="legalpro-court-detail__notes-head">
                        <span class="legalpro-court-detail__card-icon legalpro-court-detail__card-icon--sm" aria-hidden="true"><?php echo legalpro_icon('file-text'); ?></span>
                        <span class="legalpro-court-detail__label">Description</span>
                    </div>
                    <p class="legalpro-court-detail__notes-text" id="view_description">—</p>
                </div>
            </div>
            <div class="modal-footer legalpro-court-detail__footer">
                <button type="button" class="btn btn-outline-secondary mb-0" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
