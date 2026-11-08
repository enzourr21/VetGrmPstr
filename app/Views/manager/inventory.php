<?php
require __DIR__ . '/_nav.php';
$invTab = $inventoryTab ?? 'stock';
$q = $searchQuery ?? '';
$isInvManager = !empty($isInventoryManagerOnly);
?>

<div class="page-head">
  <div>
    <p class="eyebrow">Inventory Control</p>
    <h1>Inventory</h1>
    <p class="muted">Stock level monitoring, verified adjustments, purchase order tracking, and stock receiving records.</p>
  </div>
</div>

<?php if ((int)$unitId === \App\Models\Units::vetClinicId() && !empty($inventorySummary) && !$isInvManager): ?>
  <!-- Veterinary Clinic Inventory Overview -->
  <section class="card" id="vet-inventory-overview-card" style="margin-bottom: 1.5rem; padding: 1.35rem 1.5rem;">
    <div class="row" style="justify-content: space-between; align-items: baseline; margin-bottom: 1rem;">
      <div>
        <span class="eyebrow" style="margin-bottom: 0.2rem;">Veterinary Clinical Inventory</span>
        <h2 style="margin: 0; font-size: 1.25rem;">Inventory Overview</h2>
      </div>
      <span class="caption muted">Real-Time Stock &amp; Expiry Analytics</span>
    </div>

    <!-- 6 Primary Metric Stat Cards -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; margin-bottom: 1.25rem;">
      <article class="card stat-card" style="padding: 1rem; background: var(--surface-subtle, #f8fafc); border: 1px solid var(--line);">
        <span class="eyebrow">Total Products</span>
        <strong class="stat-value" style="font-size: 1.6rem;"><?= (int)($inventorySummary['total_products'] ?? 0) ?></strong>
        <p class="caption" style="margin: 0.25rem 0 0 0;">Active catalog items</p>
      </article>

      <article class="card stat-card" style="padding: 1rem; background: var(--surface-subtle, #f8fafc); border: 1px solid var(--line);">
        <span class="eyebrow">Physical Stock</span>
        <strong class="stat-value" style="font-size: 1.6rem;"><?= (int)($inventorySummary['total_physical_stock'] ?? 0) ?></strong>
        <p class="caption" style="margin: 0.25rem 0 0 0;">Total units on hand</p>
      </article>

      <article class="card stat-card" style="padding: 1rem; background: var(--surface-subtle, #f8fafc); border: 1px solid var(--line);">
        <span class="eyebrow">Low Stock</span>
        <strong class="stat-value" style="font-size: 1.6rem; <?= ($inventorySummary['low_stock_count'] ?? 0) > 0 ? 'color: var(--danger, #dc2626);' : '' ?>">
          <?= (int)($inventorySummary['low_stock_count'] ?? 0) ?>
        </strong>
        <p class="caption" style="margin: 0.25rem 0 0 0;">Available stock &le; 10</p>
      </article>

      <article class="card stat-card" style="padding: 1rem; background: var(--surface-subtle, #f8fafc); border: 1px solid var(--line);">
        <span class="eyebrow">Out of Stock</span>
        <strong class="stat-value" style="font-size: 1.6rem; <?= ($inventorySummary['out_of_stock_count'] ?? 0) > 0 ? 'color: var(--danger, #dc2626);' : '' ?>">
          <?= (int)($inventorySummary['out_of_stock_count'] ?? 0) ?>
        </strong>
        <p class="caption" style="margin: 0.25rem 0 0 0;">Zero usable available</p>
      </article>

      <article class="card stat-card" style="padding: 1rem; background: var(--surface-subtle, #f8fafc); border: 1px solid var(--line);">
        <span class="eyebrow">Expiring Soon</span>
        <strong class="stat-value" style="font-size: 1.6rem; <?= ($inventorySummary['expiring_soon_count'] ?? 0) > 0 ? 'color: #b45309;' : '' ?>">
          <?= (int)($inventorySummary['expiring_soon_count'] ?? 0) ?>
        </strong>
        <p class="caption" style="margin: 0.25rem 0 0 0;">Batches &le; 30 days</p>
      </article>

      <article class="card stat-card" style="padding: 1rem; background: var(--surface-subtle, #f8fafc); border: 1px solid var(--line);">
        <span class="eyebrow">Expired Batches</span>
        <strong class="stat-value" style="font-size: 1.6rem; <?= ($inventorySummary['expired_count'] ?? 0) > 0 ? 'color: var(--danger, #dc2626);' : '' ?>">
          <?= (int)($inventorySummary['expired_count'] ?? 0) ?>
        </strong>
        <p class="caption" style="margin: 0.25rem 0 0 0;">Require write-off</p>
      </article>
    </div>

    <!-- 7. Recent Inventory Movements Summary -->
    <div style="border-top: 1px solid var(--line); padding-top: 1rem;">
      <div class="row" style="justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
        <span class="caption" style="font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em;">Recent Inventory Movements</span>
        <a href="/manager/inventory<?= $unitQuery ?><?= !empty($unitQuery) ? '&' : '?' ?>tab=movements" class="btn ghost btn-sm">
          View All Movements (<?= (int)($inventorySummary['total_movements'] ?? 0) ?>) &rarr;
        </a>
      </div>
      <?php if (empty($inventorySummary['recent_movements'])): ?>
        <p class="caption muted" style="margin: 0;">No stock movements recorded yet for this facility.</p>
      <?php else: ?>
        <div class="table-wrap" style="border: 1px solid var(--line); border-radius: var(--radius-sm);">
          <table style="margin: 0; font-size: 0.85rem;">
            <thead>
              <tr>
                <th style="padding: 0.5rem 0.75rem;">Date &amp; Time</th>
                <th style="padding: 0.5rem 0.75rem;">Product</th>
                <th style="padding: 0.5rem 0.75rem;">Batch</th>
                <th style="padding: 0.5rem 0.75rem;">Movement Type</th>
                <th style="padding: 0.5rem 0.75rem; text-align: right;">Quantity</th>
                <th style="padding: 0.5rem 0.75rem;">Notes / Reason</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($inventorySummary['recent_movements'] as $rm):
                $rmQty = (int)$rm['quantity'];
              ?>
                <tr>
                  <td style="padding: 0.5rem 0.75rem; white-space: nowrap; color: var(--muted);">
                    <?= date('M j, Y · g:i A', strtotime((string)$rm['movement_date'])) ?>
                  </td>
                  <td style="padding: 0.5rem 0.75rem;">
                    <strong><?= e($rm['product_name']) ?></strong>
                    <?php if (!empty($rm['sku'])): ?>
                      <span class="caption muted" style="margin-left: 0.35rem;"><code><?= e($rm['sku']) ?></code></span>
                    <?php endif; ?>
                  </td>
                  <td style="padding: 0.5rem 0.75rem;">
                    <?= !empty($rm['batch_number']) ? '<code>' . e($rm['batch_number']) . '</code>' : '<span class="caption muted">—</span>' ?>
                  </td>
                  <td style="padding: 0.5rem 0.75rem;">
                    <span class="badge secondary" style="font-size: 0.75rem;"><?= e(str_replace('_', ' ', (string)$rm['movement_type'])) ?></span>
                  </td>
                  <td style="padding: 0.5rem 0.75rem; text-align: right; font-weight: 600; color: <?= $rmQty > 0 ? 'var(--primary, #059669)' : 'var(--danger, #dc2626)' ?>;">
                    <?= $rmQty > 0 ? ('+' . $rmQty) : $rmQty ?>
                  </td>
                  <td style="padding: 0.5rem 0.75rem; color: var(--ink);">
                    <?= e($rm['notes'] ?: '—') ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </section>
<?php endif; ?>

<!-- Sub-Tabs: Stock Levels vs Purchase Orders vs Movement History -->
<div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.5rem;">
  <div class="segmented-bar">
    <a href="/manager/inventory<?= $unitQuery ?><?= !empty($unitQuery) ? '&' : '?' ?>tab=stock"
       class="segmented-pill <?= $invTab === 'stock' ? 'is-active' : '' ?>">
      Stock Levels &amp; Adjustments (<?= count($inventoryItems) ?>)
    </a>
    <a href="/manager/inventory<?= $unitQuery ?><?= !empty($unitQuery) ? '&' : '?' ?>tab=orders"
       class="segmented-pill <?= $invTab === 'orders' ? 'is-active' : '' ?>">
      Purchase Orders (<?= count($purchaseOrders) ?>)
    </a>
    <a href="/manager/inventory<?= $unitQuery ?><?= !empty($unitQuery) ? '&' : '?' ?>tab=movements"
       class="segmented-pill <?= $invTab === 'movements' ? 'is-active' : '' ?>">
      Movement History (<?= (int)($inventorySummary['total_movements'] ?? 0) ?>)
    </a>
  </div>

  <?php if ($invTab === 'stock'): ?>
    <!-- Inventory Key Action, Search Field, and Request Order Action -->
    <div class="row" style="gap: 0.75rem; align-items: center;">
      <?php if (!empty($canManageInventoryKey)): ?>
        <div class="inv-key-header-actions" style="display: inline-flex; align-items: center; gap: 0.5rem;">
          <?php if (empty($activeInventoryKey)): ?>
            <form method="post" action="/manager/inventory/generate-key<?= $unitQuery ?>" style="margin: 0; display: inline-flex;" id="generate-inv-key-form">
              <?= \App\Core\CSRF::field() ?>
              <input type="hidden" name="return_tab" value="<?= e($invTab) ?>">
              <button type="submit" class="btn primary btn-sm" id="generate-inv-key-btn" style="white-space: nowrap;">
                Generate Inventory Key
              </button>
            </form>
          <?php else: ?>
            <button type="button"
                    class="btn primary btn-sm"
                    id="view-inv-key-btn"
                    style="white-space: nowrap;"
                    data-modal-open="inventory-key-modal"
                    data-key-seconds="<?= (int)($activeInventoryKey['seconds_remaining'] ?? 0) ?>">
              View Key
            </button>
            <button type="button"
                    class="btn danger btn-sm"
                    id="header-revoke-inv-key-btn"
                    style="white-space: nowrap;"
                    data-modal-open="revoke-key-modal"
                    data-revoke-key-id="<?= (int)$activeInventoryKey['key_id'] ?>"
                    data-revoke-key-code="<?= e($activeInventoryKey['key_code']) ?>"
                    data-revoke-expires="<?= e($activeInventoryKey['expires_at_formatted'] ?? '') ?>">
              Revoke
            </button>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <form method="get" action="/manager/inventory" class="row" style="gap: 0.5rem; align-items: center; margin: 0;">
        <?php if (!empty($isSuper)): ?>
          <input type="hidden" name="unit_id" value="<?= (int)$unitId ?>">
        <?php endif; ?>
        <input type="hidden" name="tab" value="stock">
        <div class="search-input-wrap" style="width: 240px;">
          <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="11" cy="11" r="8"></circle>
            <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
          </svg>
          <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search stock or SKU... (Enter)" class="search-input">
        </div>
        <?php if ($q !== ''): ?>
          <a href="/manager/inventory<?= $unitQuery ?>" class="btn ghost btn-sm">Reset</a>
        <?php endif; ?>
      </form>
    </div>
  <?php elseif ($invTab === 'movements'): ?>
    <!-- Movement Search Field -->
    <form method="get" action="/manager/inventory" class="row" style="gap: 0.5rem; align-items: center; margin: 0;">
      <?php if (!empty($isSuper)): ?>
        <input type="hidden" name="unit_id" value="<?= (int)$unitId ?>">
      <?php endif; ?>
      <input type="hidden" name="tab" value="movements">
      <div class="search-input-wrap" style="width: 280px;">
        <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <circle cx="11" cy="11" r="8"></circle>
          <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
        </svg>
        <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search movements, batch, SKU... (Enter)" class="search-input">
      </div>
      <?php if ($q !== ''): ?>
        <a href="/manager/inventory<?= $unitQuery ?><?= !empty($unitQuery) ? '&' : '?' ?>tab=movements" class="btn ghost btn-sm">Reset</a>
      <?php endif; ?>
    </form>
  <?php endif; ?>
