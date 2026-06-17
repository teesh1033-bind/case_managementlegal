<?php

/**
 * Lawyer portal helpers (settings snapshot, etc.).
 */

function legalpro_lawyer_settings_snapshot(?PDO $pdo, int $lawyerId): array
{
    $empty = [
        'display_name' => '',
        'email' => '',
        'phone' => '',
        'specialization' => '',
        'member_since' => '',
        'total_cases' => 0,
        'active_cases' => 0,
        'total_clients' => 0,
        'upcoming_appointments' => 0,
        'open_tasks' => 0,
        'unread_notifications' => 0,
        'theme_mode' => 'light',
    ];

    if ($pdo === null || $lawyerId <= 0) {
        return $empty;
    }

    $snapshot = $empty;

    try {
        $stmt = $pdo->prepare('SELECT first_name, last_name, email, phone, specialization, created_at FROM lawyers WHERE id = ? LIMIT 1');
        $stmt->execute([$lawyerId]);
        $lawyer = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($lawyer) {
            $snapshot['display_name'] = trim(($lawyer['first_name'] ?? '') . ' ' . ($lawyer['last_name'] ?? ''));
            $snapshot['email'] = (string) ($lawyer['email'] ?? '');
            $snapshot['phone'] = (string) ($lawyer['phone'] ?? '');
            $snapshot['specialization'] = (string) ($lawyer['specialization'] ?? '');
            if (!empty($lawyer['created_at'])) {
                $snapshot['member_since'] = date('M j, Y', strtotime($lawyer['created_at']));
            }
        }

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM case_lawyers WHERE lawyer_id = ?');
        $stmt->execute([$lawyerId]);
        $snapshot['total_cases'] = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM cases c
            INNER JOIN case_lawyers cl ON cl.case_id = c.id
            WHERE cl.lawyer_id = ? AND LOWER(COALESCE(c.status, '')) NOT IN ('closed', 'resolved')
        ");
        $stmt->execute([$lawyerId]);
        $snapshot['active_cases'] = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT c.client_id) FROM cases c
            INNER JOIN case_lawyers cl ON cl.case_id = c.id
            WHERE cl.lawyer_id = ?
        ");
        $stmt->execute([$lawyerId]);
        $snapshot['total_clients'] = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM appointments a
            WHERE a.lawyer_id = ? AND a.starts_at > NOW()
              AND LOWER(COALESCE(a.status, '')) IN ('accepted', 'pending')
        ");
        $stmt->execute([$lawyerId]);
        $snapshot['upcoming_appointments'] = (int) $stmt->fetchColumn();

        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM tasks
                WHERE assigned_lawyer_id = ?
                  AND LOWER(COALESCE(status, '')) IN ('pending', 'in_progress')
            ");
            $stmt->execute([$lawyerId]);
            $snapshot['open_tasks'] = (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            // tasks table may not exist on older installs
        }
    } catch (PDOException $e) {
        error_log('lawyer settings snapshot: ' . $e->getMessage());
    }

    if (function_exists('legalpro_lawyer_notification_count')) {
        $snapshot['unread_notifications'] = legalpro_lawyer_notification_count($pdo, $lawyerId);
    }

    if (function_exists('getLawyerPortalThemeMode')) {
        $snapshot['theme_mode'] = getLawyerPortalThemeMode($lawyerId);
    }

    return $snapshot;
}
