<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Audit;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Provenance-safe legacy invitation placeholder analyzer, cleanup, and rollback archiver.
 *
 * Strictly distinguishes:
 *  - PROTECTED_GENUINE: Direct accounts, Super Administrators, accepted invitees, verified accounts,
 *    or accounts with zero employee_invitation history (including legacy email_verified_at=NULL accounts).
 *  - PROVEN_PLACEHOLDER: Never-accepted invitee accounts with positive invitation-creation provenance
 *    and ZERO operational, customer-data, or actor dependencies across all schema foreign keys.
 *  - QUARANTINED_UNCERTAIN: Never-accepted invitee accounts carrying any unexpected dependency or
 *    missing positive provenance; preserved intact with is_legacy_quarantined = 1.
 */
final class InvitationPlaceholderCleanupService
{
    public const CLASS_PROTECTED = 'PROTECTED_GENUINE';
    public const CLASS_PROVEN_PLACEHOLDER = 'PROVEN_PLACEHOLDER';
    public const CLASS_QUARANTINED = 'QUARANTINED_UNCERTAIN';

    /** @var array<string, list<array{table:string,column:string}>> */
    private const CUSTOMER_DEP_CHECKS = [
        'pet' => [['table' => 'pet', 'column' => 'customer_id']],
        'sale_customer' => [['table' => 'sale', 'column' => 'customer_id']],
        'customer_order' => [['table' => 'customer_order', 'column' => 'customer_id']],
        'appointment' => [['table' => 'appointment', 'column' => 'customer_id']],
        'cart' => [['table' => 'cart', 'column' => 'customer_id']],
        'customer_review' => [['table' => 'customer_review', 'column' => 'customer_id']],
        'refund_request_customer' => [['table' => 'refund_request', 'column' => 'customer_id']],
        'checkout_attempt' => [['table' => 'checkout_attempt', 'column' => 'customer_id']],
        'inventory_hold' => [['table' => 'inventory_hold', 'column' => 'customer_id']],
        'payment_reconciliation_customer' => [['table' => 'payment_reconciliation', 'column' => 'customer_id']],
    ];

    /** @var array<string, list<array{table:string,column:string}>> */
    private const EMPLOYEE_DEP_CHECKS = [
        'sale_employee' => [['table' => 'sale', 'column' => 'employee_id']],
        'performed_service' => [['table' => 'performed_service', 'column' => 'performer_employee_id']],
        'purchase_order' => [['table' => 'purchase_order', 'column' => 'created_by_employee_id']],
        'stock_receiving' => [['table' => 'stock_receiving', 'column' => 'employee_id']],
        'stock_adjustment' => [['table' => 'stock_adjustment', 'column' => 'employee_id']],
        'refund_request_decider' => [['table' => 'refund_request', 'column' => 'decided_by_employee_id']],
        'inventory_access_key' => [
            ['table' => 'inventory_access_key', 'column' => 'created_by_employee_id'],
            ['table' => 'inventory_access_key', 'column' => 'revoked_by_employee_id'],
        ],
        'purchase_order_request' => [
            ['table' => 'purchase_order_request', 'column' => 'requested_by_employee_id'],
            ['table' => 'purchase_order_request', 'column' => 'reviewed_by_employee_id'],
        ],
        'customer_order_employee' => [
            ['table' => 'customer_order', 'column' => 'delivery_employee_id'],
            ['table' => 'customer_order', 'column' => 'failure_classified_by_employee_id'],
            ['table' => 'customer_order', 'column' => 'dispute_resolved_by_employee_id'],
        ],
        'payment_collected' => [['table' => 'payment', 'column' => 'collected_by_employee_id']],
        'courier_cod_remittance' => [
            ['table' => 'courier_cod_remittance', 'column' => 'courier_employee_id'],
            ['table' => 'courier_cod_remittance', 'column' => 'verified_by_employee_id'],
        ],
        'courier_cod_remittance_allocation' => [
            ['table' => 'courier_cod_remittance_allocation', 'column' => 'completed_by_employee_id'],
            ['table' => 'courier_cod_remittance_allocation', 'column' => 'verified_by_employee_id'],
        ],
    ];

