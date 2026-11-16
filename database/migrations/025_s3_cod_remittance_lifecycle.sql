-- Migration 025: Stage 3 COD Remittance Lifecycle (Allocation Handover, Verification & Auto-Verify Setting)
-- Reversible: see database/migrations/down/025_s3_cod_remittance_lifecycle.down.sql
--
-- Scope (schema & invariants only):
--   1. Non-destructive preflight checks (prerequisite tables & employee.employee_id type compatibility,
--      partial-execution column detection, legacy verified/discrepancy batch detection, empty-batch detection,
--      subtotal/allocation parity, and header-to-allocation lifecycle consistency) before any DDL executes.
--   2. business_settings: add `auto_verify_cod_remittance TINYINT(1) NOT NULL DEFAULT 0`.
--   3. courier_cod_remittance: extend `remittance_status` ENUM to include `'completed'` (preserving
--      existing values and columns; no batch-level `verification_mode`), plus header verification checks.
--   4. courier_cod_remittance_allocation: add `handover_status`, `completed_by_employee_id`, `completed_at`,
--      `verification_status`, `verified_by_employee_id`, `verified_at`, and `verification_mode`, with
--      employee foreign keys and lifecycle CHECK constraints while preserving `allocation_status`,
--      `active_payment_id`, and `uk_cod_alloc_active_payment` unchanged.
--
-- Diagnostic queries for manual inspection if preflight aborts:
--   a) Legacy or inconsistent batch status vs. allocation lifecycle state:
--      SELECT r.remittance_id, r.remittance_reference, r.remittance_status, r.verified_by_employee_id, r.verified_at
--      FROM courier_cod_remittance r WHERE r.remittance_status IN ('completed','verified','discrepancy');
--   b) Non-cancelled batches with zero active allocations:
--      SELECT r.remittance_id, r.remittance_reference, r.remittance_status
--      FROM courier_cod_remittance r
--      WHERE r.remittance_status <> 'cancelled'
--        AND NOT EXISTS (
--            SELECT 1 FROM courier_cod_remittance_allocation a
--            WHERE a.remittance_id = r.remittance_id AND a.allocation_status = 'active'
--        );
--   c) Active allocations with invalid order subtotals or delivery-fee-inclusive expected_amount:
--      SELECT a.remittance_id, a.allocation_id, a.order_id, a.payment_id,
--             a.expected_amount, a.allocated_amount,
--             co.subtotal_amount, co.delivery_fee, co.total_amount, p.amount AS payment_amount
--      FROM courier_cod_remittance_allocation a
--      JOIN courier_cod_remittance r ON r.remittance_id = a.remittance_id
--      LEFT JOIN customer_order co ON co.order_id = a.order_id AND co.business_unit_id = a.business_unit_id
--      LEFT JOIN payment p ON p.payment_id = a.payment_id AND p.order_id = a.order_id
--      WHERE r.remittance_status <> 'cancelled'
--        AND a.allocation_status = 'active'
--        AND (
--            co.order_id IS NULL OR p.payment_id IS NULL
--            OR co.subtotal_amount IS NULL OR co.subtotal_amount <= 0
--            OR co.delivery_fee IS NULL OR co.delivery_fee < 0
--            OR co.total_amount IS NULL OR co.total_amount <= 0
--            OR (co.subtotal_amount + co.delivery_fee) <> co.total_amount
--            OR p.amount <> co.total_amount
--            OR a.expected_amount <> co.subtotal_amount
--            OR a.allocated_amount <> a.expected_amount
--        );

-- 0. Preflight checks BEFORE any DDL changes
DROP PROCEDURE IF EXISTS _p_mig_025_preflight;
CREATE PROCEDURE _p_mig_025_preflight(IN p_msg VARCHAR(128))
SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = p_msg;

-- 0.1 Prerequisite tables, customer_order.subtotal_amount, and employee.employee_id INT UNSIGNED PK compatibility
SET @prereq_025_tables = (
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME IN (
          'business_settings',
          'customer_order',
          'payment',
          'employee',
          'courier_cod_remittance',
          'courier_cod_remittance_allocation'
      )
);
SET @prereq_025_subtotal_col = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'customer_order'
      AND COLUMN_NAME = 'subtotal_amount'
);
SET @prereq_025_emp_pk_compatible = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS c
    JOIN information_schema.KEY_COLUMN_USAGE kcu
      ON kcu.TABLE_SCHEMA = c.TABLE_SCHEMA
     AND kcu.TABLE_NAME = c.TABLE_NAME
     AND kcu.COLUMN_NAME = c.COLUMN_NAME
     AND kcu.CONSTRAINT_NAME = 'PRIMARY'
    WHERE c.TABLE_SCHEMA = DATABASE()
      AND c.TABLE_NAME = 'employee'
      AND c.COLUMN_NAME = 'employee_id'
      AND LOWER(c.DATA_TYPE) = 'int'
      AND LOWER(c.COLUMN_TYPE) LIKE '%unsigned%'
);
SET @sql_025_prereq_abort = IF(
    @prereq_025_tables < 6 OR @prereq_025_subtotal_col = 0 OR @prereq_025_emp_pk_compatible = 0,
    "CALL _p_mig_025_preflight('Mig 025 preflight failed: prerequisite tables, subtotal_amount, or employee.employee_id INT UNSIGNED PK missing.')",
    'DO 0'
);
PREPARE stmt_025_prereq_abort FROM @sql_025_prereq_abort;
EXECUTE stmt_025_prereq_abort;
DEALLOCATE PREPARE stmt_025_prereq_abort;

