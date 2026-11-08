<?php
$tab = $activeTab ?? 'dashboard';
?>
<div class="admin-workspace-banner">
  <div class="row" style="gap: 0.75rem;">
    <span class="unit-pill">SYSTEM ADMINISTRATOR · <?= e(strtoupper($unitName)) ?></span>
    <span class="caption">
      <?= e($unitName) ?> System Administrator · Scoped to Unit #<?= (int)$unitId ?> · <?= e(\App\Core\Session::get('email', '')) ?>
    </span>
  </div>
</div>

