<?php
require __DIR__ . '/_nav.php';
$unitQuery = !empty($isSuper) ? '?unit_id=' . (int)$unitId : '';
if (!empty($isPetStore)) {
    ?>
<div class="page-head">
  <div>
    <p class="eyebrow"><?= e($unitName) ?> Administration</p>
    <h1><?= e($unitName) ?> System Administrator</h1>
    <p class="muted">Manage employee accounts, default positions, and role assignments strictly isolated to <strong><?= e($unitName) ?></strong>.</p>
  </div>
</div>

<section class="grid grid-4" style="margin-bottom: 2rem;" data-reveal>
  <article class="card stat-card">
    <span class="eyebrow">Business Unit Scope</span>
    <strong class="stat-value" style="font-size: 1.35rem;"><?= e($unitName) ?></strong>
    <p class="caption">Unit ID #<?= (int)$unitId ?> · Cross-unit access blocked</p>
  </article>
  <article class="card stat-card">
    <span class="eyebrow">Unit Employees</span>
    <strong class="stat-value"><?= count($employees) ?></strong>
    <p class="caption">Staff accounts assigned to <?= e($unitName) ?></p>
  </article>
  <article class="card stat-card">
    <span class="eyebrow">Unit System Admins</span>
    <strong class="stat-value"><?= (int)$sysAdminCount ?></strong>
    <p class="caption">Active <code>SYSTEM_ADMINISTRATOR</code> holders in unit</p>
  </article>
  <article class="card stat-card">
    <span class="eyebrow">Unit Positions</span>
    <strong class="stat-value"><?= (int)$activePositionCount ?> <span class="muted" style="font-size: 1rem;">/ <?= (int)$totalPositionCount ?></span></strong>
    <p class="caption">Active default positions for <?= e($unitName) ?></p>
  </article>
</section>

<div class="grid grid-2" style="align-items: start; gap: 1.5rem;">
  <?php require __DIR__ . '/_pet_employee_form.php'; ?>
  <?php require __DIR__ . '/_pet_roster.php'; ?>
</div>
<?php
    return;
}
$activePositionsCount = 0;
foreach ($positions as $pos) {
    if ($pos['position_status'] === 'active') {
        $activePositionsCount++;
    }
}
?>

<div class="page-head">
  <div>
    <p class="eyebrow"><?= e($unitName) ?> Administration</p>
    <h1><?= e($unitName) ?> System Administrator</h1>
    <p class="muted">Manage employee accounts, default positions, and role assignments strictly isolated to <strong><?= e($unitName) ?></strong>.</p>
  </div>
  <div class="row">
    <a href="/sysadmin/employees<?= $unitQuery ?>" class="btn">+ Add Unit Employee</a>
    <a href="/sysadmin/positions<?= $unitQuery ?>" class="btn secondary">Manage Positions</a>
  </div>
</div>

<section class="grid grid-4" style="margin-bottom: 2rem;" data-reveal>
  <article class="card stat-card">
    <span class="eyebrow">Business Unit Scope</span>
    <strong class="stat-value" style="font-size: 1.35rem;"><?= e($unitName) ?></strong>
    <p class="caption">Unit ID #<?= (int)$unitId ?> · Cross-unit access blocked</p>
  </article>
  <article class="card stat-card">
    <span class="eyebrow">Unit Employees</span>
    <strong class="stat-value"><?= count($employees) ?></strong>
    <p class="caption">Staff accounts assigned to <?= e($unitName) ?></p>
  </article>
  <article class="card stat-card">
    <span class="eyebrow">Unit System Admins</span>
    <strong class="stat-value"><?= (int)$sysAdminCount ?></strong>
    <p class="caption">Active <code>SYSTEM_ADMINISTRATOR</code> holders in unit</p>
  </article>
  <article class="card stat-card">
    <span class="eyebrow">Unit Positions</span>
    <strong class="stat-value"><?= $activePositionsCount ?> <span class="muted" style="font-size: 1rem;">/ <?= count($positions) ?></span></strong>
    <p class="caption">Active default positions for <?= e($unitName) ?></p>
  </article>
</section>

<div class="grid grid-2" style="align-items: start; gap: 1.5rem;">
  <section class="card">
    <div class="row" style="justify-content: space-between; margin-bottom: 1rem;">
      <div>
        <h2><?= e($unitName) ?> Employees</h2>
        <p class="caption">Accounts scoped to this business unit.</p>
      </div>
      <a href="/sysadmin/employees<?= $unitQuery ?>" class="btn ghost btn-sm">Manage all &rarr;</a>
    </div>
    <?php if (!$employees): ?>
      <div class="empty">No employee accounts assigned to <?= e($unitName) ?> yet.</div>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Employee</th>
              <th>Default Position</th>
              <th>Unit Roles</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach (array_slice($employees, 0, 8) as $emp): ?>
              <tr>
                <td>
                  <strong><?= e($emp['first_name'] . ' ' . $emp['last_name']) ?></strong>
                  <div class="caption"><?= e($emp['email']) ?></div>
                </td>
                <td><?= e($emp['position']) ?></td>
                <td><span class="caption"><?= e($emp['unit_roles'] ?: '—') ?></span></td>
                <td><span class="badge status-<?= e($emp['account_status']) ?>"><?= e($emp['account_status']) ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <section class="card">
    <div class="row" style="justify-content: space-between; margin-bottom: 1rem;">
      <div>
        <h2>Recent <?= e($unitName) ?> Audit Activity</h2>
        <p class="caption">Administrative actions logged within <?= e($unitName) ?>.</p>
      </div>
      <a href="/sysadmin/roles<?= $unitQuery ?>" class="btn ghost btn-sm">Roles &amp; Permissions &rarr;</a>
    </div>
    <?php if (!$recentLogs): ?>
      <div class="empty">No unit-specific audit events recorded yet.</div>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>When</th>
              <th>Actor</th>
              <th>Action</th>
              <th>Summary</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recentLogs as $log): ?>
              <tr>
                <td class="caption"><?= e($log['created_at']) ?></td>
                <td class="caption"><?= e($log['actor_email'] ?? 'system') ?></td>
                <td><code><?= e($log['action_code']) ?></code></td>
                <td><?= e($log['summary']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
</div>