-- 0.2 Inspect each of the 7 allocation lifecycle columns individually (detect none=0, partial=1..6, or all=7)
SET @c025_hs = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courier_cod_remittance_allocation' AND COLUMN_NAME = 'handover_status'
);
SET @c025_cbe = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courier_cod_remittance_allocation' AND COLUMN_NAME = 'completed_by_employee_id'
);
SET @c025_ca = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courier_cod_remittance_allocation' AND COLUMN_NAME = 'completed_at'
);
SET @c025_vs = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courier_cod_remittance_allocation' AND COLUMN_NAME = 'verification_status'
);
SET @c025_vbe = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courier_cod_remittance_allocation' AND COLUMN_NAME = 'verified_by_employee_id'
);
SET @c025_va = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courier_cod_remittance_allocation' AND COLUMN_NAME = 'verified_at'
);
SET @c025_vm = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courier_cod_remittance_allocation' AND COLUMN_NAME = 'verification_mode'
);
SET @alloc_025_col_cnt = (@c025_hs + @c025_cbe + @c025_ca + @c025_vs + @c025_vbe + @c025_va + @c025_vm);

-- If partial columns exist (1..6), verify existing partial columns contain only initial default values so recovery is safe;
-- if non-default data was written into a half-migrated table, abort before further DDL and report affected batch IDs.
SET @bad_025_partial_dirty_cnt = 0;
SET @bad_025_partial_dirty_ids = '';

SET @sql_025_chk_part_hs = IF(
    @alloc_025_col_cnt BETWEEN 1 AND 6 AND @c025_hs > 0,
    "SELECT @bad_025_partial_dirty_cnt + COUNT(*),
            TRIM(BOTH ',' FROM CONCAT_WS(',', NULLIF(@bad_025_partial_dirty_ids, ''), COALESCE(GROUP_CONCAT(DISTINCT remittance_id ORDER BY remittance_id ASC SEPARATOR ','), '')))
     INTO @bad_025_partial_dirty_cnt, @bad_025_partial_dirty_ids
     FROM courier_cod_remittance_allocation WHERE handover_status <> 'submitted'",
    'DO 0'
);
PREPARE stmt_025_chk_part_hs FROM @sql_025_chk_part_hs;
EXECUTE stmt_025_chk_part_hs;
DEALLOCATE PREPARE stmt_025_chk_part_hs;

SET @sql_025_chk_part_cbe = IF(
    @alloc_025_col_cnt BETWEEN 1 AND 6 AND @c025_cbe > 0,
    "SELECT @bad_025_partial_dirty_cnt + COUNT(*),
            TRIM(BOTH ',' FROM CONCAT_WS(',', NULLIF(@bad_025_partial_dirty_ids, ''), COALESCE(GROUP_CONCAT(DISTINCT remittance_id ORDER BY remittance_id ASC SEPARATOR ','), '')))
     INTO @bad_025_partial_dirty_cnt, @bad_025_partial_dirty_ids
     FROM courier_cod_remittance_allocation WHERE completed_by_employee_id IS NOT NULL",
    'DO 0'
);
PREPARE stmt_025_chk_part_cbe FROM @sql_025_chk_part_cbe;
EXECUTE stmt_025_chk_part_cbe;
DEALLOCATE PREPARE stmt_025_chk_part_cbe;

SET @sql_025_chk_part_ca = IF(
    @alloc_025_col_cnt BETWEEN 1 AND 6 AND @c025_ca > 0,
    "SELECT @bad_025_partial_dirty_cnt + COUNT(*),
            TRIM(BOTH ',' FROM CONCAT_WS(',', NULLIF(@bad_025_partial_dirty_ids, ''), COALESCE(GROUP_CONCAT(DISTINCT remittance_id ORDER BY remittance_id ASC SEPARATOR ','), '')))
     INTO @bad_025_partial_dirty_cnt, @bad_025_partial_dirty_ids
     FROM courier_cod_remittance_allocation WHERE completed_at IS NOT NULL",
    'DO 0'
);
PREPARE stmt_025_chk_part_ca FROM @sql_025_chk_part_ca;
EXECUTE stmt_025_chk_part_ca;
DEALLOCATE PREPARE stmt_025_chk_part_ca;

SET @sql_025_chk_part_vs = IF(
    @alloc_025_col_cnt BETWEEN 1 AND 6 AND @c025_vs > 0,
    "SELECT @bad_025_partial_dirty_cnt + COUNT(*),
            TRIM(BOTH ',' FROM CONCAT_WS(',', NULLIF(@bad_025_partial_dirty_ids, ''), COALESCE(GROUP_CONCAT(DISTINCT remittance_id ORDER BY remittance_id ASC SEPARATOR ','), '')))
     INTO @bad_025_partial_dirty_cnt, @bad_025_partial_dirty_ids
     FROM courier_cod_remittance_allocation WHERE verification_status <> 'pending'",
    'DO 0'
);
PREPARE stmt_025_chk_part_vs FROM @sql_025_chk_part_vs;
EXECUTE stmt_025_chk_part_vs;
DEALLOCATE PREPARE stmt_025_chk_part_vs;

