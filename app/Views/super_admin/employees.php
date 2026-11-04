<?php require __DIR__ . '/_nav.php'; ?>

<div class="page-header">
  <div>
    <span class="section-kicker">Workforce Directory</span>
    <h1>Employee Accounts</h1>
    <p class="muted">View and create employee and administrator accounts across Pet Store, Veterinary Clinic, and Grooming Services.</p>
  </div>
  <form method="get" action="/super-admin/employees" class="row">
    <label for="filter_unit" style="margin:0">Business Unit</label>
    <select id="filter_unit" name="unit_id" style="width:auto">
      <option value="">All Business Units</option>
      <?php foreach ($units as $u): ?>
        <option value="<?= (int)$u['business_unit_id'] ?>" <?= $filterUnit === (int)$u['business_unit_id'] ? 'selected' : '' ?>>
          <?= e($u['business_unit_name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn secondary">Filter</button>
  </form>
</div>

<!-- Employee Directory Table -->
<section style="margin-bottom: 2.5rem;">
  <?php if (!$employees): ?>
    <div class="card"><p class="muted">No employee records match the selected filter.</p></div>
  <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>Employee Name</th>
          <th>Email (Staff Sign-In)</th>
          <th>Username (Customer Sign-In)</th>
          <th>Business Unit</th>
          <th>Default Position</th>
          <th>System Role(s)</th>
          <th>Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($employees as $emp): ?>
          <tr>
            <td><strong><?= e($emp['first_name'] . ' ' . $emp['last_name']) ?></strong></td>
            <td class="tabular"><?= e($emp['email']) ?></td>
            <td class="tabular"><?= e($emp['username'] ?: '—') ?></td>
            <td>
              <?php if ((bool)$emp['is_super_admin']): ?>
                <span class="unit-pill unit-pill-global">All Business Units</span>
              <?php else: ?>
                <span class="unit-pill"><?= e($emp['business_unit_name'] ?: 'Unassigned') ?></span>
              <?php endif; ?>
            </td>
            <td><?= e($emp['position']) ?></td>
            <td><code><?= e($emp['roles'] ?: ((bool)$emp['is_super_admin'] ? 'SUPER_ADMINISTRATOR' : 'None')) ?></code></td>
            <td>
              <span class="badge <?= $emp['account_status'] === 'active' ? 'ok' : ($emp['account_status'] === 'suspended' ? 'err' : 'warn') ?>">
                <?= e(ucfirst($emp['account_status'])) ?>
              </span>
            </td>
            <td>
              <?php if ((bool)$emp['is_super_admin']): ?>
                <span class="caption">Permanent Account</span>
              <?php else: ?>
                <form method="post" action="/super-admin/accounts/<?= (int)$emp['user_account_id'] ?>/status" class="inline" data-confirm-action="Change account status for <?= e($emp['email']) ?>?">
                  <?= \App\Core\CSRF::field() ?>
                  <input type="hidden" name="return_to" value="/super-admin/employees">
                  <input type="hidden" name="account_status" value="<?= $emp['account_status'] === 'active' ? 'suspended' : 'active' ?>">
                  <button type="submit" class="btn <?= $emp['account_status'] === 'active' ? 'ghost' : 'secondary' ?> btn-sm">
                    <?= $emp['account_status'] === 'active' ? 'Suspend' : 'Activate' ?>
                  </button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>

<!-- Super Administrator Account Creation Form (Priority 1.5) -->
<section id="create-employee-form">
  <div class="card">
    <span class="section-kicker">Direct Account Creation</span>
    <h2 style="margin-top: 0.15rem;">Create Employee or System Administrator Account</h2>
    <p class="muted" style="margin-bottom: 1.25rem;">
      Collects the employee's full name, email address, assigned business unit, default position, system role(s), and initial password. Passwords are hashed immediately and never stored or displayed in recoverable form.
    </p>

    <?php if (!empty($errors['general'])): ?>
      <div class="flash flash-error" role="alert"><?= e($errors['general']) ?></div>
    <?php endif; ?>

    <form method="post" action="/super-admin/employees" novalidate data-unit-position-form>
      <?= \App\Core\CSRF::field() ?>

      <div class="form-grid-2">
        <div class="field">
          <label for="emp_full_name">1. Employee full name</label>
          <input id="emp_full_name" name="full_name" type="text" required placeholder="e.g. Jordan Rivera" value="<?= e($old['full_name'] ?? '') ?>">
          <?php if (isset($errors['full_name'])): ?><div class="field-error"><?= e($errors['full_name']) ?></div><?php endif; ?>
        </div>

        <div class="field">
          <label for="emp_email">2. Email address <span class="label-optional">(Employee/Admin Sign-In)</span></label>
          <input id="emp_email" name="email" type="email" required placeholder="employee@vetgrpet.local" value="<?= e($old['email'] ?? '') ?>">
          <?php if (isset($errors['email'])): ?><div class="field-error"><?= e($errors['email']) ?></div><?php endif; ?>
        </div>

        <div class="field">
          <label for="emp_unit">3. Assigned business unit</label>
          <select id="emp_unit" name="business_unit_id" required data-unit-select>
            <option value="">Select business unit...</option>
            <?php foreach ($units as $u): ?>
              <option value="<?= (int)$u['business_unit_id'] ?>" data-unit-name="<?= e($u['business_unit_name']) ?>" <?= (int)($old['business_unit_id'] ?? 0) === (int)$u['business_unit_id'] ? 'selected' : '' ?>>
                <?= e($u['business_unit_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <?php if (isset($errors['business_unit_id'])): ?><div class="field-error"><?= e($errors['business_unit_id']) ?></div><?php endif; ?>
        </div>

        <div class="field">
          <label for="emp_position">4. Default position</label>
          <select id="emp_position" name="position" required data-position-select>
            <option value="">Select default position...</option>
            <?php foreach ($positionsByUnit as $p): ?>
              <option value="<?= e($p['position_name']) ?>" data-unit-id="<?= (int)$p['business_unit_id'] ?>" <?= ($old['position'] ?? '') === $p['position_name'] ? 'selected' : '' ?>>
                <?= e($p['business_unit_name'] . ' — ' . $p['position_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <?php if (isset($errors['position'])): ?><div class="field-error"><?= e($errors['position']) ?></div><?php endif; ?>
        </div>

        <div class="field">
          <label for="emp_username">Username <span class="label-optional">(Optional · Customer-Only Login)</span></label>
          <input id="emp_username" name="username" type="text" placeholder="e.g. jordan_rivera" value="<?= e($old['username'] ?? '') ?>">
          <?php if (isset($errors['username'])): ?><div class="field-error"><?= e($errors['username']) ?></div><?php endif; ?>
        </div>

        <div class="field">
          <label for="emp_password">6. Initial password <span class="label-optional">(Min 10 characters)</span></label>
          <div class="password-input-wrap">
            <input id="emp_password" name="password" type="password" required minlength="10" autocomplete="new-password" placeholder="At least 10 characters">
            <button type="button" class="password-toggle-btn" data-pw-toggle="emp_password" aria-label="Show password" aria-pressed="false">Show</button>
          </div>
          <?php if (isset($errors['password'])): ?><div class="field-error"><?= e($errors['password']) ?></div><?php endif; ?>
        </div>
      </div>

      <fieldset class="petblock" style="margin-top: 1.15rem;">
        <legend><strong>5. System role or roles</strong> <span class="caption">(Scoped to selected business unit)</span></legend>
        <div class="grid-3">
          <?php
          $oldRoles = (array)($old['roles'] ?? []);
          foreach ($assignableRoles as $r):
            $isClinical = in_array($r['role_name'], \App\Models\AdminAccountService::CLINICAL_ROLES, true);
            $checked = in_array($r['role_name'], $oldRoles, true) || in_array((string)$r['role_id'], $oldRoles, true);
          ?>
            <label class="check" <?= $isClinical ? 'data-clinical-role="1"' : '' ?>>
              <input type="checkbox" name="roles[]" value="<?= e($r['role_name']) ?>" <?= $checked ? 'checked' : '' ?>>
              <span>
                <strong><?= e($r['role_name']) ?></strong>
                <?php if ($isClinical): ?><span class="caption" style="display:block;color:var(--brand)">(Veterinary Clinic only)</span><?php endif; ?>
                <span class="caption" style="display:block"><?= e($r['description']) ?></span>
              </span>
            </label>
          <?php endforeach; ?>
        </div>
        <?php if (isset($errors['roles'])): ?><div class="field-error"><?= e($errors['roles']) ?></div><?php endif; ?>
      </fieldset>

      <div class="stage-actions">
        <button type="submit" class="btn">Create Employee Account</button>
      </div>
    </form>
  </div>
</section>

