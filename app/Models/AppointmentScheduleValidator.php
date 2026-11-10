<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

/**
 * Authoritative Server-Side Appointment Scheduling & Overlap Validator.
 *
 * Enforces mandatory scheduling rules for Grooming Services and Veterinary Clinic:
 * - Rule A: Two appointments must NEVER start at the exact same time within the same business unit.
 * - Rule B: Actual interval overlap MAX(0, MIN(endA, endB) - MAX(startA, startB)) must not exceed
 *           the business unit's configured maximum allowed overlap.
 * - Rule C: Symmetrical check in both directions (whether new appointment starts before or after).
 * - Rule D: End times use actual service durations (individual, multiple, packages, add-ons).
 * - Concurrency: Locks the business_settings row (FOR UPDATE) inside transactions to prevent race conditions.
 */
class AppointmentScheduleValidator
{
    public const DEFAULT_MAX_OVERLAP_MINUTES = 15;

    /**
     * Retrieve the configured maximum appointment overlap for a business unit.
     */
    public static function getMaxOverlapMinutes(PDO $db, int $businessUnitId): int
    {
        try {
            $stmt = $db->prepare("SELECT max_appointment_overlap_minutes FROM business_settings WHERE business_unit_id = ? LIMIT 1");
            $stmt->execute([$businessUnitId]);
            $val = $stmt->fetchColumn();
            if ($val !== false && $val !== null && is_numeric($val) && (int)$val >= 0) {
                return (int)$val;
            }
        } catch (\Throwable $e) {
            // Fallback gracefully to default
        }
        return self::DEFAULT_MAX_OVERLAP_MINUTES;
    }

    /**
     * Update the maximum appointment overlap for a business unit.
     */
    public static function updateMaxOverlapMinutes(PDO $db, int $businessUnitId, int $minutes): void
    {
        if ($minutes < 0) {
            throw new \InvalidArgumentException('Maximum Appointment Overlap must be a non-negative whole number.');
        }

        $stmt = $db->prepare("UPDATE business_settings SET max_appointment_overlap_minutes = ?, updated_at = NOW() WHERE business_unit_id = ?");
        $stmt->execute([$minutes, $businessUnitId]);
    }

    /**
     * Calculate estimated total duration for a list of service IDs.
     */
    public static function calculateServiceDuration(PDO $db, array $serviceIds): int
    {
        $cleanIds = array_values(array_unique(array_filter(array_map('intval', $serviceIds), fn($id) => $id > 0)));
        if (empty($cleanIds)) {
            return 30;
        }

        $ph = implode(',', array_fill(0, count($cleanIds), '?'));
        $stmt = $db->prepare("SELECT service_id, estimated_duration_minutes FROM service WHERE service_id IN ($ph)");
        $stmt->execute($cleanIds);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totalMinutes = 0;
        foreach ($rows as $r) {
            $dur = (int)($r['estimated_duration_minutes'] ?? 0);
            $totalMinutes += max(15, $dur);
        }

        return max(15, $totalMinutes);
    }