SET @sql_025_chk_part_vbe = IF(
    @alloc_025_col_cnt BETWEEN 1 AND 6 AND @c025_vbe > 0,
    "SELECT @bad_025_partial_dirty_cnt + COUNT(*),
            TRIM(BOTH ',' FROM CONCAT_WS(',', NULLIF(@bad_025_partial_dirty_ids, ''), COALESCE(GROUP_CONCAT(DISTINCT remittance_id ORDER BY remittance_id ASC SEPARATOR ','), '')))
     INTO @bad_025_partial_dirty_cnt, @bad_025_partial_dirty_ids
     FROM courier_cod_remittance_allocation WHERE verified_by_employee_id IS NOT NULL",
    'DO 0'
);
PREPARE stmt_025_chk_part_vbe FROM @sql_025_chk_part_vbe;
EXECUTE stmt_025_chk_part_vbe;
DEALLOCATE PREPARE stmt_025_chk_part_vbe;

SET @sql_025_chk_part_va = IF(
    @alloc_025_col_cnt BETWEEN 1 AND 6 AND @c025_va > 0,
    "SELECT @bad_025_partial_dirty_cnt + COUNT(*),
            TRIM(BOTH ',' FROM CONCAT_WS(',', NULLIF(@bad_025_partial_dirty_ids, ''), COALESCE(GROUP_CONCAT(DISTINCT remittance_id ORDER BY remittance_id ASC SEPARATOR ','), '')))
     INTO @bad_025_partial_dirty_cnt, @bad_025_partial_dirty_ids
     FROM courier_cod_remittance_allocation WHERE verified_at IS NOT NULL",
    'DO 0'
);
PREPARE stmt_025_chk_part_va FROM @sql_025_chk_part_va;
EXECUTE stmt_025_chk_part_va;
DEALLOCATE PREPARE stmt_025_chk_part_va;

SET @sql_025_chk_part_vm = IF(
    @alloc_025_col_cnt BETWEEN 1 AND 6 AND @c025_vm > 0,
    "SELECT @bad_025_partial_dirty_cnt + COUNT(*),
            TRIM(BOTH ',' FROM CONCAT_WS(',', NULLIF(@bad_025_partial_dirty_ids, ''), COALESCE(GROUP_CONCAT(DISTINCT remittance_id ORDER BY remittance_id ASC SEPARATOR ','), '')))
     INTO @bad_025_partial_dirty_cnt, @bad_025_partial_dirty_ids
     FROM courier_cod_remittance_allocation WHERE verification_mode IS NOT NULL",
    'DO 0'
);
PREPARE stmt_025_chk_part_vm FROM @sql_025_chk_part_vm;
EXECUTE stmt_025_chk_part_vm;
DEALLOCATE PREPARE stmt_025_chk_part_vm;

-- 0a. Legacy verified/completed/discrepancy batches when < 7 lifecycle columns exist, or any discrepancy batch anytime
--     (Refuses to fabricate individual handover actors/timestamps from batch-level metadata)
SET @bad_025_legacy_cnt = (
    SELECT COUNT(*)
    FROM courier_cod_remittance
    WHERE remittance_status = 'discrepancy'
       OR (@alloc_025_col_cnt < 7 AND remittance_status IN ('completed', 'verified'))
);
SET @bad_025_legacy_ids = (
    SELECT COALESCE(GROUP_CONCAT(remittance_id ORDER BY remittance_id ASC SEPARATOR ','), '')
    FROM courier_cod_remittance
    WHERE remittance_status = 'discrepancy'
       OR (@alloc_025_col_cnt < 7 AND remittance_status IN ('completed', 'verified'))
);

-- 0b. Active non-cancelled batches with zero active allocations
SET @bad_025_empty_cnt = (
    SELECT COUNT(*)
    FROM courier_cod_remittance r
    WHERE r.remittance_status <> 'cancelled'
      AND NOT EXISTS (
          SELECT 1
          FROM courier_cod_remittance_allocation a
          WHERE a.remittance_id = r.remittance_id
            AND a.allocation_status = 'active'
      )
);
SET @bad_025_empty_ids = (
    SELECT COALESCE(GROUP_CONCAT(r.remittance_id ORDER BY r.remittance_id ASC SEPARATOR ','), '')
    FROM courier_cod_remittance r
    WHERE r.remittance_status <> 'cancelled'
      AND NOT EXISTS (
          SELECT 1
          FROM courier_cod_remittance_allocation a
          WHERE a.remittance_id = r.remittance_id
            AND a.allocation_status = 'active'
      )
);

