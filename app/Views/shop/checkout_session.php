<?php
$attemptId = (int)$attempt['attempt_id'];
$buId = (int)$attempt['business_unit_id'];
$holdActive = !empty($attempt['is_hold_currently_active']);
$holdStatus = (string)($attempt['hold_status'] ?? '');
$attStatus = (string)$attempt['attempt_status'];
$secondsRemaining = (int)($attempt['seconds_remaining'] ?? 0);
?>
<a class="breadcrumb-link" href="/checkout/<?= $buId ?>"><span class="arrow-back" aria-hidden="true">&larr;</span> Back to <?= e($attempt['business_unit_name']) ?> checkout</a>
<div class="page-header">
  <div>
    <h1>Online Checkout Session #<?= $attemptId ?></h1>
    <p class="muted tabular"><?= e($attempt['business_unit_name']) ?> · Attempt Reference: <code><?= e(substr((string)$attempt['attempt_token'], 0, 16)) ?></code></p>
  </div>
  <?php if ($holdActive): ?>
    <span class="status ok">Hold Active (<?= (int)ceil($secondsRemaining / 60) ?>m / <?= $secondsRemaining ?>s left)</span>
  <?php elseif ($attStatus === 'reconciliation_required'): ?>
    <span class="status warn">Payment Reconciliation Pending</span>
  <?php elseif ($holdStatus === 'expired' || ($holdStatus === 'active' && !$holdActive)): ?>
    <span class="status err">Hold Expired</span>
  <?php elseif ($holdStatus === 'released' || $attStatus === 'failed'): ?>
    <span class="status err">Hold Released / Cancelled</span>
  <?php else: ?>
    <span class="meta-label"><?= e(ucwords(str_replace('_', ' ', $attStatus))) ?></span>
  <?php endif; ?>
</div>

<div class="card" style="margin-bottom: 1.5rem;">
  <p>
    <strong>Shop:</strong> <?= e($attempt['business_unit_name']) ?><br>
    <strong>Fulfillment:</strong> <?= e(ucfirst((string)$attempt['fulfillment_method'])) ?><br>
    <strong>Checkout Status:</strong> <span class="meta-label"><?= e(ucwords(str_replace('_', ' ', $attStatus))) ?></span><br>
    <?php if (!empty($attempt['hold_created_at']) && !empty($attempt['hold_expires_at'])): ?>
      <strong>Hold Created (UTC):</strong> <span class="tabular"><?= e($attempt['hold_created_at']) ?></span><br>
      <strong>Hold Expires (UTC):</strong> <span class="tabular"><?= e($attempt['hold_expires_at']) ?></span>
      <?php if ($holdActive): ?>
        <span class="caption muted">(Server-enforced 3-minute hold · <?= $secondsRemaining ?>s remaining)</span>
      <?php endif; ?>
    <?php endif; ?>
  </p>

  <?php if ($holdActive): ?>
    <p class="notice" style="margin-top: 0.75rem;">
      Your items are temporarily held for <strong>3 minutes</strong>. No customer order is confirmed until online payment capture is verified server-side.
      Online payment provider integration is pending selection; browser redirects are never treated as proof of payment.
    </p>
  <?php elseif ($attStatus === 'reconciliation_required'): ?>
    <p class="notice" style="margin-top: 0.75rem;">
      A captured payment arrived after your hold expired and could not be automatically fulfilled. It has been recorded for staff reconciliation.
    </p>
  <?php else: ?>
    <p class="notice" style="margin-top: 0.75rem;">
      This temporary hold is no longer active, so the stock has been returned to available inventory. Your cart items remain available for a fresh checkout attempt.
    </p>
  <?php endif; ?>
</div>

<table>
  <thead><tr><th>Product</th><th>Unit price</th><th>Qty</th><th class="right">Subtotal</th></tr></thead>
  <tbody>
  <?php foreach ($items as $l): ?>
    <tr>
      <td><strong><?= e($l['product_name']) ?></strong></td>
      <td class="tabular"><?= money($l['unit_price']) ?></td>
      <td class="tabular"><?= (int)$l['quantity'] ?></td>
      <td class="right price"><?= money((float)$l['unit_price'] * (int)$l['quantity']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
  <tfoot>
    <tr><th colspan="3" class="right">Product subtotal</th><th class="right tabular"><?= money($attempt['subtotal_amount'] ?? $attempt['expected_amount']) ?></th></tr>
    <tr><th colspan="3" class="right">Delivery fee</th><th class="right tabular"><?= money($attempt['delivery_fee'] ?? 0) ?></th></tr>
    <tr><th colspan="3" class="right">Total</th><th class="right price"><?= money($attempt['expected_amount']) ?></th></tr>
  </tfoot>
</table>

<div class="row" style="margin-top: 1.25rem; justify-content: space-between;">
  <a class="btn secondary" href="/cart/<?= $buId ?>">Return to cart</a>
  <?php if ($holdActive): ?>
    <form method="post" action="/checkout/attempts/<?= $attemptId ?>/cancel" class="inline">
      <?= \App\Core\CSRF::field() ?>
      <button class="btn danger" type="submit">Cancel Checkout &amp; Release Hold</button>
    </form>
  <?php else: ?>
    <a class="btn" href="/checkout/<?= $buId ?>">Start new checkout</a>
  <?php endif; ?>
</div>
