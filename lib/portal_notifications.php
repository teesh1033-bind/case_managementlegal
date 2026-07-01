<?php
/**
 * Portal notification feed (YouTube-style dropdown items).
 */

require_once __DIR__ . '/../inc/legalpro-icons.php';

function legalpro_ensure_notification_reads_table(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS portal_notification_reads (
            id INT AUTO_INCREMENT PRIMARY KEY,
            portal_role VARCHAR(20) NOT NULL,
            user_id INT NOT NULL,
            notif_key VARCHAR(120) NOT NULL,
            read_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_portal_notif_read (portal_role, user_id, notif_key),
            INDEX idx_portal_notif_user (portal_role, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $ready = true;
}

function legalpro_portal_notification_actor(): ?array
{
    if (!empty($_SESSION['admin_id'])) {
        return ['role' => 'admin', 'user_id' => (int) $_SESSION['admin_id']];
    }
    if (!empty($_SESSION['lawyer_id'])) {
        return ['role' => 'lawyer', 'user_id' => (int) $_SESSION['lawyer_id']];
    }
    if (!empty($_SESSION['client_id'])) {
        return ['role' => 'client', 'user_id' => (int) $_SESSION['client_id']];
    }

    return null;
}

function legalpro_get_read_notification_keys(PDO $pdo, string $role, int $userId): array
{
    if ($userId <= 0 || $role === '') {
        return [];
    }

    legalpro_ensure_notification_reads_table($pdo);

    try {
        $stmt = $pdo->prepare('SELECT notif_key FROM portal_notification_reads WHERE portal_role = ? AND user_id = ?');
        $stmt->execute([$role, $userId]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (PDOException $e) {
        return [];
    }
}

function legalpro_mark_notification_read(PDO $pdo, string $role, int $userId, string $notifKey): bool
{
    $notifKey = trim($notifKey);
    if ($userId <= 0 || $role === '' || $notifKey === '') {
        return false;
    }

    if (!preg_match('/^[a-z][a-z0-9:_-]{0,119}$/i', $notifKey)) {
        return false;
    }

    legalpro_ensure_notification_reads_table($pdo);

    try {
        $stmt = $pdo->prepare('
            INSERT INTO portal_notification_reads (portal_role, user_id, notif_key)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE read_at = CURRENT_TIMESTAMP
        ');

        return $stmt->execute([$role, $userId, $notifKey]);
    } catch (PDOException $e) {
        return false;
    }
}

function legalpro_filter_read_notifications(PDO $pdo, array $items): array
{
    $actor = legalpro_portal_notification_actor();
    if (!$actor) {
        return $items;
    }

    $readKeys = legalpro_get_read_notification_keys($pdo, $actor['role'], $actor['user_id']);
    if ($readKeys === []) {
        return $items;
    }

    $readLookup = array_flip($readKeys);

    return array_values(array_filter($items, static function (array $item) use ($readLookup): bool {
        $key = (string) ($item['key'] ?? '');

        return $key === '' || !isset($readLookup[$key]);
    }));
}

function legalpro_notification_unread_hint(): string
{
<<<<<<< HEAD
    if (!empty($_SESSION['lawyer_id']) && function_exists('lawyer_t')) {
        $hint = lawyer_t('notifications.unread_hint');
=======
    if (!empty($_SESSION['admin_id']) && function_exists('admin_t')) {
        $hint = admin_t('notifications.unread_hint');
>>>>>>> f63da589d24754b69ba747815f2fbedd935808fa
        if ($hint !== 'notifications.unread_hint') {
            return $hint;
        }
    }

    if (function_exists('client_t')) {
        $hint = client_t('notifications.unread_hint');
        if ($hint !== 'notifications.unread_hint') {
            return $hint;
        }
    }

    return 'New — not yet seen';
}

function legalpro_admin_notification_text(string $key, string $fallback): string
{
    if (!empty($_SESSION['admin_id']) && function_exists('admin_t')) {
        $text = admin_t($key);
        if ($text !== $key) {
            return $text;
        }
    }

    return $fallback;
}

function legalpro_notification_unread_caption_html(): string
{
    return '<span class="legalpro-notif-item__hover-caption" role="tooltip">'
        . htmlspecialchars(legalpro_notification_unread_hint(), ENT_QUOTES, 'UTF-8')
        . '</span>';
}

function legalpro_notification_sanitize_redirect(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    if (preg_match('#^(https?:)?//#i', $url) || strpos($url, '..') !== false) {
        return '';
    }

    return ltrim($url, '/');
}

function legalpro_notification_click_url(string $key, string $destinationUrl): string
{
    $destinationUrl = legalpro_notification_sanitize_redirect($destinationUrl);
    if ($destinationUrl === '') {
        return '#';
    }

    return 'notification-read.php?key=' . rawurlencode($key) . '&to=' . rawurlencode($destinationUrl);
}

function legalpro_notification_time_label(?string $datetime): string
{
    if ($datetime === null || $datetime === '') {
        return '';
    }

    $ts = strtotime($datetime);
    if ($ts === false) {
        return '';
    }

    $diff = time() - $ts;
    if ($diff < 60) {
        return 'Just now';
    }
    if ($diff < 3600) {
        $mins = (int) floor($diff / 60);

        return $mins === 1 ? '1 minute ago' : $mins . ' minutes ago';
    }
    if ($diff < 86400) {
        $hours = (int) floor($diff / 3600);

        return $hours === 1 ? '1 hour ago' : $hours . ' hours ago';
    }
    if ($diff < 604800) {
        $days = (int) floor($diff / 86400);

        return $days === 1 ? '1 day ago' : $days . ' days ago';
    }

    return date('M j, Y', $ts);
}

function legalpro_build_notification_item(
    string $key,
    string $type,
    string $title,
    string $message,
    string $url,
    ?string $createdAt,
    string $icon = 'bell',
    int $sortTs = 0
): array {
    if ($sortTs <= 0 && $createdAt) {
        $sortTs = strtotime($createdAt) ?: 0;
    }

    return [
        'key' => $key,
        'type' => $type,
        'title' => $title,
        'message' => $message,
        'url' => $url,
        'time' => legalpro_notification_time_label($createdAt),
        'icon' => $icon,
        'sort_ts' => $sortTs,
    ];
}

function legalpro_sort_notifications(array $items, int $limit = 20): array
{
    usort($items, static function (array $a, array $b): int {
        return ($b['sort_ts'] ?? 0) <=> ($a['sort_ts'] ?? 0);
    });

    return array_slice($items, 0, $limit);
}

function legalpro_fetch_admin_notifications(PDO $pdo, int $limit = 20): array
{
    $items = [];

    try {
        $stmt = $pdo->query("
            SELECT
                a.id,
                a.starts_at,
                a.created_at,
                a.status,
                CONCAT(cl.first_name, ' ', cl.last_name) AS client_name,
                CONCAT(l.first_name, ' ', l.last_name) AS lawyer_name,
                cs.title AS case_title,
                cs.id AS case_id
            FROM appointments a
            LEFT JOIN cases cs ON cs.id = a.case_id
            LEFT JOIN clients cl ON cl.id = a.client_id
            LEFT JOIN lawyers l ON l.id = a.lawyer_id
            WHERE LOWER(COALESCE(a.status, 'pending')) = 'pending'
            ORDER BY COALESCE(a.starts_at, a.created_at) ASC
            LIMIT 12
        ");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $clientName = trim((string) ($row['client_name'] ?? '')) ?: legalpro_admin_notification_text('badges.role.client', 'Client');
            $lawyerName = trim((string) ($row['lawyer_name'] ?? '')) ?: legalpro_admin_notification_text('notifications.unassigned', 'Unassigned');
            $when = !empty($row['starts_at'])
                ? date('M j, Y · g:i A', strtotime($row['starts_at']))
                : legalpro_admin_notification_text('notifications.date_tbd', 'Date TBD');
            $caseLabel = !empty($row['case_title']) ? (string) $row['case_title'] : legalpro_admin_notification_text('notifications.general_appointment', 'General appointment');

            $items[] = legalpro_build_notification_item(
                'appointment:' . (int) $row['id'],
                'appointment',
                legalpro_admin_notification_text('notifications.pending_appointment', 'Pending appointment'),
                $clientName . ' · ' . $caseLabel . ' · ' . $when . ' · ' . $lawyerName,
                'new_appointment.php?id=' . (int) $row['id'],
                (string) ($row['created_at'] ?? $row['starts_at'] ?? ''),
                'calendar',
                !empty($row['starts_at']) ? (int) strtotime($row['starts_at']) : (int) strtotime((string) ($row['created_at'] ?? ''))
            );
        }
    } catch (PDOException $e) {
        // ignore
    }

    try {
        $tableExists = $pdo->query("SHOW TABLES LIKE 'court_dates'")->rowCount() > 0;
        if ($tableExists) {
            $stmt = $pdo->query("
                SELECT
                    cd.id,
                    cd.court_date,
                    cd.title,
                    cd.created_at,
                    cd.case_id,
                    c.title AS case_title
                FROM court_dates cd
                LEFT JOIN cases c ON c.id = cd.case_id
                WHERE cd.court_date >= CURDATE()
                  AND cd.court_date <= DATE_ADD(CURDATE(), INTERVAL 14 DAY)
                  AND LOWER(COALESCE(cd.status, 'scheduled')) = 'scheduled'
                ORDER BY cd.court_date ASC
                LIMIT 8
            ");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $caseId = (int) ($row['case_id'] ?? 0);
                $caseNumber = $caseId > 0 ? 'C-' . str_pad((string) $caseId, 4, '0', STR_PAD_LEFT) : 'Case';
                $caseTitle = trim((string) ($row['case_title'] ?? '')) ?: 'Court hearing';
                $hearingTitle = trim((string) ($row['title'] ?? '')) ?: 'Court date';
                $when = !empty($row['court_date'])
                    ? date('M j, Y', strtotime($row['court_date']))
                    : 'Upcoming';

                $items[] = legalpro_build_notification_item(
                    'court:' . (int) $row['id'],
                    'court',
                    legalpro_admin_notification_text('notifications.upcoming_court', 'Upcoming court date'),
                    $caseNumber . ' · ' . $caseTitle . ' · ' . $hearingTitle . ' · ' . $when,
                    $caseId > 0 ? 'case-view.php?id=' . $caseId : 'court-tracking.php',
                    (string) ($row['created_at'] ?? $row['court_date'] ?? ''),
                    'landmark',
                    !empty($row['court_date']) ? (int) strtotime($row['court_date']) : 0
                );
            }
        }
    } catch (PDOException $e) {
        // ignore
    }

    try {
        $stmt = $pdo->query("
            SELECT
                c.id,
                c.title,
                COALESCE(c.estimated_fees, 0) AS estimated_fees,
                COALESCE(SUM(p.amount), 0) AS paid_total,
                CONCAT(cl.first_name, ' ', cl.last_name) AS client_name
            FROM cases c
            LEFT JOIN clients cl ON cl.id = c.client_id
            LEFT JOIN payments p ON p.case_id = c.id
            GROUP BY c.id, c.title, c.estimated_fees, cl.first_name, cl.last_name
            HAVING estimated_fees > 0 AND (estimated_fees - paid_total) > 0.01
            ORDER BY (estimated_fees - paid_total) DESC
            LIMIT 6
        ");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $caseId = (int) $row['id'];
            $balance = max((float) $row['estimated_fees'] - (float) $row['paid_total'], 0);
            $caseNumber = 'C-' . str_pad((string) $caseId, 4, '0', STR_PAD_LEFT);
            $clientName = trim((string) ($row['client_name'] ?? '')) ?: legalpro_admin_notification_text('badges.role.client', 'Client');

            $items[] = legalpro_build_notification_item(
                'payment:case:' . $caseId,
                'payment',
                legalpro_admin_notification_text('notifications.outstanding_balance', 'Outstanding balance'),
                $caseNumber . ' · ' . (string) $row['title'] . ' · ' . $clientName . ' · ' . formatCurrency($balance) . ' due',
                'payments.php?case_id=' . $caseId,
                null,
                'banknote',
                $caseId
            );
        }
    } catch (PDOException $e) {
        // ignore
    }

    return legalpro_filter_read_notifications($pdo, legalpro_sort_notifications($items, $limit));
}

function legalpro_admin_notification_unread_count(?PDO $pdo = null): int
{
    if (!$pdo instanceof PDO) {
        return 0;
    }

    return count(legalpro_fetch_admin_notifications($pdo, 100));
}

function legalpro_mark_all_portal_notifications_read(PDO $pdo, string $role, int $userId, array $items): bool
{
    if ($userId <= 0 || $role === '' || $items === []) {
        return false;
    }

    $ok = true;
    foreach ($items as $item) {
        $key = trim((string) ($item['key'] ?? ''));
        if ($key === '') {
            continue;
        }
        if (!legalpro_mark_notification_read($pdo, $role, $userId, $key)) {
            $ok = false;
        }
    }

    return $ok;
}

function legalpro_fetch_lawyer_notifications(PDO $pdo, int $lawyerId, int $limit = 20): array
{
    if ($lawyerId <= 0) {
        return [];
    }

    $items = [];

    try {
        $stmt = $pdo->prepare("
            SELECT
                clw.case_id,
                clw.assigned_at,
                clw.is_primary,
                c.title AS case_title,
                c.status AS case_status,
                CONCAT(cl.first_name, ' ', cl.last_name) AS client_name
            FROM case_lawyers clw
            INNER JOIN cases c ON c.id = clw.case_id
            LEFT JOIN clients cl ON cl.id = c.client_id
            WHERE clw.lawyer_id = ?
              AND LOWER(COALESCE(c.status, 'open')) != 'closed'
              AND clw.assigned_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
            ORDER BY clw.assigned_at DESC
            LIMIT 12
        ");
        $stmt->execute([$lawyerId]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $caseId = (int) ($row['case_id'] ?? 0);
            if ($caseId <= 0) {
                continue;
            }
            $caseNumber = 'C-' . str_pad((string) $caseId, 4, '0', STR_PAD_LEFT);
            $caseTitle = trim((string) ($row['case_title'] ?? '')) ?: 'Case';
            $clientName = trim((string) ($row['client_name'] ?? '')) ?: 'Client';
            $assignedAt = (string) ($row['assigned_at'] ?? '');
            $roleLabel = !empty($row['is_primary']) ? 'Primary lawyer' : 'Assigned lawyer';

            $items[] = legalpro_build_notification_item(
                'case-assign:' . $caseId,
                'case',
                'Assigned to a case',
                $caseNumber . ' · ' . $caseTitle . ' · ' . $clientName . ' · ' . $roleLabel,
                'lawyer-case-view.php?id=' . $caseId,
                $assignedAt,
                'briefcase',
                $assignedAt !== '' ? (int) strtotime($assignedAt) : time()
            );
        }
    } catch (PDOException $e) {
        // ignore
    }

    try {
        require_once __DIR__ . '/task_helpers.php';
        ensure_task_support_schema($pdo);

        $stmt = $pdo->prepare("
            SELECT
                t.id,
                t.title,
                t.due_date,
                t.created_at,
                c.id AS case_id,
                c.title AS case_title
            FROM tasks t
            INNER JOIN cases c ON c.id = t.case_id
            WHERE " . lawyer_task_access_sql() . "
              AND t.due_date IS NOT NULL
              AND t.due_date < CURDATE()
              AND LOWER(COALESCE(t.status, 'pending')) NOT IN ('closed', 'completed', 'cancelled')
            ORDER BY t.due_date ASC
            LIMIT 8
        ");
        $stmt->execute([$lawyerId, $lawyerId]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $taskId = (int) ($row['id'] ?? 0);
            if ($taskId <= 0) {
                continue;
            }
            $caseId = (int) ($row['case_id'] ?? 0);
            $caseNumber = $caseId > 0 ? 'C-' . str_pad((string) $caseId, 4, '0', STR_PAD_LEFT) : 'Case';
            $caseTitle = trim((string) ($row['case_title'] ?? '')) ?: 'Case';
            $taskTitle = trim((string) ($row['title'] ?? '')) ?: 'Task';
            $dueLabel = date('M j, Y', strtotime((string) $row['due_date']));

            $items[] = legalpro_build_notification_item(
                'task-overdue:' . $taskId,
                'task',
                'Task overdue',
                $caseNumber . ' · ' . $taskTitle . ' · ' . $caseTitle . ' · Due ' . $dueLabel,
                'tasks.php?due=overdue',
                (string) ($row['due_date'] ?? $row['created_at'] ?? ''),
                'list-checks',
                (int) strtotime((string) $row['due_date'])
            );
        }

        $stmt = $pdo->prepare("
            SELECT
                t.id,
                t.title,
                t.due_date,
                t.created_at,
                c.id AS case_id,
                c.title AS case_title
            FROM tasks t
            INNER JOIN cases c ON c.id = t.case_id
            WHERE " . lawyer_task_access_sql() . "
              AND t.due_date = CURDATE()
              AND LOWER(COALESCE(t.status, 'pending')) NOT IN ('closed', 'completed', 'cancelled')
            ORDER BY t.due_date ASC
            LIMIT 8
        ");
        $stmt->execute([$lawyerId, $lawyerId]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $taskId = (int) ($row['id'] ?? 0);
            if ($taskId <= 0) {
                continue;
            }
            $caseId = (int) ($row['case_id'] ?? 0);
            $caseNumber = $caseId > 0 ? 'C-' . str_pad((string) $caseId, 4, '0', STR_PAD_LEFT) : 'Case';
            $caseTitle = trim((string) ($row['case_title'] ?? '')) ?: 'Case';
            $taskTitle = trim((string) ($row['title'] ?? '')) ?: 'Task';

            $items[] = legalpro_build_notification_item(
                'task-due-today:' . $taskId,
                'task',
                'Task due today',
                $caseNumber . ' · ' . $taskTitle . ' · ' . $caseTitle,
                'tasks.php?due=today',
                (string) ($row['due_date'] ?? $row['created_at'] ?? ''),
                'list-checks',
                (int) strtotime((string) $row['due_date'])
            );
        }
    } catch (PDOException $e) {
        // ignore
    }

    try {
        $stmt = $pdo->prepare("
            SELECT
                t.id,
                t.title,
                t.priority,
                t.due_date,
                t.created_at,
                t.status,
                c.id AS case_id,
                c.title AS case_title
            FROM tasks t
            INNER JOIN cases c ON c.id = t.case_id
            WHERE LOWER(COALESCE(t.status, 'pending')) IN ('pending', 'in_progress')
              AND (
                t.assigned_lawyer_id = ?
                OR EXISTS (
                    SELECT 1 FROM task_lawyers tl
                    WHERE tl.task_id = t.id AND tl.lawyer_id = ?
                )
              )
            ORDER BY COALESCE(t.due_date, t.created_at) ASC
            LIMIT 12
        ");
        $stmt->execute([$lawyerId, $lawyerId]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $taskId = (int) ($row['id'] ?? 0);
            if ($taskId <= 0) {
                continue;
            }
            $dueDate = (string) ($row['due_date'] ?? '');
            $today = date('Y-m-d');
            if ($dueDate !== '' && ($dueDate < $today || $dueDate === $today)) {
                continue;
            }
            $caseId = (int) ($row['case_id'] ?? 0);
            $caseNumber = $caseId > 0 ? 'C-' . str_pad((string) $caseId, 4, '0', STR_PAD_LEFT) : 'Case';
            $caseTitle = trim((string) ($row['case_title'] ?? '')) ?: 'Case';
            $taskTitle = trim((string) ($row['title'] ?? '')) ?: 'Task';
            $priority = ucfirst(strtolower((string) ($row['priority'] ?? 'medium')));
            $dueLabel = !empty($row['due_date'])
                ? 'Due ' . date('M j, Y', strtotime((string) $row['due_date']))
                : 'No due date';

            $items[] = legalpro_build_notification_item(
                'task:' . $taskId,
                'task',
                'Task assigned to you',
                $caseNumber . ' · ' . $taskTitle . ' · ' . $caseTitle . ' · ' . $priority . ' · ' . $dueLabel,
                'tasks.php',
                (string) ($row['created_at'] ?? ''),
                'list-checks',
                !empty($row['due_date'])
                    ? (int) strtotime((string) $row['due_date'])
                    : (int) strtotime((string) ($row['created_at'] ?? ''))
            );
        }
    } catch (PDOException $e) {
        // ignore — task_lawyers table may not exist yet on older installs
    }

    try {
        $tableExists = $pdo->query("SHOW TABLES LIKE 'court_dates'")->rowCount() > 0;
        if ($tableExists) {
            $stmt = $pdo->prepare("
                SELECT
                    cd.id,
                    cd.court_date,
                    cd.title,
                    cd.created_at,
                    cd.case_id,
                    c.title AS case_title,
                    CONCAT(cl.first_name, ' ', cl.last_name) AS client_name
                FROM court_dates cd
                INNER JOIN case_lawyers clw ON clw.case_id = cd.case_id
                LEFT JOIN cases c ON c.id = cd.case_id
                LEFT JOIN clients cl ON cl.id = c.client_id
                WHERE clw.lawyer_id = ?
                  AND cd.court_date >= CURDATE()
                  AND cd.court_date <= DATE_ADD(CURDATE(), INTERVAL 14 DAY)
                  AND LOWER(COALESCE(cd.status, 'scheduled')) = 'scheduled'
                ORDER BY cd.court_date ASC
                LIMIT 8
            ");
            $stmt->execute([$lawyerId]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $caseId = (int) ($row['case_id'] ?? 0);
                $caseNumber = $caseId > 0 ? 'C-' . str_pad((string) $caseId, 4, '0', STR_PAD_LEFT) : 'Case';
                $caseTitle = trim((string) ($row['case_title'] ?? '')) ?: 'Court hearing';
                $hearingTitle = trim((string) ($row['title'] ?? '')) ?: 'Court date';
                $clientName = trim((string) ($row['client_name'] ?? '')) ?: 'Client';
                $when = !empty($row['court_date'])
                    ? date('M j, Y · g:i A', strtotime((string) $row['court_date']))
                    : 'Upcoming';

                $items[] = legalpro_build_notification_item(
                    'court:' . (int) $row['id'],
                    'court',
                    'Upcoming court date',
                    $caseNumber . ' · ' . $caseTitle . ' · ' . $hearingTitle . ' · ' . $clientName . ' · ' . $when,
                    'lawyer-court-tracking.php',
                    (string) ($row['created_at'] ?? $row['court_date'] ?? ''),
                    'landmark',
                    !empty($row['court_date']) ? (int) strtotime((string) $row['court_date']) : 0
                );
            }
        }
    } catch (PDOException $e) {
        // ignore
    }

    try {
        $stmt = $pdo->prepare("
            SELECT
                a.id,
                a.starts_at,
                a.created_at,
                a.status,
                CONCAT(cl.first_name, ' ', cl.last_name) AS client_name,
                cs.title AS case_title
            FROM appointments a
            LEFT JOIN cases cs ON cs.id = a.case_id
            LEFT JOIN clients cl ON cl.id = a.client_id
            WHERE a.lawyer_id = ?
              AND LOWER(COALESCE(a.status, 'pending')) = 'pending'
            ORDER BY COALESCE(a.starts_at, a.created_at) ASC
            LIMIT 15
        ");
        $stmt->execute([$lawyerId]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $clientName = trim((string) ($row['client_name'] ?? '')) ?: 'Client';
            $when = !empty($row['starts_at'])
                ? date('M j, Y · g:i A', strtotime($row['starts_at']))
                : 'Date TBD';
            $caseLabel = !empty($row['case_title']) ? (string) $row['case_title'] : 'Appointment request';

            $items[] = legalpro_build_notification_item(
                'appointment:' . (int) $row['id'],
                'appointment',
                'Appointment awaiting response',
                $clientName . ' · ' . $caseLabel . ' · ' . $when,
                'lawyer-appointments.php#apt-' . (int) $row['id'],
                (string) ($row['created_at'] ?? $row['starts_at'] ?? ''),
                'calendar',
                !empty($row['starts_at']) ? (int) strtotime($row['starts_at']) : (int) strtotime((string) ($row['created_at'] ?? ''))
            );
        }
    } catch (PDOException $e) {
        // ignore
    }

    return legalpro_filter_read_notifications($pdo, legalpro_sort_notifications($items, $limit));
}

function legalpro_fetch_client_notifications(PDO $pdo, int $clientId, int $limit = 20): array
{
    if ($clientId <= 0) {
        return [];
    }

    $items = [];

    try {
        $stmt = $pdo->prepare("
            SELECT
                a.id,
                a.starts_at,
                a.created_at,
                a.status,
                CONCAT(l.first_name, ' ', l.last_name) AS lawyer_name,
                cs.title AS case_title
            FROM appointments a
            LEFT JOIN cases cs ON cs.id = a.case_id
            LEFT JOIN lawyers l ON l.id = a.lawyer_id
            WHERE a.client_id = ?
              AND (
                LOWER(COALESCE(a.status, 'pending')) = 'pending'
                OR LOWER(COALESCE(a.status, 'pending')) = 'rejected'
                OR (
                  LOWER(COALESCE(a.status, 'pending')) IN ('approved', 'accepted')
                  AND COALESCE(a.starts_at, a.created_at) >= DATE_SUB(NOW(), INTERVAL 1 DAY)
                )
              )
            ORDER BY COALESCE(a.starts_at, a.created_at) DESC
            LIMIT 15
        ");
        $stmt->execute([$clientId]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $status = strtolower((string) ($row['status'] ?? 'pending'));
            if ($status === 'approved') {
                $status = 'accepted';
            }
            $lawyerName = trim((string) ($row['lawyer_name'] ?? '')) ?: 'Lawyer';
            $when = !empty($row['starts_at'])
                ? date('M j, Y · g:i A', strtotime($row['starts_at']))
                : 'Date TBD';
            $caseLabel = !empty($row['case_title']) ? (string) $row['case_title'] : 'Your appointment';

            if ($status === 'pending') {
                $title = 'Appointment pending approval';
                $message = $caseLabel . ' · ' . $when . ' · ' . $lawyerName;
            } elseif ($status === 'accepted') {
                $title = 'Appointment confirmed';
                $message = $caseLabel . ' · ' . $when . ' · ' . $lawyerName;
            } else {
                $title = 'Appointment declined';
                $message = $caseLabel . ' · ' . $when . ' · You can book another time';
            }

            $items[] = legalpro_build_notification_item(
                'appointment:' . (int) $row['id'] . ':' . $status,
                'appointment',
                $title,
                $message,
                'client-appointments.php#apt-' . (int) $row['id'],
                (string) ($row['created_at'] ?? $row['starts_at'] ?? ''),
                'calendar',
                !empty($row['starts_at']) ? (int) strtotime($row['starts_at']) : (int) strtotime((string) ($row['created_at'] ?? ''))
            );
        }
    } catch (PDOException $e) {
        // ignore
    }

    return legalpro_filter_read_notifications($pdo, legalpro_sort_notifications($items, $limit));
}

function legalpro_render_notification_panel(array $items, string $viewAllUrl): string
{
    if (!function_exists('legalpro_portal_translate')) {
        $localePath = __DIR__ . '/lawyer-locale.php';
        if (is_file($localePath)) {
            require_once $localePath;
        }
    }

    $title = function_exists('legalpro_portal_translate')
        ? legalpro_portal_translate('notifications.title', 'Notifications')
        : 'Notifications';
    $viewAll = function_exists('legalpro_portal_translate')
        ? legalpro_portal_translate('notifications.view_all', 'View all')
        : 'View all';
    $emptyTitle = function_exists('legalpro_portal_translate')
        ? legalpro_portal_translate('notifications.empty_title', 'No new notifications')
        : 'No new notifications';
    $emptySub = function_exists('legalpro_portal_translate')
        ? legalpro_portal_translate('notifications.empty_sub', 'You are all caught up.')
        : 'You are all caught up.';

    $count = count($items);
    $bodyHtml = '';

    if ($count === 0) {
        $bodyHtml = '<div class="legalpro-notif-panel__empty">'
            . legalpro_icon('bell', 'legalpro-notif-panel__empty-icon')
            . '<p>' . htmlspecialchars($emptyTitle) . '</p>'
            . '<span>' . htmlspecialchars($emptySub) . '</span>'
            . '</div>';
    } else {
        foreach ($items as $item) {
            $icon = htmlspecialchars((string) ($item['icon'] ?? 'bell'), ENT_QUOTES, 'UTF-8');
            $notifKey = (string) ($item['key'] ?? '');
            $destinationUrl = (string) ($item['url'] ?? '');
            $clickUrl = $notifKey !== ''
                ? legalpro_notification_click_url($notifKey, $destinationUrl)
                : $destinationUrl;
            $unreadHint = legalpro_notification_unread_hint();
            $bodyHtml .= '<a href="' . htmlspecialchars($clickUrl, ENT_QUOTES, 'UTF-8') . '" class="legalpro-notif-item is-unread"'
                . ($notifKey !== '' ? ' data-notif-key="' . htmlspecialchars($notifKey, ENT_QUOTES, 'UTF-8') . '"' : '')
                . ' title="' . htmlspecialchars($unreadHint, ENT_QUOTES, 'UTF-8') . '"'
                . '>'
                . '<span class="legalpro-notif-item__icon legalpro-notif-item__icon--' . htmlspecialchars((string) ($item['type'] ?? 'default'), ENT_QUOTES, 'UTF-8') . '">'
                . legalpro_icon($icon)
                . '</span>'
                . '<span class="legalpro-notif-item__body">'
                . '<span class="legalpro-notif-item__title">' . htmlspecialchars((string) $item['title']) . '</span>'
                . '<span class="legalpro-notif-item__message">' . htmlspecialchars((string) $item['message']) . '</span>'
                . '<span class="legalpro-notif-item__time">' . htmlspecialchars((string) ($item['time'] ?? '')) . '</span>'
                . '</span>'
                . legalpro_notification_unread_caption_html()
                . '</a>';
        }
    }

    return '<div class="legalpro-notif-panel" id="legalproNotifPanel" role="menu" aria-label="' . htmlspecialchars($title) . '">'
        . '<div class="legalpro-notif-panel__head">'
        . '<h6 class="legalpro-notif-panel__title">' . htmlspecialchars($title) . '</h6>'
        . ($count > 0 ? '<span class="legalpro-notif-panel__count">' . (int) $count . '</span>' : '')
        . '</div>'
        . '<div class="legalpro-notif-panel__body">' . $bodyHtml . '</div>'
        . '<div class="legalpro-notif-panel__foot">'
        . '<a href="' . htmlspecialchars($viewAllUrl, ENT_QUOTES, 'UTF-8') . '" class="legalpro-notif-panel__view-all">' . htmlspecialchars($viewAll) . '</a>'
        . '</div>'
        . '</div>';
}