-- 0c. Active allocations with NULL/zero/invalid subtotals, delivery-fee-inclusive expected_amount, or corrupt order/payment state
SET @bad_025_alloc_cnt = (
    SELECT COUNT(*)
    FROM courier_cod_remittance_allocation a
    JOIN courier_cod_remittance r ON r.remittance_id = a.remittance_id
    LEFT JOIN customer_order co ON co.order_id = a.order_id AND co.business_unit_id = a.business_unit_id
    LEFT JOIN payment p ON p.payment_id = a.payment_id AND p.order_id = a.order_id
    WHERE r.remittance_status <> 'cancelled'
      AND a.allocation_status = 'active'
      AND (
          co.order_id IS NULL
          OR p.payment_id IS NULL
          OR co.subtotal_amount IS NULL
          OR co.subtotal_amount <= 0
          OR co.delivery_fee IS NULL
          OR co.delivery_fee < 0
          OR co.total_amount IS NULL
          OR co.total_amount <= 0
          OR (co.subtotal_amount + co.delivery_fee) <> co.total_amount
          OR p.amount <> co.total_amount
          OR a.expected_amount <> co.subtotal_amount
          OR a.allocated_amount <> a.expected_amount
          OR co.fulfillment_method <> 'delivery'
          OR co.order_status <> 'completed'
          OR p.payment_type <> 'payment'
          OR p.payment_status <> 'completed'
          OR p.collection_channel <> 'courier_cod'
          OR p.collected_by_employee_id IS NULL
          OR p.collected_at IS NULL
          OR p.collected_by_employee_id <> r.courier_employee_id
      )
);
SET @bad_025_alloc_ids = (
    SELECT COALESCE(GROUP_CONCAT(DISTINCT a.remittance_id ORDER BY a.remittance_id ASC SEPARATOR ','), '')
    FROM courier_cod_remittance_allocation a
    JOIN courier_cod_remittance r ON r.remittance_id = a.remittance_id
    LEFT JOIN customer_order co ON co.order_id = a.order_id AND co.business_unit_id = a.business_unit_id
    LEFT JOIN payment p ON p.payment_id = a.payment_id AND p.order_id = a.order_id
    WHERE r.remittance_status <> 'cancelled'
      AND a.allocation_status = 'active'
      AND (
          co.order_id IS NULL
          OR p.payment_id IS NULL
          OR co.subtotal_amount IS NULL
          OR co.subtotal_amount <= 0
          OR co.delivery_fee IS NULL
          OR co.delivery_fee < 0
          OR co.total_amount IS NULL
          OR co.total_amount <= 0
          OR (co.subtotal_amount + co.delivery_fee) <> co.total_amount
          OR p.amount <> co.total_amount
          OR a.expected_amount <> co.subtotal_amount
          OR a.allocated_amount <> a.expected_amount
          OR co.fulfillment_method <> 'delivery'
          OR co.order_status <> 'completed'
          OR p.payment_type <> 'payment'
          OR p.payment_status <> 'completed'
          OR p.collection_channel <> 'courier_cod'
          OR p.collected_by_employee_id IS NULL
          OR p.collected_at IS NULL
          OR p.collected_by_employee_id <> r.courier_employee_id
      )
);

-- 0d. Batch header total mismatch or invalid header verification metadata on non-cancelled batches
SET @bad_025_hdr_cnt = (
    SELECT COUNT(*)
    FROM courier_cod_remittance r
    WHERE r.remittance_status <> 'cancelled'
      AND (
          r.expected_total <= 0
          OR r.remitted_total <> r.expected_total
          OR r.expected_total <> COALESCE((
              SELECT SUM(a2.expected_amount)
              FROM courier_cod_remittance_allocation a2
              WHERE a2.remittance_id = r.remittance_id
                AND a2.allocation_status = 'active'
          ), -1)
          OR (r.verified_by_employee_id IS NOT NULL AND r.verified_by_employee_id = r.courier_employee_id)
          OR (r.remittance_status IN ('submitted', 'completed')
              AND (r.verified_total IS NOT NULL OR r.verified_by_employee_id IS NOT NULL OR r.verified_at IS NOT NULL))
          OR (r.remittance_status = 'verified'
              AND (r.verified_total IS NULL OR r.verified_total <> r.expected_total OR r.verified_at IS NULL))
      )
);
SET @bad_025_hdr_ids = (
    SELECT COALESCE(GROUP_CONCAT(r.remittance_id ORDER BY r.remittance_id ASC SEPARATOR ','), '')
    FROM courier_cod_remittance r
    WHERE r.remittance_status <> 'cancelled'
      AND (
          r.expected_total <= 0
          OR r.remitted_total <> r.expected_total
          OR r.expected_total <> COALESCE((
              SELECT SUM(a2.expected_amount)
              FROM courier_cod_remittance_allocation a2
              WHERE a2.remittance_id = r.remittance_id
                AND a2.allocation_status = 'active'
          ), -1)
          OR (r.verified_by_employee_id IS NOT NULL AND r.verified_by_employee_id = r.courier_employee_id)
          OR (r.remittance_status IN ('submitted', 'completed')
              AND (r.verified_total IS NOT NULL OR r.verified_by_employee_id IS NOT NULL OR r.verified_at IS NOT NULL))
          OR (r.remittance_status = 'verified'
              AND (r.verified_total IS NULL OR r.verified_total <> r.expected_total OR r.verified_at IS NULL))
      )
);

