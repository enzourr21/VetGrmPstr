<div class="page-header">
  <div>
    <h1>Grooming appointment requests</h1>
    <p class="muted">Review incoming customer appointment requests and update booking statuses.</p>
  </div>
  <form method="get" action="/staff/appointments" class="row">
    <label for="status" style="margin:0">Status</label>
    <select id="status" name="status" style="width:auto">
      <option value="">All</option>
      <?php foreach ($statuses as $s): ?><option <?= $filter === $s ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?>
    </select>
    <button class="btn secondary" type="submit">Filter</button>
  </form>
</div>

<?php if (!$rows): ?>
  <div class="card"><p class="muted">No requests found.</p></div>
<?php else: ?>
<table>
  <thead><tr><th>#</th><th>Customer</th><th>Pets</th><th>Preferred</th><th>Requested at</th><th>Status</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td class="tabular"><a href="/staff/appointments/<?= (int)$r['appointment_id'] ?>">#<?= (int)$r['appointment_id'] ?></a></td>
      <td><strong><?= e($r['customer_name']) ?></strong></td>
      <td><?= e($r['pets']) ?></td>
      <td class="tabular"><?= e($r['preferred_date']) ?> <?= e($r['preferred_time'] ? substr($r['preferred_time'], 0, 5) : '') ?></td>
      <td class="tabular caption"><?= e($r['created_at']) ?></td>
      <td><span class="badge <?= $r['status_name'] === 'Pending' ? 'warn' : ($r['status_name'] === 'Confirmed' ? 'ok' : '') ?>"><?= e($r['status_name']) ?></span></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
