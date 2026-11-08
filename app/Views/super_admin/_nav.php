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

