<?php
/**
 * Shared quotation UI helpers for admin and lawyer case views.
 */

require_once __DIR__ . '/case_quotations.php';

function case_quotations_handle_post(
    PDO $pdo,
    int $caseId,
    string $actorLabel,
    array $post,
    string &$activeTab,
    string &$message,
    string &$messageType
): void {
    $formType = (string) ($post['form_type'] ?? '');
    if ($formType === '') {
        return;
    }

    if ($formType === 'save_quotation') {
        $activeTab = 'quotations';
        $editQuotationId = isset($post['quotation_id']) ? (int) $post['quotation_id'] : 0;
        $validUntil = trim((string) ($post['quotation_valid_until'] ?? ''));
        $taxRate = isset($post['quotation_tax_rate']) ? (float) $post['quotation_tax_rate'] : 0;

        try {
            $amount = parse_quotation_amount_from_post($post);
            $items = quotation_items_from_amount($amount);
            $payload = array_merge([
                'title' => null,
                'status' => case_quotations_default_status(),
                'valid_until' => $validUntil,
                'notes' => null,
                'tax_rate' => $taxRate,
                'created_by' => $actorLabel,
                'updated_by' => $actorLabel,
            ], quotation_save_number_payload($pdo, $editQuotationId));

            if ($editQuotationId > 0) {
                if (update_case_quotation($pdo, $caseId, $editQuotationId, $payload, $items)) {
                    $message = 'Quotation updated successfully.';
                    $messageType = 'success';
                } else {
                    $message = 'Quotation not found.';
                    $messageType = 'danger';
                }
            } else {
                $quotationId = save_case_quotation($pdo, $caseId, $payload, $items);
                notify_client_about_quotation($pdo, $quotationId);
                $message = 'Quotation saved successfully.';
                $messageType = 'success';
            }
        } catch (InvalidArgumentException $e) {
            $message = $e->getMessage();
            $messageType = 'danger';
        } catch (PDOException $e) {
            $message = 'Error saving quotation: ' . htmlspecialchars($e->getMessage());
            $messageType = 'danger';
        }
        return;
    }

    if ($formType === 'delete_quotation') {
        $activeTab = 'quotations';
        $quotationId = isset($post['quotation_id']) ? (int) $post['quotation_id'] : 0;
        if ($quotationId <= 0) {
            $message = 'Invalid quotation.';
            $messageType = 'danger';
            return;
        }
        try {
            if (delete_case_quotation($pdo, $caseId, $quotationId)) {
                $message = 'Quotation deleted successfully.';
                $messageType = 'success';
            } else {
                $message = 'Quotation not found.';
                $messageType = 'danger';
            }
        } catch (PDOException $e) {
            $message = 'Error deleting quotation: ' . htmlspecialchars($e->getMessage());
            $messageType = 'danger';
        }
    }
}

/**
 * @return array{html: string, edit_json: string, count: int, next_number: string, default_valid_until: string}
 */
function case_quotations_build_admin_view(PDO $pdo, int $caseId): array
{
    if (!function_exists('legalpro_icon')) {
        require_once dirname(__DIR__) . '/inc/admin-layout.php';
    }

    $quotations = fetch_case_quotations($pdo, $caseId);
    $quotationsForEdit = [];
    $html = '';

    if (empty($quotations)) {
        $emptyMsg = 'No quotations yet. Click Add Quotation to create one.';
        if (function_exists('caseDetailFeedEmpty')) {
            $html = caseDetailFeedEmpty('clipboard-list', $emptyMsg);
        } else {
            $icon = function_exists('legalpro_icon') ? legalpro_icon('clipboard-list') : '';
            $html = '<div class="case-feed-empty text-center py-4">' . $icon
                . '<p class="text-sm text-muted mb-0 mt-2">' . htmlspecialchars($emptyMsg) . '</p></div>';
        }
    } else {
        $items = '';
        foreach ($quotations as $quotation) {
            $quoteId = (int) $quotation['id'];
            $quotationsForEdit[$quoteId] = [
                'id' => $quoteId,
                'quotation_number' => (string) ($quotation['quotation_number'] ?? ''),
                'amount' => (float) ($quotation['subtotal'] ?? 0),
                'valid_until' => (string) ($quotation['valid_until'] ?? ''),
                'tax_rate' => (float) ($quotation['tax_rate'] ?? 0),
            ];

            $quoteNumber = !empty($quotation['quotation_number'])
                ? $quotation['quotation_number']
                : 'QUO-' . str_pad((string) $quotation['id'], 4, '0', STR_PAD_LEFT);
            $quoteTotal = formatCurrency((float) ($quotation['total_amount'] ?? 0));
            $validUntilText = !empty($quotation['valid_until'])
                ? 'Valid until ' . date('M j, Y', strtotime($quotation['valid_until']))
                : 'No expiry date';
            $createdText = !empty($quotation['created_at'])
                ? 'Created ' . date('M j, Y', strtotime($quotation['created_at']))
                : '';

            $items .= '<article class="case-feed-item case-quotation-card flex-wrap align-items-start">'
                . '<div class="case-feed-item__icon dashboard-stat-icon-wrap dashboard-stat-icon-wrap--primary d-inline-flex align-items-center justify-content-center">'
                . legalpro_icon('clipboard-list')
                . '</div><div class="case-feed-item__body">'
                . '<h6 class="case-feed-item__title">' . htmlspecialchars($quoteNumber) . '</h6>'
                . '<p class="case-feed-item__subtitle mb-0">' . htmlspecialchars($quoteTotal) . ' · ' . htmlspecialchars($validUntilText)
                . ($createdText !== '' ? ' · ' . htmlspecialchars($createdText) : '') . '</p>'
                . '</div><div class="case-feed-item__aside flex-column align-items-end gap-2">'
                . '<div class="d-flex flex-wrap gap-1 justify-content-end">'
                . '<a href="client-quotation-view.php?id=' . $quoteId . '&view=1" class="btn btn-sm btn-outline-info mb-0" target="_blank" rel="noopener">View</a>'
                . '<button type="button" class="btn btn-sm btn-outline-primary mb-0 case-quotation-edit-btn" data-quotation-id="' . $quoteId . '">Edit</button>'
                . '<form method="POST" action="" onsubmit="return confirm(\'Delete this quotation?\');">'
                . '<input type="hidden" name="form_type" value="delete_quotation">'
                . '<input type="hidden" name="quotation_id" value="' . $quoteId . '">'
                . '<button type="submit" class="btn btn-sm btn-outline-danger mb-0">Delete</button>'
                . '</form></div></div></article>';
        }
        $html = '<div class="case-feed-list">' . $items . '</div>';
    }

    return [
        'html' => $html,
        'edit_json' => json_encode($quotationsForEdit, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP),
        'count' => count($quotations),
        'next_number' => get_next_quotation_number($pdo),
        'default_valid_until' => date('Y-m-d', strtotime('+30 days')),
    ];
}

