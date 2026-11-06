<?php
require __DIR__ . '/_nav.php';
$q = $searchQuery ?? '';
$posFilter = $positionFilter ?? '';
$statusFilter = $workStatusFilter ?? '';
?>

<div class="page-head">
  <div>
    <p class="eyebrow">Pet Store Personnel</p>
    <h1>Employees</h1>
    <p class="muted">Read-only personnel roster for Pet Store staff, employment details, and active work presence.</p>
  </div>
</div>

<!-- Search & Filtering Bar -->
<section class="card" style="margin-bottom: 1.5rem; padding: 1rem 1.25rem;">
  <form method="get" action="/manager/employees" class="row" style="gap: 1rem; align-items: flex-end; flex-wrap: wrap;">
    <?php if (!empty($isSuper)): ?>
      <input type="hidden" name="unit_id" value="<?= (int)$unitId ?>">
    <?php endif; ?>

    <div class="field" style="margin: 0; min-width: 220px; flex: 1;">
      <label for="emp-search" class="caption" style="display: block; margin-bottom: 0.25rem;">Search Name or Email</label>
      <input id="emp-search" type="search" name="q" value="<?= e($q) ?>" placeholder="e.g. David, Reyes, staff@..." style="width: 100%;">
    </div>

    <div class="field" style="margin: 0; width: 200px;">
      <label for="emp-pos" class="caption" style="display: block; margin-bottom: 0.25rem;">Position</label>
      <select id="emp-pos" name="position" style="width: 100%;">
        <option value="">All Positions</option>
        <?php foreach ($positions as $pos): ?>
          <option value="<?= e($pos) ?>" <?= $posFilter === $pos ? 'selected' : '' ?>><?= e($pos) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field" style="margin: 0; width: 170px;">
      <label for="emp-ws" class="caption" style="display: block; margin-bottom: 0.25rem;">Work Status</label>
      <select id="emp-ws" name="work_status" style="width: 100%;">
        <option value="">All Statuses</option>
        <option value="On Duty" <?= $statusFilter === 'On Duty' ? 'selected' : '' ?>>On Duty</option>
        <option value="Free" <?= $statusFilter === 'Free' ? 'selected' : '' ?>>Free</option>
        <option value="Not Present" <?= $statusFilter === 'Not Present' ? 'selected' : '' ?>>Not Present</option>
      </select>
    </div>

    <div class="row" style="gap: 0.5rem; align-items: center;">
      <button type="submit" class="btn secondary">Filter</button>
      <?php if ($q !== '' || $posFilter !== '' || $statusFilter !== ''): ?>
        <a href="/manager/employees<?= $unitQuery ?>" class="btn ghost">Reset</a>
      <?php endif; ?>
    </div>
  </form>
</section>

<!-- Employee Roster Table -->
<section class="card">
  <div class="row" style="justify-content: space-between; align-items: baseline; margin-bottom: 1rem;">
    <div>
      <h2 style="margin: 0;">Pet Store Employee Roster (<?= count($employees) ?>)</h2>
      <p class="caption" style="margin: 0.2rem 0 0 0;">Read-only roster. Employee account administration and role assignments are managed by the System Administrator.</p>
    </div>
    <span class="caption"><?= count($employees) ?> staff members</span>
  </div>

  <?php if (empty($employees)): ?>
    <div class="empty">No employees found matching the specified filters.</div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th style="width: 48px;">Photo</th>
            <th>Employee Name</th>
            <th>Employee ID</th>
            <th>Position</th>
            <th>Email</th>
            <th>Contact #</th>
            <th>Date Hired</th>
            <th>Work Status</th>
            <th style="text-align: right;">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($employees as $emp):
            $initials = strtoupper(substr($emp['first_name'], 0, 1) . substr($emp['last_name'], 0, 1));
            $ws = $emp['work_status'] ?? 'Free';
            $wsBadgeClass = $ws === 'On Duty' ? 'badge-success' : ($ws === 'Free' ? 'secondary' : 'badge-danger');
          ?>
            <tr>
              <td>
                <div style="width: 36px; height: 36px; border-radius: 50%; background: #e0f2fe; color: #0369a1; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 0.85rem;" aria-hidden="true">
                  <?= e($initials) ?>
                </div>
              </td>
              <td>
                <strong><?= e($emp['first_name'] . ' ' . $emp['last_name']) ?></strong>
              </td>
              <td><code>EMP-<?= str_pad((string)$emp['employee_id'], 4, '0', STR_PAD_LEFT) ?></code></td>
              <td><?= e($emp['position']) ?></td>
              <td><?= e($emp['email']) ?></td>
              <td><?= e($emp['phone'] ?: '—') ?></td>
              <td><?= e(date('M j, Y', strtotime((string)$emp['created_at']))) ?></td>
              <td>
                <span class="badge <?= $wsBadgeClass ?>">
                  <?= e($ws) ?>
                </span>
              </td>
              <td style="text-align: right;">
                <button type="button" class="btn ghost btn-sm" data-modal-open="emp-detail-<?= (int)$emp['employee_id'] ?>">
                  View Profile
                </button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<!-- Employee Detail Modals (Read-Only) -->
