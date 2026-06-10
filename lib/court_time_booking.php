<?php
/**
 * Court date/time slot conflict checks.
 */

if (!function_exists('legalpro_normalize_time_hm')) {
    require_once __DIR__ . '/../inc/court-time-picker.php';
}

function legalpro_court_blocking_statuses(): array
{
    return ['scheduled'];
}

function legalpro_court_booking_entries_from_rows(array $courtDates): array
{
    $entries = [];

    foreach ($courtDates as $row) {
        $ts = !empty($row['court_date']) ? strtotime((string) $row['court_date']) : false;
        if ($ts === false) {
            continue;
        }

        $entries[] = [
            'id' => (int) ($row['id'] ?? 0),
            'date' => date('Y-m-d', $ts),
            'time' => date('H:i', $ts),
            'status' => strtolower(trim((string) ($row['status'] ?? 'scheduled'))),
        ];
    }

    return $entries;
}

function legalpro_is_court_slot_booked(PDO $pdo, string $dateYmd, string $timeHm, ?int $excludeId = null): bool
{
    $dateYmd = trim($dateYmd);
    $timeHm = legalpro_normalize_time_hm($timeHm);

    if ($dateYmd === '' || $timeHm === '') {
        return false;
    }

    $sql = "
        SELECT COUNT(*)
        FROM court_dates
        WHERE DATE(court_date) = ?
          AND TIME_FORMAT(court_date, '%H:%i') = ?
          AND LOWER(COALESCE(status, 'scheduled')) = 'scheduled'
    ";
    $params = [$dateYmd, $timeHm];

    if ($excludeId !== null && $excludeId > 0) {
        $sql .= ' AND id <> ?';
        $params[] = $excludeId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (int) $stmt->fetchColumn() > 0;
}

function legalpro_validate_court_booking_datetime(
    PDO $pdo,
    string $dateYmd,
    string $timeHm,
    ?int $excludeId = null,
    ?string $status = 'scheduled'
): array {
    $status = strtolower(trim((string) $status));
    if (!in_array($status, legalpro_court_blocking_statuses(), true)) {
        return ['ok' => true, 'message' => ''];
    }

    $dateYmd = trim($dateYmd);
    $timeHm = legalpro_normalize_time_hm($timeHm);

    if ($dateYmd === '' || $timeHm === '') {
        return ['ok' => false, 'message' => 'Court date and time are required.'];
    }

    if (legalpro_is_court_slot_booked($pdo, $dateYmd, $timeHm, $excludeId)) {
        return [
            'ok' => false,
            'message' => 'That time is already booked for another court date. Please choose a different slot.',
        ];
    }

    return ['ok' => true, 'message' => ''];
}
