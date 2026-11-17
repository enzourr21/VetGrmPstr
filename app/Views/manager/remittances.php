<?php
require __DIR__ . '/_nav.php';
$q = $searchQuery ?? '';
$stFilter = $statusFilter ?? 'all';
$isManagerOperator = !empty($isManagerOperator);
$empId = (int)($empId ?? 0);
$batches = $remittanceBatches ?? [];
$postActionPrefix = ($isSuper && !empty($_SERVER['REQUEST_URI']) && str_contains((string)$_SERVER['REQUEST_URI'], '/manager/units/'))
    ? '/manager/units/' . (int)$unitId . '/remittances'
    : '/manager/remittances';

$batchStatusMeta = static function (string $st): array {
    return match ($st) {
        'submitted' => ['label' => 'Awaiting Handover', 'badge' => 'badge-warning'],
        'completed' => ['label' => 'Handover Completed', 'badge' => 'badge-info'],
        'verified'  => ['label' => 'Verified', 'badge' => 'badge-success'],
        default     => ['label' => ucfirst($st), 'badge' => 'badge-secondary'],
    };
};

$allocHandoverMeta = static function (string $hs): array {
    return $hs === 'completed'
        ? ['label' => 'Handover Completed', 'badge' => 'badge-info']
        : ['label' => 'Awaiting Handover', 'badge' => 'badge-warning'];
};

$allocVerificationMeta = static function (string $vs): array {
    return $vs === 'verified'
        ? ['label' => 'Verified', 'badge' => 'badge-success']
        : ['label' => 'Pending Verification', 'badge' => 'badge-warning'];
};

$verificationModeLabel = static function (?string $mode): string {
    return match ($mode) {
        'manager_collection' => 'Manager Collection',
        'manual' => 'Manual Verification',
        'auto_setting' => 'Auto-Verified',
        default => '',
    };
};
?>

<div class="page-head">
  <div>
    <p class="eyebrow">Transaction Management</p>
    <h1>COD Remittances</h1>
    <p class="muted">Collect and verify courier COD remittance batches and individual order allocations using remittable subtotals (excluding delivery fees).</p>
  </div>
</div>

<?php if (!$isManagerOperator): ?>
  <div class="alert alert-info" style="margin-bottom: 1.25rem;">
    <strong>Administrative Oversight Mode (Read-Only):</strong> Operational COD remittance collection and verification require an active Pet Store <code>BUSINESS_MANAGER</code> role assignment.
  </div>
<?php endif; ?>

<section class="card" style="margin-bottom: 1.5rem; padding: 1rem 1.25rem;">
  <form method="get" action="/manager/remittances" class="row" style="gap: 0.75rem; align-items: flex-end; flex-wrap: wrap;">
    <?php if (!empty($isSuper)): ?>
      <input type="hidden" name="unit_id" value="<?= (int)$unitId ?>">
    <?php endif; ?>

    <div class="field" style="margin: 0; min-width: 240px; flex: 1;">
      <label for="bm-remit-search" class="caption" style="display: block; margin-bottom: 0.25rem;">Search Batch Reference, Courier Name, or Order ID</label>
      <input id="bm-remit-search" type="search" name="q" value="<?= e($q) ?>" placeholder="Search RMT-..., Courier Name, or #ORD-..." class="search-input">
    </div>

    <div class="field" style="margin: 0; width: 200px;">
      <label for="bm-remit-status" class="caption" style="display: block; margin-bottom: 0.25rem;">Batch Status</label>
      <select id="bm-remit-status" name="status" onchange="this.form.submit()" style="width: 100%;">
        <option value="all" <?= $stFilter === 'all' ? 'selected' : '' ?>>All Batches</option>
        <option value="submitted" <?= $stFilter === 'submitted' ? 'selected' : '' ?>>Awaiting Handover</option>
        <option value="completed" <?= $stFilter === 'completed' ? 'selected' : '' ?>>Handover Completed</option>
        <option value="verified" <?= $stFilter === 'verified' ? 'selected' : '' ?>>Verified</option>
      </select>
    </div>

    <?php if ($q !== '' || $stFilter !== 'all'): ?>
      <div class="row" style="align-items: center;">
        <a href="/manager/remittances<?= $unitQuery ?>" class="btn ghost btn-sm">Reset</a>
      </div>
    <?php endif; ?>
  </form>
