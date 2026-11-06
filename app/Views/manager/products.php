<?php
require __DIR__ . '/_nav.php';
$q = $searchQuery ?? '';
$catFilter = $categoryFilter ?? '';
$statusFilter = $statusFilter ?? '';
?>

<div class="page-head">
  <div>
    <p class="eyebrow">Catalog Administration</p>
    <h1>Products</h1>
    <p class="muted">Manage Pet Store retail catalog items, product codes, descriptions, and pricing.</p>
  </div>
</div>

<!-- Search & Filtering Bar (Automatic Filtering on Selection; No Green Filter Button) -->
<section class="card" style="margin-bottom: 1.5rem; padding: 1rem 1.25rem;">
  <form method="get" action="/manager/products" class="row" style="gap: 1rem; align-items: flex-end; flex-wrap: wrap;">
    <?php if (!empty($isSuper)): ?>
      <input type="hidden" name="unit_id" value="<?= (int)$unitId ?>">
    <?php endif; ?>

    <div class="field" style="margin: 0; min-width: 220px; flex: 1;">
      <label for="prod-search" class="caption" style="display: block; margin-bottom: 0.25rem;">Search Name or SKU</label>
      <div class="search-input-wrap">
        <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <circle cx="11" cy="11" r="8"></circle>
          <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
        </svg>
        <input id="prod-search" type="search" name="q" value="<?= e($q) ?>" placeholder="Search Name or SKU... (Press Enter)" class="search-input">
      </div>
    </div>

    <div class="field" style="margin: 0; width: 180px;">
      <label for="prod-cat" class="caption" style="display: block; margin-bottom: 0.25rem;">Category</label>
      <select id="prod-cat" name="category" style="width: 100%;" onchange="this.form.submit()">
        <option value="">All Categories</option>
        <?php foreach ($categories as $cat): ?>
          <option value="<?= e($cat) ?>" <?= $catFilter === $cat ? 'selected' : '' ?>><?= e($cat) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field" style="margin: 0; width: 150px;">
      <label for="prod-status" class="caption" style="display: block; margin-bottom: 0.25rem;">Status</label>
      <select id="prod-status" name="status" style="width: 100%;" onchange="this.form.submit()">
        <option value="">All Statuses</option>
        <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active Only</option>
        <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive Only</option>
      </select>
    </div>

    <?php if ($q !== '' || $catFilter !== '' || $statusFilter !== ''): ?>
      <div class="row" style="align-items: center;">
        <a href="/manager/products<?= $unitQuery ?>" class="btn ghost">Reset</a>
      </div>
    <?php endif; ?>
  </form>
</section>

<!-- Product Catalog Table -->
<section class="card">
  <div class="row" style="justify-content: space-between; align-items: baseline; margin-bottom: 1rem;">
    <div>
      <h2 style="margin: 0;">Product Catalog (<?= count($products) ?>)</h2>
      <span class="caption">Inventory quantities and stock operations are managed on the separate <a href="/manager/inventory<?= $unitQuery ?>">Inventory</a> page.</span>
    </div>
    <div>
      <button type="button" class="btn secondary btn-sm" data-modal-open="add-product-modal">
        + Add Product
      </button>
    </div>
  </div>

  <?php if (empty($products)): ?>
    <div class="empty">No products found matching the current filters.</div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th style="width: 56px;">Image</th>
            <th>Product Name</th>
            <th>SKU / Code</th>
            <th>Category</th>
            <th>Selling Price</th>
            <th>Status</th>
            <th style="text-align: right;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($products as $p): ?>
            <tr>
              <td>
                <div style="width: 44px; height: 44px; border-radius: 6px; background: var(--bg-subtle, #f3f4f6); border: 1px solid var(--border, #e5e7eb); display: flex; align-items: center; justify-content: center; overflow: hidden;">
                  <?php if (!empty($p['image_url'])): ?>
                    <img src="<?= e($p['image_url']) ?>" alt="<?= e($p['product_name']) ?>" style="width: 100%; height: 100%; object-fit: cover;">
                  <?php else: ?>
                    <span style="font-size: 1.25rem;" aria-hidden="true">📦</span>
                  <?php endif; ?>
                </div>
              </td>
              <td>
                <strong><?= e($p['product_name']) ?></strong>
                <?php if (!empty($p['description'])): ?>
                  <div class="caption" style="max-width: 320px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                    <?= e($p['description']) ?>
                  </div>
                <?php endif; ?>
              </td>
              <td><code><?= e($p['sku'] ?: '—') ?></code></td>
              <td><span class="badge secondary"><?= e($p['product_category']) ?></span></td>
              <td><strong><?= peso((float)$p['selling_price']) ?></strong></td>
              <td>
                <span class="badge <?= $p['product_status'] === 'active' ? 'badge-success' : 'badge-danger' ?>">
                  <?= e(ucfirst((string)$p['product_status'])) ?>
                </span>
              </td>
              <td style="text-align: right;">
                <div class="row" style="gap: 0.35rem; justify-content: flex-end;">
                  <button type="button" class="btn ghost btn-sm" data-modal-open="view-prod-<?= (int)$p['business_product_id'] ?>">
                    View
                  </button>
                  <button type="button" class="btn secondary btn-sm" data-modal-open="edit-prod-<?= (int)$p['business_product_id'] ?>">
                    Edit
                  </button>
                  <button type="button" class="btn <?= $p['product_status'] === 'active' ? 'danger' : 'ghost' ?> btn-sm" data-modal-open="confirm-status-modal-<?= (int)$p['business_product_id'] ?>">
                    <?= $p['product_status'] === 'active' ? 'Deactivate' : 'Activate' ?>
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

