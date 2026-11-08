<div class="page-head">
  <div>
    <p class="eyebrow"><?= e($unitName) ?> Operations</p>
    <h1><?= e($unitName) ?> Business Manager Dashboard</h1>
    <p class="muted">Unit inventory, customer orders, service appointments, and active staff roster.</p>
  </div>
</div>

<section class="grid grid-4" style="margin-bottom: 2rem;">
  <article class="card stat-card">
    <span class="eyebrow">Catalog Items</span>
    <strong class="stat-value"><?= count($products) ?></strong>
    <p class="caption">Products offered in <?= e($unitName) ?></p>
  </article>
  <article class="card stat-card">
    <span class="eyebrow">Recent Orders</span>
    <strong class="stat-value"><?= count($orders) ?></strong>
    <p class="caption">Orders placed in <?= e($unitName) ?></p>
  </article>
  <article class="card stat-card">
    <span class="eyebrow">Appointments</span>
    <strong class="stat-value"><?= count($appointments) ?></strong>
    <p class="caption">Scheduled visits for <?= e($unitName) ?></p>
  </article>
  <article class="card stat-card">
    <span class="eyebrow">Unit Staff</span>
    <strong class="stat-value"><?= count($unitStaff) ?></strong>
    <p class="caption">Employees assigned to <?= e($unitName) ?></p>
  </article>
</section>

<div class="grid grid-2" style="align-items: start; gap: 1.5rem;">
  <section class="card">
    <h2>Products &amp; Inventory (<?= count($products) ?>)</h2>
    <?php if (!$products): ?>
      <div class="empty">No retail products configured for <?= e($unitName) ?>.</div>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Product</th>
              <th>Price</th>
              <th>On Hand</th>
              <th>Available</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($products as $p): ?>
              <tr>
                <td>
                  <strong><?= e($p['product_name']) ?></strong>
                  <div class="caption"><?= e($p['product_category']) ?></div>
                </td>
                <td><?= peso((float)$p['selling_price']) ?></td>
                <td><?= (int)$p['on_hand'] ?></td>
                <td><strong><?= (int)$p['available'] ?></strong></td>
                <td><span class="badge status-<?= e($p['product_status']) ?>"><?= e($p['product_status']) ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <section class="card">
    <h2>Unit Staff Roster (<?= count($unitStaff) ?>)</h2>
    <?php if (!$unitStaff): ?>
      <div class="empty">No staff assigned to <?= e($unitName) ?>.</div>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Employee</th>
              <th>Position</th>
              <th>Roles</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($unitStaff as $st): ?>
              <tr>
                <td><strong><?= e($st['first_name'] . ' ' . $st['last_name']) ?></strong></td>
                <td><?= e($st['position']) ?></td>
                <td class="caption"><?= e($st['roles'] ?: '—') ?></td>
                <td><span class="badge status-<?= e($st['employee_status']) ?>"><?= e($st['employee_status']) ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
</div>

