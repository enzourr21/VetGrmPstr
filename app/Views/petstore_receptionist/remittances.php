<?php
$title = $title ?? 'Remittances · Pet Store Receptionist';
$q = $searchQuery ?? '';
$isReceptionist = !empty($isReceptionist);
$empId = (int)($empId ?? 0);
$batches = $remittanceBatches ?? [];
$totalBatches = count($batches);

$batchStatusMeta = static function (string $st): array {
    return match ($st) {
        'submitted' => ['label' => 'Awaiting Handover', 'badge' => 'warn'],
        'completed' => ['label' => 'Handover Completed', 'badge' => 'info'],
        'verified'  => ['label' => 'Verified', 'badge' => 'ok'],
        default     => ['label' => ucfirst($st), 'badge' => 'neutral'],
    };
};

$allocHandoverMeta = static function (string $hs): array {
    return $hs === 'completed'
        ? ['label' => 'Handover Completed', 'badge' => 'info']
        : ['label' => 'Awaiting Handover', 'badge' => 'warn'];
};

$allocVerificationMeta = static function (string $hs, string $vs): array {
    if ($vs === 'verified') {
        return ['label' => 'Verified', 'badge' => 'ok'];
    }
    if ($hs === 'completed') {
        return ['label' => 'Handover Completed', 'badge' => 'info'];
    }
    return ['label' => 'Awaiting Handover', 'badge' => 'warn'];
};
?>

<div class="page-head" style="margin-bottom: 1.5rem;">
  <div>
    <p class="eyebrow">Pet Store Workspace</p>
    <h1>COD Remittances</h1>
    <p class="muted">Receive courier COD cash handovers per order allocation (using remittable purchase subtotals excluding delivery fees).</p>
  </div>
</div>

<?php if (!$isReceptionist): ?>
  <div class="alert alert-info" style="margin-bottom: 1.25rem;">
    <strong>Administrative Oversight Mode (Read-Only):</strong> Operational COD remittance handover actions require an active Pet Store <code>RECEPTIONIST</code> role assignment.
  </div>
<?php endif; ?>

<!-- Search Bar -->
<section class="card" style="margin-bottom: 1.5rem; padding: 1rem 1.25rem;">
  <form method="get" action="/petstore/receptionist/remittances" class="row" style="gap: 1rem; align-items: flex-end; flex-wrap: wrap;">
    <div class="field" style="margin: 0; min-width: 260px; flex: 1;">
      <label for="remit-search" class="caption" style="display: block; margin-bottom: 0.25rem;">Search Batch Reference, Courier Name, or Order #</label>
      <input id="remit-search" type="search" name="q" value="<?= e($q) ?>" placeholder="Search RMT-..., Courier Name, or #ORD-..." class="search-input">
    </div>
    <?php if ($q !== ''): ?>
      <div class="row" style="align-items: center;">
        <a href="/petstore/receptionist/remittances" class="btn ghost btn-sm">Reset</a>
      </div>
    <?php endif; ?>
  </form>
</section>

