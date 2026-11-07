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
      <?php if (!empty($hasPermanentInventoryAccess) || !empty($activeTempInventoryGrant)): ?>
        <a href="/manager/inventory" class="btn ghost btn-sm">Inventory</a>
      <?php endif; ?>
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

<?php if (!empty($supportsTempInventoryKey)): ?>
<section class="card" style="margin-bottom: 1.5rem;">
  <?php if (!empty($activeTempInventoryGrant)): ?>
    <div class="row" style="justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
      <div>
        <div class="row" style="gap: 0.5rem; align-items: center; margin-bottom: 0.25rem;">
          <h2 style="margin: 0;">Temporary Inventory Access</h2>
          <span class="badge badge-success">Active</span>
        </div>
        <p class="caption" style="margin: 0;">
          Authorized for <strong><?= e($emp['business_unit_name'] ?? '') ?></strong> inventory
          <?php if (!empty($activeTempInventoryGrant['expires_at_formatted'])): ?>
            until <strong><?= e($activeTempInventoryGrant['expires_at_formatted']) ?></strong>
          <?php endif; ?>.
        </p>
      </div>
      <div class="row" style="gap: 0.5rem;">
        <a href="/manager/inventory" class="btn primary btn-sm">Open Inventory &rarr;</a>
        <form method="post" action="/staff/inventory-access/exit" style="margin: 0;">
          <?= \App\Core\CSRF::field() ?>
          <button type="submit" class="btn ghost btn-sm">End Access</button>
        </form>
      </div>
    </div>
  <?php else: ?>
    <div class="row" style="justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 1rem;">
      <div>
        <h2 style="margin: 0 0 0.25rem 0;">Temporary Inventory Access</h2>
        <p class="caption" style="margin: 0;">
          Enter a valid temporary Inventory Access Key issued by your <strong><?= e($emp['business_unit_name'] ?? '') ?></strong> Business Manager.
        </p>
      </div>
      <form method="post" action="/staff/inventory-access" class="row" style="gap: 0.5rem; align-items: center; margin: 0;">
        <?= \App\Core\CSRF::field() ?>
        <input type="text"
               name="inventory_key"
               placeholder="INV-XXXX-XXXX"
               autocomplete="off"
               required
               style="width: 190px; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; text-transform: uppercase;">
        <button type="submit" class="btn primary btn-sm">Unlock Inventory</button>
      </form>
    </div>
  <?php endif; ?>
</section>
<?php endif; ?>

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

