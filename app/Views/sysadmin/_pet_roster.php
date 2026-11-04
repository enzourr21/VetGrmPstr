<?php
/** Pet Store System Administrator: Unit Employee Roster (vertical scroll + Show All modal). */
$rosterPreview = 5;
$selfUid = (int)\App\Core\Session::get('user_id', 0);
$renderRosterItem = function (array $emp) use ($unitId, $unitQuery, $positions, $selfUid): void {
    $isHome = (int)($emp['home_unit_id'] ?? 0) === (int)$unitId;
    $isSelf = (int)$emp['user_account_id'] === $selfUid;
    ?>
    <article class="roster-item">
      <header class="roster-item-head">
        <div>
          <strong><?= e($emp['first_name'] . ' ' . $emp['last_name']) ?></strong>
          <span class="caption">ID #<?= (int)$emp['employee_id'] ?></span>
        </div>
        <span class="badge status-<?= e($emp['account_status']) ?>"><?= e($emp['account_status']) ?></span>
      </header>
      <dl class="roster-meta">
        <div><dt>Email</dt><dd><?= e($emp['email']) ?></dd></div>
        <div><dt>Username</dt><dd><code><?= e($emp['username'] ?? '—') ?></code></dd></div>
        <div><dt>Default position</dt><dd><?= e($emp['position']) ?></dd></div>
        <div><dt>Roles in unit</dt><dd><?= e($emp['unit_roles'] ?: '—') ?></dd></div>
      </dl>
      <?php if ($isHome): ?>
        <div class="roster-actions">
          <form method="post" action="/sysadmin/employees/<?= (int)$emp['employee_id'] ?>/position<?= $unitQuery ?>" class="roster-position-form">
            <?= \App\Core\CSRF::field() ?>
            <input type="hidden" name="business_unit_id" value="<?= (int)$unitId ?>">
            <select name="position" aria-label="Change default position for <?= e($emp['first_name'] . ' ' . $emp['last_name']) ?>">
              <?php
              $hasCurrent = false;
              foreach ($positions as $pos):
                  $sel = $pos['position_name'] === $emp['position'];
                  $hasCurrent = $hasCurrent || $sel;
              ?>
                <option value="<?= e($pos['position_name']) ?>" <?= $sel ? 'selected' : '' ?>><?= e($pos['position_name']) ?></option>
              <?php endforeach; ?>
              <?php if (!$hasCurrent): ?>
                <option value="" selected disabled><?= e($emp['position']) ?> (retired)</option>
              <?php endif; ?>
            </select>
            <button type="submit" class="btn ghost btn-sm">Change position</button>
          </form>
          <?php if (!$isSelf): ?>
            <form method="post" action="/sysadmin/employees/<?= (int)$emp['employee_id'] ?>/status<?= $unitQuery ?>" data-confirm-action="<?= $emp['account_status'] === 'active' ? 'Deactivate' : 'Reactivate' ?> the account of <?= e($emp['first_name'] . ' ' . $emp['last_name']) ?>?">
              <?= \App\Core\CSRF::field() ?>
              <input type="hidden" name="business_unit_id" value="<?= (int)$unitId ?>">
              <input type="hidden" name="account_status" value="<?= $emp['account_status'] === 'active' ? 'inactive' : 'active' ?>">
              <button type="submit" class="btn <?= $emp['account_status'] === 'active' ? 'ghost' : 'secondary' ?> btn-sm">
                <?= $emp['account_status'] === 'active' ? 'Deactivate' : 'Reactivate' ?>
              </button>
            </form>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </article>
    <?php
};
?>
<section class="card">
  <div class="row" style="justify-content: space-between; margin-bottom: 1rem;">
    <div>
      <h2>Unit Employee Roster (<?= count($employees) ?>)</h2>
      <p class="caption">Employees assigned to <?= e($unitName) ?>.</p>
    </div>
    <div class="row">
      <button type="button" class="btn secondary btn-sm" data-modal-open="roster-modal">Show All</button>
      <a href="/sysadmin/roles<?= $unitQuery ?>" class="btn ghost btn-sm">Edit Role Assignments &rarr;</a>
    </div>
  </div>

  <?php if (!$employees): ?>
    <div class="empty">No employees found for <?= e($unitName) ?>.</div>
  <?php else: ?>
    <div class="roster-list">
      <?php foreach (array_slice($employees, 0, $rosterPreview) as $emp) { $renderRosterItem($emp); } ?>
    </div>
    <?php if (count($employees) > $rosterPreview): ?>
      <p class="caption" style="margin-top: 0.75rem;">Showing <?= $rosterPreview ?> of <?= count($employees) ?> employees. Use Show All to see the complete roster.</p>
    <?php endif; ?>
  <?php endif; ?>

  <dialog class="modal modal-wide" id="roster-modal" aria-labelledby="roster-modal-title">
    <div class="modal-head">
      <h3 id="roster-modal-title"><?= e($unitName) ?> Employees (<?= count($employees) ?>)</h3>
      <button type="button" class="btn ghost btn-sm" data-modal-close aria-label="Close dialog">&times; Close</button>
    </div>
    <div class="modal-body">
      <?php if (!$employees): ?>
        <div class="empty">No employees found for <?= e($unitName) ?>.</div>
      <?php else: ?>
        <div class="roster-list roster-list-full">
          <?php foreach ($employees as $emp) { $renderRosterItem($emp); } ?>
        </div>
      <?php endif; ?>
    </div>
  </dialog>
</section>
