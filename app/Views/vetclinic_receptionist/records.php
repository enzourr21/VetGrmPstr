<?php
$title = $title ?? 'Records · Veterinary Clinic Receptionist';
$recList = $records ?? [];
$curFilter = $currentFilter ?? 'all';
$q = $searchQuery ?? '';
?>

<div class="page-head" style="margin-bottom: 1.5rem;">
  <div>
    <p class="eyebrow">Veterinary Clinic Workspace</p>
    <h1>Records</h1>
    <p class="muted">Read-only administrative logs and payment settlement history for Veterinary Clinic appointments.</p>
  </div>
</div>

<!-- Status Filters & Search Bar -->
<div class="card" style="margin-bottom: 1.5rem; padding: 1rem 1.25rem;">
  <form method="get" action="/vetclinic/receptionist/records" style="display: flex; gap: 1rem; align-items: center; flex-wrap: wrap;">
    <div style="display: flex; gap: 0.5rem; align-items: center;">
      <span class="caption" style="font-weight: 600; text-transform: uppercase;">Status:</span>
      
      <a href="/vetclinic/receptionist/records?status=all<?= $q !== '' ? '&q=' . urlencode($q) : '' ?>"
         class="btn <?= $curFilter === 'all' ? 'primary' : 'ghost' ?> btn-sm">
        All
      </a>

      <a href="/vetclinic/receptionist/records?status=pending_payment<?= $q !== '' ? '&q=' . urlencode($q) : '' ?>"
         class="btn <?= ($curFilter === 'pending_payment' || $curFilter === 'pending payment') ? 'primary' : 'ghost' ?> btn-sm">
        Pending Payment
      </a>

      <a href="/vetclinic/receptionist/records?status=completed<?= $q !== '' ? '&q=' . urlencode($q) : '' ?>"
         class="btn <?= $curFilter === 'completed' ? 'primary' : 'ghost' ?> btn-sm">
        Completed
      </a>
    </div>

    <div style="flex: 1; min-width: 200px; display: flex; gap: 0.5rem; margin-left: auto;">
      <input type="hidden" name="status" value="<?= e($curFilter) ?>">
      <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search Appt #, Customer, or Pet..." style="width: 100%;">
      <button type="submit" class="btn secondary btn-sm">Search</button>
      <?php if ($q !== ''): ?>
        <a href="/vetclinic/receptionist/records?status=<?= e($curFilter) ?>" class="btn ghost btn-sm">Reset</a>
      <?php endif; ?>
    </div>
  </form>
</div>

