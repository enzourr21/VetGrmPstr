<?php
$curPath = rtrim((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/') ?: '/';
$isSignedIn = (bool)\App\Core\Session::get('user_id');
$baseContext = (string)\App\Core\Session::get('base_context', \App\Core\Session::get('employee_id') ? 'employee' : 'customer');
$activeContext = (string)\App\Core\Session::get('active_context', $baseContext);
$isWorkspaceRoute = str_starts_with($curPath, '/super-admin')
    || str_starts_with($curPath, '/sysadmin')
    || str_starts_with($curPath, '/manager')
    || str_starts_with($curPath, '/petstore/receptionist')
    || str_starts_with($curPath, '/grooming/receptionist')
    || str_starts_with($curPath, '/vetclinic/receptionist')
    || str_starts_with($curPath, '/delivery')
    || (str_starts_with($curPath, '/staff') && !str_starts_with($curPath, '/staffs') && $curPath !== '/staff/login');

// Staff / Administrator identification & role resolution
$isSuperAdmin = false;
$isSysAdmin = false;
$hasBmRole = false;
$hasImRole = false;
$switcherCtx = null;
$adminInitials = 'SA';
$activeWorkspaceInterface = (string)\App\Core\Session::get('active_workspace_interface', '');

if ($isSignedIn && $baseContext === 'employee') {
    $uid = (int)\App\Core\Session::get('user_id');
    $db = \App\Core\Database::pdo();
    $switcherCtx = \App\Services\InterfaceSwitcherService::loadContext($db, $uid);

    $isSuperAdmin = !empty($switcherCtx['is_super_admin']);
    $isSysAdmin = !empty($switcherCtx['sysadmin_units']);
    $hasBmRole = !empty($switcherCtx['bm_units']);
    $hasImRole = !empty($switcherCtx['im_units']);

    $first = $switcherCtx['first_name'] ?? '';
    $last = $switcherCtx['last_name'] ?? '';
    if ($first !== '' || $last !== '') {
        $adminInitials = strtoupper(substr($first, 0, 1) . substr($last, 0, 1));
    } else {
        $adminInitials = $isSuperAdmin ? 'ES' : 'AM';
    }
}

// Active Business Unit identification
if (str_starts_with($curPath, '/petstore/receptionist') || str_starts_with($curPath, '/delivery')) {
    $activeUnitId = 1;
} elseif (str_starts_with($curPath, '/vetclinic/receptionist')) {
    $activeUnitId = 2;
} elseif (str_starts_with($curPath, '/grooming/receptionist') || str_starts_with($curPath, '/staff/appointments')) {
    $activeUnitId = 3;
} elseif (preg_match('#/units/(\d+)#', $curPath, $matches)) {
    $activeUnitId = (int)$matches[1];
} elseif (isset($_GET['unit_id']) && ctype_digit((string)$_GET['unit_id'])) {
    $activeUnitId = (int)$_GET['unit_id'];
} elseif (isset($unitId) && is_numeric($unitId)) {
    $activeUnitId = (int)$unitId;
} elseif ($isSysAdmin && !empty($switcherCtx['sysadmin_units'])) {
    $activeUnitId = (int)$switcherCtx['sysadmin_units'][0];
} elseif ($hasBmRole && !empty($switcherCtx['bm_units'])) {
    $activeUnitId = (int)$switcherCtx['bm_units'][0];
} elseif ($hasImRole && !empty($switcherCtx['im_units'])) {
    $activeUnitId = (int)$switcherCtx['im_units'][0];
} elseif (!empty($switcherCtx['emp_unit_id'])) {
    $activeUnitId = (int)$switcherCtx['emp_unit_id'];
} elseif (!empty($switcherCtx['role_rows'][0]['business_unit_id'])) {
    $activeUnitId = (int)$switcherCtx['role_rows'][0]['business_unit_id'];
} else {
    $activeUnitId = 1;
}
$currentUnitId = $activeUnitId;

// Inventory Manager Mode resolution
if ($isSuperAdmin) {
    $isInvMode = !empty(\App\Core\Session::get('inventory_manager_mode'));
} else {
    $isInvMode = !empty(\App\Core\Session::get('inventory_manager_mode')) || (!$hasBmRole && $hasImRole);
}

// Temporary Inventory Access Key check
$isTempInv = !empty($isTempKeyAccess);
if (!$isTempInv && !empty($switcherCtx['employee_id'])) {
    $tempGrants = \App\Core\Session::get('temp_inventory_access', []);
    if (is_array($tempGrants)) {
        $empId = (int)$switcherCtx['employee_id'];
        foreach ($tempGrants as $bId => $g) {
            if (is_array($g) && (int)($g['employee_id'] ?? 0) === $empId && (int)$bId === (int)$currentUnitId) {
                $isTempInv = true;
                break;
            }
        }
    }
}

// Determine authorized business-grouped interfaces for the Switch Interface menu
$authorizedInterfaces = [];
if ($isSignedIn && $baseContext === 'employee' && $switcherCtx !== null) {
    $authorizedInterfaces = \App\Services\InterfaceSwitcherService::buildAuthorizedMenu(
        $switcherCtx,
        $curPath,
        $activeContext,
        $isWorkspaceRoute,
        $currentUnitId,
        $isInvMode,
        $activeWorkspaceInterface
    );
}

$inStaffWorkspace = $isSignedIn && $baseContext === 'employee' && $activeContext === 'employee' && $isWorkspaceRoute;
$showCustomerNav = $isSignedIn && ((bool)\App\Core\Session::get('customer_id') || $activeContext === 'customer' || !$isWorkspaceRoute);
$isAuthSignInOrOut = str_starts_with($curPath, '/login') || str_starts_with($curPath, '/staff/login') || str_starts_with($curPath, '/logout');
$isCalendarView = str_starts_with($curPath, '/grooming/receptionist/appointments')
    || str_starts_with($curPath, '/vetclinic/receptionist/appointments')
    || str_starts_with($curPath, '/manager/appointments');
$unitQuery = ($isSuperAdmin && isset($_GET['unit_id']) && ctype_digit((string)$_GET['unit_id'])) ? '?unit_id=' . (int)$_GET['unit_id'] : '';

$renderProfileMenu = static function (string $adminInitials, array $authorizedInterfaces, bool $isTempInv): void { ?>
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
                    <button type="button" class="profile-switcher-parent-btn <?= $iface['active'] ? 'is-current-parent' : '' ?>" data-target="<?= e($iface['id']) ?>" aria-expanded="<?= $iface['active'] ? 'true' : 'false' ?>" aria-controls="switcher-units-<?= e($iface['id']) ?>">
                      <span class="switcher-opt-indicator" aria-hidden="true"><?= $iface['active'] ? '●' : '○' ?></span>
                      <span class="switcher-opt-name"><?= e($iface['label']) ?></span>
                      <svg class="profile-switcher-parent-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"></polyline></svg>
                    </button>
                    <div class="profile-switcher-units" id="switcher-units-<?= e($iface['id']) ?>" <?= $iface['active'] ? '' : 'hidden' ?>>
                      <?php foreach ($iface['units'] as $u): ?>
                        <?php $isUnitCurrent = !empty($u['active']); ?>
                        <?php if (!empty($u['disabled'])): ?>
                          <span class="profile-switcher-unit-link is-disabled" role="menuitem" aria-disabled="true" style="opacity: 0.55; cursor: not-allowed;">
                            <span class="unit-dot" aria-hidden="true">▪</span>
                            <span class="unit-name"><?= e($u['name']) ?></span>
                          </span>
                        <?php else: ?>
                          <a href="<?= e($u['url']) ?>" class="profile-switcher-unit-link <?= $isUnitCurrent ? 'is-current-unit' : '' ?>" role="menuitem">
                            <span class="unit-dot" aria-hidden="true"><?= $isUnitCurrent ? '●' : '▪' ?></span>
                            <span class="unit-name"><?= e($u['name']) ?></span>
                            <?php if ($isUnitCurrent): ?>
                              <span class="unit-current">Current</span>
                            <?php endif; ?>
                          </a>
                        <?php endif; ?>
                      <?php endforeach; ?>
                    </div>
                  </div>
                <?php else: ?>
                  <a href="<?= e($iface['url']) ?>" class="profile-switcher-option <?= $iface['active'] ? 'is-current-interface' : '' ?>" role="menuitem">
                    <span class="switcher-opt-indicator" aria-hidden="true"><?= $iface['active'] ? '●' : '○' ?></span>
                    <span class="switcher-opt-name"><?= e($iface['label']) ?></span>
                    <?php if ($iface['active']): ?>
                      <span class="switcher-current">Current</span>
                    <?php endif; ?>
                  </a>
                <?php endif; ?>
              <?php endforeach; ?>
            </div>
          </div>
          <?php if ($isTempInv): ?>
            <form method="post" action="/staff/inventory-access/exit" class="profile-dropdown-form">
              <?= \App\Core\CSRF::field() ?>
              <button type="submit" class="profile-dropdown-item" role="menuitem">Exit Temporary Access</button>
            </form>
          <?php endif; ?>
          <button type="button" class="profile-dropdown-item" id="admin-dark-mode-toggle" role="menuitem">Dark Mode</button>
          <form method="post" action="/logout" class="profile-dropdown-form">
            <?= \App\Core\CSRF::field() ?>
            <button type="submit" class="profile-dropdown-item profile-dropdown-logout" role="menuitem">Log Out</button>
          </form>
        </div>
      </div>
<?php }; ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php
$rawTitle = trim((string)($title ?? ''));
$docTitle = ($curPath === '/' || $rawTitle === '' || $rawTitle === 'PawCariño')
    ? 'PawCariño'
    : ($rawTitle . ' | PawCariño');
?>
<title><?= e($docTitle) ?></title>
<link rel="icon" type="image/svg+xml" href="/assets/images/branding/favicon.svg">
<link rel="shortcut icon" href="/assets/images/branding/favicon.svg">
<link rel="apple-touch-icon" href="/assets/images/branding/pawcarino-emblem.svg">
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
<body class="<?= !empty($isCalendarView) ? 'has-calendar-view' : '' ?>">
<a class="skip" href="#main">Skip to content</a>
<header class="topbar" data-topbar>
  <div class="topbar-inner">
    <a class="brand" href="/" aria-label="PawCariño Home">
      <img
        class="brand-logo-horizontal"
        src="/assets/images/branding/pawcarino-logo-horizontal.svg"
        alt="PawCariño"
        width="165"
        height="44"
        decoding="async"
      >
    </a>
    <div class="topbar-mobile-actions">
      <?php if (!$inStaffWorkspace && !($isSignedIn && $baseContext === 'employee')): ?>
        <a href="/login" class="mobile-nav-signin <?= str_starts_with($curPath, '/login') ? 'is-current' : '' ?>">Sign in</a>
        <a href="/register" class="mobile-nav-signup <?= str_starts_with($curPath, '/register') ? 'is-current' : '' ?>">Sign up</a>
      <?php endif; ?>
      <button
        type="button"
        class="nav-mobile-toggle"
        id="mobile-nav-toggle"
        aria-expanded="false"
        aria-controls="main-nav"
        aria-label="Show more pages"
      >
        <svg class="nav-toggle-chevron" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <polyline points="6 9 12 15 18 9"></polyline>
        </svg>
      </button>
    </div>
    <nav id="main-nav" aria-label="Main">
<?php if ($inStaffWorkspace): ?>
  <?php if (str_starts_with($curPath, '/super-admin')): ?>
      <a href="/super-admin" class="<?= $curPath === '/super-admin' ? 'is-current' : '' ?>">Dashboard</a>
      <a href="/super-admin/business-units" class="<?= str_starts_with($curPath, '/super-admin/business-units') ? 'is-current' : '' ?>">Business Units</a>
      <a href="/super-admin/system-admins" class="<?= str_starts_with($curPath, '/super-admin/system-admins') ? 'is-current' : '' ?>">System Administrators</a>
      <a href="/super-admin/employees" class="<?= str_starts_with($curPath, '/super-admin/employees') ? 'is-current' : '' ?>">Employee Accounts</a>
      <a href="/super-admin/roles" class="<?= str_starts_with($curPath, '/super-admin/roles') ? 'is-current' : '' ?>">Roles &amp; Permissions</a>
      <a href="/super-admin/activity" class="<?= str_starts_with($curPath, '/super-admin/activity') ? 'is-current' : '' ?>">System Activity</a>

  <?php elseif (str_starts_with($curPath, '/sysadmin')): ?>
      <a href="/sysadmin<?= $unitQuery ?>" class="<?= ($curPath === '/sysadmin' || preg_match('#^/sysadmin/units/\d+$#', $curPath)) ? 'is-current' : '' ?>">Overview</a>
      <a href="/sysadmin/employees<?= $unitQuery ?>" class="<?= (str_starts_with($curPath, '/sysadmin/employees') || preg_match('#^/sysadmin/units/\d+/employees#', $curPath)) ? 'is-current' : '' ?>">Unit Employees</a>
      <a href="/sysadmin/roles<?= $unitQuery ?>" class="<?= (str_starts_with($curPath, '/sysadmin/roles') || preg_match('#^/sysadmin/units/\d+/roles#', $curPath)) ? 'is-current' : '' ?>">Roles &amp; Permissions</a>
      <a href="/sysadmin/activity<?= $unitQuery ?>" class="<?= (str_starts_with($curPath, '/sysadmin/activity') || preg_match('#^/sysadmin/units/\d+/activity#', $curPath)) ? 'is-current' : '' ?>">Activity Log</a>

  <?php elseif (str_starts_with($curPath, '/manager')): ?>
    <?php if ($isTempInv): ?>
      <a href="/manager/inventory" class="<?= str_starts_with($curPath, '/manager/inventory') ? 'is-current' : '' ?>">Inventory</a>
      <a href="/staff/workspace">&larr; Employee Workspace</a>
    <?php elseif ($isInvMode): ?>
      <?php if ((int)$currentUnitId === 1): /* Pet Store Inventory Manager */ ?>
        <a href="/manager/orders<?= $unitQuery ?>" class="<?= (str_contains($curPath, '/orders') && str_starts_with($curPath, '/manager')) ? 'is-current' : '' ?>">Orders</a>
        <a href="/manager/inventory<?= $unitQuery ?>" class="<?= (str_contains($curPath, '/inventory') && str_starts_with($curPath, '/manager')) ? 'is-current' : '' ?>">Inventory</a>
        <a href="/manager/employees<?= $unitQuery ?>" class="<?= (str_contains($curPath, '/employees') && str_starts_with($curPath, '/manager')) ? 'is-current' : '' ?>">Employees</a>
      <?php elseif ((int)$currentUnitId === 2): /* Veterinary Clinic Inventory Manager: EXACTLY 3 pages */ ?>
        <a href="/manager/inventory<?= $unitQuery ?>" class="<?= (str_contains($curPath, '/inventory') && str_starts_with($curPath, '/manager')) ? 'is-current' : '' ?>">Inventory</a>
        <a href="/manager/medical-records<?= $unitQuery ?>" class="<?= (str_contains($curPath, '/medical-records') && str_starts_with($curPath, '/manager')) ? 'is-current' : '' ?>">Medical Records</a>
        <a href="/manager/employees<?= $unitQuery ?>" class="<?= (str_contains($curPath, '/employees') && str_starts_with($curPath, '/manager')) ? 'is-current' : '' ?>">Employees</a>
      <?php else: /* Grooming Services Inventory Manager */ ?>
        <a href="/manager/inventory<?= $unitQuery ?>" class="<?= (str_contains($curPath, '/inventory') && str_starts_with($curPath, '/manager')) ? 'is-current' : '' ?>">Inventory</a>
        <a href="/manager/employees<?= $unitQuery ?>" class="<?= (str_contains($curPath, '/employees') && str_starts_with($curPath, '/manager')) ? 'is-current' : '' ?>">Employees</a>
      <?php endif; ?>
    <?php else: /* Business Manager */ ?>
      <?php if ((int)$currentUnitId === 1): /* Pet Store Business Manager */ ?>
        <a href="/manager<?= $unitQuery ?>" class="<?= ($curPath === '/manager' || preg_match('#^/manager/units/\d+(/overview)?$#', $curPath) || $curPath === '/manager/overview') ? 'is-current' : '' ?>">Overview</a>
        <a href="/manager/sales<?= $unitQuery ?>" class="<?= ((str_contains($curPath, '/sales') || str_contains($curPath, '/orders')) && !str_contains($curPath, '/refunds') && str_starts_with($curPath, '/manager')) ? 'is-current' : '' ?>">Sales</a>
        <a href="/manager/refunds<?= $unitQuery ?>" class="<?= (str_contains($curPath, '/refunds') && str_starts_with($curPath, '/manager')) ? 'is-current' : '' ?>">Refunds</a>
        <a href="/manager/remittances<?= $unitQuery ?>" class="<?= (str_contains($curPath, '/remittances') && str_starts_with($curPath, '/manager')) ? 'is-current' : '' ?>">Remittances</a>
        <a href="/manager/employees<?= $unitQuery ?>" class="<?= (str_contains($curPath, '/employees') && str_starts_with($curPath, '/manager')) ? 'is-current' : '' ?>">Employees</a>
        <a href="/manager/products<?= $unitQuery ?>" class="<?= ((str_contains($curPath, '/products') || str_contains($curPath, '/inventory')) && str_starts_with($curPath, '/manager')) ? 'is-current' : '' ?>">Products &amp; Inventory</a>
        <a href="/manager/settings<?= $unitQuery ?>" class="<?= (str_contains($curPath, '/settings') && str_starts_with($curPath, '/manager')) ? 'is-current' : '' ?>">Business Settings</a>
      <?php elseif ((int)$currentUnitId === 2): /* Veterinary Clinic Business Manager */ ?>
        <a href="/manager<?= $unitQuery ?>" class="<?= ($curPath === '/manager' || preg_match('#^/manager/units/\d+(/overview)?$#', $curPath) || $curPath === '/manager/overview') ? 'is-current' : '' ?>">Overview</a>
        <a href="/manager/appointments<?= $unitQuery ?>" class="<?= (str_contains($curPath, '/appointments') && str_starts_with($curPath, '/manager')) ? 'is-current' : '' ?>">Appointments</a>
        <a href="/manager/employees<?= $unitQuery ?>" class="<?= (str_contains($curPath, '/employees') && str_starts_with($curPath, '/manager')) ? 'is-current' : '' ?>">Employees</a>
        <a href="/manager/services<?= $unitQuery ?>" class="<?= (str_contains($curPath, '/services') && str_starts_with($curPath, '/manager')) ? 'is-current' : '' ?>">Services</a>
        <a href="/manager/inventory<?= $unitQuery ?>" class="<?= (str_contains($curPath, '/inventory') && str_starts_with($curPath, '/manager')) ? 'is-current' : '' ?>">Inventory</a>
        <a href="/manager/medical-records<?= $unitQuery ?>" class="<?= (str_contains($curPath, '/medical-records') && str_starts_with($curPath, '/manager')) ? 'is-current' : '' ?>">Medical Records</a>
        <a href="/manager/settings<?= $unitQuery ?>" class="<?= (str_contains($curPath, '/settings') && str_starts_with($curPath, '/manager')) ? 'is-current' : '' ?>">Business Settings</a>
      <?php else: /* Grooming Services Business Manager */ ?>
        <a href="/manager<?= $unitQuery ?>" class="<?= ($curPath === '/manager' || preg_match('#^/manager/units/\d+(/overview)?$#', $curPath) || $curPath === '/manager/overview') ? 'is-current' : '' ?>">Overview</a>
        <a href="/manager/appointments<?= $unitQuery ?>" class="<?= (str_contains($curPath, '/appointments') && str_starts_with($curPath, '/manager')) ? 'is-current' : '' ?>">Appointments</a>
        <a href="/manager/employees<?= $unitQuery ?>" class="<?= (str_contains($curPath, '/employees') && str_starts_with($curPath, '/manager')) ? 'is-current' : '' ?>">Employees</a>
        <a href="/manager/services<?= $unitQuery ?>" class="<?= (str_contains($curPath, '/services') && str_starts_with($curPath, '/manager')) ? 'is-current' : '' ?>">Services</a>
        <a href="/manager/inventory<?= $unitQuery ?>" class="<?= (str_contains($curPath, '/inventory') && str_starts_with($curPath, '/manager')) ? 'is-current' : '' ?>">Inventory</a>
        <a href="/manager/settings<?= $unitQuery ?>" class="<?= (str_contains($curPath, '/settings') && str_starts_with($curPath, '/manager')) ? 'is-current' : '' ?>">Business Settings</a>
      <?php endif; ?>
    <?php endif; ?>

  <?php elseif (str_starts_with($curPath, '/petstore/receptionist')): ?>
      <a href="/petstore/receptionist/overview" class="<?= ($curPath === '/petstore/receptionist' || str_starts_with($curPath, '/petstore/receptionist/overview') || str_starts_with($curPath, '/petstore/receptionist/sales')) ? 'is-current' : '' ?>">Overview</a>
      <a href="/petstore/receptionist/orders" class="<?= str_starts_with($curPath, '/petstore/receptionist/orders') ? 'is-current' : '' ?>">Orders</a>
      <a href="/petstore/receptionist/remittances" class="<?= str_starts_with($curPath, '/petstore/receptionist/remittances') ? 'is-current' : '' ?>">Remittances</a>
      <a href="/petstore/receptionist/employees" class="<?= str_starts_with($curPath, '/petstore/receptionist/employees') ? 'is-current' : '' ?>">Employees</a>

  <?php elseif (str_starts_with($curPath, '/grooming/receptionist')): ?>
      <a href="/grooming/receptionist/overview" class="<?= str_starts_with($curPath, '/grooming/receptionist/overview') ? 'is-current' : '' ?>">Overview</a>
      <a href="/grooming/receptionist/appointments" class="<?= str_starts_with($curPath, '/grooming/receptionist/appointments') ? 'is-current' : '' ?>">Appointments</a>
      <a href="/grooming/receptionist/billing" class="<?= str_starts_with($curPath, '/grooming/receptionist/billing') ? 'is-current' : '' ?>">Billing</a>
      <a href="/grooming/receptionist/records" class="<?= str_starts_with($curPath, '/grooming/receptionist/records') ? 'is-current' : '' ?>">Records</a>
      <a href="/grooming/receptionist/employees" class="<?= str_starts_with($curPath, '/grooming/receptionist/employees') ? 'is-current' : '' ?>">Employees</a>

  <?php elseif (str_starts_with($curPath, '/vetclinic/receptionist')): ?>
      <a href="/vetclinic/receptionist/overview" class="<?= str_starts_with($curPath, '/vetclinic/receptionist/overview') ? 'is-current' : '' ?>">Overview</a>
      <a href="/vetclinic/receptionist/appointments" class="<?= str_starts_with($curPath, '/vetclinic/receptionist/appointments') ? 'is-current' : '' ?>">Appointments</a>
      <a href="/vetclinic/receptionist/billing" class="<?= str_starts_with($curPath, '/vetclinic/receptionist/billing') ? 'is-current' : '' ?>">Billing</a>
      <a href="/vetclinic/receptionist/records" class="<?= str_starts_with($curPath, '/vetclinic/receptionist/records') ? 'is-current' : '' ?>">Records</a>
      <a href="/vetclinic/receptionist/employees" class="<?= str_starts_with($curPath, '/vetclinic/receptionist/employees') ? 'is-current' : '' ?>">Employees</a>

  <?php elseif (str_starts_with($curPath, '/delivery')): ?>
      <a href="/delivery/orders" class="<?= str_starts_with($curPath, '/delivery/orders') ? 'is-current' : '' ?>">Delivery Orders</a>

  <?php elseif (str_starts_with($curPath, '/staff') || $curPath === '/workspace'): ?>
      <a href="/staff/workspace" class="<?= ($curPath === '/staff/workspace' || $curPath === '/workspace') ? 'is-current' : '' ?>">Workspace</a>
      <a href="/customer-interface">Customer Interface</a>
  <?php endif; ?>

  <?php $renderProfileMenu($adminInitials, $authorizedInterfaces, $isTempInv); ?>

<?php else: /* Customer / Public navigation */ ?>
      <a href="/products" class="<?= (str_starts_with($curPath, '/products') || str_starts_with($curPath, '/shop')) ? 'is-current' : '' ?>">Products</a>
      <a href="/services" class="<?= str_starts_with($curPath, '/services') ? 'is-current' : '' ?>">Services</a>
      <a href="/staffs" class="<?= str_starts_with($curPath, '/staffs') ? 'is-current' : '' ?>">Staffs</a>
      <a href="/reviews" class="<?= str_starts_with($curPath, '/reviews') ? 'is-current' : '' ?>">Customer Reviews</a>
  <?php if ($isSignedIn && (bool)\App\Core\Session::get('customer_id') && ($baseContext !== 'employee' || $activeContext === 'customer')): ?>
      <span class="nav-divider" aria-hidden="true"></span>
      <a href="/appointments" class="<?= str_starts_with($curPath, '/appointments') ? 'is-current' : '' ?>">Appointments</a>
      <a href="/pets" class="<?= str_starts_with($curPath, '/pets') ? 'is-current' : '' ?>">My pets</a>
      <a href="/orders" class="<?= str_starts_with($curPath, '/orders') ? 'is-current' : '' ?>">Orders</a>
      <a href="/profile" class="<?= str_starts_with($curPath, '/profile') ? 'is-current' : '' ?>">Profile</a>
  <?php endif; ?>

  <?php if ($isSignedIn && $baseContext === 'employee'): ?>
    <?php $renderProfileMenu($adminInitials, $authorizedInterfaces, $isTempInv); ?>
  <?php else: ?>
      <span class="nav-divider nav-desktop-auth" aria-hidden="true"></span>
      <a href="/login" class="nav-desktop-auth <?= str_starts_with($curPath, '/login') ? 'is-current' : '' ?>">Sign in</a>
      <a class="nav-cta nav-desktop-auth" href="/register">Create account</a>
  <?php endif; ?>
<?php endif; ?>
    </nav>
  </div>
</header>
<?php if (!$isAuthSignInOrOut && empty($isCalendarView)): ?>
  <?php require APP_ROOT . '/app/Views/partials/scenic_rails.php'; ?>
<?php endif; ?>
<main id="main">
<?php foreach ($flashes as [$type, $msg]): ?>
  <div class="flash flash-<?= e($type) ?>" role="<?= $type === 'error' ? 'alert' : 'status' ?>"><?= e($msg) ?></div>
<?php endforeach; ?>
<?= $content ?>
</main>
<?php if ($inStaffWorkspace): ?>
<footer class="foot foot-workspace">
  <div class="foot-workspace-inner">
    <a class="foot-logo-link" href="/" aria-label="PawCariño Home">
      <img class="foot-logo-compact" src="/assets/images/branding/pawcarino-logo-horizontal.svg" alt="PawCariño" width="126" height="34" loading="lazy" decoding="async">
    </a>
    <span>PawCariño · Pet Store · Veterinary Clinic · Grooming Services · Sample data marked [SAMPLE]</span>
  </div>
</footer>
<?php else: ?>
<footer class="foot foot-customer">
  <div class="foot-customer-inner">
    <div class="foot-brand-col">
      <a class="foot-logo-link" href="/" aria-label="PawCariño Home">
        <img class="foot-logo-horizontal" src="/assets/images/branding/pawcarino-logo-horizontal.svg" alt="PawCariño" width="150" height="40" loading="lazy" decoding="async">
      </a>
      <p class="foot-tagline">Veterinary Clinic · Grooming Services · Pet Store</p>
    </div>
    <nav class="foot-links" aria-label="Footer">
      <a href="/products">Products</a>
      <a href="/services">Services</a>
      <a href="/staffs">Staffs</a>
      <a href="/reviews">Customer Reviews</a>
      <?php if (!$isSignedIn): ?>
        <a href="/login">Sign in</a>
        <a href="/register">Create account</a>
      <?php endif; ?>
    </nav>
  </div>
  <div class="foot-meta">PawCariño · Pet Store · Veterinary Clinic · Grooming Services · Sample data marked [SAMPLE]</div>
</footer>
<?php endif; ?>
<script src="/assets/js/app.js?v=<?= file_exists(APP_ROOT . '/public/assets/js/app.js') ? filemtime(APP_ROOT . '/public/assets/js/app.js') : time() ?>" defer></script>
</body>
</html>