<!-- Add Product Modal -->
<dialog class="modal modal-wide" id="add-product-modal" aria-labelledby="add-prod-title">
  <div class="modal-head">
    <h3 id="add-prod-title">Add New Product to Pet Store</h3>
    <button type="button" class="btn ghost btn-sm" data-modal-close>&times; Close</button>
  </div>
  <div class="modal-body">
    <form method="post" action="/manager/products<?= $unitQuery ?>" enctype="multipart/form-data">
      <?= \App\Core\CSRF::field() ?>

      <div class="grid grid-2">
        <div class="field">
          <label for="new-prod-name">Product Name *</label>
          <input id="new-prod-name" name="product_name" type="text" required placeholder="e.g. Grain-Free Puppy Chow 1kg">
        </div>

        <div class="field">
          <label for="new-prod-sku">SKU / Product Code *</label>
          <input id="new-prod-sku" name="sku" type="text" required placeholder="e.g. SKU-PS-PUP-005">
        </div>
      </div>

      <div class="grid grid-2">
        <div class="field">
          <label for="new-prod-cat">Category *</label>
          <input id="new-prod-cat" name="product_category" type="text" required list="cat-suggestions" placeholder="e.g. Food, Toys, Grooming">
          <datalist id="cat-suggestions">
            <option value="Food &amp; Treats">
            <option value="Toys &amp; Enrichment">
            <option value="Grooming &amp; Hygiene">
            <option value="Health &amp; Wellness">
            <option value="Accessories &amp; Bedding">
          </datalist>
        </div>

        <div class="field">
          <label for="new-prod-price">Selling Price (₱) *</label>
          <input id="new-prod-price" name="selling_price" type="number" step="0.01" min="0.01" required placeholder="0.00">
        </div>
      </div>

      <div class="field">
        <label for="new-prod-desc">Description</label>
        <textarea id="new-prod-desc" name="description" rows="3" placeholder="Provide retail product description..."></textarea>
      </div>

      <div class="field">
        <label for="new-prod-img">Product Image File (Optional)</label>
        <input id="new-prod-img" name="product_image" type="file" accept="image/png, image/jpeg, image/jpg, image/webp, image/gif">
        <span class="caption">Supported formats: PNG, JPG, WebP, GIF (Max 5MB).</span>
      </div>

      <div class="modal-foot" style="margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid var(--border, #e5e7eb); display: flex; justify-content: flex-end; gap: 0.5rem;">
        <button type="button" class="btn ghost" data-modal-close>Cancel</button>
        <button type="submit" class="btn primary">Create Product</button>
      </div>
    </form>
  </div>
</dialog>

