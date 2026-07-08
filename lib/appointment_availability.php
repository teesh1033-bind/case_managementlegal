<?php

const LAWYER_WEEK_DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

/** Maximum clients who may book the same lawyer in an overlapping time window. */
const LAWYER_APPOINTMENT_SLOT_CAPACITY = 2;

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
    removeAppointmentAvailabilitySlot($pdo, $appointmentId, $lawyerId);

    if ($status === 'rejected' || $status === 'cancelled') {
        return;
    }

    // Capacity is enforced by counting appointments — do not block the lawyer after one booking.
}

function normalizeAppointmentTime(string $appointmentTime): string
{
    $appointmentTime = trim($appointmentTime);
    if (preg_match('/^\d{2}:\d{2}$/', $appointmentTime)) {
        return $appointmentTime . ':00';
    }

    return $appointmentTime;
}

function normalizeAvailabilityTime(string $time): string
{
    $time = trim($time);
    if (preg_match('/^\d{2}:\d{2}$/', $time)) {
        return $time . ':00';
    }

    return $time;
}

function ensureLawyerWorkingHoursTable(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `lawyer_availability` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `lawyer_id` INT NOT NULL,
            `day_of_week` ENUM('monday','tuesday','wednesday','thursday','friday','saturday','sunday') NOT NULL,
            `start_time` TIME NOT NULL,
            `end_time` TIME NOT NULL,
            `is_available` TINYINT(1) DEFAULT 1,
            UNIQUE KEY `unique_lawyer_day` (`lawyer_id`, `day_of_week`),
            FOREIGN KEY (`lawyer_id`) REFERENCES `lawyers`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $ready = true;
}

