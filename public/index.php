<?php
declare(strict_types=1);

// PHP built-in server: let it serve existing static files (CSS, JS, images) directly.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if ($file !== __DIR__ . '/' && is_file($file)) {
        return false;
    }
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Router;

$r = new Router();

$r->get('/', 'HomeController', 'index');
$r->get('/search', 'HomeController', 'search');
$r->get('/products', 'ShopController', 'index');
$r->get('/services', 'HomeController', 'services');
$r->get('/staffs', 'HomeController', 'staffs');
$r->get('/reviews', 'HomeController', 'reviews');
$r->post('/reviews', 'HomeController', 'storeReview');

// Authentication & Session Context Switching
$r->get('/login', 'AuthController', 'loginEmailForm');
$r->post('/login', 'AuthController', 'loginPassword');
$r->post('/login/email', 'AuthController', 'loginEmail');
$r->get('/login/password', 'AuthController', 'loginPasswordForm');
$r->post('/login/password', 'AuthController', 'loginPassword');
$r->get('/register', 'AuthController', 'registerEmailForm');
$r->post('/register', 'AuthController', 'registerDetails');
$r->post('/register/email', 'AuthController', 'registerEmail');
$r->get('/register/details', 'AuthController', 'registerDetailsForm');
$r->post('/register/details', 'AuthController', 'registerDetails');
$r->post('/logout', 'AuthController', 'logout');
$r->post('/demo/super-admin', 'AuthController', 'demoSuperAdmin');
$r->get('/customer-interface', 'AuthController', 'enterCustomerInterface');
$r->get('/workspace', 'AuthController', 'returnToWorkspace');
$r->post('/session/context', 'AuthController', 'switchContext');

// Profile + pets
$r->get('/profile', 'ProfileController', 'show');
$r->post('/profile', 'ProfileController', 'update');
$r->get('/pets', 'PetController', 'index');
$r->get('/pets/new', 'PetController', 'create');
$r->post('/pets', 'PetController', 'store');
$r->get('/pets/{id}/edit', 'PetController', 'edit');
$r->post('/pets/{id}', 'PetController', 'update');

// Grooming appointments (customer)
$r->get('/appointments', 'AppointmentController', 'index');
$r->get('/appointments/new', 'AppointmentController', 'create');
$r->post('/appointments', 'AppointmentController', 'store');
$r->get('/appointments/{id}', 'AppointmentController', 'show');

// Shop, carts, orders
$r->get('/shop', 'ShopController', 'index');
$r->get('/shop/{bu}', 'ShopController', 'catalog');
$r->get('/shop/{bu}/product/{id}', 'ShopController', 'product');
$r->get('/cart/{bu}', 'CartController', 'show');
$r->post('/cart/{bu}/add', 'CartController', 'add');
$r->post('/cart/{bu}/update', 'CartController', 'update');
$r->post('/cart/{bu}/remove', 'CartController', 'remove');
$r->get('/checkout/{bu}', 'OrderController', 'checkout');
$r->post('/checkout/{bu}', 'OrderController', 'place');
$r->get('/orders', 'OrderController', 'index');
$r->get('/orders/{id}', 'OrderController', 'show');

// Staff & Employee Workspace
$r->get('/staff/login', 'StaffController', 'loginForm');
$r->post('/staff/login', 'StaffController', 'login');
$r->get('/staff/workspace', 'StaffController', 'workspace');
$r->post('/staff/inventory-access', 'StaffController', 'redeemInventoryKey');
$r->post('/staff/inventory-access/exit', 'StaffController', 'exitInventoryAccess');
$r->get('/staff/appointments', 'StaffController', 'appointments');
$r->get('/staff/appointments/{id}', 'StaffController', 'appointment');
$r->post('/staff/appointments/{id}/status', 'StaffController', 'setStatus');
$r->post('/staff/appointments/{id}/cancel', 'StaffController', 'cancelAppointment');
$r->post('/staff/appointments/{id}/reassign', 'StaffController', 'reassignAppointment');
$r->post('/staff/appointments/{id}/progress', 'StaffController', 'updateProgress');
$r->post('/staff/appointments/{id}/complete-service', 'StaffController', 'completeService');

