<?php
declare(strict_types=1);

/**
 * Controlled CLI helper: Provision the three business-unit System Administrator accounts
 * (Pet Store, Veterinary Clinic, Grooming Services) if not already present.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden: CLI execution only.\n");
}

require_once dirname(__DIR__) . '/config/database.php';

function provisionUnitSystemAdmins(PDO $pdo): array
{
    $roleId = (int)$pdo->query("SELECT role_id FROM role WHERE role_name = 'SYSTEM_ADMINISTRATOR'")->fetchColumn();
    if (!$roleId) {
        throw new RuntimeException('SYSTEM_ADMINISTRATOR role not seeded.');
    }

    $superId = (int)$pdo->query("SELECT user_account_id FROM user_account WHERE is_super_admin = 1 LIMIT 1")->fetchColumn();

    $specs = [
        [
            'unit' => 'Pet Store',
            'email' => 'sysadmin.petstore@vetgrpet.local',
            'username' => 'sysadmin_petstore',
            'first_name' => 'Adrian',
            'last_name' => 'Mercer',
            'position' => 'System Administrator',
            'password' => getenv('SYSADMIN_PETSTORE_PASSWORD') ?: 'SysAdminPet!2026',
        ],
        [
            'unit' => 'Veterinary Clinic',
            'email' => 'sysadmin.vetclinic@vetgrpet.local',
            'username' => 'sysadmin_vetclinic',
            'first_name' => 'Beatrice',
            'last_name' => 'solis',
            'position' => 'System Administrator',
            'password' => getenv('SYSADMIN_VETCLINIC_PASSWORD') ?: 'SysAdminVet!2026',
        ],
        [
            'unit' => 'Grooming Services',
            'email' => 'sysadmin.grooming@vetgrpet.local',
            'username' => 'sysadmin_grooming',
            'first_name' => 'Caleb',
            'last_name' => 'Thorne',
            'position' => 'System Administrator',
            'password' => getenv('SYSADMIN_GROOMING_PASSWORD') ?: 'SysAdminGroom!2026',
        ],
    ];
    $specs[1]['last_name'] = 'Solis';

    $out = [];
    foreach ($specs as $spec) {
        $uStmt = $pdo->prepare("SELECT business_unit_id FROM business_unit WHERE business_unit_name = ?");
        $uStmt->execute([$spec['unit']]);
        $unitId = (int)$uStmt->fetchColumn();
        if (!$unitId) {
            continue;
        }

        $chk = $pdo->prepare("SELECT user_account_id FROM user_account WHERE email = ?");
        $chk->execute([$spec['email']]);
        $existingUid = (int)$chk->fetchColumn();
        if ($existingUid) {
            // Ensure role and employee business_unit_id are intact
            $pdo->prepare("UPDATE employee SET business_unit_id = ? WHERE user_account_id = ? AND business_unit_id IS NULL")
                ->execute([$unitId, $existingUid]);
            $pdo->prepare("INSERT IGNORE INTO account_role (user_account_id, role_id, business_unit_id) VALUES (?, ?, ?)")
                ->execute([$existingUid, $roleId, $unitId]);
            $out[] = "SKIP  {$spec['unit']} System Admin ({$spec['email']} already exists)";
            continue;
        }

        $pdo->beginTransaction();
        try {
            $hash = password_hash($spec['password'], PASSWORD_DEFAULT);
            $pdo->prepare(
                "INSERT INTO user_account (email, username, password_hash, account_status, is_super_admin, email_verified_at)
                 VALUES (?, ?, ?, 'active', 0, CURRENT_TIMESTAMP)"
            )->execute([$spec['email'], $spec['username'], $hash]);
            $uid = (int)$pdo->lastInsertId();

            $pdo->prepare(
                "INSERT INTO employee (user_account_id, business_unit_id, first_name, last_name, position, employee_status)
                 VALUES (?, ?, ?, ?, ?, 'active')"
            )->execute([$uid, $unitId, $spec['first_name'], $spec['last_name'], $spec['position']]);

            $pdo->prepare(
                "INSERT INTO customer (user_account_id, first_name, last_name, customer_status)
                 VALUES (?, ?, ?, 'active')"
            )->execute([$uid, $spec['first_name'], $spec['last_name']]);

            $pdo->prepare(
                "INSERT INTO account_role (user_account_id, role_id, business_unit_id) VALUES (?, ?, ?)"
            )->execute([$uid, $roleId, $unitId]);

            $pdo->prepare(
                "INSERT INTO audit_log (actor_user_id, business_unit_id, action_code, target_type, target_id, summary, ip_address)
                 VALUES (?, ?, 'sysadmin.provisioned', 'user_account', ?, ?, 'CLI')"
            )->execute([
                $superId ?: null,
                $unitId,
                $uid,
                "System Administrator account ({$spec['email']}) created for {$spec['unit']}.",
            ]);

            $pdo->commit();
            $out[] = "CREATE {$spec['unit']} System Admin: {$spec['email']} (username: {$spec['username']})";
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
    return $out;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    foreach (provisionUnitSystemAdmins($pdo) as $line) {
        echo $line . "\n";
    }
}