</div>

<?php if ($invTab === 'stock'): ?>
  <!-- STOCK LEVELS TABLE -->
  <section class="card">
    <div class="row" style="justify-content: space-between; align-items: baseline; margin-bottom: 1rem;">
      <div>
        <h2 style="margin: 0;"><?= e($unitName) ?> Stock Levels</h2>
        <p class="caption" style="margin: 0.2rem 0 0 0;">
          <?php if ((int)$unitId === \App\Models\Units::vetClinicId()): ?>
            Veterinary Clinic animal-health inventory with batch number and expiration date tracking. Stock adjustments require mandatory audit reasons.
          <?php else: ?>
            Prioritizing out-of-stock items (A–Z) followed by in-stock inventory. Stock adjustments require mandatory audit reasons.
          <?php endif; ?>
        </p>
      </div>
      <span class="caption"><?= count($inventoryItems) ?> items</span>
    </div>

    <?php if (empty($inventoryItems)): ?>
      <div class="empty">
        No inventory items found<?= $q !== '' ? ' matching "' . e($q) . '"' : '' ?>.
        <?php if ((int)$unitId === \App\Models\Units::vetClinicId()): ?>
          <span class="caption muted" style="display: block; margin-top: 0.25rem;">Batches &amp; Expiry tracking active for Veterinary Clinic animal-health stock.</span>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="inventory-stock-table" style="width: 100%; min-width: <?= (int)$unitId === \App\Models\Units::vetClinicId() ? '66rem' : '52rem' ?>; border-collapse: collapse;">
          <thead>
            <tr>
              <th style="min-width: 180px; width: 22%;">Product</th>
              <th style="white-space: nowrap; min-width: 110px;">SKU / Code</th>
              <th style="white-space: nowrap; min-width: 95px;">Category</th>
              <?php if ((int)$unitId === \App\Models\Units::vetClinicId()): ?>
                <th style="min-width: 280px; width: 28%;">Batches &amp; Expiry</th>
              <?php endif; ?>
              <th style="text-align: center; white-space: nowrap; min-width: 90px;">Physical On Hand</th>
              <th style="text-align: center; white-space: nowrap; min-width: 80px;">Committed</th>
              <th style="text-align: center; white-space: nowrap; min-width: 90px;">Usable Available</th>
              <th style="text-align: center; white-space: nowrap; min-width: 95px;">Stock Status</th>
              <th style="text-align: right; white-space: nowrap; min-width: 105px;">Stock Adjustment</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($inventoryItems as $item):
              $isOutOfStock = (int)$item['available'] <= 0;
            ?>
              <tr style="<?= $isOutOfStock ? 'background-color: rgba(254, 242, 242, 0.4);' : '' ?>">
                <td style="vertical-align: top; padding: 0.75rem 0.85rem;">
                  <strong><?= e($item['product_name']) ?></strong>
                  <?php if ($isOutOfStock): ?>
                    <span class="badge badge-danger" style="margin-left: 0.4rem; font-size: 0.7rem; white-space: nowrap;">Out of Stock</span>
                  <?php endif; ?>
                </td>
                <td style="white-space: nowrap; vertical-align: top; padding: 0.75rem 0.85rem;"><code><?= e($item['sku'] ?: '—') ?></code></td>
                <td style="white-space: nowrap; vertical-align: top; padding: 0.75rem 0.85rem;"><span class="badge secondary"><?= e($item['product_category']) ?></span></td>
                <?php if ((int)$unitId === \App\Models\Units::vetClinicId()): ?>
                  <td style="vertical-align: top; padding: 0.75rem 0.85rem;">
                    <?php if (!empty($item['batches'])): ?>
                      <div style="display: flex; flex-direction: column; gap: 0.35rem; font-size: 0.78rem;">
                        <?php foreach ($item['batches'] as $b): ?>
                          <div style="display: inline-flex; align-items: center; gap: 0.45rem; white-space: nowrap;">
                            <code><?= e($b['batch_number']) ?></code>
                            <span class="muted" style="font-weight: 600;">(<?= (int)$b['quantity'] ?>)</span>
                            <?php if (!empty($b['expiration_date'])): ?>
                              <span class="caption" style="color: var(--muted); font-size: 0.75rem; white-space: nowrap;">Exp: <?= date('M j, Y', strtotime((string)$b['expiration_date'])) ?></span>
                            <?php endif; ?>
                            <span class="badge <?= e($b['badge_class']) ?>" style="font-size: 0.7rem; padding: 0.1rem 0.35rem; white-space: nowrap;">
                              <?= e($b['status_label']) ?>
                            </span>
                          </div>
                        <?php endforeach; ?>
                      </div>
                    <?php elseif (!empty($item['requires_batch_tracking'])): ?>
                      <span class="caption muted">No active batches</span>
                    <?php else: ?>
                      <span class="caption muted">Standard item</span>
                    <?php endif; ?>
                  </td>
                <?php endif; ?>
                <td style="text-align: center; white-space: nowrap; vertical-align: top; padding: 0.75rem 0.85rem;">
                  <strong style="<?= (int)$item['on_hand'] <= 0 ? 'color: var(--danger, #dc2626); font-size: 1.1rem;' : '' ?>">
                    <?= (int)$item['on_hand'] ?>
                  </strong>
                  <?php if (!empty($item['expired_quantity'])): ?>
                    <div class="caption" style="color: var(--danger, #dc2626); font-weight: 600; font-size: 0.75rem;">
                      (<?= (int)$item['expired_quantity'] ?> expired)
                    </div>
                  <?php endif; ?>
                </td>
                <td style="text-align: center; white-space: nowrap; vertical-align: top; padding: 0.75rem 0.85rem;"><?= (int)$item['committed'] ?></td>
                <td style="text-align: center; white-space: nowrap; vertical-align: top; padding: 0.75rem 0.85rem;">
                  <strong style="<?= (int)$item['available'] <= 0 ? 'color: var(--danger, #dc2626);' : '' ?>">
                    <?= (int)$item['available'] ?>
                  </strong>
                </td>
                <td style="text-align: center; white-space: nowrap; vertical-align: top; padding: 0.75rem 0.85rem;">
                  <span class="badge <?= $isOutOfStock ? 'badge-danger' : 'badge-success' ?>" style="white-space: nowrap;">
                    <?= $isOutOfStock ? 'Out of Stock' : 'In Stock' ?>
                  </span>
                </td>
                <td style="text-align: right; white-space: nowrap; vertical-align: top; padding: 0.75rem 0.85rem;">
                  <div class="row" style="gap: 0.4rem; justify-content: flex-end; align-items: center;">
                    <!-- Increase Button (+) -->
                    <button type="button"
                            class="btn secondary btn-sm"
                            style="font-weight: bold; width: 34px; padding: 0.25rem 0;"
                            title="Increase Stock (+)"
                            data-modal-open="adjust-modal-inc-<?= (int)$item['business_product_id'] ?>">
                      +
                    </button>

                    <!-- Decrease Button (−): Disabled if on_hand <= 0 -->
                    <button type="button"
                            class="btn secondary btn-sm"
                            style="font-weight: bold; width: 34px; padding: 0.25rem 0;"
                            title="<?= (int)$item['on_hand'] <= 0 ? 'Cannot decrease stock when on-hand is 0' : 'Decrease Stock (−)' ?>"
                            <?= (int)$item['on_hand'] <= 0 ? 'disabled' : '' ?>
                            data-modal-open="adjust-modal-dec-<?= (int)$item['business_product_id'] ?>">
                      &minus;
                    </button>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <!-- Stock Adjustment Modals (Increase & Decrease) -->
  <?php foreach ($inventoryItems as $item): ?>
    <!-- INCREASE MODAL (+) -->
    <dialog class="modal" id="adjust-modal-inc-<?= (int)$item['business_product_id'] ?>" aria-labelledby="inc-title-<?= (int)$item['business_product_id'] ?>">
      <div class="modal-head">
        <h3 id="inc-title-<?= (int)$item['business_product_id'] ?>">Increase Stock: <?= e($item['product_name']) ?></h3>
        <button type="button" class="btn ghost btn-sm" data-modal-close aria-label="Close">&times;</button>
      </div>
      <div class="modal-body">
        <form method="post" action="/manager/inventory/adjust<?= $unitQuery ?>" class="stock-adj-form">
          <?= \App\Core\CSRF::field() ?>
          <input type="hidden" name="business_product_id" value="<?= (int)$item['business_product_id'] ?>">
          <input type="hidden" name="adjustment_type" value="increase">

          <div style="background: var(--bg-subtle, #f9fafb); padding: 0.75rem 1rem; border-radius: 6px; margin-bottom: 1rem;">
            <div class="row" style="justify-content: space-between;">
              <span class="caption">Current Physical On-Hand:</span>
              <strong><?= (int)$item['on_hand'] ?></strong>
            </div>
            <?php if (!empty($item['expired_quantity'])): ?>
              <div class="row" style="justify-content: space-between; margin-top: 0.25rem;">
                <span class="caption" style="color: var(--danger, #dc2626);">Expired Quantity:</span>
                <strong style="color: var(--danger, #dc2626);"><?= (int)$item['expired_quantity'] ?></strong>
              </div>
            <?php endif; ?>
            <div class="row" style="justify-content: space-between; margin-top: 0.25rem;">
              <span class="caption">SKU:</span>
              <code><?= e($item['sku'] ?: '—') ?></code>
            </div>
          </div>

          <?php if (!empty($item['requires_batch_tracking'])): ?>
            <!-- Batch Selection for Batch-Tracked Products -->
            <div class="field">
              <label for="inc-batch-select-<?= (int)$item['business_product_id'] ?>">Destination Batch</label>
              <select id="inc-batch-select-<?= (int)$item['business_product_id'] ?>"
                      name="batch_id"
                      onchange="const isNew = this.value === '0'; document.getElementById('inc-new-batch-fields-<?= (int)$item['business_product_id'] ?>').style.display = isNew ? 'block' : 'none'; const reqInp = document.getElementById('inc-batch-num-<?= (int)$item['business_product_id'] ?>'); if (reqInp) { reqInp.required = isNew; }">
                <option value="0">+ Create / Enter New Batch</option>
                <?php foreach ($item['batches'] as $b): ?>
                  <option value="<?= (int)$b['batch_id'] ?>">
                    Existing: <?= e($b['batch_number']) ?> (Current: <?= (int)$b['quantity'] ?><?= !empty($b['expiration_date']) ? ', Exp: ' . date('M j, Y', strtotime((string)$b['expiration_date'])) : '' ?>)
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div id="inc-new-batch-fields-<?= (int)$item['business_product_id'] ?>" style="background: var(--bg-subtle, #f9fafb); padding: 0.75rem 1rem; border-radius: 6px; margin-bottom: 1rem; border: 1px dashed var(--line);">
              <div class="field" style="margin-bottom: 0.75rem;">
                <label for="inc-batch-num-<?= (int)$item['business_product_id'] ?>">New Batch Number *</label>
                <input type="text" id="inc-batch-num-<?= (int)$item['business_product_id'] ?>" name="batch_number" placeholder="e.g. BATCH-2026-001" required>
              </div>
              <div class="field" style="margin-bottom: 0;">
                <label for="inc-exp-date-<?= (int)$item['business_product_id'] ?>">Expiration Date (YYYY-MM-DD)</label>
                <input type="date" id="inc-exp-date-<?= (int)$item['business_product_id'] ?>" name="expiration_date">
              </div>
            </div>
          <?php endif; ?>

          <div class="field">
            <label for="inc-qty-<?= (int)$item['business_product_id'] ?>">Quantity to Add *</label>
            <input id="inc-qty-<?= (int)$item['business_product_id'] ?>" name="quantity" type="number" min="1" required value="1">
          </div>

          <div class="field">
            <label for="inc-reason-<?= (int)$item['business_product_id'] ?>">Mandatory Reason for Increase *</label>
            <select id="inc-reason-<?= (int)$item['business_product_id'] ?>" name="reason" required class="inc-reason-select">
              <option value="">Select reason...</option>
              <option value="Returned product from refund">Returned product from refund</option>
              <option value="Returned product from cancelled order">Returned product from cancelled order</option>
              <option value="New stock received">New stock received</option>
              <option value="Other approved stock correction">Other approved stock correction</option>
            </select>
          </div>

          <!-- Inspection Check for Returns -->
          <div class="return-inspection-wrap" style="display: none; background: #fffbeb; border: 1px solid #fef3c7; padding: 0.75rem; border-radius: 6px; margin-bottom: 1rem;">
            <label style="display: flex; gap: 0.5rem; align-items: flex-start; cursor: pointer; font-size: 0.9rem;">
              <input type="checkbox" name="inspection_confirmed" value="1" class="inspection-checkbox" style="margin-top: 0.2rem;">
              <span><strong>Inspection Check Confirmed:</strong> Returned product has been inspected and confirmed undamaged and suitable for resale.</span>
            </label>

            <div class="field" style="margin-top: 0.75rem; margin-bottom: 0;">
              <label class="caption" style="display: block; margin-bottom: 0.25rem;">Related Order / Refund Reference # (Optional)</label>
              <input type="text" name="related_reference" placeholder="e.g. REF-1002 or ORD-2005" style="width: 100%;">
            </div>
          </div>

          <div class="modal-foot" style="margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid var(--border, #e5e7eb); display: flex; justify-content: flex-end; gap: 0.5rem;">
            <button type="button" class="btn ghost" data-modal-close>Cancel</button>
            <button type="submit" class="btn primary">Confirm Increase</button>
          </div>
        </form>
      </div>
    </dialog>

    <!-- DECREASE MODAL (−) -->
    <dialog class="modal" id="adjust-modal-dec-<?= (int)$item['business_product_id'] ?>" aria-labelledby="dec-title-<?= (int)$item['business_product_id'] ?>">
      <div class="modal-head">
        <h3 id="dec-title-<?= (int)$item['business_product_id'] ?>">Decrease Stock: <?= e($item['product_name']) ?></h3>
        <button type="button" class="btn ghost btn-sm" data-modal-close aria-label="Close">&times;</button>
      </div>
      <div class="modal-body">
        <form method="post" action="/manager/inventory/adjust<?= $unitQuery ?>">
          <?= \App\Core\CSRF::field() ?>
          <input type="hidden" name="business_product_id" value="<?= (int)$item['business_product_id'] ?>">
          <input type="hidden" name="adjustment_type" value="decrease">

          <div style="background: var(--bg-subtle, #f9fafb); padding: 0.75rem 1rem; border-radius: 6px; margin-bottom: 1rem;">
            <div class="row" style="justify-content: space-between;">
              <span class="caption">Current Physical On-Hand:</span>
              <strong><?= (int)$item['on_hand'] ?></strong>
            </div>
            <?php if (!empty($item['expired_quantity'])): ?>
              <div class="row" style="justify-content: space-between; margin-top: 0.25rem;">
                <span class="caption" style="color: var(--danger, #dc2626);">Expired Quantity:</span>
                <strong style="color: var(--danger, #dc2626);"><?= (int)$item['expired_quantity'] ?></strong>
              </div>
            <?php endif; ?>
            <div class="row" style="justify-content: space-between; margin-top: 0.25rem;">
              <span class="caption">SKU:</span>
              <code><?= e($item['sku'] ?: '—') ?></code>
            </div>
          </div>

          <?php if (!empty($item['requires_batch_tracking'])): ?>
            <!-- Batch Selection for Batch-Tracked Products -->
            <div class="field">
              <label for="dec-batch-<?= (int)$item['business_product_id'] ?>">Affected Batch *</label>
              <select id="dec-batch-<?= (int)$item['business_product_id'] ?>" name="batch_id" required>
                <option value="">Select batch to deduct from...</option>
                <?php foreach ($item['batches'] as $b): ?>
                  <option value="<?= (int)$b['batch_id'] ?>">
                    Batch <?= e($b['batch_number']) ?> &mdash; Qty: <?= (int)$b['quantity'] ?><?= !empty($b['expiration_date']) ? ' (Exp: ' . date('M j, Y', strtotime((string)$b['expiration_date'])) . ' - ' . e($b['status_label']) . ')' : '' ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          <?php endif; ?>

          <div class="field">
            <label for="dec-qty-<?= (int)$item['business_product_id'] ?>">Quantity to Deduct *</label>
            <input id="dec-qty-<?= (int)$item['business_product_id'] ?>" name="quantity" type="number" min="1" max="<?= (int)$item['on_hand'] ?>" required value="1">
          </div>

          <div class="field">
            <label for="dec-reason-<?= (int)$item['business_product_id'] ?>">Mandatory Reason for Decrease *</label>
            <select id="dec-reason-<?= (int)$item['business_product_id'] ?>" name="reason" required>
              <option value="">Select reason...</option>
              <option value="Damaged product">Damaged product</option>
              <option value="Missing or lost product">Missing or lost product</option>
              <option value="Physical stock count correction">Physical stock count correction</option>
              <option value="Expired stock write-off">Expired stock write-off</option>
              <option value="Other approved stock correction">Other approved stock correction</option>
            </select>
          </div>

          <div class="field" style="margin-top: 0.75rem;">
            <label for="dec-ref-<?= (int)$item['business_product_id'] ?>" class="caption" style="display: block; margin-bottom: 0.25rem;">Reference / Notes (Optional)</label>
            <input type="text" id="dec-ref-<?= (int)$item['business_product_id'] ?>" name="related_reference" placeholder="e.g. Audit disposal ticket #, incident ref" style="width: 100%;">
          </div>

          <div class="modal-foot" style="margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid var(--border, #e5e7eb); display: flex; justify-content: flex-end; gap: 0.5rem;">
            <button type="button" class="btn ghost" data-modal-close>Cancel</button>
            <button type="submit" class="btn danger">Confirm Deduction</button>
          </div>
        </form>
      </div>
    </dialog>
  <?php endforeach; ?>