// Business Manager Interface
$r->get('/manager', 'ManagerController', 'dashboard');
$r->get('/manager/overview', 'ManagerController', 'dashboard');
$r->get('/manager/units/{id}', 'ManagerController', 'dashboard');
$r->get('/manager/units/{id}/overview', 'ManagerController', 'dashboard');
$r->get('/manager/appointments', 'ManagerController', 'appointments');
$r->get('/manager/units/{id}/appointments', 'ManagerController', 'appointments');
$r->post('/manager/appointments/{id}/reassign', 'ManagerController', 'reassignAppointment');
$r->post('/manager/appointments/{id}/cancel', 'ManagerController', 'cancelAppointment');
$r->post('/manager/appointments/{id}/status', 'ManagerController', 'updateAppointmentStatus');
$r->post('/manager/appointments/{id}/complete', 'ManagerController', 'completeAppointment');
$r->get('/manager/services', 'ManagerController', 'services');
$r->get('/manager/units/{id}/services', 'ManagerController', 'services');
$r->post('/manager/services', 'ManagerController', 'storeService');
$r->post('/manager/units/{id}/services', 'ManagerController', 'storeService');
$r->post('/manager/services/{id}/update', 'ManagerController', 'updateService');
$r->post('/manager/services/{id}', 'ManagerController', 'updateService');
$r->post('/manager/units/{unit_id}/services/{id}/update', 'ManagerController', 'updateServiceUnit');
$r->post('/manager/units/{unit_id}/services/{id}', 'ManagerController', 'updateServiceUnit');
$r->post('/manager/services/{id}/status', 'ManagerController', 'toggleServiceStatus');
$r->post('/manager/services/{id}/toggle-status', 'ManagerController', 'toggleServiceStatus');
$r->post('/manager/units/{unit_id}/services/{id}/status', 'ManagerController', 'toggleServiceStatusUnit');
$r->post('/manager/units/{unit_id}/services/{id}/toggle-status', 'ManagerController', 'toggleServiceStatusUnit');
$r->get('/manager/sales', 'ManagerController', 'sales');
$r->get('/manager/units/{id}/sales', 'ManagerController', 'sales');
$r->post('/manager/sales/refunds/{id}/approve', 'ManagerController', 'approveRefund');
$r->post('/manager/sales/refunds/{id}/reject', 'ManagerController', 'rejectRefund');
$r->post('/manager/units/{id}/sales/refunds/{ref_id}/approve', 'ManagerController', 'approveRefundUnit');
$r->post('/manager/units/{id}/sales/refunds/{ref_id}/reject', 'ManagerController', 'rejectRefundUnit');
$r->get('/manager/products', 'ManagerController', 'products');
$r->get('/manager/units/{id}/products', 'ManagerController', 'products');
$r->post('/manager/products', 'ManagerController', 'storeProduct');
$r->post('/manager/units/{id}/products', 'ManagerController', 'storeProduct');
$r->post('/manager/products/{id}/update', 'ManagerController', 'updateProduct');
$r->post('/manager/products/{id}/status', 'ManagerController', 'toggleProductStatus');
$r->get('/manager/inventory', 'ManagerController', 'inventory');
$r->get('/manager/units/{id}/inventory', 'ManagerController', 'inventory');
$r->post('/manager/inventory/generate-key', 'ManagerController', 'generateInventoryKey');
$r->post('/manager/units/{id}/inventory/generate-key', 'ManagerController', 'generateInventoryKey');
$r->post('/manager/inventory/adjust', 'ManagerController', 'adjustStock');
$r->post('/manager/units/{id}/inventory/adjust', 'ManagerController', 'adjustStock');
$r->post('/manager/inventory/purchase-orders', 'ManagerController', 'storePurchaseOrder');
$r->post('/manager/units/{id}/inventory/purchase-orders', 'ManagerController', 'storePurchaseOrder');
$r->post('/manager/inventory/purchase-orders/{id}/receive', 'ManagerController', 'receiveStock');
$r->get('/manager/employees', 'ManagerController', 'employees');
$r->get('/manager/units/{id}/employees', 'ManagerController', 'employees');
$r->get('/manager/settings', 'ManagerController', 'settings');
$r->get('/manager/units/{id}/settings', 'ManagerController', 'settings');
$r->post('/manager/settings/profile', 'ManagerController', 'updateProfile');
$r->post('/manager/units/{id}/settings/profile', 'ManagerController', 'updateProfile');
$r->post('/manager/settings/hours', 'ManagerController', 'updateOperatingHours');
$r->post('/manager/units/{id}/settings/hours', 'ManagerController', 'updateOperatingHours');
$r->post('/manager/settings/send-otp', 'ManagerController', 'sendOtp');
$r->post('/manager/units/{id}/settings/send-otp', 'ManagerController', 'sendOtp');
$r->post('/manager/settings/verify-otp', 'ManagerController', 'verifyOtp');
$r->post('/manager/units/{id}/settings/verify-otp', 'ManagerController', 'verifyOtp');
$r->post('/manager/settings/payment', 'ManagerController', 'updatePaymentSettings');
$r->post('/manager/units/{id}/settings/payment', 'ManagerController', 'updatePaymentSettings');

