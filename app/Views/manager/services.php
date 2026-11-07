<?php
require __DIR__ . '/_nav.php';

$actionBase = !empty($isSuper) && !empty($unitId) ? '/manager/units/' . (int)$unitId . '/services' : '/manager/services';
$unitQuery = !empty($isSuper) && !empty($unitId) ? '?unit_id=' . (int)$unitId : '';
$q = $searchQuery ?? '';
$catFilter = $selectedCategory ?? '';
$statusFilter = $selectedStatus ?? '';

$catStyle = function(string $cat): array {
    return match($cat) {
        'Bathing & Brushing' => ['bg' => '#e0f2fe', 'color' => '#0369a1', 'border' => '#bae6fd'],
        'Styling' => ['bg' => '#f3e8ff', 'color' => '#6b21a8', 'border' => '#e9d5ff'],
        'Wellness' => ['bg' => '#ecfdf5', 'color' => '#047857', 'border' => '#a7f3d0'],
        'Specialized' => ['bg' => '#fef3c7', 'color' => '#92400e', 'border' => '#fde68a'],
        default => ['bg' => '#f3f4f6', 'color' => '#374151', 'border' => '#e5e7eb'],
    };
};
?>

<div class="page-head" style="display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; flex-wrap: wrap;">
  <div>
    <p class="eyebrow"><?= e($unitName) ?> Catalog</p>
    <h1>Grooming Services</h1>
    <p class="muted">Active and configured grooming treatments, categories, pricing rates, and duration rules.</p>
  </div>
  <div>
    <button type="button" class="btn primary" data-modal-open="create-service-modal" id="btn-create-service" style="display: inline-flex; align-items: center; gap: 0.5rem; font-weight: 600;">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <line x1="12" y1="5" x2="12" y2="19"></line>
        <line x1="5" y1="12" x2="19" y2="12"></line>
      </svg>
      Create Service
    </button>
  </div>
</div>

