<?php require __DIR__ . '/_nav.php'; ?>

<div class="page-header">
  <div>
    <span class="section-kicker">Architecture &amp; Operations</span>
    <h1>Business Units</h1>
    <p class="muted">Status, branch configuration, leadership assignments, and operational metrics for all three VETGRPET business units.</p>
  </div>
</div>

<div class="grid-3" style="margin-bottom: 2.25rem;">
  <?php foreach ($units as $u):
    $uid = (int)$u['business_unit_id'];
  ?>
    <article class="card overview-card">
      <div>
        <div class="row" style="justify-content: space-between; margin-bottom: 0.65rem;">
          <span class="unit-pill">Unit #<?= $uid ?></span>
          <span class="badge <?= $u['business_unit_status'] === 'active' ? 'ok' : 'warn' ?>"><?= e(strtoupper($u['business_unit_status'])) ?></span>
        </div>
        <h2><?= e($u['business_unit_name']) ?></h2>
        <dl class="meta-list">
          <div><dt>Operational Status</dt><dd><?= e(ucfirst($u['business_unit_status'])) ?></dd></div>
          <div><dt>Active Branches</dt><dd class="tabular"><?= (int)$u['branch_count'] ?></dd></div>
          <div><dt>System Administrator</dt><dd><strong><?= e($u['sysadmin_names'] ?: 'Unassigned') ?></strong></dd></div>
          <div><dt>Business Manager</dt><dd><?= e($u['manager_names'] ?: 'Unassigned') ?></dd></div>
          <div><dt>Assigned Employees</dt><dd class="tabular"><?= (int)$u['employee_count'] ?></dd></div>
          <div><dt>Catalog Products</dt><dd class="tabular"><?= (int)$u['product_count'] ?></dd></div>
          <div><dt>Appointments</dt><dd class="tabular"><?= (int)$u['appointment_count'] ?></dd></div>
          <div><dt>Customer Orders</dt><dd class="tabular"><?= (int)$u['order_count'] ?> (<?= money($u['order_revenue']) ?>)</dd></div>
        </dl>
      </div>
      <div class="overview-card-footer">
        <a class="btn secondary btn-sm" href="/sysadmin/units/<?= $uid ?>">Open System Admin</a>
        <a class="btn ghost btn-sm" href="/super-admin/employees?unit_id=<?= $uid ?>">View Unit Staff</a>
      </div>
    </article>
  <?php endforeach; ?>
</div>

<section>
  <div class="section-header" style="margin-top: 0;">
    <div>
      <h2>Operating Branches</h2>
      <p class="muted">Physical branch locations linked to each business unit.</p>
    </div>
  </div>
  <table>
    <thead>
      <tr>
        <th>ID</th>
        <th>Business Unit</th>
        <th>Branch Name</th>
        <th>City</th>
        <th>Phone</th>
        <th>Status</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($branches as $b): ?>
        <tr>
          <td class="tabular">#<?= (int)$b['branch_id'] ?></td>
          <td><span class="unit-pill"><?= e($b['business_unit_name']) ?></span></td>
          <td><strong><?= e($b['branch_name']) ?></strong></td>
          <td><?= e($b['city'] ?: '—') ?></td>
          <td class="tabular"><?= e($b['phone'] ?: '—') ?></td>
          <td><span class="badge <?= $b['branch_status'] === 'active' ? 'ok' : 'warn' ?>"><?= e(ucfirst($b['branch_status'])) ?></span></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</section>