    /** @var array<string, list<array{table:string,column:string}>> */
    private const USER_DEP_CHECKS = [
        'audit_actor' => [['table' => 'audit_log', 'column' => 'actor_user_id']],
        'payment_reconciliation_resolver' => [['table' => 'payment_reconciliation', 'column' => 'resolved_by_user_id']],
        'invitation_creator' => [['table' => 'employee_invitation', 'column' => 'created_by_user_id']],
    ];

    /**
     * Foreign keys referencing user_account, customer, or employee that are intentionally handled
     * (detached or deleted in FK-safe order) during applyCleanup() rather than treated as unconditional blockers.
     *
     * @var array<string, array{table:string,column:string,referenced_table:string,referenced_column:string,handling:string}>
     */
    private const CLEANUP_HANDLED_FKS = [
        'employee_invitation.user_account_id' => [
            'table' => 'employee_invitation',
            'column' => 'user_account_id',
            'referenced_table' => 'user_account',
            'referenced_column' => 'user_account_id',
            'handling' => 'detach_null_unaccepted',
        ],
        'employee_invitation.employee_id' => [
            'table' => 'employee_invitation',
            'column' => 'employee_id',
            'referenced_table' => 'employee',
            'referenced_column' => 'employee_id',
            'handling' => 'detach_null_unaccepted',
        ],
        'account_role.user_account_id' => [
            'table' => 'account_role',
            'column' => 'user_account_id',
            'referenced_table' => 'user_account',
            'referenced_column' => 'user_account_id',
            'handling' => 'snapshot_roles_and_delete',
        ],
        'employee_work_status.employee_id' => [
            'table' => 'employee_work_status',
            'column' => 'employee_id',
            'referenced_table' => 'employee',
            'referenced_column' => 'employee_id',
            'handling' => 'delete_when_free_or_absent',
        ],
        'customer.user_account_id' => [
            'table' => 'customer',
            'column' => 'user_account_id',
            'referenced_table' => 'user_account',
            'referenced_column' => 'user_account_id',
            'handling' => 'delete_after_customer_checks',
        ],
        'employee.user_account_id' => [
            'table' => 'employee',
            'column' => 'user_account_id',
            'referenced_table' => 'user_account',
            'referenced_column' => 'user_account_id',
            'handling' => 'delete_after_employee_checks',
        ],
    ];

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Return foreign-key coverage metadata distinguishing relationships inspected during classification
     * from relationships intentionally detached/deleted during cleanup.
     *
     * @return array{
     *   classification_checks: array<string, array{table:string,column:string,referenced_table:string,referenced_column:string,check_kind:string,check_key:string}>,
     *   cleanup_handled: array<string, array{table:string,column:string,referenced_table:string,referenced_column:string,handling:string}>
     * }
     */
    public static function foreignKeyCoverage(): array
    {
        $classification = [];
        $groups = [
            ['map' => self::USER_DEP_CHECKS, 'ref_table' => 'user_account', 'ref_col' => 'user_account_id'],
            ['map' => self::CUSTOMER_DEP_CHECKS, 'ref_table' => 'customer', 'ref_col' => 'customer_id'],
            ['map' => self::EMPLOYEE_DEP_CHECKS, 'ref_table' => 'employee', 'ref_col' => 'employee_id'],
        ];
        foreach ($groups as $g) {
            foreach ($g['map'] as $checkKey => $pairs) {
                foreach ($pairs as $pair) {
                    $key = $pair['table'] . '.' . $pair['column'];
                    $classification[$key] = [
                        'table' => $pair['table'],
                        'column' => $pair['column'],
                        'referenced_table' => $g['ref_table'],
                        'referenced_column' => $g['ref_col'],
                        'check_kind' => 'blocking_dependency',
                        'check_key' => $checkKey,
                    ];
                }
            }
        }

        // Targeted non-blocking-when-clean FKs also inspected during classifyAccount()
        $classification['employee_work_status.employee_id'] = [
            'table' => 'employee_work_status',
            'column' => 'employee_id',
            'referenced_table' => 'employee',
            'referenced_column' => 'employee_id',
            'check_kind' => 'non_default_status',
            'check_key' => 'employee_work_status_non_default',
        ];
        $classification['employee_invitation.user_account_id'] = [
            'table' => 'employee_invitation',
            'column' => 'user_account_id',
            'referenced_table' => 'user_account',
            'referenced_column' => 'user_account_id',
            'check_kind' => 'identity_link_and_lifecycle',
            'check_key' => 'cross_linked_employee_invitation',
        ];
        $classification['employee_invitation.employee_id'] = [
            'table' => 'employee_invitation',
            'column' => 'employee_id',
            'referenced_table' => 'employee',
            'referenced_column' => 'employee_id',
            'check_kind' => 'identity_link_and_lifecycle',
            'check_key' => 'mismatched_invitation_employee_link',
        ];

        return [
            'classification_checks' => $classification,
            'cleanup_handled' => self::CLEANUP_HANDLED_FKS,
        ];
    }

