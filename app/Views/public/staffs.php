<?php
$unitList = !empty($units) ? $units : \App\Models\Units::all();

$slugifyUnit = static function (string $name): string {
    return trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
};

$extractUnits = static function (array $member): array {
    $raw = trim((string)($member['units'] ?? ''));
    if ($raw === '') {
        return [];
    }
    return array_values(array_filter(array_map('trim', explode(' · ', $raw)), static fn(string $u): bool => $u !== ''));
};

$managers = [];
$team = [];
foreach ($staffs as $member) {
    if (str_contains((string)($member['roles'] ?? ''), 'BUSINESS_MANAGER') || str_contains((string)$member['position'], 'Business Manager')) {
        $managers[] = $member;
    } else {
        $team[] = $member;
    }
}

$teamByUnit = [];
foreach ($unitList as $u) {
    $teamByUnit[(string)$u['business_unit_name']] = [];
}
$unassignedTeam = [];
foreach ($team as $t) {
    $tUnits = $extractUnits($t);
    if (!$tUnits) {
        $unassignedTeam[] = $t;
        continue;
    }
    foreach ($tUnits as $tu) {
        if (!isset($teamByUnit[$tu])) {
            $teamByUnit[$tu] = [];
        }
        $teamByUnit[$tu][] = $t;
    }
}

$unitIconSvg = static function (string $unitName): string {
    if ($unitName === \App\Models\Units::VET_CLINIC) {
        return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4.8 2.3A.3.3 0 1 0 5 2H4a2 2 0 0 0-2 2v5a6 6 0 0 0 6 6v0a6 6 0 0 0 6-6V4a2 2 0 0 0-2-2h-1a.2.2 0 1 0 .3.3"/><path d="M8 15v1a6 6 0 0 0 6 6v0a6 6 0 0 0 6-6v-4"/><circle cx="20" cy="10" r="2"/></svg>';
    }
    if ($unitName === \App\Models\Units::GROOMING) {
        return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="6" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><line x1="20" y1="4" x2="8.12" y2="15.88"/><line x1="14.47" y1="14.48" x2="20" y2="20"/><line x1="8.12" y1="8.12" x2="12" y2="12"/></svg>';
    }
    return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>';
};
?>
<div class="page-header staffs-page-header">
  <div>
    <h1>Staffs &amp; Business Managers</h1>
    <p class="muted">Active team members across each unit.</p>
  </div>
</div>

<?php if ($managers): ?>
<section class="staff-section" aria-labelledby="managers-heading" data-staff-managers-section>
  <div class="section-header" style="margin-top: 0;">
    <div>
      <h2 id="managers-heading">Business Unit Managers</h2>
      <p class="muted">Unit leads overseeing operations.</p>
    </div>
  </div>
  <div class="grid">
    <?php foreach ($managers as $m):
      $initials = mb_strtoupper(mb_substr($m['first_name'], 0, 1) . mb_substr($m['last_name'], 0, 1));
      $mUnits = $extractUnits($m);
      $mSlugs = implode(' ', array_map($slugifyUnit, $mUnits));
    ?>
      <article class="card overview-card staff-card staff-profile-card" data-staff-units="<?= e($mSlugs) ?>">
        <div class="staff-card-top">
          <span class="staff-avatar staff-profile-avatar" aria-hidden="true"><?= e($initials) ?></span>
        </div>
        <div class="staff-card-identity">
          <h3 class="staff-name"><?= e($m['first_name'] . ' ' . $m['last_name']) ?></h3>
          <p class="staff-position"><?= e($m['position']) ?></p>
          <?php if ($mUnits): ?>
            <div class="staff-unit-list">
              <?php foreach ($mUnits as $mu): ?>
                <span class="staff-unit"><?= e($mu) ?></span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<section class="staff-section" aria-labelledby="team-heading">
  <div class="section-header" style="<?= $managers ? '' : 'margin-top: 0;' ?>">
    <div>
      <h2 id="team-heading">Clinical, Grooming &amp; Care Team</h2>
      <p class="muted">Specialists assigned to each unit.</p>
    </div>
  </div>

  <?php if (!$team && !$managers): ?>
    <div class="card"><p class="muted">No active staff profiles are listed yet.</p></div>
  <?php else: ?>
    <?php foreach ($unitList as $u):
      $uName = (string)$u['business_unit_name'];
      $uSlug = $slugifyUnit($uName);
      $members = $teamByUnit[$uName] ?? [];
    ?>
      <div class="staff-unit-group" id="team-<?= e($uSlug) ?>" data-staff-unit-group="<?= e($uSlug) ?>">
        <div class="service-section-header">
          <div class="service-section-title">
            <span class="service-section-icon" aria-hidden="true"><?= $unitIconSvg($uName) ?></span>
            <h3><?= e($uName) ?></h3>
          </div>
          <span class="caption tabular"><?= count($members) ?> member<?= count($members) === 1 ? '' : 's' ?></span>
        </div>

        <?php if (!$members): ?>
          <div class="empty-subtle">No additional care team members are listed under <?= e($uName) ?>.</div>
        <?php else: ?>
          <div class="grid">
            <?php foreach ($members as $t):
              $initials = mb_strtoupper(mb_substr($t['first_name'], 0, 1) . mb_substr($t['last_name'], 0, 1));
            ?>
              <article class="card overview-card staff-card staff-profile-card" data-staff-units="<?= e($uSlug) ?>">
                <div class="staff-card-top">
                  <span class="staff-avatar staff-profile-avatar" aria-hidden="true"><?= e($initials) ?></span>
                </div>
                <div class="staff-card-identity">
                  <h4 class="staff-name"><?= e($t['first_name'] . ' ' . $t['last_name']) ?></h4>
                  <p class="staff-position"><?= e($t['position']) ?> &middot; <span class="staff-unit"><?= e($uName) ?></span></p>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>

    <?php if ($unassignedTeam): ?>
      <div class="staff-unit-group" data-staff-unit-group="general">
        <div class="service-section-header">
          <div class="service-section-title">
            <h3>General Care Team</h3>
          </div>
          <span class="caption tabular"><?= count($unassignedTeam) ?> member<?= count($unassignedTeam) === 1 ? '' : 's' ?></span>
        </div>
        <div class="grid">
          <?php foreach ($unassignedTeam as $t):
            $initials = mb_strtoupper(mb_substr($t['first_name'], 0, 1) . mb_substr($t['last_name'], 0, 1));
          ?>
            <article class="card overview-card staff-card staff-profile-card">
              <div class="staff-card-top">
                <span class="staff-avatar staff-profile-avatar" aria-hidden="true"><?= e($initials) ?></span>
              </div>
              <div class="staff-card-identity">
                <h4 class="staff-name"><?= e($t['first_name'] . ' ' . $t['last_name']) ?></h4>
                <p class="staff-position"><?= e($t['position']) ?></p>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</section>
