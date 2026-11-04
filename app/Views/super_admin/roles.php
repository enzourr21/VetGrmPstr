<?php require __DIR__ . '/_nav.php'; ?>

<div class="page-header">
  <div>
    <span class="section-kicker">Access Control Architecture</span>
    <h1>Roles &amp; Permissions</h1>
    <p class="muted">System-wide RBAC visibility showing global Super Administrator authority versus business-unit-scoped roles and permissions.</p>
  </div>
</div>

<!-- Hierarchy Distinction Card -->
<div class="grid-2" style="margin-bottom: 2rem;">
  <article class="card">
    <span class="unit-pill unit-pill-global">Global Scope</span>
    <h3 style="margin-top: 0.5rem;">Super Administrator</h3>
    <p class="muted">
      Permanent system-wide administrative authority. Can access the Super Administrator interface, all three System Administrator interfaces, all Business Manager interfaces, Employee interfaces, and the Customer interface without requiring extra role assignments.
    </p>
  </article>
  <article class="card">
    <span class="unit-pill">Business-Unit Scope</span>
    <h3 style="margin-top: 0.5rem;">Business-Unit System Administrators</h3>
    <p class="muted">
      Assigned through <code>account_role</code> scoped to a single <code>business_unit_id</code> (Pet Store, Veterinary Clinic, or Grooming Services). Can manage employees, positions, and roles only within their assigned unit and cannot elevate accounts to Super Administrator.
    </p>
  </article>
</div>

<!-- System Roles Table -->
<section style="margin-bottom: 2.5rem;">
  <div class="section-header" style="margin-top: 0;">
    <div>
      <h2>System Role Definitions</h2>
      <p class="muted">Permission bundles and active assignment counts across the platform.</p>
    </div>
  </div>
  <table>
    <thead>
      <tr>
        <th>Role Name</th>
        <th>Scope</th>
        <th>Description</th>
        <th>Attached Permissions</th>
        <th class="right">Assignments</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($roles as $r): ?>
        <tr>
          <td><strong><code><?= e($r['role_name']) ?></code></strong></td>
          <td>
            <?php if ($r['role_name'] === 'SUPER_ADMINISTRATOR'): ?>
              <span class="unit-pill unit-pill-global">System-Wide</span>
            <?php else: ?>
              <span class="unit-pill">Per Business Unit</span>
            <?php endif; ?>
          </td>
          <td><?= e($r['description']) ?></td>
          <td class="caption"><code><?= e($r['permissions'] ?: 'None') ?></code></td>
          <td class="right tabular"><?= (int)$r['assigned_count'] ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</section>

<!-- Permission Catalog -->
<section>
  <div class="section-header" style="margin-top: 0;">
    <div>
      <h2>Permission Catalog</h2>
      <p class="muted">Granular backend capability codes enforced across controllers.</p>
    </div>
  </div>
  <table>
    <thead>
      <tr>
        <th>Permission Code</th>
        <th>Description</th>
        <th>Granted To Roles</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($permissions as $p): ?>
        <tr>
          <td><code><?= e($p['permission_code']) ?></code></td>
          <td><?= e($p['description']) ?></td>
          <td class="caption"><code><?= e($p['granted_to_roles'] ?: 'None') ?></code></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</section>