<!-- Services Offerings Table Card -->
<section class="card" style="margin-bottom: 1.5rem;">
  <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; flex-wrap: wrap; gap: 0.85rem;">
    <div>
      <h2 style="margin: 0; font-size: 1.15rem;">
        Service Catalog Offerings (<?= count($services) ?><?= ($catFilter !== '' || $statusFilter !== '') ? ' filtered' : '' ?>)
      </h2>
      <span class="caption">Base appointment duration is the combined sum of selected services. Unavailable services are excluded from customer bookings.</span>
    </div>

    <!-- Exactly Two Filter Controls: Service Type & Availability -->
    <form method="get" action="<?= $actionBase ?>" style="display: flex; gap: 0.75rem; align-items: center; margin: 0; flex-wrap: wrap;" id="services-filter-form">
      <?php if (!empty($isSuper)): ?>
        <input type="hidden" name="unit_id" value="<?= (int)$unitId ?>">
      <?php endif; ?>

      <div style="display: inline-flex; align-items: center; gap: 0.35rem;">
        <label for="filter-service-type" style="font-size: 0.88rem; font-weight: 600; color: var(--ink); margin: 0; white-space: nowrap;">Service Type:</label>
        <select id="filter-service-type" name="category" onchange="this.form.submit()" style="padding: 0.35rem 0.65rem; font-size: 0.88rem; border-radius: var(--radius-sm, 6px); border: 1px solid var(--line-strong); background: var(--surface); color: var(--ink);">
          <option value="" <?= $catFilter === '' ? 'selected' : '' ?>>All</option>
          <?php foreach ($categories as $cat): ?>
            <option value="<?= e($cat) ?>" <?= $catFilter === $cat ? 'selected' : '' ?>><?= e($cat) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div style="display: inline-flex; align-items: center; gap: 0.35rem;">
        <label for="filter-availability" style="font-size: 0.88rem; font-weight: 600; color: var(--ink); margin: 0; white-space: nowrap;">Availability:</label>
        <select id="filter-availability" name="status" onchange="this.form.submit()" style="padding: 0.35rem 0.65rem; font-size: 0.88rem; border-radius: var(--radius-sm, 6px); border: 1px solid var(--line-strong); background: var(--surface); color: var(--ink);">
          <option value="" <?= $statusFilter === '' ? 'selected' : '' ?>>All</option>
          <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Available</option>
          <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Unavailable</option>
        </select>
      </div>

      <?php if ($catFilter !== '' || $statusFilter !== ''): ?>
        <a href="<?= $actionBase . $unitQuery ?>" class="btn ghost btn-sm" id="btn-reset-filters" style="padding: 0.35rem 0.65rem; font-size: 0.85rem;" title="Reset all filters">Reset</a>
      <?php endif; ?>
    </form>
  </div>

  <?php if (empty($services)): ?>
    <div class="empty" style="text-align: center; padding: 2.5rem 1rem;">
      <p style="margin: 0 0 0.5rem 0; font-size: 1.05rem; font-weight: 600;">No grooming services found</p>
      <p class="muted" style="margin: 0 0 1rem 0;">No services match the selected filter criteria.</p>
      <?php if ($catFilter !== '' || $statusFilter !== ''): ?>
        <a href="<?= $actionBase . $unitQuery ?>" class="btn secondary btn-sm" style="margin-right: 0.5rem;">Clear Filters</a>
      <?php endif; ?>
      <button type="button" class="btn primary btn-sm" data-modal-open="create-service-modal">+ Create New Service</button>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="grooming-services-table">
        <thead>
          <tr>
            <th style="min-width: 140px; text-align: left;">Service Name</th>
            <th style="width: 115px; text-align: left;">Category</th>
            <th style="width: 75px; text-align: right;">Base Price</th>
            <th style="width: 85px; text-align: right;">Max Duration</th>
            <th style="width: 95px; text-align: right;">Addl. Duration Rate</th>
            <th style="width: 95px; text-align: right;">Addl. Employee Fee</th>
            <th style="width: 85px; text-align: center;">Availability</th>
            <th style="width: 145px; text-align: right;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($services as $srv): ?>
            <?php
              $cat = $srv['grooming_category'] ?: 'Bathing & Brushing';
              $cStyle = $catStyle($cat);
              $isAvail = ($srv['service_status'] === 'active');
            ?>
            <tr id="service-row-<?= (int)$srv['service_id'] ?>">
              <td>
                <strong style="display: block; font-size: 0.88rem; font-weight: 650; word-break: break-word; color: var(--ink);"><?= e($srv['service_name']) ?></strong>
              </td>
              <td>
                <span style="display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.18rem 0.5rem; border-radius: 999px; font-size: 0.74rem; font-weight: 600; background: <?= $cStyle['bg'] ?>; color: <?= $cStyle['color'] ?>; border: 1px solid <?= $cStyle['border'] ?>; white-space: nowrap;">
                  <?= e($cat) ?>
                </span>
              </td>
              <td class="tabular" style="text-align: right; font-weight: 700; color: var(--text, #1e293b); white-space: nowrap; font-size: 0.85rem;">
                <?= peso((float)$srv['base_price']) ?>
              </td>
              <td class="tabular" style="text-align: right; white-space: nowrap; font-size: 0.85rem;">
                <strong><?= (int)$srv['estimated_duration_minutes'] ?></strong> <span class="caption muted" style="font-size: 0.75rem;">mins</span>
              </td>
              <td class="tabular" style="text-align: right; white-space: nowrap; font-size: 0.85rem;">
                <?= peso((float)$srv['additional_duration_rate']) ?>
              </td>
              <td class="tabular" style="text-align: right; white-space: nowrap; font-size: 0.85rem;">
                <?= peso((float)$srv['additional_employee_fee']) ?>
              </td>
              <td style="text-align: center; white-space: nowrap;">
                <?php if ($isAvail): ?>
                  <span class="badge ok" style="padding: 0.18rem 0.5rem; font-weight: 600; font-size: 0.75rem;">Available</span>
                <?php else: ?>
                  <span class="badge" style="padding: 0.18rem 0.5rem; font-weight: 600; font-size: 0.75rem; background: #fee2e2; color: #991b1b; border: 1px solid #fecaca;">Unavailable</span>
                <?php endif; ?>
              </td>
              <td style="text-align: right; white-space: nowrap;">
                <div style="display: inline-flex; justify-content: flex-end; gap: 0.3rem; align-items: center;">
                  <button type="button" class="btn secondary btn-sm"
                          data-modal-open="edit-service-modal-<?= (int)$srv['service_id'] ?>"
                          title="Edit service settings"
                          style="padding: 0.25rem 0.5rem; font-size: 0.8rem;">
                    Edit
                  </button>
                  <?php if ($isAvail): ?>
                    <button type="button" class="btn btn-sm"
                            data-modal-open="toggle-modal-<?= (int)$srv['service_id'] ?>"
                            style="background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; padding: 0.25rem 0.5rem; font-size: 0.8rem; white-space: nowrap;"
                            title="Mark service unavailable">
                      Mark Unavailable
                    </button>
                  <?php else: ?>
                    <button type="button" class="btn btn-sm"
                            data-modal-open="toggle-modal-<?= (int)$srv['service_id'] ?>"
                            style="background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; padding: 0.25rem 0.5rem; font-size: 0.8rem; white-space: nowrap;"
                            title="Mark service available">
                      Mark Available
                    </button>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<!-- ========================================================================= -->