<?php foreach ($employees as $emp):
  $initials = strtoupper(substr($emp['first_name'], 0, 1) . substr($emp['last_name'], 0, 1));
  $ws = $emp['work_status'] ?? 'Free';
  $wsBadgeClass = $ws === 'On Duty' ? 'badge-success' : ($ws === 'Free' ? 'secondary' : 'badge-danger');
?>
  <dialog class="modal" id="emp-detail-<?= (int)$emp['employee_id'] ?>" aria-labelledby="emp-title-<?= (int)$emp['employee_id'] ?>">
    <div class="modal-head">
      <h3 id="emp-title-<?= (int)$emp['employee_id'] ?>">Employee Profile</h3>
      <button type="button" class="btn ghost btn-sm" data-modal-close>&times; Close</button>
    </div>
    <div class="modal-body">
      <div style="display: flex; gap: 1rem; align-items: center; margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 1px solid var(--border, #e5e7eb);">
        <div style="width: 56px; height: 56px; border-radius: 50%; background: #e0f2fe; color: #0369a1; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 1.25rem;">
          <?= e($initials) ?>
        </div>
        <div>
          <h4 style="margin: 0; font-size: 1.2rem;"><?= e($emp['first_name'] . ' ' . $emp['last_name']) ?></h4>
          <div class="caption"><?= e($emp['position']) ?> · Pet Store</div>
          <div style="margin-top: 0.25rem;">
            <span class="badge <?= $wsBadgeClass ?>">Work Status: <?= e($ws) ?></span>
          </div>
        </div>
      </div>

      <h5 style="margin: 0 0 0.5rem 0; font-size: 0.95rem;">Personal Information</h5>
      <div style="background: var(--bg-subtle, #f9fafb); padding: 0.75rem 1rem; border-radius: 6px; margin-bottom: 1.25rem;">
        <div class="row" style="justify-content: space-between; margin-bottom: 0.35rem;">
          <span class="caption">Full Name:</span>
          <strong><?= e($emp['first_name'] . ' ' . $emp['last_name']) ?></strong>
        </div>
        <div class="row" style="justify-content: space-between; margin-bottom: 0.35rem;">
          <span class="caption">Email Address:</span>
          <span><?= e($emp['email']) ?></span>
        </div>
        <div class="row" style="justify-content: space-between;">
          <span class="caption">Contact Number:</span>
          <span><?= e($emp['phone'] ?: 'None registered') ?></span>
        </div>
      </div>

      <h5 style="margin: 0 0 0.5rem 0; font-size: 0.95rem;">Employment Information</h5>
      <div style="background: var(--bg-subtle, #f9fafb); padding: 0.75rem 1rem; border-radius: 6px; margin-bottom: 1.25rem;">
        <div class="row" style="justify-content: space-between; margin-bottom: 0.35rem;">
          <span class="caption">Employee ID:</span>
          <code>EMP-<?= str_pad((string)$emp['employee_id'], 4, '0', STR_PAD_LEFT) ?></code>
        </div>
        <div class="row" style="justify-content: space-between; margin-bottom: 0.35rem;">
          <span class="caption">Default Position:</span>
          <strong><?= e($emp['position']) ?></strong>
        </div>
        <div class="row" style="justify-content: space-between; margin-bottom: 0.35rem;">
          <span class="caption">Date Hired:</span>
          <span><?= e(date('F j, Y', strtotime((string)$emp['created_at']))) ?></span>
        </div>
        <div class="row" style="justify-content: space-between;">
          <span class="caption">Employment Status:</span>
          <span class="badge badge-success"><?= e(ucfirst((string)$emp['employee_status'])) ?></span>
        </div>
      </div>

      <p class="caption" style="margin: 0; font-style: italic;">
        Note: Employee profile, account, and role administration permissions are reserved for the System Administrator.
      </p>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn ghost btn-sm" data-modal-close>Close</button>
    </div>
  </dialog>
<?php endforeach; ?>
