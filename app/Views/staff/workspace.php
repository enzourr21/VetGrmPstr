<?php
$canViewGroomingAppts = false;
foreach ($permissions as $perm) {
    if ($perm['permission_code'] === 'appointment.view' && $perm['business_unit_name'] === 'Grooming Services') {
        $canViewGroomingAppts = true;
        break;
    }
}
if (!empty($isSuper)) {
    $canViewGroomingAppts = true;
}
?>
<div class="admin-workspace-banner">
  <div class="row" style="justify-content: space-between; width: 100%; flex-wrap: wrap; gap: 0.75rem;">
    <div class="row" style="gap: 0.75rem;">
      <span class="unit-pill">EMPLOYEE WORKSPACE</span>
      <span class="caption">
        Signed in via staff email · <?= e($emp['email'] ?? '') ?>
        <?php if (!empty($emp['business_unit_name'])): ?>
          · Assigned to <strong><?= e($emp['business_unit_name']) ?></strong>
        <?php endif; ?>
      </span>
    </div>
    <div class="row" style="gap: 0.5rem;">
      <?php if ($canViewGroomingAppts): ?>
        <a href="/staff/appointments" class="btn ghost btn-sm">Grooming Appointments</a>
      <?php endif; ?>
      <a href="/customer-interface" class="btn secondary btn-sm">Customer Interface &rarr;</a>
    </div>
  </div>
</div>

<div class="page-head">
  <div>
    <p class="eyebrow">Staff Portal</p>
    <h1>Welcome, <?= e(($emp['first_name'] ?? 'Staff') . ' ' . ($emp['last_name'] ?? '')) ?></h1>
    <p class="muted">
      Default Position: <strong><?= e($emp['position'] ?? 'Staff') ?></strong>
      <?php if (!empty($emp['business_unit_name'])): ?>
        in <strong><?= e($emp['business_unit_name']) ?></strong>
      <?php endif; ?>
    </p>
  </div>
</div>

<div class="grid grid-2" style="align-items: start; gap: 1.5rem;">
  <section class="card">
    <h2>Assigned Roles</h2>
    <p class="caption" style="margin-bottom: 1rem;">Your active RBAC roles scoped by business unit.</p>
    <?php if (!$roles): ?>
      <div class="empty">No unit roles assigned.</div>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Business Unit</th>
              <th>Role</th>
              <th>Description</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($roles as $r): ?>
              <tr>
                <td><strong><?= e($r['business_unit_name']) ?></strong></td>
                <td><code><?= e($r['role_name']) ?></code></td>
                <td class="caption"><?= e($r['description']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <section class="card">
    <h2>Effective Permissions</h2>
    <p class="caption" style="margin-bottom: 1rem;">Operations authorized for your employee session.</p>
    <?php if (!$permissions): ?>
      <div class="empty">No granular permissions granted.</div>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Business Unit</th>
              <th>Permission</th>
              <th>Description</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($permissions as $p): ?>
              <tr>
                <td><?= e($p['business_unit_name']) ?></td>
                <td><code><?= e($p['permission_code']) ?></code></td>
                <td class="caption"><?= e($p['description']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
</div>

