<?php
require __DIR__ . '/_nav.php';

$actionBase = !empty($isSuper) && !empty($unitId) ? '/manager/units/' . (int)$unitId . '/services' : '/manager/services';
$unitQuery = !empty($isSuper) && !empty($unitId) ? '?unit_id=' . (int)$unitId : '';
$q = $searchQuery ?? '';
$statusFilter = $selectedStatus ?? '';
?>

<div class="page-head" style="display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; flex-wrap: wrap;">
  <div>
    <p class="eyebrow"><?= e($unitName) ?> Catalog</p>
    <h1>Medical Services</h1>
    <p class="muted">Active clinical consultations, preventive vaccinations, diagnostic panels, and animal-health service offerings.</p>
  </div>
  <div>
    <button type="button" class="btn primary" data-modal-open="create-service-modal" id="btn-create-service" style="display: inline-flex; align-items: center; gap: 0.5rem; font-weight: 600;">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <line x1="12" y1="5" x2="12" y2="19"></line>
        <line x1="5" y1="12" x2="19" y2="12"></line>
      </svg>
      Add Medical Service
    </button>
  </div>
</div>

<!-- Medical Services Offerings Table Card -->
<section class="card" style="margin-bottom: 1.5rem;">
  <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; flex-wrap: wrap; gap: 0.85rem;">
    <div>
      <h2 style="margin: 0; font-size: 1.15rem;">
        Medical Services (<?= count($services) ?><?= ($statusFilter !== '' || $q !== '') ? ' filtered' : '' ?>)
      </h2>
      <span class="caption">Veterinary Clinic service catalog. Medical appointments apply standard examination and clinical consultation pricing.</span>
    </div>

    <!-- Filter & Search Controls -->
    <form method="get" action="<?= $actionBase ?>" style="display: flex; gap: 0.75rem; align-items: center; margin: 0; flex-wrap: wrap;" id="services-filter-form">
      <?php if (!empty($isSuper)): ?>
        <input type="hidden" name="unit_id" value="<?= (int)$unitId ?>">
      <?php endif; ?>

      <div class="search-input-wrap" style="width: 220px;">
        <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <circle cx="11" cy="11" r="8"></circle>
          <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
        </svg>
        <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search service name... (Enter)" class="search-input" style="height: 34px;">
      </div>

      <div style="display: inline-flex; align-items: center; gap: 0.35rem;">
        <label for="filter-availability" style="font-size: 0.88rem; font-weight: 600; color: var(--ink); margin: 0; white-space: nowrap;">Availability:</label>
        <select id="filter-availability" name="status" onchange="this.form.submit()" style="padding: 0.35rem 0.65rem; font-size: 0.88rem; border-radius: var(--radius-sm, 6px); border: 1px solid var(--line-strong); background: var(--surface); color: var(--ink);">
          <option value="" <?= $statusFilter === '' ? 'selected' : '' ?>>All (<?= (int)($statusCounts['total'] ?? 0) ?>)</option>
          <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Available (<?= (int)($statusCounts['active'] ?? 0) ?>)</option>
          <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Unavailable (<?= (int)($statusCounts['inactive'] ?? 0) ?>)</option>
        </select>
      </div>

      <?php if ($statusFilter !== '' || $q !== ''): ?>
        <a href="<?= $actionBase . $unitQuery ?>" class="btn ghost btn-sm" id="btn-reset-filters" style="padding: 0.35rem 0.65rem; font-size: 0.85rem;" title="Reset all filters">Reset</a>
      <?php endif; ?>
    </form>
  </div>

  <?php if (empty($services)): ?>
    <div class="empty" style="text-align: center; padding: 2.5rem 1rem;">
      <p style="margin: 0 0 0.5rem 0; font-size: 1.05rem; font-weight: 600;">No medical services found</p>
      <p class="muted" style="margin: 0 0 1rem 0;">No services match the selected filter criteria.</p>
      <?php if ($statusFilter !== '' || $q !== ''): ?>
        <a href="<?= $actionBase . $unitQuery ?>" class="btn secondary btn-sm" style="margin-right: 0.5rem;">Clear Filters</a>
      <?php endif; ?>
      <button type="button" class="btn primary btn-sm" data-modal-open="create-service-modal">+ Add Medical Service</button>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="vet-services-table">
        <thead>
          <tr>
            <th style="min-width: 180px; text-align: left;">Medical Service</th>
            <th style="min-width: 220px; text-align: left;">Clinical Description</th>
            <th style="width: 110px; text-align: right;">Base Price</th>
            <th style="width: 100px; text-align: center;">Availability</th>
            <th style="width: 150px; text-align: right;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($services as $srv): ?>
            <?php
              $isAvail = ($srv['service_status'] === 'active');
            ?>
            <tr id="service-row-<?= (int)$srv['service_id'] ?>">
              <td>
                <strong style="display: block; font-size: 0.92rem; font-weight: 650; word-break: break-word; color: var(--ink);"><?= e($srv['service_name']) ?></strong>
                <span class="badge secondary" style="font-size: 0.72rem; padding: 0.15rem 0.4rem; margin-top: 0.2rem; display: inline-block;">Medical</span>
              </td>
              <td>
                <span style="font-size: 0.86rem; color: var(--muted); line-height: 1.4; display: block;">
                  <?= e($srv['description'] ?: 'No clinical description recorded.') ?>
                </span>
              </td>
              <td class="tabular" style="text-align: right; font-weight: 700; color: var(--text, #1e293b); white-space: nowrap; font-size: 0.92rem;">
                <?= peso((float)$srv['base_price']) ?>
              </td>
              <td style="text-align: center; white-space: nowrap;">
                <?php if ($isAvail): ?>
                  <span class="badge ok" style="padding: 0.18rem 0.5rem; font-weight: 600; font-size: 0.75rem;">Available</span>
                <?php else: ?>
                  <span class="badge" style="padding: 0.18rem 0.5rem; font-weight: 600; font-size: 0.75rem; background: #fee2e2; color: #991b1b; border: 1px solid #fecaca;">Unavailable</span>
                <?php endif; ?>
              </td>
              <td style="text-align: right; white-space: nowrap;">
                <div style="display: inline-flex; justify-content: flex-end; gap: 0.35rem; align-items: center;">
                  <button type="button" class="btn secondary btn-sm"
                          data-modal-open="edit-service-modal-<?= (int)$srv['service_id'] ?>"
                          title="Edit service details"
                          style="padding: 0.25rem 0.55rem; font-size: 0.8rem;">
                    Edit
                  </button>
                  <?php if ($isAvail): ?>
                    <button type="button" class="btn btn-sm"
                            data-modal-open="toggle-modal-<?= (int)$srv['service_id'] ?>"
                            style="background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; padding: 0.25rem 0.55rem; font-size: 0.8rem; white-space: nowrap;"
                            title="Mark service unavailable">
                      Disable
                    </button>
                  <?php else: ?>
                    <button type="button" class="btn btn-sm"
                            data-modal-open="toggle-modal-<?= (int)$srv['service_id'] ?>"
                            style="background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; padding: 0.25rem 0.55rem; font-size: 0.8rem; white-space: nowrap;"
                            title="Mark service available">
                      Enable
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