/**
 * @return array{html: string, count: int}
 */
function case_quotations_build_readonly_view(PDO $pdo, int $caseId): array
{
    if (!function_exists('legalpro_icon')) {
        require_once dirname(__DIR__) . '/inc/admin-layout.php';
    }

    $quotations = fetch_case_quotations($pdo, $caseId);
    $html = '';

    if (empty($quotations)) {
        $emptyMsg = 'No quotations have been added for this case yet.';
        if (function_exists('caseDetailFeedEmpty')) {
            $html = caseDetailFeedEmpty('clipboard-list', $emptyMsg);
        } else {
            $html = '<p class="text-sm text-muted mb-0">' . htmlspecialchars($emptyMsg) . '</p>';
        }
    } else {
        $rows = '';
        foreach ($quotations as $quotation) {
            $quoteId = (int) $quotation['id'];
            $quoteNumber = !empty($quotation['quotation_number'])
                ? $quotation['quotation_number']
                : 'QUO-' . str_pad((string) $quotation['id'], 4, '0', STR_PAD_LEFT);
            $quoteTitle = trim((string) ($quotation['title'] ?? '')) ?: 'Quotation';
            $quoteTotal = formatCurrency((float) ($quotation['total_amount'] ?? 0));
            $validUntilText = !empty($quotation['valid_until'])
                ? date('M j, Y', strtotime($quotation['valid_until']))
                : '—';

            $rows .= '<tr>'
                . '<td><span class="text-sm font-weight-bold">' . htmlspecialchars($quoteNumber) . '</span>'
                . '<br><span class="text-xs text-muted">' . htmlspecialchars($quoteTitle) . '</span></td>'
                . '<td class="text-end"><span class="text-sm font-weight-bold">' . htmlspecialchars($quoteTotal) . '</span></td>'
                . '<td><span class="text-sm">' . htmlspecialchars($validUntilText) . '</span></td>'
                . '<td class="text-end">'
                . '<div class="d-flex flex-nowrap gap-1 justify-content-end">'
                . '<a href="client-quotation-view.php?id=' . $quoteId . '&view=1" class="btn btn-sm btn-outline-secondary mb-0" target="_blank" rel="noopener">View</a>'
                . '<a href="client-quotation-view.php?id=' . $quoteId . '" class="btn btn-sm btn-outline-primary mb-0" target="_blank" rel="noopener">PDF</a>'
                . '</div>'
                . '</td></tr>';
        }

        $html = '<div class="table-responsive"><table class="table table-sm align-items-center mb-0">'
            . '<thead><tr>'
            . '<th>Quotation</th><th class="text-end">Amount</th><th>Valid until</th><th class="text-end">Actions</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table></div>';
    }

    return [
        'html' => $html,
        'count' => count($quotations),
    ];
}

function case_quotations_render_line_preview(array $lineItems): string
{
    if (empty($lineItems)) {
        return '';
    }

    $linesPreview = '<div class="case-quotation-lines mt-2"><table class="table table-sm mb-0"><thead><tr>'
        . '<th class="text-xxs text-uppercase text-secondary">Item</th>'
        . '<th class="text-xxs text-uppercase text-secondary text-end">Qty</th>'
        . '<th class="text-xxs text-uppercase text-secondary text-end">Price</th>'
        . '<th class="text-xxs text-uppercase text-secondary text-end">Total</th>'
        . '</tr></thead><tbody>';
    foreach ($lineItems as $lineItem) {
        $linesPreview .= '<tr>'
            . '<td class="text-sm">' . htmlspecialchars((string) $lineItem['description']) . '</td>'
            . '<td class="text-sm text-end">' . htmlspecialchars(rtrim(rtrim(number_format((float) $lineItem['quantity'], 2, '.', ''), '0'), '.')) . '</td>'
            . '<td class="text-sm text-end">' . formatCurrency((float) $lineItem['unit_price']) . '</td>'
            . '<td class="text-sm text-end">' . formatCurrency((float) $lineItem['line_total']) . '</td>'
            . '</tr>';
    }
    $linesPreview .= '</tbody></table></div>';

    return $linesPreview;
}

/** @deprecated Use case_quotations_build_admin_view() */
function case_quotations_build_view(PDO $pdo, int $caseId): array
{
    $view = case_quotations_build_admin_view($pdo, $caseId);
    $view['status_options_html'] = '';

    return $view;
}
