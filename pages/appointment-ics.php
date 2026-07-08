<?php
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../lib/appointment_list_ui.php';

if (!isset($_SESSION['admin_id']) && !isset($_SESSION['lawyer_id']) && !isset($_SESSION['client_id'])) {
    http_response_code(401);
    exit('Unauthorized');
}

$appointmentId = (int) ($_GET['id'] ?? 0);
if ($appointmentId <= 0) {
    http_response_code(400);
    exit('Invalid appointment');
}

$stmt = $pdo->prepare("
    SELECT a.*, cs.title AS case_title, cl.first_name AS client_first_name, cl.last_name AS client_last_name
    FROM appointments a
    LEFT JOIN cases cs ON cs.id = a.case_id
    LEFT JOIN clients cl ON cl.id = cs.client_id
    WHERE a.id = ?
");
$stmt->execute([$appointmentId]);
$appointment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$appointment) {
    http_response_code(404);
    exit('Appointment not found');
}

$startsAt = !empty($appointment['starts_at']) ? strtotime((string) $appointment['starts_at']) : false;
if ($startsAt === false) {
    http_response_code(400);
    exit('Appointment has no start time');
}

$endsAtRaw = $appointment['ends_at'] ?? '';
$endsAt = $endsAtRaw !== '' ? strtotime((string) $endsAtRaw) : strtotime('+1 hour', $startsAt);
if ($endsAt === false || $endsAt <= $startsAt) {
    $endsAt = strtotime('+1 hour', $startsAt);
}

$title = admin_appointment_title($appointment);
    $description = trim((string) ($appointment['notes'] ?? ''));
    $clientName = trim(($appointment['client_first_name'] ?? '') . ' ' . ($appointment['client_last_name'] ?? ''));
    if ($clientName !== '') {
        $description = 'Client: ' . $clientName . ($description !== '' ? ' - ' . $description : '');
    }

$uid = 'legalpro-appointment-' . $appointmentId . '@legalpro';
$dtStamp = gmdate('Ymd\THis\Z');
$dtStart = gmdate('Ymd\THis\Z', $startsAt);
$dtEnd = gmdate('Ymd\THis\Z', $endsAt);

$ics = "BEGIN:VCALENDAR\r\n"
    . "VERSION:2.0\r\n"
    . "PRODID:-//LegalPro//Appointments//EN\r\n"
    . "CALSCALE:GREGORIAN\r\n"
    . "METHOD:PUBLISH\r\n"
    . "BEGIN:VEVENT\r\n"
    . "UID:" . $uid . "\r\n"
    . "DTSTAMP:" . $dtStamp . "\r\n"
    . "DTSTART:" . $dtStart . "\r\n"
    . "DTEND:" . $dtEnd . "\r\n"
    . "SUMMARY:" . str_replace(["\r", "\n", ',', ';'], ['', ' ', '\\,', '\\;'], $title) . "\r\n"
    . "DESCRIPTION:" . str_replace(["\r", "\n", ',', ';'], ['', '\\n', '\\,', '\\;'], $description) . "\r\n"
    . "END:VEVENT\r\n"
    . "END:VCALENDAR\r\n";

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="appointment-' . $appointmentId . '.ics"');
echo $ics;
