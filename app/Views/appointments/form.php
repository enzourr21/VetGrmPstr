<a class="breadcrumb-link" href="/appointments"><span class="arrow-back" aria-hidden="true">&larr;</span> My appointment requests</a>
<div class="page-header">
  <div>
    <h1>Request a grooming appointment</h1>
    <p class="muted">This is a request. It is not a confirmed booking until the receptionist confirms it.</p>
  </div>
</div>

<?php if (!$pets): ?>
  <div class="card">
    <h3>No registered pets yet</h3>
    <p class="muted" style="margin-bottom: 1rem;">You need a registered pet before requesting an appointment.</p>
    <a class="btn" href="/pets/new">Add a pet</a>
  </div>
<?php else: ?>
<form method="post" action="/appointments" data-appointment-form>
  <?= \App\Core\CSRF::field() ?>
  <?php if (isset($errors['pets'])): ?><div class="flash flash-error" role="alert"><?= e($errors['pets']) ?></div><?php endif; ?>

  <div class="selection-summary-bar" aria-live="polite">
    <strong data-appointment-summary>Select at least one grooming service for your pet(s) below.</strong>
    <a class="action-link" href="/pets/new">+ Register another pet</a>
  </div>

  <?php foreach ($pets as $p): $pid = (int)$p['pet_id']; ?>
    <fieldset class="petblock">
      <legend><strong><?= e($p['pet_name']) ?></strong> <span class="muted">(<?= e($p['species']) ?>)</span></legend>
      <?php foreach ($services as $s): $sid = (int)$s['service_id']; ?>
        <label class="check">
          <input
            type="checkbox"
            name="pets[<?= $pid ?>][]"
            value="<?= $sid ?>"
            data-pet-id="<?= $pid ?>"
            data-price="<?= (float)$s['base_price'] ?>"
            data-mins="<?= (int)$s['estimated_duration_minutes'] ?>"
            <?= in_array($sid, $selected[$pid] ?? [], true) ? 'checked' : '' ?>
          >
          <span>
            <strong><?= e($s['service_name']) ?></strong> — <span class="price"><?= money($s['base_price']) ?></span> · <span class="tabular"><?= (int)$s['estimated_duration_minutes'] ?> min</span><br>
            <span class="muted"><?= e($s['description']) ?></span>
          </span>
        </label>
      <?php endforeach; ?>
    </fieldset>
  <?php endforeach; ?>

  <div class="card" style="margin-top:1rem">
    <div class="form-grid-2">
      <div class="field">
        <div class="field-label-row">
          <label for="preferred_date" style="margin:0">Preferred date</label>
        </div>
        <input id="preferred_date" name="preferred_date" type="date" min="<?= date('Y-m-d') ?>" required value="<?= e($old['preferred_date'] ?? '') ?>">
        <?php if (isset($errors['preferred_date'])): ?><div class="field-error"><?= e($errors['preferred_date']) ?></div><?php endif; ?>
      </div>
      <div class="field">
        <div class="field-label-row">
          <label for="preferred_time" style="margin:0">Preferred time</label>
          <span class="label-optional">Optional</span>
        </div>
        <input id="preferred_time" name="preferred_time" type="time" value="<?= e($old['preferred_time'] ?? '') ?>">
        <?php if (isset($errors['preferred_time'])): ?><div class="field-error"><?= e($errors['preferred_time']) ?></div><?php endif; ?>
      </div>
    </div>
    <div class="field" style="margin-top:0.85rem">
      <div class="field-label-row">
        <label for="notes" style="margin:0">Notes</label>
        <span class="label-optional">Optional</span>
      </div>
      <textarea id="notes" name="notes" rows="3" placeholder="Any special coat, temperament, or handling notes..."><?= e($old['notes'] ?? '') ?></textarea>
    </div>
    <div class="row" style="margin-top:1.25rem; justify-content: space-between;">
      <a class="btn secondary" href="/appointments">Cancel</a>
      <button class="btn" type="submit">Submit request</button>
    </div>
  </div>
</form>
<?php endif; ?>
