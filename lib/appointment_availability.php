<?php

function ensureAppointmentSlotColumn(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    try {
        $pdo->query('ALTER TABLE lawyer_time_slots ADD COLUMN appointment_id INT NULL AFTER slot_type');
    } catch (PDOException $e) {
        if (stripos($e->getMessage(), 'duplicate column') === false && stripos($e->getMessage(), 'duplicate column name') === false) {
            throw $e;
        }
    }

    $ready = true;
}

function removeAppointmentAvailabilitySlot(PDO $pdo, int $appointmentId, ?int $lawyerId = null): void
{
    if ($appointmentId <= 0) {
        return;
    }

    ensureAppointmentSlotColumn($pdo);

    if ($lawyerId !== null && $lawyerId > 0) {
        $stmt = $pdo->prepare('DELETE FROM lawyer_time_slots WHERE appointment_id = ? AND lawyer_id = ?');
        $stmt->execute([$appointmentId, $lawyerId]);
        return;
    }

    $stmt = $pdo->prepare('DELETE FROM lawyer_time_slots WHERE appointment_id = ?');
    $stmt->execute([$appointmentId]);
}

function syncAppointmentAvailabilitySlot(PDO $pdo, array $appointment): void
{
    $appointmentId = (int) ($appointment['id'] ?? 0);
    $lawyerId = (int) ($appointment['lawyer_id'] ?? 0);
    $startsAtRaw = $appointment['starts_at'] ?? '';

    if ($appointmentId <= 0 || $lawyerId <= 0 || $startsAtRaw === '') {
        return;
    }

    ensureAppointmentSlotColumn($pdo);

    $status = strtolower((string) ($appointment['status'] ?? 'pending'));
    if ($status === 'rejected') {
        removeAppointmentAvailabilitySlot($pdo, $appointmentId);
        return;
    }

    $startsAt = new DateTime($startsAtRaw);
    $endsAtRaw = $appointment['ends_at'] ?? '';
    $endsAt = $endsAtRaw !== '' ? new DateTime($endsAtRaw) : (clone $startsAt)->modify('+1 hour');

    $slotDate = $startsAt->format('Y-m-d');
    $dayOfWeek = strtolower($startsAt->format('l'));
    $startTime = $startsAt->format('H:i:s');
    $endTime = $endsAt->format('H:i:s');

    $stmt = $pdo->prepare('SELECT id FROM lawyer_time_slots WHERE appointment_id = ? LIMIT 1');
    $stmt->execute([$appointmentId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $stmt = $pdo->prepare("
            UPDATE lawyer_time_slots
            SET lawyer_id = ?, day_of_week = ?, slot_date = ?, start_time = ?, end_time = ?, slot_type = 'unavailable'
            WHERE appointment_id = ?
        ");
        $stmt->execute([$lawyerId, $dayOfWeek, $slotDate, $startTime, $endTime, $appointmentId]);
        return;
    }

    $stmt = $pdo->prepare("
        INSERT INTO lawyer_time_slots (lawyer_id, day_of_week, slot_date, start_time, end_time, slot_type, appointment_id)
        VALUES (?, ?, ?, ?, ?, 'unavailable', ?)
    ");
    $stmt->execute([$lawyerId, $dayOfWeek, $slotDate, $startTime, $endTime, $appointmentId]);
}

function normalizeAppointmentTime(string $appointmentTime): string
{
    $appointmentTime = trim($appointmentTime);
    if (preg_match('/^\d{2}:\d{2}$/', $appointmentTime)) {
        return $appointmentTime . ':00';
    }

    return $appointmentTime;
}

/**
 * Build availability maps for booking UIs (keyed by lawyer id).
 *
 * @return array{byDate: array<int, array<string, list<array{start: string, end: string, type: string}>>>, byDay: array<int, array<string, list<array{start: string, end: string, type: string}>>>, hasSchedule: array<int, bool>}
 */
function loadLawyerAvailabilityForBooking(PDO $pdo, array $lawyerIds): array
{
    $byDate = [];
    $byDay = [];
    $hasSchedule = [];

    foreach ($lawyerIds as $lawyerId) {
        $lawyerId = (int) $lawyerId;
        if ($lawyerId <= 0) {
            continue;
        }
        $byDate[$lawyerId] = [];
        $byDay[$lawyerId] = [];
        $hasSchedule[$lawyerId] = false;
    }

    $lawyerIds = array_values(array_filter(array_map('intval', $lawyerIds)));
    if (empty($lawyerIds)) {
        return ['byDate' => $byDate, 'byDay' => $byDay, 'hasSchedule' => $hasSchedule];
    }

    $placeholders = implode(',', array_fill(0, count($lawyerIds), '?'));
    $stmt = $pdo->prepare("
        SELECT lawyer_id, day_of_week, slot_date, start_time, end_time, slot_type
        FROM lawyer_time_slots
        WHERE lawyer_id IN ($placeholders)
        ORDER BY lawyer_id, slot_date, day_of_week, start_time
    ");
    $stmt->execute($lawyerIds);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $slot) {
        $lawyerId = (int) $slot['lawyer_id'];
        $slotType = $slot['slot_type'] === 'available' ? 'available' : 'unavailable';
        if ($slotType === 'available') {
            $hasSchedule[$lawyerId] = true;
        }

        $entry = [
            'start' => $slot['start_time'],
            'end' => $slot['end_time'],
            'type' => $slotType,
        ];

        $slotDate = isset($slot['slot_date']) ? trim((string) $slot['slot_date']) : '';
        if ($slotDate !== '') {
            if (!isset($byDate[$lawyerId][$slotDate])) {
                $byDate[$lawyerId][$slotDate] = [];
            }
            $byDate[$lawyerId][$slotDate][] = $entry;
            continue;
        }

        $dayKey = strtolower((string) $slot['day_of_week']);
        if (!isset($byDay[$lawyerId][$dayKey])) {
            $byDay[$lawyerId][$dayKey] = [];
        }
        $byDay[$lawyerId][$dayKey][] = $entry;
    }

    return ['byDate' => $byDate, 'byDay' => $byDay, 'hasSchedule' => $hasSchedule];
}

/**
 * Block booking outside published availability or during unavailable slots.
 *
 * @return array{ok: bool, message?: string}
 */
function validateLawyerBookingAvailability(PDO $pdo, int $lawyerId, string $appointmentDate, string $appointmentTime, ?int $excludeAppointmentId = null): array
{
    if ($lawyerId <= 0 || $appointmentDate === '' || $appointmentTime === '') {
        return ['ok' => false, 'message' => 'Lawyer, date, and time are required.'];
    }

    $requestedTime = normalizeAppointmentTime($appointmentTime);
    $dayOfWeek = strtolower(date('l', strtotime($appointmentDate)));
    $startTs = strtotime($appointmentDate . ' ' . $requestedTime);
    if ($startTs === false) {
        return ['ok' => false, 'message' => 'Invalid appointment date or time.'];
    }

    $endTime = date('H:i:s', strtotime('+1 hour', $startTs));

    $unavailableSql = "
        SELECT id FROM lawyer_time_slots
        WHERE lawyer_id = ?
          AND slot_type = 'unavailable'
          AND (
            (slot_date IS NOT NULL AND slot_date = ?)
            OR (slot_date IS NULL AND day_of_week = ?)
          )
          AND start_time < ?
          AND end_time > ?
    ";
    $unavailableParams = [$lawyerId, $appointmentDate, $dayOfWeek, $endTime, $requestedTime];
    if ($excludeAppointmentId !== null && $excludeAppointmentId > 0) {
        $unavailableSql .= ' AND (appointment_id IS NULL OR appointment_id <> ?)';
        $unavailableParams[] = $excludeAppointmentId;
    }
    $unavailableSql .= ' LIMIT 1';

    $stmt = $pdo->prepare($unavailableSql);
    $stmt->execute($unavailableParams);
    if ($stmt->fetch()) {
        return [
            'ok' => false,
            'message' => 'This lawyer is unavailable at the selected date and time. Please choose another slot.',
        ];
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM lawyer_time_slots
        WHERE lawyer_id = ? AND slot_type = 'available'
    ");
    $stmt->execute([$lawyerId]);
    if ((int) $stmt->fetchColumn() === 0) {
        return ['ok' => true];
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM lawyer_time_slots
        WHERE lawyer_id = ?
          AND slot_type = 'available'
          AND (
            (slot_date IS NOT NULL AND slot_date = ?)
            OR (slot_date IS NULL AND day_of_week = ?)
          )
    ");
    $stmt->execute([$lawyerId, $appointmentDate, $dayOfWeek]);
    if ((int) $stmt->fetchColumn() === 0) {
        return [
            'ok' => false,
            'message' => 'This lawyer is not available on the selected date. Please choose another date.',
        ];
    }

    $stmt = $pdo->prepare("
        SELECT id FROM lawyer_time_slots
        WHERE lawyer_id = ?
          AND slot_type = 'available'
          AND (
            (slot_date IS NOT NULL AND slot_date = ?)
            OR (slot_date IS NULL AND day_of_week = ?)
          )
          AND start_time <= ?
          AND end_time > ?
        LIMIT 1
    ");
    $stmt->execute([$lawyerId, $appointmentDate, $dayOfWeek, $requestedTime, $requestedTime]);
    if (!$stmt->fetch()) {
        return [
            'ok' => false,
            'message' => 'This lawyer is not available at the selected time. Please choose a time within their published availability.',
        ];
    }

    return ['ok' => true];
}

function backfillLawyerAppointmentAvailability(PDO $pdo, int $lawyerId): void
{
    if ($lawyerId <= 0) {
        return;
    }

    ensureAppointmentSlotColumn($pdo);

    $stmt = $pdo->prepare("
        SELECT a.*
        FROM appointments a
        LEFT JOIN lawyer_time_slots l ON l.appointment_id = a.id
        WHERE a.lawyer_id = ?
          AND a.starts_at IS NOT NULL
          AND LOWER(COALESCE(a.status, 'pending')) <> 'rejected'
          AND l.id IS NULL
    ");
    $stmt->execute([$lawyerId]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $appointment) {
        syncAppointmentAvailabilitySlot($pdo, $appointment);
    }
}
