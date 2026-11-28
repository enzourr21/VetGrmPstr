<?php
require __DIR__ . '/_nav.php';
$unitQuery = !empty($isSuper) ? '?unit_id=' . (int)$unitId : '';
if (!empty($isPetStore)) {
    $selfUid = (int)\App\Core\Session::get('user_id', 0);
    $currentPage = (int)($currentPage ?? 1);
    $totalPages = (int)($totalPages ?? 1);
    $totalEmployees = (int)($totalEmployees ?? count($employees));
    $pageUrl = function (int $p) use ($unitId, $isSuper): string {
        $params = ['page' => $p];
        if (!empty($isSuper)) {
            $params['unit_id'] = $unitId;
        }
        return '/sysadmin/employees?' . http_build_query($params);
    };
$unitInvitations = $unitInvitations ?? [];
?>
<div class="page-head page-header" style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
  <div>
    <p class="eyebrow"><?= e($unitName) ?> · Employee Directory</p>
    <h1><?= e($unitName) ?> Employees</h1>
    <p class="muted">Manage actual employee accounts, default positions, system roles, and pending invitations within <strong><?= e($unitName) ?></strong>.</p>
  </div>
  <div class="page-head-actions" style="margin-top: 0.25rem;">
    <button type="button" class="btn" data-modal-open="add-employee-modal">+ Add Employee</button>
  </div>
</div>

<section class="card roster-card" style="margin-bottom: 1.5rem;">
  <div class="row" style="justify-content: space-between; margin-bottom: 1.25rem; flex-wrap: wrap; gap: 0.75rem;">
    <div>
      <h2 style="margin: 0 0 0.25rem;">Pending &amp; Expired Invitations (<?= count($unitInvitations) ?>)</h2>
      <p class="caption" style="margin: 0;">Invitations do not create employee accounts until accepted by the recipient.</p>
    </div>
  </div>

  <?php if (!$unitInvitations): ?>
    <div class="empty">No pending or expired invitations for <?= e($unitName) ?>.</div>
  <?php else: ?>
    <div class="roster-list">
      <?php foreach ($unitInvitations as $inv):
        $invId = (int)$inv['invitation_id'];
        $invFullName = trim((string)$inv['first_name'] . ' ' . (string)$inv['last_name']);
        $invActionState = (string)($inv['action_state'] ?? (((string)($inv['invitation_state'] ?? '') === 'pending') ? 'pending_invitation' : 'expired_invitation'));
        $emailSubStatus = (string)($inv['email_submission_status'] ?? 'pending');
      ?>
        <article class="roster-item">
          <header class="roster-item-head">
            <div>
              <strong><?= e($invFullName) ?></strong>
              <span class="caption">Invitation #<?= $invId ?></span>
            </div>
            <?php if ($invActionState === 'pending_invitation'): ?>
              <span class="status warn">Pending Invitation</span>
            <?php else: ?>
              <span class="status neutral">Invitation Expired</span>
            <?php endif; ?>
          </header>
          <dl class="roster-meta">
            <div><dt>Email</dt><dd><?= e($inv['invited_email']) ?></dd></div>
            <div><dt>Username</dt><dd><code><?= e($inv['username'] ?: '—') ?></code></dd></div>
            <div><dt>Intended position</dt><dd><?= e($inv['position_name']) ?></dd></div>
            <div><dt>Intended roles</dt><dd><?= e($inv['unit_roles'] ?: '—') ?></dd></div>
            <div>
              <dt>Invitation email</dt>
              <dd>
                <?php if ($emailSubStatus === 'submitted'): ?>
                  <span class="status ok">Email sent</span>
                <?php elseif ($emailSubStatus === 'failed'): ?>
                  <span class="status err">Delivery failed</span>
                <?php else: ?>
                  <span class="status neutral">Pending delivery</span>
                <?php endif; ?>
              </dd>
            </div>
          </dl>
          <div class="roster-actions">
            <?php if ($invActionState === 'pending_invitation'): ?>
              <form method="post" action="/sysadmin/invitations/<?= $invId ?>/resend<?= $unitQuery ?>" data-confirm-action="Resend invitation to <?= e($invFullName) ?>? This will invalidate the previous invitation link." data-prevent-double-submit="Sending...">
                <?= \App\Core\CSRF::field() ?>
                <input type="hidden" name="business_unit_id" value="<?= (int)$unitId ?>">
                <button type="submit" class="btn secondary btn-sm">Resend Invitation</button>
              </form>
              <form method="post" action="/sysadmin/invitations/<?= $invId ?>/revoke<?= $unitQuery ?>" data-confirm-action="Revoke the pending invitation for <?= e($invFullName) ?>? The existing invitation link will no longer work." data-prevent-double-submit="Revoking...">
                <?= \App\Core\CSRF::field() ?>
                <input type="hidden" name="business_unit_id" value="<?= (int)$unitId ?>">
                <button type="submit" class="btn ghost btn-sm">Revoke Invitation</button>
              </form>
            <?php else: ?>
              <form method="post" action="/sysadmin/invitations/<?= $invId ?>/resend<?= $unitQuery ?>" data-confirm-action="Resend invitation to <?= e($invFullName) ?>? This will issue a new invitation link for the expired invitation." data-prevent-double-submit="Sending...">
                <?= \App\Core\CSRF::field() ?>
                <input type="hidden" name="business_unit_id" value="<?= (int)$unitId ?>">
                <button type="submit" class="btn secondary btn-sm">Resend Invitation</button>
              </form>
            <?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<section class="card roster-card">
  <div class="row" style="justify-content: space-between; margin-bottom: 1.25rem; flex-wrap: wrap; gap: 0.75rem;">
    <div>
      <h2 style="margin: 0 0 0.25rem;">Unit Employee Roster (<?= (int)$totalEmployees ?>)</h2>
      <p class="caption" style="margin: 0;">Employees assigned to <?= e($unitName) ?>. Showing 6 per page.</p>
    </div>
    <div class="row" style="gap: 0.5rem;">
      <a href="/sysadmin/roles<?= $unitQuery ?>" class="btn ghost btn-sm">Edit Role Assignments &rarr;</a>
    </div>
  </div>

  <?php if (!$employees): ?>
    <div class="empty">No employees found for <?= e($unitName) ?>.</div>
  <?php else: ?>
    <div class="roster-list roster-list-paginated">
      <?php foreach ($employees as $emp):
        $isHome = (int)($emp['home_unit_id'] ?? 0) === (int)$unitId;
        $isSelf = (int)$emp['user_account_id'] === $selfUid;
      ?>
        <article class="roster-item">
          <header class="roster-item-head">
            <div>
              <strong><?= e($emp['first_name'] . ' ' . $emp['last_name']) ?></strong>
              <span class="caption">ID #<?= (int)$emp['employee_id'] ?></span>
            </div>
            <?php
              $actionState = \App\Services\EmployeeInvitationService::classifyEmployeeRosterActionState($emp);
              $empFullName = $emp['first_name'] . ' ' . $emp['last_name'];
            ?>
            <?php if ($actionState === 'suspended'): ?>
              <span class="status err">suspended</span>
            <?php elseif ($actionState === 'inactive'): ?>
              <span class="status status-inactive">inactive</span>
            <?php elseif ($actionState === 'pending_invitation'): ?>
              <span class="status warn">Pending Invitation</span>
            <?php elseif ($actionState === 'expired_invitation'): ?>
              <span class="status neutral">Invitation Expired</span>
            <?php elseif ($actionState === 'revoked_invitation'): ?>
              <span class="status err">Invitation Revoked</span>
            <?php else: ?>
              <span class="status status-active">active</span>
            <?php endif; ?>
          </header>
          <dl class="roster-meta">
            <div><dt>Email</dt><dd><?= e($emp['email']) ?></dd></div>
            <div><dt>Username</dt><dd><code><?= e($emp['username'] ?? '—') ?></code></dd></div>
            <div><dt>Default position</dt><dd><?= e($emp['position']) ?></dd></div>
            <div><dt>Roles in unit</dt><dd><?= e($emp['unit_roles'] ?: '—') ?></dd></div>
          </dl>
          <?php if ($isHome): ?>
            <?php if ($actionState === 'active'): ?>
              <div class="roster-actions">
                <form method="post" action="/sysadmin/employees/<?= (int)$emp['employee_id'] ?>/position<?= $unitQuery ?>" class="roster-position-form">
                  <?= \App\Core\CSRF::field() ?>
                  <input type="hidden" name="business_unit_id" value="<?= (int)$unitId ?>">
                  <select name="position" aria-label="Change default position for <?= e($empFullName) ?>">
                    <?php
                    $hasCurrent = false;
                    foreach ($positions as $pos):
                        $sel = $pos['position_name'] === $emp['position'];
                        $hasCurrent = $hasCurrent || $sel;
                    ?>
                      <option value="<?= e($pos['position_name']) ?>" <?= $sel ? 'selected' : '' ?>><?= e($pos['position_name']) ?></option>
                    <?php endforeach; ?>
                    <?php if (!$hasCurrent): ?>
                      <option value="" selected disabled><?= e($emp['position']) ?> (retired)</option>
                    <?php endif; ?>
                  </select>
                  <button type="submit" class="btn ghost btn-sm">Change position</button>
                </form>
                <?php if (!$isSelf): ?>
                  <form method="post" action="/sysadmin/employees/<?= (int)$emp['employee_id'] ?>/status<?= $unitQuery ?>" data-confirm-action="Deactivate the employee profile of <?= e($empFullName) ?>? Their customer account access will remain intact.">
                    <?= \App\Core\CSRF::field() ?>
                    <input type="hidden" name="business_unit_id" value="<?= (int)$unitId ?>">
                    <input type="hidden" name="account_status" value="inactive">
                    <button type="submit" class="btn ghost btn-sm">Deactivate</button>
                  </form>
                <?php endif; ?>
              </div>
            <?php elseif ($actionState === 'inactive' && !$isSelf): ?>
              <div class="roster-actions">
                <form method="post" action="/sysadmin/employees/<?= (int)$emp['employee_id'] ?>/status<?= $unitQuery ?>" data-confirm-action="Reactivate the employee profile of <?= e($empFullName) ?>?">
                  <?= \App\Core\CSRF::field() ?>
                  <input type="hidden" name="business_unit_id" value="<?= (int)$unitId ?>">
                  <input type="hidden" name="account_status" value="active">
                  <button type="submit" class="btn secondary btn-sm">Reactivate</button>
                </form>
              </div>
            <?php elseif ($actionState === 'suspended'): ?>
              <div class="roster-actions">
                <span class="caption muted">Account globally suspended by Super Administrator.</span>
              </div>
            <?php elseif (in_array($actionState, ['pending_invitation', 'expired_invitation', 'revoked_invitation'], true)): ?>
              <div class="roster-actions">
                <span class="caption muted">Legacy unaccepted invitation quarantined — use Pending Invitations or administrator cleanup.</span>
              </div>
            <?php endif; ?>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <nav class="pagination-centered" aria-label="Employee roster pagination">
    <?php if ($currentPage <= 1): ?>
      <button type="button" class="btn ghost btn-sm pagination-prev" disabled aria-disabled="true">Previous</button>
    <?php else: ?>
      <a href="<?= e($pageUrl($currentPage - 1)) ?>" class="btn ghost btn-sm pagination-prev">Previous</a>
    <?php endif; ?>

    <span class="pagination-sep">&middot;</span>
    <span class="pagination-status">Page <?= $currentPage ?> of <?= $totalPages ?></span>
    <span class="pagination-sep">&middot;</span>

    <?php if ($currentPage >= $totalPages): ?>
      <button type="button" class="btn ghost btn-sm pagination-next" disabled aria-disabled="true">Next</button>
    <?php else: ?>
      <a href="<?= e($pageUrl($currentPage + 1)) ?>" class="btn ghost btn-sm pagination-next">Next</a>
    <?php endif; ?>
  </nav>
</section>

<!-- Employee Creation Modal -->
<?php require __DIR__ . '/_pet_employee_modal.php'; ?>
<?php
    return;
}
$selectedRoles = $old['roles'] ?? [];
if (!is_array($selectedRoles)) {
    $selectedRoles = [$selectedRoles];
}
?>

