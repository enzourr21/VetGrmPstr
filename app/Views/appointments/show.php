<a class="breadcrumb-link" href="/appointments"><span class="arrow-back" aria-hidden="true">&larr;</span> Back to my requests</a>
<div class="page-header">
  <div>
    <h1>Appointment request #<?= (int)$a['appointment_id'] ?></h1>
    <p class="muted tabular">Preferred: <?= e($a['preferred_date']) ?> <?= e($a['preferred_time'] ? substr($a['preferred_time'], 0, 5) : '') ?> · Submitted: <?= e($a['created_at']) ?></p>
  </div>
  <span class="badge <?= $a['status_name'] === 'Confirmed' ? 'ok' : (in_array($a['status_name'], ['Rejected', 'Cancelled', 'No-Show']) ? 'err' : 'warn') ?>"><?= e($a['status_name']) ?></span>
</div>

<div class="card" style="margin-bottom: 1.5rem;">
  <p>Status: <span class="badge <?= $a['status_name'] === 'Confirmed' ? 'ok' : (in_array($a['status_name'], ['Rejected', 'Cancelled', 'No-Show']) ? 'err' : 'warn') ?>"><?= e($a['status_name']) ?></span></p>
  <?php if ($a['notes']): ?><p class="muted">Notes: <?= e($a['notes']) ?></p><?php endif; ?>
  <?php if ($a['status_name'] === 'Pending'): ?><p class="notice" style="margin-bottom:0">This is a request, not a confirmed appointment. The receptionist will review it.</p><?php endif; ?>
</div>

<h2>Pets and services</h2>
<table>
  <thead><tr><th>Pet</th><th>Service</th><th>Duration</th><th class="right">Price</th></tr></thead>
  <tbody>
  <?php foreach ($lines as $l): ?>
    <tr><td><strong><?= e($l['pet_name']) ?></strong></td><td><?= e($l['service_name']) ?></td><td class="tabular"><?= (int)$l['estimated_duration_minutes'] ?> min</td><td class="right price"><?= money($l['requested_price']) ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table>
