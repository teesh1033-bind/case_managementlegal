<?php

/**
 * Admin dashboard activity / notifications feed.
 */

require_once __DIR__ . '/../inc/legalpro-icons.php';
require_once __DIR__ . '/portal_notifications.php';

function legalpro_admin_activity_label(string $key, string $fallback): string
{
    if (function_exists('admin_t')) {
        $text = admin_t($key);
        if ($text !== $key) {
            return $text;
        }
    }

    return $fallback;
}

function legalpro_admin_activity_case_ref(int $caseId): string
{
    if ($caseId <= 0) {
        return 'General';
    }

    return 'C-' . str_pad((string) $caseId, 4, '0', STR_PAD_LEFT);
}

function legalpro_admin_get_activity_feed(?PDO $pdo, int $limit = 60): array
{
    if (!$pdo instanceof PDO) {
        return [];
    }

    $items = [];

    try {
        $stmt = $pdo->query("
            SELECT d.id, d.label, d.filename, d.uploaded_at, c.id AS case_id, c.title AS case_title
            FROM documents d
            INNER JOIN cases c ON c.id = d.case_id
            ORDER BY d.uploaded_at DESC
            LIMIT 30
        ");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $caseId = (int) ($row['case_id'] ?? 0);
            $fileLabel = trim((string) ($row['label'] ?: $row['filename'] ?: 'Document'));
            $items[] = [
                'type' => 'document',
                'icon' => 'file-up',
                'title' => legalpro_admin_activity_label('activity.document_uploaded', 'Document uploaded'),
                'subtitle' => $fileLabel . ' · ' . legalpro_admin_activity_case_ref($caseId),
                'ts' => strtotime((string) ($row['uploaded_at'] ?? '')) ?: 0,
                'url' => $caseId > 0 ? 'case-view.php?id=' . $caseId : 'documents.php',
            ];
        }
    } catch (PDOException $e) {
        // ignore
    }

    try {
        $stmt = $pdo->query("
            SELECT i.id, i.invoice_number, i.amount, i.status, i.created_at, c.id AS case_id, c.title AS case_title
            FROM invoices i
            LEFT JOIN cases c ON c.id = i.case_id
            ORDER BY i.created_at DESC
            LIMIT 30
        ");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $status = strtolower((string) ($row['status'] ?? ''));
            $isOverdue = in_array($status, ['overdue', 'unpaid', 'pending'], true);
            $caseId = (int) ($row['case_id'] ?? 0);
            $invNo = trim((string) ($row['invoice_number'] ?: 'Invoice'));
            $items[] = [
                'type' => $isOverdue ? 'notification' : 'invoice',
                'icon' => $isOverdue ? 'bell' : 'receipt',
                'title' => $isOverdue
                    ? legalpro_admin_activity_label('activity.notification_sent', 'New notification sent')
                    : legalpro_admin_activity_label('activity.invoice_issued', 'Invoice issued'),
                'subtitle' => ($isOverdue
                    ? legalpro_admin_activity_label('activity.invoice_overdue', 'Invoice overdue')
                    : $invNo) . ' · ' . legalpro_admin_activity_case_ref($caseId),
                'ts' => strtotime((string) ($row['created_at'] ?? '')) ?: 0,
                'url' => 'invoices.php',
            ];
        }
    } catch (PDOException $e) {
        // ignore
    }

    try {
        $stmt = $pdo->query("
            SELECT a.id, a.starts_at, a.status, a.updated_at, a.created_at,
                   c.id AS case_id, c.title AS case_title,
                   TRIM(CONCAT(cl.first_name, ' ', cl.last_name)) AS client_name
            FROM appointments a
            LEFT JOIN cases c ON c.id = a.case_id
            LEFT JOIN clients cl ON cl.id = a.client_id
            ORDER BY COALESCE(a.updated_at, a.created_at, a.starts_at) DESC
            LIMIT 25
        ");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $status = strtolower((string) ($row['status'] ?? 'pending'));
            $title = match ($status) {
                'approved', 'accepted' => legalpro_admin_activity_label('activity.appt_confirmed', 'Appointment confirmed'),
                'rejected', 'cancelled' => legalpro_admin_activity_label('activity.appt_cancelled', 'Appointment cancelled'),
                default => legalpro_admin_activity_label('activity.appt_pending', 'Appointment pending'),
            };
            $caseId = (int) ($row['case_id'] ?? 0);
            $clientName = trim((string) ($row['client_name'] ?? '')) ?: legalpro_admin_activity_label('notifications.unassigned', 'Unassigned');
            $when = !empty($row['starts_at'])
                ? date('M j, g:i A', strtotime((string) $row['starts_at']))
                : legalpro_admin_activity_label('notifications.date_tbd', 'Date TBD');
            $items[] = [
                'type' => 'appointment',
                'icon' => 'calendar',
                'title' => $title,
                'subtitle' => $clientName . ' · ' . $when . ' · ' . legalpro_admin_activity_case_ref($caseId),
                'ts' => strtotime((string) ($row['updated_at'] ?? $row['created_at'] ?? $row['starts_at'] ?? '')) ?: 0,
                'url' => 'new_appointment.php?id=' . (int) $row['id'],
            ];
        }
    } catch (PDOException $e) {
        // ignore
    }

    try {
        $stmt = $pdo->query("
            SELECT p.id, p.amount, p.payment_date, p.created_at, c.id AS case_id, c.title AS case_title
            FROM payments p
            LEFT JOIN cases c ON c.id = p.case_id
            ORDER BY COALESCE(p.payment_date, p.created_at) DESC
            LIMIT 20
        ");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $caseId = (int) ($row['case_id'] ?? 0);
            $items[] = [
                'type' => 'payment',
                'icon' => 'credit-card',
                'title' => legalpro_admin_activity_label('activity.payment_recorded', 'Payment recorded'),
                'subtitle' => formatCurrency((float) ($row['amount'] ?? 0)) . ' · ' . legalpro_admin_activity_case_ref($caseId),
                'ts' => strtotime((string) ($row['payment_date'] ?? $row['created_at'] ?? '')) ?: 0,
                'url' => $caseId > 0 ? 'payments.php?case_id=' . $caseId : 'payments.php',
            ];
        }
    } catch (PDOException $e) {
        // ignore
    }

    try {
        if ($pdo->query("SHOW TABLES LIKE 'court_dates'")->rowCount() > 0) {
            $stmt = $pdo->query("
                SELECT cd.id, cd.court_date, cd.title, cd.created_at, c.id AS case_id, c.title AS case_title
                FROM court_dates cd
                LEFT JOIN cases c ON c.id = cd.case_id
                ORDER BY cd.created_at DESC
                LIMIT 20
            ");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $caseId = (int) ($row['case_id'] ?? 0);
                $items[] = [
                    'type' => 'court',
                    'icon' => 'landmark',
                    'title' => legalpro_admin_activity_label('activity.court_scheduled', 'Court date scheduled'),
                    'subtitle' => trim((string) ($row['title'] ?: 'Hearing')) . ' · '
                        . date('M j, Y', strtotime((string) $row['court_date'])) . ' · '
                        . legalpro_admin_activity_case_ref($caseId),
                    'ts' => strtotime((string) ($row['created_at'] ?? $row['court_date'] ?? '')) ?: 0,
                    'url' => $caseId > 0 ? 'case-view.php?id=' . $caseId : 'court-tracking.php',
                ];
            }
        }
    } catch (PDOException $e) {
        // ignore
    }

    try {
        $stmt = $pdo->query("
            SELECT c.id, c.title, c.status, c.created_at
            FROM cases c
            ORDER BY c.created_at DESC
            LIMIT 15
        ");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $caseId = (int) ($row['id'] ?? 0);
            $items[] = [
                'type' => 'case',
                'icon' => 'briefcase',
                'title' => legalpro_admin_activity_label('activity.case_created', 'New case opened'),
                'subtitle' => (string) ($row['title'] ?? 'Case') . ' · ' . legalpro_admin_activity_case_ref($caseId),
                'ts' => strtotime((string) ($row['created_at'] ?? '')) ?: 0,
                'url' => 'case-view.php?id=' . $caseId,
            ];
        }
    } catch (PDOException $e) {
        // ignore
    }

    usort($items, static fn (array $a, array $b): int => ($b['ts'] ?? 0) <=> ($a['ts'] ?? 0));

    return array_slice($items, 0, $limit);
}

