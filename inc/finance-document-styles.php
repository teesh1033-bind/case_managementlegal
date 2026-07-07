<?php
/**
 * Shared print/PDF styles for invoices, receipts, and quotations.
 */

function legalpro_finance_theme_colors(): array
{
    $theme = getPortalTheme();
    $preset = $theme['preset'] ?? [];
    $primary = (string) ($preset['primary'] ?? '#0077b6');
    $primaryDark = (string) ($preset['primary_dark'] ?? '#004e77');
    $rgb = portalThemeRgbFromHex($primary);

    return [
        'primary' => $primary,
        'primary_dark' => $primaryDark,
        'primary_rgb' => implode(', ', $rgb),
        'primary_soft' => portalThemeHexToRgba($primary, 0.08),
        'primary_soft_border' => portalThemeHexToRgba($primary, 0.14),
        'primary_notes_bg' => portalThemeHexToRgba($primary, 0.06),
        'primary_notes_border' => portalThemeHexToRgba($primary, 0.12),
        'primary_totals_border' => portalThemeHexToRgba($primary, 0.2),
    ];
}

function legalpro_finance_document_css(): string
{
    $c = legalpro_finance_theme_colors();

    return ''
        . '*,*::before,*::after{box-sizing:border-box;}'
        . 'body.fin-doc-page{font-family:DejaVu Sans,Helvetica,Arial,sans-serif;margin:0;padding:24px 18px;background:#f0f2f8;color:#1e293b;-webkit-font-smoothing:antialiased;}'
        . '.fin-doc{max-width:780px;margin:0 auto;background:#fff;border-radius:16px;overflow:hidden;border:1px solid #e9ecf3;box-shadow:0 10px 36px rgba(15,23,42,.09);}'
        . '.fin-doc-top{background:' . $c['primary'] . ';color:#fff;padding:26px 30px;display:table;width:100%;position:relative;}'
        . '.fin-doc-top-main{display:table-cell;vertical-align:top;width:70%;}'
        . '.fin-doc-top-actions{display:table-cell;vertical-align:top;text-align:right;white-space:nowrap;}'
        . '.fin-doc-brand-row{display:table;margin-bottom:10px;}'
        . '.fin-doc-logo{display:table-cell;vertical-align:middle;width:52px;height:52px;max-width:120px;max-height:52px;object-fit:contain;background:rgba(255,255,255,.96);border-radius:10px;padding:5px;margin-right:12px;}'
        . '.fin-doc-brand-text{display:table-cell;vertical-align:middle;}'
        . '.fin-doc-top h1{margin:0;font-size:22px;font-weight:700;letter-spacing:-.02em;}'
        . '.fin-doc-top .fin-doc-firm{font-size:14px;font-weight:700;opacity:1;line-height:1.3;}'
        . '.fin-doc-badge{display:inline-block;background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.28);color:#fff;padding:6px 12px;border-radius:8px;font-size:11px;font-weight:700;letter-spacing:.04em;margin-top:4px;}'
        . '.fin-doc-toolbar{max-width:780px;margin:0 auto 12px;text-align:right;}'
        . '.fin-doc-action{display:inline-block;background:#fff;color:' . $c['primary'] . ';border:1px solid #dbe3f0;border-radius:8px;padding:8px 14px;font-size:12px;font-weight:700;text-decoration:none;margin-left:8px;cursor:pointer;font-family:inherit;}'
        . '.fin-doc-body{padding:26px 30px 30px;}'
        . '.fin-doc-section{margin-bottom:22px;}'
        . '.fin-doc-section:last-child{margin-bottom:0;}'
        . '.fin-doc-section-title{font-size:10px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:' . $c['primary'] . ';margin-bottom:10px;}'
        . '.fin-doc-grid{display:block;font-size:13px;line-height:1.45;}'
        . '.fin-doc-grid > div{display:inline-block;vertical-align:top;width:48%;margin:0 1% 12px 0;}'
        . '.fin-doc-grid strong{color:#334155;display:block;margin-bottom:2px;font-size:10px;text-transform:uppercase;letter-spacing:.06em;font-weight:700;}'
        . '.fin-doc-table{width:100%;border-collapse:collapse;font-size:13px;margin-top:8px;}'
        . '.fin-doc-table th{background:' . $c['primary_soft'] . ';color:' . $c['primary'] . ';font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;padding:10px 12px;text-align:left;border-bottom:2px solid ' . $c['primary_soft_border'] . ';}'
        . '.fin-doc-table td{padding:12px;border-bottom:1px solid #f1f5f9;vertical-align:top;}'
        . '.fin-doc-table tbody tr:last-child td{border-bottom:none;}'
        . '.fin-doc-totals{width:100%;max-width:320px;margin-left:auto;margin-top:16px;font-size:13px;}'
        . '.fin-doc-totals td{border:none;padding:6px 0;}'
        . '.fin-doc-totals .label{color:#64748b;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;}'
        . '.fin-doc-totals .value{text-align:right;font-weight:700;color:#1e293b;}'
        . '.fin-doc-totals tr.grand td{padding-top:12px;border-top:2px solid ' . $c['primary_totals_border'] . ';}'
        . '.fin-doc-totals tr.grand .value{font-size:16px;color:' . $c['primary'] . ';}'
        . '.fin-doc-notes{background:' . $c['primary_notes_bg'] . ';border:1px solid ' . $c['primary_notes_border'] . ';border-radius:10px;padding:14px 16px;font-size:13px;line-height:1.55;}'
        . '.fin-doc-footer{font-size:12px;color:#64748b;line-height:1.5;padding-top:8px;border-top:1px solid #f1f5f9;margin-top:8px;}'
        . '.fin-doc-summary-box{background:' . $c['primary'] . ';color:#fff;border-radius:12px;padding:18px 20px;margin-top:16px;}'
        . '.fin-doc-summary-table{width:100%;border-collapse:collapse;font-size:13px;}'
        . '.fin-doc-summary-table td{padding:5px 0;border:none;}'
        . '.fin-doc-summary-table .label{opacity:.9;font-size:12px;}'
        . '.fin-doc-summary-table .value{text-align:right;font-weight:700;}'
        . '.fin-doc-summary-table tr.grand td{padding-top:10px;}'
        . '.fin-doc-summary-table tr.emphasis .value{font-size:14px;font-weight:800;}'
        . '.fin-doc-summary-table tr.divider td{padding:0;height:1px;border:none;border-top:1px solid rgba(255,255,255,.28);}'
        . '.fin-doc-summary-table tr.grand-total td{padding-top:14px;font-size:16px;font-weight:800;}'
        . '.fin-doc-payment{margin-top:24px;padding-top:18px;border-top:1px solid #e2e8f0;font-size:13px;line-height:1.55;}'
        . '.fin-doc-pay-status{margin:0 0 12px;color:#334155;}'
        . '.fin-doc-payable-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#64748b;margin-bottom:6px;}'
        . '.fin-doc-payable-firm{font-size:16px;font-weight:800;color:' . $c['primary'] . ';text-transform:uppercase;margin-bottom:10px;letter-spacing:.02em;}'
        . '.fin-doc-pay-line{margin-bottom:4px;color:#1e293b;}'
        . '.fin-doc-pay-label{font-weight:600;color:#475569;}'
        . '.fin-doc-pay-terms{margin-top:12px;font-weight:600;color:#334155;}'
        . '.fin-doc-pay-instructions{margin-top:8px;color:#64748b;}'
        . '.fin-doc-thanks{margin-top:20px;padding-top:14px;border-top:1px solid #e2e8f0;text-align:center;font-size:12px;color:#94a3b8;}'
        . '.fin-doc-signature{margin-top:36px;font-size:12px;color:#64748b;}'
        . '.fin-doc-signature-line{margin-top:24px;border-top:1px solid #cbd5e1;width:220px;}'
        . '.text-end{text-align:right;}'
        . '.fin-doc-legal-body{font-size:13px;line-height:1.65;}'
        . '.fin-doc--invoice{border-radius:0;border:none;box-shadow:none;max-width:800px;}'
        . 'body.fin-doc-page .fin-doc--invoice{margin:0 auto;}'
        . '.fin-doc-inv-accent{height:5px;background:linear-gradient(90deg,' . $c['primary'] . ' 0%,' . $c['primary_dark'] . ' 100%);}'
        . '.fin-doc-inv-header{background:#fff;border-bottom:1px solid #eef2f7;}'
        . '.fin-doc-inv-header-inner{display:table;width:100%;padding:28px 32px 24px;}'
        . '.fin-doc-inv-col{display:table-cell;vertical-align:top;}'
        . '.fin-doc-inv-col--brand{width:46%;padding-right:16px;}'
        . '.fin-doc-inv-col--spacer{width:18%;}'
        . '.fin-doc-inv-col--meta{width:36%;text-align:right;padding-left:12px;}'
        . '.fin-doc-inv-brand{display:table;margin-bottom:14px;}'
        . '.fin-doc-inv-logo{display:table-cell;vertical-align:middle;width:48px;height:48px;max-width:110px;max-height:48px;object-fit:contain;border-radius:10px;border:1px solid #e8edf4;padding:4px;margin-right:12px;background:#fff;}'
        . '.fin-doc-inv-brand-text{display:table-cell;vertical-align:middle;}'
        . '.fin-doc-inv-firm{font-size:15px;font-weight:800;color:#0f172a;line-height:1.25;}'
        . '.fin-doc-inv-address{font-size:10px;color:#64748b;line-height:1.45;margin-top:3px;}'
        . '.fin-doc-inv-contact{font-size:10px;color:#94a3b8;margin-top:4px;}'
        . '.fin-doc-inv-doc-label{font-size:32px;font-weight:800;color:#0f172a;letter-spacing:-.04em;line-height:1;margin-top:4px;}'
        . '.fin-doc-inv-number{font-size:12px;font-weight:700;color:#64748b;letter-spacing:.12em;text-transform:uppercase;margin-bottom:6px;}'
        . '.fin-doc-inv-status{display:inline-block;font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.1em;padding:4px 10px;border-radius:999px;margin-bottom:12px;}'
        . '.fin-doc-inv-status.is-paid{background:#dcfce7;color:#166534;}'
        . '.fin-doc-inv-status.is-overdue{background:#fee2e2;color:#991b1b;}'
        . '.fin-doc-inv-status.is-open{background:' . $c['primary_soft'] . ';color:' . $c['primary'] . ';}'
        . '.fin-doc-inv-due-card{background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:14px 16px;text-align:right;}'
        . '.fin-doc-inv-due-label{display:block;font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#94a3b8;margin-bottom:4px;}'
        . '.fin-doc-inv-due-value{display:block;font-size:26px;font-weight:800;color:' . $c['primary'] . ';letter-spacing:-.03em;line-height:1.1;}'
        . '.fin-doc-inv-due-date{display:block;font-size:10px;color:#64748b;margin-top:6px;}'
        . '.fin-doc--invoice .fin-doc-body{padding:24px 32px 28px;}'
        . '.fin-doc-inv-cards{display:table;width:100%;margin-bottom:22px;border-collapse:separate;border-spacing:14px 0;margin-left:-14px;width:calc(100% + 28px);}'
        . '.fin-doc-inv-card{display:table-cell;vertical-align:top;width:50%;background:#f8fafc;border:1px solid #e8edf4;border-radius:12px;padding:16px 18px;}'
        . '.fin-doc-inv-card-name{font-size:16px;font-weight:800;color:#0f172a;margin:6px 0 4px;}'
        . '.fin-doc-inv-card-line{font-size:12px;color:#64748b;line-height:1.5;}'
        . '.fin-doc-inv-detail{font-size:12px;color:#1e293b;margin-bottom:7px;line-height:1.4;}'
        . '.fin-doc-inv-detail span{display:block;font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#94a3b8;margin-bottom:2px;}'
        . '.fin-doc-table--invoice{border:1px solid #e8edf4;border-radius:12px;overflow:hidden;}'
        . '.fin-doc-table--invoice th{border-bottom:1px solid #e2e8f0;}'
        . '.fin-doc-table--invoice td{border-bottom:1px solid #f1f5f9;}'
        . '.fin-doc-table--invoice tbody tr:last-child td{border-bottom:none;}'
        . '.fin-doc-table--invoice tbody tr:nth-child(even){background:#fafbfd;}'
        . '.fin-doc-table .col-num{width:40px;color:#cbd5e1;font-weight:800;font-size:11px;}'
        . '.fin-doc-amount-cell{font-weight:700;color:#0f172a;font-size:14px;}'
        . '.fin-doc-section--items{margin-bottom:20px;}'
        . '.fin-doc-section--notes{margin-bottom:20px;}'
        . '.fin-doc-inv-bottom{display:table;width:100%;margin-top:6px;}'
        . '.fin-doc-inv-bottom-pay{display:table-cell;vertical-align:top;width:54%;padding-right:20px;}'
        . '.fin-doc-inv-bottom-totals{display:table-cell;vertical-align:top;width:46%;}'
        . '.fin-doc-inv-bottom-pay .fin-doc-payment{margin-top:0;padding-top:0;border-top:none;}'
        . '.fin-doc-pay-grid{display:table;width:100%;border-collapse:separate;border-spacing:0 6px;margin-top:4px;}'
        . '.fin-doc-pay-item{display:table-row;}'
        . '.fin-doc-pay-item .fin-doc-pay-label{display:table-cell;font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#94a3b8;padding:2px 12px 2px 0;vertical-align:top;width:38%;}'
        . '.fin-doc-pay-item .fin-doc-pay-value{display:table-cell;font-size:12px;font-weight:600;color:#1e293b;vertical-align:top;}'
        . '.fin-doc-pay-terms,.fin-doc-pay-instructions{margin-top:12px;font-size:12px;color:#475569;line-height:1.5;}'
        . '.fin-doc-pay-terms .fin-doc-pay-label,.fin-doc-pay-instructions .fin-doc-pay-label{display:block;margin-bottom:3px;}'
        . '.fin-doc-summary-box--light{background:#f8fafc;border:1px solid #e2e8f0;color:#1e293b;border-radius:12px;padding:16px 18px;margin-top:0;}'
        . '.fin-doc-summary-box--light .fin-doc-summary-table .label{color:#64748b;font-size:11px;}'
        . '.fin-doc-summary-box--light .fin-doc-summary-table .value{color:#0f172a;}'
        . '.fin-doc-summary-box--light .fin-doc-summary-table tr.divider td{border-top:1px solid #e2e8f0;}'
        . '.fin-doc-summary-box--light .fin-doc-summary-table tr.emphasis td{padding-top:10px;}'
        . '.fin-doc-summary-box--light .fin-doc-summary-table tr.emphasis .label{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:' . $c['primary'] . ';}'
        . '.fin-doc-summary-box--light .fin-doc-summary-table tr.emphasis .value{font-size:20px;font-weight:800;color:' . $c['primary'] . ';}'
        . '.fin-doc-summary-box--light .fin-doc-summary-table tr.grand-total td{padding-top:8px;font-size:12px;}'
        . '.fin-doc-summary-box--light .fin-doc-summary-table tr.grand-total .label{color:#94a3b8;font-weight:600;}'
        . '.fin-doc-summary-box--light .fin-doc-summary-table tr.grand-total .value{font-size:13px;color:#64748b;font-weight:700;}'
        . '.fin-doc-inv-footer{margin-top:24px;padding-top:16px;border-top:1px solid #eef2f7;text-align:center;}'
        . '.fin-doc-inv-footer-firm{font-size:12px;font-weight:800;color:#0f172a;margin-bottom:4px;}'
        . '.fin-doc-inv-footer-meta{font-size:10px;color:#94a3b8;line-height:1.5;}'
        . '.fin-doc-inv-footer-thanks{margin-top:10px;font-size:10px;color:#cbd5e1;font-weight:600;letter-spacing:.04em;}'
        . '@media print{body.fin-doc-page{padding:0;background:#fff;}.fin-doc{border:none;border-radius:0;box-shadow:none;}.fin-doc-toolbar,.fin-doc-action,.no-print{display:none!important;}}';
}

function legalpro_render_finance_document_head(string $pageTitle = 'Document'): void
{
    static $rendered = false;
    if ($rendered) {
        return;
    }
    $rendered = true;

    echo '<style>' . legalpro_finance_document_css() . '</style>' . "\n";
}