    /**
     * Authoritatively validate appointment timing and overlap constraints.
     *
     * @param PDO $db
     * @param int $businessUnitId Business unit (2 = Vet Clinic, 3 = Grooming Services)
     * @param string $date Date formatted as 'Y-m-d'
     * @param string $startTime Time formatted as 'H:i' or 'H:i:s'
     * @param int $durationMinutes Duration of the appointment in minutes
     * @param int $excludeAppointmentId Appointment ID to exclude from self-comparison
     * @param int|null $maxOverlapMinutes Override limit, or null to load saved setting
     * @param bool $forUpdate Set true inside database transaction to acquire exclusive lock
     * @return array [
     *     'valid' => bool,
     *     'error' => ?string,
     *     'rule' => ?string ('same_start' | 'max_overlap'),
     *     'conflict_id' => ?int,
     *     'overlap_minutes' => ?int,
     *     'max_allowed' => ?int,
     * ]
     */
    public static function validateAppointment(
        PDO $db,
        int $businessUnitId,
        string $date,
        string $startTime,
        int $durationMinutes,
        int $excludeAppointmentId = 0,
        ?int $maxOverlapMinutes = null,
        bool $forUpdate = false
    ): array {
        // Concurrency Control: Serialize confirmation/booking within the business unit
        if ($forUpdate && $db->inTransaction()) {
            $lockStmt = $db->prepare("SELECT setting_id, max_appointment_overlap_minutes FROM business_settings WHERE business_unit_id = ? FOR UPDATE");
            $lockStmt->execute([$businessUnitId]);
            $lockedRow = $lockStmt->fetch(PDO::FETCH_ASSOC);
            if ($maxOverlapMinutes === null && $lockedRow && isset($lockedRow['max_appointment_overlap_minutes'])) {
                $maxOverlapMinutes = (int)$lockedRow['max_appointment_overlap_minutes'];
            }
        }

        if ($maxOverlapMinutes === null) {
            $maxOverlapMinutes = self::getMaxOverlapMinutes($db, $businessUnitId);
        }

        // Clean time format
        if (preg_match('/^\d{1,2}:\d{2}$/', $startTime)) {
            $startTime .= ':00';
        }

        $targetStartTs = strtotime("$date $startTime");
        if ($targetStartTs === false) {
            return [
                'valid' => false,
                'error' => 'Invalid appointment date or start time format.',
                'rule' => 'format',
                'conflict_id' => null,
            ];
        }

        $durationMinutes = max(15, $durationMinutes);
        $targetEndTs = $targetStartTs + ($durationMinutes * 60);

        // Query active appointments that occupy the schedule on that date
        // Note: Pending and Awaiting Customer Response requests do not reserve slots.
        $stmt = $db->prepare(
            "SELECT a.appointment_id, a.preferred_date, a.preferred_time, a.appointment_begin, a.appointment_end,
                    st.status_name,
                    COALESCE(
                        (SELECT SUM(s.estimated_duration_minutes)
                         FROM performed_service ps
                         JOIN service s ON s.service_id = ps.service_id
                         JOIN appointment_pet ap ON ap.appointment_pet_id = ps.appointment_pet_id
                         WHERE ap.appointment_id = a.appointment_id),
                        (SELECT SUM(s.estimated_duration_minutes)
                         FROM requested_pet_service rps
                         JOIN service s ON s.service_id = rps.service_id
                         JOIN appointment_pet ap ON ap.appointment_pet_id = rps.appointment_pet_id
                         WHERE ap.appointment_id = a.appointment_id AND rps.request_status <> 'declined')
                    ) AS calculated_duration
             FROM appointment a
             JOIN appointment_status st ON st.appointment_status_id = a.appointment_status_id
             WHERE a.business_unit_id = ?
               AND a.preferred_date = ?
               AND st.status_name IN ('Confirmed', 'In Progress', 'Completed')
               AND a.appointment_id <> ?
             ORDER BY COALESCE(TIME(a.appointment_begin), a.preferred_time) ASC, a.appointment_id ASC"
        );
        $stmt->execute([$businessUnitId, $date, $excludeAppointmentId]);
        $existingAppts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($existingAppts as $row) {
            $rawStart = !empty($row['appointment_begin']) ? $row['appointment_begin'] : ($row['preferred_date'] . ' ' . ($row['preferred_time'] ?: '09:00:00'));
            $existStartTs = strtotime((string)$rawStart);

            if (!empty($row['appointment_end'])) {
                $existEndTs = strtotime((string)$row['appointment_end']);
            } else {
                $dur = max(15, (int)($row['calculated_duration'] ?: 30));
                $existEndTs = $existStartTs + ($dur * 60);
            }

            // RULE A — Same start time is ALWAYS prohibited within the business unit
            if ($targetStartTs === $existStartTs) {
                $timeDisplay = date('g:i A', $existStartTs);
                return [
                    'valid' => false,
                    'error' => "An existing appointment (#{$row['appointment_id']}) is already scheduled at the exact same start time ({$timeDisplay}). Two appointments must never have identical start times within the same business unit.",
                    'rule' => 'same_start',
                    'conflict_id' => (int)$row['appointment_id'],
                    'conflict_start' => date('H:i:s', $existStartTs),
                    'conflict_end' => date('H:i:s', $existEndTs),
                    'max_allowed' => $maxOverlapMinutes,
                ];
            }

            // RULE B & C — Maximum overlap duration (bidirectional interval intersection)
            // Overlap Minutes = MAX(0, MIN(endA, endB) - MAX(startA, startB))
            $overlapSecs = max(0, min($targetEndTs, $existEndTs) - max($targetStartTs, $existStartTs));
            $overlapMins = (int)ceil($overlapSecs / 60);

            if ($overlapMins > 0 && $overlapMins > $maxOverlapMinutes) {
                $existStartDisplay = date('g:i A', $existStartTs);
                $existEndDisplay = date('g:i A', $existEndTs);
                return [
                    'valid' => false,
                    'error' => "The requested appointment overlaps with appointment #{$row['appointment_id']} ({$existStartDisplay} – {$existEndDisplay}) by {$overlapMins} minutes, which exceeds the allowed maximum overlap of {$maxOverlapMinutes} minutes.",
                    'rule' => 'max_overlap',
                    'conflict_id' => (int)$row['appointment_id'],
                    'overlap_minutes' => $overlapMins,
                    'max_allowed' => $maxOverlapMinutes,
                    'conflict_start' => date('H:i:s', $existStartTs),
                    'conflict_end' => date('H:i:s', $existEndTs),
                ];
            }
        }

        return [
            'valid' => true,
            'error' => null,
            'rule' => null,
            'conflict_id' => null,
            'overlap_minutes' => 0,
            'max_allowed' => $maxOverlapMinutes,
        ];
    }
}

