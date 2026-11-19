<?php
require __DIR__ . '/_nav.php';
$q = $searchQuery ?? '';
$stFilter = $statusFilter ?? '';
$activeSort = $sortKey ?? 'default';
$refundRequests = $refundRequests ?? [];
$pendingRefundsCount = $pendingRefundsCount ?? 0;
$totalRefundsCount = $totalRefundsCount ?? count($refundRequests);
?>

<div class="page-head">
  <div>
    <p class="eyebrow">Refund Management</p>
    <h1>Refunds</h1>
    <p class="muted">Review all customer refund requests across pending, approved, and rejected outcomes without altering completed sales history.</p>
  </div>
</div>

<!-- Search, Status Filter & Sorting Bar -->
<section class="card" style="margin-bottom: 1.5rem; padding: 1rem 1.25rem;">
  <form method="get" action="/manager/refunds" class="row" style="gap: 0.75rem; align-items: flex-end; flex-wrap: wrap;">
    <?php if (!empty($isSuper)): ?>
      <input type="hidden" name="unit_id" value="<?= (int)$unitId ?>">
    <?php endif; ?>

    <div class="field" style="margin: 0; min-width: 250px; flex: 1;">
      <label for="refund-search" class="caption" style="display: block; margin-bottom: 0.25rem;">Search Customer Name, Customer ID, or Order ID</label>
      <div class="search-input-wrap">
        <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <circle cx="11" cy="11" r="8"></circle>
          <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
        </svg>
        <input id="refund-search" type="search" name="q" value="<?= e($q) ?>" placeholder="Customer name, Customer ID, Order ID, or ref... (Press Enter)" class="search-input">
      </div>
    </div>

    <div class="field" style="margin: 0; width: 175px;">
      <label for="refund-status" class="caption" style="display: block; margin-bottom: 0.25rem;">Request Status</label>
      <select id="refund-status" name="status" onchange="this.form.submit()" style="width: 100%;">
        <option value="" <?= $stFilter === '' ? 'selected' : '' ?>>All Statuses</option>
        <option value="pending" <?= $stFilter === 'pending' ? 'selected' : '' ?>>Pending Review</option>
        <option value="approved" <?= $stFilter === 'approved' ? 'selected' : '' ?>>Approved</option>
        <option value="rejected" <?= $stFilter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
      </select>
    </div>

    <div class="field" style="margin: 0; width: 230px;">
      <label for="refund-sort" class="caption" style="display: block; margin-bottom: 0.25rem;">Sort By</label>
      <select id="refund-sort" name="sort" onchange="this.form.submit()" style="width: 100%;">
        <option value="default" <?= $activeSort === 'default' ? 'selected' : '' ?>>Pending First (Newest)</option>
        <option value="date_desc" <?= $activeSort === 'date_desc' ? 'selected' : '' ?>>Request Date: Newest First</option>
        <option value="date_asc" <?= $activeSort === 'date_asc' ? 'selected' : '' ?>>Request Date: Oldest First</option>
        <option value="amount_desc" <?= $activeSort === 'amount_desc' ? 'selected' : '' ?>>Requested Amount: Highest First</option>
        <option value="amount_asc" <?= $activeSort === 'amount_asc' ? 'selected' : '' ?>>Requested Amount: Lowest First</option>
      </select>
    </div>

    <?php if ($q !== '' || $stFilter !== '' || $activeSort !== 'default'): ?>
      <div class="row" style="align-items: center;">
        <a href="/manager/refunds<?= $unitQuery ?>" class="btn ghost btn-sm">Reset</a>
      </div>
    <?php endif; ?>
  </form>
</section>

