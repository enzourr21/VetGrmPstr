<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\CSRF;
use App\Core\Session;
use App\Models\Audit;
use App\Models\Units;
use App\Services\CustomerRegistrationPendingService;
use PDO;
use PDOException;
use Throwable;

final class AuthController extends Controller
{
    private const MAX_FAILS = 5;
    private const LOCK_SECONDS = 60;

    private function validEmail(string $email): bool
    {
        return strlen($email) <= 255 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    private function validUsername(string $username): bool
    {
        return preg_match('/^[a-z0-9_.]{3,60}$/i', $username) === 1;
    }

    private function validIdentifier(string $id): bool
    {
        return str_contains($id, '@') ? $this->validEmail($id) : $this->validUsername($id);
    }

    private function wantsJson(): bool
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $xhr = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
        return str_contains($accept, 'application/json') || strtolower($xhr) === 'xmlhttprequest';
    }

    private function safeReturn(string $fallback = '/'): string
    {
        $to = (string)Session::get('return_to', $fallback);
        Session::forget('return_to');
        return (str_starts_with($to, '/') && !str_starts_with($to, '//') && !str_contains($to, "\n")) ? $to : $fallback;
    }

    public static function isDemoEnvironmentAllowed(): bool
    {
        $envPath = dirname(__DIR__, 2) . '/.env';
        $appEnv = getenv('APP_ENV') ?: 'local';
        if (is_file($envPath)) {
            $parsed = @parse_ini_file($envPath, false, INI_SCANNER_RAW);
            if (is_array($parsed) && isset($parsed['APP_ENV'])) {
                $appEnv = strtolower(trim((string)$parsed['APP_ENV']));
            }
        }
        if ($appEnv === 'production' || $appEnv === 'prod') {
            return false;
        }
        $remote = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        return in_array($remote, ['127.0.0.1', '::1', 'localhost'], true);
    }

    /**
     * Determine the authorized landing URL for an email-authenticated employee/admin session.
     */
    public static function resolveEmployeeLandingFor(PDO $db, int $userId, bool $isSuperAdmin): string
    {
        if ($isSuperAdmin) {
            return '/super-admin';
        }

        $stmt = $db->prepare(
            "SELECT r.role_name, bu.business_unit_name
             FROM account_role ar
             JOIN role r ON r.role_id = ar.role_id
             JOIN business_unit bu ON bu.business_unit_id = ar.business_unit_id
             WHERE ar.user_account_id = ?"
        );
        $stmt->execute([$userId]);
        $roles = $stmt->fetchAll();

        $roleNames = array_column($roles, 'role_name');
        if (in_array('SUPER_ADMINISTRATOR', $roleNames, true)) {
            return '/super-admin';
        }
        if (in_array('SYSTEM_ADMINISTRATOR', $roleNames, true)) {
            return '/sysadmin';
        }
        if (in_array('BUSINESS_MANAGER', $roleNames, true)) {
            return '/manager';
        }
        foreach ($roles as $r) {
            if ($r['role_name'] === 'INVENTORY_MANAGER') {
                if ($r['business_unit_name'] === Units::PET_STORE || (int)($r['business_unit_id'] ?? 0) === 1) {
                    return '/manager/orders';
                }
                return '/manager/inventory';
            }
        }
        foreach ($roles as $r) {
            if ($r['role_name'] === 'RECEPTIONIST') {
                if ($r['business_unit_name'] === Units::PET_STORE || (int)($r['business_unit_id'] ?? 0) === 1) {
                    return '/petstore/receptionist/sales';
                }
                if ($r['business_unit_name'] === Units::VET_CLINIC || (int)($r['business_unit_id'] ?? 0) === 2) {
                    return '/vetclinic/receptionist/overview';
                }
                if ($r['business_unit_name'] === Units::GROOMING || (int)($r['business_unit_id'] ?? 0) === 3) {
                    return '/grooming/receptionist/overview';
                }
            }
        }
        return '/staff/workspace';
    }

    /**
     * Ensure a customer record exists for a user account (used when an employee/admin logs in with username
     * or switches into the Customer interface).
     */
    public static function ensureCustomerProfile(PDO $db, int $userId, ?int $existingCustomerId): int
    {
        if ($existingCustomerId !== null && $existingCustomerId > 0) {
            return $existingCustomerId;
        }
        $s = $db->prepare('SELECT customer_id FROM customer WHERE user_account_id = ?');
        $s->execute([$userId]);
        $cid = (int)$s->fetchColumn();
        if ($cid > 0) {
            return $cid;
        }

        $emp = $db->prepare('SELECT first_name, last_name, phone FROM employee WHERE user_account_id = ?');
        $emp->execute([$userId]);
        $eRow = $emp->fetch();
        $first = $eRow['first_name'] ?? 'Customer';
        $last = $eRow['last_name'] ?? 'Account';
        $phone = $eRow['phone'] ?? null;

        $db->prepare(
            'INSERT INTO customer (user_account_id, first_name, last_name, phone, customer_status)
             VALUES (?, ?, ?, ?, "active")'
        )->execute([$userId, $first, $last, $phone]);

        return (int)$db->lastInsertId();
    }

