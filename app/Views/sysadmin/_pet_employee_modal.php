<?php
/** Pet Store System Administrator: Add Employee modal dialog with full employee creation form. */
$selectedRoles = $old['roles'] ?? [];
if (!is_array($selectedRoles)) {
    $selectedRoles = [$selectedRoles];
}
$selectedRoles = array_map('strval', $selectedRoles);
$roleDescriptions = [];
foreach ($assignableRoles as $r) {
    $roleDescriptions[(string)$r['role_name']] = (string)$r['description'];
}

$positionRoleMap = [];
foreach ($positions as $pos) {
    $initRoles = \App\Models\AdminAccountService::initialRolesForPosition($pos['position_name'], $unitName);
    if (!empty($initRoles)) {
        $positionRoleMap[$pos['position_name']] = count($initRoles) === 1 ? $initRoles[0] : $initRoles;
    }
}
$selectedPos = $old['position'] ?? '';
$initialRolesForSelectedPos = \App\Models\AdminAccountService::initialRolesForPosition($selectedPos, $unitName);
?>
<dialog class="modal modal-wide" id="add-employee-modal" aria-labelledby="add-employee-modal-title" <?= !empty($errors) ? 'data-open-on-load="true"' : '' ?> data-role-picker>
  <div class="modal-head">
    <h3 id="add-employee-modal-title">Create Employee in <?= e($unitName) ?></h3>
    <button type="button" class="btn ghost btn-sm" data-modal-close aria-label="Close dialog">&times; Close</button>
  </div>
  <div class="modal-body">
    <p class="caption" style="margin-bottom: 1rem;">All new accounts created here are automatically bound to <strong><?= e($unitName) ?></strong>.</p>

    <form method="post" action="/sysadmin/employees<?= $unitQuery ?>" novalidate id="add-employee-form">
      <?= \App\Core\CSRF::field() ?>
      <input type="hidden" name="business_unit_id" value="<?= (int)$unitId ?>">

      <div class="field">
        <label for="sa-emp-name">1. Employee full name</label>
        <input id="sa-emp-name" name="full_name" type="text" required
               value="<?= e($old['full_name'] ?? '') ?>"
               placeholder="e.g. Carla Mendoza"
               aria-invalid="<?= isset($errors['full_name']) ? 'true' : 'false' ?>">
        <?php if (isset($errors['full_name'])): ?>
          <div class="field-error"><?= e($errors['full_name']) ?></div>
        <?php endif; ?>
      </div>

      <div class="grid grid-2">
        <div class="field">
          <label for="sa-emp-email">2. Email address (Staff login)</label>
          <input id="sa-emp-email" name="email" type="email" required
                 value="<?= e($old['email'] ?? '') ?>"
                 placeholder="carla.mendoza@vetgrpet.local"
                 aria-invalid="<?= isset($errors['email']) ? 'true' : 'false' ?>">
          <?php if (isset($errors['email'])): ?>
            <div class="field-error"><?= e($errors['email']) ?></div>
          <?php endif; ?>
        </div>
        <div class="field">
          <label for="sa-emp-username">Username (Optional · Customer-only login)</label>
          <input id="sa-emp-username" name="username" type="text"
                 value="<?= e($old['username'] ?? '') ?>"
                 placeholder="Auto-generated if blank"
                 aria-invalid="<?= isset($errors['username']) ? 'true' : 'false' ?>">
          <?php if (isset($errors['username'])): ?>
            <div class="field-error"><?= e($errors['username']) ?></div>
          <?php endif; ?>
        </div>
      </div>

      <div class="grid grid-2">
        <div class="field">
          <label>3. Assigned business unit</label>
          <input type="text" value="<?= e($unitName) ?>" disabled>
          <div class="help">Locked to your assigned business unit.</div>
        </div>

        <div class="field">
          <label for="sa-emp-position">4. Default position</label>
          <select id="sa-emp-position" name="position" required
                  data-position-roles='<?= htmlspecialchars(json_encode($positionRoleMap), ENT_QUOTES, 'UTF-8') ?>'
                  aria-invalid="<?= isset($errors['position']) ? 'true' : 'false' ?>">
            <option value="">Select position in <?= e($unitName) ?>&hellip;</option>
            <?php foreach ($positions as $pos): ?>
              <option value="<?= e($pos['position_name']) ?>"
                <?= ($old['position'] ?? '') === $pos['position_name'] ? 'selected' : '' ?>>
                <?= e($pos['position_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <?php if (isset($errors['position'])): ?>
            <div class="field-error"><?= e($errors['position']) ?></div>
          <?php endif; ?>
        </div>
      </div>

      <div class="field">
        <div class="field-label-row">
          <label style="margin:0">5. System roles in <?= e($unitName) ?></label>
          <button type="button" class="btn secondary btn-sm" data-role-add aria-haspopup="dialog">+ Add Role</button>
        </div>
        <div class="role-chips" data-role-chips aria-live="polite">
          <?php foreach ($selectedRoles as $rn): if ($rn === '' || !isset($roleDescriptions[$rn])) continue; ?>
            <span class="role-chip" data-role-chip="<?= e($rn) ?>" <?= in_array($rn, $initialRolesForSelectedPos, true) ? 'data-role-auto="true"' : '' ?>>
              <input type="hidden" name="roles[]" value="<?= e($rn) ?>">
              <span><?= e($rn) ?></span>
              <button type="button" class="role-chip-remove" data-role-remove aria-label="Remove role <?= e($rn) ?>">&times;</button>
            </span>
          <?php endforeach; ?>
        </div>
        <div class="help" data-role-empty <?= array_filter($selectedRoles) ? 'hidden' : '' ?>>No roles selected yet. Selecting a default position automatically assigns its initial role, or use Add Role to choose roles.</div>
        <?php if (isset($errors['roles'])): ?>
          <div class="field-error"><?= e($errors['roles']) ?></div>
        <?php endif; ?>
      </div>

      <div class="field">
        <label for="sa-emp-phone">Phone (Optional)</label>
        <input id="sa-emp-phone" name="phone" type="text"
               value="<?= e($old['phone'] ?? '') ?>"
               placeholder="0917-555-0199"
               aria-invalid="<?= isset($errors['phone']) ? 'true' : 'false' ?>">
        <?php if (isset($errors['phone'])): ?>
          <div class="field-error"><?= e($errors['phone']) ?></div>
        <?php endif; ?>
      </div>

      <div style="display: flex; gap: 0.75rem; justify-content: flex-end; margin-top: 1.25rem;">
        <button type="button" class="btn ghost" data-modal-close>Cancel</button>
        <button type="submit" class="btn" id="create-emp-submit-btn" disabled>Send Invite Link</button>
      </div>
    </form>
  </div>

  <dialog class="modal" id="role-modal" aria-labelledby="role-modal-title">
    <div class="modal-head">
      <h3 id="role-modal-title">Add system role</h3>
      <button type="button" class="btn ghost btn-sm" data-modal-close>Close</button>
    </div>
    <div class="modal-body">
      <p class="caption" style="margin-bottom: 0.75rem;">Roles control system access and permissions. They are separate from the default position.</p>
      <ul class="list-clean role-options">
        <?php foreach ($assignableRoles as $r): ?>
          <li>
            <button type="button" class="role-option" data-role-option="<?= e($r['role_name']) ?>">
              <strong><?= e($r['role_name']) ?></strong>
              <small><?= e($r['description']) ?></small>
            </button>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </dialog>
</dialog>