<!-- MODALS                                                                    -->
<!-- ========================================================================= -->

<!-- Create Service Modal -->
<dialog class="modal" id="create-service-modal" style="max-width: 620px;" <?= !empty($errors) ? 'data-open-on-load="true"' : '' ?>>
  <div class="modal-head">
    <h3 style="margin: 0;">Create Grooming Service</h3>
    <button type="button" class="btn ghost btn-sm" data-modal-close>&times;</button>
  </div>
  <form method="post" action="<?= $actionBase ?>" id="form-create-service">
    <?= \App\Core\CSRF::field() ?>
    <div class="modal-body" style="display: flex; flex-direction: column; gap: 1rem;">
      <p class="caption muted" style="margin: 0;">
        Add a new grooming treatment to the catalog. Configure its category, base pricing, duration limit, and extra rates.
      </p>

      <?php if (!empty($errors)): ?>
        <div style="background: #fef2f2; color: #991b1b; padding: 0.75rem 1rem; border-radius: 6px; border: 1px solid #fecaca; font-size: 0.875rem;">
          <strong>Please correct the following:</strong>
          <ul style="margin: 0.25rem 0 0 1.25rem; padding: 0;">
            <?php foreach ($errors as $field => $err): ?>
              <li><?= e($err) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <div class="field" style="margin: 0;">
        <label for="create-service-name" style="font-weight: 600;">Service Name <span style="color: #dc2626;">*</span></label>
        <input id="create-service-name" type="text" name="service_name" required maxlength="200"
               value="<?= e($old['service_name'] ?? '') ?>"
               placeholder="e.g. Deluxe Spa Hydrobath &amp; Scissoring" style="width: 100%;">
        <span class="caption muted">A clear, descriptive treatment name.</span>
      </div>

      <div class="field" style="margin: 0;">
        <label for="create-service-category" style="font-weight: 600;">Service Category <span style="color: #dc2626;">*</span></label>
        <select id="create-service-category" name="grooming_category" required style="width: 100%;">
          <option value="">Select a category...</option>
          <?php foreach ($categories as $cat): ?>
            <option value="<?= e($cat) ?>" <?= ($old['grooming_category'] ?? '') === $cat ? 'selected' : '' ?>>
              <?= e($cat) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <span class="caption muted">Select one of the four established grooming categories.</span>
      </div>

      <div class="field" style="margin: 0;">
        <label for="create-service-description" style="font-weight: 600;">Description</label>
        <textarea id="create-service-description" name="description" rows="3" style="width: 100%; resize: vertical;"
                  placeholder="Outline what is included in this treatment..."><?= e($old['description'] ?? '') ?></textarea>
      </div>

      <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
        <div class="field" style="margin: 0;">
          <label for="create-base-price" style="font-weight: 600;">Base Price (₱) <span style="color: #dc2626;">*</span></label>
          <input id="create-base-price" type="number" name="base_price" required min="0" step="0.01"
                 value="<?= e($old['base_price'] ?? '0.00') ?>" style="width: 100%;">
          <span class="caption muted">Standard fee for the service.</span>
        </div>

        <div class="field" style="margin: 0;">
          <label for="create-max-duration" style="font-weight: 600;">Maximum Duration (mins) <span style="color: #dc2626;">*</span></label>
          <input id="create-max-duration" type="number" name="estimated_duration_minutes" required min="1" step="1"
                 value="<?= e($old['estimated_duration_minutes'] ?? '30') ?>" style="width: 100%;">
          <span class="caption muted">Expected time ceiling in minutes.</span>
        </div>
      </div>

      <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
        <div class="field" style="margin: 0;">
          <label for="create-addl-duration-rate" style="font-weight: 600;">Additional-Duration Rate (₱) <span style="color: #dc2626;">*</span></label>
          <input id="create-addl-duration-rate" type="number" name="additional_duration_rate" required min="0" step="0.01"
                 value="<?= e($old['additional_duration_rate'] ?? '0.00') ?>" style="width: 100%;">
          <span class="caption muted">Rate charged if full appointment overruns.</span>
        </div>

        <div class="field" style="margin: 0;">
          <label for="create-addl-employee-fee" style="font-weight: 600;">Additional-Employee Fee (₱) <span style="color: #dc2626;">*</span></label>
          <input id="create-addl-employee-fee" type="number" name="additional_employee_fee" required min="0" step="0.01"
                 value="<?= e($old['additional_employee_fee'] ?? '0.00') ?>" style="width: 100%;">
          <span class="caption muted">Rate charged when an extra staff is assigned.</span>
        </div>
      </div>
    </div>
    <div class="modal-foot" style="display: flex; justify-content: flex-end; gap: 0.5rem; border-top: 1px solid var(--border, #e5e7eb); padding-top: 1rem; margin-top: 0.5rem;">
      <button type="button" class="btn ghost btn-sm" data-modal-close>Cancel</button>
      <button type="submit" class="btn primary btn-sm" id="btn-submit-create-service">Create Service</button>
    </div>
  </form>