function legalpro_admin_activity_feed_per_page(): int
{
    return 5;
}

function legalpro_admin_render_activity_feed_html(array $items, ?int $perPage = null): string
{
    if ($perPage === null) {
        $perPage = legalpro_admin_activity_feed_per_page();
    }
    $perPage = max(1, $perPage);

    if ($items === []) {
        return '<div class="vu-activity-empty">'
            . '<div class="vu-activity-empty__icon">' . legalpro_icon('inbox') . '</div>'
            . '<p class="vu-activity-empty__title">' . htmlspecialchars(legalpro_admin_activity_label('activity.empty_title', 'No recent activity')) . '</p>'
            . '<p class="vu-activity-empty__sub">' . htmlspecialchars(legalpro_admin_activity_label('activity.empty_sub', 'Business events will appear here as they happen.')) . '</p>'
            . '</div>';
    }

    $total = count($items);
    $needsPagination = $total > $perPage;
    $activityWord = legalpro_admin_activity_label('activity.items_word', 'activities');

    $html = '<div class="vu-activity-feed-wrap"'
        . ' data-activity-per-page="' . (int) $perPage . '"'
        . ' data-activity-total="' . (int) $total . '"'
        . ' data-activity-label="' . htmlspecialchars($activityWord, ENT_QUOTES, 'UTF-8') . '">';
    $html .= '<div class="vu-activity-feed">';

    foreach ($items as $item) {
        $type = preg_replace('/[^a-z0-9_-]/', '', (string) ($item['type'] ?? 'other'));
        $icon = htmlspecialchars((string) ($item['icon'] ?? 'bell'));
        $title = htmlspecialchars((string) ($item['title'] ?? ''));
        $subtitle = htmlspecialchars((string) ($item['subtitle'] ?? ''));
        $url = htmlspecialchars((string) ($item['url'] ?? '#'));
        $ts = (int) ($item['ts'] ?? 0);
        $time = $ts > 0
            ? htmlspecialchars(legalpro_notification_time_label(date('Y-m-d H:i:s', $ts)))
            : '';

        $html .= '<a href="' . $url . '" class="vu-activity-item vu-activity-item--' . $type . '">'
            . '<span class="vu-activity-item__icon">' . legalpro_icon($icon) . '</span>'
            . '<span class="vu-activity-item__body">'
            . '<span class="vu-activity-item__title">' . $title . '</span>'
            . '<span class="vu-activity-item__sub">' . $subtitle . '</span>'
            . '</span>'
            . '<time class="vu-activity-item__time">' . $time . '</time>'
            . '</a>';
    }

    $html .= '</div>';

    if ($needsPagination) {
        $html .= '<nav class="vu-activity-pagination" aria-label="' . htmlspecialchars(legalpro_admin_activity_label('activity.pagination_aria', 'Activity pages')) . '">'
            . '<p class="vu-activity-pagination__info" data-activity-range></p>'
            . '<div class="vu-activity-pagination__controls" data-activity-pages></div>'
            . '</nav>';
    }

    $html .= '</div>';

    return $html;
}
