<?php
/**
 * Client portal: activity feed, notifications, documents, case progress, calendar export.
 */

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

function legalpro_client_create_notification(
    ?PDO $pdo,
    int $clientId,
    string $type,
    string $title,
    string $body,
    string $linkUrl,
    string $icon = 'bell',
    ?string $refType = null,
    ?int $refId = null
): void {
    if ($pdo === null || $clientId <= 0) {
        return;
    }

    legalpro_client_portal_ensure_tables($pdo);

    try {
        $stmt = $pdo->prepare("
            INSERT INTO client_notifications
            (client_id, type, title, body, link_url, icon, ref_type, ref_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                title = VALUES(title),
                body = VALUES(body),
                link_url = VALUES(link_url),
                icon = VALUES(icon),
                created_at = IF(is_read = 1, created_at, VALUES(created_at))
        ");
        $stmt->execute([$clientId, $type, $title, $body, $linkUrl, $icon, $refType, $refId]);
    } catch (PDOException $e) {
        error_log('client notification: ' . $e->getMessage());
    }
}

function legalpro_client_sync_notifications(?PDO $pdo, int $clientId): void
{
    if ($pdo === null || $clientId <= 0) {
        return;
    }

    legalpro_client_portal_ensure_tables($pdo);

    try {
        $stmt = $pdo->prepare("
            SELECT d.id, d.label, d.filename, d.case_id, c.title AS case_title
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
                (int) $doc['id']
            );
        }

        $stmt = $pdo->prepare("
            SELECT a.id, a.starts_at, a.status, c.title AS case_title, a.case_id
            FROM appointments a
            LEFT JOIN cases c ON c.id = a.case_id
            WHERE a.client_id = ?
              AND a.updated_at >= DATE_SUB(NOW(), INTERVAL 60 DAY)
            ORDER BY a.updated_at DESC
            LIMIT 30
        ");
        $stmt->execute([$clientId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $apt) {
            $status = strtolower((string) ($apt['status'] ?? ''));
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
                    (int) $apt['id']
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
                    (int) $apt['id']
                );
            }
        }

        $stmt = $pdo->prepare("
            SELECT i.id, i.invoice_number, i.amount, i.case_id, c.title AS case_title
            FROM invoices i
            LEFT JOIN cases c ON c.id = i.case_id
            WHERE i.client_id = ?
              AND i.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
            ORDER BY i.created_at DESC
            LIMIT 30
        ");
        $stmt->execute([$clientId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $inv) {
            legalpro_client_create_notification(
                $pdo,
                $clientId,
                'invoice',
                'Invoice issued',
                ($inv['invoice_number'] ?: 'Invoice') . ' — $' . number_format((float) $inv['amount'], 2),
                'client-payments.php',
                'receipt',
                'invoice',
                (int) $inv['id']
            );
        }

        $stmt = $pdo->prepare("
            SELECT cd.id, cd.court_date, cd.title, cd.case_id, c.title AS case_title
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
                (int) $court['id']
            );
        }
    } catch (PDOException $e) {
        error_log('client sync notifications: ' . $e->getMessage());
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
    if (empty($items)) {
        return '<div class="cp-activity-empty">
            <div class="cp-activity-empty__icon" aria-hidden="true">📋</div>
            <p class="cp-activity-empty__title">No recent activity</p>
            <p class="cp-activity-empty__sub">Updates from your cases, documents, and appointments will appear here.</p>
        </div>';
    }

    $html = '<div class="cp-activity-feed">';
    foreach ($items as $item) {
        $icon = htmlspecialchars((string) ($item['icon'] ?? 'bell'));
        $title = htmlspecialchars((string) ($item['title'] ?? ''));
        $subtitle = htmlspecialchars((string) ($item['subtitle'] ?? ''));
        $url = htmlspecialchars((string) ($item['url'] ?? '#'));
        $time = !empty($item['ts']) ? htmlspecialchars(date('M j · g:i A', (int) $item['ts'])) : '';
        $typeClass = 'cp-activity-item--' . preg_replace('/[^a-z0-9_-]/', '', (string) ($item['type'] ?? 'other'));

        $html .= '<a href="' . $url . '" class="cp-activity-item ' . $typeClass . '">
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

function legalpro_client_case_progress_phase(string $status, array $stages = [], bool $hasCourtDates = false): array
{
    $phases = [
        ['key' => 'intake', 'label' => 'Intake'],
        ['key' => 'active', 'label' => 'Active'],
        ['key' => 'hearing', 'label' => 'Hearing'],
        ['key' => 'settlement', 'label' => 'Settlement'],
        ['key' => 'closed', 'label' => 'Closed'],
    ];

    $statusNorm = strtolower(str_replace([' ', '-'], '_', trim($status)));
    $currentIndex = 1;

    if (in_array($statusNorm, ['closed', 'complete', 'completed'], true)) {
        $currentIndex = 4;
    } elseif ($statusNorm === 'pending' || $statusNorm === 'intake') {
        $currentIndex = 0;
    } elseif (in_array($statusNorm, ['settlement', 'settled', 'resolved'], true)) {
        $currentIndex = 3;
    } elseif ($hasCourtDates) {
        $currentIndex = 2;
    } elseif (in_array($statusNorm, ['open', 'active', 'in_progress', 'under_review'], true)) {
        $currentIndex = 1;
    }

    foreach ($stages as $stage) {
        $title = strtolower((string) ($stage['title'] ?? ''));
        if (strpos($title, 'settlement') !== false || strpos($title, 'settle') !== false) {
            if (!empty($stage['actual_end_date']) || !empty($stage['start_date'])) {
                $currentIndex = max($currentIndex, 3);
            }
        }
        if (strpos($title, 'hearing') !== false || strpos($title, 'court') !== false) {
            if (!empty($stage['start_date'])) {
                $currentIndex = max($currentIndex, 2);
            }
        }
        if (strpos($title, 'intake') !== false || strpos($title, 'onboard') !== false) {
            if (!empty($stage['actual_end_date'])) {
                $currentIndex = max($currentIndex, 1);
            }
        }
    }

    foreach ($phases as $i => &$phase) {
        if ($i < $currentIndex) {
            $phase['state'] = 'done';
        } elseif ($i === $currentIndex) {
            $phase['state'] = 'current';
        } else {
            $phase['state'] = 'upcoming';
        }
    }
    unset($phase);

    return $phases;
}

function legalpro_client_render_case_progress_stepper(array $phases): string
{
    $html = '<div class="cp-case-stepper" role="list" aria-label="Case progress">';
    $count = count($phases);
    foreach ($phases as $i => $phase) {
        $state = htmlspecialchars((string) ($phase['state'] ?? 'upcoming'));
        $label = htmlspecialchars((string) ($phase['label'] ?? ''));
        $connector = $i < $count - 1 ? '<span class="cp-case-stepper__line" aria-hidden="true"></span>' : '';
        $html .= '<div class="cp-case-stepper__step cp-case-stepper__step--' . $state . '" role="listitem">
            <span class="cp-case-stepper__dot" aria-hidden="true"></span>
            <span class="cp-case-stepper__label">' . $label . '</span>
        </div>' . $connector;
    }
    $html .= '</div>';
    return $html;
}

function legalpro_client_generate_ics_content(
    string $uid,
    string $title,
    string $startAt,
    ?string $endAt,
    string $description = '',
    string $location = ''
): string {
    $start = new DateTime($startAt);
    $end = $endAt ? new DateTime($endAt) : (clone $start)->modify('+1 hour');

    $fmt = static fn (DateTime $dt) => $dt->format('Ymd\THis');

    $desc = str_replace(["\r\n", "\n", "\r"], '\\n', $description);
    $desc = str_replace(',', '\\,', $desc);

    return "BEGIN:VCALENDAR\r\n"
        . "VERSION:2.0\r\n"
        . "PRODID:-//LegalPro//Client Portal//EN\r\n"
        . "CALSCALE:GREGORIAN\r\n"
        . "METHOD:PUBLISH\r\n"
        . "BEGIN:VEVENT\r\n"
        . "UID:" . $uid . "@legalpro\r\n"
        . "DTSTAMP:" . gmdate('Ymd\THis') . "Z\r\n"
        . "DTSTART:" . $fmt($start) . "\r\n"
        . "DTEND:" . $fmt($end) . "\r\n"
        . "SUMMARY:" . str_replace(',', '\\,', $title) . "\r\n"
        . ($location !== '' ? "LOCATION:" . str_replace(',', '\\,', $location) . "\r\n" : '')
        . ($desc !== '' ? "DESCRIPTION:" . $desc . "\r\n" : '')
        . "END:VEVENT\r\n"
        . "END:VCALENDAR\r\n";
}

function legalpro_client_render_calendar_links(string $exportUrl): string
{
    $url = htmlspecialchars($exportUrl);
    return '<div class="cp-calendar-links">
        <a href="' . $url . '" class="cp-calendar-links__btn" download>
            <span aria-hidden="true">📅</span> Add to calendar (.ics)
        </a>
    </div>';
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
    ];

    $html = '<nav class="legalpro-client-bottom-nav d-xl-none" aria-label="Mobile navigation">';
    foreach ($items as $item) {
        $active = clientNavIsActive($item['id'], $currentPage);
        if (!empty($item['is_more'])) {
            $active = in_array($currentPage, ['client-documents', 'client-payments', 'client-court-tracking', 'client-settings', 'client-profile', 'client-appointments'], true);
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
