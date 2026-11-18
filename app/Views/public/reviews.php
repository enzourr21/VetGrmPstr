<?php
$signedIn = (bool)\App\Core\Session::get('customer_id');
$hasFormState = !empty($errors) || !empty($old['review_title']) || !empty($old['comment']);
$totalReviews = count($reviews);
?>
<div class="reviews-workspace" data-reviews-workspace>
  <div class="page-header reviews-page-header">
    <div>
      <h1>Customer Reviews</h1>
    </div>
    <?php if ($totalReviews > 0): ?>
      <span class="caption tabular"><?= $totalReviews ?> review<?= $totalReviews === 1 ? '' : 's' ?></span>
    <?php endif; ?>
  </div>

  <section class="reviews-feed" aria-labelledby="reviews-feed-heading">
    <h2 id="reviews-feed-heading" class="sr">Customer Reviews List</h2>
    <?php if (!$reviews): ?>
      <div class="card empty-subtle">No customer reviews have been submitted yet.</div>
    <?php else: ?>
      <div class="reviews-list">
        <?php foreach ($reviews as $rev):
          $isSample = !empty($rev['is_sample']);
          $rating = max(1, min(5, (int)$rev['rating']));
          $authorDisplay = trim((string)$rev['first_name']) . ' ' . trim((string)$rev['last_initial']) . '.';
          $authorInitial = mb_strtoupper(mb_substr((string)$rev['first_name'], 0, 1));
          $reviewDate = substr((string)$rev['created_at'], 0, 10);
        ?>
          <article class="card review-card <?= $isSample ? 'review-card-sample' : '' ?>">
            <header class="review-card-head">
              <div class="review-author-wrap">
                <span class="review-author-badge" aria-hidden="true"><?= e($authorInitial) ?></span>
                <div class="review-author-meta">
                  <div class="review-author-line">
                    <strong class="review-author-name"><?= e($authorDisplay) ?></strong>
                    <?php if ($isSample): ?>
                      <span class="review-sample-tag">Sample</span>
                    <?php endif; ?>
                    <?php if (!empty($rev['business_unit_name'])): ?>
                      <span class="staff-unit-pill"><?= e($rev['business_unit_name']) ?></span>
                    <?php endif; ?>
                  </div>
                  <time class="caption tabular review-date" datetime="<?= e($reviewDate) ?>"><?= e($reviewDate) ?></time>
                </div>
              </div>
              <div class="review-rating-wrap">
                <span class="stars" aria-label="<?= $rating ?> out of 5 stars"><?= str_repeat('★', $rating) . str_repeat('☆', 5 - $rating) ?></span>
              </div>
            </header>
            <h3 class="review-title"><?= e($rev['review_title']) ?></h3>
            <p class="review-comment"><?= e($rev['comment']) ?></p>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <section class="card review-composer-dock <?= $hasFormState ? 'is-expanded' : '' ?>" aria-labelledby="review-composer-heading" data-review-composer>
    <div class="review-composer-header">
      <h2 id="review-composer-heading">Write a review</h2>
      <?php if ($signedIn): ?>
        <button type="button" class="btn ghost btn-sm review-composer-toggle" data-review-composer-toggle aria-expanded="<?= $hasFormState ? 'true' : 'false' ?>" aria-controls="review-composer-body">
          <?= $hasFormState ? 'Minimize' : 'Expand' ?>
        </button>
      <?php endif; ?>
    </div>

    <?php if (!$signedIn): ?>
      <div class="review-guest-bar">
        <p class="review-guest-prompt">Sign in with your customer account to submit a verified review.</p>
        <div class="row review-guest-actions">
          <a class="btn" href="/login">Sign in to write a review</a>
          <a class="btn secondary" href="/register">Create account</a>
        </div>
      </div>
    <?php else: ?>
      <div id="review-composer-body" class="review-composer-body" data-review-composer-body>
        <form method="post" action="/reviews" data-review-form novalidate>
          <?= \App\Core\CSRF::field() ?>
          <div class="review-form-alert flash flash-error" data-review-form-alert role="alert" hidden></div>

          <div class="review-composer-meta-grid">
            <div class="field">
              <div class="field-label-row">
                <label for="rating" style="margin:0">Rating</label>
              </div>
              <select id="rating" name="rating" required>
                <?php for ($r = 5; $r >= 1; $r--): ?>
                  <option value="<?= $r ?>" <?= (int)($old['rating'] ?? 5) === $r ? 'selected' : '' ?>><?= $r ?> <?= $r === 1 ? 'Star' : 'Stars' ?> (<?= str_repeat('★', $r) ?>)</option>
                <?php endfor; ?>
              </select>
              <div class="field-error" data-review-error="rating" <?= isset($errors['rating']) ? '' : 'hidden' ?>><?= e($errors['rating'] ?? '') ?></div>
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
              <div class="field-error" data-review-error="business_unit_id" <?= isset($errors['business_unit_id']) ? '' : 'hidden' ?>><?= e($errors['business_unit_id'] ?? '') ?></div>
            </div>

            <div class="field review-headline-field">
              <div class="field-label-row">
                <label for="review_title" style="margin:0">Headline</label>
              </div>
              <input id="review_title" name="review_title" type="text" maxlength="150" placeholder="Short summary of your visit" required value="<?= e($old['review_title'] ?? '') ?>">
              <div class="field-error" data-review-error="review_title" <?= isset($errors['review_title']) ? '' : 'hidden' ?>><?= e($errors['review_title'] ?? '') ?></div>
            </div>
          </div>

          <div class="review-composer-submit-row">
            <div class="field review-comment-field">
              <div class="field-label-row">
                <label for="comment" style="margin:0">Your review</label>
                <span class="caption tabular" data-review-char-count><?= mb_strlen((string)($old['comment'] ?? '')) ?> / 1500</span>
              </div>
              <textarea id="comment" name="comment" rows="2" maxlength="1500" placeholder="Share details about your visit..." required><?= e($old['comment'] ?? '') ?></textarea>
              <div class="field-error" data-review-error="comment" <?= isset($errors['comment']) ? '' : 'hidden' ?>><?= e($errors['comment'] ?? '') ?></div>
            </div>

            <div class="review-submit-wrap">
              <button class="btn review-submit-btn" type="submit" data-review-submit>Submit review</button>
            </div>
          </div>
        </form>
      </div>
    <?php endif; ?>
  </section>
</div>
