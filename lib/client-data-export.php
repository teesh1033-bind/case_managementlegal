<?php
declare(strict_types=1);

/**
 * Client portal data export (download / email backup).
 */

function legalpro_client_build_data_export(PDO $pdo, int $clientId): array
{
    if ($clientId <= 0) {
        return [];
    }

    $export = [
        'exported_at' => date('c'),
        'portal' => 'client',
        'profile' => null,
        'cases' => [],
        'invoices' => [],
        'payments' => [],
        'appointments' => [],
        'documents' => [],
    ];

    $stmt = $pdo->prepare('SELECT id, first_name, last_name, email, phone, address, created_at FROM clients WHERE id = ? LIMIT 1');
    $stmt->execute([$clientId]);
    $export['profile'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $stmt = $pdo->prepare('SELECT id, title, status, category, priority, created_at, updated_at FROM cases WHERE client_id = ? ORDER BY updated_at DESC');
    $stmt->execute([$clientId]);
    $export['cases'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare('SELECT id, invoice_number, case_id, amount, issue_date, due_date, status FROM invoices WHERE client_id = ? ORDER BY issue_date DESC');
    $stmt->execute([$clientId]);
    $export['invoices'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare('SELECT id, invoice_id, case_id, amount, payment_method, payment_date, status FROM payments WHERE client_id = ? ORDER BY payment_date DESC');
    $stmt->execute([$clientId]);
    $export['payments'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare('SELECT id, case_id, title, starts_at, ends_at, status, notes FROM appointments WHERE client_id = ? ORDER BY starts_at DESC');
    $stmt->execute([$clientId]);
    $export['appointments'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    try {
        $stmt = $pdo->prepare('
            SELECT d.id, d.case_id, d.file_name, d.file_path, d.uploaded_at
            FROM documents d
            INNER JOIN cases c ON c.id = d.case_id
            WHERE c.client_id = ?
            ORDER BY d.uploaded_at DESC
        ');
        $stmt->execute([$clientId]);
        $export['documents'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $export['documents'] = [];
    }

    return $export;
}

function legalpro_client_send_data_export_email(PDO $pdo, int $clientId, string $toEmail): array
{
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'Invalid email address.'];
    }

    $payload = legalpro_client_build_data_export($pdo, $clientId);
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return ['ok' => false, 'message' => 'Unable to build export file.'];
    }

    if (!function_exists('legalpro_send_email')) {
        require_once __DIR__ . '/mail.php';
    }

    $company = function_exists('getCompanyName') ? getCompanyName() : 'LegalPro';
    $subject = $company . ' — Your portal data backup';
    $body = '<p>Your client portal data export was requested.</p>'
        . '<p>Sign in to the client portal and open <strong>My Data Backup</strong> to download the full JSON file.</p>'
        . '<p>If you did not request this, please contact your firm.</p>';

    $result = legalpro_send_email($toEmail, $subject, $body);
    if (empty($result['ok'])) {
        return ['ok' => false, 'message' => (string) ($result['message'] ?? 'Email could not be sent.')];
    }

    return ['ok' => true, 'message' => 'Backup instructions sent to ' . $toEmail . '. Use Download on this page for the full file.'];
}