-- 0e. When all 7 allocation lifecycle columns exist (retry or idempotent re-run), validate:
--     (1) per-allocation tuple invariants, employee FK existence, and non-self courier actor rules;
--     (2) batch header-to-allocation state consistency (preventing a legacy 'verified' batch with
--         default 'submitted'/'pending' allocations from ever passing preflight on retry).
SET @bad_025_existing_lifecycle_cnt = 0;
SET @bad_025_existing_lifecycle_ids = '';
SET @sql_025_chk_existing_lc = IF(
    @alloc_025_col_cnt = 7,
    "SELECT COUNT(DISTINCT bad_remit_id),
            COALESCE(GROUP_CONCAT(DISTINCT bad_remit_id ORDER BY bad_remit_id ASC SEPARATOR ','), '')
     INTO @bad_025_existing_lifecycle_cnt, @bad_025_existing_lifecycle_ids
     FROM (
         SELECT a.remittance_id AS bad_remit_id
         FROM courier_cod_remittance_allocation a
         JOIN courier_cod_remittance r ON r.remittance_id = a.remittance_id
         LEFT JOIN employee ec ON ec.employee_id = a.completed_by_employee_id
         LEFT JOIN employee ev ON ev.employee_id = a.verified_by_employee_id
         WHERE NOT (
             (a.handover_status = 'submitted'
              AND a.completed_by_employee_id IS NULL
              AND a.completed_at IS NULL
              AND a.verification_status = 'pending'
              AND a.verified_by_employee_id IS NULL
              AND a.verified_at IS NULL
              AND a.verification_mode IS NULL)
             OR
             (a.handover_status = 'completed'
              AND a.completed_by_employee_id IS NOT NULL
              AND a.completed_at IS NOT NULL)
         )
         OR NOT (
             (a.verification_status = 'pending'
              AND a.verified_by_employee_id IS NULL
              AND a.verified_at IS NULL
              AND a.verification_mode IS NULL)
             OR
             (a.verification_status = 'verified'
              AND a.handover_status = 'completed'
              AND a.verified_at IS NOT NULL
              AND a.verification_mode IS NOT NULL)
         )
         OR NOT (
             a.verification_mode IS NULL
             OR (a.verification_mode = 'auto_setting' AND a.verified_by_employee_id IS NULL)
             OR (a.verification_mode = 'manual' AND a.verified_by_employee_id IS NOT NULL)
             OR (a.verification_mode = 'manager_collection' AND a.verified_by_employee_id IS NOT NULL AND a.verified_by_employee_id = a.completed_by_employee_id)
         )
         OR (a.completed_by_employee_id IS NOT NULL AND (ec.employee_id IS NULL OR a.completed_by_employee_id = r.courier_employee_id))
         OR (a.verified_by_employee_id IS NOT NULL AND (ev.employee_id IS NULL OR a.verified_by_employee_id = r.courier_employee_id))
         UNION
         SELECT r2.remittance_id AS bad_remit_id
         FROM courier_cod_remittance r2
         WHERE r2.remittance_status <> 'cancelled'
           AND (
               (r2.remittance_status = 'submitted' AND NOT EXISTS (
                   SELECT 1 FROM courier_cod_remittance_allocation a2
                   WHERE a2.remittance_id = r2.remittance_id
                     AND a2.allocation_status = 'active'
                     AND a2.handover_status = 'submitted'
               ))
               OR (r2.remittance_status = 'completed' AND (
                   EXISTS (
                       SELECT 1 FROM courier_cod_remittance_allocation a3
                       WHERE a3.remittance_id = r2.remittance_id
                         AND a3.allocation_status = 'active'
                         AND a3.handover_status <> 'completed'
                   )
                   OR NOT EXISTS (
                       SELECT 1 FROM courier_cod_remittance_allocation a4
                       WHERE a4.remittance_id = r2.remittance_id
                         AND a4.allocation_status = 'active'
                         AND a4.verification_status = 'pending'
                   )
               ))
               OR (r2.remittance_status = 'verified' AND (
                   EXISTS (
                       SELECT 1 FROM courier_cod_remittance_allocation a5
                       WHERE a5.remittance_id = r2.remittance_id
                         AND a5.allocation_status = 'active'
                         AND (a5.handover_status <> 'completed' OR a5.verification_status <> 'verified')
                   )
                   OR (r2.verified_by_employee_id IS NOT NULL AND EXISTS (
                       SELECT 1 FROM courier_cod_remittance_allocation a6
                       WHERE a6.remittance_id = r2.remittance_id
                         AND a6.allocation_status = 'active'
                         AND (a6.verified_by_employee_id IS NULL OR a6.verified_by_employee_id <> r2.verified_by_employee_id)
                   ))
                   OR (
                       r2.verified_by_employee_id IS NULL
                       AND EXISTS (
                           SELECT 1 FROM courier_cod_remittance_allocation a7
                           WHERE a7.remittance_id = r2.remittance_id
                             AND a7.allocation_status = 'active'
                       )
                       AND NOT EXISTS (
                           SELECT 1 FROM courier_cod_remittance_allocation a8
                           WHERE a8.remittance_id = r2.remittance_id
                             AND a8.allocation_status = 'active'
                             AND a8.verified_by_employee_id IS NULL
                       )
                       AND NOT EXISTS (
                           SELECT 1
                           FROM courier_cod_remittance_allocation a9
                           JOIN courier_cod_remittance_allocation a10
                             ON a10.remittance_id = a9.remittance_id
                            AND a10.allocation_status = 'active'
                           WHERE a9.remittance_id = r2.remittance_id
                             AND a9.allocation_status = 'active'
                             AND a9.verified_by_employee_id <> a10.verified_by_employee_id
                       )
                   )
               ))
           )
     ) lc_violations",
    'DO 0'
);
PREPARE stmt_025_chk_existing_lc FROM @sql_025_chk_existing_lc;
EXECUTE stmt_025_chk_existing_lc;
DEALLOCATE PREPARE stmt_025_chk_existing_lc;

