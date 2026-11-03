<a class="breadcrumb-link" href="/staff/appointments"><span class="arrow-back" aria-hidden="true">&larr;</span> Back to list</a>
<div class="page-header">
  <div>
    <h1>Request #<?= (int)$a['appointment_id'] ?></h1>
    <p class="muted tabular">Requested at: <?= e($a['created_at']) ?></p>
  </div>
  <span class="badge <?= $a['status_name'] === 'Confirmed' ? 'ok' : ($a['status_name'] === 'Pending' ? 'warn' : 'err') ?>"><?= e($a['status_name']) ?></span>
</div>

<div class="grid-2" style="margin-bottom: 1.5rem;">
  <div class="card">
    <h2 style="margin-top:0">Customer</h2>
    <p><strong><?= e($a['first_name'] . ' ' . $a['last_name']) ?></strong><br><?= e($a['email']) ?><br><?= e($a['phone'] ?: 'No phone on file') ?></p>
  </div>
  <div class="card">
    <h2 style="margin-top:0">Request</h2>
    <p>
      Status: <span class="badge <?= $a['status_name'] === 'Confirmed' ? 'ok' : ($a['status_name'] === 'Pending' ? 'warn' : 'err') ?>"><?= e($a['status_name']) ?></span><br>
      Requested at: <span class="tabular"><?= e($a['created_at']) ?></span><br>
      Preferred: <span class="tabular"><?= e($a['preferred_date']) ?> <?= e($a['preferred_time'] ? substr($a['preferred_time'], 0, 5) : '(no time given)') ?></span>
    </p>
    <?php if ($a['notes']): ?><p class="muted">Customer notes: <?= e($a['notes']) ?></p><?php endif; ?>
  </div>
</div>

<h2>Pets</h2>
<table>
  <thead><tr><th>Name</th><th>Species</th><th>Breed</th><th>Sex</th><th>Weight</th><th>Notes</th></tr></thead>
  <tbody>
  <?php foreach ($pets as $p): ?>
    <tr><td><strong><?= e($p['pet_name']) ?></strong></td><td><?= e($p['species']) ?></td><td><?= e($p['breed']) ?></td><td><?= e(ucfirst($p['sex'])) ?></td><td class="tabular"><?= $p['weight_kg'] ? e($p['weight_kg']) . ' kg' : '' ?></td><td><?= e($p['notes']) ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table>

<h2>Selected services</h2>
<table>
  <thead><tr><th>Pet</th><th>Service</th><th>Duration</th><th class="right">Price</th></tr></thead>
  <tbody>
  <?php foreach ($lines as $l): ?>
    <tr><td><strong><?= e($l['pet_name']) ?></strong></td><td><?= e($l['service_name']) ?></td><td class="tabular"><?= (int)$l['estimated_duration_minutes'] ?> min</td><td class="right price"><?= money($l['requested_price']) ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table>

<div class="card" style="margin-top: 1.5rem;">
  <h2 style="margin-top: 0;">Process request</h2>
  <?php if ($next): ?>
    <form method="post" action="/staff/appointments/<?= (int)$a['appointment_id'] ?>/status" class="row" style="margin-bottom: 0.75rem;">
      <?= \App\Core\CSRF::field() ?>
      <?php foreach ($next as $n): ?><button class="btn <?= in_array($n, ['Rejected', 'Cancelled', 'No-Show']) ? 'danger' : '' ?>" type="submit" name="status" value="<?= e($n) ?>"><?= e($n) ?></button><?php endforeach; ?>
    </form>
    <p class="muted">Confirming records acceptance only. Employee and facility scheduling are not implemented yet.</p>
  <?php else: ?>
    <p class="muted">No further receptionist actions for this status.</p>
  <?php endif; ?>
</div>
