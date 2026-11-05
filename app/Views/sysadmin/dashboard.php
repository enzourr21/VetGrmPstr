<?php
require __DIR__ . '/_nav.php';
$unitQuery = !empty($isSuper) ? '?unit_id=' . (int)$unitId : '';
if (!empty($isPetStore)) {
    ?>
<div class="page-head">
  <div>
    <h1><?= e($unitName) ?> Administration</h1>
    <p class="muted">System Administrator · <?= e($unitName) ?> · Unit #<?= (int)$unitId ?></p>
  </div>
</div>

<section class="grid grid-4" style="margin-bottom: 2rem;" aria-label="Unit at a glance">
  <article class="card stat-card">
    <span class="eyebrow">Total Employees</span>
    <strong class="stat-value"><?= (int)$totalEmployees ?></strong>
    <p class="caption">Total staff accounts in <?= e($unitName) ?></p>
  </article>
  <article class="card stat-card">
    <span class="eyebrow">Active Employees</span>
    <strong class="stat-value"><?= (int)$activeEmployees ?></strong>
    <p class="caption">Active staff with system access</p>
  </article>
  <article class="card stat-card">
    <span class="eyebrow">System Roles</span>
    <strong class="stat-value"><?= (int)$totalRoles ?></strong>
    <p class="caption">Configured roles available in unit</p>
  </article>
  <article class="card stat-card">
    <span class="eyebrow">Active Positions</span>
    <strong class="stat-value"><?= (int)$activePositionCount ?> <span class="muted" style="font-size: 1rem; font-weight: 500;">/ <?= (int)$totalPositionCount ?></span></strong>
    <p class="caption">Active of total configured titles</p>
  </article>
</section>

<section class="card overview-card" style="margin-bottom: 2rem;" aria-labelledby="attention-heading">
  <div class="overview-card-header">
    <div>
      <h2 id="attention-heading" style="margin: 0; font-size: 1.25rem;">Administrative Attention</h2>
      <p class="caption" style="margin: 0.25rem 0 0;">Items and accounts in <?= e($unitName) ?> requiring administrative follow-up.</p>
    </div>
    <span class="badge <?= !empty($attentionItems) ? 'badge-attention-active' : 'badge-attention-clear' ?>">
      <?= count($attentionItems) ?> <?= count($attentionItems) === 1 ? 'item' : 'items' ?>
    </span>
  </div>

  <?php if (empty($attentionItems)): ?>
    <div class="empty empty-subtle">
      <p class="caption" style="margin: 0;">
        All employee accounts and invitations in <?= e($unitName) ?> are currently in good standing. No immediate administrative actions are required.
      </p>
    </div>
  <?php else: ?>
    <ul class="list-clean attention-list">
      <?php foreach ($attentionItems as $item): ?>
        <li class="attention-item">
          <div class="attention-content">
            <div class="row" style="gap: 0.5rem; align-items: center; flex-wrap: wrap;">
              <span class="badge badge-tag badge-<?= e($item['badge_class']) ?>"><?= e($item['badge']) ?></span>
              <strong><?= e($item['title']) ?></strong>
            </div>
            <p class="caption" style="margin: 0.35rem 0 0;"><?= e($item['subtitle']) ?></p>
          </div>
          <div class="attention-actions">
            <?php if (!empty($item['time'])): ?>
              <span class="caption muted"><?= e(date('M j, Y', strtotime((string)$item['time']))) ?></span>
            <?php endif; ?>
            <a href="<?= e($item['action_url']) ?>" class="btn secondary btn-sm"><?= e($item['action_label']) ?> &rarr;</a>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>

<section class="card overview-card" aria-labelledby="recent-activity-heading">
  <div class="overview-card-header">
    <div>
      <h2 id="recent-activity-heading" style="margin: 0; font-size: 1.25rem;">Recent Administrative Activity</h2>
      <p class="caption" style="margin: 0.25rem 0 0;">Recent administrative changes recorded within <?= e($unitName) ?>.</p>
    </div>
    <a href="/sysadmin/activity<?= $unitQuery ?>" class="btn ghost btn-sm">Full Activity Log &rarr;</a>
  </div>

  <?php if (empty($recentActivity)): ?>
    <div class="empty">No administrative activity recorded for <?= e($unitName) ?> yet.</div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="activity-table">
        <thead>
          <tr>
            <th style="width: 175px;">Date and time</th>
            <th style="width: 155px;">Administrator</th>
            <th style="width: 210px;">Activity</th>
            <th style="width: 175px;">Affected employee</th>
            <th>Details</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recentActivity as $log): ?>
            <tr>
              <td class="tabular caption"><?= e(date('M j, Y, g:i A', strtotime((string)$log['created_at']))) ?></td>
              <td><?= e($log['actor_name'] !== '' ? $log['actor_name'] : ($log['actor_email'] ?: 'System')) ?></td>
              <td><strong><?= e(\App\Models\Audit::ADMIN_ACTIVITY_TYPES[$log['action_code']] ?? $log['action_code']) ?></strong></td>
              <td><?= e($log['affected_name'] !== '' ? $log['affected_name'] : (!empty($log['affected_employee_id']) ? 'Employee #' . (int)$log['affected_employee_id'] : '—')) ?></td>
              <td class="caption">
                <?= e($log['summary']) ?>
                <?php if ($log['old_value'] !== null || $log['new_value'] !== null): ?>
                  <div class="caption muted" style="margin-top: 0.15rem;">
                    <?php if ($log['old_value'] !== null): ?>Previous: <code><?= e($log['old_value']) ?></code><?php endif; ?>
                    <?php if ($log['old_value'] !== null && $log['new_value'] !== null): ?> &rarr; <?php endif; ?>
                    <?php if ($log['new_value'] !== null): ?>New: <code><?= e($log['new_value']) ?></code><?php endif; ?>
                  </div>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
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