<!-- Refund Requests Table -->
<section class="card">
  <div class="row" style="justify-content: space-between; align-items: baseline; margin-bottom: 1rem;">
    <div>
      <h2 style="margin: 0;">Customer Refund Requests (<?= count($refundRequests) ?>)</h2>
      <p class="caption" style="margin: 0.2rem 0 0 0;">
        Showing <?= count($refundRequests) ?> of <?= (int)$totalRefundsCount ?> total requests (<?= (int)$pendingRefundsCount ?> pending review).
      </p>
    </div>
    <span class="caption"><?= count($refundRequests) ?> records</span>
  </div>

  <?php if (empty($refundRequests)): ?>
    <div class="empty">No refund requests found<?= $q !== '' ? ' matching "' . e($q) . '"' : '' ?><?= $stFilter !== '' ? ' with status ' . e($stFilter) : '' ?>.</div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Refund Ref</th>
            <th>Original Transaction</th>
            <th>Customer</th>
            <th>Request Date &amp; Time</th>
            <th>Requested Amount</th>
            <th>Status</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($refundRequests as $rf):
            $origTab = $rf['channel'] === 'online'
                ? (($rf['order_fulfillment_method'] ?? 'delivery') === 'pickup' ? 'pickup' : 'delivery')
                : 'walkin';
          ?>
            <tr>
              <td>
                <strong><?= e($rf['refund_reference']) ?></strong>
                <div class="caption">ID #<?= (int)$rf['refund_request_id'] ?></div>
              </td>
              <td>
                <a href="/manager/sales<?= $unitQuery ?><?= !empty($unitQuery) ? '&' : '?' ?>tab=<?= e($origTab) ?>&view=<?= (int)($rf['order_id'] ?: $rf['sale_id']) ?>">
                  <strong><?= e($rf['transaction_ref']) ?></strong>
                </a>
                <div>
                  <span class="meta-label"><?= e($rf['channel_label']) ?></span>
                </div>
              </td>
              <td>
                <strong><?= e($rf['customer_name']) ?></strong>
                <div class="caption">Customer ID #<?= (int)$rf['customer_id'] ?><?= !empty($rf['customer_phone']) ? ' · ' . e($rf['customer_phone']) : '' ?></div>
              </td>
              <td>
                <?= e(date('M j, Y', strtotime((string)$rf['created_at']))) ?>
                <div class="caption"><?= e(date('g:i A', strtotime((string)$rf['created_at']))) ?></div>
              </td>
              <td>
                <strong style="color: var(--danger, #dc2626); font-size: 0.95rem;"><?= peso((float)$rf['requested_amount']) ?></strong>
                <?php if ($rf['request_status'] === 'approved' && !empty($rf['approved_amount'])): ?>
                  <div class="caption" style="color: #059669;">Approved: <?= peso((float)$rf['approved_amount']) ?></div>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($rf['request_status'] === 'pending'): ?>
                  <span class="status warn">Pending Review</span>
                <?php elseif ($rf['request_status'] === 'approved'): ?>
                  <span class="status ok">Approved</span>
                <?php elseif ($rf['request_status'] === 'rejected'): ?>
                  <span class="status err">Rejected</span>
                <?php else: ?>
                  <span class="meta-label"><?= e(ucfirst((string)$rf['request_status'])) ?></span>
                <?php endif; ?>
              </td>
              <td>
                <button type="button" class="btn secondary btn-sm" data-modal-open="refund-modal-<?= (int)$rf['refund_request_id'] ?>">
                  <?= $rf['request_status'] === 'pending' ? 'Review Request' : 'View Details' ?>
                </button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<!-- Refund Request Details Modals & Action Dialogs -->
<?php foreach ($refundRequests as $rf):
  $origTab = $rf['channel'] === 'online'
      ? (($rf['order_fulfillment_method'] ?? 'delivery') === 'pickup' ? 'pickup' : 'delivery')
      : 'walkin';
