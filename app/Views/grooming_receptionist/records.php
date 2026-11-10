<?php
$title = $title ?? 'Records · Grooming Services Receptionist';
$recList = $records ?? [];
$curFilter = $statusFilter ?? 'all';
?>

<div class="page-head" style="margin-bottom: 1.5rem;">
  <div>
    <p class="eyebrow">Grooming Services Workspace</p>
    <h1>Records</h1>
    <p class="muted">Read-only transaction log and comprehensive history of grooming sessions and payment records.</p>
  </div>
</div>

<!-- Status Filters -->
<div class="card" style="margin-bottom: 1.5rem; padding: 1rem 1.25rem;">
  <div style="display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap;">
    <span class="caption" style="font-weight: 600; text-transform: uppercase;">Filter Status:</span>
    
    <a href="/grooming/receptionist/records?status=all"
       class="btn <?= $curFilter === 'all' ? 'primary' : 'ghost' ?> btn-sm">
      All (<?= count($recList) ?>)
    </a>

    <a href="/grooming/receptionist/records?status=pending_payment"
       class="btn <?= ($curFilter === 'pending_payment' || $curFilter === 'pending payment') ? 'primary' : 'ghost' ?> btn-sm">
      Pending Payment
    </a>

    <a href="/grooming/receptionist/records?status=completed"
       class="btn <?= $curFilter === 'completed' ? 'primary' : 'ghost' ?> btn-sm">
      Completed
    </a>
  </div>
</div>

