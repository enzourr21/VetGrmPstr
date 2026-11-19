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
            <td><span class="meta-label"><?= e($sa['business_unit_name']) ?></span></td>
            <td><?= e($sa['position'] ?: 'System Administrator') ?></td>
            <td>
              <span class="status <?= $sa['account_status'] === 'active' ? 'ok' : 'err' ?>">
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

    <?php
      $selectedUnitId = (int)($old['business_unit_id'] ?? 0);
      $derivedPosition = '';
      foreach ($units as $u) {
          if ((int)$u['business_unit_id'] === $selectedUnitId) {
              $derivedPosition = \App\Models\AdminAccountService::systemAdminPositionForUnit((string)$u['business_unit_name']);
              break;
          }
      }
      $positionPlaceholder = 'Select a business unit to assign position';
    ?>
    <form method="post" action="/super-admin/system-admins" novalidate data-sysadmin-provision-form>
      <?= \App\Core\CSRF::field() ?>

      <div class="form-grid-2">
        <div class="field">
          <label for="sa_full_name">1. Employee full name</label>
          <input id="sa_full_name" name="full_name" type="text" required placeholder="e.g. Adrian Mercer" value="<?= e($old['full_name'] ?? '') ?>">
          <?php if (isset($errors['full_name'])): ?><div class="field-error"><?= e($errors['full_name']) ?></div><?php endif; ?>
        </div>

        <div class="field">
          <label for="sa_email">2. Email address <span class="label-optional">(Used for Admin Sign-In)</span></label>
          <input id="sa_email" name="email" type="email" autocomplete="email" required placeholder="sysadmin.unit@vetgrpet.local" aria-describedby="sa_email_error" aria-invalid="<?= isset($errors['email']) ? 'true' : 'false' ?>" value="<?= e($old['email'] ?? '') ?>">
          <div class="field-error" id="sa_email_error" data-sa-email-error <?= isset($errors['email']) ? '' : 'hidden' ?>><?= e($errors['email'] ?? '') ?></div>
        </div>

        <div class="field">
          <label for="sa_unit">3. Assigned business unit</label>
          <select id="sa_unit" name="business_unit_id" required data-sysadmin-unit-select>
            <option value="" data-sysadmin-position="">Select business unit...</option>
            <?php foreach ($units as $u):
              $unitPosition = \App\Models\AdminAccountService::systemAdminPositionForUnit((string)$u['business_unit_name']);
            ?>
              <option value="<?= (int)$u['business_unit_id'] ?>" data-sysadmin-position="<?= e($unitPosition) ?>" <?= $selectedUnitId === (int)$u['business_unit_id'] ? 'selected' : '' ?>>
                <?= e($u['business_unit_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <?php if (isset($errors['business_unit_id'])): ?><div class="field-error"><?= e($errors['business_unit_id']) ?></div><?php endif; ?>
        </div>

        <div class="field">
          <label id="sa_position_label">4. Default position</label>
          <div
            id="sa_position_display"
            class="readonly-display-field <?= $derivedPosition === '' ? 'is-placeholder' : '' ?>"
            aria-labelledby="sa_position_label"
            aria-live="polite"
            data-sysadmin-position-display
            data-placeholder="<?= e($positionPlaceholder) ?>"
          ><?= e($derivedPosition !== '' ? $derivedPosition : $positionPlaceholder) ?></div>
        </div>

        <div class="field">
          <label for="sa_password">5. Initial password <span class="label-optional">(Min 10 characters)</span></label>
          <div class="password-input-wrap">
            <input id="sa_password" name="password" type="password" required minlength="10" autocomplete="new-password" placeholder="At least 10 characters">
            <button type="button" class="password-toggle-btn" data-pw-toggle="sa_password" aria-label="Show password" aria-pressed="false"><svg class="icon-eye" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg><svg class="icon-eye-off" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.4 10.4 0 0 1 12 19c-6.5 0-10-7-10-7a18.6 18.6 0 0 1 4.06-5.06"/><path d="M9.9 5.24A9.7 9.7 0 0 1 12 5c6.5 0 10 7 10 7a18.5 18.5 0 0 1-2.16 3.19"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/><line x1="2" y1="2" x2="22" y2="22"/></svg></button>
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

