<?php require __DIR__ . '/_nav.php'; ?>

<div class="page-header">
  <div>
    <span class="section-kicker">Executive Governance</span>
    <h1>Super Administrator Dashboard</h1>
    <p class="muted">Real-time operational summary across Pet Store, Veterinary Clinic, and Grooming Services.</p>
  </div>
  <div class="row">
    <a href="/super-admin/system-admins#create-sysadmin-form" class="btn secondary">New System Admin</a>
    <a href="/super-admin/employees#create-employee-form" class="btn">New Employee Account</a>
  </div>
</div>

<!-- System-Wide Metric Cards -->
<div class="grid-3" style="margin-bottom: 1.5rem;">
  <article class="card stat-card">
    <span class="caption">Registered Customers</span>
    <p class="stat-value tabular"><?= (int)$stats['customers'] ?></p>
    <span class="caption">Active customer profiles</span>
  </article>
  <article class="card stat-card">
    <span class="caption">Active Employees</span>
    <p class="stat-value tabular"><?= (int)$stats['employees'] ?></p>
    <span class="caption">Across all three business units</span>
  </article>
  <article class="card stat-card">
    <span class="caption">System Administrators</span>
    <p class="stat-value tabular"><?= (int)$stats['sysadmins'] ?></p>
    <span class="caption"><a href="/super-admin/system-admins">Manage unit administrators &rarr;</a></span>
  </article>
  <article class="card stat-card">
    <span class="caption">Business Managers</span>
    <p class="stat-value tabular"><?= (int)$stats['managers'] ?></p>
    <span class="caption">Operational unit leadership</span>
  </article>
  <article class="card stat-card">
    <span class="caption">Total Appointments</span>
    <p class="stat-value tabular"><?= (int)$stats['appointments'] ?></p>
    <span class="caption">Veterinary &amp; Grooming bookings</span>
  </article>
  <article class="card stat-card">
    <span class="caption">Customer Orders &amp; Sales</span>
    <p class="stat-value tabular"><?= (int)($stats['orders'] + $stats['sales']) ?></p>
    <span class="caption">Order volume: <strong class="tabular"><?= money($stats['revenue']) ?></strong></span>
  </article>
</div>

<!-- Three Business Units Overview -->
<section style="margin-top: 2.25rem;">
  <div class="section-header" style="margin-top: 0;">
    <div>
      <h2>Business Units Overview</h2>
      <p class="muted">Inspect unit status, assigned System Administrators, and enter unit-specific interfaces.</p>
    </div>
    <a class="action-link" href="/super-admin/business-units">Detailed unit breakdown <span class="arrow" aria-hidden="true">&rarr;</span></a>
  </div>

  <div class="grid-3">
    <?php foreach ($units as $u):
      $uid = (int)$u['business_unit_id'];
    ?>
      <article class="card overview-card">
        <div>
          <div class="row" style="justify-content: space-between; margin-bottom: 0.5rem;">
            <span class="unit-pill"><?= e($u['business_unit_name']) ?></span>
            <span class="badge <?= $u['business_unit_status'] === 'active' ? 'ok' : 'warn' ?>"><?= e(ucfirst($u['business_unit_status'])) ?></span>
          </div>
          <h3><?= e($u['business_unit_name']) ?></h3>
          <dl class="meta-list">
            <div><dt>System Admin(s)</dt><dd><?= e($u['sysadmin_names'] ?: 'None assigned') ?></dd></div>
            <div><dt>Business Manager</dt><dd><?= e($u['manager_names'] ?: 'None assigned') ?></dd></div>
            <div><dt>Active Staff</dt><dd class="tabular"><?= (int)$u['employee_count'] ?> employees</dd></div>
            <div><dt>Active Products</dt><dd class="tabular"><?= (int)$u['product_count'] ?> listed</dd></div>
            <div><dt>Appointments / Orders</dt><dd class="tabular"><?= (int)$u['appointment_count'] ?> appts · <?= (int)$u['order_count'] ?> orders</dd></div>
          </dl>
        </div>
        <div class="overview-card-footer">
          <a class="action-link" href="/sysadmin/units/<?= $uid ?>">System Admin <span class="arrow" aria-hidden="true">&rarr;</span></a>
          <a class="action-link" href="/manager/units/<?= $uid ?>">Manager <span class="arrow" aria-hidden="true">&rarr;</span></a>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
</section>

<!-- Recent System Activity -->
<section style="margin-top: 2.5rem;">
  <div class="section-header" style="margin-top: 0;">
    <div>
      <h2>Recent System Activity</h2>
      <p class="muted">Latest recorded administrative and authentication events across VETGRPET.</p>
    </div>
    <a class="action-link" href="/super-admin/activity">View full audit log <span class="arrow" aria-hidden="true">&rarr;</span></a>
  </div>

  <?php if (!$recentActivity): ?>
    <div class="card"><p class="muted">No administrative activity has been recorded yet.</p></div>
  <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>Timestamp</th>
          <th>Action</th>
          <th>Business Unit</th>
          <th>Actor</th>
          <th>Summary</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($recentActivity as $log): ?>
          <tr>
            <td class="tabular caption"><?= e($log['created_at']) ?></td>
            <td><code><?= e($log['action_code']) ?></code></td>
            <td><?= e($log['business_unit_name'] ?: 'System-Wide') ?></td>
            <td><?= e(trim((string)$log['actor_name']) !== '' ? $log['actor_name'] : ($log['actor_email'] ?: 'System')) ?></td>
            <td><?= e($log['summary']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>

