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
    <span class="eyebrow">Orders</span>
    <strong class="stat-value"><?= count($orders) ?></strong>
    <p class="caption">Orders placed in <?= e($unitName) ?></p>
  </article>
  <article class="card stat-card">
    <span class="eyebrow">Appointments</span>
    <strong class="stat-value"><?= count($appointments) ?></strong>
    <p class="caption">Scheduled visits for <?= e($unitName) ?></p>
  </article>
  <article class="card stat-card">
    <span class="eyebrow">Employees</span>
    <strong class="stat-value"><?= count($unitStaff) ?></strong>
    <p class="caption">Employees assigned to <?= e($unitName) ?></p>
  </article>
</section>

<div style="margin-bottom: 2rem;">
  <section class="card">
    <div class="row" style="justify-content: space-between; align-items: baseline; margin-bottom: 1rem;">
      <div>
        <h2 style="margin: 0;">Products &amp; Inventory (<?= count($products) ?>)</h2>
        <p class="caption" style="margin: 0.2rem 0 0 0;">Dedicated product catalog and inventory monitoring.</p>
      </div>
      <a href="/manager/inventory" class="btn secondary btn-sm">Open Inventory &rarr;</a>
    </div>
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
</div>

