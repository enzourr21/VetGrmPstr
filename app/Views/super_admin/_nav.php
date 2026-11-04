<?php $tab = $activeTab ?? 'dashboard'; ?>
<div class="admin-workspace-banner">
  <div class="row" style="justify-content: space-between; width: 100%;">
    <div class="row" style="gap: 0.75rem;">
      <span class="unit-pill unit-pill-global">SUPER ADMINISTRATOR</span>
      <span class="caption">System-Wide Unrestricted Administrative Context · <?= e(\App\Core\Session::get('email', '')) ?></span>
    </div>
    <div class="row" style="gap: 0.45rem;">
      <a href="/sysadmin" class="btn ghost btn-sm">System Admin View</a>
      <a href="/manager" class="btn ghost btn-sm">Business Manager View</a>
      <a href="/staff/appointments" class="btn ghost btn-sm">Staff Requests</a>
      <a href="/customer-interface" class="btn secondary btn-sm">Customer Interface &rarr;</a>
    </div>
  </div>
</div>

<nav class="admin-subnav" aria-label="Super Administrator navigation">
  <a href="/super-admin" class="segmented-pill <?= $tab === 'dashboard' ? 'is-active' : '' ?>">Dashboard</a>
  <a href="/super-admin/business-units" class="segmented-pill <?= $tab === 'business-units' ? 'is-active' : '' ?>">Business Units</a>
  <a href="/super-admin/system-admins" class="segmented-pill <?= $tab === 'system-admins' ? 'is-active' : '' ?>">System Administrators</a>
  <a href="/super-admin/employees" class="segmented-pill <?= $tab === 'employees' ? 'is-active' : '' ?>">Employee Accounts</a>
  <a href="/super-admin/roles" class="segmented-pill <?= $tab === 'roles' ? 'is-active' : '' ?>">Roles &amp; Permissions</a>
  <a href="/super-admin/activity" class="segmented-pill <?= $tab === 'activity' ? 'is-active' : '' ?>">System Activity</a>
</nav>

