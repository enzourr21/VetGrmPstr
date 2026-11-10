<?php
$title = $title ?? 'Billing · Grooming Services Receptionist';
$ongoing = $ongoingAppointments ?? [];
$completed = $recentlyCompleted ?? [];
?>

<div class="page-head" style="margin-bottom: 1.5rem;">
  <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem; width: 100%;">
    <div>
      <p class="eyebrow">Grooming Services Workspace</p>
      <h1>Billing &amp; Payments</h1>
      <p class="muted">Settle payments for completed grooming sessions, generate verified service invoices, and review payment logs.</p>
    </div>
    <div>
      <!-- Mandatory + Add Billing Button -->
      <button type="button" class="btn primary" id="add-billing-btn" onclick="openAddBillingModal()">
        + Add Billing
      </button>
    </div>
  </div>
</div>

<!-- SECTION 1: Upcoming / Ongoing Appointments Card -->
<section class="card" style="margin-bottom: 1.5rem; padding: 1.5rem;">
  <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 1rem;">
    <div>
      <h2 style="margin: 0; font-size: 1.25rem;">Upcoming / Ongoing Studio Appointments (<?= count($ongoing) ?>)</h2>
      <p class="caption muted" style="margin: 0.2rem 0 0 0;">
        Today's scheduled grooming visits. When all services are performed, sessions transition to <strong>Pending Payment</strong> for billing.
      </p>
    </div>
    <span class="caption muted"><?= date('F j, Y') ?></span>
  </div>

  <?php if (empty($ongoing)): ?>
    <div class="empty" style="padding: 2rem 1rem; text-align: center;">
      <p class="muted" style="margin: 0;">No active ongoing grooming appointments scheduled for today.</p>
    </div>
  <?php else: ?>
    <div style="overflow-x: auto; width: 100%;">
      <table style="width: 100%; border-collapse: collapse;">
        <thead>
          <tr style="border-bottom: 1px solid var(--color-border, #e5e7eb); text-align: left; background: var(--color-bg-subtle, #f9fafb);">
            <th style="padding: 0.65rem 1rem; font-size: 0.75rem; font-weight: 600; text-transform: uppercase;">Appt #</th>
            <th style="padding: 0.65rem 1rem; font-size: 0.75rem; font-weight: 600; text-transform: uppercase;">Customer &amp; Pet</th>
            <th style="padding: 0.65rem 1rem; font-size: 0.75rem; font-weight: 600; text-transform: uppercase;">Scheduled Time</th>
            <th style="padding: 0.65rem 1rem; font-size: 0.75rem; font-weight: 600; text-transform: uppercase;">Services</th>
            <th style="padding: 0.65rem 1rem; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; text-align: center;">Session Status</th>
            <th style="padding: 0.65rem 1rem; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; text-align: right;">Billing Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($ongoing as $item): ?>
            <?php
            $isPaid = ($item['invoice_status'] ?? '') === 'paid';
            $isReady = !empty($item['is_pending_payment']);
            ?>
            <tr style="border-bottom: 1px solid var(--color-border, #f3f4f6);">
              <td style="padding: 0.75rem 1rem; font-weight: 700; color: var(--color-text, #111827);">
                #<?= (int)$item['appointment_id'] ?>
              </td>
              <td style="padding: 0.75rem 1rem;">
                <div style="font-weight: 600; color: var(--color-text, #111827);">
                  <?= e($item['customer_display_name']) ?>
                  <?php if (!empty($item['customer_id_display'])): ?>
                    <span class="caption muted">(#<?= (int)$item['customer_id_display'] ?>)</span>
                  <?php else: ?>
                    <span class="badge" style="font-size: 0.65rem; background: #e0f2fe; color: #0369a1;">Guest</span>
                  <?php endif; ?>
                </div>
                <div class="caption muted" style="margin-top: 0.15rem;">
                  🐾 <?= e($item['pets_summary']) ?>
                </div>
              </td>
              <td style="padding: 0.75rem 1rem;">
                <div style="font-weight: 500; color: var(--color-text, #374151);">
                  <?= e($item['time_coverage']) ?>
                </div>
              </td>
              <td style="padding: 0.75rem 1rem; font-size: 0.85rem; color: var(--color-text, #4b5563);">
                <?= e($item['services_summary']) ?>
              </td>
              <td style="padding: 0.75rem 1rem; text-align: center;">
                <span class="badge <?= e($item['status_badge_class']) ?>">
                  <?= e($item['display_status']) ?>
                </span>
              </td>
              <td style="padding: 0.75rem 1rem; text-align: right;">
                <button type="button" class="btn primary btn-sm" onclick="startBillingForAppointment(<?= (int)$item['appointment_id'] ?>)">
                  💳 Process Bill
                </button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<!-- SECTION 2: Recently Completed Appointments Card -->
<section class="card" style="margin-bottom: 2rem; padding: 1.5rem;">
  <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 1rem;">
    <div>
      <h2 style="margin: 0; font-size: 1.25rem;">Recently Completed &amp; Settled Appointments (<?= count($completed) ?>)</h2>
      <p class="caption muted" style="margin: 0.2rem 0 0 0;">
        Recent Grooming sessions with fully settled payments and verified service invoices.
      </p>
    </div>
  </div>

  <?php if (empty($completed)): ?>
    <div class="empty" style="padding: 2rem 1rem; text-align: center;">
      <p class="muted" style="margin: 0;">No grooming appointments have been billed and paid yet.</p>
    </div>
  <?php else: ?>
    <div style="overflow-x: auto; width: 100%;">
      <table style="width: 100%; border-collapse: collapse;">
        <thead>
          <tr style="border-bottom: 1px solid var(--color-border, #e5e7eb); text-align: left; background: var(--color-bg-subtle, #f9fafb);">
            <th style="padding: 0.65rem 1rem; font-size: 0.75rem; font-weight: 600; text-transform: uppercase;">Invoice #</th>
            <th style="padding: 0.65rem 1rem; font-size: 0.75rem; font-weight: 600; text-transform: uppercase;">Appt #</th>
            <th style="padding: 0.65rem 1rem; font-size: 0.75rem; font-weight: 600; text-transform: uppercase;">Customer &amp; Pet</th>
            <th style="padding: 0.65rem 1rem; font-size: 0.75rem; font-weight: 600; text-transform: uppercase;">Method</th>
            <th style="padding: 0.65rem 1rem; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; text-align: right;">Amount Settled</th>
            <th style="padding: 0.65rem 1rem; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; text-align: center;">Invoice Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($completed as $c): ?>
            <tr style="border-bottom: 1px solid var(--color-border, #f3f4f6);">
              <td style="padding: 0.75rem 1rem; font-weight: 600; color: var(--color-text, #111827);">
                #INV-<?= (int)($c['invoice_id'] ?? 0) ?>
              </td>
              <td style="padding: 0.75rem 1rem; font-weight: 600; color: var(--brand, #0e8a75);">
                #<?= (int)$c['appointment_id'] ?>
              </td>
              <td style="padding: 0.75rem 1rem;">
                <div style="font-weight: 600; color: var(--color-text, #111827);">
                  <?= e($c['customer_display_name']) ?>
                </div>
                <div class="caption muted">🐾 <?= e($c['pets_summary']) ?></div>
              </td>
              <td style="padding: 0.75rem 1rem;">
                <span class="badge" style="background: #f3f4f6; color: #374151; font-weight: 500; text-transform: uppercase; font-size: 0.75rem;">
                  <?= e($c['payment_method'] ?? 'Cash') ?>
                </span>
              </td>
              <td style="padding: 0.75rem 1rem; text-align: right; font-weight: 700; color: #059669; font-size: 1rem;">
                ₱<?= number_format((float)($c['total_amount'] ?? 0), 2) ?>
              </td>
              <td style="padding: 0.75rem 1rem; text-align: center;">
                <span class="badge badge-success">
                  Paid
                </span>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<!-- ========================================================================= -->
<!-- MODAL: + ADD BILLING (Step 1: Type Selection -> Step 2: Settle Payment)   -->
<!-- ========================================================================= -->
<dialog class="modal modal-wide" id="add-billing-modal" aria-labelledby="add-billing-modal-title">
  <div class="card" style="width: 100%; max-width: 680px; max-height: 90vh; overflow-y: auto; padding: 1.75rem; border: none; box-shadow: none;">
    
    <div class="modal-head" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; border-bottom: 1px solid var(--color-border, #e5e7eb); padding: 0 0 0.75rem 0;">
      <div>
        <h2 id="add-billing-modal-title" style="margin: 0; font-size: 1.25rem;">+ Add Billing</h2>
        <p class="caption muted" style="margin: 0.2rem 0 0 0;">Select eligible completed session and process payment invoice.</p>
      </div>
      <button type="button" class="btn ghost btn-sm" data-modal-close onclick="closeAddBillingModal()" style="font-size: 1.25rem; line-height: 1; padding: 0.25rem 0.5rem;" aria-label="Close modal">✕</button>
    </div>

    <!-- Step 1: Appointment Selection (Walk-in vs Reserved) -->
    <div id="billing-step-1">
      <div style="margin-bottom: 1.25rem;">
        <span class="caption" style="font-weight: 600; display: block; margin-bottom: 0.4rem;">Select Transaction Type</span>
        <div style="display: flex; gap: 1.5rem;">
          <label style="display: flex; align-items: center; gap: 0.4rem; cursor: pointer; font-size: 0.95rem;">
            <input type="radio" name="billing_type" value="walkin" checked onchange="toggleBillingType('walkin')">
            <span><strong>Walk-in Customer</strong></span>
          </label>
          <label style="display: flex; align-items: center; gap: 0.4rem; cursor: pointer; font-size: 0.95rem;">
            <input type="radio" name="billing_type" value="reserved" onchange="toggleBillingType('reserved')">
            <span><strong>Reserved Booking</strong></span>
          </label>
        </div>
      </div>

      <!-- Search Section -->
      <div id="billing-search-wrap" style="margin-bottom: 1rem;">
        <div class="field" style="margin: 0; position: relative;">
          <label id="billing-search-label" for="billing-search-input" class="caption" style="display: block; font-weight: 600; margin-bottom: 0.25rem;">
            Search Walk-in by Guest Customer Name
          </label>
          <div style="display: flex; gap: 0.5rem; align-items: center;">
            <div style="position: relative; flex: 1;">
              <input type="search" id="billing-search-input" placeholder="Enter guest customer name..." style="width: 100%; padding-right: 2.2rem;" oninput="handleBillingSearchInput(this.value)">
              <span id="billing-search-spinner" style="position: absolute; right: 0.65rem; top: 50%; transform: translateY(-50%); font-size: 0.85rem; color: #6b7280; display: none;">⏳</span>
            </div>
            <button type="button" class="btn ghost btn-sm" onclick="runBillingSearchImmediate()" title="Refresh / Load eligible appointments">🔄 Refresh</button>
          </div>
          <p class="caption muted" style="margin: 0.25rem 0 0 0;" id="billing-search-hint">
            Only appointments with completed services awaiting payment (PENDING_PAYMENT) appear.
          </p>
        </div>
      </div>

      <!-- Search Results / Eligible Appointments List (min-height prevents container height jumping) -->
      <div id="billing-results-container" style="min-height: 180px; max-height: 280px; overflow-y: auto; display: flex; flex-direction: column; gap: 0.5rem; margin-bottom: 1rem; border: 1px dashed #e5e7eb; border-radius: 6px; padding: 0.5rem;">
        <!-- Injected dynamically via JS -->
      </div>
    </div>

    <!-- Step 2: Billing Breakdown & Payment Settlement Form -->
    <div id="billing-step-2" style="display: none;">
      <div style="background: var(--color-bg-subtle, #f9fafb); padding: 1rem; border-radius: 8px; border: 1px solid var(--color-border, #e5e7eb); margin-bottom: 1.25rem;">
        <div style="display: flex; justify-content: space-between; align-items: baseline;">
          <div>
            <div style="font-weight: 700; font-size: 1.1rem; color: var(--color-text, #111827);" id="b-appt-title">
              Appointment #---
            </div>
            <div class="caption muted" id="b-customer-info">Customer: ---</div>
            <div class="caption muted" id="b-pets-info">Pets: ---</div>
          </div>
          <button type="button" class="btn ghost btn-sm" onclick="backToBillingSelection()">&larr; Change Appointment</button>
        </div>
      </div>

      <!-- Itemized Billing Breakdown Table -->
      <div style="margin-bottom: 1.25rem;">
        <h3 style="margin: 0 0 0.5rem 0; font-size: 1rem;">Billing Breakdown</h3>
        <div style="border: 1px solid var(--color-border, #e5e7eb); border-radius: 6px; overflow: hidden;">
          <table style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
            <thead>
              <tr style="background: var(--color-bg-subtle, #f9fafb); border-bottom: 1px solid var(--color-border, #e5e7eb); text-align: left;">
                <th style="padding: 0.5rem 0.75rem;">Service / Package Item</th>
                <th style="padding: 0.5rem 0.75rem; text-align: center;">Qty (Pets)</th>
                <th style="padding: 0.5rem 0.75rem; text-align: right;">Unit Price</th>
                <th style="padding: 0.5rem 0.75rem; text-align: right;">Subtotal</th>
              </tr>
            </thead>
            <tbody id="billing-breakdown-tbody">
              <!-- Line items injected via JS -->
            </tbody>
          </table>
        </div>
      </div>

      <!-- Payment Section: Payment Method appears BEFORE Total Cost -->
      <form method="post" action="/grooming/receptionist/billing/settle" id="settle-payment-form">
        <?= \App\Core\CSRF::field() ?>
        <input type="hidden" name="appointment_id" id="b-hidden-appt-id">

        <div style="background: #fdfefe; border: 1.5px solid #d1fae5; border-radius: 8px; padding: 1.25rem; margin-bottom: 1.25rem;">
          <h4 style="margin: 0 0 0.75rem 0; font-size: 0.95rem; color: #065f46;">Payment Settlement</h4>

          <!-- Payment Method: Placed BEFORE Total Cost as explicitly requested -->
          <div class="field" style="margin-bottom: 0.75rem;">
            <label for="b-payment-method" class="caption" style="display: block; font-weight: 600; margin-bottom: 0.25rem;">
              Payment Method *
            </label>
            <select id="b-payment-method" name="payment_method" required style="width: 100%; font-weight: 600;">
              <option value="cash">Cash</option>
              <option value="gcash">GCash</option>
              <option value="maya">Maya</option>
              <option value="other">Other / Card</option>
            </select>
          </div>

          <!-- Total Cost Display -->
          <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 0.75rem; padding: 0.5rem 0; border-top: 1px dashed #d1d5db; border-bottom: 1px dashed #d1d5db;">
            <span style="font-weight: 600; font-size: 1.05rem; color: var(--color-text, #111827);">Total Cost</span>
            <span style="font-weight: 800; font-size: 1.35rem; color: var(--brand, #0e8a75);" id="b-total-cost-display">₱0.00</span>
          </div>

          <!-- Payment Received Input -->
          <div class="field" style="margin-bottom: 0.75rem;">
            <label for="b-payment-received" class="caption" style="display: block; font-weight: 600; margin-bottom: 0.25rem;">
              Payment Received (₱) *
            </label>
            <input type="number" id="b-payment-received" name="payment_received" step="0.01" min="0" required placeholder="0.00" style="width: 100%; font-size: 1.1rem; font-weight: 700;" oninput="calculateChange()">
          </div>

          <!-- Change Calculation Display -->
          <div style="display: flex; justify-content: space-between; align-items: baseline; background: #fff; padding: 0.65rem 0.75rem; border-radius: 6px; border: 1px solid #e5e7eb;">
            <span class="caption" style="font-weight: 600; text-transform: uppercase;">Change Due</span>
            <span style="font-size: 1.15rem; font-weight: 700; color: #111827;" id="b-change-display">₱0.00</span>
          </div>
          <!-- Warning uses visibility: hidden to prevent layout shift while typing -->
          <div id="b-payment-warning" class="caption" style="color: #dc2626; margin-top: 0.4rem; font-weight: 600; min-height: 1.25rem; visibility: hidden;">
            Payment Received is less than Total Cost. Amount must be fully settled.
          </div>
        </div>

        <div style="display: flex; justify-content: space-between; align-items: center;">
          <button type="button" class="btn ghost btn-sm" onclick="closeAddBillingModal()">Cancel</button>
          <button type="submit" class="btn primary btn-sm" id="b-submit-settle-btn">
            ✓ Settle &amp; Complete Appointment
          </button>
        </div>
      </form>
    </div>
  </div>
</dialog>

<script>
var currentBillingType = 'walkin';
var currentTotalAmount = 0.0;
var billingSearchTimer = null;
var billingSearchAbortController = null;

function openAddBillingModal() {
  var dlg = document.getElementById('add-billing-modal');
  if (!dlg) return;
  if (typeof dlg.showModal === 'function') {
    if (!dlg.open) dlg.showModal();
  } else {
    dlg.setAttribute('open', '');
  }
  document.body.classList.add('has-modal-open');
  backToBillingSelection();
  runBillingSearchImmediate();
}

function closeAddBillingModal() {
  var dlg = document.getElementById('add-billing-modal');
  if (!dlg) return;
  if (typeof dlg.close === 'function') {
    dlg.close();
  } else {
    dlg.removeAttribute('open');
  }
  document.body.classList.remove('has-modal-open');
}

// Preserve search input across toggling Walk-in vs Reserved
function toggleBillingType(type) {
  currentBillingType = type;
  var label = document.getElementById('billing-search-label');
  var input = document.getElementById('billing-search-input');
  if (type === 'walkin') {
    label.textContent = 'Search Walk-in by Guest Customer Name';
    input.placeholder = 'Enter guest customer name...';
  } else {
    label.textContent = 'Search Reserved Booking by Appointment ID or Customer Name #CustomerID';
    input.placeholder = 'e.g. #101 or Adrian Mercer #2...';
  }
  // Typed search query is preserved across type toggle!
  runBillingSearchImmediate();
}

function handleBillingSearchInput(val) {
  clearTimeout(billingSearchTimer);
  var spinner = document.getElementById('billing-search-spinner');
  if (spinner) spinner.style.display = 'inline-block';
  billingSearchTimer = setTimeout(function() {
    runBillingSearch(val);
  }, 220);
}

function refreshBillingSearch() {
  runBillingSearchImmediate();
}

function runBillingSearchImmediate() {
  clearTimeout(billingSearchTimer);
  var input = document.getElementById('billing-search-input');
  var q = input ? input.value.trim() : '';
  runBillingSearch(q);
}

function onBillingSearch(query) {
  runBillingSearch(query);
}

function runBillingSearch(query) {
  var spinner = document.getElementById('billing-search-spinner');
  if (spinner) spinner.style.display = 'inline-block';

  if (billingSearchAbortController) {
    billingSearchAbortController.abort();
  }
  if (window.AbortController) {
    billingSearchAbortController = new AbortController();
  }

  var container = document.getElementById('billing-results-container');
  if (container) {
    container.style.opacity = '0.6';
  }

  var fetchOpts = billingSearchAbortController ? { signal: billingSearchAbortController.signal } : {};

  fetch('/grooming/receptionist/billing/search-appointments?type=' + currentBillingType + '&q=' + encodeURIComponent(query || ''), fetchOpts)
    .then(function(res) { return res.json(); })
    .then(function(data) {
      if (container) container.style.opacity = '1';
      if (spinner) spinner.style.display = 'none';

      if (!data.ok || !data.appointments || data.appointments.length === 0) {
        if (container) {
          container.innerHTML = '<div style="padding: 1.5rem; text-align: center; color: #6b7280; font-size: 0.9rem; background: #f9fafb; border-radius: 6px;">' +
                                  'No eligible appointments found. Only appointments with finished services not yet settled (PENDING_PAYMENT) appear.' +
                                '</div>';
        }
        return;
      }
      var html = '';
      data.appointments.forEach(function(a) {
        html += '<div style="background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; padding: 0.75rem 1rem; display: flex; justify-content: space-between; align-items: center; transition: background 0.15s ease;" onmouseover="this.style.background=\'#f3f4f6\'" onmouseout="this.style.background=\'#f9fafb\'">' +
                  '<div>' +
                    '<div style="font-weight: 700; color: #111827; font-size: 0.95rem;">' +
                      '#' + a.appointment_id + ' — ' + escapeHtml(a.customer_display_name) +
                      (a.customer_id_display ? ' <span style="font-size: 0.75rem; color: #6b7280;">(#' + a.customer_id_display + ')</span>' : ' <span class="badge" style="font-size: 0.65rem; background: #e0f2fe; color: #0369a1;">Guest</span>') +
                    '</div>' +
                    '<div class="caption muted" style="margin-top: 0.15rem;">🐾 ' + escapeHtml(a.pets_summary) + ' • ✂️ ' + escapeHtml(a.services_summary) + '</div>' +
                  '</div>' +
                  '<div style="text-align: right;">' +
                    '<div style="font-weight: 700; color: #0e8a75; font-size: 1.05rem;">₱' + parseFloat(a.total_amount || 0).toFixed(2) + '</div>' +
                    '<button type="button" class="btn primary btn-sm" style="margin-top: 0.25rem;" onclick="loadAppointmentForBilling(' + a.appointment_id + ')">Select &rarr;</button>' +
                  '</div>' +
                '</div>';
      });
      if (container) container.innerHTML = html;
    })
    .catch(function(err) {
      if (err.name === 'AbortError') return;
      if (container) {
        container.style.opacity = '1';
        container.innerHTML = '<div style="padding: 1rem; text-align: center; color: #dc2626; font-size: 0.85rem;">Error searching appointments. Please try again.</div>';
      }
      if (spinner) spinner.style.display = 'none';
    });
}

function startBillingForAppointment(apptId) {
  openAddBillingModal();
  loadAppointmentForBilling(apptId);
}

function loadAppointmentForBilling(apptId) {
  fetch('/grooming/receptionist/billing/appointments/' + apptId)
    .then(function(res) { return res.json(); })
    .then(function(data) {
      if (!data.ok || !data.appointment) {
        alert(data.error || 'Failed to load appointment billing details.');
        return;
      }
      var a = data.appointment;
      document.getElementById('b-hidden-appt-id').value = a.appointment_id;
      document.getElementById('b-appt-title').textContent = 'Appointment #' + a.appointment_id + ' — ' + a.formatted_date;
      document.getElementById('b-customer-info').textContent = 'Customer: ' + a.customer_display_name + (a.customer_phone ? ' (' + a.customer_phone + ')' : '');
      document.getElementById('b-pets-info').textContent = 'Pets: ' + a.pets_summary;

      currentTotalAmount = parseFloat(a.total_amount || 0);
      document.getElementById('b-total-cost-display').textContent = '₱' + currentTotalAmount.toFixed(2);
      document.getElementById('b-payment-received').value = '';
      document.getElementById('b-change-display').textContent = '₱0.00';
      var warning = document.getElementById('b-payment-warning');
      if (warning) {
        warning.style.visibility = 'hidden';
      }
      var submitBtn = document.getElementById('b-submit-settle-btn');
      if (submitBtn) {
        submitBtn.removeAttribute('disabled');
        submitBtn.textContent = '✓ Settle & Complete Appointment';
      }

      // Build breakdown table
      var tbody = document.getElementById('billing-breakdown-tbody');
      var bHtml = '';
      if (a.billing_breakdown && a.billing_breakdown.items) {
        a.billing_breakdown.items.forEach(function(it) {
          bHtml += '<tr style="border-bottom: 1px solid #f3f4f6;">' +
                     '<td style="padding: 0.5rem 0.75rem;">' +
                       '<strong>' + escapeHtml(it.service_name) + '</strong>' +
                       (it.is_package ? ' <span class="badge" style="background: #e0e7ff; color: #3730a3; font-size: 0.65rem;">Package</span>' : '') +
                     '</td>' +
                     '<td style="padding: 0.5rem 0.75rem; text-align: center;">' + it.quantity + '</td>' +
                     '<td style="padding: 0.5rem 0.75rem; text-align: right;">₱' + parseFloat(it.unit_price).toFixed(2) + '</td>' +
                     '<td style="padding: 0.5rem 0.75rem; text-align: right; font-weight: 600;">₱' + parseFloat(it.subtotal).toFixed(2) + '</td>' +
                   '</tr>';
        });
      }
      tbody.innerHTML = bHtml;

      document.getElementById('billing-step-1').style.display = 'none';
      document.getElementById('billing-step-2').style.display = 'block';
    });
}

function backToBillingSelection() {
  document.getElementById('billing-step-1').style.display = 'block';
  document.getElementById('billing-step-2').style.display = 'none';
}

function calculateChange() {
  var recInput = document.getElementById('b-payment-received');
  var recVal = parseFloat(recInput ? recInput.value : 0) || 0;
  var change = recVal - currentTotalAmount;
  var warning = document.getElementById('b-payment-warning');
  var submitBtn = document.getElementById('b-submit-settle-btn');
  var changeDisplay = document.getElementById('b-change-display');

  if (change < 0) {
    if (changeDisplay) changeDisplay.textContent = '₱0.00';
    if (warning) warning.style.visibility = 'visible';
    if (submitBtn) submitBtn.setAttribute('disabled', '');
  } else {
    if (changeDisplay) changeDisplay.textContent = '₱' + change.toFixed(2);
    if (warning) warning.style.visibility = 'hidden';
    if (submitBtn) submitBtn.removeAttribute('disabled');
  }
}

function escapeHtml(str) {
  return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

// Dialog backdrop click closing and double-submission protection
document.addEventListener('DOMContentLoaded', function() {
  var dlg = document.getElementById('add-billing-modal');
  if (dlg) {
    dlg.addEventListener('cancel', function() {
      document.body.classList.remove('has-modal-open');
    });
    var mouseDownOutside = false;
    dlg.addEventListener('mousedown', function(e) {
      mouseDownOutside = (e.target === dlg);
    });
    dlg.addEventListener('click', function(e) {
      if (e.target === dlg && mouseDownOutside) {
        closeAddBillingModal();
      }
      mouseDownOutside = false;
    });
  }

  var settleForm = document.getElementById('settle-payment-form');
  if (settleForm) {
    settleForm.addEventListener('submit', function(e) {
      if (settleForm._submitting) {
        e.preventDefault();
        return false;
      }
      var submitBtn = document.getElementById('b-submit-settle-btn');
      if (submitBtn) {
        submitBtn.setAttribute('disabled', 'disabled');
        submitBtn.textContent = 'Processing Payment...';
      }
      settleForm._submitting = true;
    });
  }
});
</script>

