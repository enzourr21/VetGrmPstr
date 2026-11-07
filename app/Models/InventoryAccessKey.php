<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

final class InventoryAccessKey
{
    /** Default validity window for temporary Inventory Access Keys (60 minutes). */
    public const TTL_SECONDS = 3600;

    /** Unambiguous uppercase alphanumeric characters for generated key codes. */
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /**
     * Business units supporting temporary Inventory Access Keys (Pet Store & Grooming Services).
     *
     * @return int[]
     */
    public static function supportedUnitIds(): array
    {
        return [Units::petStoreId(), Units::groomingId()];
    }

    public static function isUnitSupported(int $unitId): bool
    {
        return in_array($unitId, self::supportedUnitIds(), true);
    }

    /**
     * Mark any expired keys for the given business unit as inactive.
     */
    public static function expireStaleForUnit(PDO $db, int $unitId): void
    {
        $stmt = $db->prepare(
            'UPDATE inventory_access_key
             SET is_active = 0
             WHERE business_unit_id = ? AND is_active = 1 AND expires_at <= NOW()'
        );
        $stmt->execute([$unitId]);
    }

    /**
     * Fetch the single active, unexpired Inventory Access Key for a business unit, or null if none exists.
     */
    public static function getActiveForUnit(PDO $db, int $unitId): ?array
    {
        if (!self::isUnitSupported($unitId)) {
            return null;
        }

        self::expireStaleForUnit($db, $unitId);

        return self::queryActiveRowForUnit($db, $unitId);
    }

