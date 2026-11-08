<?php
$curPath = rtrim((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/') ?: '/';
$isSignedIn = (bool)\App\Core\Session::get('user_id');
$baseContext = (string)\App\Core\Session::get('base_context', \App\Core\Session::get('employee_id') ? 'employee' : 'customer');
$activeContext = (string)\App\Core\Session::get('active_context', $baseContext);
$isWorkspaceRoute = str_starts_with($curPath, '/super-admin')
    || str_starts_with($curPath, '/sysadmin')
    || str_starts_with($curPath, '/manager')
    || (str_starts_with($curPath, '/staff') && !str_starts_with($curPath, '/staffs') && $curPath !== '/staff/login');

// Administrator identification & state
$isSuperAdmin = $isSignedIn && (bool)\App\Core\Session::get('is_super_admin');
$isSysAdmin = false;
$hasBmRole = false;
$hasEmployeeRecord = false;
$sysAdminUnitId = null;
$sysAdminUnitName = '';
$adminInitials = 'SA';

if ($isSignedIn && $baseContext === 'employee') {
    $uid = (int)\App\Core\Session::get('user_id');
    static $adminUserCache = [];
    if (!isset($adminUserCache[$uid])) {
        $db = \App\Core\Database::pdo();
        $stmt = $db->prepare(
            "SELECT ua.is_super_admin, e.employee_id, e.first_name, e.last_name, e.business_unit_id, bu.business_unit_name,
                    (SELECT 1 FROM account_role ar JOIN role r ON r.role_id = ar.role_id WHERE ar.user_account_id = ua.user_account_id AND r.role_name = 'SYSTEM_ADMINISTRATOR' LIMIT 1) AS has_sysadmin_role,
                    (SELECT 1 FROM account_role ar JOIN role r ON r.role_id = ar.role_id WHERE ar.user_account_id = ua.user_account_id AND r.role_name IN ('BUSINESS_MANAGER', 'INVENTORY_MANAGER') LIMIT 1) AS has_bm_role
             FROM user_account ua
             LEFT JOIN employee e ON e.user_account_id = ua.user_account_id
             LEFT JOIN business_unit bu ON bu.business_unit_id = e.business_unit_id
             WHERE ua.user_account_id = ?
             LIMIT 1"
        );
        $stmt->execute([$uid]);
        $adminUserCache[$uid] = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    $adminRow = $adminUserCache[$uid] ?? null;
    if ($adminRow) {
        if ((bool)$adminRow['is_super_admin']) {
            $isSuperAdmin = true;
        }
        if ((bool)$adminRow['has_sysadmin_role']) {
            $isSysAdmin = true;
            $sysAdminUnitId = (int)($adminRow['business_unit_id'] ?? 0);
            $sysAdminUnitName = (string)($adminRow['business_unit_name'] ?? '');
        }
        if ((bool)$adminRow['has_bm_role']) {
            $hasBmRole = true;
        }
        if (!empty($adminRow['employee_id'])) {
            $hasEmployeeRecord = true;
        }
        $first = trim((string)($adminRow['first_name'] ?? ''));
        $last = trim((string)($adminRow['last_name'] ?? ''));
        if ($first !== '' || $last !== '') {
            $adminInitials = strtoupper(substr($first, 0, 1) . substr($last, 0, 1));
        } else {
            $adminInitials = $isSuperAdmin ? 'ES' : 'AM';
        }
    }
}

$isAdminUser = $isSuperAdmin || $isSysAdmin;
$inAdminWorkspace = $isAdminUser && $baseContext === 'employee' && $activeContext === 'employee';

// Determine authorized interfaces for the Switch Interface menu
$authorizedInterfaces = [];
$activeUnitId = isset($_GET['unit_id']) && ctype_digit((string)$_GET['unit_id'])
    ? (int)$_GET['unit_id']
    : (isset($unitId) && is_numeric($unitId) ? (int)$unitId : ($sysAdminUnitId ?: 1));

if ($isSignedIn && $baseContext === 'employee') {
    $allBusinessUnits = [
        ['id' => 1, 'name' => 'Pet Store'],
        ['id' => 2, 'name' => 'Veterinary Clinic'],
        ['id' => 3, 'name' => 'Grooming Services'],
    ];

    if ($isSuperAdmin) {
        $authorizedInterfaces[] = [
            'id' => 'super-admin',
            'label' => 'Super Administrator',
            'hasUnits' => false,
            'url' => '/switch-interface?target=super-admin',
            'active' => str_starts_with($curPath, '/super-admin') && $activeContext !== 'customer',
        ];
        $authorizedInterfaces[] = [
            'id' => 'sysadmin',
            'label' => 'System Administrator',
            'hasUnits' => true,
            'units' => array_map(fn($u) => [
                'id' => $u['id'],
                'name' => $u['name'],
                'url' => '/switch-interface?target=sysadmin&unit_id=' . $u['id'],
            ], $allBusinessUnits),
            'active' => str_starts_with($curPath, '/sysadmin') && $activeContext !== 'customer',
        ];
        $authorizedInterfaces[] = [
            'id' => 'manager',
            'label' => 'Business Manager',
            'hasUnits' => true,
            'units' => array_map(fn($u) => [
                'id' => $u['id'],
                'name' => $u['name'],
                'url' => '/switch-interface?target=manager&unit_id=' . $u['id'],
            ], $allBusinessUnits),
            'active' => str_starts_with($curPath, '/manager') && $activeContext !== 'customer',
        ];
        $authorizedInterfaces[] = [
            'id' => 'employee',
            'label' => 'Employee Workspace',
            'hasUnits' => true,
            'units' => array_map(fn($u) => [
                'id' => $u['id'],
                'name' => $u['name'],
                'url' => '/switch-interface?target=employee&unit_id=' . $u['id'],
            ], $allBusinessUnits),
            'active' => (str_starts_with($curPath, '/staff') || $curPath === '/workspace') && $activeContext !== 'customer',
        ];
        $authorizedInterfaces[] = [
            'id' => 'customer',
            'label' => 'Customer Interface',
            'hasUnits' => false,
            'url' => '/switch-interface?target=customer',
            'active' => $activeContext === 'customer' || (!$isWorkspaceRoute && !$inAdminWorkspace),
        ];
    } elseif ($isSysAdmin) {
        $sysUnits = [];
        if ($sysAdminUnitId) {
            $sysUnits[] = ['id' => $sysAdminUnitId, 'name' => $sysAdminUnitName ?: 'Assigned Unit', 'url' => '/switch-interface?target=sysadmin&unit_id=' . $sysAdminUnitId];
        } else {
            $sysUnits[] = ['id' => 1, 'name' => 'Assigned Unit', 'url' => '/switch-interface?target=sysadmin'];
        }
        $authorizedInterfaces[] = [
            'id' => 'sysadmin',
            'label' => 'System Administrator',
            'hasUnits' => true,
            'units' => $sysUnits,
            'active' => str_starts_with($curPath, '/sysadmin') && $activeContext !== 'customer',
        ];
        if ($hasEmployeeRecord) {
            $authorizedInterfaces[] = [
                'id' => 'employee',
                'label' => 'Employee Workspace',
                'hasUnits' => true,
                'units' => [
                    ['id' => $sysAdminUnitId ?: 1, 'name' => $sysAdminUnitName ?: 'Assigned Unit', 'url' => '/switch-interface?target=employee'],
                ],
                'active' => (str_starts_with($curPath, '/staff') || $curPath === '/workspace') && $activeContext !== 'customer',
            ];
        }
        $authorizedInterfaces[] = [
            'id' => 'customer',
            'label' => 'Customer Interface',
            'hasUnits' => false,
            'url' => '/switch-interface?target=customer',
            'active' => $activeContext === 'customer' || (!$isWorkspaceRoute),
        ];
    } elseif (!empty($hasBmRole)) {
        $bmUnitId = (int)($adminRow['business_unit_id'] ?? 1);
        $bmUnitName = (string)($adminRow['business_unit_name'] ?? 'Assigned Unit');
        $authorizedInterfaces[] = [
            'id' => 'manager',
            'label' => 'Business Manager',
            'hasUnits' => true,
            'units' => [
                ['id' => $bmUnitId, 'name' => $bmUnitName, 'url' => '/switch-interface?target=manager&unit_id=' . $bmUnitId],
            ],
            'active' => str_starts_with($curPath, '/manager') && $activeContext !== 'customer',
        ];
        $authorizedInterfaces[] = [
            'id' => 'employee',
            'label' => 'Employee Workspace',
            'hasUnits' => true,
            'units' => [
                ['id' => $bmUnitId, 'name' => $bmUnitName, 'url' => '/switch-interface?target=employee'],
            ],
            'active' => (str_starts_with($curPath, '/staff') || $curPath === '/workspace') && $activeContext !== 'customer',
        ];
        $authorizedInterfaces[] = [
            'id' => 'customer',
            'label' => 'Customer Interface',
            'hasUnits' => false,
            'url' => '/switch-interface?target=customer',
            'active' => $activeContext === 'customer' || (!$isWorkspaceRoute),
        ];
    } elseif (!empty($hasEmployeeRecord)) {
        $empUId = (int)($adminRow['business_unit_id'] ?? 1);
        $empUName = (string)($adminRow['business_unit_name'] ?? 'Assigned Unit');
        $authorizedInterfaces[] = [
            'id' => 'employee',
            'label' => 'Employee Workspace',
            'hasUnits' => true,
            'units' => [
                ['id' => $empUId, 'name' => $empUName, 'url' => '/switch-interface?target=employee'],
            ],
            'active' => (str_starts_with($curPath, '/staff') || $curPath === '/workspace') && $activeContext !== 'customer',
        ];
        $authorizedInterfaces[] = [
            'id' => 'customer',
            'label' => 'Customer Interface',
            'hasUnits' => false,
            'url' => '/switch-interface?target=customer',
            'active' => $activeContext === 'customer' || (!$isWorkspaceRoute),
        ];
    }
}

$showCustomerNav = $isSignedIn && ((bool)\App\Core\Session::get('customer_id') || $activeContext === 'customer' || !$isWorkspaceRoute);
$showEmployeeWorkspaceNav = $isSignedIn && $baseContext === 'employee' && $activeContext === 'employee' && $isWorkspaceRoute;
$isAuthSignInOrOut = str_starts_with($curPath, '/login') || str_starts_with($curPath, '/staff/login') || str_starts_with($curPath, '/logout');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · VETGRPET</title>
<script>
  (function() {
    try {
      if (localStorage.getItem('vetgrpet_theme') === 'dark') {
        document.documentElement.setAttribute('data-theme', 'dark');
      }
    } catch (e) {}
  })();
</script>
<link rel="stylesheet" href="/assets/css/app.css?v=<?= file_exists(APP_ROOT . '/public/assets/css/app.css') ? filemtime(APP_ROOT . '/public/assets/css/app.css') : time() ?>">
</head>
<body>
<a class="skip" href="#main">Skip to content</a>
<header class="topbar">
  <div class="topbar-inner">
    <a class="brand" href="/">
      <span class="brand-mark" aria-hidden="true">VP</span>
      <span>VETGRPET</span>
    </a>
    <nav aria-label="Main">
<?php if ($inAdminWorkspace): ?>
  <?php if (str_starts_with($curPath, '/sysadmin')): ?>
      <a href="/sysadmin" class="segmented-pill <?= ($curPath === '/sysadmin' || preg_match('#^/sysadmin/units/\d+$#', $curPath)) ? 'is-current' : '' ?>">Overview</a>
      <a href="/sysadmin/employees" class="segmented-pill <?= (str_starts_with($curPath, '/sysadmin/employees') || preg_match('#^/sysadmin/units/\d+/employees#', $curPath)) ? 'is-current' : '' ?>">Unit Employees</a>
      <a href="/sysadmin/roles" class="segmented-pill <?= (str_starts_with($curPath, '/sysadmin/roles') || preg_match('#^/sysadmin/units/\d+/roles#', $curPath)) ? 'is-current' : '' ?>">Roles &amp; Permissions</a>
      <a href="/sysadmin/activity" class="segmented-pill <?= (str_starts_with($curPath, '/sysadmin/activity') || preg_match('#^/sysadmin/units/\d+/activity#', $curPath)) ? 'is-current' : '' ?>">Activity Log</a>
  <?php elseif (str_starts_with($curPath, '/super-admin')): ?>
      <a href="/super-admin" class="<?= $curPath === '/super-admin' ? 'is-current' : '' ?>">Dashboard</a>
      <a href="/super-admin/business-units" class="<?= str_starts_with($curPath, '/super-admin/business-units') ? 'is-current' : '' ?>">Business Units</a>
      <a href="/super-admin/system-admins" class="<?= str_starts_with($curPath, '/super-admin/system-admins') ? 'is-current' : '' ?>">System Administrators</a>
      <a href="/super-admin/employees" class="<?= str_starts_with($curPath, '/super-admin/employees') ? 'is-current' : '' ?>">Employee Accounts</a>
      <a href="/super-admin/roles" class="<?= str_starts_with($curPath, '/super-admin/roles') ? 'is-current' : '' ?>">Roles &amp; Permissions</a>
      <a href="/super-admin/activity" class="<?= str_starts_with($curPath, '/super-admin/activity') ? 'is-current' : '' ?>">System Activity</a>
  <?php endif; ?>
      <span class="nav-divider" aria-hidden="true"></span>
      <div class="profile-menu">
        <button type="button" class="profile-avatar-btn" id="admin-profile-toggle" aria-expanded="false" aria-haspopup="true" aria-label="Administrator profile menu">
          <span class="profile-avatar"><?= e($adminInitials) ?></span>
        </button>
        <div class="profile-dropdown" id="admin-profile-dropdown" role="menu" hidden>
          <div class="profile-switcher-wrap">
            <button type="button" class="profile-dropdown-item profile-switcher-btn" id="profile-switch-btn" aria-expanded="false" aria-haspopup="true">
              <span>Switch Interface</span>
              <svg class="profile-switcher-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"></polyline></svg>
            </button>
            <div class="profile-switcher-menu" id="profile-switcher-menu" hidden>
              <?php foreach ($authorizedInterfaces as $iface): ?>
                <?php if (!empty($iface['hasUnits']) && !empty($iface['units'])): ?>
                  <div class="profile-switcher-group">
                    <button type="button" class="profile-switcher-parent-btn <?= $iface['active'] ? 'is-current-parent is-current-interface' : '' ?>" data-target="<?= e($iface['id']) ?>" aria-expanded="false" aria-controls="switcher-units-<?= e($iface['id']) ?>">
                      <span class="switcher-opt-indicator" aria-hidden="true"><?= $iface['active'] ? '●' : '○' ?></span>
                      <span class="switcher-opt-name"><?= e($iface['label']) ?></span>
                      <?php if ($iface['active']): ?>
                        <span class="switcher-opt-badge">Current</span>
                      <?php endif; ?>
                      <svg class="profile-switcher-parent-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"></polyline></svg>
                    </button>
                    <div class="profile-switcher-units" id="switcher-units-<?= e($iface['id']) ?>" hidden>
                      <?php foreach ($iface['units'] as $u): ?>
                        <?php $isUnitCurrent = $iface['active'] && ((int)$u['id'] === (int)$currentUnitId); ?>
                        <a href="<?= e($u['url']) ?>" class="profile-switcher-unit-link <?= $isUnitCurrent ? 'is-current-unit' : '' ?>" role="menuitem">
                          <span class="unit-dot" aria-hidden="true"><?= $isUnitCurrent ? '●' : '▪' ?></span>
                          <span class="unit-name"><?= e($u['name']) ?></span>
                          <?php if ($isUnitCurrent): ?>
                            <span class="unit-badge">Active</span>
                          <?php endif; ?>
                        </a>
                      <?php endforeach; ?>
                    </div>
                  </div>
                <?php else: ?>
                  <a href="<?= e($iface['url']) ?>" class="profile-switcher-option <?= $iface['active'] ? 'is-current-interface' : '' ?>" role="menuitem">
                    <span class="switcher-opt-indicator" aria-hidden="true"><?= $iface['active'] ? '●' : '○' ?></span>
                    <span class="switcher-opt-name"><?= e($iface['label']) ?></span>
                    <?php if ($iface['active']): ?>
                      <span class="switcher-opt-badge">Current</span>
                    <?php endif; ?>
                  </a>
                <?php endif; ?>
              <?php endforeach; ?>
            </div>
          </div>
          <button type="button" class="profile-dropdown-item" id="admin-dark-mode-toggle" role="menuitem">Dark Mode</button>
          <form method="post" action="/logout" class="profile-dropdown-form">
            <?= \App\Core\CSRF::field() ?>
            <button type="submit" class="profile-dropdown-item profile-dropdown-logout" role="menuitem">Log Out</button>
          </form>
        </div>
      </div>
<?php else: ?>
      <a href="/products" class="<?= (str_starts_with($curPath, '/products') || str_starts_with($curPath, '/shop')) ? 'is-current' : '' ?>">Products</a>
      <a href="/services" class="<?= str_starts_with($curPath, '/services') ? 'is-current' : '' ?>">Services</a>
      <a href="/staffs" class="<?= str_starts_with($curPath, '/staffs') ? 'is-current' : '' ?>">Staffs</a>
      <a href="/reviews" class="<?= str_starts_with($curPath, '/reviews') ? 'is-current' : '' ?>">Customer Reviews</a>
  <?php if ($showCustomerNav && !$showEmployeeWorkspaceNav): ?>
      <span class="nav-divider" aria-hidden="true"></span>
      <a href="/appointments" class="<?= str_starts_with($curPath, '/appointments') ? 'is-current' : '' ?>">Appointments</a>
      <a href="/pets" class="<?= str_starts_with($curPath, '/pets') ? 'is-current' : '' ?>">My pets</a>
      <a href="/orders" class="<?= str_starts_with($curPath, '/orders') ? 'is-current' : '' ?>">Orders</a>
      <a href="/profile" class="<?= str_starts_with($curPath, '/profile') ? 'is-current' : '' ?>">Profile</a>
  <?php endif; ?>
  <?php if ($showEmployeeWorkspaceNav): ?>
      <span class="nav-divider" aria-hidden="true"></span>
      <a href="/workspace" class="is-current">Workspace</a>
      <a href="/customer-interface">Customer Interface</a>
  <?php endif; ?>
  <?php if ($isAdminUser && $activeContext === 'customer'): ?>
      <span class="nav-divider" aria-hidden="true"></span>
      <div class="profile-menu">
        <button type="button" class="profile-avatar-btn" id="admin-profile-toggle" aria-expanded="false" aria-haspopup="true" aria-label="Administrator profile menu">
          <span class="profile-avatar"><?= e($adminInitials) ?></span>
        </button>
        <div class="profile-dropdown" id="admin-profile-dropdown" role="menu" hidden>
          <div class="profile-switcher-wrap">
            <button type="button" class="profile-dropdown-item profile-switcher-btn" id="profile-switch-btn" aria-expanded="false" aria-haspopup="true">
              <span>Switch Interface</span>
              <svg class="profile-switcher-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"></polyline></svg>
            </button>
            <div class="profile-switcher-menu" id="profile-switcher-menu" hidden>
              <?php foreach ($authorizedInterfaces as $iface): ?>
                <?php if (!empty($iface['hasUnits']) && !empty($iface['units'])): ?>
                  <div class="profile-switcher-group">
                    <button type="button" class="profile-switcher-parent-btn <?= $iface['active'] ? 'is-current-parent is-current-interface' : '' ?>" data-target="<?= e($iface['id']) ?>" aria-expanded="false" aria-controls="switcher-units-<?= e($iface['id']) ?>">
                      <span class="switcher-opt-indicator" aria-hidden="true"><?= $iface['active'] ? '●' : '○' ?></span>
                      <span class="switcher-opt-name"><?= e($iface['label']) ?></span>
                      <?php if ($iface['active']): ?>
                        <span class="switcher-opt-badge">Current</span>
                      <?php endif; ?>
                      <svg class="profile-switcher-parent-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"></polyline></svg>
                    </button>
                    <div class="profile-switcher-units" id="switcher-units-<?= e($iface['id']) ?>" hidden>
                      <?php foreach ($iface['units'] as $u): ?>
                        <?php $isUnitCurrent = $iface['active'] && ((int)$u['id'] === (int)$currentUnitId); ?>
                        <a href="<?= e($u['url']) ?>" class="profile-switcher-unit-link <?= $isUnitCurrent ? 'is-current-unit' : '' ?>" role="menuitem">
                          <span class="unit-dot" aria-hidden="true"><?= $isUnitCurrent ? '●' : '▪' ?></span>
                          <span class="unit-name"><?= e($u['name']) ?></span>
                          <?php if ($isUnitCurrent): ?>
                            <span class="unit-badge">Active</span>
                          <?php endif; ?>
                        </a>
                      <?php endforeach; ?>
                    </div>
                  </div>
                <?php else: ?>
                  <a href="<?= e($iface['url']) ?>" class="profile-switcher-option <?= $iface['active'] ? 'is-current-interface' : '' ?>" role="menuitem">
                    <span class="switcher-opt-indicator" aria-hidden="true"><?= $iface['active'] ? '●' : '○' ?></span>
                    <span class="switcher-opt-name"><?= e($iface['label']) ?></span>
                    <?php if ($iface['active']): ?>
                      <span class="switcher-opt-badge">Current</span>
                    <?php endif; ?>
                  </a>
                <?php endif; ?>
              <?php endforeach; ?>
            </div>
          </div>
          <button type="button" class="profile-dropdown-item" id="admin-dark-mode-toggle" role="menuitem">Dark Mode</button>
          <form method="post" action="/logout" class="profile-dropdown-form">
            <?= \App\Core\CSRF::field() ?>
            <button type="submit" class="profile-dropdown-item profile-dropdown-logout" role="menuitem">Log Out</button>
          </form>
        </div>
      </div>
  <?php elseif ($isSignedIn): ?>
      <form method="post" action="/logout" class="inline"><?= \App\Core\CSRF::field() ?><button class="link" type="submit">Sign out</button></form>
  <?php else: ?>
      <span class="nav-divider" aria-hidden="true"></span>
      <a href="/login" class="<?= str_starts_with($curPath, '/login') ? 'is-current' : '' ?>">Sign in</a>
      <a class="nav-cta" href="/register">Create account</a>
  <?php endif; ?>
<?php endif; ?>
    </nav>
  </div>
</header>
<?php if (!$isAuthSignInOrOut): ?>
  <?php require APP_ROOT . '/app/Views/partials/scenic_rails.php'; ?>
<?php endif; ?>
<main id="main">
<?php foreach ($flashes as [$type, $msg]): ?>
  <div class="flash flash-<?= e($type) ?>" role="<?= $type === 'error' ? 'alert' : 'status' ?>"><?= e($msg) ?></div>
<?php endforeach; ?>
<?= $content ?>
</main>
<footer class="foot">VETGRPET · Pet Store · Veterinary Clinic · Grooming Services · Sample data marked [SAMPLE]</footer>
<script src="/assets/js/app.js?v=<?= file_exists(APP_ROOT . '/public/assets/js/app.js') ? filemtime(APP_ROOT . '/public/assets/js/app.js') : time() ?>" defer></script>
</body>
</html>