<!-- Records Table (Read-Only) -->
<section class="card" style="padding: 1.5rem;">
  <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 1rem;">
    <div>
      <h2 style="margin: 0; font-size: 1.25rem;">Grooming Services Session Log</h2>
      <p class="caption muted" style="margin: 0.2rem 0 0 0;">
        Showing sessions awaiting payment and completed historical visits. Click any row to view full read-only details.
      </p>
    </div>
    <span class="caption muted"><?= count($recList) ?> records</span>
  </div>

  <?php if (empty($recList)): ?>
    <div class="empty" style="padding: 2.5rem 1rem; text-align: center;">
      <div style="font-size: 2.25rem; margin-bottom: 0.5rem;" aria-hidden="true">📋</div>
      <h3 style="margin: 0 0 0.35rem 0; font-size: 1.1rem; color: var(--color-text, #111827);">No Records Found</h3>
      <p class="muted" style="margin: 0; font-size: 0.9rem;">There are no grooming appointments matching the selected status filter.</p>
    </div>
  <?php else: ?>
    <div style="overflow-x: auto; width: 100%;">
      <table style="width: 100%; border-collapse: collapse;">
        <thead>
          <tr style="border-bottom: 1px solid var(--color-border, #e5e7eb); text-align: left; background: var(--color-bg-subtle, #f9fafb);">
            <th style="padding: 0.65rem 1rem; font-size: 0.75rem; font-weight: 600; text-transform: uppercase;">Appt #</th>
            <th style="padding: 0.65rem 1rem; font-size: 0.75rem; font-weight: 600; text-transform: uppercase;">Booking Date</th>
            <th style="padding: 0.65rem 1rem; font-size: 0.75rem; font-weight: 600; text-transform: uppercase;">Appointment Schedule</th>
            <th style="padding: 0.65rem 1rem; font-size: 0.75rem; font-weight: 600; text-transform: uppercase;">Customer</th>
            <th style="padding: 0.65rem 1rem; font-size: 0.75rem; font-weight: 600; text-transform: uppercase;">Pets</th>
            <th style="padding: 0.65rem 1rem; font-size: 0.75rem; font-weight: 600; text-transform: uppercase;">Services</th>
            <th style="padding: 0.65rem 1rem; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; text-align: right;">Total Cost</th>
            <th style="padding: 0.65rem 1rem; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; text-align: center;">Status</th>
            <th style="padding: 0.65rem 1rem; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; text-align: right;">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recList as $r): ?>
            <?php
            $isPaid = ($r['invoice_status'] ?? '') === 'paid';
            $isPendingPay = !empty($r['is_pending_payment']);
            $stText = $isPaid ? 'Completed' : ($isPendingPay ? 'Pending Payment' : $r['display_status']);
            $stBadge = $isPaid ? 'badge-success' : ($isPendingPay ? 'badge-warning' : $r['status_badge_class']);
            ?>
            <tr style="border-bottom: 1px solid var(--color-border, #f3f4f6); cursor: pointer;" onclick="openRecordDetailModal(<?= (int)$r['appointment_id'] ?>)" onmouseover="this.style.background='var(--color-bg-subtle, #f9fafb)'" onmouseout="this.style.background='transparent'">
              <td style="padding: 0.75rem 1rem; font-weight: 700; color: var(--brand, #0e8a75);">
                #<?= (int)$r['appointment_id'] ?>
              </td>
              <td style="padding: 0.75rem 1rem; font-size: 0.85rem; color: var(--color-text, #4b5563);">
                <?= !empty($r['created_at']) ? date('M j, Y', strtotime((string)$r['created_at'])) : date('M j, Y', strtotime((string)$r['preferred_date'])) ?>
              </td>
              <td style="padding: 0.75rem 1rem;">
                <div style="font-weight: 600; color: var(--color-text, #111827);">
                  <?= e($r['formatted_date']) ?>
                </div>
                <div class="caption muted">🕒 <?= e($r['time_coverage']) ?></div>
              </td>
              <td style="padding: 0.75rem 1rem;">
                <div style="font-weight: 600; color: var(--color-text, #111827);">
                  <?= e($r['customer_display_name']) ?>
                  <?php if (!empty($r['customer_id_display'])): ?>
                    <span class="caption muted">(#<?= (int)$r['customer_id_display'] ?>)</span>
                  <?php else: ?>
                    <span class="badge" style="font-size: 0.65rem; background: #e0f2fe; color: #0369a1;">Guest</span>
                  <?php endif; ?>
                </div>
              </td>
              <td style="padding: 0.75rem 1rem; font-size: 0.85rem; color: var(--color-text, #374151);">
                🐾 <?= e($r['pets_summary']) ?>
              </td>
              <td style="padding: 0.75rem 1rem; font-size: 0.85rem; color: var(--color-text, #4b5563);">
                <?= e($r['services_summary']) ?>
              </td>
              <td style="padding: 0.75rem 1rem; text-align: right; font-weight: 700; color: #0e8a75; font-size: 0.95rem;">
                ₱<?= number_format((float)($r['total_amount'] ?? 0), 2) ?>
              </td>
              <td style="padding: 0.75rem 1rem; text-align: center;">
                <span class="badge <?= $stBadge ?>">
                  <?= e($stText) ?>
                </span>
              </td>
              <td style="padding: 0.75rem 1rem; text-align: right;">
                <button type="button" class="btn ghost btn-sm" onclick="event.stopPropagation(); openRecordDetailModal(<?= (int)$r['appointment_id'] ?>)">
                  Details &rarr;
                </button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<!-- ========================================================================= -->
<!-- MODAL: READ-ONLY DETAIL VIEW                                              -->
<!-- ========================================================================= -->
<div id="record-detail-modal" class="modal-backdrop" hidden style="position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 9999; display: none; align-items: center; justify-content: center; padding: 1rem;">
  <div class="card" style="width: 100%; max-width: 660px; max-height: 90vh; overflow-y: auto; padding: 1.75rem; background: #fff; border-radius: 12px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.2);">
    
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; border-bottom: 1px solid var(--color-border, #e5e7eb); padding-bottom: 0.75rem;">
      <div>
        <h2 style="margin: 0; font-size: 1.25rem;" id="rd-modal-title">Grooming Session #---</h2>
        <p class="caption muted" style="margin: 0.2rem 0 0 0;">Comprehensive read-only appointment and billing record.</p>
      </div>
      <button type="button" class="btn ghost btn-sm" onclick="closeRecordDetailModal()" style="font-size: 1.25rem; line-height: 1; padding: 0.25rem 0.5rem;">✕</button>
    </div>

    <!-- Section 1: Appointment Information -->
    <div style="background: var(--color-bg-subtle, #f9fafb); padding: 1rem; border-radius: 8px; border: 1px solid var(--color-border, #e5e7eb); margin-bottom: 1rem;">
      <h3 style="margin: 0 0 0.5rem 0; font-size: 0.95rem; text-transform: uppercase; color: var(--color-muted, #6b7280);">Appointment Information</h3>
      <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; font-size: 0.85rem;">
        <div><strong>Appointment ID:</strong> <span id="rd-appt-id">#---</span></div>
        <div><strong>Status:</strong> <span id="rd-status" class="badge">---</span></div>
        <div><strong>Booking Date:</strong> <span id="rd-booking-date">---</span></div>
        <div><strong>Scheduled Time:</strong> <span id="rd-sched-time">---</span></div>
        <div><strong>Customer Name:</strong> <span id="rd-customer-name">---</span></div>
        <div><strong>Contact Phone:</strong> <span id="rd-customer-phone">---</span></div>
        <div><strong>Completion Time:</strong> <span id="rd-completion-time">---</span></div>
        <div><strong>Booked By Receptionist:</strong> <span id="rd-booked-by">Staff</span></div>
      </div>
    </div>

    <!-- Section 2: Pets Information -->
    <div style="background: var(--color-bg-subtle, #f9fafb); padding: 1rem; border-radius: 8px; border: 1px solid var(--color-border, #e5e7eb); margin-bottom: 1rem;">
      <h3 style="margin: 0 0 0.5rem 0; font-size: 0.95rem; text-transform: uppercase; color: var(--color-muted, #6b7280);">Registered Pets</h3>
      <div id="rd-pets-container" style="display: flex; flex-direction: column; gap: 0.4rem; font-size: 0.85rem;">
        <!-- Injected via JS -->
      </div>
    </div>

    <!-- Section 3: Services Performed -->
    <div style="background: var(--color-bg-subtle, #f9fafb); padding: 1rem; border-radius: 8px; border: 1px solid var(--color-border, #e5e7eb); margin-bottom: 1rem;">
      <h3 style="margin: 0 0 0.5rem 0; font-size: 0.95rem; text-transform: uppercase; color: var(--color-muted, #6b7280);">Services &amp; Packages</h3>
      <div id="rd-services-container" style="display: flex; flex-direction: column; gap: 0.35rem; font-size: 0.85rem;">
        <!-- Injected via JS -->
      </div>
    </div>

    <!-- Section 4: Billing & Payment Information -->
    <div style="background: #fdfefe; border: 1.5px solid #d1fae5; border-radius: 8px; padding: 1rem; margin-bottom: 1rem;">
      <h3 style="margin: 0 0 0.5rem 0; font-size: 0.95rem; text-transform: uppercase; color: #065f46;">Billing &amp; Payment Settlement</h3>
      <div style="border: 1px solid #e5e7eb; border-radius: 6px; overflow: hidden; margin-bottom: 0.75rem;">
        <table style="width: 100%; border-collapse: collapse; font-size: 0.8rem;">
          <thead>
            <tr style="background: #f9fafb; border-bottom: 1px solid #e5e7eb; text-align: left;">
              <th style="padding: 0.4rem 0.6rem;">Item</th>
              <th style="padding: 0.4rem 0.6rem; text-align: center;">Qty</th>
              <th style="padding: 0.4rem 0.6rem; text-align: right;">Price</th>
              <th style="padding: 0.4rem 0.6rem; text-align: right;">Subtotal</th>
            </tr>
          </thead>
          <tbody id="rd-breakdown-tbody">
            <!-- Breakdown items -->
          </tbody>
        </table>
      </div>

      <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; font-size: 0.85rem;">
        <div><strong>Total Cost:</strong> <span style="font-weight: 700; color: #0e8a75;" id="rd-total-cost">₱0.00</span></div>
        <div><strong>Payment Method:</strong> <span style="text-transform: uppercase; font-weight: 600;" id="rd-payment-method">Cash</span></div>
        <div><strong>Payment Received:</strong> <span id="rd-payment-received">₱0.00</span></div>
        <div><strong>Change Given:</strong> <span id="rd-change">₱0.00</span></div>
      </div>
    </div>

    <!-- Section 5: Staff Information -->
    <div style="background: var(--color-bg-subtle, #f9fafb); padding: 0.75rem 1rem; border-radius: 8px; border: 1px solid var(--color-border, #e5e7eb); margin-bottom: 1.25rem; font-size: 0.85rem;">
      <h3 style="margin: 0 0 0.35rem 0; font-size: 0.85rem; text-transform: uppercase; color: var(--color-muted, #6b7280);">Staff Processing</h3>
      <div style="display: flex; justify-content: space-between; flex-wrap: wrap; gap: 0.5rem;">
        <div><strong>Assigned Groomer:</strong> <span id="rd-groomer-staff">Owen Bennett (Master Stylist)</span></div>
        <div><strong>Billing Processed By:</strong> <span id="rd-billing-staff">Receptionist</span></div>
      </div>
    </div>

    <div style="display: flex; justify-content: flex-end;">
      <button type="button" class="btn ghost btn-sm" onclick="closeRecordDetailModal()">Close</button>
    </div>
  </div>
</div>

<script>
function openRecordDetailModal(id) {
  fetch('/grooming/receptionist/records/' + id)
    .then(function(res) { return res.json(); })
    .then(function(data) {
      if (!data.ok || !data.record) {
        alert(data.error || 'Failed to load record details.');
        return;
      }
      var r = data.record;
      document.getElementById('rd-modal-title').textContent = 'Grooming Session #' + r.appointment_id;
      document.getElementById('rd-appt-id').textContent = '#' + r.appointment_id;
      
      var isPaid = r.invoice_status === 'paid';
      var isPendingPay = r.is_pending_payment;
      var stLabel = isPaid ? 'Completed' : (isPendingPay ? 'Pending Payment' : r.display_status);
      var stClass = isPaid ? 'badge-success' : (isPendingPay ? 'badge-warning' : r.status_badge_class);
      var stEl = document.getElementById('rd-status');
      stEl.textContent = stLabel;
      stEl.className = 'badge ' + stClass;

      document.getElementById('rd-booking-date').textContent = r.created_at ? r.created_at.substring(0, 10) : r.preferred_date;
      document.getElementById('rd-sched-time').textContent = r.formatted_date + ' (' + r.time_coverage + ')';
      document.getElementById('rd-customer-name').textContent = r.customer_display_name + (r.customer_id_display ? ' (#' + r.customer_id_display + ')' : ' [Guest]');
      document.getElementById('rd-customer-phone').textContent = r.customer_phone || '—';
      document.getElementById('rd-completion-time').textContent = r.appointment_end || '—';
      document.getElementById('rd-groomer-staff').textContent = r.assigned_employee_display || 'Staff';

      // Pets rendering
      var petsContainer = document.getElementById('rd-pets-container');
      var pHtml = '';
      if (r.pet_items && r.pet_items.length > 0) {
        r.pet_items.forEach(function(p) {
          pHtml += '<div>🐾 <strong>' + escapeHtml(p.name) + '</strong> (' + escapeHtml(p.species) + (p.breed ? ' - ' + escapeHtml(p.breed) : '') + ')</div>';
        });
      } else {
        pHtml = '<div>🐾 ' + escapeHtml(r.pets_summary || 'Standard Grooming Pet') + '</div>';
      }
      petsContainer.innerHTML = pHtml;

      // Services rendering
      var svcsContainer = document.getElementById('rd-services-container');
      var sHtml = '';
      if (r.service_list && r.service_list.length > 0) {
        r.service_list.forEach(function(s) {
          sHtml += '<div>✓ ' + escapeHtml(s) + '</div>';
        });
      } else {
        sHtml = '<div>' + escapeHtml(r.services_summary || 'Standard Grooming') + '</div>';
      }
      svcsContainer.innerHTML = sHtml;

      // Billing Breakdown table
      var bTbody = document.getElementById('rd-breakdown-tbody');
      var bHtml = '';
      if (r.billing_breakdown && r.billing_breakdown.items) {
        r.billing_breakdown.items.forEach(function(it) {
          bHtml += '<tr style="border-bottom: 1px solid #f3f4f6;">' +
                     '<td style="padding: 0.35rem 0.6rem;">' + escapeHtml(it.service_name) + '</td>' +
                     '<td style="padding: 0.35rem 0.6rem; text-align: center;">' + it.quantity + '</td>' +
                     '<td style="padding: 0.35rem 0.6rem; text-align: right;">₱' + parseFloat(it.unit_price).toFixed(2) + '</td>' +
                     '<td style="padding: 0.35rem 0.6rem; text-align: right; font-weight: 600;">₱' + parseFloat(it.subtotal).toFixed(2) + '</td>' +
                   '</tr>';
        });
      }
      bTbody.innerHTML = bHtml;

      document.getElementById('rd-total-cost').textContent = '₱' + parseFloat(r.total_amount || 0).toFixed(2);
      document.getElementById('rd-payment-method').textContent = r.payment_method || (isPaid ? 'Cash' : 'Unpaid');
      document.getElementById('rd-payment-received').textContent = r.payment_amount ? '₱' + parseFloat(r.payment_amount).toFixed(2) : (isPaid ? '₱' + parseFloat(r.total_amount || 0).toFixed(2) : '—');
      document.getElementById('rd-change').textContent = isPaid ? '₱0.00' : '—';
      document.getElementById('rd-billing-staff').textContent = 'Grooming Receptionist';

      document.getElementById('record-detail-modal').style.display = 'flex';
      document.getElementById('record-detail-modal').removeAttribute('hidden');
    });
}

function closeRecordDetailModal() {
  document.getElementById('record-detail-modal').style.display = 'none';
  document.getElementById('record-detail-modal').setAttribute('hidden', '');
}

function escapeHtml(str) {
  return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
</script>

