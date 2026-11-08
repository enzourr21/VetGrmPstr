<?php
$tab = $activeTab ?? 'overview';
$unitQuery = !empty($isSuper) ? '?unit_id=' . (int)$unitId : '';
$isPetStore = (int)$unitId === 1;
$isVetClinic = (int)$unitId === \App\Models\Units::vetClinicId();
$isGrooming = (int)$unitId === 3;
$isTempInv = !empty($isTempKeyAccess);
?>
<?php if ($isTempInv): ?>
<div class="admin-workspace-banner">
  <div class="row" style="justify-content: space-between; width: 100%; flex-wrap: wrap; gap: 0.75rem;">
    <div class="row" style="gap: 0.75rem;">
      <span class="unit-pill">TEMPORARY INVENTORY ACCESS · <?= e(strtoupper($unitName)) ?></span>
      <span class="caption">
        Scoped to <strong><?= e($unitName) ?></strong>
        <?php if (!empty($tempKeyGrant['expires_at_formatted'])): ?>
          · Valid until <strong><?= e($tempKeyGrant['expires_at_formatted']) ?></strong>
        <?php endif; ?>
      </span>
    </div>
    <div class="row" style="gap: 0.5rem;">
      <a href="/staff/workspace" class="btn ghost btn-sm">&larr; Employee Workspace</a>
      <form method="post" action="/staff/inventory-access/exit" style="margin: 0;">
        <?= \App\Core\CSRF::field() ?>
        <button type="submit" class="btn secondary btn-sm">Exit Temporary Access</button>
      </form>
    </div>
  </div>
</div>
<nav class="admin-subnav" aria-label="Temporary Inventory navigation">
  <a href="/manager/inventory" class="segmented-pill is-active">Inventory</a>
</nav>
<?php else: ?>
<div class="admin-workspace-banner">
  <div class="row" style="justify-content: space-between; width: 100%; flex-wrap: wrap; gap: 0.75rem;">
    <div class="row" style="gap: 0.75rem;">
      <span class="unit-pill">BUSINESS MANAGER · <?= e(strtoupper($unitName)) ?></span>
      <span class="caption">
        Operational workspace for <strong><?= e($unitName) ?></strong> (Unit #<?= (int)$unitId ?>)
        · <?= e(\App\Core\Session::get('email', '')) ?>
      </span>
    </div>
    <div class="row" style="gap: 0.5rem;">
      <?php if (!empty($isSuper) && !empty($allUnits)): ?>
        <div class="row" style="gap: 0.35rem;">
          <?php foreach ($allUnits as $u): ?>
            <a href="/manager?unit_id=<?= (int)$u['business_unit_id'] ?>"
               class="btn <?= (int)$u['business_unit_id'] === (int)$unitId ? 'secondary' : 'ghost' ?> btn-sm">
              <?= e($u['business_unit_name']) ?>
            </a>
          <?php endforeach; ?>
        </div>
        <a href="/super-admin" class="btn ghost btn-sm">&larr; Super Admin</a>
      <?php endif; ?>
      <a href="/customer-interface" class="btn secondary btn-sm">Customer Interface &rarr;</a>
    </div>
  </div>
</div>

<?php if ($isPetStore): ?>
  <nav class="admin-subnav" aria-label="Pet Store Business Manager navigation">
    <a href="/manager<?= $unitQuery ?>" class="segmented-pill <?= $tab === 'overview' ? 'is-active' : '' ?>">Overview</a>
    <a href="/manager/sales<?= $unitQuery ?>" class="segmented-pill <?= $tab === 'sales' ? 'is-active' : '' ?>">Sales</a>
    <a href="/manager/products<?= $unitQuery ?>" class="segmented-pill <?= $tab === 'products' ? 'is-active' : '' ?>">Products</a>
    <a href="/manager/inventory<?= $unitQuery ?>" class="segmented-pill <?= $tab === 'inventory' ? 'is-active' : '' ?>">Inventory</a>
    <a href="/manager/employees<?= $unitQuery ?>" class="segmented-pill <?= $tab === 'employees' ? 'is-active' : '' ?>">Employees</a>
    <?php if (empty($isInventoryManagerOnly)): ?>
      <a href="/manager/settings<?= $unitQuery ?>" class="segmented-pill <?= $tab === 'settings' ? 'is-active' : '' ?>">Business Settings</a>
    <?php endif; ?>
  </nav>
<?php elseif ($isVetClinic): ?>
  <nav class="admin-subnav" aria-label="Veterinary Clinic Business Manager navigation">
    <a href="/manager<?= $unitQuery ?>" class="segmented-pill <?= $tab === 'overview' ? 'is-active' : '' ?>">Overview</a>
    <a href="/manager/appointments<?= $unitQuery ?>" class="segmented-pill <?= $tab === 'appointments' ? 'is-active' : '' ?>">Appointments</a>
    <a href="/manager/employees<?= $unitQuery ?>" class="segmented-pill <?= $tab === 'employees' ? 'is-active' : '' ?>">Employees</a>
    <a href="/manager/services<?= $unitQuery ?>" class="segmented-pill <?= $tab === 'services' ? 'is-active' : '' ?>">Services</a>
    <a href="/manager/inventory<?= $unitQuery ?>" class="segmented-pill <?= $tab === 'inventory' ? 'is-active' : '' ?>">Inventory</a>
    <a href="/manager/medical-records<?= $unitQuery ?>" class="segmented-pill <?= $tab === 'medical-records' ? 'is-active' : '' ?>">Medical Records</a>
    <?php if (empty($isInventoryManagerOnly)): ?>
      <a href="/manager/settings<?= $unitQuery ?>" class="segmented-pill <?= $tab === 'settings' ? 'is-active' : '' ?>">Business Settings</a>
    <?php endif; ?>
  </nav>
<?php elseif ($isGrooming): ?>
  <nav class="admin-subnav" aria-label="Grooming Services Business Manager navigation">
    <a href="/manager<?= $unitQuery ?>" class="segmented-pill <?= $tab === 'overview' ? 'is-active' : '' ?>">Overview</a>
    <a href="/manager/appointments<?= $unitQuery ?>" class="segmented-pill <?= $tab === 'appointments' ? 'is-active' : '' ?>">Appointments</a>
    <a href="/manager/employees<?= $unitQuery ?>" class="segmented-pill <?= $tab === 'employees' ? 'is-active' : '' ?>">Employees</a>
    <a href="/manager/services<?= $unitQuery ?>" class="segmented-pill <?= $tab === 'services' ? 'is-active' : '' ?>">Services</a>
    <a href="/manager/inventory<?= $unitQuery ?>" class="segmented-pill <?= $tab === 'inventory' ? 'is-active' : '' ?>">Inventory</a>
    <?php if (empty($isInventoryManagerOnly)): ?>
      <a href="/manager/settings<?= $unitQuery ?>" class="segmented-pill <?= $tab === 'settings' ? 'is-active' : '' ?>">Business Settings</a>
    <?php endif; ?>
  </nav>
<?php endif; ?>
<?php endif; ?>
