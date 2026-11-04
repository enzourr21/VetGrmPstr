<?php
$tab = $activeTab ?? 'dashboard';
$unitQuery = !empty($isSuper) ? '?unit_id=' . (int)$unitId : '';
?>
<div class="admin-workspace-banner">
  <div class="row" style="justify-content: space-between; width: 100%; flex-wrap: wrap; gap: 0.75rem;">
    <div class="row" style="gap: 0.75rem;">
      <span class="unit-pill">SYSTEM ADMINISTRATOR · <?= e(strtoupper($unitName)) ?></span>
      <span class="caption">
        Scoped to <strong><?= e($unitName) ?></strong> (Unit #<?= (int)$unitId ?>)
        · <?= e(\App\Core\Session::get('email', '')) ?>
      </span>
    </div>
    <div class="row" style="gap: 0.5rem;">
      <?php if (!empty($isSuper) && !empty($allUnits)): ?>
        <div class="row" style="gap: 0.35rem;">
          <?php foreach ($allUnits as $u): ?>
            <a href="/sysadmin?unit_id=<?= (int)$u['business_unit_id'] ?>"
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

<nav class="admin-subnav" aria-label="<?= e($unitName) ?> System Administrator navigation">
  <a href="/sysadmin<?= $unitQuery ?>" class="segmented-pill <?= $tab === 'dashboard' ? 'is-active' : '' ?>">Overview</a>
  <a href="/sysadmin/employees<?= $unitQuery ?>" class="segmented-pill <?= $tab === 'employees' ? 'is-active' : '' ?>">Unit Employees</a>
  <?php if (!empty($isPetStore)): ?>
    <a href="/sysadmin/roles<?= $unitQuery ?>" class="segmented-pill <?= $tab === 'roles' ? 'is-active' : '' ?>">Roles &amp; Permissions</a>
    <a href="/sysadmin/activity<?= $unitQuery ?>" class="segmented-pill <?= $tab === 'activity' ? 'is-active' : '' ?>">Activity Log</a>
  <?php else: ?>
    <a href="/sysadmin/positions<?= $unitQuery ?>" class="segmented-pill <?= $tab === 'positions' ? 'is-active' : '' ?>">Unit Positions</a>
    <a href="/sysadmin/roles<?= $unitQuery ?>" class="segmented-pill <?= $tab === 'roles' ? 'is-active' : '' ?>">Roles &amp; Permissions</a>
  <?php endif; ?>
</nav>

