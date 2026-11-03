<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class Units
{
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

    /** @return array{business_unit_id:int,business_unit_name:string}|null */
    public static function find(int $id): ?array
    {
        $s = Database::pdo()->prepare('SELECT business_unit_id, business_unit_name FROM business_unit WHERE business_unit_id=? AND business_unit_status="active"');
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    public static function all(): array
    {
        return Database::pdo()->query('SELECT business_unit_id, business_unit_name FROM business_unit WHERE business_unit_status="active" ORDER BY business_unit_id')->fetchAll();
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

