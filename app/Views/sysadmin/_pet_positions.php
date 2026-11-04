<?php
/** Pet Store System Administrator: default position management (activate/deactivate + add). */
?>
<section class="card" style="margin-top: 1.5rem;" id="pet-positions">
  <div class="row" style="justify-content: space-between; margin-bottom: 1rem;">
    <div>
      <h2>Default Positions (<?= count($allPositions) ?>)</h2>
      <p class="caption">Primary job designations for <?= e($unitName) ?>. Inactive positions are hidden from new accounts; existing employees keep their recorded position.</p>
    </div>
  </div>

  <div class="grid grid-2" style="align-items: start; gap: 1.25rem;">
    <div class="position-list">
      <?php foreach ($allPositions as $pos): ?>
        <div class="position-row">
          <div class="position-info">
            <strong class="position-name"><?= e($pos['position_name']) ?></strong>
            <div class="caption position-desc"><?= e($pos['description'] ?? '—') ?></div>
          </div>
          <div class="position-actions">
            <span class="badge status-<?= e($pos['position_status']) ?> position-badge"><?= e($pos['position_status']) ?></span>
            <form method="post" action="/sysadmin/positions/<?= (int)$pos['position_id'] ?>/toggle<?= $unitQuery ?>" class="position-toggle-form">
              <?= \App\Core\CSRF::field() ?>
              <input type="hidden" name="business_unit_id" value="<?= (int)$unitId ?>">
              <button type="submit" class="btn ghost btn-sm position-toggle-btn"><?= $pos['position_status'] === 'active' ? 'Deactivate' : 'Activate' ?></button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <form method="post" action="/sysadmin/positions<?= $unitQuery ?>" novalidate>
      <?= \App\Core\CSRF::field() ?>
      <input type="hidden" name="business_unit_id" value="<?= (int)$unitId ?>">
      <div class="field">
        <label for="pet-pos-name">New position title</label>
        <input id="pet-pos-name" name="position_name" type="text" required maxlength="100" placeholder="e.g. Store Cashier">
      </div>
      <div class="field">
        <label for="pet-pos-desc">Description</label>
        <textarea id="pet-pos-desc" name="description" rows="2" maxlength="255" placeholder="Responsibilities within <?= e($unitName) ?>"></textarea>
      </div>
      <button type="submit" class="btn secondary">Add Position</button>
    </form>
  </div>
</section>