    /**
     * Read-only provenance analysis for every user_account linked to any employee_invitation row
     * plus summary counts of zero-invitation accounts left untouched.
     *
     * @return array{
     *   summary: array<string, int>,
     *   proven_placeholders: list<array<string, mixed>>,
     *   quarantined_uncertain: list<array<string, mixed>>,
     *   protected_with_invitations: list<array<string, mixed>>
     * }
     */
    public function analyze(): array
    {
        $zeroInviteAccounts = (int)$this->db->query(
            "SELECT COUNT(*)
             FROM user_account ua
             WHERE NOT EXISTS (
                 SELECT 1 FROM employee_invitation ei WHERE ei.user_account_id = ua.user_account_id
             )"
        )->fetchColumn();

        $decoupledInvitations = (int)$this->db->query(
            "SELECT COUNT(*) FROM employee_invitation WHERE user_account_id IS NULL OR employee_id IS NULL"
        )->fetchColumn();

        $candidateStmt = $this->db->query(
            "SELECT DISTINCT ua.user_account_id, ua.email, ua.username, ua.account_status,
                    ua.is_super_admin, ua.email_verified_at,
                    e.employee_id, e.business_unit_id, e.first_name, e.last_name, e.position, e.employee_status,
                    c.customer_id, c.middle_name AS cust_middle_name, c.address_line1, c.address_line2,
                    c.city, c.province, c.postal_code,
                    EXISTS (
                        SELECT 1
                        FROM account_role ar_sa
                        JOIN role r_sa ON r_sa.role_id = ar_sa.role_id
                        WHERE ar_sa.user_account_id = ua.user_account_id
                          AND r_sa.role_name = 'SUPER_ADMINISTRATOR'
                    ) AS has_super_role
             FROM user_account ua
             JOIN employee_invitation ei_any ON ei_any.user_account_id = ua.user_account_id
             LEFT JOIN employee e ON e.user_account_id = ua.user_account_id
             LEFT JOIN customer c ON c.user_account_id = ua.user_account_id
             ORDER BY ua.user_account_id ASC"
        );
        $accounts = $candidateStmt->fetchAll(PDO::FETCH_ASSOC);

        $proven = [];
        $quarantined = [];
        $protected = [];

        foreach ($accounts as $acct) {
            $report = $this->classifyAccount($acct);
            if ($report['classification'] === self::CLASS_PROVEN_PLACEHOLDER) {
                $proven[] = $report;
            } elseif ($report['classification'] === self::CLASS_QUARANTINED) {
                $quarantined[] = $report;
            } else {
                $protected[] = $report;
            }
        }

        return [
            'summary' => [
                'zero_invitation_accounts_protected' => $zeroInviteAccounts,
                'already_decoupled_invitations' => $decoupledInvitations,
                'linked_invitation_accounts_inspected' => count($accounts),
                'proven_placeholder_count' => count($proven),
                'quarantined_uncertain_count' => count($quarantined),
                'protected_with_invitations_count' => count($protected),
            ],
            'proven_placeholders' => $proven,
            'quarantined_uncertain' => $quarantined,
            'protected_with_invitations' => $protected,
        ];
    }

