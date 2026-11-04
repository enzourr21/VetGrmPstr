<?php
require __DIR__ . '/_nav.php';
$unitQuery = !empty($isSuper) ? '?unit_id=' . (int)$unitId : '';
?>

<div class="page-head">
  <div>
    <p class="eyebrow"><?= e($unitName) ?> · Position Catalog</p>
    <h1>Positions in <?= e($unitName) ?></h1>
    <p class="muted">Create and manage default employee positions available when onboarding staff in <strong><?= e($unitName) ?></strong>.</p>
  </div>
</div>

<div class="grid grid-2" style="align-items: start; gap: 1.5rem;">
  <section class="card">
    <h2>Create Position in <?= e($unitName) ?></h2>
    <p class="caption" style="margin-bottom: 1rem;">Positions are scoped to <?= e($unitName) ?> (Unit #<?= (int)$unitId ?>).</p>

    <form method="post" action="/sysadmin/positions<?= $unitQuery ?>" novalidate>
      <?= \App\Core\CSRF::field() ?>
      <input type="hidden" name="business_unit_id" value="<?= (int)$unitId ?>">

      <div class="field">
        <label for="pos-name">Position Title</label>
        <input id="pos-name" name="position_name" type="text" required maxlength="100"
               value="<?= e($old['position_name'] ?? '') ?>"
               placeholder="e.g. Senior Grooming Specialist"
               aria-invalid="<?= isset($errors['position_name']) ? 'true' : 'false' ?>">
        <?php if (isset($errors['position_name'])): ?>
          <div class="field-error"><?= e($errors['position_name']) ?></div>
        <?php endif; ?>
      </div>

      <div class="field">
        <label for="pos-desc">Description</label>
        <textarea id="pos-desc" name="description" rows="3" maxlength="255"
                  placeholder="Describe the responsibilities of this position within <?= e($unitName) ?>"><?= e($old['description'] ?? '') ?></textarea>
        <?php if (isset($errors['description'])): ?>
          <div class="field-error"><?= e($errors['description']) ?></div>
        <?php endif; ?>
      </div>

      <button type="submit" class="btn">Add Position to <?= e($unitName) ?></button>
    </form>
  </section>

  <section class="card">
    <h2><?= e($unitName) ?> Positions (<?= count($positions) ?>)</h2>
    <p class="caption" style="margin-bottom: 1rem;">Toggle position availability for new employee assignments.</p>

    <?php if (!$positions): ?>
      <div class="empty">No positions defined for <?= e($unitName) ?> yet.</div>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Position Title</th>
              <th>Description</th>
              <th>Status</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($positions as $pos): ?>
              <tr>
                <td><strong><?= e($pos['position_name']) ?></strong></td>
                <td class="caption"><?= e($pos['description'] ?? '—') ?></td>
                <td><span class="badge status-<?= e($pos['position_status']) ?>"><?= e($pos['position_status']) ?></span></td>
                <td>
                  <form method="post" action="/sysadmin/positions/<?= (int)$pos['position_id'] ?>/toggle<?= $unitQuery ?>" style="display:inline;">
                    <?= \App\Core\CSRF::field() ?>
                    <input type="hidden" name="business_unit_id" value="<?= (int)$unitId ?>">
                    <button type="submit" class="btn ghost btn-sm">
                      <?= $pos['position_status'] === 'active' ? 'Deactivate' : 'Activate' ?>
                    </button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
</div>