    // ---- Sign In (single-page progressive disclosure) ------------------

    public function loginEmailForm(): void
    {
        Session::forget('pending_email');
        $this->render('auth/login', [
            'title' => 'Sign in',
            'email' => '',
            'emailUnlocked' => false,
            'errors' => [],
        ]);
    }

    public function loginEmail(): void
    {
        CSRF::check();
        $raw = $this->post('email') !== '' ? $this->post('email') : ($this->post('username') !== '' ? $this->post('username') : $this->post('identifier'));
        $identifier = strtolower($raw);
        if (!$this->validIdentifier($identifier)) {
            if ($this->wantsJson()) {
                $this->jsonError('Enter a valid email address, for example name@example.com.', 422);
            }
            http_response_code(422);
            Session::forget('pending_email');
            Session::flash('error', 'Enter a valid email address, for example name@example.com.');
            $this->render('auth/login', [
                'title' => 'Sign in',
                'email' => $identifier,
                'emailUnlocked' => false,
                'errors' => ['email' => 'Enter a valid email address, for example name@example.com.'],
            ]);
            return;
        }
        Session::set('pending_email', $identifier);
        if ($this->wantsJson()) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['ok' => true, 'email' => $identifier, 'csrf_token' => CSRF::token()]);
            exit;
        }
        $this->render('auth/login', [
            'title' => 'Sign in',
            'email' => $identifier,
            'emailUnlocked' => true,
            'errors' => [],
        ]);
    }

    public function loginPasswordForm(): void
    {
        $identifier = (string)Session::get('pending_email', '');
        if (!$this->validIdentifier($identifier)) {
            $this->redirect('/login');
        }
        $this->render('auth/login', [
            'title' => 'Sign in',
            'email' => $identifier,
            'emailUnlocked' => true,
            'errors' => [],
        ]);
    }

    public function loginPassword(): void
    {
        CSRF::check();
        $raw = $this->post('email') !== ''
            ? $this->post('email')
            : ($this->post('username') !== '' ? $this->post('username') : $this->post('identifier'));
        $postedIdentifier = strtolower($raw);
        $identifier = $postedIdentifier !== '' ? $postedIdentifier : (string)Session::get('pending_email', '');

        // Server-side guard: always validate email or username syntax before proceeding to password verification
        if (!$this->validIdentifier($identifier)) {
            http_response_code(422);
            Session::forget('pending_email');
            Session::flash('error', 'Enter a valid email address, for example name@example.com.');
            $this->render('auth/login', [
                'title' => 'Sign in',
                'email' => $identifier,
                'emailUnlocked' => false,
                'errors' => ['email' => 'Enter a valid email address, for example name@example.com.'],
            ]);
            return;
        }
        Session::set('pending_email', $identifier);

        // If submitted without password field (e.g. non-JS progressive step 1), unlock password step on same page
        if (!array_key_exists('password', $_POST)) {
            $this->render('auth/login', [
                'title' => 'Sign in',
                'email' => $identifier,
                'emailUnlocked' => true,
                'errors' => [],
            ]);
            return;
        }

        if ((int)Session::get('fails', 0) >= self::MAX_FAILS && time() < (int)Session::get('lock_until', 0)) {
            http_response_code(429);
            Session::flash('error', 'Too many attempts. Please wait a minute and try again.');
            $this->render('auth/login', [
                'title' => 'Sign in',
                'email' => $identifier,
                'emailUnlocked' => true,
                'errors' => [],
            ]);
            return;
        }

        $password = $_POST['password'] ?? '';
        $password = is_string($password) ? $password : '';
        $isEmail = str_contains($identifier, '@');

        if ($isEmail) {
            $s = $this->db->prepare('SELECT ua.user_account_id, ua.email, ua.username, ua.password_hash, ua.account_status, ua.is_super_admin,
                    (SELECT customer_id FROM customer c WHERE c.user_account_id = ua.user_account_id AND c.customer_status="active") AS customer_id,
                    (SELECT employee_id FROM employee e WHERE e.user_account_id = ua.user_account_id AND e.employee_status="active") AS employee_id
                FROM user_account ua WHERE ua.email = ?');
        } else {
            $s = $this->db->prepare('SELECT ua.user_account_id, ua.email, ua.username, ua.password_hash, ua.account_status, ua.is_super_admin,
                    (SELECT customer_id FROM customer c WHERE c.user_account_id = ua.user_account_id AND c.customer_status="active") AS customer_id,
                    (SELECT employee_id FROM employee e WHERE e.user_account_id = ua.user_account_id AND e.employee_status="active") AS employee_id
                FROM user_account ua WHERE ua.username = ?');
        }
        $s->execute([$identifier]);
        $acct = $s->fetch();

        // Always run one password_verify so response time does not reveal whether the account exists.
        static $dummy = null;
        $dummy ??= password_hash('not-a-real-password', PASSWORD_DEFAULT);
        $ok = password_verify($password, $acct['password_hash'] ?? $dummy);

        if (!$acct || !$ok || $acct['account_status'] !== 'active') {
            Session::set('fails', (int)Session::get('fails', 0) + 1);
            if ((int)Session::get('fails') >= self::MAX_FAILS) {
                Session::set('lock_until', time() + self::LOCK_SECONDS);
            }
            http_response_code(401);
            Session::flash('error', 'Incorrect email or password.');
            $this->render('auth/login', [
                'title' => 'Sign in',
                'email' => $identifier,
                'emailUnlocked' => true,
                'errors' => [],
            ]);
            return;
        }

        if (password_needs_rehash($acct['password_hash'], PASSWORD_DEFAULT)) {
            $this->db->prepare('UPDATE user_account SET password_hash=? WHERE user_account_id=?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $acct['user_account_id']]);
        }

        $uid = (int)$acct['user_account_id'];
        $forceCustomerMode = ($this->post('login_context') === 'customer');
        $hasEmployeeIdentity = ($acct['employee_id'] !== null || (bool)$acct['is_super_admin']);

        // Priority 3 rules:
        // - Any username login -> customer-only session (even for employees, System Admins, or Super Admin)
        // - Customer login mode or customer-only account -> customer-only session
        // - Employee/Admin email login -> authorized employee/admin session
        if (!$isEmail || $forceCustomerMode || !$hasEmployeeIdentity) {
            $cid = self::ensureCustomerProfile(
                $this->db,
                $uid,
                $acct['customer_id'] !== null ? (int)$acct['customer_id'] : null
            );
            Session::login([
                'user_id' => $uid,
                'email' => (string)$acct['email'],
                'username' => $acct['username'] ?? null,
                'customer_id' => $cid,
                'employee_id' => null,
                'auth_method' => $isEmail ? 'email' : 'username',
                'base_context' => 'customer',
                'active_context' => 'customer',
                'is_super_admin' => false,
                'fails' => 0,
            ]);
            Session::forget('pending_email');
            Audit::log('auth.login.customer', "Authenticated in customer-only session via " . ($isEmail ? 'email' : 'username') . ".", null, 'user_account', $uid, $uid);
            $this->redirect($this->safeReturn('/'));
        }

        // Email login by an employee, Business Manager, System Administrator, or Super Administrator
        $isSuper = (bool)$acct['is_super_admin'];
        $landing = self::resolveEmployeeLandingFor($this->db, $uid, $isSuper);
        Session::login([
            'user_id' => $uid,
            'email' => (string)$acct['email'],
            'username' => $acct['username'] ?? null,
            'customer_id' => $acct['customer_id'] !== null ? (int)$acct['customer_id'] : null,
            'employee_id' => $acct['employee_id'] !== null ? (int)$acct['employee_id'] : null,
            'auth_method' => 'email',
            'base_context' => 'employee',
            'active_context' => 'employee',
            'is_super_admin' => $isSuper,
            'fails' => 0,
        ]);
        Session::forget('pending_email');
        Session::forget('inventory_manager_mode');
        Audit::log('auth.login.employee', "Authenticated in employee/admin session via email ($landing).", null, 'user_account', $uid, $uid);
        $this->redirect($landing);
    }

    /**
     * Controlled Demo Navigation Shortcut (Priority 1.6):
     * Routes directly to the Super Administrator entry point in local development/demo environments.
     */
    public function demoSuperAdmin(): void
    {
        CSRF::check();
        if (!self::isDemoEnvironmentAllowed()) {
            $this->forbidden('Demo shortcut is restricted to local development/demo environments.');
        }

        $acct = $this->db->query(
            "SELECT ua.user_account_id, ua.email, ua.username, ua.is_super_admin,
                    (SELECT customer_id FROM customer c WHERE c.user_account_id = ua.user_account_id AND c.customer_status='active') AS customer_id,
                    (SELECT employee_id FROM employee e WHERE e.user_account_id = ua.user_account_id AND e.employee_status='active') AS employee_id
             FROM user_account ua
             WHERE ua.is_super_admin = 1 AND ua.account_status = 'active'
             ORDER BY ua.user_account_id LIMIT 1"
        )->fetch();

        if (!$acct) {
            Session::flash('error', 'Super Administrator account has not been provisioned yet. Run php database/seed.php first.');
            $this->redirect('/login');
        }

        $uid = (int)$acct['user_account_id'];
        Session::login([
            'user_id' => $uid,
            'email' => (string)$acct['email'],
            'username' => $acct['username'] ?? null,
            'customer_id' => $acct['customer_id'] !== null ? (int)$acct['customer_id'] : null,
            'employee_id' => $acct['employee_id'] !== null ? (int)$acct['employee_id'] : null,
            'auth_method' => 'email',
            'base_context' => 'employee',
            'active_context' => 'employee',
            'is_super_admin' => true,
            'fails' => 0,
        ]);
        Session::forget('inventory_manager_mode');
        Audit::log('super_admin.demo_access', 'Accessed Super Administrator portal via local demo navigation button.', null, 'user_account', $uid, $uid);
        Session::flash('info', 'Signed in to the Super Administrator demonstration session.');
        $this->redirect('/super-admin');
    }

    /**
     * Priority 3.5: Enter the Customer interface from an authenticated employee session
     * while preserving the underlying employee session and without exposing employee privileges.
     */
    public function enterCustomerInterface(): void
    {
        $uid = Session::get('user_id');
        if (!$uid) {
            $this->redirect('/login');
        }
        $cid = self::ensureCustomerProfile(
            $this->db,
            (int)$uid,
            Session::get('customer_id') ? (int)Session::get('customer_id') : null
        );
        Session::set('customer_id', $cid);
        Session::set('active_context', 'customer');
        Session::flash('info', 'Viewing VETGRPET in Customer interface mode.');
        $this->redirect('/');
    }

    /**
     * Priority 3.5: Return from Customer interface context back to the employee's authorized interface.
     * Customer-only sessions (including username logins) are strictly denied.
     */
    public function returnToWorkspace(): void
    {
        $uid = Session::get('user_id');
        if (!$uid) {
            $this->redirect('/login');
        }
        if (Session::get('base_context') !== 'employee' || Session::get('auth_method') !== 'email') {
            $this->forbidden('Customer-only sessions cannot enter employee or administrative workspaces.');
        }
        Session::set('active_context', 'employee');
        $isSuper = $this->isSuperAdmin();
        $landing = self::resolveEmployeeLandingFor($this->db, (int)$uid, $isSuper);
        $this->redirect($landing);
    }

    public function switchContext(): void
    {
        CSRF::check();
        $target = $this->post('context', 'customer');
        if ($target === 'employee') {
            $this->returnToWorkspace();
        }
        $this->enterCustomerInterface();
    }

    /**
     * Interface Switcher: allows authenticated users to switch between interfaces
     * they are legitimately authorized to access (Super Admin, System Admin, Business Manager, Employee, Customer).
     */
    public function switchInterface(): void
    {
        $uid = Session::get('user_id');
        if (!$uid) {
            $this->redirect('/login');
        }

        $target = strtolower(trim((string)($_GET['target'] ?? $this->post('target', ''))));
        $unitId = isset($_GET['unit_id']) && ctype_digit((string)$_GET['unit_id']) ? (int)$_GET['unit_id'] : null;
        if ($unitId === null && isset($_POST['unit_id']) && ctype_digit((string)$_POST['unit_id'])) {
            $unitId = (int)$_POST['unit_id'];
        }
        $unitQuery = $unitId !== null ? '?unit_id=' . $unitId : '';

        // 1. Customer Interface
        if ($target === 'customer') {
            Session::forget('inventory_manager_mode');
            $this->enterCustomerInterface();
            return;
        }

        // Customer-only sessions (including username logins) cannot access administrative or employee interfaces
        if (Session::get('base_context') !== 'employee' || Session::get('auth_method') !== 'email') {
            $this->forbidden('Customer-only sessions cannot access administrative or employee workspaces.');
        }

        // Determine if account is Super Administrator from trusted base session or database
        $isSuper = (bool)Session::get('is_super_admin');
        if (!$isSuper) {
            $s = $this->db->prepare('SELECT is_super_admin FROM user_account WHERE user_account_id = ?');
            $s->execute([(int)$uid]);
            $isSuper = (bool)$s->fetchColumn();
        }

        // 2. Super Administrator Interface
        if ($target === 'super-admin' || $target === 'super_admin') {
            if (!$isSuper) {
                $this->forbidden('Super Administrator authorization is required.');
            }
            Session::set('active_context', 'employee');
            Session::forget('inventory_manager_mode');
            $this->redirect('/super-admin');
            return;
        }

        // 3. System Administrator Interface
        if ($target === 'sysadmin' || $target === 'system_admin') {
            if (!$isSuper) {
                if ($unitId !== null) {
                    $stmt = $this->db->prepare(
                        "SELECT 1 FROM account_role ar JOIN role r ON r.role_id = ar.role_id WHERE ar.user_account_id = ? AND ar.business_unit_id = ? AND r.role_name = 'SYSTEM_ADMINISTRATOR' LIMIT 1"
                    );
                    $stmt->execute([(int)$uid, $unitId]);
                } else {
                    $stmt = $this->db->prepare(
                        "SELECT 1 FROM account_role ar JOIN role r ON r.role_id = ar.role_id WHERE ar.user_account_id = ? AND r.role_name = 'SYSTEM_ADMINISTRATOR' LIMIT 1"
                    );
                    $stmt->execute([(int)$uid]);
                }
                if (!$stmt->fetchColumn()) {
                    $this->forbidden('System Administrator authorization is required.');
                }
            }
            Session::set('active_context', 'employee');
            Session::forget('inventory_manager_mode');
            $this->redirect('/sysadmin' . $unitQuery);
            return;
        }

        // 4. Business Manager Interface
        if ($target === 'manager' || $target === 'business_manager') {
            if (!$isSuper) {
                if ($unitId !== null) {
                    $stmt = $this->db->prepare(
                        "SELECT 1 FROM account_role ar JOIN role r ON r.role_id = ar.role_id WHERE ar.user_account_id = ? AND ar.business_unit_id = ? AND r.role_name IN ('BUSINESS_MANAGER', 'INVENTORY_MANAGER') LIMIT 1"
                    );
                    $stmt->execute([(int)$uid, $unitId]);
                } else {
                    $stmt = $this->db->prepare(
                        "SELECT 1 FROM account_role ar JOIN role r ON r.role_id = ar.role_id WHERE ar.user_account_id = ? AND r.role_name IN ('BUSINESS_MANAGER', 'INVENTORY_MANAGER') LIMIT 1"
                    );
                    $stmt->execute([(int)$uid]);
                }
                if (!$stmt->fetchColumn()) {
                    $this->forbidden('Business Manager authorization is required.');
                }
            }
            Session::set('active_context', 'employee');
            Session::forget('inventory_manager_mode');
            if (!$isSuper) {
                $chkRole = $this->db->prepare(
                    "SELECT ar.business_unit_id, r.role_name FROM account_role ar JOIN role r ON r.role_id = ar.role_id WHERE ar.user_account_id = ? AND r.role_name IN ('BUSINESS_MANAGER', 'INVENTORY_MANAGER')"
                );
                $chkRole->execute([(int)$uid]);
                $userRoleRows = $chkRole->fetchAll();
                $userRoles = array_column($userRoleRows, 'role_name');
                if (in_array('INVENTORY_MANAGER', $userRoles, true) && !in_array('BUSINESS_MANAGER', $userRoles, true)) {
                    Session::set('inventory_manager_mode', true);
                    $uId = (int)($unitId ?? $userRoleRows[0]['business_unit_id'] ?? 1);
                    if ($uId === 1) {
                        $this->redirect('/manager/orders' . $unitQuery);
                    } else {
                        $this->redirect('/manager/inventory' . $unitQuery);
                    }
                    return;
                }
            }
            $this->redirect('/manager' . $unitQuery);
            return;
        }

        // 4b. Inventory Manager Interface
        if ($target === 'inventory-manager' || $target === 'inventory_manager') {
            if (!$isSuper) {
                if ($unitId !== null) {
                    $stmt = $this->db->prepare(
                        "SELECT 1 FROM account_role ar JOIN role r ON r.role_id = ar.role_id WHERE ar.user_account_id = ? AND ar.business_unit_id = ? AND r.role_name IN ('INVENTORY_MANAGER', 'BUSINESS_MANAGER') LIMIT 1"
                    );
                    $stmt->execute([(int)$uid, $unitId]);
                } else {
                    $stmt = $this->db->prepare(
                        "SELECT 1 FROM account_role ar JOIN role r ON r.role_id = ar.role_id WHERE ar.user_account_id = ? AND r.role_name IN ('INVENTORY_MANAGER', 'BUSINESS_MANAGER') LIMIT 1"
                    );
                    $stmt->execute([(int)$uid]);
                }
                if (!$stmt->fetchColumn()) {
                    $this->forbidden('Inventory Manager authorization is required.');
                }
            }
            Session::set('active_context', 'employee');
            Session::set('inventory_manager_mode', true);
            if ($unitId === null && !$isSuper) {
                $uStmt = $this->db->prepare(
                    "SELECT ar.business_unit_id FROM account_role ar JOIN role r ON r.role_id = ar.role_id WHERE ar.user_account_id = ? AND r.role_name = 'INVENTORY_MANAGER' LIMIT 1"
                );
                $uStmt->execute([(int)$uid]);
                $foundU = $uStmt->fetchColumn();
                if ($foundU) {
                    $unitId = (int)$foundU;
                    $unitQuery = '?unit_id=' . $unitId;
                }
            }
            $targetUnit = $unitId ?? 1;
            if ($targetUnit === 1) {
                $this->redirect('/manager/orders' . $unitQuery);
            } else {
                $this->redirect('/manager/inventory' . $unitQuery);
            }
            return;
        }

        // 4c. Pet Store Receptionist Interface
        if ($target === 'petstore-receptionist' || $target === 'petstore_receptionist') {
            if (!$isSuper) {
                $stmt = $this->db->prepare(
                    "SELECT 1 FROM account_role ar
                     JOIN role r ON r.role_id = ar.role_id
                     WHERE ar.user_account_id = ? AND ar.business_unit_id = 1 AND r.role_name = 'RECEPTIONIST'
                     LIMIT 1"
                );
                $stmt->execute([(int)$uid]);
                if (!$stmt->fetchColumn()) {
                    $this->forbidden('Pet Store Receptionist authorization is required.');
                }
            }
            Session::set('active_context', 'employee');
            Session::forget('inventory_manager_mode');
            $this->redirect('/petstore/receptionist/sales');
            return;
        }

        // 4d. Grooming Services Receptionist Interface
        if ($target === 'grooming-receptionist' || $target === 'grooming_receptionist') {
            if (!$isSuper) {
                $stmt = $this->db->prepare(
                    "SELECT 1 FROM account_role ar
                     JOIN role r ON r.role_id = ar.role_id
                     WHERE ar.user_account_id = ? AND ar.business_unit_id = 3 AND r.role_name = 'RECEPTIONIST'
                     LIMIT 1"
                );
                $stmt->execute([(int)$uid]);
                if (!$stmt->fetchColumn()) {
                    $this->forbidden('Grooming Services Receptionist authorization is required.');
                }
            }
            Session::set('active_context', 'employee');
            Session::forget('inventory_manager_mode');
            $this->redirect('/grooming/receptionist/overview');
            return;
        }

        // 4e. Veterinary Clinic Receptionist Interface
        if ($target === 'vetclinic-receptionist' || $target === 'vetclinic_receptionist' || $target === 'vet-receptionist') {
            if (!$isSuper) {
                $stmt = $this->db->prepare(
                    "SELECT 1 FROM account_role ar
                     JOIN role r ON r.role_id = ar.role_id
                     WHERE ar.user_account_id = ? AND ar.business_unit_id = 2 AND r.role_name = 'RECEPTIONIST'
                     LIMIT 1"
                );
                $stmt->execute([(int)$uid]);
                if (!$stmt->fetchColumn()) {
                    $this->forbidden('Veterinary Clinic Receptionist authorization is required.');
                }
            }
            Session::set('active_context', 'employee');
            Session::forget('inventory_manager_mode');
            $this->redirect('/vetclinic/receptionist/overview');
            return;
        }

        // 4f. General Receptionist Switcher Target with unit_id
        if ($target === 'receptionist') {
            $uId = isset($_GET['unit_id']) ? (int)$_GET['unit_id'] : 1;
            if ($uId === 3) {
                $this->redirect('/switch-interface?target=grooming-receptionist');
            } elseif ($uId === 2) {
                $this->redirect('/switch-interface?target=vetclinic-receptionist');
            } else {
                $this->redirect('/switch-interface?target=petstore-receptionist');
            }
            return;
        }

        // 5. Employee Workspace
        if ($target === 'employee' || $target === 'workspace') {
            if (!$isSuper) {
                $stmt = $this->db->prepare(
                    "SELECT 1 FROM employee WHERE user_account_id = ? AND employee_status = 'active' LIMIT 1"
                );
                $stmt->execute([(int)$uid]);
                if (!$stmt->fetchColumn()) {
                    $this->forbidden('Employee workspace authorization is required.');
                }
            }
            Session::set('active_context', 'employee');
            Session::forget('inventory_manager_mode');
            $this->redirect('/staff/workspace' . $unitQuery);
            return;
        }

        // Fallback: return to default authorized workspace
        $this->returnToWorkspace();
    }

    public function logout(): void
    {
        CSRF::check();
        $uid = Session::get('user_id') ? (int)Session::get('user_id') : null;
        if ($uid) {
            Audit::log('auth.logout', 'User signed out and session invalidated.', null, 'user_account', $uid, $uid);
        }
        Session::destroy();
        Session::start();
        Session::flash('info', 'You have been signed out.');
        $this->redirect('/login');
    }

    // ---- Register (single-page progressive disclosure) -----------------

    public function registerEmailForm(): void
    {
        $existingToken = (string)Session::get('register_session_token', '');
        if ($existingToken !== '') {
            $cleaned = false;
            try {
                $pendingService = new CustomerRegistrationPendingService($this->db);
                $cleaned = $pendingService->deletePendingRegistration($existingToken);
            } catch (Throwable $e) {
                $cleaned = false;
            }

            if (!$cleaned) {
                http_response_code(500);
                Session::flash('error', 'Unable to reset your previous registration session cleanly. Please try again.');
                $this->render('auth/register', [
                    'title' => 'Create account',
                    'email' => (string)Session::get('register_email', ''),
                    'unlockedStage' => 1,
                    'errors' => [],
                    'old' => [],
                ]);
                return;
            }

            Session::forget('register_session_token');
        }

        Session::forget('register_email');
        $this->render('auth/register', [
            'title' => 'Create account',
            'email' => '',
            'unlockedStage' => 1,
            'errors' => [],
            'old' => [],
        ]);
    }

    public function registerSendOtp(): void
    {
        CSRF::check();
        $token = (string)Session::get('register_session_token', '');
        if ($token === '') {
            $token = CustomerRegistrationPendingService::generateSessionToken();
            Session::set('register_session_token', $token);
        }

        $email = strtolower($this->post('email'));
        $data = [
            'first_name' => $this->post('first_name'),
            'middle_name' => $this->post('middle_name'),
            'last_name' => $this->post('last_name'),
            'username' => $this->post('username'),
            'phone' => $this->post('phone'),
            'address_line1' => $this->post('address_line1'),
            'city' => $this->post('city'),
            'province' => $this->post('province'),
            'postal_code' => $this->post('postal_code'),
            'email' => $email,
        ];

        $service = new CustomerRegistrationPendingService($this->db);
        $result = $service->initiateRegistration($token, $data, (string)($_SERVER['REMOTE_ADDR'] ?? ''));

        if (($result['ok'] ?? false) === true && $email !== '') {
            Session::set('register_email', $email);
        }

        $this->jsonServiceResult($result);
    }

    public function registerResendOtp(): void
    {
        CSRF::check();
        $token = (string)Session::get('register_session_token', '');
        if ($token === '') {
            $this->jsonError('No active registration session. Please start registration again.', 400);
        }

        $service = new CustomerRegistrationPendingService($this->db);
        $result = $service->resendOtp($token, (string)($_SERVER['REMOTE_ADDR'] ?? ''));

        $this->jsonServiceResult($result);
    }

    public function registerVerifyOtp(): void
    {
        CSRF::check();
        $token = (string)Session::get('register_session_token', '');
        if ($token === '') {
            $this->jsonError('No active registration session. Please start registration again.', 400);
        }

        $otp = $this->post('otp') !== '' ? $this->post('otp') : $this->post('email_otp');
        $service = new CustomerRegistrationPendingService($this->db);
        $result = $service->verifyOtp($token, $otp, (string)($_SERVER['REMOTE_ADDR'] ?? ''));

        $this->jsonServiceResult($result);
    }

    public function registerEmail(): void
    {
        CSRF::check();
        $email = strtolower($this->post('email'));
        if (!$this->validEmail($email)) {
            if ($this->wantsJson()) {
                $this->jsonError('Enter a valid email address, for example name@example.com.', 422);
            }
            http_response_code(422);
            Session::forget('register_email');
            Session::flash('error', 'Enter a valid email address, for example name@example.com.');
            $this->render('auth/register', [
                'title' => 'Create account',
                'email' => $email,
                'unlockedStage' => 1,
                'errors' => ['email' => 'Enter a valid email address, for example name@example.com.'],
                'old' => [],
            ]);
            return;
        }
        Session::set('register_email', $email);
        if ($this->wantsJson()) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['ok' => true, 'email' => $email, 'csrf_token' => CSRF::token()]);
            exit;
        }
        $this->render('auth/register', [
            'title' => 'Create account',
            'email' => $email,
            'unlockedStage' => 2,
            'errors' => [],
            'old' => [],
        ]);
    }

    public function registerDetailsForm(): void
    {
        $email = (string)Session::get('register_email', '');
        if (!$this->validEmail($email)) {
            $this->redirect('/register');
        }
        $this->render('auth/register', [
            'title' => 'Create account',
            'email' => $email,
            'unlockedStage' => 4,
            'errors' => [],
            'old' => [],
        ]);
    }

    public function registerDetails(): void
    {
        CSRF::check();
        $postedEmail = strtolower($this->post('email'));
        $email = $postedEmail !== '' ? $postedEmail : (string)Session::get('register_email', '');

        // Always validate email syntax before proceeding to password/profile creation
        if (!$this->validEmail($email)) {
            http_response_code(422);
            Session::forget('register_email');
            Session::flash('error', 'Enter a valid email address, for example name@example.com.');
            $this->render('auth/register', [
                'title' => 'Create account',
                'email' => $email,
                'unlockedStage' => 1,
                'errors' => ['email' => 'Enter a valid email address, for example name@example.com.'],
                'old' => [],
            ]);
            return;
        }
        Session::set('register_email', $email);

        $old = [
            'username' => strtolower($this->post('username')),
            'first_name' => $this->post('first_name'),
            'middle_name' => $this->post('middle_name'),
            'last_name' => $this->post('last_name'),
            'phone' => $this->post('phone'),
            'address_line1' => $this->post('address_line1'),
            'city' => $this->post('city'),
            'province' => $this->post('province'),
            'postal_code' => $this->post('postal_code'),
        ];
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $confirm = is_string($_POST['password_confirm'] ?? null) ? $_POST['password_confirm'] : '';
        $errors = [];

        if ($old['username'] !== '' && !$this->validUsername($old['username'])) {
            $errors['username'] = 'Username must be 3–60 characters (letters, digits, underscores, dots).';
        }
        if ($old['first_name'] === '' || mb_strlen($old['first_name']) > 100) {
            $errors['first_name'] = 'Enter your first name (max 100 characters).';
        }
        if ($old['middle_name'] !== '' && mb_strlen($old['middle_name']) > 100) {
            $errors['middle_name'] = 'Maximum 100 characters.';
        }
        if ($old['last_name'] === '' || mb_strlen($old['last_name']) > 100) {
            $errors['last_name'] = 'Enter your last name (max 100 characters).';
        }
        if ($old['phone'] !== '' && !preg_match('/^[0-9+()\-\s]{6,30}$/', $old['phone'])) {
            $errors['phone'] = 'Enter a valid phone number.';
        }
        if (mb_strlen($old['address_line1']) > 255) {
            $errors['address_line1'] = 'Maximum 255 characters.';
        }
        if (mb_strlen($old['city']) > 100) {
            $errors['city'] = 'Maximum 100 characters.';
        }
        if (mb_strlen($old['province']) > 100) {
            $errors['province'] = 'Maximum 100 characters.';
        }
        if ($old['postal_code'] !== '' && !preg_match('/^[0-9]{4}$/', $old['postal_code'])) {
            $errors['postal_code'] = 'Postal code must be exactly 4 numeric digits.';
        }
        if (strlen($password) < 10) {
            $errors['password'] = 'Use at least 10 characters.';
        }
        if (strlen($password) > 200) {
            $errors['password'] = 'Password is too long.';
        }
        if (!hash_equals($password, $confirm)) {
            $errors['password_confirm'] = 'Passwords do not match.';
        }

        if ($errors) {
            http_response_code(422);
            $this->render('auth/register', [
                'title' => 'Create account',
                'email' => $email,
                'unlockedStage' => 4,
                'errors' => $errors,
                'old' => $old,
            ]);
            return;
        }

        $token = (string)Session::get('register_session_token', '');
        if ($token === '') {
            http_response_code(400);
            Session::flash('error', 'Please verify your email address before creating your account.');
            $this->render('auth/register', [
                'title' => 'Create account',
                'email' => $email,
                'unlockedStage' => 3,
                'errors' => [],
                'old' => $old,
            ]);
            return;
        }

        $service = new CustomerRegistrationPendingService($this->db);
        $result = $service->provisionVerifiedAccount($token, $password);

        if (($result['ok'] ?? false) !== true) {
            $status = (int)($result['status'] ?? 422);
            $conflictField = (string)($result['conflict_field'] ?? '');
            $stage = match (true) {
                $status === 403 => 3,
                $status === 409 && $conflictField === 'email' => 3,
                $status === 409 && $conflictField === 'username' => 4,
                $status === 422 => 4,
                $status === 404 || $status === 410 => 1,
                default => 3,
            };

            http_response_code($status);
            Session::flash('error', (string)($result['error'] ?? 'We could not create an account with those details. Please try again.'));
            $this->render('auth/register', [
                'title' => 'Create account',
                'email' => $email,
                'unlockedStage' => $stage,
                'errors' => is_array($result['errors'] ?? null) ? $result['errors'] : [],
                'old' => $old,
            ]);
            return;
        }

        Session::login([
            'user_id' => (int)$result['user_id'],
            'email' => (string)$result['email'],
            'username' => $result['username'] ?? null,
            'customer_id' => (int)$result['customer_id'],
            'employee_id' => null,
            'auth_method' => 'email',
            'base_context' => 'customer',
            'active_context' => 'customer',
            'is_super_admin' => false,
        ]);
        Session::forget('register_session_token');
        Session::forget('register_email');
        Session::flash('success', 'Account created. Welcome!');
        $this->redirect('/profile');
    }

    /**
     * Emit a JSON response preserving the service's HTTP status and fields plus a refreshed CSRF token.
     *
     * @param array<string, mixed> $result
     */
    private function jsonServiceResult(array $result): never
    {
        $status = (int)($result['status'] ?? 200);
        $payload = $result;
        $payload['csrf_token'] = CSRF::token();
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($payload);
        exit;
    }

    private function jsonError(string $msg, int $status): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'error' => $msg, 'csrf_token' => CSRF::token()]);
        exit;
    }
}