    /**
     * Execute provenance-verified cleanup of unaccepted placeholder accounts.
     * Refuses execution unless $confirmed === true.
     *
     * @return array{
     *   cleaned_count:int,
     *   quarantined_count:int,
     *   skipped_count:int,
     *   cleaned_user_ids:list<int>,
     *   quarantined_user_ids:list<int>,
     *   analysis:array<string,mixed>
     * }
     */
    public function applyCleanup(bool $confirmed, ?int $actorUserId = null): array
    {
        if (!$confirmed) {
            throw new RuntimeException('Cleanup execution refused without explicit confirmation (--confirm-cleanup).');
        }
        if ($this->db->inTransaction()) {
            throw new RuntimeException('Cleanup cannot start inside an active database transaction.');
        }

        $analysis = $this->analyze();
        $cleanedUserIds = [];
        $quarantinedUserIds = [];
        $skippedCount = 0;

        foreach ($analysis['quarantined_uncertain'] as $q) {
            $uid = (int)$q['user_account_id'];
            $this->db->beginTransaction();
            try {
                $this->db->prepare(
                    "UPDATE employee_invitation
                     SET is_legacy_quarantined = 1
                     WHERE user_account_id = ?
                       AND status <> 'accepted'"
                )->execute([$uid]);
                $this->db->commit();
                $quarantinedUserIds[] = $uid;
            } catch (Throwable $e) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                throw $e;
            }
        }