SET @bad_025_total = (
    @bad_025_partial_dirty_cnt
    + @bad_025_legacy_cnt
    + @bad_025_empty_cnt
    + @bad_025_alloc_cnt
    + @bad_025_hdr_cnt
    + @bad_025_existing_lifecycle_cnt
);
SET @bad_025_all_ids = TRIM(BOTH ',' FROM CONCAT_WS(
    ',',
    IF(@bad_025_partial_dirty_ids <> '', @bad_025_partial_dirty_ids, NULL),
    IF(@bad_025_legacy_ids <> '', @bad_025_legacy_ids, NULL),
    IF(@bad_025_empty_ids <> '', @bad_025_empty_ids, NULL),
    IF(@bad_025_alloc_ids <> '', @bad_025_alloc_ids, NULL),
    IF(@bad_025_hdr_ids <> '', @bad_025_hdr_ids, NULL),
    IF(@bad_025_existing_lifecycle_ids <> '', @bad_025_existing_lifecycle_ids, NULL)
));
SET @msg_025_abort = LEFT(
    CONCAT(
        'Mig 025 preflight failed (cols=', @alloc_025_col_cnt, '/7,pdirty=', @bad_025_partial_dirty_cnt,
        ',leg=', @bad_025_legacy_cnt,
        ',emp=', @bad_025_empty_cnt,
        ',sub=', @bad_025_alloc_cnt,
        ',hdr=', @bad_025_hdr_cnt,
        ',lc=', @bad_025_existing_lifecycle_cnt,
        ') ids:', IF(@bad_025_all_ids = '', 'none', @bad_025_all_ids)
    ),
    128
);
SET @sql_025_abort = IF(
    @bad_025_total > 0,
    'CALL _p_mig_025_preflight(@msg_025_abort)',
    'DO 0'
);
PREPARE stmt_025_abort FROM @sql_025_abort;
EXECUTE stmt_025_abort;
DEALLOCATE PREPARE stmt_025_abort;
DROP PROCEDURE IF EXISTS _p_mig_025_preflight;

-- 1. business_settings: add auto_verify_cod_remittance TINYINT(1) NOT NULL DEFAULT 0
SET @col_bs_av = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'business_settings' AND COLUMN_NAME = 'auto_verify_cod_remittance'
);
SET @sql_col_bs_av = IF(
    @col_bs_av = 0,
    'ALTER TABLE business_settings ADD COLUMN auto_verify_cod_remittance TINYINT(1) NOT NULL DEFAULT 0 AFTER delivery_fee',
    'DO 0'
);
PREPARE stmt_col_bs_av FROM @sql_col_bs_av;
EXECUTE stmt_col_bs_av;
DEALLOCATE PREPARE stmt_col_bs_av;

SET @chk_bs_av = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'business_settings' AND CONSTRAINT_NAME = 'chk_biz_settings_auto_verify_bool' AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql_chk_bs_av = IF(
    @chk_bs_av = 0,
    'ALTER TABLE business_settings ADD CONSTRAINT chk_biz_settings_auto_verify_bool CHECK (auto_verify_cod_remittance IN (0, 1))',
    'DO 0'
);
PREPARE stmt_chk_bs_av FROM @sql_chk_bs_av;
EXECUTE stmt_chk_bs_av;
DEALLOCATE PREPARE stmt_chk_bs_av;

-- 2. courier_cod_remittance: extend remittance_status ENUM to include 'completed' and add header integrity checks
SET @has_remit_completed_enum = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'courier_cod_remittance'
      AND COLUMN_NAME = 'remittance_status'
      AND COLUMN_TYPE LIKE "%'completed'%"
);
SET @sql_mod_remit_status = IF(
    @has_remit_completed_enum = 0,
    "ALTER TABLE courier_cod_remittance MODIFY COLUMN remittance_status ENUM('submitted','completed','verified','discrepancy','cancelled') NOT NULL DEFAULT 'submitted'",
    'DO 0'
);
PREPARE stmt_mod_remit_status FROM @sql_mod_remit_status;
EXECUTE stmt_mod_remit_status;
DEALLOCATE PREPARE stmt_mod_remit_status;

SET @chk_remit_hdr_tuple = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'courier_cod_remittance' AND CONSTRAINT_NAME = 'chk_cod_remit_header_verified_tuple' AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql_chk_remit_hdr_tuple = IF(
    @chk_remit_hdr_tuple = 0,
    "ALTER TABLE courier_cod_remittance ADD CONSTRAINT chk_cod_remit_header_verified_tuple CHECK ((remittance_status IN ('submitted','completed') AND verified_total IS NULL AND verified_by_employee_id IS NULL AND verified_at IS NULL) OR (remittance_status = 'verified' AND verified_total IS NOT NULL AND verified_total = expected_total AND verified_at IS NOT NULL) OR remittance_status IN ('discrepancy','cancelled'))",
    'DO 0'
);
PREPARE stmt_chk_remit_hdr_tuple FROM @sql_chk_remit_hdr_tuple;
EXECUTE stmt_chk_remit_hdr_tuple;
DEALLOCATE PREPARE stmt_chk_remit_hdr_tuple;

SET @chk_remit_noself = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'courier_cod_remittance' AND CONSTRAINT_NAME = 'chk_cod_remit_no_self_verify' AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql_chk_remit_noself = IF(
    @chk_remit_noself = 0,
    'ALTER TABLE courier_cod_remittance ADD CONSTRAINT chk_cod_remit_no_self_verify CHECK (verified_by_employee_id IS NULL OR verified_by_employee_id <> courier_employee_id)',
    'DO 0'
);
PREPARE stmt_chk_remit_noself FROM @sql_chk_remit_noself;
EXECUTE stmt_chk_remit_noself;
DEALLOCATE PREPARE stmt_chk_remit_noself;