</section>

<?php if (empty($batches)): ?>
  <section class="card">
    <div class="empty">
      No courier COD remittance batches found<?= $q !== '' ? ' matching "' . e($q) . '"' : '' ?>.
    </div>
  </section>
<?php else: ?>
  <div style="display: grid; gap: 1.5rem; margin-bottom: 2rem;">
    <?php foreach ($batches as $batch): ?>
      <?php
      $remitId = (int)$batch['remittance_id'];
      $bStatus = (string)$batch['remittance_status'];
      $bMeta = $batchStatusMeta($bStatus);
      $courierEmpId = (int)$batch['courier_employee_id'];
      $isOwnCourierBatch = $isManagerOperator && $empId > 0 && $courierEmpId === $empId;
      $allocations = $batch['allocations'] ?? [];
      $totalAllocs = (int)($batch['allocation_count'] ?? count($allocations));
      $completedHandover = (int)($batch['completed_handover_count'] ?? 0);
      $verifiedCount = (int)($batch['verified_count'] ?? 0);
      $remainingEligible = (int)($batch['remaining_eligible_count'] ?? 0);
      ?>
      <section class="card" id="bm-remittance-batch-<?= $remitId ?>" style="padding: 1.25rem;">
        <div class="row" style="justify-content: space-between; align-items: flex-start; gap: 1rem; flex-wrap: wrap; padding-bottom: 1rem; border-bottom: 1px solid var(--color-border, #e5e7eb); margin-bottom: 1rem;">
          <div>
            <div style="display: flex; align-items: center; gap: 0.65rem; flex-wrap: wrap;">
              <h2 style="margin: 0; font-size: 1.15rem; font-family: var(--font-mono, monospace);">
                <?= e((string)$batch['remittance_reference']) ?>
              </h2>
              <span class="badge <?= $bMeta['badge'] ?>"><?= e($bMeta['label']) ?></span>
            </div>
            <div class="caption muted" style="margin-top: 0.35rem; display: flex; gap: 1rem; flex-wrap: wrap;">
              <span>Courier: <strong><?= e((string)$batch['courier_name']) ?></strong> (ID #<?= $courierEmpId ?>)</span>
              <span>Submitted: <strong><?= e(date('M j, Y g:i A', strtotime((string)$batch['submitted_at']))) ?> UTC</strong></span>
              <span>Handover: <strong><?= $completedHandover ?> / <?= $totalAllocs ?></strong></span>
              <span>Verified: <strong><?= $verifiedCount ?> / <?= $totalAllocs ?></strong></span>
            </div>
            <?php if ($bStatus === 'verified'): ?>
              <div class="caption muted" style="margin-top: 0.25rem;">
                Batch Verified:
                <strong><?= !empty($batch['batch_verified_by_name']) ? e((string)$batch['batch_verified_by_name']) : 'Auto / Mixed Verifiers' ?></strong>
                <?php if (!empty($batch['verified_at'])): ?>
                  on <?= e((string)$batch['verified_at']) ?> UTC
                <?php endif; ?>
              </div>
            <?php endif; ?>
            <?php if (!empty($batch['notes'])): ?>
              <div class="caption muted" style="margin-top: 0.25rem;">Notes: <?= e((string)$batch['notes']) ?></div>
            <?php endif; ?>
          </div>

          <div style="display: flex; flex-direction: column; align-items: flex-end; gap: 0.5rem;">
            <div style="text-align: right;">
              <span class="caption muted" style="display: block; text-transform: uppercase; font-weight: 600;">Expected Remittance Total (Subtotal)</span>
              <strong style="font-size: 1.25rem; color: var(--color-primary, #047857);">
                ₱<?= number_format((float)$batch['expected_total'], 2) ?>
              </strong>
            </div>
            <?php if ($remainingEligible > 0 && in_array($bStatus, ['submitted', 'completed'], true)): ?>
              <?php if (!$isManagerOperator): ?>
                <span class="caption muted">Read-only oversight</span>
              <?php elseif ($isOwnCourierBatch): ?>
                <span class="badge secondary">Self-verification blocked</span>
              <?php else: ?>
                <form method="post" action="<?= e($postActionPrefix) ?>/<?= $remitId ?>/process" style="margin: 0;">
                  <?= \App\Core\CSRF::field() ?>
                  <button type="submit" class="btn primary btn-sm">Collect &amp; Verify Remaining (<?= $remainingEligible ?>)</button>
                </form>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>

        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Order Ref</th>
                <th>Customer</th>
                <th style="text-align: right;">Allocation Subtotal</th>
                <th>Handover Status</th>
                <th>Verification Status</th>
                <th style="text-align: right;">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($allocations as $alloc): ?>
                <?php
                $allocId = (int)$alloc['allocation_id'];
                $ordId = (int)$alloc['order_id'];
                $hStatus = (string)$alloc['handover_status'];
                $vStatus = (string)$alloc['verification_status'];
                $hMeta = $allocHandoverMeta($hStatus);
                $vMeta = $allocVerificationMeta($vStatus);
                $modeText = $verificationModeLabel($alloc['verification_mode'] !== null ? (string)$alloc['verification_mode'] : null);
                $allocAmt = (float)$alloc['allocated_amount'];
                $delivFee = (float)($alloc['delivery_fee'] ?? 0.0);
                ?>
                <tr id="bm-allocation-row-<?= $allocId ?>">
                  <td style="font-family: var(--font-mono, monospace); font-weight: 600;">
                    #ORD-<?= $ordId ?>
                    <div class="caption muted" style="font-weight: 400;">Allocation #<?= $allocId ?></div>
                  </td>
                  <td>
                    <div style="font-weight: 500;"><?= e((string)$alloc['customer_name']) ?></div>
                    <div class="caption muted"><?= e(date('M j, Y', strtotime((string)$alloc['order_date']))) ?></div>
                  </td>
                  <td style="text-align: right;">
                    <strong>₱<?= number_format($allocAmt, 2) ?></strong>
                    <?php if ($delivFee > 0): ?>
                      <div class="caption muted">Excludes ₱<?= number_format($delivFee, 2) ?> delivery fee</div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <span class="badge <?= $hMeta['badge'] ?>"><?= e($hMeta['label']) ?></span>
                    <?php if ($hStatus === 'completed'): ?>
                      <div class="caption muted" style="margin-top: 0.25rem;">
                        <?= e((string)($alloc['completed_by_name'] ?? 'Staff')) ?>
                        <?php if (!empty($alloc['completed_at'])): ?>
                          · <?= e((string)$alloc['completed_at']) ?> UTC
                        <?php endif; ?>
                      </div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <span class="badge <?= $vMeta['badge'] ?>"><?= e($vMeta['label']) ?></span>
                    <?php if ($vStatus === 'verified'): ?>
                      <div class="caption muted" style="margin-top: 0.25rem;">
                        <?php if ($modeText !== ''): ?>
                          <?= e($modeText) ?>
                        <?php endif; ?>
                        <?php if (!empty($alloc['verified_by_name'])): ?>
                          by <?= e((string)$alloc['verified_by_name']) ?>
                        <?php endif; ?>
                        <?php if (!empty($alloc['verified_at'])): ?>
                          · <?= e((string)$alloc['verified_at']) ?> UTC
                        <?php endif; ?>
                      </div>
                    <?php endif; ?>
                  </td>
                  <td style="text-align: right;">
                    <?php if ($vStatus === 'verified'): ?>
                      <span class="caption muted">✓ Verified</span>
                    <?php elseif (!$isManagerOperator): ?>
                      <span class="caption muted">Read-only oversight</span>
                    <?php elseif ($isOwnCourierBatch): ?>
                      <span class="badge secondary">Self-verification blocked</span>
                    <?php elseif ($hStatus === 'submitted'): ?>
                      <form method="post" action="<?= e($postActionPrefix) ?>/allocations/<?= $allocId ?>/collect" style="margin: 0; display: inline-block;">
                        <?= \App\Core\CSRF::field() ?>
                        <button type="submit" class="btn primary btn-sm">Collect &amp; Verify</button>
                      </form>
                    <?php else: ?>
                      <form method="post" action="<?= e($postActionPrefix) ?>/allocations/<?= $allocId ?>/verify" style="margin: 0; display: inline-block;">
                        <?= \App\Core\CSRF::field() ?>
                        <button type="submit" class="btn primary btn-sm">Verify Handover</button>
                      </form>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