    /**
     * Enforce one-valid-key-at-a-time per business unit:
     * - If a valid, unexpired key already exists for $unitId, returns it without generating or replacing it.
     * - Otherwise creates a new temporary key scoped strictly to $unitId.
     *
     * @return array{created: bool, key: array}
     */
    public static function generateIfNoneActive(
        PDO $db,
        int $unitId,
        ?int $employeeId,
        ?int $actorUserId = null,
        int $ttlSeconds = self::TTL_SECONDS
    ): array {
        if (!self::isUnitSupported($unitId)) {
            throw new \InvalidArgumentException('Inventory Access Keys are not enabled for this business unit.');
        }

        $ttl = max(60, $ttlSeconds);
        $validEmpId = null;
        if ($employeeId !== null && $employeeId > 0) {
            $empChk = $db->prepare('SELECT employee_id FROM employee WHERE employee_id = ? LIMIT 1');
            $empChk->execute([$employeeId]);
            $foundEmp = $empChk->fetchColumn();
            if ($foundEmp) {
                $validEmpId = (int)$foundEmp;
            }
        }

        $ownTx = !$db->inTransaction();
        if ($ownTx) {
            $db->beginTransaction();
        }

        try {
            // Serialize per business unit without blocking other business units
            $lockStmt = $db->prepare('SELECT business_unit_id FROM business_unit WHERE business_unit_id = ? FOR UPDATE');
            $lockStmt->execute([$unitId]);

            self::expireStaleForUnit($db, $unitId);

            $existing = self::queryActiveRowForUnit($db, $unitId);
            if ($existing !== null) {
                if ($ownTx) {
                    $db->commit();
                }
                return ['created' => false, 'key' => $existing];
            }

            $keyCode = self::generateUniqueCode($db);

            $ins = $db->prepare(
                'INSERT INTO inventory_access_key (business_unit_id, key_code, created_by_employee_id, is_active, created_at, expires_at)
                 VALUES (?, ?, ?, 1, NOW(), DATE_ADD(NOW(), INTERVAL ? SECOND))'
            );
            $ins->execute([$unitId, $keyCode, $validEmpId, $ttl]);
            $newId = (int)$db->lastInsertId();

            $createdKey = self::queryByIdForUnit($db, $newId, $unitId);
            if ($createdKey === null) {
                throw new \RuntimeException('Failed to load newly generated Inventory Access Key.');
            }

            if ($ownTx) {
                $db->commit();
            }

            Audit::log(
                'inventory.key_generated',
                "Generated temporary Inventory Access Key #{$newId} for {$createdKey['business_unit_name']} (expires {$createdKey['expires_at']})",
                $unitId,
                'inventory_access_key',
                $newId,
                $actorUserId
            );

            return ['created' => true, 'key' => $createdKey];
        } catch (\Throwable $e) {
            if ($ownTx && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Verify an entered Inventory Access Key for an employee assigned to $employeeUnitId.
     * Strictly prevents cross-business-unit redemption and expired/inactive key usage.
     *
     * @return array{ok: bool, reason?: string, status?: int, message?: string, key?: array}
     */
    public static function verifyKeyForUnit(PDO $db, string $rawKeyCode, int $employeeUnitId): array
    {
        $code = strtoupper(trim($rawKeyCode));
        if ($code === '') {
            return [
                'ok' => false,
                'reason' => 'empty',
                'status' => 422,
                'message' => 'Please enter an Inventory Access Key.',
            ];
        }

        if (!self::isUnitSupported($employeeUnitId)) {
            return [
                'ok' => false,
                'reason' => 'unsupported_unit',
                'status' => 403,
                'message' => 'Temporary Inventory Access Keys are not available for your assigned business unit.',
            ];
        }

        $stmt = $db->prepare(
            "SELECT k.key_id,
                    k.business_unit_id,
                    bu.business_unit_name,
                    k.key_code,
                    k.is_active,
                    k.created_at,
                    k.expires_at,
                    DATE_FORMAT(k.expires_at, '%b %e, %Y · %l:%i %p') AS expires_at_formatted,
                    TIMESTAMPDIFF(SECOND, NOW(), k.expires_at) AS seconds_remaining
             FROM inventory_access_key k
             JOIN business_unit bu ON bu.business_unit_id = k.business_unit_id
             WHERE k.key_code = ?
             LIMIT 1"
        );
        $stmt->execute([$code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return [
                'ok' => false,
                'reason' => 'invalid',
                'status' => 403,
                'message' => 'Invalid Inventory Access Key.',
            ];
        }

        if ((int)$row['business_unit_id'] !== $employeeUnitId) {
            return [
                'ok' => false,
                'reason' => 'cross_unit',
                'status' => 403,
                'message' => 'Access denied: this Inventory Access Key belongs to another business unit and cannot grant access here.',
            ];
        }

        if ((int)$row['is_active'] !== 1 || (int)$row['seconds_remaining'] <= 0) {
            self::expireStaleForUnit($db, $employeeUnitId);
            return [
                'ok' => false,
                'reason' => 'expired',
                'status' => 403,
                'message' => 'This Inventory Access Key has expired or is no longer valid.',
            ];
        }

        return ['ok' => true, 'key' => $row];
    }

    /**
     * Re-validate an active session temporary-access grant against the database on every request.
     */
    public static function validateSessionGrant(PDO $db, int $keyId, int $expectedUnitId): ?array
    {
        if ($keyId <= 0 || !self::isUnitSupported($expectedUnitId)) {
            return null;
        }

        $stmt = $db->prepare(
            "SELECT k.key_id,
                    k.business_unit_id,
                    bu.business_unit_name,
                    k.expires_at,
                    DATE_FORMAT(k.expires_at, '%b %e, %Y · %l:%i %p') AS expires_at_formatted,
                    GREATEST(TIMESTAMPDIFF(SECOND, NOW(), k.expires_at), 0) AS seconds_remaining
             FROM inventory_access_key k
             JOIN business_unit bu ON bu.business_unit_id = k.business_unit_id
             WHERE k.key_id = ?
               AND k.business_unit_id = ?
               AND k.is_active = 1
               AND k.expires_at > NOW()
             LIMIT 1"
        );
        $stmt->execute([$keyId, $expectedUnitId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            self::expireStaleForUnit($db, $expectedUnitId);
            return null;
        }

        return $row;
    }

    private static function queryActiveRowForUnit(PDO $db, int $unitId): ?array
    {
        $stmt = $db->prepare(
            "SELECT k.key_id,
                    k.business_unit_id,
                    bu.business_unit_name,
                    k.key_code,
                    k.created_by_employee_id,
                    CASE
                        WHEN e.employee_id IS NOT NULL THEN CONCAT(e.first_name, ' ', e.last_name)
                        ELSE 'Business Manager'
                    END AS created_by_name,
                    k.is_active,
                    k.created_at,
                    k.expires_at,
                    DATE_FORMAT(k.created_at, '%b %e, %Y · %l:%i %p') AS created_at_formatted,
                    DATE_FORMAT(k.expires_at, '%b %e, %Y · %l:%i %p') AS expires_at_formatted,
                    GREATEST(TIMESTAMPDIFF(SECOND, NOW(), k.expires_at), 0) AS seconds_remaining
             FROM inventory_access_key k
             JOIN business_unit bu ON bu.business_unit_id = k.business_unit_id
             LEFT JOIN employee e ON e.employee_id = k.created_by_employee_id
             WHERE k.business_unit_id = ?
               AND k.is_active = 1
               AND k.expires_at > NOW()
             ORDER BY k.key_id DESC
             LIMIT 1"
        );
        $stmt->execute([$unitId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private static function queryByIdForUnit(PDO $db, int $keyId, int $unitId): ?array
    {
        $stmt = $db->prepare(
            "SELECT k.key_id,
                    k.business_unit_id,
                    bu.business_unit_name,
                    k.key_code,
                    k.created_by_employee_id,
                    CASE
                        WHEN e.employee_id IS NOT NULL THEN CONCAT(e.first_name, ' ', e.last_name)
                        ELSE 'Business Manager'
                    END AS created_by_name,
                    k.is_active,
                    k.created_at,
                    k.expires_at,
                    DATE_FORMAT(k.created_at, '%b %e, %Y · %l:%i %p') AS created_at_formatted,
                    DATE_FORMAT(k.expires_at, '%b %e, %Y · %l:%i %p') AS expires_at_formatted,
                    GREATEST(TIMESTAMPDIFF(SECOND, NOW(), k.expires_at), 0) AS seconds_remaining
             FROM inventory_access_key k
             JOIN business_unit bu ON bu.business_unit_id = k.business_unit_id
             LEFT JOIN employee e ON e.employee_id = k.created_by_employee_id
             WHERE k.key_id = ? AND k.business_unit_id = ?
             LIMIT 1"
        );
        $stmt->execute([$keyId, $unitId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private static function generateUniqueCode(PDO $db): string
    {
        $alpha = self::ALPHABET;
        $maxIdx = strlen($alpha) - 1;
        $chk = $db->prepare('SELECT COUNT(*) FROM inventory_access_key WHERE key_code = ?');

        do {
            $part1 = '';
            $part2 = '';
            for ($i = 0; $i < 4; $i++) {
                $part1 .= $alpha[random_int(0, $maxIdx)];
                $part2 .= $alpha[random_int(0, $maxIdx)];
            }
            $code = "INV-{$part1}-{$part2}";
            $chk->execute([$code]);
        } while ((int)$chk->fetchColumn() > 0);

        return $code;
    }
}

