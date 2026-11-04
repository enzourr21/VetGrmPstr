<?php
require __DIR__ . '/_nav.php';
$unitQuery = !empty($isSuper) ? '?unit_id=' . (int)$unitId : '';
$f = $filters;
$qs = function (array $extra = []) use ($f, $unitId, $isSuper): string {
    $q = array_filter([
        'unit_id' => $isSuper ? $unitId : null,
        'type' => $f['type'] ?: null,
        'employee_id' => $f['employee_id'] ?: null,
        'date_from' => $f['date_from'] ?: null,
        'date_to' => $f['date_to'] ?: null,
    ] + $extra, fn($v) => $v !== null && $v !== '');
    return $q ? '?' . http_build_query($q) : '';
};
$hasFilter = $f['type'] !== '' || $f['employee_id'] || $f['date_from'] !== '' || $f['date_to'] !== '';
$labels = \App\Models\Audit::ADMIN_ACTIVITY_TYPES;
?>

<div class="page-head">
  <div>
    <p class="eyebrow"><?= e($unitName) ?> · Audit Trail</p>
    <h1>Administrative Activity Log</h1>
    <p class="muted">A permanent, read-only history of administrative actions recorded within <strong><?= e($unitName) ?></strong>, newest first.</p>
  </div>
</div>

<section class="card" style="margin-bottom: 1.25rem;">
  <form method="get" action="/sysadmin/activity" class="activity-filters">
    <?php if ($isSuper): ?><input type="hidden" name="unit_id" value="<?= (int)$unitId ?>"><?php endif; ?>
    <div class="field">
      <label for="f-type">Activity type</label>
      <select id="f-type" name="type">
        <option value="">All activity</option>
        <?php foreach (\App\Models\Audit::ADMIN_ACTIVITY_FILTERS as $key => [$label]): ?>
          <option value="<?= e($key) ?>" <?= $f['type'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="f-emp">Affected employee</label>
      <select id="f-emp" name="employee_id">
        <option value="">Any employee</option>
        <?php foreach ($employeeOptions as $emp): ?>
          <option value="<?= (int)$emp['employee_id'] ?>" <?= (int)$f['employee_id'] === (int)$emp['employee_id'] ? 'selected' : '' ?>>
            <?= e($emp['first_name'] . ' ' . $emp['last_name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="f-from">From</label>
      <input id="f-from" name="date_from" type="date" value="<?= e($f['date_from']) ?>">
    </div>
    <div class="field">
      <label for="f-to">To</label>
      <input id="f-to" name="date_to" type="date" value="<?= e($f['date_to']) ?>">
    </div>
    <div class="row" style="align-items: flex-end; gap: 0.5rem;">
      <button type="submit" class="btn secondary">Filter</button>
      <?php if ($hasFilter): ?><a class="btn ghost" href="/sysadmin/activity<?= $unitQuery ?>">Reset</a><?php endif; ?>
    </div>
  </form>
</section>

<section class="card">
  <div class="row" style="justify-content: space-between; margin-bottom: 1rem;">
    <h2>Activity (<?= (int)$result['total'] ?>)</h2>
    <span class="caption">Page <?= (int)$result['page'] ?> of <?= (int)$result['pages'] ?></span>
  </div>

  <?php if (!$result['rows']): ?>
    <div class="empty">
      <?= $hasFilter ? 'No activity matches the selected filters.' : 'No administrative activity has been recorded for ' . e($unitName) . ' yet.' ?>
    </div>
  <?php else: ?>
    <div class="activity-scroll">
      <table class="activity-table">
        <thead>
          <tr>
            <th>Date and time</th>
            <th>Administrator</th>
            <th>Activity</th>
            <th>Affected employee</th>
            <th>Details</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($result['rows'] as $row): ?>
            <tr>
              <td class="tabular"><?= e(date('M j, Y, g:i A', strtotime((string)$row['created_at']))) ?></td>
              <td><?= e($row['actor_name'] !== '' ? $row['actor_name'] : ($row['actor_email'] ?: 'System')) ?></td>
              <td><strong><?= e($labels[$row['action_code']] ?? $row['action_code']) ?></strong></td>
              <td><?= e($row['affected_name'] !== '' ? $row['affected_name'] : '—') ?></td>
              <td>
                <?= e($row['summary']) ?>
                <?php if ($row['old_value'] !== null || $row['new_value'] !== null): ?>
                  <div class="caption">
                    <?php if ($row['old_value'] !== null): ?>Previous: <code><?= e($row['old_value']) ?></code><?php endif; ?>
                    <?php if ($row['old_value'] !== null && $row['new_value'] !== null): ?> &rarr; <?php endif; ?>
                    <?php if ($row['new_value'] !== null): ?>New: <code><?= e($row['new_value']) ?></code><?php endif; ?>
                  </div>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($result['pages'] > 1): ?>
      <nav class="row" style="justify-content: space-between; margin-top: 1rem;" aria-label="Activity pages">
        <?php if ($result['page'] > 1): ?>
          <a class="btn ghost btn-sm" href="/sysadmin/activity<?= $qs(['page' => $result['page'] - 1]) ?>">&larr; Newer</a>
        <?php else: ?><span></span><?php endif; ?>
        <?php if ($result['page'] < $result['pages']): ?>
          <a class="btn ghost btn-sm" href="/sysadmin/activity<?= $qs(['page' => $result['page'] + 1]) ?>">Older &rarr;</a>
        <?php endif; ?>
      </nav>
    <?php endif; ?>
  <?php endif; ?>
</section>

