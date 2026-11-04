<?php
require __DIR__ . '/_nav.php';
$unitQuery = !empty($isSuper) ? '?unit_id=' . (int)$unitId : '';
?>

<div class="page-head">
  <div>
    <p class="eyebrow"><?= e($unitName) ?> · Role &amp; Permission Administration</p>
    <h1>Roles &amp; Permissions in <?= e($unitName) ?></h1>
    <p class="muted">Assign unit-scoped roles—including <code>SYSTEM_ADMINISTRATOR</code>—to employees within <strong><?= e($unitName) ?></strong>.</p>
  </div>
</div>

<section class="card" style="margin-bottom: 1.75rem;">
  <h2>Employee Role Assignments in <?= e($unitName) ?></h2>
  <p class="caption" style="margin-bottom: 1rem;">Changes apply strictly to <strong><?= e($unitName) ?></strong> and never modify roles in other business units.</p>

  <?php if (!$employees): ?>
    <div class="empty">No employees in <?= e($unitName) ?> yet.</div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Employee</th>
            <th>Default Position</th>
            <th>Assigned Roles in <?= e($unitName) ?></th>
            <th>Save</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($employees as $emp):
            $currentRoles = array_filter(array_map('trim', explode(',', (string)$emp['unit_roles'])));
            $formId = 'role-form-' . (int)$emp['employee_id'];
          ?>
            <tr>
              <td>
                <strong><?= e($emp['first_name'] . ' ' . $emp['last_name']) ?></strong>
                <div class="caption"><?= e($emp['email']) ?></div>
              </td>
              <td><?= e($emp['position']) ?></td>
              <td>
                <form id="<?= e($formId) ?>" method="post" action="/sysadmin/employees/<?= (int)$emp['employee_id'] ?>/roles<?= $unitQuery ?>">
                  <?= \App\Core\CSRF::field() ?>
                  <input type="hidden" name="business_unit_id" value="<?= (int)$unitId ?>">
                  <div class="row" style="gap: 0.65rem; flex-wrap: wrap;">
                    <?php foreach ($assignableRoles as $r):
                      $rName = (string)$r['role_name'];
                      $hasRole = in_array($rName, $currentRoles, true);
                    ?>
                      <label style="display: inline-flex; align-items: center; gap: 0.35rem; font-size: 0.82rem; font-weight: 500; cursor: pointer;">
                        <input type="checkbox" name="roles[]" value="<?= e($rName) ?>" <?= $hasRole ? 'checked' : '' ?> style="width: auto;">
                        <span><?= e($rName) ?></span>
                      </label>
                    <?php endforeach; ?>
                  </div>
                </form>
              </td>
              <td>
                <button type="submit" form="<?= e($formId) ?>" class="btn secondary btn-sm">Update Roles</button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<?php if (!empty($isPetStore)): ?>
<section class="grid grid-2" style="gap: 1.25rem; align-items: start;">
  <?php foreach ($assignableRoles as $i => $r):
    $full = trim((string)$r['description']);
    $short = mb_strlen($full) > 70 ? rtrim(mb_substr($full, 0, 70)) . '…' : $full;
    $panelId = 'role-panel-' . (int)$r['role_id'];
  ?>
    <article class="card role-card" data-role-card>
      <div class="row" style="justify-content: space-between; margin-bottom: 0.5rem;">
        <h3 style="margin: 0;"><code><?= e($r['role_name']) ?></code></h3>
        <span class="badge"><?= count($r['permissions'] ?? []) ?> permissions</span>
      </div>
      <div class="role-desc-wrap">
        <p class="caption role-short-desc"><?= e($short) ?></p>
      </div>
      <div class="role-card-panel" id="<?= e($panelId) ?>" data-role-panel>
        <div class="role-card-panel-inner">
          <p class="caption role-full-desc"><?= e($full) ?></p>
          <?php if (!empty($r['permissions'])): ?>
            <div class="role-card-permissions">
              <span class="caption" style="font-weight: 600; color: var(--muted); text-transform: uppercase; font-size: 0.72rem; letter-spacing: 0.05em; display: block; margin: 0.65rem 0 0.35rem;">Permissions (<?= count($r['permissions']) ?>)</span>
              <ul class="list-clean" style="font-size: 0.84rem;">
                <?php foreach ($r['permissions'] as $p): ?>
                  <li style="padding: 0.2rem 0;"><code><?= e($p['permission_code']) ?></code> — <span class="muted"><?= e($p['description']) ?></span></li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>
        </div>
      </div>
      <button type="button" class="role-card-toggle" data-role-toggle aria-expanded="false" aria-controls="<?= e($panelId) ?>" aria-label="Show full description of <?= e($r['role_name']) ?>">
        <svg class="role-card-chevron" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <polyline points="6 9 12 15 18 9"></polyline>
        </svg>
      </button>
    </article>
  <?php endforeach; ?>
</section>
<?php return; endif; ?>

<section class="grid grid-2" style="gap: 1.25rem;">
  <?php foreach ($assignableRoles as $r): ?>
    <article class="card">
      <div class="row" style="justify-content: space-between; margin-bottom: 0.5rem;">
        <h3 style="margin: 0;"><code><?= e($r['role_name']) ?></code></h3>
        <span class="badge"><?= count($r['permissions'] ?? []) ?> permissions</span>
      </div>
      <p class="caption" style="margin-bottom: 0.75rem;"><?= e($r['description']) ?></p>
      <?php if (!empty($r['permissions'])): ?>
        <ul class="list-clean" style="font-size: 0.84rem;">
          <?php foreach ($r['permissions'] as $p): ?>
            <li><code><?= e($p['permission_code']) ?></code> — <span class="muted"><?= e($p['description']) ?></span></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </article>
  <?php endforeach; ?>
</section>