// System Administrator Interface (Business-Unit Scoped)
$r->get('/sysadmin', 'SysAdminController', 'dashboard');
$r->get('/sysadmin/units/{id}', 'SysAdminController', 'dashboard');
$r->get('/sysadmin/employees', 'SysAdminController', 'employees');
$r->post('/sysadmin/employees', 'SysAdminController', 'storeEmployee');
$r->get('/sysadmin/units/{id}/employees', 'SysAdminController', 'employees');
$r->post('/sysadmin/units/{id}/employees', 'SysAdminController', 'storeEmployee');
$r->post('/sysadmin/employees/{id}/roles', 'SysAdminController', 'updateEmployeeRoles');
$r->post('/sysadmin/employees/{id}/status', 'SysAdminController', 'updateEmployeeStatus');
$r->post('/sysadmin/employees/{id}/position', 'SysAdminController', 'updateEmployeePosition');
$r->get('/sysadmin/activity', 'SysAdminController', 'activity');
$r->get('/sysadmin/units/{id}/activity', 'SysAdminController', 'activity');
$r->get('/sysadmin/positions', 'SysAdminController', 'positions');
$r->post('/sysadmin/positions', 'SysAdminController', 'storePosition');
$r->get('/sysadmin/units/{id}/positions', 'SysAdminController', 'positions');
$r->post('/sysadmin/units/{id}/positions', 'SysAdminController', 'storePosition');
$r->post('/sysadmin/positions/{id}/toggle', 'SysAdminController', 'togglePosition');
$r->get('/sysadmin/roles', 'SysAdminController', 'roles');
$r->get('/sysadmin/units/{id}/roles', 'SysAdminController', 'roles');

// Super Administrator Interface (System-Wide)
$r->get('/super-admin', 'SuperAdminController', 'dashboard');
$r->get('/super-admin/business-units', 'SuperAdminController', 'businessUnits');
$r->get('/super-admin/system-admins', 'SuperAdminController', 'systemAdmins');
$r->post('/super-admin/system-admins', 'SuperAdminController', 'storeSystemAdmin');
$r->get('/super-admin/employees', 'SuperAdminController', 'employees');
$r->post('/super-admin/employees', 'SuperAdminController', 'storeEmployee');
$r->post('/super-admin/accounts/{id}/status', 'SuperAdminController', 'updateAccountStatus');
$r->get('/super-admin/roles', 'SuperAdminController', 'roles');
$r->get('/super-admin/activity', 'SuperAdminController', 'activity');

$r->dispatch($_SERVER['REQUEST_URI'] ?? '/', $_SERVER['REQUEST_METHOD'] ?? 'GET');
