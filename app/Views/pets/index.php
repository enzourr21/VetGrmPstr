<div class="page-header">
  <div>
    <h1>My pets</h1>
    <p class="muted">Manage your registered pets' profiles, breeds, weights, and care notes.</p>
  </div>
  <a class="btn" href="/pets/new">Add a pet</a>
</div>

<?php if (!$pets): ?>
  <div class="card">
    <h3>No pets registered yet</h3>
    <p class="muted" style="margin-bottom: 1rem;">You have not registered any pets yet. Add your pet to request grooming appointments.</p>
    <a class="btn" href="/pets/new">Register your first pet</a>
  </div>
<?php else: ?>
<div class="grid">
<?php foreach ($pets as $p): ?>
  <article class="card overview-card">
    <div>
      <div class="row" style="justify-content: space-between; margin-bottom: 0.4rem;">
        <h3><?= e($p['pet_name']) ?></h3>
        <span class="badge"><?= e($p['species']) ?></span>
      </div>
      <p class="muted tabular"><?= e($p['breed'] ?: 'Breed not set') ?> · <?= e(ucfirst($p['sex'])) ?><?= $p['weight_kg'] ? ' · ' . e($p['weight_kg']) . ' kg' : '' ?></p>
      <?php if (!empty($p['notes'])): ?>
        <p class="caption" style="margin-top: 0.35rem;"><?= e($p['notes']) ?></p>
      <?php endif; ?>
    </div>
    <div class="overview-card-footer">
      <a class="action-link" href="/pets/<?= (int)$p['pet_id'] ?>/edit">Edit <span class="arrow" aria-hidden="true">&rarr;</span></a>
    </div>
  </article>
<?php endforeach; ?>
</div>
<?php endif; ?>
