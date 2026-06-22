<?php
/**
 * Client portal: activity feed, notifications, documents, case progress.
 */

if (function_exists('legalpro_client_portal_ensure_tables')) {
    return;
}

function legalpro_client_portal_ensure_tables(?PDO $pdo = null): void
{
    global $pdo;
    $db = $pdo ?? $GLOBALS['pdo'] ?? null;
    if (!$db instanceof PDO) {
        return;
    }

    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS `client_notifications` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `client_id` INT NOT NULL,
              `type` VARCHAR(50) NOT NULL,
              `title` VARCHAR(255) NOT NULL,
              `body` TEXT,
              `link_url` VARCHAR(500) DEFAULT NULL,
              `icon` VARCHAR(50) DEFAULT 'bell',
              `is_read` TINYINT(1) NOT NULL DEFAULT 0,
              `ref_type` VARCHAR(50) DEFAULT NULL,
              `ref_id` INT DEFAULT NULL,
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              UNIQUE KEY `uniq_client_ref` (`client_id`, `type`, `ref_type`, `ref_id`),
              KEY `idx_client_unread` (`client_id`, `is_read`, `created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS `client_document_acknowledgments` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `client_id` INT NOT NULL,
              `document_id` INT NOT NULL,
              `acknowledged_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              UNIQUE KEY `uniq_client_doc` (`client_id`, `document_id`),
              FOREIGN KEY (`document_id`) REFERENCES `documents`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (PDOException $e) {
        error_log('client portal tables: ' . $e->getMessage());
    }
}

function clientDocumentsVisitSettingKey(int $clientId): string
{
    return 'client_documents_last_visit_' . max(0, $clientId);
}

function clientEmailDigestSettingKey(int $clientId): string
{
    return 'client_email_digest_' . max(0, $clientId);
}

function getClientDocumentsLastVisit(int $clientId): ?string
{
    $v = getSetting(clientDocumentsVisitSettingKey($clientId), '');
    return $v !== '' ? (string) $v : null;
}

function markClientDocumentsVisited(int $clientId): void
{
    setSetting(clientDocumentsVisitSettingKey($clientId), date('Y-m-d H:i:s'));
}

function getClientEmailDigest(int $clientId): string
{
    $v = strtolower(trim((string) getSetting(clientEmailDigestSettingKey($clientId), 'none')));
    return in_array($v, ['none', 'daily', 'weekly'], true) ? $v : 'none';
}

function saveClientEmailDigest(int $clientId, string $digest): array
{
    $digest = strtolower(trim($digest));
    if (!in_array($digest, ['none', 'daily', 'weekly'], true)) {
        return ['ok' => false, 'message' => 'Invalid digest option.'];
    }
    setSetting(clientEmailDigestSettingKey($clientId), $digest);
    return ['ok' => true];
}

function legalpro_client_settings_snapshot(?PDO $pdo, int $clientId): array
{
    $empty = [
        'display_name' => '',
        'email' => '',
        'phone' => '',
        'member_since' => '',
        'total_cases' => 0,
        'open_cases' => 0,
        'unread_notifications' => 0,
        'new_documents' => 0,
        'upcoming_appointments' => 0,
        'outstanding_balance' => 0.0,
        'theme_mode' => 'light',
        'locale' => 'en',
        'email_digest' => 'none',
    ];

    if ($pdo === null || $clientId <= 0) {
        return $empty;
    }

    $snapshot = $empty;

    try {
        $stmt = $pdo->prepare('SELECT first_name, last_name, email, phone, created_at FROM clients WHERE id = ? LIMIT 1');
        $stmt->execute([$clientId]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($client) {
            $snapshot['display_name'] = trim(($client['first_name'] ?? '') . ' ' . ($client['last_name'] ?? ''));
            $snapshot['email'] = (string) ($client['email'] ?? '');
            $snapshot['phone'] = (string) ($client['phone'] ?? '');
            if (!empty($client['created_at'])) {
                $snapshot['member_since'] = date('M j, Y', strtotime($client['created_at']));
            }
        }

        $stmt = $pdo->prepare("
            SELECT
                COUNT(*) AS total_cases,
                SUM(CASE WHEN LOWER(status) NOT IN ('closed', 'resolved') THEN 1 ELSE 0 END) AS open_cases
            FROM cases
            WHERE client_id = ?
        ");
        $stmt->execute([$clientId]);
        $caseRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $snapshot['total_cases'] = (int) ($caseRow['total_cases'] ?? 0);
        $snapshot['open_cases'] = (int) ($caseRow['open_cases'] ?? 0);

        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT a.id)
            FROM appointments a
            WHERE a.client_id = ? AND a.starts_at > NOW()
              AND LOWER(COALESCE(a.status, '')) IN ('accepted', 'pending')
        ");
        $stmt->execute([$clientId]);
        $snapshot['upcoming_appointments'] = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(i.amount - COALESCE(paid.paid_amount, 0)), 0) AS outstanding
            FROM invoices i
            LEFT JOIN (
                SELECT invoice_id, SUM(amount) AS paid_amount
                FROM payments
                GROUP BY invoice_id
            ) paid ON paid.invoice_id = i.id
            WHERE i.client_id = ?
        ");
        $stmt->execute([$clientId]);
        $snapshot['outstanding_balance'] = max(0.0, (float) $stmt->fetchColumn());
    } catch (PDOException $e) {
        error_log('client settings snapshot: ' . $e->getMessage());
    }

    $snapshot['unread_notifications'] = legalpro_client_notification_count_unread($pdo, $clientId);
    $snapshot['new_documents'] = legalpro_client_count_new_documents($pdo, $clientId);
    $snapshot['theme_mode'] = function_exists('getClientPortalThemeMode') ? getClientPortalThemeMode($clientId) : 'light';
    $snapshot['locale'] = function_exists('getClientPortalLocale') ? getClientPortalLocale($clientId) : 'en';
    $snapshot['email_digest'] = getClientEmailDigest($clientId);

    return $snapshot;
}

function legalpro_client_count_new_documents(?PDO $pdo, int $clientId): int
{
    if ($pdo === null || $clientId <= 0) {
        return 0;
    }

    legalpro_client_portal_ensure_tables($pdo);
    $lastVisit = getClientDocumentsLastVisit($clientId);

    try {
        if ($lastVisit) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM documents d
                INNER JOIN cases c ON c.id = d.case_id
                WHERE c.client_id = ? AND d.uploaded_at > ?
            ");
            $stmt->execute([$clientId, $lastVisit]);
        } else {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM documents d
                INNER JOIN cases c ON c.id = d.case_id
                WHERE c.client_id = ?
            ");
            $stmt->execute([$clientId]);
        }
        return (int) $stmt->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

function legalpro_client_document_file_urls(array $doc): array
{
    $filepath = '../' . ltrim((string) ($doc['filepath'] ?? ''), '/');
    $filename = (string) ($doc['filename'] ?? 'document');
    $label = (string) ($doc['label'] ?: $filename);

    return [
        'view_url' => $filepath,
        'download_url' => $filepath,
        'download_filename' => $filename,
        'display_label' => $label,
        'is_finance_pdf' => false,
    ];
}

function legalpro_client_normalize_finance_reference(string $value): string
{
    return strtoupper(preg_replace('/[^A-Z0-9]/', '', $value));
}

function legalpro_client_lookup_invoice_for_document(
    PDO $pdo,
    int $clientId,
    int $caseId,
    string $invoiceReference
): ?array {
    $normalized = legalpro_client_normalize_finance_reference($invoiceReference);
    if ($normalized === '') {
        return null;
    }

    $stmt = $pdo->prepare('SELECT id, invoice_number, case_id FROM invoices WHERE client_id = ?');
    $stmt->execute([$clientId]);
    $best = null;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $invoice) {
        if (legalpro_client_normalize_finance_reference((string) $invoice['invoice_number']) !== $normalized) {
            continue;
        }
        if ($caseId > 0 && (int) $invoice['case_id'] === $caseId) {
            return $invoice;
        }
        if ($best === null) {
            $best = $invoice;
        }
    }

    return $best;
}

/**
 * Map legacy uploaded invoice/receipt/quotation HTML files to live PDF endpoints.
 */
function legalpro_client_resolve_finance_document_urls(?PDO $pdo, int $clientId, array $doc): array
{
    $defaults = legalpro_client_document_file_urls($doc);
    if ($pdo === null || $clientId <= 0) {
        return $defaults;
    }

    $filename = (string) ($doc['filename'] ?? '');
    $label = (string) ($doc['label'] ?? '');
    $caseId = (int) ($doc['case_id'] ?? 0);
    $probe = $filename . ' ' . $label;

    if (preg_match('/invoice[_\s-]+([a-z0-9\-]+)(?:\.html?)?/i', $probe, $m)) {
        $invoiceNumber = $m[1];
        try {
            $invoice = legalpro_client_lookup_invoice_for_document($pdo, $clientId, $caseId, $invoiceNumber);
            if ($invoice) {
                $number = (string) ($invoice['invoice_number'] ?: $invoiceNumber);
                $safe = preg_replace('/[^A-Za-z0-9_\-]/', '', $number) ?: 'invoice';

                return [
                    'view_url' => 'invoice-download.php?id=' . (int) $invoice['id'] . '&view=1',
                    'download_url' => 'invoice-download.php?id=' . (int) $invoice['id'],
                    'download_filename' => 'invoice-' . $safe . '.pdf',
                    'display_label' => preg_replace('/\.html?$/i', '.pdf', $label) ?: ('Invoice ' . $number),
                    'is_finance_pdf' => true,
                ];
            }
        } catch (PDOException $e) {
            // Keep static file fallback.
        }
    }

    if (preg_match('/(?:receipt|payment)[_\s-]+(?:rc[_\s-]*)?(\d+)/i', $probe, $m)) {
        $paymentId = (int) $m[1];
        if ($paymentId > 0) {
            try {
                $stmt = $pdo->prepare('SELECT id FROM payments WHERE id = ? AND client_id = ? LIMIT 1');
                $stmt->execute([$paymentId, $clientId]);
                $payment = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($payment) {
                    $safe = 'RC-' . str_pad((string) $paymentId, 6, '0', STR_PAD_LEFT);

                    return [
                        'view_url' => 'payment-receipt.php?id=' . $paymentId . '&view=1',
                        'download_url' => 'payment-receipt.php?id=' . $paymentId,
                        'download_filename' => 'receipt-' . $safe . '.pdf',
                        'display_label' => preg_replace('/\.html?$/i', '.pdf', $label) ?: ('Receipt ' . $safe),
                        'is_finance_pdf' => true,
                    ];
                }
            } catch (PDOException $e) {
                // Keep static file fallback.
            }
        }
    }

    if (preg_match('/quotation[_\s-]+([a-z0-9\-]+)/i', $probe, $m)) {
        $quoteNumber = $m[1];
        $normalizedQuote = legalpro_client_normalize_finance_reference($quoteNumber);
        try {
            $stmt = $pdo->prepare('
                SELECT q.id, q.quotation_number, q.case_id
                FROM case_quotations q
                INNER JOIN cases c ON c.id = q.case_id
                WHERE c.client_id = ?
            ');
            $stmt->execute([$clientId]);
            $best = null;
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $quote) {
                if (legalpro_client_normalize_finance_reference((string) $quote['quotation_number']) !== $normalizedQuote) {
                    continue;
                }
                if ($caseId > 0 && (int) $quote['case_id'] === $caseId) {
                    $best = $quote;
                    break;
                }
                if ($best === null) {
                    $best = $quote;
                }
            }
            if ($best) {
                $number = (string) ($best['quotation_number'] ?: $quoteNumber);
                $safe = preg_replace('/[^A-Za-z0-9_\-]/', '', $number) ?: 'quotation';

                return [
                    'view_url' => 'client-quotation-view.php?id=' . (int) $best['id'] . '&view=1',
                    'download_url' => 'client-quotation-view.php?id=' . (int) $best['id'],
                    'download_filename' => 'quotation-' . $safe . '.pdf',
                    'display_label' => preg_replace('/\.html?$/i', '.pdf', $label) ?: ('Quotation ' . $number),
                    'is_finance_pdf' => true,
                ];
            }
        } catch (PDOException $e) {
            // Keep static file fallback.
        }
    }

    return $defaults;
}

function legalpro_client_get_documents(?PDO $pdo, int $clientId, ?int $caseId = null, string $search = ''): array
{
    if ($pdo === null || $clientId <= 0) {
        return [];
    }

    legalpro_client_portal_ensure_tables($pdo);
    $lastVisit = getClientDocumentsLastVisit($clientId);
    $params = [$clientId];
    $where = 'c.client_id = ?';

    if ($caseId !== null && $caseId > 0) {
        $where .= ' AND c.id = ?';
        $params[] = $caseId;
    }

    if ($search !== '') {
        $where .= ' AND (d.filename LIKE ? OR d.label LIKE ? OR c.title LIKE ?)';
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT d.*, c.title AS case_title, c.id AS case_id
            FROM documents d
            INNER JOIN cases c ON c.id = d.case_id
            WHERE {$where}
            ORDER BY d.uploaded_at DESC
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $ackIds = [];
        if (!empty($rows)) {
            $docIds = array_map(static fn ($r) => (int) $r['id'], $rows);
            $placeholders = implode(',', array_fill(0, count($docIds), '?'));
            $ackStmt = $pdo->prepare("
                SELECT document_id FROM client_document_acknowledgments
                WHERE client_id = ? AND document_id IN ({$placeholders})
            ");
            $ackStmt->execute(array_merge([$clientId], $docIds));
            $ackIds = array_map('intval', $ackStmt->fetchAll(PDO::FETCH_COLUMN));
        }

        foreach ($rows as &$row) {
            $row['is_new'] = $lastVisit === null || strtotime((string) $row['uploaded_at']) > strtotime($lastVisit);
            $row['is_acknowledged'] = in_array((int) $row['id'], $ackIds, true);
            $row['needs_ack'] = !$row['is_acknowledged']
                && stripos((string) ($row['uploaded_by'] ?? ''), 'client') === false;
            $row = array_merge($row, legalpro_client_resolve_finance_document_urls($pdo, $clientId, $row));
        }
        unset($row);

        return $rows;
    } catch (PDOException $e) {
        return [];
    }
}

function legalpro_client_acknowledge_document(?PDO $pdo, int $clientId, int $documentId): bool
{
    if ($pdo === null || $clientId <= 0 || $documentId <= 0) {
        return false;
    }

    legalpro_client_portal_ensure_tables($pdo);

    try {
        $check = $pdo->prepare("
            SELECT d.id FROM documents d
            INNER JOIN cases c ON c.id = d.case_id
            WHERE d.id = ? AND c.client_id = ?
        ");
        $check->execute([$documentId, $clientId]);
        if (!$check->fetch()) {
            return false;
        }

        $stmt = $pdo->prepare("
            INSERT IGNORE INTO client_document_acknowledgments (client_id, document_id)
            VALUES (?, ?)
        ");
        return $stmt->execute([$clientId, $documentId]);
    } catch (PDOException $e) {
        return false;
    }
}

function legalpro_client_resolve_notification_link(array $notification): string
{
    $link = trim((string) ($notification['link_url'] ?? ''));
    $refType = strtolower(trim((string) ($notification['ref_type'] ?? '')));
    $refId = (int) ($notification['ref_id'] ?? 0);

    if ($refType === 'quotation' && $refId > 0) {
        if (function_exists('client_quotation_notification_link')) {
            return client_quotation_notification_link($refId);
        }

        return 'client-payments.php?quote=' . $refId . '#quotations';
    }

    if ($link !== '' && preg_match('#client-quotation-view\.php#i', $link)) {
        if (preg_match('/[?&]id=(\d+)/', $link, $matches)) {
            return 'client-payments.php?quote=' . (int) $matches[1] . '#quotations';
        }

        return 'client-payments.php#quotations';
    }

    return $link !== '' ? $link : '#';
}

function legalpro_client_create_notification(
    ?PDO $pdo,
    int $clientId,
    string $type,
    string $title,
    string $body,
    string $linkUrl,
    string $icon = 'bell',
    ?string $refType = null,
    ?int $refId = null,
    ?string $eventAt = null
): void {
    if ($pdo === null || $clientId <= 0) {
        return;
    }

    legalpro_client_portal_ensure_tables($pdo);

    $createdAt = legalpro_client_notification_normalize_datetime($eventAt);

    try {
        $stmt = $pdo->prepare("
            INSERT INTO client_notifications
            (client_id, type, title, body, link_url, icon, ref_type, ref_id, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                title = VALUES(title),
                body = VALUES(body),
                link_url = VALUES(link_url),
                icon = VALUES(icon)
        ");
        $stmt->execute([$clientId, $type, $title, $body, $linkUrl, $icon, $refType, $refId, $createdAt]);
    } catch (PDOException $e) {
        error_log('client notification: ' . $e->getMessage());
    }
}

function legalpro_client_notification_normalize_datetime(?string $value): string
{
    if ($value !== null && trim($value) !== '') {
        $ts = strtotime($value);
        if ($ts !== false) {
            return date('Y-m-d H:i:s', $ts);
        }
    }

    return date('Y-m-d H:i:s');
}

/**
 * @return array{label: string, ago: string, iso: string}
 */
function legalpro_client_notification_time_parts(string $datetime): array
{
    $ts = strtotime($datetime);
    if ($ts === false) {
        return ['label' => '', 'ago' => '', 'iso' => ''];
    }

    $iso = date('c', $ts);
    $now = time();
    $diff = $now - $ts;
    $todayStart = strtotime('today');
    $yesterdayStart = strtotime('yesterday');

    if ($diff < 45) {
        $ago = 'Just now';
    } elseif ($diff < 3600) {
        $ago = (int) floor($diff / 60) . 'm ago';
    } elseif ($ts >= $todayStart) {
        $ago = date('g:i A', $ts);
    } elseif ($ts >= $yesterdayStart) {
        $ago = 'Yesterday ' . date('g:i A', $ts);
    } elseif ($diff < 604800) {
        $ago = (int) floor($diff / 86400) . 'd ago';
    } else {
        $ago = date('M j', $ts);
    }

    $label = date('M j, Y g:i A', $ts);

    return ['label' => $label, 'ago' => $ago, 'iso' => $iso];
}

function legalpro_client_sync_notifications(?PDO $pdo, int $clientId): void
{
    if ($pdo === null || $clientId <= 0) {
        return;
    }

    legalpro_client_portal_ensure_tables($pdo);

    try {
        $stmt = $pdo->prepare("
            SELECT d.id, d.label, d.filename, d.case_id, d.uploaded_at, c.title AS case_title
            FROM documents d
            INNER JOIN cases c ON c.id = d.case_id
            WHERE c.client_id = ?
              AND d.uploaded_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
              AND (d.uploaded_by IS NULL OR d.uploaded_by NOT LIKE '%client%')
            ORDER BY d.uploaded_at DESC
            LIMIT 40
        ");
        $stmt->execute([$clientId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $doc) {
            $label = $doc['label'] ?: $doc['filename'];
            legalpro_client_create_notification(
                $pdo,
                $clientId,
                'document',
                'New document uploaded',
                $label . ' — ' . $doc['case_title'],
                'client-documents.php?case_id=' . (int) $doc['case_id'],
                'file-text',
                'document',
                (int) $doc['id'],
                (string) ($doc['uploaded_at'] ?? '')
            );
        }

        $stmt = $pdo->prepare("
            SELECT a.id, a.starts_at, a.status, a.updated_at, a.created_at, c.title AS case_title, a.case_id
            FROM appointments a
            LEFT JOIN cases c ON c.id = a.case_id
            WHERE a.client_id = ?
              AND COALESCE(a.updated_at, a.created_at) >= DATE_SUB(NOW(), INTERVAL 60 DAY)
            ORDER BY COALESCE(a.updated_at, a.created_at) DESC
            LIMIT 30
        ");
        $stmt->execute([$clientId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $apt) {
            $status = strtolower((string) ($apt['status'] ?? ''));
            $eventAt = (string) ($apt['updated_at'] ?? $apt['created_at'] ?? $apt['starts_at'] ?? '');
            if ($status === 'accepted') {
                $dt = date('M j, g:i A', strtotime((string) $apt['starts_at']));
                legalpro_client_create_notification(
                    $pdo,
                    $clientId,
                    'appointment_confirmed',
                    'Appointment confirmed',
                    $dt . ' · ' . ($apt['case_title'] ?: 'General'),
                    'client-appointments.php',
                    'calendar-check',
                    'appointment',
                    (int) $apt['id'],
                    $eventAt
                );
            } elseif ($status === 'pending') {
                legalpro_client_create_notification(
                    $pdo,
                    $clientId,
                    'appointment_pending',
                    'Appointment awaiting confirmation',
                    $apt['case_title'] ?: 'Your request is pending review',
                    'client-appointments.php',
                    'calendar-clock',
                    'appointment',
                    (int) $apt['id'],
                    $eventAt
                );
            }
        }

        $stmt = $pdo->prepare("
            SELECT i.id, i.invoice_number, i.amount, i.case_id, i.created_at, i.issue_date, c.title AS case_title
            FROM invoices i
            LEFT JOIN cases c ON c.id = i.case_id
            WHERE i.client_id = ?
              AND COALESCE(i.issue_date, i.created_at) >= DATE_SUB(NOW(), INTERVAL 90 DAY)
            ORDER BY COALESCE(i.issue_date, i.created_at) DESC
            LIMIT 30
        ");
        $stmt->execute([$clientId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $inv) {
            $eventAt = (string) ($inv['issue_date'] ?? $inv['created_at'] ?? '');
            legalpro_client_create_notification(
                $pdo,
                $clientId,
                'invoice',
                'Invoice issued',
                ($inv['invoice_number'] ?: 'Invoice') . ' — $' . number_format((float) $inv['amount'], 2),
                'client-payments.php',
                'receipt',
                'invoice',
                (int) $inv['id'],
                $eventAt
            );
        }

        $stmt = $pdo->prepare("
            SELECT p.id, p.amount, p.payment_date, p.created_at, p.case_id, c.title AS case_title
            FROM payments p
            LEFT JOIN cases c ON c.id = p.case_id
            WHERE p.client_id = ?
              AND COALESCE(p.payment_date, p.created_at) >= DATE_SUB(NOW(), INTERVAL 90 DAY)
            ORDER BY COALESCE(p.payment_date, p.created_at) DESC
            LIMIT 25
        ");
        $stmt->execute([$clientId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $payment) {
            $eventAt = (string) ($payment['payment_date'] ?? $payment['created_at'] ?? '');
            legalpro_client_create_notification(
                $pdo,
                $clientId,
                'payment',
                'Payment recorded',
                formatCurrency((float) $payment['amount']) . ' — ' . ($payment['case_title'] ?: 'Account'),
                'client-payments.php',
                'credit-card',
                'payment',
                (int) $payment['id'],
                $eventAt
            );
        }

        $stmt = $pdo->prepare("
            SELECT cd.id, cd.court_date, cd.title, cd.created_at, cd.case_id, c.title AS case_title
            FROM court_dates cd
            INNER JOIN cases c ON c.id = cd.case_id
            WHERE c.client_id = ?
              AND cd.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
            ORDER BY cd.created_at DESC
            LIMIT 30
        ");
        $stmt->execute([$clientId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $court) {
            $dt = date('M j, Y g:i A', strtotime((string) $court['court_date']));
            legalpro_client_create_notification(
                $pdo,
                $clientId,
                'court_date',
                'Hearing scheduled',
                ($court['title'] ?: 'Court date') . ' · ' . $dt . ' — ' . $court['case_title'],
                'client-court-tracking.php',
                'landmark',
                'court_date',
                (int) $court['id'],
                (string) ($court['created_at'] ?? '')
            );
        }
    } catch (PDOException $e) {
        error_log('client sync notifications: ' . $e->getMessage());
    }

    legalpro_client_repair_notification_timestamps($pdo, $clientId);
}

function legalpro_client_repair_notification_timestamps(?PDO $pdo, int $clientId): void
{
    if ($pdo === null || $clientId <= 0) {
        return;
    }

    $repairs = [
        "
            UPDATE client_notifications n
            INNER JOIN documents d ON n.ref_type = 'document' AND n.ref_id = d.id
            INNER JOIN cases c ON c.id = d.case_id AND c.client_id = n.client_id
            SET n.created_at = d.uploaded_at
            WHERE n.client_id = ? AND d.uploaded_at IS NOT NULL
        ",
        "
            UPDATE client_notifications n
            INNER JOIN appointments a ON n.ref_type = 'appointment' AND n.ref_id = a.id
            SET n.created_at = COALESCE(a.updated_at, a.created_at, a.starts_at)
            WHERE n.client_id = ? AND a.client_id = n.client_id
        ",
        "
            UPDATE client_notifications n
            INNER JOIN invoices i ON n.ref_type = 'invoice' AND n.ref_id = i.id
            SET n.created_at = COALESCE(i.issue_date, i.created_at)
            WHERE n.client_id = ? AND i.client_id = n.client_id
        ",
        "
            UPDATE client_notifications n
            INNER JOIN court_dates cd ON n.ref_type = 'court_date' AND n.ref_id = cd.id
            INNER JOIN cases c ON c.id = cd.case_id AND c.client_id = n.client_id
            SET n.created_at = cd.created_at
            WHERE n.client_id = ? AND cd.created_at IS NOT NULL
        ",
        "
            UPDATE client_notifications n
            INNER JOIN payments p ON n.ref_type = 'payment' AND n.ref_id = p.id
            SET n.created_at = COALESCE(p.payment_date, p.created_at)
            WHERE n.client_id = ? AND p.client_id = n.client_id
        ",
    ];

    try {
        foreach ($repairs as $sql) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$clientId]);
        }
    } catch (PDOException $e) {
        error_log('repair notification timestamps: ' . $e->getMessage());
    }
}

function legalpro_client_notification_count_unread(?PDO $pdo, ?int $clientId): int
{
    if ($pdo === null || $clientId === null || $clientId <= 0) {
        return 0;
    }

    legalpro_client_portal_ensure_tables($pdo);
    legalpro_client_sync_notifications($pdo, $clientId);

    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM client_notifications WHERE client_id = ? AND is_read = 0");
        $stmt->execute([$clientId]);
        return (int) $stmt->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

function legalpro_client_get_notifications(?PDO $pdo, int $clientId, int $limit = 40): array
{
    if ($pdo === null || $clientId <= 0) {
        return [];
    }

    legalpro_client_portal_ensure_tables($pdo);
    legalpro_client_sync_notifications($pdo, $clientId);

    try {
        $stmt = $pdo->prepare("
            SELECT * FROM client_notifications
            WHERE client_id = ?
            ORDER BY created_at DESC
            LIMIT ?
        ");
        $stmt->bindValue(1, $clientId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

function legalpro_client_mark_notification_read(?PDO $pdo, int $clientId, int $notificationId): bool
{
    if ($pdo === null || $clientId <= 0) {
        return false;
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE client_notifications SET is_read = 1
            WHERE id = ? AND client_id = ?
        ");
        return $stmt->execute([$notificationId, $clientId]);
    } catch (PDOException $e) {
        return false;
    }
}

function legalpro_client_mark_all_notifications_read(?PDO $pdo, int $clientId): bool
{
    if ($pdo === null || $clientId <= 0) {
        return false;
    }

    try {
        $stmt = $pdo->prepare("UPDATE client_notifications SET is_read = 1 WHERE client_id = ?");
        return $stmt->execute([$clientId]);
    } catch (PDOException $e) {
        return false;
    }
}

function legalpro_client_get_activity_feed(?PDO $pdo, int $clientId, int $limit = 30): array
{
    if ($pdo === null || $clientId <= 0) {
        return [];
    }

    $items = [];

    try {
        $stmt = $pdo->prepare("
            SELECT d.id, d.label, d.filename, d.uploaded_by, d.uploaded_at, c.id AS case_id, c.title AS case_title
            FROM documents d
            INNER JOIN cases c ON c.id = d.case_id
            WHERE c.client_id = ?
            ORDER BY d.uploaded_at DESC
            LIMIT 20
        ");
        $stmt->execute([$clientId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $by = (string) ($row['uploaded_by'] ?? '');
            $isClient = stripos($by, 'client') !== false;
            $items[] = [
                'type' => 'document',
                'icon' => 'file-text',
                'title' => $isClient ? 'You uploaded a document' : 'Lawyer uploaded a document',
                'subtitle' => ($row['label'] ?: $row['filename']) . ' · ' . $row['case_title'],
                'ts' => strtotime((string) $row['uploaded_at']),
                'url' => 'client-documents.php?case_id=' . (int) $row['case_id'],
            ];
        }

        $stmt = $pdo->prepare("
            SELECT a.id, a.starts_at, a.status, a.updated_at, c.title AS case_title, c.id AS case_id
            FROM appointments a
            LEFT JOIN cases c ON c.id = a.case_id
            WHERE a.client_id = ?
            ORDER BY COALESCE(a.updated_at, a.starts_at) DESC
            LIMIT 20
        ");
        $stmt->execute([$clientId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $status = strtolower((string) ($row['status'] ?? ''));
            if ($status === 'accepted') {
                $title = 'Appointment confirmed';
            } elseif ($status === 'pending') {
                $title = 'Appointment requested';
            } else {
                $title = 'Appointment updated';
            }
            $items[] = [
                'type' => 'appointment',
                'icon' => 'calendar',
                'title' => $title,
                'subtitle' => date('M j, g:i A', strtotime((string) $row['starts_at'])) . ' · ' . ($row['case_title'] ?: 'General'),
                'ts' => strtotime((string) ($row['updated_at'] ?: $row['starts_at'])),
                'url' => 'client-appointments.php',
            ];
        }

        $stmt = $pdo->prepare("
            SELECT i.id, i.invoice_number, i.amount, i.created_at, c.title AS case_title
            FROM invoices i
            LEFT JOIN cases c ON c.id = i.case_id
            WHERE i.client_id = ?
            ORDER BY i.created_at DESC
            LIMIT 15
        ");
        $stmt->execute([$clientId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = [
                'type' => 'invoice',
                'icon' => 'receipt',
                'title' => 'Invoice issued',
                'subtitle' => ($row['invoice_number'] ?: 'Invoice') . ' — $' . number_format((float) $row['amount'], 2),
                'ts' => strtotime((string) $row['created_at']),
                'url' => 'client-payments.php',
            ];
        }

        $stmt = $pdo->prepare("
            SELECT cd.id, cd.court_date, cd.title, cd.created_at, c.title AS case_title, c.id AS case_id
            FROM court_dates cd
            INNER JOIN cases c ON c.id = cd.case_id
            WHERE c.client_id = ?
            ORDER BY cd.created_at DESC
            LIMIT 15
        ");
        $stmt->execute([$clientId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = [
                'type' => 'court',
                'icon' => 'landmark',
                'title' => 'Hearing scheduled',
                'subtitle' => ($row['title'] ?: 'Court date') . ' · ' . date('M j, Y', strtotime((string) $row['court_date'])) . ' — ' . $row['case_title'],
                'ts' => strtotime((string) $row['created_at']),
                'url' => 'client-court-tracking.php',
            ];
        }

        $stmt = $pdo->prepare("
            SELECT p.id, p.amount, p.payment_date, p.created_at, c.title AS case_title, c.id AS case_id
            FROM payments p
            LEFT JOIN cases c ON c.id = p.case_id
            WHERE p.client_id = ?
            ORDER BY COALESCE(p.payment_date, p.created_at) DESC
            LIMIT 15
        ");
        $stmt->execute([$clientId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $ts = strtotime((string) ($row['payment_date'] ?? $row['created_at']));
            $items[] = [
                'type' => 'payment',
                'icon' => 'credit-card',
                'title' => 'Payment recorded',
                'subtitle' => formatCurrency((float) $row['amount']) . ' · ' . ($row['case_title'] ?: 'Account'),
                'ts' => $ts !== false ? $ts : time(),
                'url' => 'client-payments.php',
            ];
        }

        $stmt = $pdo->prepare("
            SELECT e.id, e.event_type, e.event_description, e.created_at, c.title AS case_title, c.id AS case_id
            FROM case_events e
            INNER JOIN cases c ON c.id = e.case_id
            WHERE c.client_id = ?
            ORDER BY e.created_at DESC
            LIMIT 15
        ");
        $stmt->execute([$clientId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $desc = trim((string) ($row['event_description'] ?? ''));
            if ($desc === '') {
                $desc = ucfirst(str_replace('_', ' ', (string) ($row['event_type'] ?? 'update')));
            }
            $items[] = [
                'type' => 'event',
                'icon' => 'activity',
                'title' => 'Case activity',
                'subtitle' => mb_substr($desc, 0, 80) . (mb_strlen($desc) > 80 ? '…' : '') . ' · ' . $row['case_title'],
                'ts' => strtotime((string) $row['created_at']),
                'url' => 'client-case-view.php?id=' . (int) $row['case_id'],
            ];
        }

        $stmt = $pdo->prepare("
            SELECT cc.id, cc.comment, cc.comment_type, cc.created_at, c.title AS case_title, c.id AS case_id
            FROM case_comments cc
            INNER JOIN cases c ON c.id = cc.case_id
            WHERE c.client_id = ? AND cc.is_private = 0 AND cc.comment_type != 'client'
            ORDER BY cc.created_at DESC
            LIMIT 15
        ");
        $stmt->execute([$clientId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $role = ucfirst((string) $row['comment_type']);
            $preview = mb_substr((string) $row['comment'], 0, 80);
            if (mb_strlen((string) $row['comment']) > 80) {
                $preview .= '…';
            }
            $items[] = [
                'type' => 'comment',
                'icon' => 'message-circle',
                'title' => $role . ' posted an update',
                'subtitle' => $preview . ' · ' . $row['case_title'],
                'ts' => strtotime((string) $row['created_at']),
                'url' => 'client-case-view.php?id=' . (int) $row['case_id'],
            ];
        }

        $stmt = $pdo->prepare("
            SELECT c.id, c.title, c.status, c.updated_at
            FROM cases c
            WHERE c.client_id = ?
            ORDER BY c.updated_at DESC
            LIMIT 10
        ");
        $stmt->execute([$clientId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = [
                'type' => 'case',
                'icon' => 'briefcase',
                'title' => 'Case updated',
                'subtitle' => $row['title'] . ' — ' . ucfirst(str_replace('_', ' ', (string) $row['status'])),
                'ts' => strtotime((string) $row['updated_at']),
                'url' => 'client-case-view.php?id=' . (int) $row['id'],
            ];
        }
    } catch (PDOException $e) {
        error_log('activity feed: ' . $e->getMessage());
    }

    usort($items, static fn ($a, $b) => ($b['ts'] ?? 0) <=> ($a['ts'] ?? 0));

    return array_slice($items, 0, $limit);
}

function legalpro_client_render_activity_feed_html(array $items): string
{
    require_once __DIR__ . '/../inc/legalpro-icons.php';

    if (empty($items)) {
        return '<div class="cp-activity-empty">'
            . '<div class="cd-empty-icon">' . legalpro_icon('inbox') . '</div>'
            . '<p class="cp-activity-empty__title">No recent activity</p>'
            . '<p class="cp-activity-empty__sub">Updates from your cases, documents, and appointments will appear here.</p>'
            . '</div>';
    }

    $html = '<div class="cp-activity-feed">';
    foreach ($items as $item) {
        $icon = htmlspecialchars((string) ($item['icon'] ?? 'bell'));
        $title = htmlspecialchars((string) ($item['title'] ?? ''));
        $subtitle = htmlspecialchars((string) ($item['subtitle'] ?? ''));
        $url = htmlspecialchars((string) ($item['url'] ?? '#'));
        $time = !empty($item['ts']) ? htmlspecialchars(date('M j · g:i A', (int) $item['ts'])) : '';
        $typeClass = 'cp-activity-item--' . preg_replace('/[^a-z0-9_-]/', '', (string) ($item['type'] ?? 'other'));

        $searchHay = htmlspecialchars(
            strtolower(trim((string) ($item['title'] ?? '') . ' ' . (string) ($item['subtitle'] ?? ''))),
            ENT_QUOTES,
            'UTF-8'
        );

        $html .= '<a href="' . $url . '" class="cp-activity-item ' . $typeClass . '" data-search="' . $searchHay . '">
            <span class="cp-activity-item__icon" data-icon="' . $icon . '"></span>
            <span class="cp-activity-item__body">
                <span class="cp-activity-item__title">' . $title . '</span>
                <span class="cp-activity-item__sub">' . $subtitle . '</span>
            </span>
            <time class="cp-activity-item__time">' . $time . '</time>
        </a>';
    }
    $html .= '</div>';

    return $html;
}

function legalpro_client_render_bottom_nav(string $currentPage): string
{
    $items = [
        ['id' => 'client-dashboard', 'url' => 'client-dashboard.php', 'icon' => 'layout-dashboard', 'label_key' => 'nav.home', 'fallback' => 'Home'],
        ['id' => 'client-cases', 'url' => 'client-cases.php', 'icon' => 'briefcase', 'label_key' => 'nav.my_cases', 'fallback' => 'Cases'],
        ['id' => 'chatbot', 'url' => 'chatbot.php', 'icon' => 'message-circle', 'label_key' => 'nav.messages', 'fallback' => 'Messages'],
        ['id' => 'client-more', 'url' => '#', 'icon' => 'menu', 'label_key' => 'nav.more', 'fallback' => 'More', 'is_more' => true],
    ];

    $moreLinks = [
        ['url' => 'client-documents.php', 'icon' => 'file-text', 'label_key' => 'nav.documents', 'fallback' => 'Documents'],
        ['url' => 'client-appointments.php', 'icon' => 'calendar', 'label_key' => 'nav.appointments', 'fallback' => 'Appointments'],
        ['url' => 'client-payments.php', 'icon' => 'credit-card', 'label_key' => 'nav.payments', 'fallback' => 'Payments'],
        ['url' => 'client-court-tracking.php', 'icon' => 'landmark', 'label_key' => 'nav.court_tracking', 'fallback' => 'Court'],
        ['url' => 'client-settings.php', 'icon' => 'settings', 'label_key' => 'nav.settings', 'fallback' => 'Settings'],
        ['url' => 'client-profile.php', 'icon' => 'user', 'label_key' => 'nav.profile', 'fallback' => 'Profile'],
        ['url' => 'client-requests.php', 'icon' => 'message-circle', 'label_key' => 'nav.my_requests', 'fallback' => 'My requests'],
    ];

    $html = '<nav class="legalpro-client-bottom-nav d-xl-none" aria-label="Mobile navigation">';
    foreach ($items as $item) {
        $active = clientNavIsActive($item['id'], $currentPage);
        if (!empty($item['is_more'])) {
            $active = in_array($currentPage, ['client-documents', 'client-payments', 'client-court-tracking', 'client-settings', 'client-profile', 'client-appointments', 'client-requests'], true);
        }
        $label = function_exists('client_t') ? client_t($item['label_key']) : $item['fallback'];
        if ($label === $item['label_key']) {
            $label = $item['fallback'];
        }
        $class = 'legalpro-client-bottom-nav__item' . ($active ? ' is-active' : '');
        $attrs = !empty($item['is_more']) ? ' data-more-trigger="1" href="#" role="button"' : ' href="' . htmlspecialchars($item['url']) . '"';

        $html .= '<a class="' . $class . '"' . $attrs . '>
            <span class="legalpro-client-bottom-nav__icon">' . legalpro_icon($item['icon']) . '</span>
            <span class="legalpro-client-bottom-nav__label">' . htmlspecialchars($label) . '</span>
        </a>';
    }
    $html .= '</nav>';

    $html .= '<div class="legalpro-client-more-sheet d-xl-none" id="clientMoreSheet" hidden>
        <div class="legalpro-client-more-sheet__backdrop" data-more-close="1"></div>
        <div class="legalpro-client-more-sheet__panel" role="dialog" aria-label="More options">
            <div class="legalpro-client-more-sheet__hdr">
                <strong>' . htmlspecialchars(function_exists('client_t') ? (client_t('nav.more') !== 'nav.more' ? client_t('nav.more') : 'More') : 'More') . '</strong>
                <button type="button" class="btn btn-link p-0" data-more-close="1" aria-label="Close">&times;</button>
            </div>
            <div class="legalpro-client-more-sheet__grid">';
    foreach ($moreLinks as $link) {
        $label = function_exists('client_t') ? client_t($link['label_key']) : $link['fallback'];
        if ($label === $link['label_key']) {
            $label = $link['fallback'];
        }
        $html .= '<a href="' . htmlspecialchars($link['url']) . '" class="legalpro-client-more-sheet__link">
            <span class="legalpro-client-more-sheet__link-icon">' . legalpro_icon($link['icon']) . '</span>
            <span>' . htmlspecialchars($label) . '</span>
        </a>';
    }
    $html .= '</div></div></div>';

    return $html;
}
