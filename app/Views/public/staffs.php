<?php
$managers = [];
$team = [];
foreach ($staffs as $member) {
    if (str_contains((string)($member['roles'] ?? ''), 'BUSINESS_MANAGER') || str_contains((string)$member['position'], 'Manager')) {
        $managers[] = $member;
    } else {
        $team[] = $member;
    }
}
?>
<div class="page-header">
  <div>
    <h1>Staffs &amp; Business Managers</h1>
    <p class="muted">Meet the unit managers, veterinarians, nurses, and grooming specialists caring for your pets.</p>
  </div>
</div>

<?php if ($managers): ?>
<section style="margin-bottom: 2.5rem;">
  <div class="section-header" style="margin-top: 0;">
    <div>
      <h2>Business Unit Managers</h2>
      <p class="muted">Operational leadership across our Pet Store, Veterinary Clinic, and Grooming Services units.</p>
    </div>
  </div>
  <div class="grid">
    <?php foreach ($managers as $m):
      $initials = mb_strtoupper(mb_substr($m['first_name'], 0, 1) . mb_substr($m['last_name'], 0, 1));
    ?>
      <article class="card overview-card staff-card">
        <div>
          <div class="row" style="justify-content: space-between; margin-bottom: 0.75rem;">
            <span class="staff-avatar" aria-hidden="true"><?= e($initials) ?></span>
            <?php if (!empty($m['units'])): ?><span class="caption"><?= e($m['units']) ?></span><?php endif; ?>
          </div>
          <h3><?= e($m['first_name'] . ' ' . $m['last_name']) ?></h3>
          <p class="muted" style="margin-bottom: 0.35rem;"><?= e($m['position']) ?></p>
        </div>
        <div class="overview-card-footer caption">
          <span>Role</span>
          <strong>Business Manager</strong>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<section>
  <div class="section-header">
    <div>
      <h2>Clinical, Grooming &amp; Care Team</h2>
      <p class="muted">Dedicated specialists supporting appointments, clinical care, and pet styling.</p>
    </div>
  </div>
  <?php if (!$team && !$managers): ?>
    <div class="card"><p>No active staff profiles are listed yet.</p></div>
  <?php else: ?>
    <div class="grid">
      <?php foreach ($team as $t):
        $initials = mb_strtoupper(mb_substr($t['first_name'], 0, 1) . mb_substr($t['last_name'], 0, 1));
        $roleLabel = str_replace('_', ' ', ucwords(strtolower((string)($t['roles'] ?: 'Staff Member')), '_'));
      ?>
        <article class="card overview-card staff-card">
          <div>
            <div class="row" style="justify-content: space-between; margin-bottom: 0.75rem;">
              <span class="staff-avatar" aria-hidden="true"><?= e($initials) ?></span>
              <?php if (!empty($t['units'])): ?><span class="caption"><?= e($t['units']) ?></span><?php endif; ?>
            </div>
            <h3><?= e($t['first_name'] . ' ' . $t['last_name']) ?></h3>
            <p class="muted" style="margin-bottom: 0.35rem;"><?= e($t['position']) ?></p>
          </div>
          <div class="overview-card-footer caption">
            <span>Role</span>
            <strong><?= e($roleLabel) ?></strong>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
