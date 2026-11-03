<?php $signedIn = (bool)\App\Core\Session::get('customer_id'); ?>
<div class="page-header">
  <div>
    <h1>Customer Reviews</h1>
    <p class="muted">Read genuine experiences submitted by VETGRPET pet owners or share your own positive visit.</p>
  </div>
</div>

<div class="grid-2" style="align-items: start; gap: 1.5rem;">
  <section class="card">
    <h2 style="margin-top: 0;">Share your experience</h2>
    <?php if (!$signedIn): ?>
      <p class="muted">Sign in to your customer account to submit a verified review about your visit.</p>
      <div class="row" style="margin-top: 1rem;">
        <a class="btn" href="/login">Sign in to write a review</a>
        <a class="btn secondary" href="/register">Create account</a>
      </div>
    <?php else: ?>
      <form method="post" action="/reviews" novalidate>
        <?= \App\Core\CSRF::field() ?>
        <div class="form-grid-2">
          <div class="field">
            <div class="field-label-row">
              <label for="rating" style="margin:0">Rating</label>
            </div>
            <select id="rating" name="rating" required>
              <?php for ($r = 5; $r >= 1; $r--): ?>
                <option value="<?= $r ?>" <?= (int)($old['rating'] ?? 5) === $r ? 'selected' : '' ?>><?= $r ?> <?= $r === 1 ? 'Star' : 'Stars' ?> (<?= str_repeat('★', $r) ?>)</option>
              <?php endfor; ?>
            </select>
            <?php if (isset($errors['rating'])): ?><div class="field-error"><?= e($errors['rating']) ?></div><?php endif; ?>
          </div>

          <div class="field">
            <div class="field-label-row">
              <label for="business_unit_id" style="margin:0">Business unit</label>
              <span class="label-optional">Optional</span>
            </div>
            <select id="business_unit_id" name="business_unit_id">
              <option value="">All / General</option>
              <?php foreach ($units as $u): ?>
                <option value="<?= (int)$u['business_unit_id'] ?>" <?= (int)($old['business_unit_id'] ?? 0) === (int)$u['business_unit_id'] ? 'selected' : '' ?>><?= e($u['business_unit_name']) ?></option>
              <?php endforeach; ?>
            </select>
            <?php if (isset($errors['business_unit_id'])): ?><div class="field-error"><?= e($errors['business_unit_id']) ?></div><?php endif; ?>
          </div>
        </div>

        <div class="field" style="margin-top: 0.85rem;">
          <div class="field-label-row">
            <label for="review_title" style="margin:0">Headline</label>
          </div>
          <input id="review_title" name="review_title" type="text" placeholder="e.g., Wonderful grooming experience for our beagle!" required value="<?= e($old['review_title'] ?? '') ?>">
          <?php if (isset($errors['review_title'])): ?><div class="field-error"><?= e($errors['review_title']) ?></div><?php endif; ?>
        </div>

        <div class="field">
          <div class="field-label-row">
            <label for="comment" style="margin:0">Your review</label>
          </div>
          <textarea id="comment" name="comment" rows="4" placeholder="Tell us what went well during your visit..." required><?= e($old['comment'] ?? '') ?></textarea>
          <?php if (isset($errors['comment'])): ?><div class="field-error"><?= e($errors['comment']) ?></div><?php endif; ?>
        </div>

        <div style="margin-top: 1rem;">
          <button class="btn full" type="submit">Submit review</button>
        </div>
      </form>
    <?php endif; ?>
  </section>

  <section class="stack">
    <h2 style="margin-top: 0;">Submitted Customer Reviews</h2>
    <?php if (!$reviews): ?>
      <div class="card">
        <h3>No customer reviews submitted yet</h3>
        <p class="muted">We never display fabricated or mock testimonials. As soon as a customer submits a review, it appears here directly from the database.</p>
      </div>
    <?php else: ?>
      <?php foreach ($reviews as $rev): ?>
        <article class="card overview-card">
          <div>
            <div class="row" style="justify-content: space-between; margin-bottom: 0.4rem;">
              <span class="stars" aria-label="<?= (int)$rev['rating'] ?> out of 5 stars"><?= str_repeat('★', (int)$rev['rating']) . str_repeat('☆', 5 - (int)$rev['rating']) ?></span>
              <?php if (!empty($rev['business_unit_name'])): ?>
                <span class="caption"><?= e($rev['business_unit_name']) ?></span>
              <?php endif; ?>
            </div>
            <h3><?= e($rev['review_title']) ?></h3>
            <p class="muted" style="margin: 0.45rem 0 0;"><?= e($rev['comment']) ?></p>
          </div>
          <div class="overview-card-footer caption">
            <span>By <strong><?= e($rev['first_name'] . ' ' . $rev['last_initial'] . '.') ?></strong></span>
            <span class="tabular"><?= e(substr((string)$rev['created_at'], 0, 10)) ?></span>
          </div>
        </article>
      <?php endforeach; ?>
    <?php endif; ?>
  </section>
</div>
