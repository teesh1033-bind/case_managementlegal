<?php
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../lib/client-portal-features.php';

if (!isset($_SESSION['client_id'])) {
    http_response_code(403);
    exit('Access denied');
}

$clientId = (int) $_SESSION['client_id'];
$type = strtolower(trim((string) ($_GET['type'] ?? '')));
$id = (int) ($_GET['id'] ?? 0);

if ($id <= 0 || !in_array($type, ['appointment', 'court'], true)) {
    http_response_code(400);
    exit('Invalid request');
}

try {
    if ($type === 'appointment') {
        $stmt = $pdo->prepare("
            SELECT a.*, c.title AS case_title
            FROM appointments a
            LEFT JOIN cases c ON c.id = a.case_id
            WHERE a.id = ? AND a.client_id = ?
        ");
        $stmt->execute([$id, $clientId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            http_response_code(404);
            exit('Not found');
        }

        $title = 'Appointment: ' . ($row['case_title'] ?: 'Legal consultation');
        $desc = (string) ($row['notes'] ?? '');
        $ics = legalpro_client_generate_ics_content(
            'appointment-' . $id,
            $title,
            (string) $row['starts_at'],
            $row['ends_at'] ?: null,
            $desc
        );
        $filename = 'appointment-' . $id . '.ics';
    } else {
        $stmt = $pdo->prepare("
            SELECT cd.*, c.title AS case_title
            FROM court_dates cd
            INNER JOIN cases c ON c.id = cd.case_id
            WHERE cd.id = ? AND c.client_id = ?
        ");
        $stmt->execute([$id, $clientId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            http_response_code(404);
            exit('Not found');
        }

        $startAt = (string) $row['court_date'];
        $title = ($row['title'] ?: 'Court hearing') . ': ' . $row['case_title'];
        $desc = (string) ($row['description'] ?? '');
        $location = (string) ($row['location'] ?? '');
        $ics = legalpro_client_generate_ics_content(
            'court-' . $id,
            $title,
            $startAt,
            null,
            $desc,
            $location
        );
        $filename = 'hearing-' . $id . '.ics';
    }

    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, must-revalidate');
    echo $ics;
} catch (PDOException $e) {
    http_response_code(500);
    exit('Error generating calendar file');
}