function getDefaultWorkingHoursSchedule(): array
{
    $schedule = [];
    foreach (LAWYER_WEEK_DAYS as $day) {
        $schedule[$day] = [
            'enabled' => in_array($day, ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'], true),
            'start' => '09:00:00',
            'end' => '17:00:00',
        ];
    }

    return $schedule;
}

function getLawyerWorkingHours(PDO $pdo, int $lawyerId): array
{
    ensureLawyerWorkingHoursTable($pdo);
    $schedule = getDefaultWorkingHoursSchedule();

    if ($lawyerId <= 0) {
        return $schedule;
    }

    $stmt = $pdo->prepare('SELECT day_of_week, start_time, end_time, is_available FROM lawyer_availability WHERE lawyer_id = ?');
    $stmt->execute([$lawyerId]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $day = strtolower((string) $row['day_of_week']);
        if (!isset($schedule[$day])) {
            continue;
        }
        $schedule[$day] = [
            'enabled' => (bool) $row['is_available'],
            'start' => normalizeAvailabilityTime((string) $row['start_time']),
            'end' => normalizeAvailabilityTime((string) $row['end_time']),
        ];
    }

    return $schedule;
}

function lawyerHasSavedWorkingHours(PDO $pdo, int $lawyerId): bool
{
    if ($lawyerId <= 0) {
        return false;
    }

    ensureLawyerWorkingHoursTable($pdo);
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM lawyer_availability WHERE lawyer_id = ?');
    $stmt->execute([$lawyerId]);

    return (int) $stmt->fetchColumn() > 0;
}

function saveLawyerWorkingHours(PDO $pdo, int $lawyerId, array $postedDays): void
{
    if ($lawyerId <= 0) {
        throw new InvalidArgumentException('Invalid lawyer id.');
    }

    ensureLawyerWorkingHoursTable($pdo);
    $pdo->prepare('DELETE FROM lawyer_availability WHERE lawyer_id = ?')->execute([$lawyerId]);

    $insert = $pdo->prepare('
        INSERT INTO lawyer_availability (lawyer_id, day_of_week, start_time, end_time, is_available)
        VALUES (?, ?, ?, ?, ?)
    ');

    foreach (LAWYER_WEEK_DAYS as $day) {
        $dayData = $postedDays[$day] ?? [];
        $enabled = !empty($dayData['enabled']);
        $start = normalizeAvailabilityTime((string) ($dayData['start'] ?? '09:00'));
        $end = normalizeAvailabilityTime((string) ($dayData['end'] ?? '17:00'));

        if (strtotime($start) >= strtotime($end)) {
            throw new InvalidArgumentException('End time must be after start time for ' . ucfirst($day) . '.');
        }

        $insert->execute([$lawyerId, $day, $start, $end, $enabled ? 1 : 0]);
    }
}

function isAppointmentWithinWorkingHours(array $workingHours, string $dayOfWeek, string $requestedTime, string $endTime): bool
{
    $day = strtolower($dayOfWeek);
    if (!isset($workingHours[$day]) || empty($workingHours[$day]['enabled'])) {
        return false;
    }

    $requestedTime = normalizeAvailabilityTime($requestedTime);
    $endTime = normalizeAvailabilityTime($endTime);

    return $requestedTime >= $workingHours[$day]['start'] && $endTime <= $workingHours[$day]['end'];
}

function isSlotWithinWorkingHours(array $workingHours, string $dayOfWeek, string $startTime, string $endTime): bool
{
    return isAppointmentWithinWorkingHours($workingHours, $dayOfWeek, $startTime, $endTime);
}

function lawyerHasExplicitSlotsOnDate(PDO $pdo, int $lawyerId, string $appointmentDate): bool
{
    if ($lawyerId <= 0 || $appointmentDate === '') {
        return false;
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM lawyer_time_slots WHERE lawyer_id = ? AND slot_date IS NOT NULL AND slot_date = ?');
    $stmt->execute([$lawyerId, $appointmentDate]);

    return (int) $stmt->fetchColumn() > 0;
}

function loadLawyerWorkingHoursForBooking(PDO $pdo, array $lawyerIds): array
{
    $workingHours = [];
    $hasWorkingHours = [];

    foreach ($lawyerIds as $lawyerId) {
        $lawyerId = (int) $lawyerId;
        if ($lawyerId <= 0) {
            continue;
        }
        $workingHours[$lawyerId] = getLawyerWorkingHours($pdo, $lawyerId);
        $hasWorkingHours[$lawyerId] = lawyerHasSavedWorkingHours($pdo, $lawyerId);
    }

    return ['workingHours' => $workingHours, 'hasWorkingHours' => $hasWorkingHours];
}

function formatSlotTimeForBooking(string $time): string
{
    $parts = explode(':', trim($time));
    $hours = (int) ($parts[0] ?? 0);
    $minutes = (int) ($parts[1] ?? 0);
    $seconds = (int) ($parts[2] ?? 0);

    return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
}

/**
 * Build availability maps for booking UIs (keyed by lawyer id).
 *
 * @return array{byDate: array<int, array<string, list<array{start: string, end: string, type: string}>>>, byDay: array<int, array<string, list<array{start: string, end: string, type: string}>>>, hasSchedule: array<int, bool>, workingHours: array<int, array<string, array{enabled: bool, start: string, end: string}>>, hasWorkingHours: array<int, bool>}
 */
function loadLawyerAvailabilityForBooking(PDO $pdo, array $lawyerIds): array
{
    cleanupLegacyAppointmentSlotBlocks($pdo);

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
    $workingHourMaps = loadLawyerWorkingHoursForBooking($pdo, $lawyerIds);

    if (empty($lawyerIds)) {
        return [
            'byDate' => $byDate,
            'byDay' => $byDay,
            'hasSchedule' => $hasSchedule,
            'workingHours' => $workingHourMaps['workingHours'],
            'hasWorkingHours' => $workingHourMaps['hasWorkingHours'],
        ];
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
        $slotDateRaw = isset($slot['slot_date']) ? trim((string) $slot['slot_date']) : '';
        $slotDate = $slotDateRaw;
        if ($slotDateRaw !== '') {
            $slotTimestamp = strtotime($slotDateRaw);
            if ($slotTimestamp !== false) {
                $slotDate = date('Y-m-d', $slotTimestamp);
            }
        }
        if ($slotType === 'available' && $slotDate !== '') {
            $hasSchedule[$lawyerId] = true;
        }

        $entry = [
            'start' => formatSlotTimeForBooking((string) $slot['start_time']),
            'end' => formatSlotTimeForBooking((string) $slot['end_time']),
            'type' => $slotType,
        ];

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

    return [
        'byDate' => $byDate,
        'byDay' => $byDay,
        'hasSchedule' => $hasSchedule,
        'workingHours' => $workingHourMaps['workingHours'],
        'hasWorkingHours' => $workingHourMaps['hasWorkingHours'],
    ];
}

function cleanupLegacyAppointmentSlotBlocks(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        ensureAppointmentSlotColumn($pdo);
        $pdo->exec('DELETE FROM lawyer_time_slots WHERE appointment_id IS NOT NULL');
    } catch (PDOException $e) {
        // Capacity is enforced via appointment counts.
    }
}

function legalpro_lawyer_slot_capacity_message(): string
{
    return 'This time slot is fully booked. A maximum of '
        . LAWYER_APPOINTMENT_SLOT_CAPACITY
        . ' clients can book the same lawyer at the same time.';
}

/**
 * Row-locked capacity check — call inside an open transaction before INSERT/UPDATE.
 *
 * @return array{ok: bool, message?: string}
 */
function legalpro_assert_lawyer_slot_capacity_locked(
    PDO $pdo,
    int $lawyerId,
    string $startsAt,
    string $endsAt,
    ?int $excludeAppointmentId = null
): array {
    if ($lawyerId <= 0 || $startsAt === '' || $endsAt === '') {
        return ['ok' => false, 'message' => 'Lawyer, date, and time are required.'];
    }

    cleanupLegacyAppointmentSlotBlocks($pdo);

    $sql = "
        SELECT id FROM appointments
        WHERE lawyer_id = ?
          AND LOWER(COALESCE(status, 'pending')) NOT IN ('rejected', 'cancelled')
          AND starts_at IS NOT NULL
          AND starts_at < ?
          AND COALESCE(ends_at, DATE_ADD(starts_at, INTERVAL 1 HOUR)) > ?
    ";
    $params = [$lawyerId, $endsAt, $startsAt];

    if ($excludeAppointmentId !== null && $excludeAppointmentId > 0) {
        $sql .= ' AND id <> ?';
        $params[] = $excludeAppointmentId;
    }

    $sql .= ' FOR UPDATE';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $count = count($stmt->fetchAll(PDO::FETCH_COLUMN));

    if ($count >= LAWYER_APPOINTMENT_SLOT_CAPACITY) {
        return ['ok' => false, 'message' => legalpro_lawyer_slot_capacity_message()];
    }

    return ['ok' => true];
}

/**
 * @return array{ok: bool, message?: string}
 */
function legalpro_assert_lawyer_slot_capacity_for_booking(
    PDO $pdo,
    int $lawyerId,
    string $appointmentDate,
    string $appointmentTime,
    int $durationMinutes = 60,
    ?int $excludeAppointmentId = null
): array {
    $durationMinutes = in_array($durationMinutes, [30, 60], true) ? $durationMinutes : 60;
    $requestedTime = normalizeAppointmentTime($appointmentTime);
    $startTs = strtotime($appointmentDate . ' ' . $requestedTime);
    if ($startTs === false) {
        return ['ok' => false, 'message' => 'Invalid appointment date or time.'];
    }

    $startsAt = date('Y-m-d H:i:s', $startTs);
    $endsAt = date('Y-m-d H:i:s', strtotime('+' . $durationMinutes . ' minutes', $startTs));

    return legalpro_assert_lawyer_slot_capacity_locked($pdo, $lawyerId, $startsAt, $endsAt, $excludeAppointmentId);
}

/**
 * @template T
 * @param callable(): T $callback
 * @return T
 */
function legalpro_with_locked_lawyer_booking(PDO $pdo, callable $callback)
{
    $pdo->beginTransaction();

    try {
        $result = $callback();
        if (is_array($result) && array_key_exists('ok', $result) && empty($result['ok'])) {
            $pdo->rollBack();

            return $result;
        }

        $pdo->commit();

        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}

function legalpro_merge_unavailable_slot(array &$slots, string $busyStart, string $busyEnd): void
{
    foreach ($slots as $slot) {
        if (($slot['type'] ?? '') === 'unavailable'
            && ($slot['start'] ?? '') === $busyStart
            && ($slot['end'] ?? '') === $busyEnd) {
            return;
        }
    }

    $slots[] = [
        'start' => $busyStart,
        'end' => $busyEnd,
        'type' => 'unavailable',
    ];
}

/**
 * Mark times on a date unavailable when the lawyer is at booking capacity.
 */
function legalpro_append_capacity_blocked_slots(
    PDO $pdo,
    int $lawyerId,
    string $date,
    array $slots,
    int $durationMinutes = 60
): array {
    if ($lawyerId <= 0 || $date === '') {
        return $slots;
    }

    $durationMinutes = in_array($durationMinutes, [30, 60], true) ? $durationMinutes : 60;
    $candidateTimes = [];

    $stmt = $pdo->prepare("
        SELECT starts_at
        FROM appointments
        WHERE lawyer_id = ?
          AND DATE(starts_at) = ?
          AND LOWER(COALESCE(status, 'pending')) NOT IN ('rejected', 'cancelled')
          AND starts_at IS NOT NULL
    ");
    $stmt->execute([$lawyerId, $date]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $startsAtRaw) {
        $ts = strtotime((string) $startsAtRaw);
        if ($ts !== false) {
            $candidateTimes[] = date('H:i:s', $ts);
        }
    }

    for ($hour = 8; $hour <= 18; $hour++) {
        foreach (['00', '30'] as $minute) {
            $candidateTimes[] = sprintf('%02d:%s:00', $hour, $minute);
        }
    }

    foreach (array_unique($candidateTimes) as $timeValue) {
        $startsAt = $date . ' ' . normalizeAvailabilityTime($timeValue);
        $endsAt = date('Y-m-d H:i:s', strtotime('+' . $durationMinutes . ' minutes', strtotime($startsAt)));
        if (countOverlappingAppointments($pdo, $lawyerId, $startsAt, $endsAt) >= LAWYER_APPOINTMENT_SLOT_CAPACITY) {
            legalpro_merge_unavailable_slot(
                $slots,
                formatSlotTimeForBooking(date('H:i:s', strtotime($startsAt))),
                formatSlotTimeForBooking(date('H:i:s', strtotime($endsAt)))
            );
        }
    }

    return $slots;
}

/**
 * Merge appointment busy blocks into slot list for a specific date.
 */
function appendAppointmentBusySlots(PDO $pdo, int $lawyerId, string $date, array $slots): array
{
    if ($lawyerId <= 0 || $date === '') {
        return $slots;
    }

    return legalpro_append_capacity_blocked_slots($pdo, $lawyerId, $date, $slots, 60);
}

/**
 * Slots for one lawyer on one date (matches client booking UI rules).
 */
function legalpro_get_lawyer_slots_for_booking_date(PDO $pdo, int $lawyerId, string $date): array
{
    if ($lawyerId <= 0 || $date === '') {
        return [];
    }

    $maps = loadLawyerAvailabilityForBooking($pdo, [$lawyerId]);
    $byDate = $maps['byDate'][$lawyerId] ?? [];
    $byDay = $maps['byDay'][$lawyerId] ?? [];
    $dayKey = strtolower(date('l', strtotime($date)));

    if (!empty($byDate[$date])) {
        $slots = $byDate[$date];
    } else {
        $slots = $byDate[$date] ?? [];
        if (!empty($byDay[$dayKey])) {
            $slots = array_merge($slots, $byDay[$dayKey]);
        }
    }

    return appendAppointmentBusySlots($pdo, $lawyerId, $date, $slots);
}

/**
 * Count non-rejected appointments overlapping the requested window.
 */
function countOverlappingAppointments(
    PDO $pdo,
    int $lawyerId,
    string $startsAt,
    string $endsAt,
    ?int $excludeAppointmentId = null
): int {
    if ($lawyerId <= 0 || $startsAt === '' || $endsAt === '') {
        return 0;
    }

    $sql = "
        SELECT COUNT(*) FROM appointments
        WHERE lawyer_id = ?
          AND LOWER(COALESCE(status, 'pending')) NOT IN ('rejected', 'cancelled')
          AND starts_at IS NOT NULL
          AND starts_at < ?
          AND COALESCE(ends_at, DATE_ADD(starts_at, INTERVAL 1 HOUR)) > ?
    ";
    $params = [$lawyerId, $endsAt, $startsAt];

    if ($excludeAppointmentId !== null && $excludeAppointmentId > 0) {
        $sql .= ' AND id <> ?';
        $params[] = $excludeAppointmentId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (int) $stmt->fetchColumn();
}

/**
 * True when the lawyer's slot is at capacity for the requested window.
 */
function lawyerHasOverlappingAppointment(
    PDO $pdo,
    int $lawyerId,
    string $appointmentDate,
    string $appointmentTime,
    int $durationMinutes = 60,
    ?int $excludeAppointmentId = null
): bool {
    if ($lawyerId <= 0) {
        return false;
    }

    $durationMinutes = in_array($durationMinutes, [30, 60], true) ? $durationMinutes : 60;
    $requestedTime = normalizeAppointmentTime($appointmentTime);
    $startTs = strtotime($appointmentDate . ' ' . $requestedTime);
    if ($startTs === false) {
        return false;
    }

    $startsAt = date('Y-m-d H:i:s', $startTs);
    $endsAt = date('Y-m-d H:i:s', strtotime('+' . $durationMinutes . ' minutes', $startTs));

    return countOverlappingAppointments($pdo, $lawyerId, $startsAt, $endsAt, $excludeAppointmentId)
        >= LAWYER_APPOINTMENT_SLOT_CAPACITY;
}

/**
 * True when the lawyer has published at least one date-specific available slot.
 */
function lawyerHasPublishedAvailabilitySchedule(PDO $pdo, int $lawyerId): bool
{
    if ($lawyerId <= 0) {
        return false;
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM lawyer_time_slots
        WHERE lawyer_id = ?
          AND slot_type = 'available'
          AND slot_date IS NOT NULL
    ");
    $stmt->execute([$lawyerId]);

    return (int) $stmt->fetchColumn() > 0;
}

/**
 * Block booking outside published availability or during unavailable slots.
 * When no availability is published, the lawyer is treated as available by default
 * (only unavailable slots and overlapping appointments are enforced).
 *
 * @return array{ok: bool, message?: string}
 */
function validateLawyerBookingAvailability(PDO $pdo, int $lawyerId, string $appointmentDate, string $appointmentTime, ?int $excludeAppointmentId = null, int $durationMinutes = 60): array
{
    cleanupLegacyAppointmentSlotBlocks($pdo);

    if ($lawyerId <= 0 || $appointmentDate === '' || $appointmentTime === '') {
        return ['ok' => false, 'message' => 'Lawyer, date, and time are required.'];
    }

    $durationMinutes = in_array($durationMinutes, [30, 60], true) ? $durationMinutes : 60;

    $requestedTime = normalizeAppointmentTime($appointmentTime);
    $dayOfWeek = strtolower(date('l', strtotime($appointmentDate)));
    $startTs = strtotime($appointmentDate . ' ' . $requestedTime);
    if ($startTs === false) {
        return ['ok' => false, 'message' => 'Invalid appointment date or time.'];
    }

    $endTime = date('H:i:s', strtotime('+' . $durationMinutes . ' minutes', $startTs));
    $hasWorkingHours = lawyerHasSavedWorkingHours($pdo, $lawyerId);
    $workingHours = $hasWorkingHours ? getLawyerWorkingHours($pdo, $lawyerId) : getDefaultWorkingHoursSchedule();

    if ($hasWorkingHours && !isAppointmentWithinWorkingHours($workingHours, $dayOfWeek, $requestedTime, $endTime)) {
        $daySchedule = $workingHours[$dayOfWeek] ?? null;
        if (!$daySchedule || empty($daySchedule['enabled'])) {
            return [
                'ok' => false,
                'message' => 'This lawyer does not work on the selected day. Please choose another date.',
            ];
        }

        return [
            'ok' => false,
            'message' => 'Selected time is outside the lawyer\'s working hours. Please choose a time within their schedule.',
        ];
    }

    $unavailableSql = "
        SELECT id FROM lawyer_time_slots
        WHERE lawyer_id = ?
          AND slot_type = 'unavailable'
    ";
    $unavailableParams = [$lawyerId];

    if (lawyerHasExplicitSlotsOnDate($pdo, $lawyerId, $appointmentDate)) {
        $unavailableSql .= ' AND slot_date = ?';
        $unavailableParams[] = $appointmentDate;
    } else {
        $unavailableSql .= ' AND (
            (slot_date IS NOT NULL AND slot_date = ?)
            OR (slot_date IS NULL AND day_of_week = ?)
        )';
        $unavailableParams[] = $appointmentDate;
        $unavailableParams[] = $dayOfWeek;
    }

    $unavailableSql .= '
          AND start_time < ?
          AND end_time > ?
    ';
    $unavailableParams[] = $endTime;
    $unavailableParams[] = $requestedTime;
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
        WHERE lawyer_id = ?
          AND slot_type = 'available'
          AND slot_date IS NOT NULL
          AND slot_date = ?
    ");
    $stmt->execute([$lawyerId, $appointmentDate]);
    $hasExplicitAvailableOnDate = (int) $stmt->fetchColumn() > 0;

    if ($hasExplicitAvailableOnDate) {
        $stmt = $pdo->prepare("
            SELECT id FROM lawyer_time_slots
            WHERE lawyer_id = ?
              AND slot_type = 'available'
              AND slot_date IS NOT NULL
              AND slot_date = ?
              AND start_time <= ?
              AND end_time >= ?
            LIMIT 1
        ");
        $stmt->execute([$lawyerId, $appointmentDate, $requestedTime, $requestedTime]);
        if (!$stmt->fetch()) {
            return [
                'ok' => false,
                'message' => 'This lawyer is not available at the selected time. Please choose a time within their published availability.',
            ];
        }
    } elseif (!$hasWorkingHours && lawyerHasPublishedAvailabilitySchedule($pdo, $lawyerId)) {
        return [
            'ok' => false,
            'message' => 'No available times on this date. Choose another date.',
        ];
    } elseif (!$hasWorkingHours && !lawyerHasPublishedAvailabilitySchedule($pdo, $lawyerId)) {
        if (!isAppointmentWithinWorkingHours(getDefaultWorkingHoursSchedule(), $dayOfWeek, $requestedTime, $endTime)) {
            $daySchedule = getDefaultWorkingHoursSchedule()[$dayOfWeek] ?? null;
            if (!$daySchedule || empty($daySchedule['enabled'])) {
                return [
                    'ok' => false,
                    'message' => 'This lawyer is not available on the selected day. Please choose a weekday between 9:00 AM and 5:00 PM.',
                ];
            }

            return [
                'ok' => false,
                'message' => 'Selected time is outside standard business hours (9:00 AM – 5:00 PM). Please choose another time.',
            ];
        }

        if (lawyerHasOverlappingAppointment($pdo, $lawyerId, $appointmentDate, $appointmentTime, $durationMinutes, $excludeAppointmentId)) {
            return [
                'ok' => false,
                'message' => legalpro_lawyer_slot_capacity_message(),
            ];
        }

        return ['ok' => true];
    }

    if (lawyerHasOverlappingAppointment($pdo, $lawyerId, $appointmentDate, $appointmentTime, $durationMinutes, $excludeAppointmentId)) {
        return [
            'ok' => false,
            'message' => legalpro_lawyer_slot_capacity_message(),
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

    // Legacy rows blocked the lawyer after a single booking — remove them.
    $stmt = $pdo->prepare('DELETE FROM lawyer_time_slots WHERE lawyer_id = ? AND appointment_id IS NOT NULL');
    $stmt->execute([$lawyerId]);
}