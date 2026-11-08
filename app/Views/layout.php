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
$sysAdminUnitId = null;
$sysAdminUnitName = '';
$adminInitials = 'SA';

if ($isSignedIn && $baseContext === 'employee') {
    $uid = (int)\App\Core\Session::get('user_id');
    static $adminUserCache = [];
    if (!isset($adminUserCache[$uid])) {
        $db = \App\Core\Database::pdo();
        $stmt = $db->prepare(
            "SELECT ua.is_super_admin, e.first_name, e.last_name, e.business_unit_id, bu.business_unit_name,
                    (SELECT 1 FROM account_role ar JOIN role r ON r.role_id = ar.role_id WHERE ar.user_account_id = ua.user_account_id AND r.role_name = 'SYSTEM_ADMINISTRATOR' LIMIT 1) AS has_sysadmin_role
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
  <?php if ($isSuperAdmin): ?>
      <a href="/super-admin" class="<?= $curPath === '/super-admin' ? 'is-current' : '' ?>">Dashboard</a>
      <a href="/super-admin/business-units" class="<?= str_starts_with($curPath, '/super-admin/business-units') ? 'is-current' : '' ?>">Business Units</a>
      <a href="/super-admin/system-admins" class="<?= str_starts_with($curPath, '/super-admin/system-admins') ? 'is-current' : '' ?>">System Administrators</a>
      <a href="/super-admin/employees" class="<?= str_starts_with($curPath, '/super-admin/employees') ? 'is-current' : '' ?>">Employee Accounts</a>
      <a href="/super-admin/roles" class="<?= str_starts_with($curPath, '/super-admin/roles') ? 'is-current' : '' ?>">Roles &amp; Permissions</a>
      <a href="/super-admin/activity" class="<?= str_starts_with($curPath, '/super-admin/activity') ? 'is-current' : '' ?>">System Activity</a>
  <?php elseif ($isSysAdmin): ?>
      <a href="/sysadmin" class="<?= ($curPath === '/sysadmin' || preg_match('#^/sysadmin/units/\d+$#', $curPath)) ? 'is-current' : '' ?>">Overview</a>
      <a href="/sysadmin/employees" class="<?= (str_starts_with($curPath, '/sysadmin/employees') || preg_match('#^/sysadmin/units/\d+/employees#', $curPath)) ? 'is-current' : '' ?>">Unit Employees</a>
      <a href="/sysadmin/roles" class="<?= (str_starts_with($curPath, '/sysadmin/roles') || preg_match('#^/sysadmin/units/\d+/roles#', $curPath)) ? 'is-current' : '' ?>">Roles &amp; Permissions</a>
      <a href="/sysadmin/activity" class="<?= (str_starts_with($curPath, '/sysadmin/activity') || preg_match('#^/sysadmin/units/\d+/activity#', $curPath)) ? 'is-current' : '' ?>">Activity Log</a>
  <?php endif; ?>
      <span class="nav-divider" aria-hidden="true"></span>
      <div class="profile-menu">
        <button type="button" class="profile-avatar-btn" id="admin-profile-toggle" aria-expanded="false" aria-haspopup="true" aria-label="Administrator profile menu">
          <span class="profile-avatar"><?= e($adminInitials) ?></span>
        </button>
        <div class="profile-dropdown" id="admin-profile-dropdown" role="menu" hidden>
          <a href="/customer-interface" class="profile-dropdown-item" role="menuitem">Switch Interface</a>
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
          <a href="/workspace" class="profile-dropdown-item" role="menuitem">Switch Interface</a>
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
