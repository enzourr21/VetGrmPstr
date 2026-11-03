<a class="breadcrumb-link" href="/pets"><span class="arrow-back" aria-hidden="true">&larr;</span> My pets</a>
<div class="page-header">
  <div>
    <h1><?= e($title) ?></h1>
    <p class="muted">Keep your pet's species, breed, weight, and care notes accurate for our clinical and grooming teams.</p>
  </div>
</div>

<form method="post" action="<?= e($action) ?>" class="card">
  <?= \App\Core\CSRF::field() ?>
  <div class="form-grid-3">
    <div class="field">
      <div class="field-label-row">
        <label for="pet_name" style="margin:0">Pet name</label>
      </div>
      <input id="pet_name" name="pet_name" type="text" required value="<?= e($pet['pet_name'] ?? '') ?>">
      <?php if (isset($errors['pet_name'])): ?><div class="field-error"><?= e($errors['pet_name']) ?></div><?php endif; ?>
    </div>
    <div class="field">
      <div class="field-label-row">
        <label for="species" style="margin:0">Species</label>
      </div>
      <select id="species" name="species" required>
        <option value="">Choose…</option>
        <?php foreach (\App\Controllers\PetController::SPECIES as $s): ?><option <?= ($pet['species'] ?? '') === $s ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?>
      </select>
      <?php if (isset($errors['species'])): ?><div class="field-error"><?= e($errors['species']) ?></div><?php endif; ?>
    </div>
    <div class="field">
      <div class="field-label-row">
        <label for="breed" style="margin:0">Breed</label>
        <span class="label-optional">Optional</span>
      </div>
      <input id="breed" name="breed" type="text" value="<?= e($pet['breed'] ?? '') ?>">
    </div>
  </div>

  <div class="form-grid-3" style="margin-top:0.85rem">
    <div class="field">
      <div class="field-label-row">
        <label for="sex" style="margin:0">Sex</label>
      </div>
      <select id="sex" name="sex">
        <?php foreach (['unknown','male','female'] as $s): ?><option value="<?= $s ?>" <?= ($pet['sex'] ?? 'unknown') === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <div class="field-label-row">
        <label for="date_of_birth" style="margin:0">Date of birth</label>
        <span class="label-optional">Optional</span>
      </div>
      <input id="date_of_birth" name="date_of_birth" type="date" max="<?= date('Y-m-d') ?>" value="<?= e($pet['date_of_birth'] ?? '') ?>">
      <?php if (isset($errors['date_of_birth'])): ?><div class="field-error"><?= e($errors['date_of_birth']) ?></div><?php endif; ?>
    </div>
    <div class="field">
      <div class="field-label-row">
        <label for="weight_kg" style="margin:0">Weight (kg)</label>
        <span class="label-optional">Optional</span>
      </div>
      <input id="weight_kg" name="weight_kg" type="text" inputmode="decimal" value="<?= e($pet['weight_kg'] ?? '') ?>">
      <?php if (isset($errors['weight_kg'])): ?><div class="field-error"><?= e($errors['weight_kg']) ?></div><?php endif; ?>
    </div>
  </div>

  <div class="field" style="margin-top:0.85rem">
    <div class="field-label-row">
      <label for="color_markings" style="margin:0">Colour / markings</label>
      <span class="label-optional">Optional</span>
    </div>
    <input id="color_markings" name="color_markings" type="text" value="<?= e($pet['color_markings'] ?? '') ?>">
  </div>

  <div class="field">
    <div class="field-label-row">
      <label for="notes" style="margin:0">Notes (temperament, allergies…)</label>
      <span class="label-optional">Optional</span>
    </div>
    <textarea id="notes" name="notes" rows="3"><?= e($pet['notes'] ?? '') ?></textarea>
  </div>

  <div class="row" style="margin-top:1.25rem; justify-content: space-between;">
    <a class="btn secondary" href="/pets">Cancel</a>
    <button class="btn" type="submit">Save</button>
  </div>
</form>
