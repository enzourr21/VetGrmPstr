<?php
$title = $title ?? 'Employees · Grooming Services Receptionist';
$q = $searchQuery ?? '';
$wsFilter = $workStatusFilter ?? '';
$allEmployees = $employees ?? [];
$totalEmployees = count($allEmployees);
?>

<div class="page-head" style="margin-bottom: 1.5rem;">
  <div>
    <p class="eyebrow"><?= e($unitName) ?> Personnel</p>
    <h1>Employees</h1>
    <p class="muted">Read-only personnel directory for <?= e($unitName) ?> groomers, stylists, bathers, and studio staff.</p>
  </div>
</div>

<!-- Search & Filtering Bar -->
<section class="card" style="margin-bottom: 1.5rem; padding: 1rem 1.25rem;">
  <form method="get" action="/grooming/receptionist/employees" class="row" style="gap: 1rem; align-items: flex-end; flex-wrap: wrap;">
    <div class="field" style="margin: 0; min-width: 240px; flex: 1;">
      <label for="emp-search" class="caption" style="display: block; margin-bottom: 0.25rem;">Search Name or Email</label>
      <input id="emp-search" type="search" name="q" value="<?= e($q) ?>" placeholder="Search Name or Email... (Press Enter)" class="search-input">
    </div>

    <div class="field" style="margin: 0; width: 170px;">
      <label for="emp-ws" class="caption" style="display: block; margin-bottom: 0.25rem;">Work Presence</label>
      <select id="emp-ws" name="work_status" style="width: 100%;" onchange="this.form.submit()">
        <option value="">All Statuses</option>
        <option value="On Duty" <?= $wsFilter === 'On Duty' ? 'selected' : '' ?>>On Duty</option>
        <option value="Free" <?= $wsFilter === 'Free' ? 'selected' : '' ?>>Free</option>
        <option value="Not Present" <?= $wsFilter === 'Not Present' ? 'selected' : '' ?>>Not Present</option>
      </select>
    </div>

    <?php if ($q !== '' || $wsFilter !== ''): ?>
      <div class="row" style="align-items: center;">
        <a href="/grooming/receptionist/employees" class="btn ghost btn-sm">Reset</a>
      </div>
    <?php endif; ?>
  </form>
</section>

<!-- Employee Directory Table (Read-Only) -->
<section class="card">
  <div class="row" style="justify-content: space-between; align-items: baseline; margin-bottom: 1rem;">
    <div>
      <h2 style="margin: 0; font-size: 1.25rem;"><?= e($unitName) ?> Personnel Directory (<?= $totalEmployees ?>)</h2>
      <p class="caption muted" style="margin: 0.25rem 0 0 0;">
        Read-only staff roster for Grooming Services. Employee positions, roles, and administrative rights are managed by Administrators.
      </p>
    </div>
    <span class="caption muted"><?= $totalEmployees ?> staff members</span>
  </div>

  <?php if (empty($allEmployees)): ?>
    <div class="empty" style="padding: 2.5rem 1rem; text-align: center;">
      <p style="margin: 0; font-size: 1rem; color: var(--color-muted, #6b7280);">
        No personnel found matching the specified filters.
      </p>
    </div>
  <?php else: ?>
    <div style="overflow-x: auto; width: 100%;">
      <table style="width: 100%; border-collapse: collapse;">
        <thead>
          <tr style="border-bottom: 1px solid var(--color-border, #e5e7eb); text-align: left; background: var(--color-bg-subtle, #f9fafb);">
            <th style="padding: 0.75rem 1rem; font-size: 0.8rem; font-weight: 600; text-transform: uppercase;">Employee Name</th>
            <th style="padding: 0.75rem 1rem; font-size: 0.8rem; font-weight: 600; text-transform: uppercase;">Position</th>
            <th style="padding: 0.75rem 1rem; font-size: 0.8rem; font-weight: 600; text-transform: uppercase;">Contact Details</th>
            <th style="padding: 0.75rem 1rem; font-size: 0.8rem; font-weight: 600; text-transform: uppercase; text-align: center;">Work Presence</th>
            <th style="padding: 0.75rem 1rem; font-size: 0.8rem; font-weight: 600; text-transform: uppercase; text-align: center;">Today's Assignments</th>
            <th style="padding: 0.75rem 1rem; font-size: 0.8rem; font-weight: 600; text-transform: uppercase; text-align: center;">Account Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($allEmployees as $emp): ?>
            <?php
            $ws = (string)($emp['work_status'] ?? 'Free');
            $wsBadge = 'badge-secondary';
            if ($ws === 'On Duty') {
                $wsBadge = 'badge-success';
            } elseif ($ws === 'Free') {
                $wsBadge = 'badge-info';
            } elseif ($ws === 'Not Present') {
                $wsBadge = 'badge-danger';
            }
            ?>
            <tr style="border-bottom: 1px solid var(--color-border, #f3f4f6);">
              <td style="padding: 0.875rem 1rem;">
                <div style="font-weight: 600; color: var(--color-text, #111827);">
                  <?= e($emp['first_name'] . ' ' . $emp['last_name']) ?>
                </div>
                <span class="caption muted">ID #<?= (int)$emp['employee_id'] ?></span>
              </td>
              <td style="padding: 0.875rem 1rem;">
                <span class="badge" style="background: var(--color-bg-subtle, #f3f4f6); color: var(--color-text, #374151); font-weight: 500;">
                  <?= e($emp['position'] ?: 'Groomer') ?>
                </span>
              </td>
              <td style="padding: 0.875rem 1rem;">
                <div style="font-size: 0.9rem; color: var(--color-text, #374151);">
                  <?= e($emp['email'] ?? '—') ?>
                </div>
                <div class="caption muted">
                  <?= e($emp['phone'] ?? '—') ?>
                </div>
              </td>
              <td style="padding: 0.875rem 1rem; text-align: center;">
                <span class="badge <?= $wsBadge ?>" style="font-weight: 600;">
                  <?= e($ws) ?>
                </span>
              </td>
              <td style="padding: 0.875rem 1rem; text-align: center; font-weight: 600; color: var(--color-text, #111827);">
                <?= (int)($emp['today_assignments'] ?? 0) ?>
              </td>
              <td style="padding: 0.875rem 1rem; text-align: center;">
                <span class="badge <?= ($emp['employee_status'] ?? 'active') === 'active' ? 'badge-success' : 'badge-danger' ?>">
                  <?= ucfirst((string)($emp['employee_status'] ?? 'active')) ?>
                </span>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