<div class="page-head">
  <div>
    <p class="eyebrow"><?= e($unitName) ?> · Employee Directory</p>
    <h1><?= e($unitName) ?> Employees</h1>
    <p class="muted">Create and view employee accounts within <strong><?= e($unitName) ?></strong>. You may also grant <code>SYSTEM_ADMINISTRATOR</code> within this unit.</p>
  </div>
</div>

<div class="grid grid-2" style="align-items: start; gap: 1.5rem;">
  <section class="card">
    <h2>Create Employee in <?= e($unitName) ?></h2>
    <p class="caption" style="margin-bottom: 1rem;">All new accounts created here are automatically bound to <strong><?= e($unitName) ?></strong>.</p>

    <form method="post" action="/sysadmin/employees<?= $unitQuery ?>" novalidate>
      <?= \App\Core\CSRF::field() ?>
      <input type="hidden" name="business_unit_id" value="<?= (int)$unitId ?>">

      <div class="field">
        <label for="sa-emp-name">1. Employee full name</label>
        <input id="sa-emp-name" name="full_name" type="text" required
               value="<?= e($old['full_name'] ?? '') ?>"
               placeholder="e.g. Carla Mendoza"
               aria-invalid="<?= isset($errors['full_name']) ? 'true' : 'false' ?>">
        <?php if (isset($errors['full_name'])): ?>
          <div class="field-error"><?= e($errors['full_name']) ?></div>
        <?php endif; ?>
      </div>

      <div class="grid grid-2">
        <div class="field">
          <label for="sa-emp-email">2. Email address (Staff login)</label>
          <input id="sa-emp-email" name="email" type="email" required
                 value="<?= e($old['email'] ?? '') ?>"
                 placeholder="carla.mendoza@vetgrpet.local"
                 aria-invalid="<?= isset($errors['email']) ? 'true' : 'false' ?>">
          <?php if (isset($errors['email'])): ?>
            <div class="field-error"><?= e($errors['email']) ?></div>
          <?php endif; ?>
        </div>
        <div class="field">
          <label for="sa-emp-username">Username (Optional · Customer-only login)</label>
          <input id="sa-emp-username" name="username" type="text"
                 value="<?= e($old['username'] ?? '') ?>"
                 placeholder="Auto-generated if blank"
                 aria-invalid="<?= isset($errors['username']) ? 'true' : 'false' ?>">
          <?php if (isset($errors['username'])): ?>
            <div class="field-error"><?= e($errors['username']) ?></div>
          <?php endif; ?>
        </div>
      </div>

      <div class="grid grid-2">
        <div class="field">
          <label>3. Assigned business unit</label>
          <input type="text" value="<?= e($unitName) ?>" disabled>
          <div class="help">Locked to your assigned business unit.</div>
        </div>

        <div class="field">
          <label for="sa-emp-position">4. Default position</label>
          <select id="sa-emp-position" name="position" required aria-invalid="<?= isset($errors['position']) ? 'true' : 'false' ?>">
            <option value="">Select position in <?= e($unitName) ?>&hellip;</option>
            <?php foreach ($positions as $pos): ?>
              <option value="<?= e($pos['position_name']) ?>"
                <?= ($old['position'] ?? '') === $pos['position_name'] ? 'selected' : '' ?>>
                <?= e($pos['position_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <?php if (isset($errors['position'])): ?>
            <div class="field-error"><?= e($errors['position']) ?></div>
          <?php endif; ?>
        </div>
      </div>

      <div class="field">
        <label>5. System role or roles in <?= e($unitName) ?></label>
        <div class="grid grid-2" style="gap: 0.5rem; margin-top: 0.25rem;">
          <?php foreach ($assignableRoles as $r):
            $rName = (string)$r['role_name'];
            $checked = in_array($rName, $selectedRoles, true) || in_array((string)$r['role_id'], $selectedRoles, true);
          ?>
            <label class="check-card" style="padding: 0.65rem 0.85rem;">
              <input type="checkbox" name="roles[]" value="<?= e($rName) ?>" <?= $checked ? 'checked' : '' ?>>
              <span>
                <strong><?= e($rName) ?></strong>
                <small><?= e($r['description']) ?></small>
              </span>
            </label>
          <?php endforeach; ?>
        </div>
        <?php if (isset($errors['roles'])): ?>
          <div class="field-error"><?= e($errors['roles']) ?></div>
        <?php endif; ?>
      </div>

      <div class="grid grid-2">
        <div class="field">
          <label for="sa-emp-password">6. Password</label>
          <div class="password-input-wrap">
            <input id="sa-emp-password" name="password" type="password" required minlength="8"
                   placeholder="At least 8 characters"
                   aria-invalid="<?= isset($errors['password']) ? 'true' : 'false' ?>">
            <button type="button" class="password-toggle-btn" data-pw-toggle="sa-emp-password" aria-label="Show password" aria-pressed="false"><svg class="icon-eye" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg><svg class="icon-eye-off" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.4 10.4 0 0 1 12 19c-6.5 0-10-7-10-7a18.6 18.6 0 0 1 4.06-5.06"/><path d="M9.9 5.24A9.7 9.7 0 0 1 12 5c6.5 0 10 7 10 7a18.5 18.5 0 0 1-2.16 3.19"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/><line x1="2" y1="2" x2="22" y2="22"/></svg></button>
          </div>
          <div class="help">Hashed with <code>password_hash()</code>.</div>
          <?php if (isset($errors['password'])): ?>
            <div class="field-error"><?= e($errors['password']) ?></div>
          <?php endif; ?>
        </div>
        <div class="field">
          <label for="sa-emp-phone">Phone (Optional)</label>
          <input id="sa-emp-phone" name="phone" type="text"
                 value="<?= e($old['phone'] ?? '') ?>"
                 placeholder="0917-555-0199">
        </div>
      </div>

      <button type="submit" class="btn" style="width: 100%; margin-top: 0.5rem;">Create <?= e($unitName) ?> Employee Account</button>
    </form>
  </section>

  <section class="card">
    <div class="row" style="justify-content: space-between; margin-bottom: 1rem;">
      <div>
        <h2>Unit Employee Roster (<?= count($employees) ?>)</h2>
        <p class="caption">Employees assigned to <?= e($unitName) ?>.</p>
      </div>
      <a href="/sysadmin/roles<?= $unitQuery ?>" class="btn ghost btn-sm">Edit Role Assignments &rarr;</a>
    </div>

    <?php if (!$employees): ?>
      <div class="empty">No employees found for <?= e($unitName) ?>.</div>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Employee</th>
              <th>Credentials</th>
              <th>Default Position</th>
              <th>Roles in <?= e($unitName) ?></th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($employees as $emp): ?>
              <tr>
                <td>
                  <strong><?= e($emp['first_name'] . ' ' . $emp['last_name']) ?></strong>
                  <div class="caption">ID #<?= (int)$emp['employee_id'] ?></div>
                </td>
                <td>
                  <div><?= e($emp['email']) ?></div>
                  <div class="caption">username: <code><?= e($emp['username'] ?? '—') ?></code></div>
                </td>
                <td><?= e($emp['position']) ?></td>
                <td><span class="caption"><?= e($emp['unit_roles'] ?: '—') ?></span></td>
                <td><span class="status status-<?= e($emp['account_status']) ?>"><?= e($emp['account_status']) ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
</div>