<?php if (empty($batches)): ?>
  <section class="card">
    <div class="empty" style="padding: 3rem 1rem; text-align: center;">
      <p style="margin: 0; font-size: 1rem; color: var(--color-muted, #6b7280);">
        No courier COD remittance batches found<?= $q !== '' ? ' matching "' . e($q) . '"' : '' ?>.
      </p>
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
      $isOwnCourierBatch = $isReceptionist && $empId > 0 && $courierEmpId === $empId;
      $allocations = $batch['allocations'] ?? [];
      $totalAllocs = (int)($batch['allocation_count'] ?? count($allocations));
      $completedAllocs = (int)($batch['completed_handover_count'] ?? 0);
      ?>
      <section class="card" id="remittance-batch-<?= $remitId ?>" style="padding: 1.25rem;">
        <div class="row" style="justify-content: space-between; align-items: flex-start; gap: 1rem; flex-wrap: wrap; padding-bottom: 1rem; border-bottom: 1px solid var(--color-border, #e5e7eb); margin-bottom: 1rem;">
          <div>
            <div style="display: flex; align-items: center; gap: 0.65rem; flex-wrap: wrap;">
              <h2 style="margin: 0; font-size: 1.15rem; font-family: var(--font-mono, monospace);">
                <?= e((string)$batch['remittance_reference']) ?>
              </h2>
              <span class="status <?= $bMeta['status'] ?>"><?= e($bMeta['label']) ?></span>
            </div>
            <div class="caption muted" style="margin-top: 0.35rem; display: flex; gap: 1rem; flex-wrap: wrap;">
              <span>Courier: <strong><?= e((string)$batch['courier_name']) ?></strong> (ID #<?= $courierEmpId ?>)</span>
              <span>Submitted: <strong><?= e(date('M j, Y g:i A', strtotime((string)$batch['submitted_at']))) ?> UTC</strong></span>
              <span>Progress: <strong><?= $completedAllocs ?> / <?= $totalAllocs ?> handed over</strong></span>
            </div>
            <?php if (!empty($batch['notes'])): ?>
              <div class="caption muted" style="margin-top: 0.25rem;">
                Notes: <?= e((string)$batch['notes']) ?>
              </div>
            <?php endif; ?>
          </div>

          <div style="text-align: right;">
            <span class="caption muted" style="display: block; text-transform: uppercase; font-weight: 600;">Expected Remittance Total (Subtotal)</span>
            <strong style="font-size: 1.25rem; color: var(--color-primary, #047857);">
              ₱<?= number_format((float)$batch['expected_total'], 2) ?>
            </strong>
          </div>
        </div>

        <div style="overflow-x: auto; width: 100%;">
          <table style="width: 100%; border-collapse: collapse;">
            <thead>
              <tr style="border-bottom: 1px solid var(--color-border, #e5e7eb); text-align: left; background: var(--color-bg-subtle, #f9fafb);">
                <th style="padding: 0.65rem 0.85rem; font-size: 0.78rem; font-weight: 600; text-transform: uppercase;">Order Ref</th>
                <th style="padding: 0.65rem 0.85rem; font-size: 0.78rem; font-weight: 600; text-transform: uppercase;">Customer</th>
                <th style="padding: 0.65rem 0.85rem; font-size: 0.78rem; font-weight: 600; text-transform: uppercase; text-align: right;">Allocation Amount (Subtotal)</th>
                <th style="padding: 0.65rem 0.85rem; font-size: 0.78rem; font-weight: 600; text-transform: uppercase; text-align: center;">Handover Status</th>
                <th style="padding: 0.65rem 0.85rem; font-size: 0.78rem; font-weight: 600; text-transform: uppercase; text-align: center;">Verification Status</th>
                <th style="padding: 0.65rem 0.85rem; font-size: 0.78rem; font-weight: 600; text-transform: uppercase; text-align: right;">Action</th>
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
                $vMeta = $allocVerificationMeta($hStatus, $vStatus);
                $allocAmt = (float)$alloc['allocated_amount'];
                $delivFee = (float)($alloc['delivery_fee'] ?? 0.0);
                ?>
                <tr id="allocation-row-<?= $allocId ?>" style="border-bottom: 1px solid var(--color-border, #f3f4f6);">
                  <td style="padding: 0.75rem 0.85rem; font-family: var(--font-mono, monospace); font-weight: 600;">
                    #ORD-<?= $ordId ?>
                    <div class="caption muted" style="font-weight: 400;">Allocation #<?= $allocId ?></div>
                  </td>
                  <td style="padding: 0.75rem 0.85rem;">
                    <div style="font-weight: 500;"><?= e((string)$alloc['customer_name']) ?></div>
                    <div class="caption muted"><?= e(date('M j, Y', strtotime((string)$alloc['order_date']))) ?></div>
                  </td>
                  <td style="padding: 0.75rem 0.85rem; text-align: right;">
                    <strong style="font-size: 0.95rem;">₱<?= number_format($allocAmt, 2) ?></strong>
                    <?php if ($delivFee > 0): ?>
                      <div class="caption muted">Excludes ₱<?= number_format($delivFee, 2) ?> delivery fee</div>
                    <?php endif; ?>
                  </td>
                  <td style="padding: 0.75rem 0.85rem; text-align: center;">
                    <span class="status <?= $hMeta['status'] ?>"><?= e($hMeta['label']) ?></span>
                    <?php if ($hStatus === 'completed' && !empty($alloc['completed_by_name'])): ?>
                      <div class="caption muted" style="margin-top: 0.2rem;">
                        by <?= e((string)$alloc['completed_by_name']) ?>
                      </div>
                    <?php endif; ?>
                  </td>
                  <td style="padding: 0.75rem 0.85rem; text-align: center;">
                    <span class="status <?= $vMeta['status'] ?>"><?= e($vMeta['label']) ?></span>
                    <?php if ($vStatus === 'verified' && !empty($alloc['verification_mode'])): ?>
                      <div class="caption muted" style="margin-top: 0.2rem;">
                        <?= $alloc['verification_mode'] === 'auto_setting' ? 'Auto-Verified' : e(ucwords(str_replace('_', ' ', (string)$alloc['verification_mode']))) ?>
                      </div>
                    <?php endif; ?>
                  </td>
                  <td style="padding: 0.75rem 0.85rem; text-align: right;">
                    <?php if ($hStatus === 'submitted'): ?>
                      <?php if (!$isReceptionist): ?>
                        <span class="caption muted">Read-only oversight</span>
                      <?php elseif ($isOwnCourierBatch): ?>
                        <span class="meta-label" title="Assigned courier cannot complete own handover">Self-handover blocked</span>
                      <?php else: ?>
                        <form method="post" action="/petstore/receptionist/remittances/allocations/<?= $allocId ?>/complete" style="margin: 0; display: inline-block;">
                          <?= \App\Core\CSRF::field() ?>
                          <button type="submit" class="btn primary btn-sm">Complete Handover</button>
                        </form>
                      <?php endif; ?>
                    <?php else: ?>
                      <span class="caption muted">✓ <?= $vStatus === 'verified' ? 'Verified' : 'Handover Completed' ?></span>
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