?>
  <dialog class="modal modal-wide" id="refund-modal-<?= (int)$rf['refund_request_id'] ?>" aria-labelledby="refund-title-<?= (int)$rf['refund_request_id'] ?>" <?= (!empty($viewId) && (int)$viewId === (int)$rf['refund_request_id']) ? 'data-open-on-load="true"' : '' ?>>
    <div class="modal-head">
      <div>
        <div style="display: flex; gap: 0.5rem; align-items: center; margin-bottom: 0.35rem;">
          <span class="meta-label">Refund Request</span>
          <?php if ($rf['request_status'] === 'pending'): ?>
            <span class="status warn">Pending Review</span>
          <?php elseif ($rf['request_status'] === 'approved'): ?>
            <span class="status ok">Approved</span>
          <?php elseif ($rf['request_status'] === 'rejected'): ?>
            <span class="status err">Rejected</span>
          <?php endif; ?>
        </div>
        <h3 id="refund-title-<?= (int)$rf['refund_request_id'] ?>" style="margin: 0;">Request <?= e($rf['refund_reference']) ?></h3>
      </div>
      <button type="button" class="btn ghost btn-sm" data-modal-close aria-label="Close dialog">&times;</button>
    </div>

    <div class="modal-body">
      <div class="grid grid-2" style="gap: 1rem; margin-bottom: 1.5rem; background: var(--bg-subtle, #f9fafb); padding: 1rem; border-radius: 6px;">
        <div>
          <p style="margin: 0 0 0.25rem 0;" class="caption">Refund Reference</p>
          <strong style="font-size: 1rem;"><?= e($rf['refund_reference']) ?></strong>

          <p style="margin: 0.6rem 0 0.25rem 0;" class="caption">Request Date &amp; Time</p>
          <div><?= e(date('F j, Y · g:i:s A', strtotime((string)$rf['created_at']))) ?></div>

          <p style="margin: 0.6rem 0 0.25rem 0;" class="caption">Requested Refund Amount</p>
          <strong style="font-size: 1.15rem; color: var(--danger, #dc2626);"><?= peso((float)$rf['requested_amount']) ?></strong>

          <?php if ($rf['request_status'] === 'approved' && !empty($rf['approved_amount'])): ?>
            <p style="margin: 0.6rem 0 0.25rem 0;" class="caption">Approved Refund Amount</p>
            <strong style="font-size: 1.1rem; color: #059669;"><?= peso((float)$rf['approved_amount']) ?></strong>
          <?php endif; ?>
        </div>

        <div>
          <p style="margin: 0 0 0.25rem 0;" class="caption">Original Transaction</p>
          <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
            <strong><?= e($rf['transaction_ref']) ?></strong>
            <span class="meta-label"><?= e($rf['channel_label']) ?></span>
            <a href="/manager/sales<?= $unitQuery ?><?= !empty($unitQuery) ? '&' : '?' ?>tab=<?= e($origTab) ?>&view=<?= (int)($rf['order_id'] ?: $rf['sale_id']) ?>"
               class="btn secondary btn-sm"
               style="padding: 0.15rem 0.5rem; font-size: 0.75rem;">
              View Original Transaction &rarr;
            </a>
          </div>

          <p style="margin: 0.6rem 0 0.25rem 0;" class="caption">Original Transaction Total</p>
          <div><strong><?= peso((float)($rf['original_total_amount'] ?? 0)) ?></strong> (dated <?= e(date('M j, Y g:i A', strtotime((string)($rf['original_transaction_date'] ?? $rf['created_at'])))) ?>)</div>

          <p style="margin: 0.6rem 0 0.25rem 0;" class="caption">Customer Information</p>
          <strong><?= e($rf['customer_name']) ?></strong> <span class="caption">(ID #<?= (int)$rf['customer_id'] ?>)</span>
          <div class="caption"><?= e($rf['customer_email'] ?? '') ?><?= !empty($rf['customer_phone']) ? ' · ' . e($rf['customer_phone']) : '' ?></div>
        </div>
      </div>

      <div style="background: #fffbeb; border-left: 4px solid #f59e0b; padding: 0.85rem 1rem; border-radius: 4px; margin-bottom: 1rem;">
        <p class="caption" style="margin: 0 0 0.35rem 0; font-weight: 700; color: #b45309; text-transform: uppercase; font-size: 0.72rem; letter-spacing: 0.05em;">
          Customer Reason for Refund Request
        </p>
        <div style="color: #78350f; font-size: 0.95rem; line-height: 1.5;"><?= nl2br(e($rf['customer_reason'])) ?></div>
      </div>

      <?php if (!empty($rf['supporting_evidence'])): ?>
        <div style="background: #f0fdf4; border-left: 4px solid #10b981; padding: 0.85rem 1rem; border-radius: 4px; margin-bottom: 1.25rem;">
          <p class="caption" style="margin: 0 0 0.35rem 0; font-weight: 700; color: #047857; text-transform: uppercase; font-size: 0.72rem; letter-spacing: 0.05em;">
            Supporting Evidence / Customer Verification
          </p>
          <div style="color: #064e3b; font-size: 0.92rem; line-height: 1.5;"><?= nl2br(e($rf['supporting_evidence'])) ?></div>
        </div>
      <?php endif; ?>

      <h4 style="margin: 1.25rem 0 0.5rem 0;">Purchased Products in Original Transaction</h4>
      <div class="table-wrap" style="margin-bottom: 1.5rem;">
        <table>
          <thead>
            <tr>
              <th>Product</th>
              <th>SKU</th>
              <th>Unit Price</th>
              <th>Qty Purchased</th>
              <th style="text-align: right;">Line Total</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($rf['items'])): ?>
              <tr>
                <td colspan="5" class="caption" style="text-align: center; padding: 1rem;">Original items details preserved in transaction reference <?= e($rf['transaction_ref']) ?>.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($rf['items'] as $it):
                $lTot = (float)$it['unit_price'] * (int)$it['quantity'];
              ?>
                <tr>
                  <td><strong><?= e($it['product_name']) ?></strong></td>
                  <td><code><?= e($it['sku'] ?: '—') ?></code></td>
                  <td><?= peso((float)$it['unit_price']) ?></td>
                  <td><?= (int)$it['quantity'] ?></td>
                  <td style="text-align: right;"><?= peso($lTot) ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <?php if ($rf['request_status'] === 'approved'): ?>
        <div style="background: #ecfdf5; border: 1px solid #a7f3d0; padding: 1rem; border-radius: 6px; margin-top: 1rem;">
          <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.25rem;">
            <span class="status ok">Decision: Approved</span>
            <strong style="color: #065f46;">Approved Amount: <?= peso((float)$rf['approved_amount']) ?></strong>
          </div>
          <div class="caption" style="color: #047857;">
            Decided by <strong><?= e($rf['decided_by_name'] ?: 'Authorized Pet Store Manager') ?></strong>
            <?php if (!empty($rf['decided_by_position'])): ?>
              (<?= e($rf['decided_by_position']) ?>)
            <?php endif; ?>
            · <?= e(date('F j, Y · g:i A', strtotime((string)$rf['decided_at']))) ?>
          </div>
          <p class="caption" style="margin: 0.5rem 0 0 0; color: #065f46;">
            Operational Note: Payment reversal and inventory inspection follow their sanctioned separate procedures. Stock is not automatically modified.
          </p>
        </div>
      <?php elseif ($rf['request_status'] === 'rejected'): ?>
        <div style="background: #fef2f2; border: 1px solid #fecaca; padding: 1rem; border-radius: 6px; margin-top: 1rem;">
          <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.25rem;">
            <span class="status err">Decision: Rejected</span>
          </div>
          <div class="caption" style="color: #991b1b;">
            Decided by <strong><?= e($rf['decided_by_name'] ?: 'Authorized Pet Store Manager') ?></strong>
            <?php if (!empty($rf['decided_by_position'])): ?>
              (<?= e($rf['decided_by_position']) ?>)
            <?php endif; ?>
            · <?= e(date('F j, Y · g:i A', strtotime((string)$rf['decided_at']))) ?>
          </div>
          <div style="margin-top: 0.5rem; font-size: 0.92rem; color: #7f1d1d;">
            <strong>Rejection Reason:</strong> <?= nl2br(e($rf['rejection_reason'])) ?>
          </div>
        </div>
      <?php endif; ?>
    </div>

    <div class="modal-foot" style="display: flex; justify-content: space-between; align-items: center;">
      <div>
        <?php if ($rf['request_status'] === 'pending'): ?>
          <div class="row" style="gap: 0.5rem;">
            <button type="button" class="btn btn-primary btn-sm" data-modal-open="approve-modal-<?= (int)$rf['refund_request_id'] ?>">
              Approve Request...
            </button>
            <button type="button" class="btn secondary btn-sm" style="color: var(--danger, #dc2626); border-color: rgba(220, 38, 38, 0.35);" data-modal-open="reject-modal-<?= (int)$rf['refund_request_id'] ?>">
              Reject Request...
            </button>
          </div>
        <?php endif; ?>
      </div>
      <button type="button" class="btn ghost btn-sm" data-modal-close>Close</button>
    </div>
  </dialog>

  <?php if ($rf['request_status'] === 'pending'): ?>
    <dialog class="modal" id="approve-modal-<?= (int)$rf['refund_request_id'] ?>" style="max-width: 520px;">
      <div class="modal-head">
        <h3 style="margin: 0; color: #065f46;">Confirm Refund Approval</h3>
        <button type="button" class="btn ghost btn-sm" data-modal-close>&times;</button>
      </div>
      <form method="post" action="/manager<?= !empty($isSuper) ? '/units/' . (int)$unitId : '' ?>/sales/refunds/<?= (int)$rf['refund_request_id'] ?>/approve">
        <?= \App\Core\CSRF::field() ?>
        <div class="modal-body">
          <p style="margin: 0 0 1rem 0;">Are you sure you want to approve this customer refund request?</p>

          <div style="background: var(--bg-subtle, #f9fafb); padding: 0.85rem; border-radius: 6px; margin-bottom: 1rem; border: 1px solid var(--border, #e5e7eb);">
            <div class="row" style="justify-content: space-between; margin-bottom: 0.35rem;">
              <span class="caption">Refund Reference:</span>
              <strong><?= e($rf['refund_reference']) ?></strong>
            </div>
            <div class="row" style="justify-content: space-between; margin-bottom: 0.35rem;">
              <span class="caption">Customer:</span>
              <span><?= e($rf['customer_name']) ?> (ID #<?= (int)$rf['customer_id'] ?>)</span>
            </div>
            <div class="row" style="justify-content: space-between; margin-bottom: 0.35rem;">
              <span class="caption">Original Transaction:</span>
              <span><?= e($rf['transaction_ref']) ?> (<?= e($rf['channel_label']) ?>)</span>
            </div>
            <hr style="margin: 0.5rem 0; border: 0; border-top: 1px solid var(--border, #e5e7eb);">
            <div class="row" style="justify-content: space-between;">
              <strong>Refund Amount to Approve:</strong>
              <strong style="color: #059669; font-size: 1.15rem;"><?= peso((float)$rf['requested_amount']) ?></strong>
            </div>
          </div>

          <div style="background: #f0fdf4; border-left: 3px solid #10b981; padding: 0.65rem 0.85rem; border-radius: 4px; font-size: 0.82rem; color: #065f46; margin-bottom: 0.5rem;">
            <strong>Policy &amp; Workflow Notice:</strong> Approving this request records manager authorization and the decision timestamp. Original transaction records are preserved. Payment reversal and stock inspection proceed through separate operations.
          </div>
        </div>
        <div class="modal-foot" style="display: flex; justify-content: flex-end; gap: 0.5rem;">
          <button type="button" class="btn ghost btn-sm" data-modal-close>Cancel</button>
          <button type="submit" class="btn btn-primary btn-sm">Confirm Approval</button>
        </div>
      </form>
    </dialog>

    <dialog class="modal" id="reject-modal-<?= (int)$rf['refund_request_id'] ?>" style="max-width: 520px;">
      <div class="modal-head">
        <h3 style="margin: 0; color: #991b1b;">Reject Refund Request</h3>
        <button type="button" class="btn ghost btn-sm" data-modal-close>&times;</button>
      </div>
      <form method="post" action="/manager<?= !empty($isSuper) ? '/units/' . (int)$unitId : '' ?>/sales/refunds/<?= (int)$rf['refund_request_id'] ?>/reject">
        <?= \App\Core\CSRF::field() ?>
        <div class="modal-body">
          <p style="margin: 0 0 1rem 0;">Are you sure you want to reject this customer refund request? Please state the reason for rejection.</p>

          <div style="background: var(--bg-subtle, #f9fafb); padding: 0.85rem; border-radius: 6px; margin-bottom: 1rem; border: 1px solid var(--border, #e5e7eb);">
            <div class="row" style="justify-content: space-between; margin-bottom: 0.35rem;">
              <span class="caption">Refund Reference:</span>
              <strong><?= e($rf['refund_reference']) ?></strong>
            </div>
            <div class="row" style="justify-content: space-between; margin-bottom: 0.35rem;">
              <span class="caption">Customer:</span>
              <span><?= e($rf['customer_name']) ?> (ID #<?= (int)$rf['customer_id'] ?>)</span>
            </div>
            <div class="row" style="justify-content: space-between;">
              <span class="caption">Requested Amount:</span>
              <strong style="color: var(--danger, #dc2626);"><?= peso((float)$rf['requested_amount']) ?></strong>
            </div>
          </div>

          <div style="margin-bottom: 1rem;">
            <label for="rej-reason-<?= (int)$rf['refund_request_id'] ?>" style="display: block; font-weight: 600; font-size: 0.85rem; margin-bottom: 0.35rem;">
              Rejection Reason <span style="color: var(--danger, #dc2626);">*</span>
            </label>
            <textarea id="rej-reason-<?= (int)$rf['refund_request_id'] ?>"
                      name="rejection_reason"
                      required
                      rows="3"
                      style="width: 100%; padding: 0.5rem 0.75rem; border-radius: 6px; border: 1px solid var(--border, #d1d5db); font-family: inherit; font-size: 0.875rem;"
                      placeholder="State clearly why this refund request was not approved (e.g. past policy window, non-refundable product)..."></textarea>
          </div>

          <div class="caption" style="color: var(--muted, #6b7280);">
            This decision and your reason will be recorded in the audit trail and finalized. Original transactions remain intact.
          </div>
        </div>
        <div class="modal-foot" style="display: flex; justify-content: flex-end; gap: 0.5rem;">
          <button type="button" class="btn ghost btn-sm" data-modal-close>Cancel</button>
          <button type="submit" class="btn secondary btn-sm" style="background: var(--danger, #dc2626); color: white; border-color: var(--danger, #dc2626);">
            Confirm Rejection
          </button>
        </div>
      </form>
    </dialog>
  <?php endif; ?>
<?php endforeach; ?>
