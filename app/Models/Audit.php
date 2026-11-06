<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Session;

final class Audit
{
    public static function log(
        string $actionCode,
        string $summary,
        ?int $businessUnitId = null,
        ?string $targetType = null,
        ?int $targetId = null,
        ?int $actorUserId = null
    ): void {
        try {
            $db = Database::pdo();
            $actor = $actorUserId ?? (Session::get('user_id') ? (int)Session::get('user_id') : null);
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            if (is_string($ip) && strlen($ip) > 45) {
                $ip = substr($ip, 0, 45);
            }
            $stmt = $db->prepare(
                'INSERT INTO audit_log (actor_user_id, business_unit_id, action_code, target_type, target_id, summary, ip_address)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $actor,
                $businessUnitId,
                substr($actionCode, 0, 100),
                $targetType !== null ? substr($targetType, 0, 60) : null,
                $targetId,
                mb_substr($summary, 0, 500),
                $ip,
            ]);
        } catch (\Throwable) {
            // Never let audit logging failure crash a primary request if table is mid-migration
        }
    }

    /** Administrative activity types shown in the System Administrator Activity Log (code => label). */
    public const ADMIN_ACTIVITY_TYPES = [
        'employee.created' => 'Employee account created',
        'sysadmin.created' => 'Employee account created',
        'employee.activated' => 'Account activated',
        'employee.deactivated' => 'Account deactivated',
        'employee.reactivated' => 'Account reactivated',
        'role.assigned' => 'Role assigned',
        'role.removed' => 'Role removed',
        'sysadmin.roles_updated' => 'Roles updated',
        'employee.position_changed' => 'Default position changed',
        'sysadmin.position_created' => 'Position created',
        'sysadmin.position_toggled' => 'Position activated/deactivated',
        'invitation.created' => 'Invitation created',
        'invitation.resent' => 'Invitation resent',
        'invitation.accepted' => 'Invitation accepted',
        'invitation.expired' => 'Invitation expired',
        'invitation.revoked' => 'Invitation revoked',
        'appointment.cancelled' => 'Appointment cancelled',
        'appointment.reassigned' => 'Appointment reassigned',
    ];

    /** Filter-dropdown groups: key => [label, codes]. */
    public const ADMIN_ACTIVITY_FILTERS = [
        'account_created' => ['Employee account created', ['employee.created', 'sysadmin.created']],
        'account_status' => ['Account activated / deactivated / reactivated', ['employee.activated', 'employee.deactivated', 'employee.reactivated']],
        'role' => ['Role assigned / removed', ['role.assigned', 'role.removed', 'sysadmin.roles_updated']],
        'position_changed' => ['Employee default position changed', ['employee.position_changed']],
        'position_status' => ['Position created / activated / deactivated', ['sysadmin.position_created', 'sysadmin.position_toggled']],
    ];

    /**
     * Strict, exception-throwing audit insert for use INSIDE a transaction so the change and its
     * record commit or roll back together. Never pass passwords, hashes, or tokens in any argument.
     */
    public static function record(
        \PDO $db,
        string $actionCode,
        string $summary,
        int $businessUnitId,
        ?int $actorUserId,
        ?int $affectedEmployeeId = null,
        ?string $oldValue = null,
        ?string $newValue = null,
        ?string $targetType = null,
        ?int $targetId = null
    ): void {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $stmt = $db->prepare(
            'INSERT INTO audit_log (actor_user_id, business_unit_id, action_code, target_type, target_id, affected_employee_id, summary, old_value, new_value, ip_address)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $actorUserId,
            $businessUnitId,
            substr($actionCode, 0, 100),
            $targetType !== null ? substr($targetType, 0, 60) : null,
            $targetId,
            $affectedEmployeeId,
            mb_substr($summary, 0, 500),
            $oldValue !== null ? mb_substr($oldValue, 0, 255) : null,
            $newValue !== null ? mb_substr($newValue, 0, 255) : null,
            is_string($ip) ? substr($ip, 0, 45) : null,
        ]);
    }

    /**
     * Administrative activity of ONE business unit (always scoped by business_unit_id), newest first.
     *
     * @param array{type?:string,employee_id?:int,date_from?:string,date_to?:string} $filters
     * @return array{rows:array,total:int,page:int,pages:int,per_page:int}
     */
    public static function activityForUnit(int $unitId, array $filters = [], int $page = 1, int $perPage = 15): array
    {
        $db = Database::pdo();
        $codes = array_keys(self::ADMIN_ACTIVITY_TYPES);
        $where = ['al.business_unit_id = ?'];
        $args = [$unitId];

        $type = (string)($filters['type'] ?? '');
        if ($type !== '' && isset(self::ADMIN_ACTIVITY_FILTERS[$type])) {
            $codes = self::ADMIN_ACTIVITY_FILTERS[$type][1];
        }
        $where[] = 'al.action_code IN (' . implode(',', array_fill(0, count($codes), '?')) . ')';
        array_push($args, ...$codes);

        if (!empty($filters['employee_id'])) {
            $where[] = 'al.affected_employee_id = ?';
            $args[] = (int)$filters['employee_id'];
        }
        if (!empty($filters['date_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$filters['date_from'])) {
            $where[] = 'al.created_at >= ?';
            $args[] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$filters['date_to'])) {
            $where[] = 'al.created_at <= ?';
            $args[] = $filters['date_to'] . ' 23:59:59';
        }
        $whereSql = implode(' AND ', $where);

        $cnt = $db->prepare("SELECT COUNT(*) FROM audit_log al WHERE $whereSql");
        $cnt->execute($args);
        $total = (int)$cnt->fetchColumn();
        $pages = max(1, (int)ceil($total / $perPage));
        $page = max(1, min($page, $pages));
        $offset = ($page - 1) * $perPage;

        $stmt = $db->prepare(
            "SELECT al.log_id, al.action_code, al.summary, al.old_value, al.new_value, al.created_at, al.affected_employee_id,
                    ua.email AS actor_email,
                    TRIM(CONCAT(COALESCE(ae.first_name, ''), ' ', COALESCE(ae.last_name, ''))) AS actor_name,
                    TRIM(CONCAT(COALESCE(te.first_name, ''), ' ', COALESCE(te.last_name, ''))) AS affected_name
             FROM audit_log al
             LEFT JOIN user_account ua ON ua.user_account_id = al.actor_user_id
             LEFT JOIN employee ae ON ae.user_account_id = al.actor_user_id
             LEFT JOIN employee te ON te.employee_id = al.affected_employee_id
             WHERE $whereSql
             ORDER BY al.created_at DESC, al.log_id DESC
             LIMIT " . (int)$perPage . ' OFFSET ' . (int)$offset
        );
        $stmt->execute($args);

        return ['rows' => $stmt->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => $pages, 'per_page' => $perPage];
    }

    public static function recent(int $limit = 25, ?int $businessUnitId = null): array
    {
        $limit = max(1, min(200, $limit));
        $db = Database::pdo();
        $sql = "SELECT al.log_id, al.action_code, al.target_type, al.target_id, al.summary, al.ip_address, al.created_at,
                       ua.email AS actor_email,
                       CONCAT(COALESCE(e.first_name, c.first_name, ''), ' ', COALESCE(e.last_name, c.last_name, '')) AS actor_name,
                       bu.business_unit_name
                FROM audit_log al
                LEFT JOIN user_account ua ON ua.user_account_id = al.actor_user_id
                LEFT JOIN employee e ON e.user_account_id = al.actor_user_id
                LEFT JOIN customer c ON c.user_account_id = al.actor_user_id
                LEFT JOIN business_unit bu ON bu.business_unit_id = al.business_unit_id";
        $args = [];
        if ($businessUnitId !== null) {
            $sql .= " WHERE al.business_unit_id = ?";
            $args[] = $businessUnitId;
        }
        $sql .= " ORDER BY al.created_at DESC, al.log_id DESC LIMIT $limit";
        $stmt = $db->prepare($sql);
        $stmt->execute($args);
        return $stmt->fetchAll();
    }
}