<?php elseif ($invTab === 'orders'): ?>
  <section class="card" <?= !$isInvManager ? 'style="margin-bottom: 1.5rem;"' : '' ?>>
    <div class="row" style="justify-content: space-between; align-items: baseline; margin-bottom: 1rem;">
      <div>
        <h2 style="margin: 0;">Purchase Order Requests (<?= count($purchaseOrderRequests ?? []) ?>)</h2>
        <p class="caption" style="margin: 0.2rem 0 0 0;">
          <?php if ($isInvManager): ?>
            Stock replenishment requisitions submitted for Business Manager review and approval.
          <?php else: ?>
            Stock replenishment requests submitted by the Inventory Manager awaiting review and purchase order generation.
          <?php endif; ?>
        </p>
      </div>
      <div>
        <?php if ($isInvManager): ?>
          <button type="button" class="btn primary btn-sm" data-modal-open="po-request-modal">
            Request Purchase Order
          </button>
        <?php endif; ?>
      </div>
    </div>

    <?php if (empty($purchaseOrderRequests)): ?>
      <div class="empty">No purchase order requests found. Use "Request Purchase Order" to submit a stock replenishment requisition.</div>
    <?php else: ?>
      <div class="table-wrap">
        <table style="width: 100%; min-width: 52rem; border-collapse: collapse;">
          <thead>
            <tr>
              <th style="white-space: nowrap; min-width: 110px;">Request #</th>
              <th style="white-space: nowrap; min-width: 95px;">Date</th>
              <th style="min-width: 130px;">Requester</th>
              <th style="min-width: 140px;">Reason</th>
              <th style="white-space: nowrap; min-width: 110px;">Items</th>
              <th style="white-space: nowrap; min-width: 85px;">Priority</th>
              <th style="white-space: nowrap; min-width: 85px;">Status</th>
              <th style="text-align: right; white-space: nowrap; min-width: 100px;">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($purchaseOrderRequests as $por): ?>
              <tr>
                <td><strong><?= e($por['request_number']) ?></strong></td>
                <td><?= e(date('M j, Y', strtotime((string)$por['request_date']))) ?></td>
                <td><?= e($por['requester_name']) ?></td>
                <td><?= e($por['reason']) ?></td>
                <td><?= (int)$por['total_items'] ?> item(s) (<?= (int)$por['total_qty_requested'] ?> units)</td>
                <td>
                  <?php
                  $prio = (string)$por['priority'];
                  $prioClass = in_array($prio, ['High', 'Urgent'], true) ? 'badge-danger' : ($prio === 'Medium' ? 'badge-warning' : 'secondary');
                  ?>
                  <span class="badge <?= $prioClass ?>"><?= e($prio) ?></span>
                </td>
                <td>
                  <?php
                  $rst = (string)$por['request_status'];
                  $rClass = $rst === 'Approved' ? 'badge-success' : ($rst === 'Rejected' ? 'badge-danger' : 'badge-warning');
                  ?>
                  <span class="badge <?= $rClass ?>"><?= e($rst) ?></span>
                </td>
                <td style="text-align: right;">
                  <div class="row" style="gap: 0.35rem; justify-content: flex-end;">
                    <button type="button" class="btn ghost btn-sm" data-modal-open="view-por-<?= (int)$por['request_id'] ?>">
                      View Details
                    </button>
                    <?php if (!$isInvManager && $por['request_status'] === 'Pending'): ?>
                      <button type="button" class="btn secondary btn-sm" data-modal-open="review-por-<?= (int)$por['request_id'] ?>">
                        Review
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

  <!-- PURCHASE ORDERS & RECEIVING TABLE (Visible to Business Manager) -->
  <?php if (!$isInvManager): ?>
    <section class="card">
      <div class="row" style="justify-content: space-between; align-items: baseline; margin-bottom: 1rem;">
        <div>
          <h2 style="margin: 0;">Purchase Orders &amp; Stock Receiving</h2>
          <p class="caption" style="margin: 0.2rem 0 0 0;">Purchase orders do not increase stock upon creation; only accepted received quantities update inventory.</p>
        </div>
        <div>
          <button type="button" class="btn primary btn-sm" data-modal-open="po-create-modal">
            + Purchase Order
          </button>
        </div>
      </div>

      <?php if (empty($purchaseOrders)): ?>
        <div class="empty">No purchase orders found. Create a purchase order to initiate stock replenishment.</div>
      <?php else: ?>
        <div class="table-wrap">
          <table style="width: 100%; min-width: 54rem; border-collapse: collapse;">
            <thead>
              <tr>
                <th style="white-space: nowrap; min-width: 110px;">PO Number</th>
                <th style="min-width: 140px;">Supplier</th>
                <th style="white-space: nowrap; min-width: 95px;">Order Date</th>
                <th style="min-width: 120px;">Ordered By</th>
                <th style="white-space: nowrap; min-width: 90px;">Items</th>
                <th style="white-space: nowrap; min-width: 95px;">Total Cost</th>
                <th style="white-space: nowrap; min-width: 90px;">Status</th>
                <th style="text-align: right; white-space: nowrap; min-width: 110px;">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($purchaseOrders as $po): ?>
                <tr>
                  <td><strong><?= e($po['po_number']) ?></strong></td>
                  <td>
                    <strong><?= e($po['supplier_name']) ?></strong>
                    <div class="caption"><?= e($po['contact_person']) ?></div>
                  </td>
                  <td><?= e(date('M j, Y', strtotime((string)$po['order_date']))) ?></td>
                  <td><?= e($po['created_by_name']) ?></td>
                  <td><?= (int)$po['total_ordered_qty'] ?> unit(s)</td>
                  <td><?= peso((float)$po['total_cost']) ?></td>
                  <td>
                    <span class="badge <?= $po['po_status'] === 'Fully Received' ? 'badge-success' : ($po['po_status'] === 'Partially Received' ? 'badge-warning' : 'secondary') ?>">
                      <?= e($po['po_status']) ?>
                    </span>
                  </td>
                  <td style="text-align: right;">
                    <div class="row" style="gap: 0.35rem; justify-content: flex-end;">
                      <button type="button" class="btn ghost btn-sm" data-modal-open="view-po-<?= (int)$po['purchase_order_id'] ?>">
                        View Details
                      </button>
                      <?php if (in_array($po['po_status'], ['Ordered', 'Partially Received'], true)): ?>
                        <button type="button" class="btn secondary btn-sm" data-modal-open="receive-po-<?= (int)$po['purchase_order_id'] ?>">
                          Receive Stock
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
  <?php endif; ?>

  <!-- View PO Details & Stock Receiving Modals -->
  <?php foreach ($purchaseOrders as $po): ?>
    <!-- PO Details Modal -->
    <dialog class="modal modal-wide" id="view-po-<?= (int)$po['purchase_order_id'] ?>" aria-labelledby="view-po-title-<?= (int)$po['purchase_order_id'] ?>">
      <div class="modal-head">
        <div>
          <span class="badge secondary" style="margin-bottom: 0.25rem;">Purchase Order</span>
          <h3 id="view-po-title-<?= (int)$po['purchase_order_id'] ?>" style="margin: 0;"><?= e($po['po_number']) ?></h3>
        </div>
        <button type="button" class="btn ghost btn-sm" data-modal-close aria-label="Close">&times;</button>
      </div>
      <div class="modal-body">
        <div class="grid grid-2" style="background: var(--bg-subtle, #f9fafb); padding: 1rem; border-radius: 6px; margin-bottom: 1.5rem; gap: 1rem;">
          <div>
            <p class="caption" style="margin: 0 0 0.25rem 0;">Supplier</p>
            <strong><?= e($po['supplier_name']) ?></strong>
            <div class="caption">Contact: <?= e($po['contact_person']) ?></div>
            <div class="caption">Email: <?= e($po['supplier_email'] ?: '—') ?> · Phone: <?= e($po['supplier_phone'] ?: '—') ?></div>
          </div>
          <div>
            <p class="caption" style="margin: 0 0 0.25rem 0;">Order Metadata</p>
            <div>Date: <strong><?= e(date('F j, Y g:i A', strtotime((string)$po['order_date']))) ?></strong></div>
            <div>Created By: <strong><?= e($po['created_by_name']) ?></strong></div>
            <div>Status: <span class="badge secondary"><?= e($po['po_status']) ?></span></div>
          </div>
        </div>

        <h4>Ordered Items</h4>
        <div class="table-wrap" style="margin-bottom: 1.5rem;">
          <table>
            <thead>
              <tr>
                <th>Product</th>
                <th>SKU</th>
                <th>Qty Ordered</th>
                <th>Qty Received</th>
                <th>Outstanding</th>
                <th>Unit Cost</th>
                <th>Line Cost</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($po['items'] as $it): ?>
                <tr>
                  <td><strong><?= e($it['product_name']) ?></strong></td>
                  <td><code><?= e($it['sku'] ?: '—') ?></code></td>
                  <td><?= (int)$it['quantity_ordered'] ?></td>
                  <td><?= (int)$it['quantity_received'] ?></td>
                  <td><strong><?= max(0, (int)$it['quantity_ordered'] - (int)$it['quantity_received']) ?></strong></td>
                  <td><?= peso((float)$it['unit_cost']) ?></td>
                  <td><?= peso((float)$it['unit_cost'] * (int)$it['quantity_ordered']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <?php if (!empty($po['receivings'])): ?>
          <h4>Receiving Records (<?= count($po['receivings']) ?>)</h4>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Receiving Ref</th>
                  <th>Date</th>
                  <th>Received By</th>
                  <th>Product</th>
                  <th>Received</th>
                  <th>Accepted</th>
                  <th>Rejected</th>
                  <th>Rejection Reason</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($po['receivings'] as $rcv): ?>
                  <tr>
                    <td><strong><?= e($rcv['receiving_reference']) ?></strong></td>
                    <td class="caption"><?= e(date('M j, Y g:i A', strtotime((string)$rcv['receiving_date']))) ?></td>
                    <td><?= e($rcv['employee_name']) ?></td>
                    <td><?= e($rcv['product_name']) ?></td>
                    <td><?= (int)$rcv['quantity_received'] ?></td>
                    <td><strong style="color: var(--success, #16a34a);"><?= (int)$rcv['quantity_accepted'] ?></strong></td>
                    <td><?= (int)$rcv['quantity_rejected'] ?></td>
                    <td class="caption"><?= e($rcv['rejection_reason'] ?: '—') ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn ghost btn-sm" data-modal-close>Close</button>
      </div>
    </dialog>

    <!-- Receive Stock Modal -->
    <dialog class="modal modal-wide" id="receive-po-<?= (int)$po['purchase_order_id'] ?>" aria-labelledby="rcv-po-title-<?= (int)$po['purchase_order_id'] ?>">
      <div class="modal-head">
        <h3 id="rcv-po-title-<?= (int)$po['purchase_order_id'] ?>">Receive Stock for <?= e($po['po_number']) ?></h3>
        <button type="button" class="btn ghost btn-sm" data-modal-close aria-label="Close">&times;</button>
      </div>
      <div class="modal-body">
        <p class="caption" style="margin-bottom: 1rem;">
          Record incoming shipment. <strong>Important:</strong> Only accepted quantities increase on-hand inventory. Rejected items are logged with reason and do not enter stock.
        </p>

        <form method="post" action="/manager/inventory/purchase-orders/<?= (int)$po['purchase_order_id'] ?>/receive<?= $unitQuery ?>">
          <?= \App\Core\CSRF::field() ?>

          <div class="table-wrap" style="margin-bottom: 1.5rem;">
            <table>
              <thead>
                <tr>
                  <th>Product</th>
                  <th>Ordered</th>
                  <th>Prev Recv</th>
                  <th>Qty Received Now *</th>
                  <th>Qty Accepted *</th>
                  <th>Qty Rejected</th>
                  <th>Rejection Reason (if any)</th>
                  <th>Batch &amp; Expiry</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($po['items'] as $idx => $it):
                  $remaining = max(0, (int)$it['quantity_ordered'] - (int)$it['quantity_received']);
                  $isBatchTracked = !empty($it['requires_batch_tracking']);
                ?>
                  <tr>
                    <td>
                      <strong><?= e($it['product_name']) ?></strong>
                      <?php if ($isBatchTracked): ?>
                        <span class="badge secondary" style="font-size: 0.7rem; margin-left: 0.25rem;">Batch Tracked</span>
                      <?php endif; ?>
                      <input type="hidden" name="items[<?= $idx ?>][po_item_id]" value="<?= (int)$it['po_item_id'] ?>">
                      <input type="hidden" name="items[<?= $idx ?>][business_product_id]" value="<?= (int)$it['business_product_id'] ?>">
                    </td>
                    <td><?= (int)$it['quantity_ordered'] ?></td>
                    <td><?= (int)$it['quantity_received'] ?></td>
                    <td>
                      <input type="number" name="items[<?= $idx ?>][quantity_received]" min="0" value="<?= $remaining ?>" style="width: 80px;" required>
                    </td>
                    <td>
                      <input type="number" name="items[<?= $idx ?>][quantity_accepted]" min="0" value="<?= $remaining ?>" style="width: 80px;" required>
                    </td>
                    <td>
                      <input type="number" name="items[<?= $idx ?>][quantity_rejected]" min="0" value="0" style="width: 80px;">
                    </td>
                    <td>
                      <input type="text" name="items[<?= $idx ?>][rejection_reason]" placeholder="Reason if rejected..." style="width: 160px;">
                    </td>
                    <td>
                      <?php if ($isBatchTracked): ?>
                        <div style="display: flex; gap: 0.35rem; align-items: center; flex-wrap: wrap;">
                          <input type="text" name="items[<?= $idx ?>][batch_number]" placeholder="Batch #" required style="width: 100px;">
                          <input type="date" name="items[<?= $idx ?>][expiration_date]" style="width: 130px;" title="Expiration Date">
                        </div>
                      <?php else: ?>
                        <span class="caption muted">Standard item</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <div class="field">
            <label for="rcv-notes-<?= (int)$po['purchase_order_id'] ?>">Receiving Notes (Optional)</label>
            <input id="rcv-notes-<?= (int)$po['purchase_order_id'] ?>" name="notes" type="text" placeholder="e.g. Delivered by carrier, package inspected in good condition.">
          </div>

          <div class="modal-foot" style="margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid var(--border, #e5e7eb); display: flex; justify-content: flex-end; gap: 0.5rem;">
            <button type="button" class="btn ghost" data-modal-close>Cancel</button>
            <button type="submit" class="btn primary">Accept &amp; Update Inventory</button>
          </div>
        </form>
      </div>
    </dialog>
  <?php endforeach; ?>

  <!-- Purchase Order Request Details & Review Modals -->
  <?php foreach ($purchaseOrderRequests as $por): ?>
    <dialog class="modal modal-wide" id="view-por-<?= (int)$por['request_id'] ?>" aria-labelledby="view-por-title-<?= (int)$por['request_id'] ?>">
      <div class="modal-head">
        <div>
          <span class="badge" style="background: #e0f2fe; color: #0369a1; margin-bottom: 0.25rem;">Purchase Order Request</span>
          <h3 id="view-por-title-<?= (int)$por['request_id'] ?>" style="margin: 0;"><?= e($por['request_number']) ?></h3>
        </div>
        <button type="button" class="btn ghost btn-sm" data-modal-close aria-label="Close">&times;</button>
      </div>
      <div class="modal-body">
        <div class="grid grid-2" style="gap: 1rem; margin-bottom: 1.5rem; background: var(--bg-subtle, #f9fafb); padding: 1rem; border-radius: 6px;">
          <div>
            <p style="margin: 0 0 0.25rem 0;" class="caption">Request Number</p>
            <strong><?= e($por['request_number']) ?></strong>

            <p style="margin: 0.5rem 0 0.25rem 0;" class="caption">Date Submitted</p>
            <div><?= e(date('F j, Y · g:i A', strtotime((string)$por['request_date']))) ?></div>

            <p style="margin: 0.5rem 0 0.25rem 0;" class="caption">Status</p>
            <div>
              <?php
              $rst = (string)$por['request_status'];
              $rClass = $rst === 'Approved' ? 'badge-success' : ($rst === 'Rejected' ? 'badge-danger' : 'badge-warning');
              ?>
              <span class="badge <?= $rClass ?>"><?= e($rst) ?></span>
            </div>
          </div>
          <div>
            <p style="margin: 0 0 0.25rem 0;" class="caption">Requester</p>
            <strong><?= e($por['requester_name']) ?></strong>

            <p style="margin: 0.5rem 0 0.25rem 0;" class="caption">Reason &amp; Priority</p>
            <div><strong><?= e($por['reason']) ?></strong> · <span class="badge secondary"><?= e($por['priority']) ?> Priority</span></div>

            <?php if (!empty($por['needed_by_date'])): ?>
              <p style="margin: 0.5rem 0 0.25rem 0;" class="caption">Needed by Date</p>
              <div><?= e(date('M j, Y', strtotime((string)$por['needed_by_date']))) ?></div>
            <?php endif; ?>

            <?php if (!empty($por['reviewer_name'])): ?>
              <p style="margin: 0.5rem 0 0.25rem 0;" class="caption">Reviewed By</p>
              <div><strong><?= e($por['reviewer_name']) ?></strong> on <?= e(date('M j, Y · g:i A', strtotime((string)$por['reviewed_at']))) ?></div>
              <?php if (!empty($por['review_notes'])): ?>
                <div class="caption">Review Notes: <?= e($por['review_notes']) ?></div>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>

        <h4 style="margin: 1rem 0 0.5rem 0;">Requested Products</h4>
        <div class="table-wrap" style="margin-bottom: 1.5rem;">
          <table>
            <thead>
              <tr>
                <th>Product</th>
                <th>SKU</th>
                <th>Requested Qty</th>
                <th>On Hand</th>
                <th>Committed</th>
                <th>Available</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($por['items'] as $it): ?>
                <tr>
                  <td><strong><?= e($it['product_name']) ?></strong></td>
                  <td><code><?= e($it['sku'] ?: '—') ?></code></td>
                  <td><strong><?= (int)$it['quantity_requested'] ?></strong></td>
                  <td><?= (int)$it['on_hand'] ?></td>
                  <td><?= (int)$it['committed'] ?></td>
                  <td><strong><?= (int)$it['available'] ?></strong></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn ghost btn-sm" data-modal-close>Close</button>
      </div>
    </dialog>

    <?php if (!$isInvManager && $por['request_status'] === 'Pending'): ?>
      <dialog class="modal" id="review-por-<?= (int)$por['request_id'] ?>" aria-labelledby="review-por-title-<?= (int)$por['request_id'] ?>">
        <div class="modal-head">
          <div>
            <span class="badge secondary" style="margin-bottom: 0.25rem;">Business Manager Review</span>
            <h3 id="review-por-title-<?= (int)$por['request_id'] ?>" style="margin: 0;">Review <?= e($por['request_number']) ?></h3>
          </div>
          <button type="button" class="btn ghost btn-sm" data-modal-close aria-label="Close">&times;</button>
        </div>
        <div class="modal-body">
          <p style="margin-bottom: 1rem;">
            Review stock requisition submitted by <strong><?= e($por['requester_name']) ?></strong> for <strong><?= (int)$por['total_items'] ?> product(s)</strong> (<?= (int)$por['total_qty_requested'] ?> units).
          </p>

          <form method="post" action="/manager/inventory/purchase-order-requests/<?= (int)$por['request_id'] ?>/review<?= $unitQuery ?>">
            <?= \App\Core\CSRF::field() ?>

            <div class="field">
              <label class="caption" style="display: block; margin-bottom: 0.5rem;">Review Decision</label>
              <div class="row" style="gap: 1.5rem;">
                <label style="display: flex; align-items: center; gap: 0.4rem; cursor: pointer;">
                  <input type="radio" name="decision" value="approve" checked>
                  <strong>Approve Requisition</strong>
                </label>
                <label style="display: flex; align-items: center; gap: 0.4rem; cursor: pointer;">
                  <input type="radio" name="decision" value="reject">
                  <strong style="color: var(--danger, #dc2626);">Reject Requisition</strong>
                </label>
              </div>
            </div>

            <div class="field" style="margin-top: 1rem;">
              <label for="review-notes-<?= (int)$por['request_id'] ?>" class="caption" style="display: block; margin-bottom: 0.25rem;">Review Notes (Optional)</label>
              <textarea id="review-notes-<?= (int)$por['request_id'] ?>" name="review_notes" rows="3" style="width: 100%;" placeholder="e.g. Approved for procurement from vendor"></textarea>
            </div>

            <div class="modal-foot" style="margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid var(--border, #e5e7eb); display: flex; justify-content: flex-end; gap: 0.5rem;">
              <button type="button" class="btn ghost btn-sm" data-modal-close>Cancel</button>
              <button type="submit" class="btn primary btn-sm">Submit Decision</button>
            </div>
          </form>
        </div>
      </dialog>
    <?php endif; ?>
  <?php endforeach; ?>

<?php elseif ($invTab === 'movements'): ?>
  <!-- MOVEMENT HISTORY SECTION -->
  <section class="card">
    <div class="row" style="justify-content: space-between; align-items: baseline; margin-bottom: 1rem;">
      <div>
        <h2 style="margin: 0;"><?= e($unitName) ?> Movement History</h2>
        <p class="caption" style="margin: 0.2rem 0 0 0;">
          Audit trail of inventory movements, receiving events, manual adjustments, write-offs, and batch allocations.
        </p>
      </div>
      <span class="caption"><?= count($movements ?? []) ?> movements recorded</span>
    </div>

    <?php if (empty($movements)): ?>
      <div class="empty">
        No stock movements recorded<?= $q !== '' ? ' matching "' . e($q) . '"' : '' ?>.
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table style="width: 100%; min-width: 58rem; border-collapse: collapse;">
          <thead>
            <tr>
              <th style="white-space: nowrap; min-width: 120px;">Date &amp; Time</th>
              <th style="min-width: 150px;">Product</th>
              <th style="white-space: nowrap; min-width: 105px;">SKU / Code</th>
              <th style="white-space: nowrap; min-width: 110px;">Batch Number</th>
              <th style="white-space: nowrap; min-width: 110px;">Movement Type</th>
              <th style="text-align: right; white-space: nowrap; min-width: 80px;">Quantity</th>
              <th style="min-width: 180px;">Notes / Reason / Reference</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($movements as $m):
              $mQty = (int)$m['quantity'];
              $typeClass = match ($m['movement_type']) {
                'receiving' => 'badge-success',
                'adjustment_increase' => 'badge-info',
                'adjustment_decrease' => 'badge-warning',
                'write_off_expired' => 'badge-danger',
                'fefo_allocation' => 'secondary',
                default => 'secondary'
              };
            ?>
              <tr>
                <td style="white-space: nowrap; color: var(--muted); font-size: 0.85rem;">
                  <?= date('M j, Y · g:i A', strtotime((string)$m['movement_date'])) ?>
                </td>
                <td>
                  <strong><?= e($m['product_name']) ?></strong>
                </td>
                <td><code><?= e($m['sku'] ?: '—') ?></code></td>
                <td>
                  <?= !empty($m['batch_number']) ? '<code>' . e($m['batch_number']) . '</code>' : '<span class="caption muted">—</span>' ?>
                </td>
                <td>
                  <span class="badge <?= e($typeClass) ?>" style="font-size: 0.75rem;">
                    <?= e(str_replace('_', ' ', (string)$m['movement_type'])) ?>
                  </span>
                </td>
                <td style="text-align: right; font-weight: 700; color: <?= $mQty > 0 ? 'var(--primary, #059669)' : 'var(--danger, #dc2626)' ?>;">
                  <?= $mQty > 0 ? ('+' . $mQty) : $mQty ?>
                </td>
                <td style="font-size: 0.85rem; color: var(--ink);">
                  <?= e($m['notes'] ?: '—') ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
<?php endif; ?>

<!-- MULTI-STEP PURCHASE ORDER CREATION MODAL -->
<dialog class="modal modal-wide" id="po-create-modal" aria-labelledby="po-modal-title">
  <div class="modal-head">
    <div>
      <span class="badge secondary" style="margin-bottom: 0.25rem;">Multi-Step Order</span>
      <h3 id="po-modal-title" style="margin: 0;">Create Purchase Order</h3>
    </div>
    <button type="button" class="btn ghost btn-sm" data-modal-close aria-label="Close">&times;</button>
  </div>
  <div class="modal-body">
    <!-- Step Indicator -->
    <div style="display: flex; gap: 1rem; margin-bottom: 1.5rem; border-bottom: 1px solid var(--border, #e5e7eb); padding-bottom: 0.75rem;">
      <div id="step-1-indicator" style="font-weight: 600; color: var(--primary, #0284c7);">
        Step 1: Product Selection
      </div>
      <div style="color: var(--muted, #9ca3af);">&rarr;</div>
      <div id="step-2-indicator" style="color: var(--muted, #9ca3af);">
        Step 2: Supplier Information
      </div>
    </div>

    <form method="post" action="/manager/inventory/purchase-orders<?= $unitQuery ?>" id="multi-step-po-form">
      <?= \App\Core\CSRF::field() ?>

      <!-- STEP 1: PRODUCT SELECTION -->
      <div id="po-step-1">
        <p class="caption" style="margin-bottom: 1rem;">Select products and quantities to include in this purchase order. Continue is enabled once at least one product has been added.</p>

        <div id="po-items-container" style="margin-bottom: 1rem;">
          <!-- Dynamically populated or empty initially -->
        </div>

        <div style="margin-bottom: 1.5rem;">
          <button type="button" class="btn secondary btn-sm" id="po-add-product-btn">
            + Add Product
          </button>
        </div>

        <div class="modal-foot" style="display: flex; justify-content: flex-end; gap: 0.5rem; padding-top: 1rem; border-top: 1px solid var(--border, #e5e7eb);">
          <button type="button" class="btn ghost" data-modal-close>Cancel</button>
          <button type="button" class="btn primary" id="po-step-1-continue-btn" disabled>
            Continue to Supplier Info &rarr;
          </button>
        </div>
      </div>

      <!-- STEP 2: SUPPLIER INFORMATION -->
      <div id="po-step-2" style="display: none;">
        <p class="caption" style="margin-bottom: 1rem;">
          Enter supplier details. Supplier Name and Contact Person are required. At least one contact method (Email or Phone) is required.
        </p>

        <div class="grid grid-2" style="gap: 1rem; margin-bottom: 1rem;">
          <div class="field" style="margin-bottom: 0;">
            <label for="po-supplier-name">Supplier Name *</label>
            <input id="po-supplier-name" name="supplier_name" type="text" required placeholder="e.g. Metro Pet Distribution Corp.">
          </div>
          <div class="field" style="margin-bottom: 0;">
            <label for="po-contact-person">Contact Person *</label>
            <input id="po-contact-person" name="contact_person" type="text" required placeholder="e.g. Maria Santos">
          </div>
        </div>

        <div class="grid grid-2" style="gap: 1rem; margin-bottom: 1rem;">
          <div class="field" style="margin-bottom: 0;">
            <label for="po-supplier-email">Supplier Email (Email OR Phone required)</label>
            <input id="po-supplier-email" name="supplier_email" type="email" placeholder="e.g. orders@metropet.ph">
          </div>
          <div class="field" style="margin-bottom: 0;">
            <label for="po-supplier-phone">Supplier Phone (Email OR Phone required)</label>
            <input id="po-supplier-phone" name="supplier_phone" type="tel" placeholder="e.g. 0917-123-4567 or 8123-4567">
          </div>
        </div>

        <div class="field" style="margin-bottom: 1rem;">
          <label for="po-notes">Order Notes (Optional)</label>
          <input id="po-notes" name="notes" type="text" placeholder="e.g. Routine monthly replenishment shipment">
        </div>

        <!-- Non-editable confirmation summary -->
        <div style="background: var(--bg-subtle, #f9fafb); padding: 0.75rem 1rem; border-radius: 6px; margin-bottom: 1rem;">
          <div class="caption">System-generated upon confirmation:</div>
          <div class="row" style="gap: 1.5rem; margin-top: 0.25rem; font-size: 0.85rem;">
            <span>PO Number: <strong>[Auto-generated]</strong></span>
            <span>Order Date: <strong><?= date('M j, Y') ?></strong></span>
            <span>Created By: <strong><?= e(\App\Core\Session::get('email', 'Current User')) ?></strong></span>
            <span>Initial Status: <strong>Ordered</strong></span>
          </div>
        </div>

        <div class="modal-foot" style="display: flex; justify-content: space-between; gap: 0.5rem; padding-top: 1rem; border-top: 1px solid var(--border, #e5e7eb);">
          <button type="button" class="btn ghost" id="po-step-2-back-btn">&larr; Back to Products</button>
          <div class="row" style="gap: 0.5rem;">
            <button type="button" class="btn ghost" data-modal-close>Cancel</button>
            <button type="submit" class="btn primary" id="po-confirm-order-btn" disabled>
              Confirm Order
            </button>
          </div>
        </div>
      </div>
    </form>
  </div>
</dialog>

<!-- Template for PO Product Options -->
<template id="po-product-options-template">
  <option value="">-- Choose Product --</option>
  <?php foreach ($catalogProducts as $cp): ?>
    <option value="<?= (int)$cp['business_product_id'] ?>" data-price="<?= (float)$cp['selling_price'] ?>">
      <?= e($cp['product_name']) ?> (<?= e($cp['sku'] ?: 'No SKU') ?>)
    </option>
  <?php endforeach; ?>
</template>

<!-- PURCHASE ORDER REQUEST MODAL (FOR INVENTORY MANAGER & BM) -->
<dialog class="modal modal-wide" id="po-request-modal" aria-labelledby="por-modal-title">
  <div class="modal-head">
    <div>
      <span class="badge" style="background: #e0f2fe; color: #0369a1; margin-bottom: 0.25rem;">Stock Requisition</span>
      <h3 id="por-modal-title" style="margin: 0;">Request Purchase Order</h3>
    </div>
    <button type="button" class="btn ghost btn-sm" data-modal-close aria-label="Close">&times;</button>
  </div>
  <div class="modal-body">
    <p class="caption" style="margin-bottom: 1.25rem;">
      Submit a stock replenishment requisition for Business Manager review. Submitting a request does not increase inventory, reserve stock, or issue an actual purchase order until approved.
    </p>

    <form method="post" action="/manager/inventory/purchase-order-requests<?= $unitQuery ?>" id="por-form">
      <?= \App\Core\CSRF::field() ?>

      <!-- Request Meta Details -->
      <div class="grid grid-2" style="gap: 1rem; margin-bottom: 1.25rem; background: var(--bg-subtle, #f9fafb); padding: 1rem; border-radius: 6px;">
        <div>
          <span class="caption" style="display: block;">Request Number</span>
          <code style="font-weight: 600; color: var(--primary, #0284c7);">System-generated upon submission</code>

          <span class="caption" style="display: block; margin-top: 0.5rem;">Request Date</span>
          <strong><?= e(date('F j, Y')) ?></strong>
        </div>
        <div>
          <span class="caption" style="display: block;">Requester</span>
          <strong><?= e($currentEmployeeName ?? 'Current Employee') ?></strong>

          <span class="caption" style="display: block; margin-top: 0.5rem;">Business Unit</span>
          <strong><?= e($unitName) ?></strong>
        </div>
      </div>

      <!-- Requested Products -->
      <h4 style="margin: 1rem 0 0.5rem 0;">Requested Products</h4>
      <p class="caption" style="margin-bottom: 0.75rem;">Select products and enter requested quantities. Current inventory stock levels (On Hand, Committed, Available) are displayed for reference.</p>

      <div id="por-items-container" style="margin-bottom: 1rem;">
        <!-- Dynamically populated rows -->
      </div>

      <div style="margin-bottom: 1.5rem;">
        <button type="button" class="btn secondary btn-sm" id="por-add-product-btn">
          + Add Product
        </button>
      </div>

      <!-- Reason & Priority -->
      <div class="grid grid-2" style="gap: 1rem; margin-bottom: 1rem;">
        <div class="field" style="margin: 0;">
          <label for="por-reason" class="caption" style="display: block; margin-bottom: 0.25rem;">Reason for Request <span style="color: var(--danger, #dc2626);">*</span></label>
          <select id="por-reason" name="reason" required style="width: 100%;">
            <option value="">Select a reason...</option>
            <?php if ((int)$unitId === 3): ?>
              <option value="Low Stock">Low Stock</option>
              <option value="Increased Usage">Increased Usage</option>
              <option value="Increased Service Demand">Increased Service Demand</option>
              <option value="Routine Restocking">Routine Restocking</option>
              <option value="Damaged / Unusable Supplies">Damaged / Unusable Supplies</option>
              <option value="New Service Requirements">New Service Requirements</option>
            <?php else: ?>
              <option value="Low stock replenishment">Low stock replenishment</option>
              <option value="Out of stock emergency">Out of stock emergency</option>
              <option value="Upcoming seasonal demand">Upcoming seasonal demand</option>
              <option value="Anticipated customer pre-orders">Anticipated customer pre-orders</option>
              <option value="Supplier minimum order quantity threshold">Supplier minimum order quantity threshold</option>
              <option value="Routine stock replenishment">Routine stock replenishment</option>
            <?php endif; ?>
          </select>
        </div>

        <div class="field" style="margin: 0;">
          <label for="por-priority" class="caption" style="display: block; margin-bottom: 0.25rem;">Priority</label>
          <select id="por-priority" name="priority" style="width: 100%;">
            <option value="Low">Low</option>
            <option value="Medium" selected>Medium</option>
            <option value="High">High</option>
            <option value="Urgent">Urgent</option>
          </select>
        </div>
      </div>

      <div class="grid grid-2" style="gap: 1rem; margin-bottom: 1.5rem;">
        <div class="field" style="margin: 0;">
          <label for="por-needed-by" class="caption" style="display: block; margin-bottom: 0.25rem;">Needed-by Date (Optional)</label>
          <input id="por-needed-by" name="needed_by_date" type="date" min="<?= date('Y-m-d') ?>" style="width: 100%;">
        </div>

        <div class="field" style="margin: 0;">
          <label for="por-notes" class="caption" style="display: block; margin-bottom: 0.25rem;">Additional Notes (Optional)</label>
          <input id="por-notes" name="notes" type="text" placeholder="e.g. Preferred delivery window or special notes" style="width: 100%;">
        </div>
      </div>

      <div class="modal-foot" style="display: flex; justify-content: flex-end; gap: 0.5rem; padding-top: 1rem; border-top: 1px solid var(--border, #e5e7eb);">
        <button type="button" class="btn ghost" data-modal-close>Cancel</button>
        <button type="submit" class="btn primary" id="por-submit-btn" disabled>
          Submit Purchase Order Request
        </button>
      </div>
    </form>
  </div>
</dialog>

<!-- Datalist for Request Product Selection (Product Name & Specifications only, NO SKU and NO on-hand/committed/available) -->
<datalist id="por-products-datalist">
  <?php
    $uniqueNames = [];
    foreach ($catalogProducts as $cp):
      $name = trim((string)$cp['product_name']);
      if ($name === '' || isset($uniqueNames[$name])) continue;
      $uniqueNames[$name] = true;
  ?>
    <option value="<?= e($name) ?>"></option>
  <?php endforeach; ?>
</datalist>

<!-- Datalist for Product SKU -->
<datalist id="por-skus-datalist">
  <?php
    $uniqueSkus = [];
    foreach ($catalogProducts as $cp):
      $sku = trim((string)($cp['sku'] ?? ''));
      if ($sku === '' || isset($uniqueSkus[$sku])) continue;
      $uniqueSkus[$sku] = true;
  ?>
    <option value="<?= e($sku) ?>"></option>
  <?php endforeach; ?>
</datalist>

<script>
  document.addEventListener('DOMContentLoaded', function() {
    var porItemsContainer = document.getElementById('por-items-container');
    var porAddBtn = document.getElementById('por-add-product-btn');
    var porSubmitBtn = document.getElementById('por-submit-btn');
    var porReasonSelect = document.getElementById('por-reason');
    var porItemIndex = 0;

    var porCatalog = <?= json_encode(array_values(array_map(function($cp) {
        return [
            'id' => (int)$cp['business_product_id'],
            'name' => (string)$cp['product_name'],
            'sku' => (string)($cp['sku'] ?? ''),
            'on_hand' => (int)$cp['on_hand'],
            'committed' => (int)$cp['committed'],
            'available' => (int)$cp['available'],
        ];
    }, $catalogProducts)), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

    function findProductByName(name) {
      if (!name) return null;
      var term = name.trim().toLowerCase();
      for (var i = 0; i < porCatalog.length; i++) {
        if (porCatalog[i].name.toLowerCase() === term) {
          return porCatalog[i];
        }
      }
      return null;
    }

    function findProductBySku(sku) {
      if (!sku) return null;
      var term = sku.trim().toLowerCase();
      for (var i = 0; i < porCatalog.length; i++) {
        if (porCatalog[i].sku.toLowerCase() === term) {
          return porCatalog[i];
        }
      }
      return null;
    }

    function checkPorValidity() {
      if (!porItemsContainer || !porSubmitBtn) return;
      var rows = porItemsContainer.querySelectorAll('.por-item-row');
      var hasValidProduct = false;
      rows.forEach(function(row) {
        var bpidInput = row.querySelector('.por-bpid-input');
        var qtyInput = row.querySelector('.por-qty-input');
        if (bpidInput && parseInt(bpidInput.value, 10) > 0 && qtyInput && parseInt(qtyInput.value, 10) > 0) {
          hasValidProduct = true;
        }
      });
      var reasonValid = porReasonSelect && porReasonSelect.value !== '';
      porSubmitBtn.disabled = !(hasValidProduct && reasonValid);
    }

    function addPorRow() {
      if (!porItemsContainer) return;
      var idx = porItemIndex++;
      var row = document.createElement('div');
      row.className = 'por-item-row';
      row.style.cssText = 'display: flex; gap: 0.65rem; align-items: flex-end; margin-bottom: 0.75rem; background: var(--bg-subtle, #f9fafb); padding: 0.85rem; border-radius: 6px; border: 1px solid var(--border, #e5e7eb); flex-wrap: wrap;';

      row.innerHTML = '' +
        '<input type="hidden" name="items[' + idx + '][business_product_id]" class="por-bpid-input" value="">' +
        '<div style="flex: 2; min-width: 220px;">' +
          '<label class="caption" style="display: block; margin-bottom: 0.25rem;">Product Name &amp; Specifications</label>' +
          '<input type="text" list="por-products-datalist" class="por-prod-search" placeholder="Type product name or specs..." autocomplete="off" style="width: 100%;">' +
        '</div>' +
        '<div style="width: 150px;">' +
          '<label class="caption" style="display: block; margin-bottom: 0.25rem;">SKU</label>' +
          '<input type="text" list="por-skus-datalist" class="por-sku-input" placeholder="e.g. SKU-..." autocomplete="off" style="width: 100%; font-family: monospace;">' +
        '</div>' +
        '<div style="width: 75px;">' +
          '<label class="caption" style="display: block; margin-bottom: 0.25rem; text-align: center;">On Hand</label>' +
          '<input type="text" class="por-onhand-input" readonly placeholder="0" style="width: 100%; text-align: center; background: var(--bg-subtle, #f3f4f6); font-weight: 600;">' +
        '</div>' +
        '<div style="width: 75px;">' +
          '<label class="caption" style="display: block; margin-bottom: 0.25rem; text-align: center;">Committed</label>' +
          '<input type="text" class="por-committed-input" readonly placeholder="0" style="width: 100%; text-align: center; background: var(--bg-subtle, #f3f4f6); font-weight: 600;">' +
        '</div>' +
        '<div style="width: 75px;">' +
          '<label class="caption" style="display: block; margin-bottom: 0.25rem; text-align: center;">Available</label>' +
          '<input type="text" class="por-available-input" readonly placeholder="0" style="width: 100%; text-align: center; background: var(--bg-subtle, #f3f4f6); font-weight: 700; color: var(--primary, #0284c7);">' +
        '</div>' +
        '<div style="width: 85px;">' +
          '<label class="caption" style="display: block; margin-bottom: 0.25rem; text-align: center;">Quantity</label>' +
          '<input type="number" name="items[' + idx + '][quantity_requested]" class="por-qty-input" min="1" value="10" required style="width: 100%; text-align: center;">' +
        '</div>' +
        '<div style="flex: 1; min-width: 130px;">' +
          '<label class="caption" style="display: block; margin-bottom: 0.25rem;">Notes (Optional)</label>' +
          '<input type="text" name="items[' + idx + '][notes]" class="por-notes-input" placeholder="Optional notes" style="width: 100%;">' +
        '</div>' +
        '<div style="padding-bottom: 2px;">' +
          '<button type="button" class="btn ghost btn-sm por-remove-row-btn" style="color: var(--danger, #dc2626);" title="Remove product">&times;</button>' +
        '</div>';

      porItemsContainer.appendChild(row);

      var prodInput = row.querySelector('.por-prod-search');
      var skuInput = row.querySelector('.por-sku-input');
      var bpidInput = row.querySelector('.por-bpid-input');
      var onHandInput = row.querySelector('.por-onhand-input');
      var committedInput = row.querySelector('.por-committed-input');
      var availableInput = row.querySelector('.por-available-input');
      var qtyInput = row.querySelector('.por-qty-input');
      var removeBtn = row.querySelector('.por-remove-row-btn');

      function syncFromProduct() {
        var p = findProductByName(prodInput.value);
        if (p) {
          bpidInput.value = p.id;
          skuInput.value = p.sku;
          onHandInput.value = p.on_hand;
          committedInput.value = p.committed;
          availableInput.value = p.available;
        } else if (prodInput.value.trim() === '') {
          bpidInput.value = '';
          skuInput.value = '';
          onHandInput.value = '';
          committedInput.value = '';
          availableInput.value = '';
        }
        checkPorValidity();
      }

      function syncFromSku() {
        var p = findProductBySku(skuInput.value);
        if (p) {
          bpidInput.value = p.id;
          prodInput.value = p.name;
          onHandInput.value = p.on_hand;
          committedInput.value = p.committed;
          availableInput.value = p.available;
        } else if (skuInput.value.trim() === '') {
          bpidInput.value = '';
          onHandInput.value = '';
          committedInput.value = '';
          availableInput.value = '';
        }
        checkPorValidity();
      }

      prodInput.addEventListener('input', syncFromProduct);
      prodInput.addEventListener('change', syncFromProduct);

      skuInput.addEventListener('input', syncFromSku);
      skuInput.addEventListener('change', syncFromSku);

      qtyInput.addEventListener('input', checkPorValidity);
      qtyInput.addEventListener('change', checkPorValidity);

      removeBtn.addEventListener('click', function() {
        row.remove();
        checkPorValidity();
      });

      checkPorValidity();
    }

    if (porAddBtn) {
      porAddBtn.addEventListener('click', addPorRow);
    }
    if (porReasonSelect) {
      porReasonSelect.addEventListener('change', checkPorValidity);
    }

    // Auto-seed one product row when opening modal
    document.querySelectorAll('[data-modal-open="po-request-modal"]').forEach(function(btn) {
      btn.addEventListener('click', function() {
        if (porItemsContainer && porItemsContainer.children.length === 0) {
          addPorRow();
        }
      });
    });

    // Return reason inspection toggle in stock increase modals
    document.querySelectorAll('.inc-reason-select').forEach(function(sel) {
      sel.addEventListener('change', function() {
        var form = sel.closest('form');
        var wrap = form ? form.querySelector('.return-inspection-wrap') : null;
        if (wrap) {
          var isReturn = sel.value.indexOf('Returned product') !== -1;
          wrap.style.display = isReturn ? 'block' : 'none';
          var chk = wrap.querySelector('.inspection-checkbox');
          if (chk && !isReturn) {
            chk.checked = false;
          }
        }
      });
    });
  });
</script>

<?php if (!empty($canManageInventoryKey) && !empty($activeInventoryKey)): ?>
  <?php
    $remSecs = max(0, (int)($activeInventoryKey['seconds_remaining'] ?? 0));
    $remMins = intdiv($remSecs, 60);
    $remSecPart = $remSecs % 60;
    $remDisplay = $remMins > 0 ? "{$remMins}m {$remSecPart}s" : "{$remSecPart}s";
  ?>
  <dialog class="modal"
          id="inventory-key-modal"
          aria-labelledby="inv-key-modal-title"
          <?= !empty($autoOpenKeyModal) ? 'data-open-on-load="true"' : '' ?>>
    <div class="modal-head">
      <div>
        <span class="badge badge-success" style="margin-bottom: 0.25rem;">Active Temporary Key</span>
        <h3 id="inv-key-modal-title" style="margin: 0;">Inventory Access Key</h3>
      </div>
      <button type="button" class="btn ghost btn-sm" data-modal-close aria-label="Close">&times;</button>
    </div>
    <div class="modal-body">
      <div class="inv-key-code-box">
        <div>
          <div class="caption" style="margin-bottom: 0.2rem;">Temporary Access Key</div>
          <code id="inv-key-code-value" class="inv-key-code-text"><?= e($activeInventoryKey['key_code']) ?></code>
        </div>
        <div style="display: flex; gap: 0.5rem; align-items: center;">
          <button type="button"
                  class="btn danger btn-sm"
                  id="modal-revoke-inv-key-btn"
                  data-modal-open="revoke-key-modal"
                  data-revoke-key-id="<?= (int)$activeInventoryKey['key_id'] ?>"
                  data-revoke-key-code="<?= e($activeInventoryKey['key_code']) ?>"
                  data-revoke-expires="<?= e($activeInventoryKey['expires_at_formatted'] ?? '') ?>">
            Revoke Key
          </button>
          <button type="button"
                  class="btn secondary btn-sm"
                  id="copy-inv-key-btn"
                  data-copy-key="<?= e($activeInventoryKey['key_code']) ?>">
            Copy Key
          </button>
        </div>
      </div>

      <div class="inv-key-details-grid">
        <div class="inv-key-detail-row">
          <span class="caption">Business Unit</span>
          <strong><?= e($activeInventoryKey['business_unit_name'] ?? $unitName) ?></strong>
        </div>
        <div class="inv-key-detail-row">
          <span class="caption">Generated By</span>
          <strong><?= e($activeInventoryKey['created_by_name'] ?? 'Business Manager') ?></strong>
        </div>
        <div class="inv-key-detail-row">
          <span class="caption">Generated At</span>
          <span><?= e($activeInventoryKey['created_at_formatted'] ?? $activeInventoryKey['created_at']) ?></span>
        </div>
        <div class="inv-key-detail-row">
          <span class="caption">Expires At</span>
          <span><?= e($activeInventoryKey['expires_at_formatted'] ?? $activeInventoryKey['expires_at']) ?></span>
        </div>
        <div class="inv-key-detail-row">
          <span class="caption">Remaining Validity</span>
          <strong id="inv-key-countdown" data-seconds-remaining="<?= $remSecs ?>"><?= e($remDisplay) ?></strong>
        </div>
      </div>
    </div>
    <div class="modal-foot" style="display: flex; justify-content: flex-end; align-items: center; gap: 0.5rem;">
      <button type="button" class="btn primary btn-sm" data-modal-close>Done</button>
    </div>
  </dialog>
<?php endif; ?>

<?php if (!empty($canManageInventoryKey)): ?>
  <!-- Revocation Confirmation Dialog -->
  <dialog class="modal" id="revoke-key-modal" aria-labelledby="revoke-key-modal-title" style="max-width: 520px;">
    <div class="modal-head">
      <div>
        <span class="badge badge-danger" style="margin-bottom: 0.25rem;">Confirm Revocation</span>
        <h3 id="revoke-key-modal-title" style="margin: 0; color: var(--danger, #dc2626);">Revoke Inventory Access Key</h3>
      </div>
      <button type="button" class="btn ghost btn-sm" data-modal-close aria-label="Close">&times;</button>
    </div>
    <div class="modal-body">
      <p style="font-size: 0.95rem; margin: 0 0 1rem 0; line-height: 1.5;">
        Are you sure you want to revoke this Inventory Access Key?
      </p>
      <div style="background: var(--surface-subtle, #f8fafc); border: 1px solid var(--line); border-radius: var(--radius-sm); padding: 0.85rem 1rem; margin-bottom: 1rem;">
        <div style="margin-bottom: 0.35rem;">
          <span class="caption" style="color: var(--muted); display: block;">Key Code</span>
          <code class="inv-key-code-text" style="font-size: 1.15rem; font-weight: 700;" id="revoke-confirm-key-code">
            <?= e($activeInventoryKey['key_code'] ?? '—') ?>
          </code>
        </div>
        <div style="display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.5rem; margin-top: 0.5rem; border-top: 1px solid var(--line); padding-top: 0.5rem;">
          <div>
            <span class="caption" style="color: var(--muted); display: block;">Business Unit</span>
            <strong><?= e($unitName) ?></strong>
          </div>
          <div>
            <span class="caption" style="color: var(--muted); display: block;">Expires At</span>
            <span id="revoke-confirm-expires"><?= e($activeInventoryKey['expires_at_formatted'] ?? '—') ?></span>
          </div>
        </div>
      </div>
      <p class="caption muted" style="margin: 0; line-height: 1.45;">
        Revoking this key immediately invalidates it. Any employee currently logged into temporary inventory access using this key will immediately have their access terminated. The key record will remain preserved in historical logs.
      </p>
    </div>
    <div class="modal-foot" style="display: flex; justify-content: flex-end; gap: 0.5rem;">
      <button type="button" class="btn ghost btn-sm" data-modal-close>Cancel</button>
      <form method="post" action="/manager/inventory/revoke-key<?= $unitQuery ?>" style="margin: 0;" id="confirm-revoke-key-form">
        <?= \App\Core\CSRF::field() ?>
        <input type="hidden" name="key_id" id="revoke-confirm-key-id" value="<?= (int)($activeInventoryKey['key_id'] ?? 0) ?>">
        <input type="hidden" name="return_tab" value="<?= e($invTab) ?>">
        <button type="submit" class="btn danger btn-sm" id="confirm-revoke-key-btn">
          Confirm Revocation
        </button>
      </form>
    </div>
  </dialog>

  <script>
    document.addEventListener('DOMContentLoaded', function() {
      document.querySelectorAll('[data-revoke-key-id]').forEach(function(btn) {
        btn.addEventListener('click', function() {
          var kid = btn.getAttribute('data-revoke-key-id');
          var kcode = btn.getAttribute('data-revoke-key-code');
          var kexp = btn.getAttribute('data-revoke-expires');
          var idInput = document.getElementById('revoke-confirm-key-id');
          var codeEl = document.getElementById('revoke-confirm-key-code');
          var expEl = document.getElementById('revoke-confirm-expires');
          if (idInput && kid) idInput.value = kid;
          if (codeEl && kcode) codeEl.textContent = kcode;
          if (expEl && kexp) expEl.textContent = kexp;
        });
      });
    });
  </script>
<?php endif; ?>