</dialog>

<!-- Edit Service Modals & Availability Confirmation Dialogs -->
<?php foreach ($services as $srv): ?>
  <?php
    $srvId = (int)$srv['service_id'];
    $curCat = (string)($srv['grooming_category'] ?: 'Bathing & Brushing');
    $isAvail = ($srv['service_status'] === 'active');
  ?>

  <!-- Edit Service Modal -->
  <dialog class="modal" id="edit-service-modal-<?= $srvId ?>" style="max-width: 620px;">
    <div class="modal-head">
      <h3 style="margin: 0;">Edit Service: <?= e($srv['service_name']) ?></h3>
      <button type="button" class="btn ghost btn-sm" data-modal-close>&times;</button>
    </div>
    <form method="post" action="<?= $actionBase ?>/<?= $srvId ?>/update" id="form-edit-service-<?= $srvId ?>">
      <?= \App\Core\CSRF::field() ?>
      <div class="modal-body" style="display: flex; flex-direction: column; gap: 1rem;">
        <div style="background: #eff6ff; border-left: 3px solid #3b82f6; padding: 0.65rem 0.85rem; border-radius: 4px; font-size: 0.85rem; color: #1e40af;">
          <strong>Snapshot Policy:</strong> Changes apply to future bookings only. Existing confirmed appointments retain their original pricing and duration snapshots.
        </div>

        <div class="field" style="margin: 0;">
          <label for="edit-name-<?= $srvId ?>" style="font-weight: 600;">Service Name <span style="color: #dc2626;">*</span></label>
          <input id="edit-name-<?= $srvId ?>" type="text" name="service_name" required maxlength="200"
                 value="<?= e($srv['service_name']) ?>" style="width: 100%;">
        </div>

        <div class="field" style="margin: 0;">
          <label for="edit-cat-<?= $srvId ?>" style="font-weight: 600;">Service Category <span style="color: #dc2626;">*</span></label>
          <select id="edit-cat-<?= $srvId ?>" name="grooming_category" required style="width: 100%;">
            <?php foreach ($categories as $cat): ?>
              <option value="<?= e($cat) ?>" <?= $curCat === $cat ? 'selected' : '' ?>>
                <?= e($cat) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="field" style="margin: 0;">
          <label for="edit-desc-<?= $srvId ?>" style="font-weight: 600;">Description</label>
          <textarea id="edit-desc-<?= $srvId ?>" name="description" rows="3" style="width: 100%; resize: vertical;"><?= e($srv['description'] ?: '') ?></textarea>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
          <div class="field" style="margin: 0;">
            <label for="edit-price-<?= $srvId ?>" style="font-weight: 600;">Base Price (₱) <span style="color: #dc2626;">*</span></label>
            <input id="edit-price-<?= $srvId ?>" type="number" name="base_price" required min="0" step="0.01"
                   value="<?= number_format((float)$srv['base_price'], 2, '.', '') ?>" style="width: 100%;">
          </div>

          <div class="field" style="margin: 0;">
            <label for="edit-dur-<?= $srvId ?>" style="font-weight: 600;">Maximum Duration (mins) <span style="color: #dc2626;">*</span></label>
            <input id="edit-dur-<?= $srvId ?>" type="number" name="estimated_duration_minutes" required min="1" step="1"
                   value="<?= (int)$srv['estimated_duration_minutes'] ?>" style="width: 100%;">
          </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
          <div class="field" style="margin: 0;">
            <label for="edit-rate-<?= $srvId ?>" style="font-weight: 600;">Additional-Duration Rate (₱) <span style="color: #dc2626;">*</span></label>
            <input id="edit-rate-<?= $srvId ?>" type="number" name="additional_duration_rate" required min="0" step="0.01"
                   value="<?= number_format((float)$srv['additional_duration_rate'], 2, '.', '') ?>" style="width: 100%;">
          </div>

          <div class="field" style="margin: 0;">
            <label for="edit-fee-<?= $srvId ?>" style="font-weight: 600;">Additional-Employee Fee (₱) <span style="color: #dc2626;">*</span></label>
            <input id="edit-fee-<?= $srvId ?>" type="number" name="additional_employee_fee" required min="0" step="0.01"
                   value="<?= number_format((float)$srv['additional_employee_fee'], 2, '.', '') ?>" style="width: 100%;">
          </div>
        </div>
      </div>
      <div class="modal-foot" style="display: flex; justify-content: flex-end; gap: 0.5rem; border-top: 1px solid var(--border, #e5e7eb); padding-top: 1rem; margin-top: 0.5rem;">
        <button type="button" class="btn ghost btn-sm" data-modal-close>Cancel</button>
        <button type="submit" class="btn primary btn-sm">Save Changes</button>
      </div>
    </form>
  </dialog>

  <!-- Availability Confirmation Dialog -->
  <dialog class="modal" id="toggle-modal-<?= $srvId ?>" style="max-width: 500px;">
    <div class="modal-head">
      <h3 style="margin: 0; color: <?= $isAvail ? '#991b1b' : '#065f46' ?>;">
        <?= $isAvail ? 'Mark Service as Unavailable' : 'Mark Service as Available' ?>
      </h3>
      <button type="button" class="btn ghost btn-sm" data-modal-close>&times;</button>
    </div>
    <form method="post" action="<?= $actionBase ?>/<?= $srvId ?>/status" id="form-toggle-status-<?= $srvId ?>">
      <?= \App\Core\CSRF::field() ?>
      <input type="hidden" name="status" value="<?= $isAvail ? 'inactive' : 'active' ?>">
      <div class="modal-body">
        <?php if ($isAvail): ?>
          <p style="margin: 0 0 1rem 0; font-size: 0.95rem;">
            Are you sure you want to mark <strong><?= e($srv['service_name']) ?></strong> as <strong>Unavailable</strong>?
          </p>
          <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 6px; padding: 0.85rem; font-size: 0.85rem; color: #991b1b; line-height: 1.45;">
            <strong>Operational Impact:</strong>
            <ul style="margin: 0.35rem 0 0 1.2rem; padding: 0;">
              <li>Customers will no longer be able to select this service for new appointment bookings.</li>
              <li>Existing confirmed appointments and historical appointment records will remain completely intact.</li>
            </ul>
          </div>
        <?php else: ?>
          <p style="margin: 0 0 1rem 0; font-size: 0.95rem;">
            Are you sure you want to mark <strong><?= e($srv['service_name']) ?></strong> as <strong>Available</strong>?
          </p>
          <div style="background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 6px; padding: 0.85rem; font-size: 0.85rem; color: #065f46; line-height: 1.45;">
            <strong>Operational Impact:</strong>
            <ul style="margin: 0.35rem 0 0 1.2rem; padding: 0;">
              <li>This service will immediately be made available for selection in customer appointment bookings.</li>
            </ul>
          </div>
        <?php endif; ?>
      </div>
      <div class="modal-foot" style="display: flex; justify-content: flex-end; gap: 0.5rem; border-top: 1px solid var(--border, #e5e7eb); padding-top: 1rem;">
        <button type="button" class="btn ghost btn-sm" data-modal-close>Cancel</button>
        <?php if ($isAvail): ?>
          <button type="submit" class="btn btn-sm" style="background: #dc2626; color: white; border-color: #dc2626;">
            Confirm (Mark Unavailable)
          </button>
        <?php else: ?>
          <button type="submit" class="btn primary btn-sm">
            Confirm (Mark Available)
          </button>
        <?php endif; ?>
      </div>
    </form>
  </dialog>
<?php endforeach; ?>
