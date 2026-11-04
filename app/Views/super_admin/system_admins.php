<?php require __DIR__ . '/_nav.php'; ?>

<div class="page-header">
  <div>
    <span class="section-kicker">Unit Governance</span>
    <h1>System Administrators</h1>
    <p class="muted">View and provision the System Administrator accounts scoped to Pet Store, Veterinary Clinic, and Grooming Services.</p>
  </div>
</div>

<!-- Existing System Administrators Table -->
<section style="margin-bottom: 2.5rem;">
  <div class="section-header" style="margin-top: 0;">
    <div>
      <h2>Assigned Business-Unit System Administrators</h2>
      <p class="muted">Each System Administrator is strictly isolated to managing employees, positions, and roles within their assigned business unit.</p>
    </div>
  </div>

  <?php if (!$sysadmins): ?>
    <div class="card"><p class="muted">No System Administrator accounts have been created yet.</p></div>
  <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>Administrator Name</th>
          <th>Email (Admin Sign-In)</th>
          <th>Username (Customer Context)</th>
          <th>Assigned Business Unit</th>
          <th>Default Position</th>
          <th>Account Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($sysadmins as $sa): ?>
          <tr>
            <td><strong><?= e(trim(($sa['first_name'] ?? '') . ' ' . ($sa['last_name'] ?? '')) ?: 'Administrator') ?></strong></td>
            <td class="tabular"><?= e($sa['email']) ?></td>
            <td class="tabular"><?= e($sa['username'] ?: '—') ?></td>
            <td><span class="unit-pill"><?= e($sa['business_unit_name']) ?></span></td>
            <td><?= e($sa['position'] ?: 'System Administrator') ?></td>
            <td>
              <span class="badge <?= $sa['account_status'] === 'active' ? 'ok' : 'err' ?>">
                <?= e(ucfirst($sa['account_status'])) ?>
              </span>
            </td>
            <td>
              <div class="row" style="gap: 0.4rem;">
                <a class="btn ghost btn-sm" href="/sysadmin/units/<?= (int)$sa['business_unit_id'] ?>">Inspect Unit</a>
                <form method="post" action="/super-admin/accounts/<?= (int)$sa['user_account_id'] ?>/status" class="inline" data-confirm-action="Are you sure you want to change the status of <?= e($sa['email']) ?>?">
                  <?= \App\Core\CSRF::field() ?>
                  <input type="hidden" name="return_to" value="/super-admin/system-admins">
                  <input type="hidden" name="account_status" value="<?= $sa['account_status'] === 'active' ? 'suspended' : 'active' ?>">
                  <button type="submit" class="btn <?= $sa['account_status'] === 'active' ? 'danger' : 'secondary' ?> btn-sm">
                    <?= $sa['account_status'] === 'active' ? 'Suspend' : 'Activate' ?>
                  </button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>

<!-- Create System Administrator Account Form -->
<section id="create-sysadmin-form">
  <div class="card">
    <span class="section-kicker">Provision Unit Administrator</span>
    <h2 style="margin-top: 0.15rem;">Create System Administrator Account</h2>
    <p class="muted" style="margin-bottom: 1.25rem;">
      Assigns the <strong>SYSTEM_ADMINISTRATOR</strong> role scoped strictly to the selected business unit. Passwords are hashed before storage and never recorded in plaintext.
    </p>

    <?php if (!empty($errors['general'])): ?>
      <div class="flash flash-error" role="alert"><?= e($errors['general']) ?></div>
    <?php endif; ?>

    <form method="post" action="/super-admin/system-admins" novalidate data-unit-position-form>
      <?= \App\Core\CSRF::field() ?>
      <input type="hidden" name="roles[]" value="SYSTEM_ADMINISTRATOR">

      <div class="form-grid-2">
        <div class="field">
          <label for="sa_full_name">1. Employee full name</label>
          <input id="sa_full_name" name="full_name" type="text" required placeholder="e.g. Adrian Mercer" value="<?= e($old['full_name'] ?? '') ?>">
          <?php if (isset($errors['full_name'])): ?><div class="field-error"><?= e($errors['full_name']) ?></div><?php endif; ?>
        </div>

        <div class="field">
          <label for="sa_email">2. Email address <span class="label-optional">(Used for Admin Sign-In)</span></label>
          <input id="sa_email" name="email" type="email" required placeholder="sysadmin.unit@vetgrpet.local" value="<?= e($old['email'] ?? '') ?>">
          <?php if (isset($errors['email'])): ?><div class="field-error"><?= e($errors['email']) ?></div><?php endif; ?>
        </div>

        <div class="field">
          <label for="sa_unit">3. Assigned business unit</label>
          <select id="sa_unit" name="business_unit_id" required data-unit-select>
            <option value="">Select business unit...</option>
            <?php foreach ($units as $u): ?>
              <option value="<?= (int)$u['business_unit_id'] ?>" <?= (int)($old['business_unit_id'] ?? 0) === (int)$u['business_unit_id'] ? 'selected' : '' ?>>
                <?= e($u['business_unit_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <?php if (isset($errors['business_unit_id'])): ?><div class="field-error"><?= e($errors['business_unit_id']) ?></div><?php endif; ?>
        </div>

        <div class="field">
          <label for="sa_position">4. Default position</label>
          <select id="sa_position" name="position" required data-position-select>
            <option value="System Administrator" selected>System Administrator</option>
            <?php foreach ($positionsByUnit as $p): ?>
              <option value="<?= e($p['position_name']) ?>" data-unit-id="<?= (int)$p['business_unit_id'] ?>" <?= ($old['position'] ?? '') === $p['position_name'] ? 'selected' : '' ?>>
                <?= e($p['business_unit_name'] . ' — ' . $p['position_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <?php if (isset($errors['position'])): ?><div class="field-error"><?= e($errors['position']) ?></div><?php endif; ?>
        </div>

        <div class="field">
          <label for="sa_role_display">5. System role</label>
          <input id="sa_role_display" type="text" readonly value="SYSTEM_ADMINISTRATOR (Scoped to Selected Business Unit)">
        </div>

        <div class="field">
          <label for="sa_username">Username <span class="label-optional">(Optional · Customer-Only Login)</span></label>
          <input id="sa_username" name="username" type="text" placeholder="e.g. sysadmin_store" value="<?= e($old['username'] ?? '') ?>">
          <?php if (isset($errors['username'])): ?><div class="field-error"><?= e($errors['username']) ?></div><?php endif; ?>
        </div>

        <div class="field">
          <label for="sa_password">6. Initial password <span class="label-optional">(Min 10 characters)</span></label>
          <div class="password-input-wrap">
            <input id="sa_password" name="password" type="password" required minlength="10" autocomplete="new-password" placeholder="At least 10 characters">
            <button type="button" class="password-toggle-btn" data-pw-toggle="sa_password" aria-label="Show password" aria-pressed="false">Show</button>
          </div>
          <?php if (isset($errors['password'])): ?><div class="field-error"><?= e($errors['password']) ?></div><?php endif; ?>
        </div>

        <div class="field">
          <label for="sa_phone">Phone number <span class="label-optional">(Optional)</span></label>
          <input id="sa_phone" name="phone" type="text" placeholder="555-0100" value="<?= e($old['phone'] ?? '') ?>">
          <?php if (isset($errors['phone'])): ?><div class="field-error"><?= e($errors['phone']) ?></div><?php endif; ?>
        </div>
      </div>

      <div class="stage-actions">
        <button type="submit" class="btn">Create System Administrator Account</button>
      </div>
    </form>
  </div>
</section>