-- 3. courier_cod_remittance_allocation: add per-order handover & verification lifecycle columns
--    Preserves allocation_status, active_payment_id, and uk_cod_alloc_active_payment unchanged.
SET @col_alloc_hs = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courier_cod_remittance_allocation' AND COLUMN_NAME = 'handover_status'
);
SET @sql_col_alloc_hs = IF(
    @col_alloc_hs = 0,
    "ALTER TABLE courier_cod_remittance_allocation ADD COLUMN handover_status ENUM('submitted','completed') NOT NULL DEFAULT 'submitted' AFTER allocation_status",
    'DO 0'
);
PREPARE stmt_col_alloc_hs FROM @sql_col_alloc_hs;
EXECUTE stmt_col_alloc_hs;
DEALLOCATE PREPARE stmt_col_alloc_hs;

SET @col_alloc_cbe = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courier_cod_remittance_allocation' AND COLUMN_NAME = 'completed_by_employee_id'
);
SET @sql_col_alloc_cbe = IF(
    @col_alloc_cbe = 0,
    'ALTER TABLE courier_cod_remittance_allocation ADD COLUMN completed_by_employee_id INT UNSIGNED NULL DEFAULT NULL AFTER handover_status',
    'DO 0'
);
PREPARE stmt_col_alloc_cbe FROM @sql_col_alloc_cbe;
EXECUTE stmt_col_alloc_cbe;
DEALLOCATE PREPARE stmt_col_alloc_cbe;

SET @col_alloc_ca = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courier_cod_remittance_allocation' AND COLUMN_NAME = 'completed_at'
);
SET @sql_col_alloc_ca = IF(
    @col_alloc_ca = 0,
    'ALTER TABLE courier_cod_remittance_allocation ADD COLUMN completed_at DATETIME NULL DEFAULT NULL AFTER completed_by_employee_id',
    'DO 0'
);
PREPARE stmt_col_alloc_ca FROM @sql_col_alloc_ca;
EXECUTE stmt_col_alloc_ca;
DEALLOCATE PREPARE stmt_col_alloc_ca;

SET @col_alloc_vs = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courier_cod_remittance_allocation' AND COLUMN_NAME = 'verification_status'
);
SET @sql_col_alloc_vs = IF(
    @col_alloc_vs = 0,
    "ALTER TABLE courier_cod_remittance_allocation ADD COLUMN verification_status ENUM('pending','verified') NOT NULL DEFAULT 'pending' AFTER completed_at",
    'DO 0'
);
PREPARE stmt_col_alloc_vs FROM @sql_col_alloc_vs;
EXECUTE stmt_col_alloc_vs;
DEALLOCATE PREPARE stmt_col_alloc_vs;

SET @col_alloc_vbe = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courier_cod_remittance_allocation' AND COLUMN_NAME = 'verified_by_employee_id'
);
SET @sql_col_alloc_vbe = IF(
    @col_alloc_vbe = 0,
    'ALTER TABLE courier_cod_remittance_allocation ADD COLUMN verified_by_employee_id INT UNSIGNED NULL DEFAULT NULL AFTER verification_status',
    'DO 0'
);
PREPARE stmt_col_alloc_vbe FROM @sql_col_alloc_vbe;
EXECUTE stmt_col_alloc_vbe;
DEALLOCATE PREPARE stmt_col_alloc_vbe;

SET @col_alloc_va = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courier_cod_remittance_allocation' AND COLUMN_NAME = 'verified_at'
);
SET @sql_col_alloc_va = IF(
    @col_alloc_va = 0,
    'ALTER TABLE courier_cod_remittance_allocation ADD COLUMN verified_at DATETIME NULL DEFAULT NULL AFTER verified_by_employee_id',
    'DO 0'
);
PREPARE stmt_col_alloc_va FROM @sql_col_alloc_va;
EXECUTE stmt_col_alloc_va;
DEALLOCATE PREPARE stmt_col_alloc_va;

SET @col_alloc_vm = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courier_cod_remittance_allocation' AND COLUMN_NAME = 'verification_mode'
);
SET @sql_col_alloc_vm = IF(
    @col_alloc_vm = 0,
    "ALTER TABLE courier_cod_remittance_allocation ADD COLUMN verification_mode ENUM('manual','auto_setting','manager_collection') NULL DEFAULT NULL AFTER verified_at",
    'DO 0'
);
PREPARE stmt_col_alloc_vm FROM @sql_col_alloc_vm;
EXECUTE stmt_col_alloc_vm;
DEALLOCATE PREPARE stmt_col_alloc_vm;

-- 4. Indexes and foreign keys on courier_cod_remittance_allocation
SET @idx_alloc_lc = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courier_cod_remittance_allocation' AND INDEX_NAME = 'idx_cod_alloc_remit_lifecycle'
);
SET @sql_idx_alloc_lc = IF(
    @idx_alloc_lc = 0,
    'ALTER TABLE courier_cod_remittance_allocation ADD INDEX idx_cod_alloc_remit_lifecycle (remittance_id, allocation_status, handover_status, verification_status)',
    'DO 0'
);
PREPARE stmt_idx_alloc_lc FROM @sql_idx_alloc_lc;
EXECUTE stmt_idx_alloc_lc;
DEALLOCATE PREPARE stmt_idx_alloc_lc;

