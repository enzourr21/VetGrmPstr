<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class Units
{
    public const PET_STORE = 'Pet Store';
    public const VET_CLINIC = 'Veterinary Clinic';
    public const GROOMING = 'Grooming Services';

    public static function idByName(string $name): int
    {
        $s = Database::pdo()->prepare('SELECT business_unit_id FROM business_unit WHERE business_unit_name=? AND business_unit_status="active"');
        $s->execute([$name]);
        $id = $s->fetchColumn();
        if (!$id) {
            throw new \RuntimeException("Business unit '$name' is not seeded. Run php database/seed.php");
        }
        return (int)$id;
    }

    public static function groomingId(): int
    {
        return self::idByName(self::GROOMING);
    }

    public static function petStoreId(): int
    {
        return self::idByName(self::PET_STORE);
    }

    public static function vetClinicId(): int
    {
        return self::idByName(self::VET_CLINIC);
    }

    /** @return array{business_unit_id:int,business_unit_name:string,business_unit_status:string}|null */
    public static function find(int $id): ?array
    {
        $s = Database::pdo()->prepare('SELECT business_unit_id, business_unit_name, business_unit_status FROM business_unit WHERE business_unit_id=? AND business_unit_status="active"');
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    public static function findAny(int $id): ?array
    {
        $s = Database::pdo()->prepare('SELECT business_unit_id, business_unit_name, business_unit_status, created_at, updated_at FROM business_unit WHERE business_unit_id=?');
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    public static function all(): array
    {
        return Database::pdo()->query('SELECT business_unit_id, business_unit_name, business_unit_status FROM business_unit WHERE business_unit_status="active" ORDER BY business_unit_id')->fetchAll();
    }

    public static function mainBranchId(int $unitId): int
    {
        $s = Database::pdo()->prepare('SELECT branch_id FROM branch WHERE business_unit_id=? AND branch_status="active" ORDER BY branch_id LIMIT 1');
        $s->execute([$unitId]);
        $id = $s->fetchColumn();
        if (!$id) {
            throw new \RuntimeException('No active branch for business unit ' . $unitId);
        }
        return (int)$id;
    }
}
