<div class="page-header">
  <div>
    <h1>My appointment requests</h1>
    <p class="muted">Track your submitted grooming appointment requests and receptionist confirmation status.</p>
  </div>
  <a class="btn" href="/appointments/new">New request</a>
</div>

<?php if (!$rows): ?>
  <div class="card">
    <h3>No requests yet</h3>
    <p class="muted" style="margin-bottom: 1rem;">Select your registered pets and grooming treatments to submit your first appointment request.</p>
    <a class="btn" href="/appointments/new">Request a grooming appointment</a>
  </div>
<?php else: ?>
<table>
  <thead><tr><th>#</th><th>Pets</th><th>Preferred</th><th>Submitted</th><th>Status</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td class="tabular"><a href="/appointments/<?= (int)$r['appointment_id'] ?>">#<?= (int)$r['appointment_id'] ?></a></td>
      <td><strong><?= e($r['pets']) ?></strong></td>
      <td class="tabular"><?= e($r['preferred_date']) ?> <?= e($r['preferred_time'] ? substr($r['preferred_time'], 0, 5) : '') ?></td>
      <td class="tabular caption"><?= e($r['created_at']) ?></td>
      <td><span class="badge <?= $r['status_name'] === 'Confirmed' ? 'ok' : (in_array($r['status_name'], ['Rejected', 'Cancelled', 'No-Show']) ? 'err' : 'warn') ?>"><?= e($r['status_name']) ?></span></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