SET @idx_alloc_cbe = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courier_cod_remittance_allocation' AND INDEX_NAME = 'idx_cod_alloc_completed_emp'
);
SET @sql_idx_alloc_cbe = IF(
    @idx_alloc_cbe = 0,
    'ALTER TABLE courier_cod_remittance_allocation ADD INDEX idx_cod_alloc_completed_emp (completed_by_employee_id)',
    'DO 0'
);
PREPARE stmt_idx_alloc_cbe FROM @sql_idx_alloc_cbe;
EXECUTE stmt_idx_alloc_cbe;
DEALLOCATE PREPARE stmt_idx_alloc_cbe;

SET @idx_alloc_vbe = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courier_cod_remittance_allocation' AND INDEX_NAME = 'idx_cod_alloc_verified_emp'
);
SET @sql_idx_alloc_vbe = IF(
    @idx_alloc_vbe = 0,
    'ALTER TABLE courier_cod_remittance_allocation ADD INDEX idx_cod_alloc_verified_emp (verified_by_employee_id)',
    'DO 0'
);
PREPARE stmt_idx_alloc_vbe FROM @sql_idx_alloc_vbe;
EXECUTE stmt_idx_alloc_vbe;
DEALLOCATE PREPARE stmt_idx_alloc_vbe;

SET @fk_alloc_cbe = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'courier_cod_remittance_allocation' AND CONSTRAINT_NAME = 'fk_cod_alloc_completed_emp' AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql_fk_alloc_cbe = IF(
    @fk_alloc_cbe = 0,
    'ALTER TABLE courier_cod_remittance_allocation ADD CONSTRAINT fk_cod_alloc_completed_emp FOREIGN KEY (completed_by_employee_id) REFERENCES employee(employee_id)',
    'DO 0'
);
PREPARE stmt_fk_alloc_cbe FROM @sql_fk_alloc_cbe;
EXECUTE stmt_fk_alloc_cbe;
DEALLOCATE PREPARE stmt_fk_alloc_cbe;

SET @fk_alloc_vbe = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'courier_cod_remittance_allocation' AND CONSTRAINT_NAME = 'fk_cod_alloc_verified_emp' AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql_fk_alloc_vbe = IF(
    @fk_alloc_vbe = 0,
    'ALTER TABLE courier_cod_remittance_allocation ADD CONSTRAINT fk_cod_alloc_verified_emp FOREIGN KEY (verified_by_employee_id) REFERENCES employee(employee_id)',
    'DO 0'
);
PREPARE stmt_fk_alloc_vbe FROM @sql_fk_alloc_vbe;
EXECUTE stmt_fk_alloc_vbe;
DEALLOCATE PREPARE stmt_fk_alloc_vbe;

-- 5. Lifecycle CHECK constraints on courier_cod_remittance_allocation
SET @chk_alloc_ht = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'courier_cod_remittance_allocation' AND CONSTRAINT_NAME = 'chk_cod_alloc_handover_tuple' AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql_chk_alloc_ht = IF(
    @chk_alloc_ht = 0,
    "ALTER TABLE courier_cod_remittance_allocation ADD CONSTRAINT chk_cod_alloc_handover_tuple CHECK ((handover_status = 'submitted' AND completed_by_employee_id IS NULL AND completed_at IS NULL AND verification_status = 'pending' AND verified_by_employee_id IS NULL AND verified_at IS NULL AND verification_mode IS NULL) OR (handover_status = 'completed' AND completed_by_employee_id IS NOT NULL AND completed_at IS NOT NULL))",
    'DO 0'
);
PREPARE stmt_chk_alloc_ht FROM @sql_chk_alloc_ht;
EXECUTE stmt_chk_alloc_ht;
DEALLOCATE PREPARE stmt_chk_alloc_ht;

SET @chk_alloc_vt = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'courier_cod_remittance_allocation' AND CONSTRAINT_NAME = 'chk_cod_alloc_verification_tuple' AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql_chk_alloc_vt = IF(
    @chk_alloc_vt = 0,
    "ALTER TABLE courier_cod_remittance_allocation ADD CONSTRAINT chk_cod_alloc_verification_tuple CHECK ((verification_status = 'pending' AND verified_by_employee_id IS NULL AND verified_at IS NULL AND verification_mode IS NULL) OR (verification_status = 'verified' AND handover_status = 'completed' AND verified_at IS NOT NULL AND verification_mode IS NOT NULL))",
    'DO 0'
);
PREPARE stmt_chk_alloc_vt FROM @sql_chk_alloc_vt;
EXECUTE stmt_chk_alloc_vt;
DEALLOCATE PREPARE stmt_chk_alloc_vt;

SET @chk_alloc_vma = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'courier_cod_remittance_allocation' AND CONSTRAINT_NAME = 'chk_cod_alloc_verification_mode_actor' AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql_chk_alloc_vma = IF(
    @chk_alloc_vma = 0,
    "ALTER TABLE courier_cod_remittance_allocation ADD CONSTRAINT chk_cod_alloc_verification_mode_actor CHECK (verification_mode IS NULL OR (verification_mode = 'auto_setting' AND verified_by_employee_id IS NULL) OR (verification_mode = 'manual' AND verified_by_employee_id IS NOT NULL) OR (verification_mode = 'manager_collection' AND verified_by_employee_id IS NOT NULL AND verified_by_employee_id = completed_by_employee_id))",
    'DO 0'
);
PREPARE stmt_chk_alloc_vma FROM @sql_chk_alloc_vma;
EXECUTE stmt_chk_alloc_vma;
DEALLOCATE PREPARE stmt_chk_alloc_vma;
