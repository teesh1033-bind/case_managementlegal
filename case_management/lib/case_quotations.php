<?php
/**
 * Case quotations — schema, numbering, and persistence helpers.
 */

function ensure_case_quotation_schema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS case_quotations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            case_id INT NOT NULL,
            quotation_number VARCHAR(50) NOT NULL,
            title VARCHAR(255) DEFAULT NULL,
            status ENUM('draft', 'sent', 'accepted', 'rejected', 'expired') NOT NULL DEFAULT 'draft',
            valid_until DATE NULL,
            notes TEXT NULL,
            subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            created_by VARCHAR(100) DEFAULT 'Admin',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_case_quotations_case_id (case_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS case_quotation_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            quotation_id INT NOT NULL,
            description VARCHAR(500) NOT NULL,
            quantity DECIMAL(10,2) NOT NULL DEFAULT 1.00,
            unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            line_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            sort_order INT NOT NULL DEFAULT 0,
            INDEX idx_case_quotation_items_quotation_id (quotation_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    foreach ([
        'ADD COLUMN invoice_id INT NULL AFTER total_amount',
        'ADD COLUMN responded_at TIMESTAMP NULL AFTER updated_at',
        'ADD COLUMN responded_by VARCHAR(100) NULL AFTER responded_at',
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

function get_next_quotation_number(PDO $pdo): string
{
    $maxNum = 0;

    try {
        $stmt = $pdo->query("SELECT quotation_number FROM case_quotations WHERE quotation_number IS NOT NULL AND quotation_number != ''");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $num = (string) ($row['quotation_number'] ?? '');
            if (preg_match('/^QUO\s+(\d+)/i', $num, $matches)) {
                $maxNum = max($maxNum, (int) $matches[1]);
            } elseif (preg_match('/^QUO-(\d+)/i', $num, $matches)) {
                $maxNum = max($maxNum, (int) $matches[1]);
            }
        }
    } catch (PDOException $e) {
        // default numbering
    }

    return 'QUO ' . str_pad((string) ($maxNum + 1), 3, '0', STR_PAD_LEFT);
}

function fetch_case_quotations(PDO $pdo, int $caseId): array
{
    $stmt = $pdo->prepare('SELECT * FROM case_quotations WHERE case_id = ? ORDER BY created_at DESC, id DESC');
    $stmt->execute([$caseId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function fetch_quotation_items(PDO $pdo, int $quotationId): array
{
    $stmt = $pdo->prepare('SELECT * FROM case_quotation_items WHERE quotation_id = ? ORDER BY sort_order ASC, id ASC');
    $stmt->execute([$quotationId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function quotation_status_options(): array
{
    return [
        'draft' => 'Draft',
        'sent' => 'Sent',
        'accepted' => 'Accepted',
        'rejected' => 'Rejected',
        'expired' => 'Expired',
    ];
}

function quotation_status_label(string $status): string
{
    $options = quotation_status_options();

    return $options[$status] ?? ucfirst($status);
}

function quotation_status_pill_class(string $status): string
{
    switch ($status) {
        case 'accepted':
            return 'case-status-pill--paid';
        case 'sent':
            return 'case-status-pill--scheduled';
        case 'rejected':
        case 'expired':
            return 'case-status-pill--pending';
        default:
            return 'case-status-pill--pending';
    }
}

/**
 * @param array<int, array{description:string, quantity:float, unit_price:float}> $items
 */
function save_case_quotation(PDO $pdo, int $caseId, array $data, array $items): int
{
    $subtotal = 0.0;
    $normalizedItems = [];

    foreach ($items as $index => $item) {
        $description = trim((string) ($item['description'] ?? ''));
        if ($description === '') {
            continue;
        }

        $quantity = max(0, (float) ($item['quantity'] ?? 1));
        if ($quantity <= 0) {
            $quantity = 1;
        }

        $unitPrice = max(0, (float) ($item['unit_price'] ?? 0));
        $lineTotal = round($quantity * $unitPrice, 2);
        $subtotal += $lineTotal;

        $normalizedItems[] = [
            'description' => $description,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'line_total' => $lineTotal,
            'sort_order' => (int) $index,
        ];
    }

    if (empty($normalizedItems)) {
        throw new InvalidArgumentException('Add at least one line item with a description.');
    }

    $taxRate = max(0, (float) ($data['tax_rate'] ?? 0));
    $subtotal = round($subtotal, 2);
    $taxAmount = round($subtotal * ($taxRate / 100), 2);
    $totalAmount = round($subtotal + $taxAmount, 2);

    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare('
            INSERT INTO case_quotations (
                case_id, quotation_number, title, status, valid_until, notes,
                subtotal, tax_rate, tax_amount, total_amount, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $caseId,
            (string) ($data['quotation_number'] ?? get_next_quotation_number($pdo)),
            trim((string) ($data['title'] ?? '')) ?: null,
            (string) ($data['status'] ?? 'draft'),
            !empty($data['valid_until']) ? (string) $data['valid_until'] : null,
            trim((string) ($data['notes'] ?? '')) ?: null,
            $subtotal,
            $taxRate,
            $taxAmount,
            $totalAmount,
            (string) ($data['created_by'] ?? 'Admin'),
        ]);

        $quotationId = (int) $pdo->lastInsertId();

        $itemStmt = $pdo->prepare('
            INSERT INTO case_quotation_items (quotation_id, description, quantity, unit_price, line_total, sort_order)
            VALUES (?, ?, ?, ?, ?, ?)
        ');

        foreach ($normalizedItems as $item) {
            $itemStmt->execute([
                $quotationId,
                $item['description'],
                $item['quantity'],
                $item['unit_price'],
                $item['line_total'],
                $item['sort_order'],
            ]);
        }

        $pdo->commit();

        return $quotationId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function delete_case_quotation(PDO $pdo, int $caseId, int $quotationId): bool
{
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare('DELETE FROM case_quotation_items WHERE quotation_id = ?');
        $stmt->execute([$quotationId]);

        $stmt = $pdo->prepare('DELETE FROM case_quotations WHERE id = ? AND case_id = ?');
        $stmt->execute([$quotationId, $caseId]);
        $deleted = $stmt->rowCount() > 0;

        if ($deleted) {
            remove_client_quotation_notifications($pdo, $quotationId);
        }

        $pdo->commit();

        return $deleted;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function parse_quotation_line_items_from_post(array $post): array
{
    $descriptions = isset($post['quotation_item_desc']) && is_array($post['quotation_item_desc'])
        ? $post['quotation_item_desc'] : [];
    $quantities = isset($post['quotation_item_qty']) && is_array($post['quotation_item_qty'])
        ? $post['quotation_item_qty'] : [];
    $prices = isset($post['quotation_item_price']) && is_array($post['quotation_item_price'])
        ? $post['quotation_item_price'] : [];

    $items = [];
    $count = max(count($descriptions), count($quantities), count($prices));

    for ($i = 0; $i < $count; $i++) {
        $items[] = [
            'description' => (string) ($descriptions[$i] ?? ''),
            'quantity' => (float) ($quantities[$i] ?? 1),
            'unit_price' => (float) ($prices[$i] ?? 0),
        ];
    }

    return $items;
}

function fetch_quotation_with_case(PDO $pdo, int $quotationId): ?array
{
    $stmt = $pdo->prepare('
        SELECT q.*, c.client_id, c.title AS case_title,
               CONCAT(cl.first_name, " ", cl.last_name) AS client_name,
               cl.email AS client_email
        FROM case_quotations q
        INNER JOIN cases c ON c.id = q.case_id
        LEFT JOIN clients cl ON cl.id = c.client_id
        WHERE q.id = ?
        LIMIT 1
    ');
    $stmt->execute([$quotationId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function fetch_client_quotations(PDO $pdo, int $clientId): array
{
    $stmt = $pdo->prepare('
        SELECT q.*, c.title AS case_title
        FROM case_quotations q
        INNER JOIN cases c ON c.id = q.case_id
        WHERE c.client_id = ?
          AND q.status <> "draft"
        ORDER BY q.created_at DESC, q.id DESC
    ');
    $stmt->execute([$clientId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function client_quotation_status_meta(string $status, ?string $validUntil): array
{
    $status = strtolower(trim($status));

    if ($status === 'accepted') {
        return ['label' => 'Accepted', 'pill' => 'ca-status-pill--done'];
    }
    if ($status === 'rejected') {
        return ['label' => 'Declined', 'pill' => 'ca-status-pill--declined'];
    }
    if ($status === 'expired' || ($validUntil && strtotime($validUntil) < strtotime('today'))) {
        return ['label' => 'Expired', 'pill' => 'ca-status-pill--declined'];
    }
    if ($status === 'sent') {
        return ['label' => 'Awaiting response', 'pill' => 'ca-status-pill--pending'];
    }

    return ['label' => ucfirst($status), 'pill' => 'ca-status-pill--muted'];
}

function notify_client_about_quotation(PDO $pdo, int $quotationId): void
{
    if ($quotationId <= 0) {
        return;
    }

    if (!function_exists('legalpro_client_create_notification')) {
        require_once __DIR__ . '/client-portal-features.php';
    }

    $quotation = fetch_quotation_with_case($pdo, $quotationId);
    if (!$quotation) {
        return;
    }

    $status = strtolower((string) ($quotation['status'] ?? 'draft'));
    if ($status === 'draft') {
        return;
    }

    $clientId = (int) ($quotation['client_id'] ?? 0);
    if ($clientId <= 0) {
        return;
    }

    $number = trim((string) ($quotation['quotation_number'] ?? ''));
    if ($number === '') {
        $number = 'QUO-' . str_pad((string) $quotationId, 4, '0', STR_PAD_LEFT);
    }

    $title = trim((string) ($quotation['title'] ?? ''));
    if ($title === '') {
        $title = 'Quotation';
    }

    $total = function_exists('formatCurrency')
        ? formatCurrency((float) ($quotation['total_amount'] ?? 0))
        : number_format((float) ($quotation['total_amount'] ?? 0), 2);

    $caseTitle = trim((string) ($quotation['case_title'] ?? ''));
    $body = $number . ' · ' . $title . ' — ' . $total;
    if ($caseTitle !== '') {
        $body .= ' · ' . $caseTitle;
    }

    $notificationTitle = 'Quotation update';
    if ($status === 'sent') {
        $notificationTitle = 'Quotation received';
    } elseif ($status === 'accepted') {
        $notificationTitle = 'Quotation accepted';
    } elseif ($status === 'rejected') {
        $notificationTitle = 'Quotation declined';
    } elseif ($status === 'expired') {
        $notificationTitle = 'Quotation expired';
    }

    legalpro_client_create_notification(
        $pdo,
        $clientId,
        'quotation',
        $notificationTitle,
        $body,
        'client-quotation-view.php?id=' . $quotationId,
        'clipboard-list',
        'quotation',
        $quotationId,
        (string) ($quotation['created_at'] ?? '')
    );
}

function remove_client_quotation_notifications(PDO $pdo, int $quotationId): void
{
    if ($quotationId <= 0) {
        return;
    }

    try {
        $stmt = $pdo->prepare("DELETE FROM client_notifications WHERE ref_type = 'quotation' AND ref_id = ?");
        $stmt->execute([$quotationId]);
    } catch (PDOException $e) {
        error_log('remove quotation notification: ' . $e->getMessage());
    }
}

function quotation_is_expired(array $quotation): bool
{
    $status = strtolower(trim((string) ($quotation['status'] ?? '')));
    if ($status === 'expired') {
        return true;
    }

    $validUntil = $quotation['valid_until'] ?? null;

    return $validUntil !== null && $validUntil !== '' && strtotime((string) $validUntil) < strtotime('today');
}

function quotation_can_client_respond(array $quotation): bool
{
    if (strtolower(trim((string) ($quotation['status'] ?? ''))) !== 'sent') {
        return false;
    }

    return !quotation_is_expired($quotation);
}

function quotation_get_next_invoice_number(PDO $pdo): string
{
    $maxNum = 0;

    try {
        $stmt = $pdo->query("SELECT invoice_number FROM invoices WHERE invoice_number IS NOT NULL AND invoice_number != ''");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $num = (string) ($row['invoice_number'] ?? '');
            if (preg_match('/^INV\s+(\d+)/i', $num, $matches)) {
                $maxNum = max($maxNum, (int) $matches[1]);
            } elseif (preg_match('/^Invoice\s+(\d+)/i', $num, $matches)) {
                $maxNum = max($maxNum, (int) $matches[1]);
            }
        }
    } catch (PDOException $e) {
        // default numbering
    }

    return 'INV ' . str_pad((string) ($maxNum + 1), 3, '0', STR_PAD_LEFT);
}

function create_invoice_from_quotation(PDO $pdo, array $quotation): int
{
    $existingInvoiceId = (int) ($quotation['invoice_id'] ?? 0);
    if ($existingInvoiceId > 0) {
        return $existingInvoiceId;
    }

    $clientId = (int) ($quotation['client_id'] ?? 0);
    $caseId = (int) ($quotation['case_id'] ?? 0);
    if ($clientId <= 0) {
        throw new InvalidArgumentException('Quotation has no linked client.');
    }

    $amount = (float) ($quotation['total_amount'] ?? 0);
    if ($amount <= 0) {
        throw new InvalidArgumentException('Quotation total must be greater than zero.');
    }

    $quoteNumber = trim((string) ($quotation['quotation_number'] ?? ''));
    if ($quoteNumber === '') {
        $quoteNumber = 'QUO-' . str_pad((string) ($quotation['id'] ?? '0'), 4, '0', STR_PAD_LEFT);
    }

    $notes = 'Generated from accepted quotation ' . $quoteNumber;
    $title = trim((string) ($quotation['title'] ?? ''));
    if ($title !== '') {
        $notes .= ' — ' . $title;
    }

    $invoiceNumber = quotation_get_next_invoice_number($pdo);
    $issueDate = date('Y-m-d');
    $dueDate = date('Y-m-d', strtotime('+14 days'));

    $stmt = $pdo->prepare('
        INSERT INTO invoices (invoice_number, client_id, case_id, amount, status, issue_date, due_date, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $invoiceNumber,
        $clientId,
        $caseId > 0 ? $caseId : null,
        $amount,
        'sent',
        $issueDate,
        $dueDate,
        $notes,
    ]);

    return (int) $pdo->lastInsertId();
}

function quotation_log_status_event(array $quotation, string $newStatus, string $actorLabel): void
{
    $caseId = (int) ($quotation['case_id'] ?? 0);
    if ($caseId <= 0) {
        return;
    }

    if (!class_exists('CaseEvents')) {
        require_once __DIR__ . '/case_events.php';
    }

    $number = trim((string) ($quotation['quotation_number'] ?? ''));
    if ($number === '') {
        $number = 'QUO-' . str_pad((string) ($quotation['id'] ?? '0'), 4, '0', STR_PAD_LEFT);
    }

    $description = $actorLabel . ' set quotation ' . $number . ' to ' . quotation_status_label($newStatus);
    CaseEvents::logEvent(
        $caseId,
        'quotation_' . $newStatus,
        $description,
        (string) ($quotation['status'] ?? ''),
        $newStatus
    );
}

/**
 * @return array{ok: bool, message: string, invoice_id?: int|null}
 */
function client_respond_to_quotation(PDO $pdo, int $clientId, int $quotationId, string $response): array
{
    $response = strtolower(trim($response));
    if (!in_array($response, ['accepted', 'rejected'], true)) {
        return ['ok' => false, 'message' => 'Invalid response.'];
    }

    $quotation = fetch_quotation_with_case($pdo, $quotationId);
    if (!$quotation || (int) ($quotation['client_id'] ?? 0) !== $clientId) {
        return ['ok' => false, 'message' => 'Quotation not found.'];
    }

    if (quotation_is_expired($quotation)) {
        $pdo->prepare('UPDATE case_quotations SET status = ? WHERE id = ? AND status = ?')
            ->execute(['expired', $quotationId, 'sent']);

        return ['ok' => false, 'message' => 'This quotation has expired and can no longer be accepted.'];
    }

    if (!quotation_can_client_respond($quotation)) {
        return ['ok' => false, 'message' => 'This quotation can no longer be responded to.'];
    }

    $pdo->beginTransaction();

    try {
        $invoiceId = null;

        if ($response === 'accepted') {
            $invoiceId = create_invoice_from_quotation($pdo, $quotation);
            $stmt = $pdo->prepare('
                UPDATE case_quotations
                SET status = ?, invoice_id = ?, responded_at = NOW(), responded_by = ?
                WHERE id = ?
            ');
            $stmt->execute(['accepted', $invoiceId, 'Client', $quotationId]);
        } else {
            $stmt = $pdo->prepare('
                UPDATE case_quotations
                SET status = ?, responded_at = NOW(), responded_by = ?
                WHERE id = ?
            ');
            $stmt->execute(['rejected', 'Client', $quotationId]);
        }

        $pdo->commit();

        notify_client_about_quotation($pdo, $quotationId);
        quotation_log_status_event($quotation, $response, 'Client');

        return [
            'ok' => true,
            'message' => $response === 'accepted'
                ? 'Quotation accepted. An invoice has been created and is now available under Invoices.'
                : 'Quotation declined. Your firm has been notified.',
            'invoice_id' => $invoiceId,
        ];
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('client_respond_to_quotation: ' . $e->getMessage());

        return ['ok' => false, 'message' => 'Could not save your response. Please try again.'];
    }
}

/**
 * @return array{ok: bool, message: string, invoice_id?: int|null}
 */
function admin_update_case_quotation_status(PDO $pdo, int $caseId, int $quotationId, string $status): array
{
    $status = strtolower(trim($status));
    $options = quotation_status_options();
    if (!isset($options[$status])) {
        return ['ok' => false, 'message' => 'Invalid quotation status selected.'];
    }

    $quotation = fetch_quotation_with_case($pdo, $quotationId);
    if (!$quotation || (int) ($quotation['case_id'] ?? 0) !== $caseId) {
        return ['ok' => false, 'message' => 'Quotation not found for this case.'];
    }

    $oldStatus = strtolower(trim((string) ($quotation['status'] ?? '')));
    if ($oldStatus === $status) {
        return ['ok' => true, 'message' => 'Quotation status is already ' . quotation_status_label($status) . '.'];
    }

    $pdo->beginTransaction();

    try {
        $invoiceId = (int) ($quotation['invoice_id'] ?? 0);

        if ($status === 'accepted' && $invoiceId <= 0) {
            $invoiceId = create_invoice_from_quotation($pdo, $quotation);
        }

        $stmt = $pdo->prepare('
            UPDATE case_quotations
            SET status = ?,
                invoice_id = CASE WHEN ? > 0 THEN ? ELSE invoice_id END,
                responded_at = CASE
                    WHEN ? IN ("accepted", "rejected") AND responded_at IS NULL THEN NOW()
                    ELSE responded_at
                END,
                responded_by = CASE
                    WHEN ? IN ("accepted", "rejected") AND responded_by IS NULL THEN ?
                    ELSE responded_by
                END
            WHERE id = ? AND case_id = ?
        ');
        $stmt->execute([
            $status,
            $invoiceId,
            $invoiceId,
            $status,
            $status,
            'Admin',
            $quotationId,
            $caseId,
        ]);

        $pdo->commit();

        if ($status === 'sent' && $oldStatus === 'draft') {
            notify_client_about_quotation($pdo, $quotationId);
        } elseif (in_array($status, ['accepted', 'rejected', 'expired', 'sent'], true)) {
            notify_client_about_quotation($pdo, $quotationId);
        }

        if ($oldStatus !== $status) {
            quotation_log_status_event($quotation, $status, 'Admin');
        }

        $message = 'Quotation status updated to ' . quotation_status_label($status) . '.';
        if ($status === 'accepted' && $invoiceId > 0) {
            $message .= ' Invoice created.';
        }

        return ['ok' => true, 'message' => $message, 'invoice_id' => $invoiceId > 0 ? $invoiceId : null];
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('admin_update_case_quotation_status: ' . $e->getMessage());

        return ['ok' => false, 'message' => 'Error updating quotation status.'];
    }
}
