<?php
/**
 * Themed print/download styles for invoices, receipts, and quotations.
 */
function legalpro_render_finance_document_head(string $pageTitle = 'Document'): void
{
    static $rendered = false;
    if ($rendered) {
        return;
    }
    $rendered = true;

    echo '<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet" />' . "\n";
    echo '<style id="legalpro-portal-theme">' . renderPortalThemeCss() . '</style>' . "\n";
    echo '<style>'
        . '*,*::before,*::after{box-sizing:border-box;}'
        . 'body.fin-doc-page{font-family:Montserrat,system-ui,sans-serif;margin:0;padding:28px 20px;background:#f0f2f8;color:#1e293b;-webkit-font-smoothing:antialiased;}'
        . '.fin-doc{max-width:780px;margin:0 auto;background:#fff;border-radius:16px;overflow:hidden;border:1px solid #e9ecf3;box-shadow:0 10px 36px rgba(15,23,42,.09);}'
        . '.fin-doc-top{background:var(--legalpro-theme-gradient,linear-gradient(135deg,#5e72e4,#825ee4));color:#fff;padding:28px 32px;display:flex;justify-content:space-between;align-items:flex-start;gap:16px;position:relative;overflow:hidden;}'
        . '.fin-doc-top::before{content:"";position:absolute;top:-40px;right:-40px;width:140px;height:140px;border-radius:50%;background:rgba(255,255,255,.1);}'
        . '.fin-doc-top h1{margin:0 0 4px;font-size:1.45rem;font-weight:800;letter-spacing:-.02em;position:relative;}'
        . '.fin-doc-top .fin-doc-firm{font-size:.88rem;opacity:.88;position:relative;}'
        . '.fin-doc-top-actions{display:flex;align-items:center;gap:10px;flex-shrink:0;position:relative;z-index:1;}'
        . '.fin-doc-badge{background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.28);color:#fff;padding:6px 12px;border-radius:8px;font-size:.72rem;font-weight:700;letter-spacing:.04em;}'
        . '.fin-doc-print{background:#fff;color:var(--legalpro-theme-primary,#5e72e4);border:none;border-radius:8px;padding:8px 16px;font-size:.8rem;font-weight:700;cursor:pointer;font-family:inherit;}'
        . '.fin-doc-print:hover{opacity:.92;}'
        . '.fin-doc-body{padding:28px 32px 32px;}'
        . '.fin-doc-section{margin-bottom:24px;}'
        . '.fin-doc-section:last-child{margin-bottom:0;}'
        . '.fin-doc-section-title{font-size:.68rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:var(--legalpro-theme-primary,#5e72e4);margin-bottom:10px;}'
        . '.fin-doc-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px;font-size:.88rem;line-height:1.45;}'
        . '.fin-doc-grid strong{color:#334155;display:block;margin-bottom:2px;font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;font-weight:700;}'
        . '.fin-doc-table{width:100%;border-collapse:collapse;font-size:.88rem;margin-top:8px;}'
        . '.fin-doc-table th{background:rgba(var(--legalpro-theme-primary-rgb,94,114,228),.08);color:var(--legalpro-theme-primary,#5e72e4);font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;padding:10px 12px;text-align:left;border-bottom:2px solid rgba(var(--legalpro-theme-primary-rgb,94,114,228),.14);}'
        . '.fin-doc-table td{padding:12px;border-bottom:1px solid #f1f5f9;vertical-align:top;}'
        . '.fin-doc-table tbody tr:last-child td{border-bottom:none;}'
        . '.fin-doc-totals{width:min(100%,320px);margin-left:auto;margin-top:16px;font-size:.88rem;}'
        . '.fin-doc-totals td{border:none;padding:6px 0;}'
        . '.fin-doc-totals .label{color:#64748b;font-size:.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em;}'
        . '.fin-doc-totals .value{text-align:right;font-weight:700;color:#1e293b;}'
        . '.fin-doc-totals tr.grand td{padding-top:12px;border-top:2px solid rgba(var(--legalpro-theme-primary-rgb,94,114,228),.2);}'
        . '.fin-doc-totals tr.grand .value{font-size:1.15rem;color:var(--legalpro-theme-primary,#5e72e4);}'
        . '.fin-doc-notes{background:rgba(var(--legalpro-theme-primary-rgb,94,114,228),.06);border:1px solid rgba(var(--legalpro-theme-primary-rgb,94,114,228),.12);border-radius:10px;padding:14px 16px;font-size:.88rem;line-height:1.55;}'
        . '.fin-doc-footer{font-size:.82rem;color:#64748b;line-height:1.5;padding-top:8px;border-top:1px solid #f1f5f9;margin-top:8px;}'
        . '.fin-doc-signature{margin-top:40px;font-size:.82rem;color:#64748b;}'
        . '.fin-doc-signature-line{margin-top:28px;border-top:1px solid #cbd5e1;width:220px;}'
        . '.text-end{text-align:right;}'
        . '@media print{body.fin-doc-page{padding:0;background:#fff;}.fin-doc{border:none;border-radius:0;box-shadow:none;}.fin-doc-print{display:none;}}'
        . '</style>' . "\n";
}
