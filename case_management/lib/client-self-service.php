<?php
/**
 * Client self-service: requests (callback, billing, evidence) and profile helpers.
 */

require_once __DIR__ . '/client-portal-features.php';

function legalpro_client_requests_ensure_tables(?PDO $pdo = null): void
{
    global $pdo;
    $db = $pdo ?? $GLOBALS['pdo'] ?? null;
    if (!$db instanceof PDO) {
        return;
    }

    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS `client_requests` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `client_id` INT NOT NULL,
              `case_id` INT NULL,
              `request_type` VARCHAR(50) NOT NULL,
              `subject` VARCHAR(255) DEFAULT NULL,
              `message` TEXT NOT NULL,
              `preferred_callback_time` VARCHAR(255) DEFAULT NULL,
              `status` VARCHAR(50) NOT NULL DEFAULT 'open',
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              KEY `idx_client_status` (`client_id`, `status`, `created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (PDOException $e) {
        error_log('client_requests table: ' . $e->getMessage());
    }
}

function legalpro_client_submit_request(PDO $pdo, int $clientId, array $data, $file = null): array
{
    legalpro_client_requests_ensure_tables($pdo);
    if (!function_exists('client_t')) {
        require_once __DIR__ . '/client-locale.php';
    }

    $type = strtolower(trim((string) ($data['request_type'] ?? '')));
    $allowed = ['callback', 'billing', 'evidence'];
    if (!in_array($type, $allowed, true)) {
        return ['ok' => false, 'message' => client_t('self_service.invalid_type')];
    }

    $message = trim((string) ($data['message'] ?? ''));
    if ($message === '') {
        return ['ok' => false, 'message' => client_t('self_service.describe_request')];
    }

    $caseId = (int) ($data['case_id'] ?? 0);
    if ($caseId > 0) {
        $chk = $pdo->prepare('SELECT id FROM cases WHERE id = ? AND client_id = ?');
        $chk->execute([$caseId, $clientId]);
        if (!$chk->fetch()) {
            $caseId = 0;
        }
    } else {
        $caseId = null;
    }

    $subject = trim((string) ($data['subject'] ?? ''));
    if ($subject === '') {
        $subject = client_t('self_service.request_subject', ['type' => ucfirst($type)]);
    }

    $preferred = trim((string) ($data['preferred_callback_time'] ?? ''));

    try {
        $stmt = $pdo->prepare("
            INSERT INTO client_requests (client_id, case_id, request_type, subject, message, preferred_callback_time, status)
            VALUES (?, ?, ?, ?, ?, ?, 'open')
        ");
        $stmt->execute([$clientId, $caseId, $type, $subject, $message, $preferred !== '' ? $preferred : null]);
        $requestId = (int) $pdo->lastInsertId();

        legalpro_client_create_notification(
            $pdo,
            $clientId,
            'request_submitted',
            client_t('self_service.request_received'),
            client_t('self_service.request_notify_body', ['type' => $type]),
            'client-requests.php',
            'clipboard',
            'client_request',
            $requestId
        );

        return ['ok' => true, 'message' => client_t('self_service.request_submitted'), 'request_id' => $requestId];
    } catch (PDOException $e) {
        error_log('submit client request: ' . $e->getMessage());
        return ['ok' => false, 'message' => client_t('self_service.save_error')];
    }
}

function legalpro_client_profile_completeness(PDO $pdo, int $clientId): array
{
    $fields = ['first_name', 'last_name', 'email', 'phone', 'address'];
    $filled = 0;
    $total = count($fields);

    try {
        $stmt = $pdo->prepare('SELECT first_name, last_name, email, phone, address FROM clients WHERE id = ?');
        $stmt->execute([$clientId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        foreach ($fields as $f) {
            if (trim((string) ($row[$f] ?? '')) !== '') {
                $filled++;
            }
        }
    } catch (PDOException $e) {
        return ['percent' => 0, 'filled' => 0, 'total' => $total];
    }

    $percent = $total > 0 ? (int) round(($filled / $total) * 100) : 0;
    return ['percent' => $percent, 'filled' => $filled, 'total' => $total];
}
