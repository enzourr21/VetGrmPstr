<?php require __DIR__ . '/_nav.php'; ?>

<div class="page-header">
  <div>
    <span class="section-kicker">Audit Trail</span>
    <h1>System Activity</h1>
    <p class="muted">Chronological audit log of account creation, role updates, authentication sessions, and operational actions.</p>
  </div>
  <form method="get" action="/super-admin/activity" class="row">
    <label for="act_unit" style="margin:0">Business Unit</label>
    <select id="act_unit" name="unit_id" style="width:auto">
      <option value="">All Units &amp; System-Wide</option>
      <?php foreach ($units as $u): ?>
        <option value="<?= (int)$u['business_unit_id'] ?>" <?= $filterUnit === (int)$u['business_unit_id'] ? 'selected' : '' ?>>
          <?= e($u['business_unit_name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn secondary">Filter</button>
  </form>
</div>

<?php if (!$logs): ?>
  <div class="card"><p class="muted">No audit log entries match the selected filter.</p></div>
<?php else: ?>
  <table>
    <thead>
      <tr>
        <th>#</th>
        <th>Timestamp</th>
        <th>Action Code</th>
        <th>Business Unit</th>
        <th>Actor</th>
        <th>Summary</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($logs as $log): ?>
        <tr>
          <td class="tabular caption">#<?= (int)$log['log_id'] ?></td>
          <td class="tabular caption"><?= e($log['created_at']) ?></td>
          <td><code><?= e($log['action_code']) ?></code></td>
          <td><?= e($log['business_unit_name'] ?: 'System-Wide') ?></td>
          <td><?= e(trim((string)$log['actor_name']) !== '' ? $log['actor_name'] : ($log['actor_email'] ?: 'System')) ?></td>
          <td><?= e($log['summary']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