<!-- View & Edit Modals per Product -->
<?php foreach ($products as $p): ?>
  <!-- View Details Modal -->
  <dialog class="modal" id="view-prod-<?= (int)$p['business_product_id'] ?>" aria-labelledby="v-title-<?= (int)$p['business_product_id'] ?>">
    <div class="modal-head">
      <h3 id="v-title-<?= (int)$p['business_product_id'] ?>"><?= e($p['product_name']) ?></h3>
      <button type="button" class="btn ghost btn-sm" data-modal-close>&times; Close</button>
    </div>
    <div class="modal-body">
      <div style="display: flex; gap: 1.25rem; margin-bottom: 1rem; align-items: flex-start;">
        <div style="width: 80px; height: 80px; border-radius: 8px; background: var(--bg-subtle, #f3f4f6); border: 1px solid var(--border, #e5e7eb); display: flex; align-items: center; justify-content: center; flex-shrink: 0; overflow: hidden;">
          <?php if (!empty($p['image_url'])): ?>
            <img src="<?= e($p['image_url']) ?>" alt="<?= e($p['product_name']) ?>" style="width: 100%; height: 100%; object-fit: cover;">
          <?php else: ?>
            <span style="font-size: 2rem;" aria-hidden="true">📦</span>
          <?php endif; ?>
        </div>
        <div>
          <h4 style="margin: 0 0 0.25rem 0; font-size: 1.15rem;"><?= e($p['product_name']) ?></h4>
          <span class="badge secondary"><?= e($p['product_category']) ?></span>
          <span class="badge <?= $p['product_status'] === 'active' ? 'badge-success' : 'badge-danger' ?>">
            <?= e(ucfirst((string)$p['product_status'])) ?>
          </span>
          <div style="margin-top: 0.5rem; font-size: 1.1rem; font-weight: 600; color: var(--primary, #0284c7);">
            <?= peso((float)$p['selling_price']) ?>
          </div>
        </div>
      </div>

      <div style="background: var(--bg-subtle, #f9fafb); padding: 1rem; border-radius: 6px; margin-bottom: 1rem;">
        <div class="row" style="justify-content: space-between; margin-bottom: 0.5rem;">
          <span class="caption">SKU / Code:</span>
          <code><?= e($p['sku'] ?: '—') ?></code>
        </div>
        <div class="row" style="justify-content: space-between; margin-bottom: 0.5rem;">
          <span class="caption">Selling Price:</span>
          <strong><?= peso((float)$p['selling_price']) ?></strong>
        </div>
        <div class="row" style="justify-content: space-between;">
          <span class="caption">Status:</span>
          <span><?= e(ucfirst((string)$p['product_status'])) ?></span>
        </div>
      </div>

      <div class="field">
        <label class="caption" style="display: block; margin-bottom: 0.25rem;">Description</label>
        <p style="margin: 0; color: var(--ink-subtle, #4b5563);"><?= nl2br(e($p['description'] ?: 'No description provided.')) ?></p>
      </div>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn ghost btn-sm" data-modal-close>Close</button>
    </div>
  </dialog>

  <!-- Edit Details Modal -->
  <dialog class="modal modal-wide" id="edit-prod-<?= (int)$p['business_product_id'] ?>" aria-labelledby="e-title-<?= (int)$p['business_product_id'] ?>">
    <div class="modal-head">
      <h3 id="e-title-<?= (int)$p['business_product_id'] ?>">Edit: <?= e($p['product_name']) ?></h3>
      <button type="button" class="btn ghost btn-sm" data-modal-close>&times; Close</button>
    </div>
    <div class="modal-body">
      <form method="post" action="/manager/products/<?= (int)$p['business_product_id'] ?>/update<?= $unitQuery ?>" enctype="multipart/form-data">
        <?= \App\Core\CSRF::field() ?>

        <div class="grid grid-2">
          <div class="field">
            <label for="edit-name-<?= (int)$p['business_product_id'] ?>">Product Name *</label>
            <input id="edit-name-<?= (int)$p['business_product_id'] ?>" name="product_name" type="text" required value="<?= e($p['product_name']) ?>">
          </div>

          <div class="field">
            <label for="edit-sku-<?= (int)$p['business_product_id'] ?>">SKU / Code *</label>
            <input id="edit-sku-<?= (int)$p['business_product_id'] ?>" name="sku" type="text" required value="<?= e($p['sku']) ?>">
          </div>
        </div>

        <div class="grid grid-2">
          <div class="field">
            <label for="edit-cat-<?= (int)$p['business_product_id'] ?>">Category *</label>
            <input id="edit-cat-<?= (int)$p['business_product_id'] ?>" name="product_category" type="text" required value="<?= e($p['product_category']) ?>">
          </div>

          <div class="field">
            <label for="edit-price-<?= (int)$p['business_product_id'] ?>">Selling Price (₱) *</label>
            <input id="edit-price-<?= (int)$p['business_product_id'] ?>" name="selling_price" type="number" step="0.01" min="0.01" required value="<?= e((string)$p['selling_price']) ?>">
          </div>
        </div>

        <div class="field">
          <label for="edit-desc-<?= (int)$p['business_product_id'] ?>">Description</label>
          <textarea id="edit-desc-<?= (int)$p['business_product_id'] ?>" name="description" rows="3"><?= e($p['description'] ?? '') ?></textarea>
        </div>

        <div class="field">
          <label for="edit-img-<?= (int)$p['business_product_id'] ?>">Product Image</label>
          <?php if (!empty($p['image_url'])): ?>
            <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.5rem; background: var(--bg-subtle, #f9fafb); padding: 0.5rem 0.75rem; border-radius: 6px; border: 1px solid var(--border, #e5e7eb);">
              <img src="<?= e($p['image_url']) ?>" alt="Current image" style="width: 36px; height: 36px; object-fit: cover; border-radius: 4px;">
              <span class="caption">Current image is preserved unless a replacement file is selected.</span>
            </div>
          <?php endif; ?>
          <input id="edit-img-<?= (int)$p['business_product_id'] ?>" name="product_image" type="file" accept="image/png, image/jpeg, image/jpg, image/webp, image/gif">
          <span class="caption">Upload a replacement image file (PNG, JPG, WebP, GIF - Max 5MB).</span>
        </div>

        <div class="modal-foot" style="margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid var(--border, #e5e7eb); display: flex; justify-content: flex-end; gap: 0.5rem;">
          <button type="button" class="btn ghost" data-modal-close>Cancel</button>
          <button type="submit" class="btn primary">Save Changes</button>
        </div>
      </form>
    </div>
  </dialog>

  <!-- Product Activation / Deactivation Confirmation Modal -->
  <dialog class="modal" id="confirm-status-modal-<?= (int)$p['business_product_id'] ?>" aria-labelledby="status-title-<?= (int)$p['business_product_id'] ?>">
    <div class="modal-head">
      <h3 id="status-title-<?= (int)$p['business_product_id'] ?>"><?= $p['product_status'] === 'active' ? 'Deactivate Product' : 'Activate Product' ?></h3>
      <button type="button" class="btn ghost btn-sm" data-modal-close>&times; Close</button>
    </div>
    <div class="modal-body">
      <div style="margin-bottom: 1rem;">
        <p style="font-size: 1rem; margin: 0 0 0.75rem 0;">
          Are you sure you want to <strong><?= $p['product_status'] === 'active' ? 'deactivate' : 'activate' ?></strong> this catalog product?
        </p>
        <div style="background: var(--bg-subtle, #f9fafb); padding: 0.85rem 1rem; border-radius: 6px; border: 1px solid var(--border, #e5e7eb);">
          <div><strong>Product:</strong> <?= e($p['product_name']) ?></div>
          <div style="margin-top: 0.25rem;"><strong>SKU / Code:</strong> <code><?= e($p['sku'] ?: '—') ?></code></div>
          <div style="margin-top: 0.25rem;"><strong>Category:</strong> <?= e($p['product_category']) ?></div>
          <div style="margin-top: 0.25rem;"><strong>Selling Price:</strong> <?= peso((float)$p['selling_price']) ?></div>
        </div>
      </div>
      <p class="caption" style="line-height: 1.45; margin: 0;">
        <?php if ($p['product_status'] === 'active'): ?>
          Deactivating this product removes it from customer catalog listings and shopping carts. All historical sales, order lines, receiving batches, and inventory adjustment records are safely preserved.
        <?php else: ?>
          Activating this product restores it to the retail storefront and allows customers to add it to their carts.
        <?php endif; ?>
      </p>
    </div>
    <div class="modal-foot" style="display: flex; justify-content: flex-end; gap: 0.5rem; padding-top: 1rem; border-top: 1px solid var(--border, #e5e7eb);">
      <button type="button" class="btn ghost" data-modal-close>Cancel</button>
      <form method="post" action="/manager/products/<?= (int)$p['business_product_id'] ?>/status<?= $unitQuery ?>" style="display: inline;">
        <?= \App\Core\CSRF::field() ?>
        <input type="hidden" name="status" value="<?= $p['product_status'] === 'active' ? 'inactive' : 'active' ?>">
        <button type="submit" class="btn <?= $p['product_status'] === 'active' ? 'danger' : 'primary' ?>">
          Confirm <?= $p['product_status'] === 'active' ? 'Deactivation' : 'Activation' ?>
        </button>
      </form>
    </div>
  </dialog>
<?php endforeach; ?>