        foreach ($analysis['proven_placeholders'] as $cand) {
            $uid = (int)$cand['user_account_id'];
            $eid = $cand['employee_id'] !== null ? (int)$cand['employee_id'] : null;
            $unitId = $cand['business_unit_id'] !== null ? (int)$cand['business_unit_id'] : 1;

            $this->db->beginTransaction();
            try {
                // Re-lock and re-verify inside the transaction before mutating
                $uaLock = $this->db->prepare(
                    "SELECT ua.user_account_id, ua.email, ua.username, ua.account_status,
                            ua.is_super_admin, ua.email_verified_at,
                            e.employee_id, e.business_unit_id, e.first_name, e.last_name, e.position, e.phone,
                            c.customer_id, c.middle_name AS cust_middle_name, c.address_line1, c.address_line2,
                            c.city, c.province, c.postal_code,
                            EXISTS (
                                SELECT 1
                                FROM account_role ar_sa
                                JOIN role r_sa ON r_sa.role_id = ar_sa.role_id
                                WHERE ar_sa.user_account_id = ua.user_account_id
                                  AND r_sa.role_name = 'SUPER_ADMINISTRATOR'
                            ) AS has_super_role
                     FROM user_account ua
                     LEFT JOIN employee e ON e.user_account_id = ua.user_account_id
                     LEFT JOIN customer c ON c.user_account_id = ua.user_account_id
                     WHERE ua.user_account_id = ?
                     FOR UPDATE"
                );
                $uaLock->execute([$uid]);
                $lockedAcct = $uaLock->fetch(PDO::FETCH_ASSOC);

                if (!$lockedAcct) {
                    $this->db->rollBack();
                    $skippedCount++;
                    continue;
                }

                $recheck = $this->classifyAccount($lockedAcct, true);
                if ($recheck['classification'] !== self::CLASS_PROVEN_PLACEHOLDER) {
                    if ($recheck['classification'] === self::CLASS_QUARANTINED) {
                        $this->db->prepare(
                            "UPDATE employee_invitation SET is_legacy_quarantined = 1 WHERE user_account_id = ? AND status <> 'accepted'"
                        )->execute([$uid]);
                        $this->db->commit();
                        $quarantinedUserIds[] = $uid;
                    } else {
                        $this->db->rollBack();
                        $skippedCount++;
                    }
                    continue;
                }

                $lockedEid = $lockedAcct['employee_id'] !== null ? (int)$lockedAcct['employee_id'] : $eid;

                // Ensure role and profile snapshots exist on every invitation row before detaching
                $invIdsStmt = $this->db->prepare(
                    "SELECT invitation_id, business_unit_id
                     FROM employee_invitation
                     WHERE user_account_id = ?
                     FOR UPDATE"
                );
                $invIdsStmt->execute([$uid]);
                $invRows = $invIdsStmt->fetchAll(PDO::FETCH_ASSOC);

                $insInvRole = $this->db->prepare(
                    "INSERT IGNORE INTO employee_invitation_role (invitation_id, role_id)
                     SELECT ?, ar.role_id
                     FROM account_role ar
                     JOIN role r ON r.role_id = ar.role_id
                     WHERE ar.user_account_id = ?
                       AND ar.business_unit_id = ?
                       AND r.role_name <> 'SUPER_ADMINISTRATOR'"
                );
                foreach ($invRows as $ir) {
                    $insInvRole->execute([(int)$ir['invitation_id'], $uid, (int)$ir['business_unit_id']]);
                }

                // Detach invitations cleanly (leaving snapshots, roles, and statuses intact)
                $this->db->prepare(
                    "UPDATE employee_invitation
                     SET user_account_id = NULL,
                         employee_id = NULL,
                         is_legacy_quarantined = 0
                     WHERE user_account_id = ?
                       AND status <> 'accepted'"
                )->execute([$uid]);

                // Preserve audit_log history & summaries while clearing reference to deleted placeholder employee_id
                if ($lockedEid !== null && $lockedEid > 0) {
                    $this->db->prepare(
                        "UPDATE audit_log
                         SET affected_employee_id = NULL
                         WHERE affected_employee_id = ?"
                    )->execute([$lockedEid]);

                    $this->db->prepare("DELETE FROM employee_work_status WHERE employee_id = ? AND work_status = 'Free'")->execute([$lockedEid]);
                }

                $this->db->prepare('DELETE FROM account_role WHERE user_account_id = ?')->execute([$uid]);
                $this->db->prepare('DELETE FROM customer WHERE user_account_id = ?')->execute([$uid]);
                if ($lockedEid !== null && $lockedEid > 0) {
                    $this->db->prepare('DELETE FROM employee WHERE employee_id = ?')->execute([$lockedEid]);
                }
                $this->db->prepare('DELETE FROM user_account WHERE user_account_id = ?')->execute([$uid]);

                Audit::record(
                    $this->db,
                    'migration.placeholder_cleanup',
                    "Cleaned provenance-verified unaccepted invitation placeholder account ({$lockedAcct['email']}) while preserving invitation and audit history.",
                    $unitId > 0 ? $unitId : 1,
                    $actorUserId,
                    null,
                    'legacy_placeholder',
                    'detached_invitation',
                    'employee_invitation',
                    !empty($invRows) ? (int)$invRows[0]['invitation_id'] : null
                );

                $this->db->commit();
                $cleanedUserIds[] = $uid;
            } catch (Throwable $e) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                throw $e;
            }
        }

        return [
            'cleaned_count' => count($cleanedUserIds),
            'quarantined_count' => count($quarantinedUserIds),
            'skipped_count' => $skippedCount,
            'cleaned_user_ids' => $cleanedUserIds,
            'quarantined_user_ids' => $quarantinedUserIds,
            'analysis' => $analysis,
        ];
    }

    /**
     * Archive decoupled invitations (user_account_id IS NULL OR employee_id IS NULL) and their
     * employee_invitation_role rows so 030.down.sql can safely verify zero data loss before rolling back.
     *
     * Note: The archive is a rollback/recovery snapshot of decoupled rows, not a synchronized live history;
     * live employee_invitation remains authoritative if an archived invitation is later accepted.
     *
     * @return array{
     *   archived_invitations:int,
     *   archived_roles:int,
     *   detached_from_live:bool,
     *   detached_invitations:int,
     *   detached_roles:int
     * }
     */
    public function archiveForRollback(bool $detachArchivedFromLive = false): array
    {
        if ($this->db->inTransaction()) {
            throw new RuntimeException('Rollback archival cannot start inside an active database transaction.');
        }

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS employee_invitation_decoupled_archive (
                invitation_id BIGINT UNSIGNED PRIMARY KEY,
                business_unit_id INT UNSIGNED NOT NULL,
                invited_email VARCHAR(255) NOT NULL,
                first_name VARCHAR(100) NOT NULL,
                last_name VARCHAR(100) NOT NULL,
                username VARCHAR(100) NULL DEFAULT NULL,
                phone VARCHAR(30) NULL DEFAULT NULL,
                position_id INT UNSIGNED NULL DEFAULT NULL,
                position_name VARCHAR(100) NOT NULL,
                token_hash CHAR(64) NOT NULL,
                status VARCHAR(20) NOT NULL,
                expires_at DATETIME NOT NULL,
                revoked_at DATETIME NULL DEFAULT NULL,
                revoke_reason VARCHAR(100) NULL DEFAULT NULL,
                replaced_invitation_id BIGINT UNSIGNED NULL DEFAULT NULL,
                email_submission_status VARCHAR(20) NOT NULL,
                email_submitted_at DATETIME NULL DEFAULT NULL,
                email_failed_at DATETIME NULL DEFAULT NULL,
                email_error VARCHAR(255) NULL DEFAULT NULL,
                created_by_user_id INT UNSIGNED NULL DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                archived_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS employee_invitation_role_archive (
                invitation_id BIGINT UNSIGNED NOT NULL,
                role_id INT UNSIGNED NOT NULL,
                PRIMARY KEY (invitation_id, role_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci"
        );

        $this->db->beginTransaction();
        try {
            $nowUtc = gmdate('Y-m-d H:i:s');
            $insInv = $this->db->prepare(
                "INSERT INTO employee_invitation_decoupled_archive (
                    invitation_id, business_unit_id, invited_email, first_name, last_name,
                    username, phone, position_id, position_name, token_hash, status,
                    expires_at, revoked_at, revoke_reason, replaced_invitation_id,
                    email_submission_status, email_submitted_at, email_failed_at, email_error,
                    created_by_user_id, created_at, updated_at, archived_at
                 )
                 SELECT src.invitation_id, src.business_unit_id, src.invited_email, src.first_name, src.last_name,
                        src.username, src.phone, src.position_id, src.position_name, src.token_hash, src.status,
                        src.expires_at, src.revoked_at, src.revoke_reason, src.replaced_invitation_id,
                        src.email_submission_status, src.email_submitted_at, src.email_failed_at, src.email_error,
                        src.created_by_user_id, src.created_at, src.updated_at, src.archived_at
                 FROM (
                     SELECT invitation_id, business_unit_id, invited_email, first_name, last_name,
                            username, phone, position_id, position_name, token_hash, status,
                            expires_at, revoked_at, revoke_reason, replaced_invitation_id,
                            email_submission_status, email_submitted_at, email_failed_at, email_error,
                            created_by_user_id, created_at, updated_at, ? AS archived_at
                     FROM employee_invitation
                     WHERE user_account_id IS NULL OR employee_id IS NULL
                 ) AS src
                 ON DUPLICATE KEY UPDATE
                     status = src.status,
                     expires_at = src.expires_at,
                     revoked_at = src.revoked_at,
                     revoke_reason = src.revoke_reason,
                     replaced_invitation_id = src.replaced_invitation_id,
                     email_submission_status = src.email_submission_status,
                     email_submitted_at = src.email_submitted_at,
                     email_failed_at = src.email_failed_at,
                     email_error = src.email_error,
                     updated_at = src.updated_at,
                     archived_at = src.archived_at"
            );
            $insInv->execute([$nowUtc]);

            $insRoles = $this->db->prepare(
                "INSERT IGNORE INTO employee_invitation_role_archive (invitation_id, role_id)
                 SELECT eir.invitation_id, eir.role_id
                 FROM employee_invitation_role eir
                 JOIN employee_invitation ei ON ei.invitation_id = eir.invitation_id
                 WHERE ei.user_account_id IS NULL OR ei.employee_id IS NULL"
            );
            $insRoles->execute();

            // Measure distinct decoupled records ensured in archive inside the transaction
            // before any optional live-row detachment (avoiding MySQL ON DUPLICATE KEY UPDATE rowCount=2).
            $archivedInvCount = (int)$this->db->query(
                "SELECT COUNT(DISTINCT ei.invitation_id)
                 FROM employee_invitation ei
                 JOIN employee_invitation_decoupled_archive a ON a.invitation_id = ei.invitation_id
                 WHERE ei.user_account_id IS NULL OR ei.employee_id IS NULL"
            )->fetchColumn();

            $archivedRoleCount = (int)$this->db->query(
                "SELECT COUNT(*)
                 FROM employee_invitation_role eir
                 JOIN employee_invitation ei ON ei.invitation_id = eir.invitation_id
                 JOIN employee_invitation_role_archive ra
                   ON ra.invitation_id = eir.invitation_id AND ra.role_id = eir.role_id
                 WHERE ei.user_account_id IS NULL OR ei.employee_id IS NULL"
            )->fetchColumn();

            $detachedRoleCount = 0;
            $detachedInvCount = 0;
            if ($detachArchivedFromLive) {
                $detachedRoleCount = (int)$this->db->exec(
                    "DELETE eir
                     FROM employee_invitation_role eir
                     JOIN employee_invitation ei ON ei.invitation_id = eir.invitation_id
                     JOIN employee_invitation_decoupled_archive a ON a.invitation_id = eir.invitation_id
                     WHERE ei.user_account_id IS NULL OR ei.employee_id IS NULL"
                );
                $detachedInvCount = (int)$this->db->exec(
                    "DELETE ei
                     FROM employee_invitation ei
                     JOIN employee_invitation_decoupled_archive a ON a.invitation_id = ei.invitation_id
                     WHERE ei.user_account_id IS NULL OR ei.employee_id IS NULL"
                );
            }

            $this->db->commit();

            return [
                'archived_invitations' => $archivedInvCount,
                'archived_roles' => $archivedRoleCount,
                'detached_from_live' => $detachArchivedFromLive,
                'detached_invitations' => $detachedInvCount,
                'detached_roles' => $detachedRoleCount,
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $acct
     * @param bool $lockForUpdate Acquire FOR UPDATE lock on employee_work_status inside applyCleanup()'s transaction.
     * @return array<string, mixed>
     */
    private function classifyAccount(array $acct, bool $lockForUpdate = false): array
    {
        $uid = (int)$acct['user_account_id'];
        $eid = $acct['employee_id'] !== null ? (int)$acct['employee_id'] : null;
        $cid = $acct['customer_id'] !== null ? (int)$acct['customer_id'] : null;
        $unitId = $acct['business_unit_id'] !== null ? (int)$acct['business_unit_id'] : null;

        $invStmt = $this->db->prepare(
            "SELECT invitation_id, employee_id, business_unit_id, status, accepted_at, revoked_at, expires_at
             FROM employee_invitation
             WHERE user_account_id = ?
             ORDER BY invitation_id ASC"
        );
        $invStmt->execute([$uid]);
        $invRows = $invStmt->fetchAll(PDO::FETCH_ASSOC);

        $acceptedCount = 0;
        $mismatchCount = 0;
        $statuses = [];
        foreach ($invRows as $ir) {
            $st = (string)$ir['status'];
            $statuses[] = $st;
            if ($st === 'accepted' || $ir['accepted_at'] !== null) {
                $acceptedCount++;
            }
            $invEid = $ir['employee_id'] !== null ? (int)$ir['employee_id'] : null;
            if ($invEid === null || $invEid <= 0 || $eid === null || $eid <= 0 || $invEid !== $eid) {
                $mismatchCount++;
            }
        }

        $protectionReasons = [];
        if (!$invRows) {
            $protectionReasons[] = 'no_invitation_history';
        }
        if ($acceptedCount > 0) {
            $protectionReasons[] = 'has_accepted_invitation';
        }
        if (!empty($acct['is_super_admin']) || !empty($acct['has_super_role'])) {
            $protectionReasons[] = 'super_administrator';
        }
        if ($acct['email_verified_at'] !== null) {
            $protectionReasons[] = 'email_verified_at_present';
        }

        if ($protectionReasons) {
            return [
                'user_account_id' => $uid,
                'employee_id' => $eid,
                'customer_id' => $cid,
                'business_unit_id' => $unitId,
                'email' => (string)$acct['email'],
                'classification' => self::CLASS_PROTECTED,
                'reasons' => $protectionReasons,
                'invitation_statuses' => $statuses,
                'dependencies' => [],
            ];
        }

        // Check all operational / customer / actor foreign-key dependencies
        $dependencies = [];
        if ($mismatchCount > 0) {
            $dependencies['mismatched_invitation_employee_link'] = $mismatchCount;
        }
        foreach (self::USER_DEP_CHECKS as $label => $pairs) {
            $cnt = $this->sumCount($pairs, $uid);
            if ($cnt > 0) {
                $dependencies[$label] = $cnt;
            }
        }

        if ($cid !== null && $cid > 0) {
            foreach (self::CUSTOMER_DEP_CHECKS as $label => $pairs) {
                $cnt = $this->sumCount($pairs, $cid);
                if ($cnt > 0) {
                    $dependencies[$label] = $cnt;
                }
            }
            $editedFields = [];
            foreach (['cust_middle_name', 'address_line1', 'address_line2', 'city', 'province', 'postal_code'] as $col) {
                if (isset($acct[$col]) && trim((string)$acct[$col]) !== '') {
                    $editedFields[] = $col;
                }
            }
            if ($editedFields) {
                $dependencies['customer_profile_address_fields'] = count($editedFields);
            }
        }

        if ($eid !== null && $eid > 0) {
            foreach (self::EMPLOYEE_DEP_CHECKS as $label => $pairs) {
                $cnt = $this->sumCount($pairs, $eid);
                if ($cnt > 0) {
                    $dependencies[$label] = $cnt;
                }
            }

            $wsSql = 'SELECT work_status FROM employee_work_status WHERE employee_id = ?'
                . ($lockForUpdate ? ' FOR UPDATE' : '');
            $wsStmt = $this->db->prepare($wsSql);
            $wsStmt->execute([$eid]);
            $wsRow = $wsStmt->fetch(PDO::FETCH_ASSOC);
            if ($wsRow !== false) {
                $wsVal = $wsRow['work_status'] ?? null;
                if ($wsVal === null || (string)$wsVal !== 'Free') {
                    $dependencies['employee_work_status_non_default'] = 1;
                }
            }

            $crossInvStmt = $this->db->prepare(
                "SELECT COUNT(*)
                 FROM employee_invitation
                 WHERE employee_id = ?
                   AND (user_account_id IS NULL OR user_account_id <> ?)"
            );
            $crossInvStmt->execute([$eid, $uid]);
            $crossInvCnt = (int)$crossInvStmt->fetchColumn();
            if ($crossInvCnt > 0) {
                $dependencies['cross_linked_employee_invitation'] = $crossInvCnt;
            }
        } else {
            $dependencies['missing_linked_employee_row'] = 1;
        }

        // Verify positive invitation-provisioning audit provenance
        $invIds = array_map(static fn(array $r): int => (int)$r['invitation_id'], $invRows);
        $hasAuditProvenance = $this->hasInvitationAuditProvenance($invIds, $eid);
        if (!$hasAuditProvenance) {
            $dependencies['missing_invitation_audit_provenance'] = 1;
        }

        if ($dependencies) {
            return [
                'user_account_id' => $uid,
                'employee_id' => $eid,
                'customer_id' => $cid,
                'business_unit_id' => $unitId,
                'email' => (string)$acct['email'],
                'classification' => self::CLASS_QUARANTINED,
                'reasons' => array_keys($dependencies),
                'invitation_statuses' => $statuses,
                'dependencies' => $dependencies,
            ];
        }

        return [
            'user_account_id' => $uid,
            'employee_id' => $eid,
            'customer_id' => $cid,
            'business_unit_id' => $unitId,
            'email' => (string)$acct['email'],
            'classification' => self::CLASS_PROVEN_PLACEHOLDER,
            'reasons' => ['unaccepted_only', 'email_verified_null', 'zero_dependencies', 'audit_provenance_verified'],
            'invitation_statuses' => $statuses,
            'dependencies' => [],
        ];
    }

    /**
     * @param list<array{table:string,column:string}> $pairs
     */
    private function sumCount(array $pairs, int $id): int
    {
        $total = 0;
        foreach ($pairs as $pair) {
            $sql = sprintf(
                'SELECT COUNT(*) FROM %s WHERE %s = ?',
                $pair['table'],
                $pair['column']
            );
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$id]);
            $total += (int)$stmt->fetchColumn();
        }
        return $total;
    }

    /**
     * @param list<int> $invitationIds
     */
    private function hasInvitationAuditProvenance(array $invitationIds, ?int $employeeId): bool
    {
        if (!$invitationIds) {
            return false;
        }

        $placeholders = implode(',', array_fill(0, count($invitationIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT COUNT(*)
             FROM audit_log
             WHERE target_type = 'employee_invitation'
               AND action_code IN ('invitation.created', 'invitation.resent')
               AND target_id IN ({$placeholders})"
        );
        $stmt->execute($invitationIds);
        if ((int)$stmt->fetchColumn() > 0) {
            return true;
        }

        if ($employeeId !== null && $employeeId > 0) {
            $empStmt = $this->db->prepare(
                "SELECT COUNT(*)
                 FROM audit_log
                 WHERE affected_employee_id = ?
                   AND action_code IN ('invitation.created', 'invitation.resent')"
            );
            $empStmt->execute([$employeeId]);
            return (int)$empStmt->fetchColumn() > 0;
        }

        return false;
    }
}