<!-- Records Table (Read-Only) -->
<section class="card" style="padding: 1.5rem;">
  <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 1rem;">
    <div>
      <h2 style="margin: 0; font-size: 1.25rem;">Veterinary Clinic Consultation Log</h2>
      <p class="caption muted" style="margin: 0.2rem 0 0 0;">
        Showing sessions awaiting payment and completed historical visits. Administrative and billing records only.
      </p>
    </div>
    <span class="caption muted"><?= count($recList) ?> records</span>
  </div>

  <?php if (empty($recList)): ?>
    <div class="empty" style="padding: 2.5rem 1rem; text-align: center;">
      <div style="font-size: 2.25rem; margin-bottom: 0.5rem;" aria-hidden="true">📋</div>
      <h3 style="margin: 0 0 0.35rem 0; font-size: 1.1rem; color: var(--color-text, #111827);">No Records Found</h3>
      <p class="muted" style="margin: 0; font-size: 0.9rem;">There are no clinic appointments matching the selected status filter.</p>
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
            <th style="padding: 0.65rem 1rem; font-size: 0.75rem; font-weight: 600; text-transform: uppercase;">Medical Services</th>
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
<!-- MODAL: READ-ONLY DETAIL VIEW (Administrative & Billing Data Only)         -->
<!-- ========================================================================= -->
<dialog class="modal modal-wide" id="record-detail-modal" aria-labelledby="rd-modal-title">
  <div class="card" style="width: 100%; max-width: 660px; max-height: 90vh; overflow-y: auto; padding: 1.75rem; border: none; box-shadow: none;">
    
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; border-bottom: 1px solid var(--color-border, #e5e7eb); padding-bottom: 0.75rem;">
      <div>
        <h2 style="margin: 0; font-size: 1.25rem;" id="rd-modal-title">Consultation Record #---</h2>
        <p class="caption muted" style="margin: 0.2rem 0 0 0;">Comprehensive administrative appointment and payment record.</p>
      </div>
      <button type="button" class="btn ghost btn-sm" onclick="closeRecordDetailModal()" style="font-size: 1.25rem; line-height: 1; padding: 0.25rem 0.5rem;" aria-label="Close modal">✕</button>
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
        <div><strong>Booked By:</strong> <span id="rd-booked-by">Staff</span></div>
      </div>
    </div>

    <!-- Section 2: Patient Pets Information -->
    <div style="background: var(--color-bg-subtle, #f9fafb); padding: 1rem; border-radius: 8px; border: 1px solid var(--color-border, #e5e7eb); margin-bottom: 1rem;">
      <h3 style="margin: 0 0 0.5rem 0; font-size: 0.95rem; text-transform: uppercase; color: var(--color-muted, #6b7280);">Patient Information</h3>
      <div id="rd-pets-container" style="display: flex; flex-direction: column; gap: 0.4rem; font-size: 0.85rem;">
        <!-- Injected via JS -->
      </div>
    </div>

    <!-- Section 3: Medical Services -->
    <div style="background: var(--color-bg-subtle, #f9fafb); padding: 1rem; border-radius: 8px; border: 1px solid var(--color-border, #e5e7eb); margin-bottom: 1rem;">
      <h3 style="margin: 0 0 0.5rem 0; font-size: 0.95rem; text-transform: uppercase; color: var(--color-muted, #6b7280);">Medical Services</h3>
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
              <th style="padding: 0.4rem 0.6rem;">Medical Service</th>
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
        <div style="grid-column: span 2;"><strong>Processed By Receptionist:</strong> <span id="rd-billed-by">—</span></div>
      </div>
    </div>

    <div style="display: flex; justify-content: flex-end;">
      <button type="button" class="btn primary btn-sm" onclick="closeRecordDetailModal()">Close</button>
    </div>
  </div>
</dialog>

<script>
function openRecordDetailModal(apptId) {
  var dlg = document.getElementById('record-detail-modal');
  if (!dlg) return;

  fetch('/vetclinic/receptionist/records/' + apptId)
    .then(function(res) { return res.json(); })
    .then(function(data) {
      if (!data.ok || !data.record) {
        alert(data.error || 'Failed to load record details.');
        return;
      }
      populateRecordModal(data.record);
      document.body.classList.add('has-modal-open');
      if (typeof dlg.showModal === 'function') {
        dlg.showModal();
      } else {
        dlg.setAttribute('open', '');
      }
    })
    .catch(function(err) {
      alert('Error fetching record.');
    });
}

function closeRecordDetailModal() {
  var dlg = document.getElementById('record-detail-modal');
  if (!dlg) return;
  if (typeof dlg.close === 'function') {
    dlg.close();
  } else {
    dlg.removeAttribute('open');
  }
  document.body.classList.remove('has-modal-open');
}

function populateRecordModal(rec) {
  document.getElementById('rd-modal-title').textContent = 'Consultation Record #' + rec.appointment_id;
  document.getElementById('rd-appt-id').textContent = '#' + rec.appointment_id;

  var stSpan = document.getElementById('rd-status');
  stSpan.textContent = rec.display_status || rec.status_name;
  stSpan.className = 'badge ' + (rec.status_badge_class || 'badge-secondary');

  document.getElementById('rd-booking-date').textContent = rec.admin_meta ? rec.admin_meta.booking_date : rec.formatted_date;
  document.getElementById('rd-sched-time').textContent = (rec.formatted_date || '') + ' (' + (rec.time_coverage || '') + ')';
  document.getElementById('rd-customer-name').textContent = rec.customer_display_name + (rec.customer_id_display ? ' (#' + rec.customer_id_display + ')' : '');
  document.getElementById('rd-customer-phone').textContent = rec.customer_phone || '—';
  document.getElementById('rd-completion-time').textContent = rec.admin_meta ? rec.admin_meta.completion_date : '—';
  document.getElementById('rd-booked-by').textContent = rec.admin_meta ? rec.admin_meta.booked_by : 'Staff';
  document.getElementById('rd-billed-by').textContent = rec.admin_meta ? rec.admin_meta.billed_by : '—';

  // Pets
  var petsBox = document.getElementById('rd-pets-container');
  petsBox.innerHTML = '';
  if (rec.pets && rec.pets.length > 0) {
    rec.pets.forEach(function(pt) {
      var d = document.createElement('div');
      d.innerHTML = '🐾 <strong>' + escapeHtml(pt.name) + '</strong> — ' + escapeHtml(pt.breed || pt.species);
      petsBox.appendChild(d);
    });
  } else {
    petsBox.textContent = 'No patient pet details recorded.';
  }

  // Services
  var svcsBox = document.getElementById('rd-services-container');
  svcsBox.innerHTML = '';
  if (rec.service_list && rec.service_list.length > 0) {
    rec.service_list.forEach(function(s) {
      var d = document.createElement('div');
      d.innerHTML = '✓ ' + escapeHtml(s);
      svcsBox.appendChild(d);
    });
  } else {
    svcsBox.textContent = 'General Medical Examination';
  }

  // Breakdown
  var tbody = document.getElementById('rd-breakdown-tbody');
  tbody.innerHTML = '';
  var bd = rec.billing_breakdown || { items: [], total: 0 };
  if (bd.items && bd.items.length > 0) {
    bd.items.forEach(function(it) {
      var tr = document.createElement('tr');
      tr.style.borderBottom = '1px solid #f3f4f6';
      tr.innerHTML = '<td style="padding: 0.4rem 0.6rem;">' + escapeHtml(it.service_name) + '</td>' +
        '<td style="padding: 0.4rem 0.6rem; text-align: center;">' + it.quantity + '</td>' +
        '<td style="padding: 0.4rem 0.6rem; text-align: right;">₱' + parseFloat(it.unit_price).toFixed(2) + '</td>' +
        '<td style="padding: 0.4rem 0.6rem; text-align: right; font-weight: 600;">₱' + parseFloat(it.subtotal).toFixed(2) + '</td>';
      tbody.appendChild(tr);
    });
  } else {
    tbody.innerHTML = '<tr><td colspan="4" style="padding: 0.5rem; text-align: center; color: #9ca3af;">Standard Consultation Fee</td></tr>';
  }

  document.getElementById('rd-total-cost').textContent = '₱' + parseFloat(rec.total_amount || 0).toFixed(2);
  document.getElementById('rd-payment-method').textContent = rec.admin_meta ? rec.admin_meta.payment_method : '—';
  document.getElementById('rd-payment-received').textContent = '₱' + (rec.admin_meta ? parseFloat(rec.admin_meta.payment_received).toFixed(2) : '0.00');
  document.getElementById('rd-change').textContent = '₱' + (rec.admin_meta ? parseFloat(rec.admin_meta.change).toFixed(2) : '0.00');
}

function escapeHtml(str) {
  if (!str) return '';
  return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
</script>