<!-- MODAL: CREATE MEDICAL SERVICE -->
<div class="modal" id="create-service-modal" aria-hidden="true" role="dialog" aria-labelledby="create-service-modal-title">
  <div class="modal-card" style="max-width: 520px;">
    <div class="modal-head">
      <div>
        <p class="caption muted" style="margin: 0;">Veterinary Clinic Catalog</p>
        <h3 id="create-service-modal-title" style="margin: 0.15rem 0 0 0;">Add Medical Service</h3>
      </div>
      <button type="button" class="modal-close" data-modal-close aria-label="Close dialog">&times;</button>
    </div>

    <form method="post" action="<?= $actionBase ?>" id="form-create-service">
      <?= \App\Core\CSRF::field() ?>

      <div class="field" style="margin-bottom: 1rem;">
        <label for="create-service-name" style="font-weight: 600; font-size: 0.88rem; display: block; margin-bottom: 0.35rem;">Service Name *</label>
        <input type="text" id="create-service-name" name="service_name" required maxlength="200"
               value="<?= e($old['service_name'] ?? '') ?>"
               placeholder="e.g. Feline Viral Rhinotracheitis Vaccination"
               style="width: 100%; height: 38px;">
        <?php if (!empty($errors['service_name'])): ?>
          <p class="caption text-danger" style="margin: 0.25rem 0 0 0;"><?= e($errors['service_name']) ?></p>
        <?php endif; ?>
      </div>

      <div class="field" style="margin-bottom: 1rem;">
        <label for="create-base-price" style="font-weight: 600; font-size: 0.88rem; display: block; margin-bottom: 0.35rem;">Base Price (₱) *</label>
        <input type="number" id="create-base-price" name="base_price" required min="0" step="0.01"
               value="<?= e($old['base_price'] ?? '') ?>"
               placeholder="0.00"
               style="width: 100%; height: 38px;">
        <?php if (!empty($errors['base_price'])): ?>
          <p class="caption text-danger" style="margin: 0.25rem 0 0 0;"><?= e($errors['base_price']) ?></p>
        <?php endif; ?>
      </div>

      <div class="field" style="margin-bottom: 1.25rem;">
        <label for="create-description" style="font-weight: 600; font-size: 0.88rem; display: block; margin-bottom: 0.35rem;">Clinical Description</label>
        <textarea id="create-description" name="description" rows="3"
                  placeholder="Clinical purpose, indications, protocol, or pre-requisite diagnostic instructions..."
                  style="width: 100%; padding: 0.5rem; font-family: inherit; font-size: 0.88rem; border-radius: var(--radius-sm, 6px); border: 1px solid var(--line-strong); background: var(--surface); color: var(--ink);"><?= e($old['description'] ?? '') ?></textarea>
      </div>

      <div class="modal-foot" style="display: flex; justify-content: flex-end; gap: 0.5rem; margin-top: 1rem;">
        <button type="button" class="btn secondary" data-modal-close>Cancel</button>
        <button type="submit" class="btn primary">Create Medical Service</button>
      </div>
    </form>
  </div>
