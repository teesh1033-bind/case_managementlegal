<?php
/**
 * Lawyer portal status/priority pills — same look as client portal (Open, Normal, etc.).
 * Loaded after portal theme so badges stay visible on every lawyer page.
 */
if (defined('LEGALPRO_LAWYER_PORTAL_BADGES_CSS')) {
    return;
}
define('LEGALPRO_LAWYER_PORTAL_BADGES_CSS', true);
?>
<style id="legalpro-lawyer-portal-badges">
body.legalpro-lawyer-portal .ca-status-pill,
body.legalpro-lawyer-portal .lp-pill {
    display: inline-block !important;
    font-size: 0.72rem !important;
    font-weight: 700 !important;
    padding: 0.35em 0.85em !important;
    border-radius: 999px !important;
    line-height: 1.2 !important;
    white-space: nowrap !important;
    border: none !important;
}
body.legalpro-lawyer-portal .ca-status-pill--pending,
body.legalpro-lawyer-portal .lp-pill--status-pending,
body.legalpro-lawyer-portal .lp-pill--status-warning {
    background: var(--lp-semantic-warning-bg, rgba(251, 140, 0, 0.14)) !important;
    color: var(--lp-semantic-warning, #e65100) !important;
}
body.legalpro-lawyer-portal .ca-status-pill--scheduled,
body.legalpro-lawyer-portal .lp-pill--status-progress,
body.legalpro-lawyer-portal .lp-pill--priority-medium {
    background: var(--lp-semantic-info-bg, rgba(17, 113, 239, 0.12)) !important;
    color: var(--lp-semantic-info, #1171ef) !important;
}
body.legalpro-lawyer-portal .ca-status-pill--done,
body.legalpro-lawyer-portal .lp-pill--status-closed {
    background: var(--lp-semantic-neutral-bg, rgba(103, 116, 142, 0.12)) !important;
    color: var(--lp-semantic-neutral, #67748e) !important;
}
body.legalpro-lawyer-portal .ca-status-pill--declined,
body.legalpro-lawyer-portal .lp-pill--status-declined,
body.legalpro-lawyer-portal .lp-pill--status-danger {
    background: var(--lp-semantic-danger-bg, rgba(245, 54, 92, 0.12)) !important;
    color: var(--lp-semantic-danger, #d6336c) !important;
}
body.legalpro-lawyer-portal .ca-status-pill--muted,
body.legalpro-lawyer-portal .lp-pill--status-default {
    background: var(--lp-semantic-neutral-bg, rgba(103, 116, 142, 0.1)) !important;
    color: var(--lp-semantic-neutral, #8392ab) !important;
}
body.legalpro-lawyer-portal .lp-pill--status-active,
body.legalpro-lawyer-portal .lp-pill--status-success {
    background: var(--lp-semantic-success-bg, rgba(45, 206, 137, 0.14)) !important;
    color: var(--lp-semantic-success, #1aae6f) !important;
}
body.legalpro-lawyer-portal .lp-pill--priority-high {
    background: var(--lp-semantic-info-bg, rgba(17, 113, 239, 0.12)) !important;
    color: var(--lp-semantic-info, #1171ef) !important;
}
body.legalpro-lawyer-portal .lp-pill--priority-urgent {
    background: var(--lp-semantic-danger-bg, rgba(245, 54, 92, 0.12)) !important;
    color: var(--lp-semantic-danger, #d6336c) !important;
}
body.legalpro-lawyer-portal .lc-category-pill {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    padding: 0.35rem 0.65rem !important;
    border-radius: 2rem !important;
    background: rgba(0, 119, 182, 0.08) !important;
    color: #324cdd !important;
    font-size: 0.75rem !important;
    font-weight: 700 !important;
    line-height: 1 !important;
    white-space: nowrap !important;
    vertical-align: middle !important;
}
</style>
