<div class="page-header">
  <div>
    <h1>Your profile</h1>
    <p class="muted">Manage your personal contact information and default delivery address.</p>
  </div>
</div>

<div class="card" style="margin-bottom:1.15rem">
  <div class="row" style="justify-content:space-between">
    <div>
      <strong><?= e($c['email']) ?></strong>
      <?php if (!$c['email_verified_at']): ?>
        <p class="caption" style="margin-top:0.2rem">We have not sent a verification email in this version, so this address is unverified.</p>
      <?php endif; ?>
    </div>
    <?php if ($c['email_verified_at']): ?>
      <span class="badge ok">Email verified</span>
    <?php else: ?>
      <span class="badge warn">Email not verified</span>
    <?php endif; ?>
  </div>
</div>

<form method="post" action="/profile" class="card">
  <?= \App\Core\CSRF::field() ?>
  <h2 style="margin-top:0">Personal information</h2>
  <div class="form-grid-3">
    <div class="field">
      <div class="field-label-row"><label for="first_name" style="margin:0">First name</label></div>
      <input id="first_name" name="first_name" type="text" required value="<?= e($c['first_name'] ?? '') ?>">
      <?php if (isset($errors['first_name'])): ?><div class="field-error"><?= e($errors['first_name']) ?></div><?php endif; ?>
    </div>
    <div class="field">
      <div class="field-label-row"><label for="middle_name" style="margin:0">Middle name</label><span class="label-optional">Optional</span></div>
      <input id="middle_name" name="middle_name" type="text" value="<?= e($c['middle_name'] ?? '') ?>">
      <?php if (isset($errors['middle_name'])): ?><div class="field-error"><?= e($errors['middle_name']) ?></div><?php endif; ?>
    </div>
    <div class="field">
      <div class="field-label-row"><label for="last_name" style="margin:0">Last name</label></div>
      <input id="last_name" name="last_name" type="text" required value="<?= e($c['last_name'] ?? '') ?>">
      <?php if (isset($errors['last_name'])): ?><div class="field-error"><?= e($errors['last_name']) ?></div><?php endif; ?>
    </div>
  </div>

  <div class="field" style="margin-top:0.85rem">
    <div class="field-label-row"><label for="phone" style="margin:0">Phone</label><span class="label-optional">Optional</span></div>
    <input id="phone" name="phone" type="text" value="<?= e($c['phone'] ?? '') ?>">
    <?php if (isset($errors['phone'])): ?><div class="field-error"><?= e($errors['phone']) ?></div><?php endif; ?>
  </div>

  <h2>Default delivery address</h2>
  <div class="form-grid-2">
    <div class="field">
      <div class="field-label-row"><label for="address_line1" style="margin:0">Address line 1</label><span class="label-optional">Optional</span></div>
      <input id="address_line1" name="address_line1" type="text" value="<?= e($c['address_line1'] ?? '') ?>">
      <?php if (isset($errors['address_line1'])): ?><div class="field-error"><?= e($errors['address_line1']) ?></div><?php endif; ?>
    </div>
    <div class="field">
      <div class="field-label-row"><label for="address_line2" style="margin:0">Address line 2</label><span class="label-optional">Optional</span></div>
      <input id="address_line2" name="address_line2" type="text" value="<?= e($c['address_line2'] ?? '') ?>">
      <?php if (isset($errors['address_line2'])): ?><div class="field-error"><?= e($errors['address_line2']) ?></div><?php endif; ?>
    </div>
  </div>
  <div class="form-grid-3" style="margin-top:0.85rem">
    <div class="field">
      <div class="field-label-row"><label for="city" style="margin:0">City</label><span class="label-optional">Optional</span></div>
      <input id="city" name="city" type="text" value="<?= e($c['city'] ?? '') ?>">
      <?php if (isset($errors['city'])): ?><div class="field-error"><?= e($errors['city']) ?></div><?php endif; ?>
    </div>
    <div class="field">
      <div class="field-label-row"><label for="province" style="margin:0">Province / State</label><span class="label-optional">Optional</span></div>
      <input id="province" name="province" type="text" value="<?= e($c['province'] ?? '') ?>">
      <?php if (isset($errors['province'])): ?><div class="field-error"><?= e($errors['province']) ?></div><?php endif; ?>
    </div>
    <div class="field">
      <div class="field-label-row"><label for="postal_code" style="margin:0">Postal code</label><span class="label-optional">Optional</span></div>
      <input id="postal_code" name="postal_code" type="text" value="<?= e($c['postal_code'] ?? '') ?>">
      <?php if (isset($errors['postal_code'])): ?><div class="field-error"><?= e($errors['postal_code']) ?></div><?php endif; ?>
    </div>
  </div>

  <div style="margin-top:1.25rem"><button class="btn" type="submit">Save changes</button></div>
</form>
