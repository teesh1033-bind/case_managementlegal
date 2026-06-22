<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../lib/appointment_availability.php';

if (!isset($_SESSION['client_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$lawyerId = (int) ($_GET['lawyer_id'] ?? 0);
$date = trim((string) ($_GET['date'] ?? ''));

if ($lawyerId <= 0 || $date === '' || strtotime($date) === false) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Lawyer and date are required.']);
    exit;
}

try {
    $maps = loadLawyerAvailabilityForBooking($pdo, [$lawyerId]);
    $slots = legalpro_get_lawyer_slots_for_booking_date($pdo, $lawyerId, $date);

    echo json_encode([
        'ok' => true,
        'lawyer_id' => $lawyerId,
        'date' => $date,
        'slots' => $slots,
        'hasSchedule' => !empty($maps['hasSchedule'][$lawyerId]),
        'hasWorkingHours' => !empty($maps['hasWorkingHours'][$lawyerId]),
        'workingHours' => $maps['workingHours'][$lawyerId] ?? getDefaultWorkingHoursSchedule(),
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not load availability.']);
}