</div>

<!-- MODALS: EDIT & TOGGLE PER SERVICE -->
<?php foreach ($services as $srv): ?>
  <?php
    $srvId = (int)$srv['service_id'];
    $isAvail = ($srv['service_status'] === 'active');
    $editUrl = !empty($isSuper) && !empty($unitId)
      ? "/manager/units/$unitId/services/$srvId/update"
      : "/manager/services/$srvId/update";
    $statusUrl = !empty($isSuper) && !empty($unitId)
      ? "/manager/units/$unitId/services/$srvId/status"
      : "/manager/services/$srvId/status";
  ?>

  <!-- EDIT SERVICE MODAL -->
  <div class="modal" id="edit-service-modal-<?= $srvId ?>" aria-hidden="true" role="dialog" aria-labelledby="edit-service-modal-title-<?= $srvId ?>">
    <div class="modal-card" style="max-width: 520px;">
      <div class="modal-head">
        <div>
          <p class="caption muted" style="margin: 0;">Edit Medical Service #<?= $srvId ?></p>
          <h3 id="edit-service-modal-title-<?= $srvId ?>" style="margin: 0.15rem 0 0 0;"><?= e($srv['service_name']) ?></h3>
        </div>
        <button type="button" class="modal-close" data-modal-close aria-label="Close dialog">&times;</button>
      </div>

      <form method="post" action="<?= $editUrl ?>" id="form-edit-service-<?= $srvId ?>">
        <?= \App\Core\CSRF::field() ?>

        <div class="field" style="margin-bottom: 1rem;">
          <label for="edit-service-name-<?= $srvId ?>" style="font-weight: 600; font-size: 0.88rem; display: block; margin-bottom: 0.35rem;">Service Name *</label>
          <input type="text" id="edit-service-name-<?= $srvId ?>" name="service_name" required maxlength="200"
                 value="<?= e($srv['service_name']) ?>"
                 style="width: 100%; height: 38px;">
        </div>

        <div class="field" style="margin-bottom: 1rem;">
          <label for="edit-base-price-<?= $srvId ?>" style="font-weight: 600; font-size: 0.88rem; display: block; margin-bottom: 0.35rem;">Base Price (₱) *</label>
          <input type="number" id="edit-base-price-<?= $srvId ?>" name="base_price" required min="0" step="0.01"
                 value="<?= e(number_format((float)$srv['base_price'], 2, '.', '')) ?>"
                 style="width: 100%; height: 38px;">
        </div>

        <div class="field" style="margin-bottom: 1.25rem;">
          <label for="edit-description-<?= $srvId ?>" style="font-weight: 600; font-size: 0.88rem; display: block; margin-bottom: 0.35rem;">Clinical Description</label>
          <textarea id="edit-description-<?= $srvId ?>" name="description" rows="3"
                    style="width: 100%; padding: 0.5rem; font-family: inherit; font-size: 0.88rem; border-radius: var(--radius-sm, 6px); border: 1px solid var(--line-strong); background: var(--surface); color: var(--ink);"><?= e($srv['description'] ?? '') ?></textarea>
        </div>

        <div class="modal-foot" style="display: flex; justify-content: flex-end; gap: 0.5rem; margin-top: 1rem;">
          <button type="button" class="btn secondary" data-modal-close>Cancel</button>
          <button type="submit" class="btn primary">Save Changes</button>
        </div>
      </form>
    </div>
  </div>

  <!-- TOGGLE STATUS MODAL -->
  <div class="modal" id="toggle-modal-<?= $srvId ?>" aria-hidden="true" role="dialog" aria-labelledby="toggle-modal-title-<?= $srvId ?>">
    <div class="modal-card" style="max-width: 440px;">
      <div class="modal-head">
        <div>
          <p class="caption muted" style="margin: 0;">Change Service Availability</p>
          <h3 id="toggle-modal-title-<?= $srvId ?>" style="margin: 0.15rem 0 0 0;"><?= $isAvail ? 'Mark as Unavailable' : 'Mark as Available' ?></h3>
        </div>
        <button type="button" class="modal-close" data-modal-close aria-label="Close dialog">&times;</button>
      </div>

      <form method="post" action="<?= $statusUrl ?>" id="form-toggle-service-<?= $srvId ?>">
        <?= \App\Core\CSRF::field() ?>
        <input type="hidden" name="status" value="<?= $isAvail ? 'inactive' : 'active' ?>">

        <p style="margin: 0.5rem 0 1.25rem 0; font-size: 0.9rem; line-height: 1.5; color: var(--ink);">
          Are you sure you want to mark <strong><?= e($srv['service_name']) ?></strong> as
          <strong><?= $isAvail ? 'Unavailable' : 'Available' ?></strong>?
          <?php if ($isAvail): ?>
            This service will not be available for selection in future client appointment requests. Existing appointment records remain intact.
          <?php else: ?>
            This service will become immediately available for future client bookings.
          <?php endif; ?>
        </p>

        <div class="modal-foot" style="display: flex; justify-content: flex-end; gap: 0.5rem;">
          <button type="button" class="btn secondary" data-modal-close>Cancel</button>
          <button type="submit" class="btn <?= $isAvail ? 'btn-danger' : 'primary' ?>" style="<?= $isAvail ? 'background: #dc2626; color: #fff; border-color: #dc2626;' : '' ?>">
            Confirm <?= $isAvail ? 'Disable' : 'Enable' ?>
          </button>
        </div>
      </form>
    </div>
  </div>
<?php endforeach; ?>

