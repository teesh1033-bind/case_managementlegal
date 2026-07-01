<?php
/**
 * Client appointment — view details modal (matches court hearing modal style).
 */
if (!function_exists('legalpro_icon')) {
    require_once __DIR__ . '/legalpro-icons.php';
}
require_once __DIR__ . '/client-modal-i18n.php';
?>
<div class="modal fade" id="viewAppointmentModal" tabindex="-1" aria-labelledby="viewAppointmentModalLabel" aria-hidden="true">
    <div class="modal-dialog legalpro-court-detail-modal modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0">
            <div class="legalpro-court-detail__hero">
                <button type="button" class="btn-close legalpro-court-detail__close" data-bs-dismiss="modal" aria-label="<?php echo htmlspecialchars(client_modal_t('common.close', 'Close')); ?>"></button>
                <p class="legalpro-court-detail__eyebrow"><?php echo htmlspecialchars(client_modal_t('modal.appointment', 'Appointment')); ?></p>
                <h2 class="legalpro-court-detail__title" id="viewAppointmentModalLabel"></h2>
                <div class="legalpro-court-detail__hero-meta">
                    <span class="legalpro-court-detail__datetime" id="view_apt_datetime"></span>
                    <span id="view_apt_status" class="legalpro-court-detail__status legalpro-court-detail__status--scheduled"></span>
                </div>
            </div>
            <div class="modal-body legalpro-court-detail__body">
                <div class="legalpro-court-detail__grid">
                    <div class="legalpro-court-detail__card">
                        <span class="legalpro-court-detail__card-icon" aria-hidden="true"><?php echo legalpro_icon('briefcase'); ?></span>
                        <div class="legalpro-court-detail__card-body">
                            <span class="legalpro-court-detail__label"><?php echo htmlspecialchars(client_modal_t('modal.matter', 'Matter')); ?></span>
                            <span class="legalpro-court-detail__value" id="view_apt_case">—</span>
                        </div>
                    </div>
                    <div class="legalpro-court-detail__card">
                        <span class="legalpro-court-detail__card-icon" aria-hidden="true"><?php echo legalpro_icon('user'); ?></span>
                        <div class="legalpro-court-detail__card-body">
                            <span class="legalpro-court-detail__label"><?php echo htmlspecialchars(client_modal_t('modal.lawyer', 'Lawyer')); ?></span>
                            <span class="legalpro-court-detail__value" id="view_apt_lawyer">—</span>
                        </div>
                    </div>
                    <div class="legalpro-court-detail__card">
                        <span class="legalpro-court-detail__card-icon" aria-hidden="true"><?php echo legalpro_icon('calendar-clock'); ?></span>
                        <div class="legalpro-court-detail__card-body">
                            <span class="legalpro-court-detail__label"><?php echo htmlspecialchars(client_modal_t('modal.starts', 'Starts')); ?></span>
                            <span class="legalpro-court-detail__value" id="view_apt_starts">—</span>
                        </div>
                    </div>
                    <div class="legalpro-court-detail__card">
                        <span class="legalpro-court-detail__card-icon" aria-hidden="true"><?php echo legalpro_icon('calendar'); ?></span>
                        <div class="legalpro-court-detail__card-body">
                            <span class="legalpro-court-detail__label"><?php echo htmlspecialchars(client_modal_t('modal.ends', 'Ends')); ?></span>
                            <span class="legalpro-court-detail__value" id="view_apt_ends">—</span>
                        </div>
                    </div>
                </div>
                <div class="legalpro-court-detail__notes" id="view_apt_notes_wrap">
                    <div class="legalpro-court-detail__notes-head">
                        <span class="legalpro-court-detail__card-icon legalpro-court-detail__card-icon--sm" aria-hidden="true"><?php echo legalpro_icon('file-text'); ?></span>
                        <span class="legalpro-court-detail__label"><?php echo htmlspecialchars(client_modal_t('modal.notes', 'Notes')); ?></span>
                    </div>
                    <p class="legalpro-court-detail__notes-text" id="view_apt_notes">—</p>
                </div>
            </div>
            <div class="modal-footer legalpro-court-detail__footer">
                <button type="button" class="btn btn-outline-secondary mb-0" data-bs-dismiss="modal"><?php echo htmlspecialchars(client_modal_t('common.close', 'Close')); ?></button>
            </div>
        </div>
    </div>
</div>
