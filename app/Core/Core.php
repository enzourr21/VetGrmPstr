<?php
declare(strict_types=1);

namespace App\Core;

use App\Models\Units;
use PDO;

final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            // config/database.php builds $pdo from .env (credentials never leave that file)
            require dirname(__DIR__, 2) . '/config/database.php';
            /** @var PDO $pdo */
            self::$pdo = $pdo;
        }
        return self::$pdo;
    }
}

final class Session
{
    public static function start(): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_name('VETGRPETSESS');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    public static function get(string $k, mixed $d = null): mixed { return $_SESSION[$k] ?? $d; }
    public static function set(string $k, mixed $v): void { $_SESSION[$k] = $v; }
    public static function forget(string $k): void { unset($_SESSION[$k]); }

    public static function login(array $data): void
    {
        $flashes = $_SESSION['_flash'] ?? [];
        $returnTo = $_SESSION['return_to'] ?? null;
        session_regenerate_id(true);
        $_SESSION = [];
        if ($flashes) {
            $_SESSION['_flash'] = $flashes;
        }
        if ($returnTo !== null) {
            $_SESSION['return_to'] = $returnTo;
        }
        foreach ($data as $k => $v) {
            $_SESSION[$k] = $v;
        }
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    public static function flash(string $type, string $msg): void { $_SESSION['_flash'][] = [$type, $msg]; }

    public static function pullFlash(): array
    {
        $f = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $f;
    }
}

final class CSRF
{
    public static function token(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8') . '">';
    }

    public static function check(): void
    {
        $sent = $_POST['csrf_token'] ?? '';
        if (!is_string($sent) || !hash_equals(self::token(), $sent)) {
            http_response_code(419);
            Controller::renderStatic('errors/error', ['code' => 419, 'message' => 'Your form expired. Go back, refresh the page and try again.']);
            exit;
        }
    }
}

final class Router
{
    private array $routes = [];

    public function get(string $p, string $c, string $m): void { $this->routes['GET'][] = [$p, $c, $m]; }
    public function post(string $p, string $c, string $m): void { $this->routes['POST'][] = [$p, $c, $m]; }

    public function dispatch(string $uri, string $method): void
    {
        $path = rtrim((string)parse_url($uri, PHP_URL_PATH), '/') ?: '/';
        foreach ($this->routes[$method] ?? [] as [$pattern, $class, $action]) {
            $re = '#^' . preg_replace('/\{(\w+)\}/', '(?P<$1>[0-9]+)', $pattern) . '$#';
            if (preg_match($re, $path, $m)) {
                $params = array_values(array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY));
                $cls = "App\\Controllers\\$class";
                (new $cls())->$action(...array_map('intval', $params));
                return;
            }
        }
        http_response_code(404);
        Controller::renderStatic('errors/error', ['code' => 404, 'message' => 'That page does not exist.']);
    }
}

abstract class Controller
{
    protected PDO $db;

    public function __construct()
    {
        $this->db = Database::pdo();
    }

    public static function renderStatic(string $view, array $data = []): void
    {
        extract($data, EXTR_SKIP);
        ob_start();
        require dirname(__DIR__) . "/Views/$view.php";
        $content = ob_get_clean();
        $flashes = Session::pullFlash();
        $title = $title ?? 'VETGRPET';
        require dirname(__DIR__) . '/Views/layout.php';
    }

    protected function render(string $view, array $data = []): void
    {
        self::renderStatic($view, $data);
    }

    protected function redirect(string $url): never
    {
        $code = http_response_code();
        $status = ($code && $code !== 200) ? $code : 302;
        header('Location: ' . $url, true, $status);
        exit;
    }

    protected function post(string $key, string $default = ''): string
    {
        $v = $_POST[$key] ?? $default;
        return is_string($v) ? trim($v) : $default;
    }

    protected function forbidden(string $message = 'You do not have access to this page.'): never
    {
        http_response_code(403);
        self::renderStatic('errors/error', ['code' => 403, 'message' => $message]);
        exit;
    }

    protected function notFound(): never
    {
        http_response_code(404);
        self::renderStatic('errors/error', ['code' => 404, 'message' => 'We could not find that.']);
        exit;
    }

    /**
     * Backend verification of Super Administrator status in an active email-authenticated employee session.
     * Never trusts client parameters or customer/username sessions.
     */
    protected function isSuperAdmin(): bool
    {
        $uid = Session::get('user_id');
        if (!$uid) {
            return false;
        }
        // Must be an email-authenticated employee/admin session, not a customer-only or username session
        $baseContext = Session::get('base_context', Session::get('employee_id') ? 'employee' : 'customer');
        $activeContext = Session::get('active_context', $baseContext);
        $authMethod = Session::get('auth_method', 'email');
        if ($baseContext !== 'employee' || $activeContext === 'customer' || $authMethod === 'username') {
            return false;
        }
        $s = $this->db->prepare(
            "SELECT 1 FROM user_account ua
             LEFT JOIN account_role ar ON ar.user_account_id = ua.user_account_id
             LEFT JOIN role r ON r.role_id = ar.role_id
             WHERE ua.user_account_id = ? AND ua.account_status = 'active'
               AND (ua.is_super_admin = 1 OR r.role_name = 'SUPER_ADMINISTRATOR')
             LIMIT 1"
        );
        $s->execute([(int)$uid]);
        return (bool)$s->fetchColumn();
    }

    /**
     * Require Super Administrator authority.
     */
    protected function requireSuperAdmin(): int
    {
        $uid = Session::get('user_id');
        if (!$uid) {
            Session::set('return_to', $_SERVER['REQUEST_URI'] ?? '/super-admin');
            $this->redirect('/login');
        }
        if (!$this->isSuperAdmin()) {
            $this->forbidden('Super Administrator authorization is required to access this area.');
        }
        return (int)$uid;
    }

    /**
     * Require System Administrator authority for a specific business unit (or Super Administrator).
     * Enforces strict business-unit isolation on the backend.
     *
     * @return array{0:int, 1:int, 2:int, 3:string, 4:bool} [user_id, employee_id, business_unit_id, business_unit_name, is_super_admin]
     */
    protected function requireSystemAdmin(?int $requestedUnitId = null): array
    {
        $uid = Session::get('user_id');
        if (!$uid) {
            Session::set('return_to', $_SERVER['REQUEST_URI'] ?? '/sysadmin');
            $this->redirect('/login');
        }

        $baseContext = Session::get('base_context', Session::get('employee_id') ? 'employee' : 'customer');
        $activeContext = Session::get('active_context', $baseContext);
        $authMethod = Session::get('auth_method', 'email');
        if ($baseContext !== 'employee' || $activeContext === 'customer' || $authMethod === 'username') {
            $this->forbidden('An email-authenticated System Administrator session is required.');
        }

        // Check if query or POST explicitly targets a business unit
        $queryUnitId = isset($_GET['unit_id']) && ctype_digit((string)$_GET['unit_id']) ? (int)$_GET['unit_id'] : null;
        $postUnitId = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['business_unit_id']) && ctype_digit((string)$_POST['business_unit_id'])
            ? (int)$_POST['business_unit_id']
            : null;
        $targetUnitId = $requestedUnitId ?? $postUnitId ?? $queryUnitId;

        if ($this->isSuperAdmin()) {
            if ($targetUnitId !== null) {
                $u = Units::find($targetUnitId) ?: $this->notFound();
            } else {
                $u = Units::find(Units::petStoreId()) ?: Units::all()[0];
            }
            $eid = (int)Session::get('employee_id', 0);
            return [(int)$uid, $eid, (int)$u['business_unit_id'], (string)$u['business_unit_name'], true];
        }

        $s = $this->db->prepare(
            "SELECT e.employee_id, bu.business_unit_id, bu.business_unit_name
             FROM user_account ua
             JOIN employee e ON e.user_account_id = ua.user_account_id AND e.employee_status = 'active'
             JOIN account_role ar ON ar.user_account_id = ua.user_account_id
             JOIN role r ON r.role_id = ar.role_id AND r.role_name = 'SYSTEM_ADMINISTRATOR'
             JOIN business_unit bu ON bu.business_unit_id = ar.business_unit_id AND bu.business_unit_status = 'active'
             WHERE ua.user_account_id = ? AND ua.account_status = 'active'
             ORDER BY bu.business_unit_id"
        );
        $s->execute([(int)$uid]);
        $authorizedUnits = $s->fetchAll();

        if (!$authorizedUnits) {
            $this->forbidden('System Administrator privileges are required.');
        }

        $byUnitId = [];
        foreach ($authorizedUnits as $au) {
            $byUnitId[(int)$au['business_unit_id']] = $au;
        }

        // Strict Business-Unit Isolation: deny if requested/posted/queried unit is outside assigned unit(s)
        foreach ([$requestedUnitId, $queryUnitId, $postUnitId] as $checkUnit) {
            if ($checkUnit !== null && !isset($byUnitId[$checkUnit])) {
                $this->forbidden('Access denied: you cannot access or manage another business unit.');
            }
        }

        $chosen = $targetUnitId !== null ? $byUnitId[$targetUnitId] : $authorizedUnits[0];
        return [
            (int)$uid,
            (int)$chosen['employee_id'],
            (int)$chosen['business_unit_id'],
            (string)$chosen['business_unit_name'],
            false,
        ];
    }

    /**
     * Require Business Manager authority for a business unit (or Super Administrator).
     *
     * @return array{0:int, 1:int, 2:int, 3:string, 4:bool} [user_id, employee_id, business_unit_id, business_unit_name, is_super_admin]
     */
    protected function requireBusinessManager(?int $requestedUnitId = null): array
    {
        $uid = Session::get('user_id');
        if (!$uid) {
            Session::set('return_to', $_SERVER['REQUEST_URI'] ?? '/manager');
            $this->redirect('/login');
        }

        $baseContext = Session::get('base_context', Session::get('employee_id') ? 'employee' : 'customer');
        $activeContext = Session::get('active_context', $baseContext);
        $authMethod = Session::get('auth_method', 'email');
        if ($baseContext !== 'employee' || $activeContext === 'customer' || $authMethod === 'username') {
            $this->forbidden('An email-authenticated Business Manager session is required.');
        }

        $queryUnitId = isset($_GET['unit_id']) && ctype_digit((string)$_GET['unit_id']) ? (int)$_GET['unit_id'] : null;
        $targetUnitId = $requestedUnitId ?? $queryUnitId;

        if ($this->isSuperAdmin()) {
            $u = $targetUnitId !== null ? (Units::find($targetUnitId) ?: $this->notFound()) : (Units::find(Units::petStoreId()) ?: Units::all()[0]);
            return [(int)$uid, (int)Session::get('employee_id', 0), (int)$u['business_unit_id'], (string)$u['business_unit_name'], true];
        }

        $s = $this->db->prepare(
            "SELECT e.employee_id, bu.business_unit_id, bu.business_unit_name
             FROM user_account ua
             JOIN employee e ON e.user_account_id = ua.user_account_id AND e.employee_status = 'active'
             JOIN account_role ar ON ar.user_account_id = ua.user_account_id
             JOIN role r ON r.role_id = ar.role_id AND r.role_name = 'BUSINESS_MANAGER'
             JOIN business_unit bu ON bu.business_unit_id = ar.business_unit_id AND bu.business_unit_status = 'active'
             WHERE ua.user_account_id = ? AND ua.account_status = 'active'
             ORDER BY bu.business_unit_id"
        );
        $s->execute([(int)$uid]);
        $authorizedUnits = $s->fetchAll();

        if (!$authorizedUnits) {
            $this->forbidden('Business Manager privileges are required.');
        }

        $byUnitId = [];
        foreach ($authorizedUnits as $au) {
            $byUnitId[(int)$au['business_unit_id']] = $au;
        }
        if ($targetUnitId !== null && !isset($byUnitId[$targetUnitId])) {
            $this->forbidden('Access denied: you cannot access another business unit.');
        }

        $chosen = $targetUnitId !== null ? $byUnitId[$targetUnitId] : $authorizedUnits[0];
        return [(int)$uid, (int)$chosen['employee_id'], (int)$chosen['business_unit_id'], (string)$chosen['business_unit_name'], false];
    }

    /**
     * Signed-in customer or redirect to login. Returns [user_id, customer_id].
     * Also allows authenticated employees / Super Admin to use the Customer interface
     * under customer-specific authorization rules without exposing employee privileges.
     */
    protected function requireCustomer(): array
    {
        $uid = Session::get('user_id');
        $cid = Session::get('customer_id');
        if (!$uid) {
            Session::set('return_to', $_SERVER['REQUEST_URI'] ?? '/');
            $this->redirect('/login');
        }

        if (!$cid) {
            // Look up existing customer profile or auto-provision one for an authenticated employee/admin
            $find = $this->db->prepare('SELECT customer_id FROM customer WHERE user_account_id = ? AND customer_status = "active"');
            $find->execute([(int)$uid]);
            $cid = (int)$find->fetchColumn();
            if (!$cid) {
                $empStmt = $this->db->prepare(
                    'SELECT e.first_name, e.last_name, e.phone
                     FROM employee e
                     JOIN user_account ua ON ua.user_account_id = e.user_account_id
                     WHERE ua.user_account_id = ? AND ua.account_status = "active"'
                );
                $empStmt->execute([(int)$uid]);
                $emp = $empStmt->fetch();
                if ($emp) {
                    $this->db->prepare(
                        'INSERT IGNORE INTO customer (user_account_id, first_name, last_name, phone, customer_status)
                         VALUES (?, ?, ?, ?, "active")'
                    )->execute([(int)$uid, $emp['first_name'], $emp['last_name'], $emp['phone']]);
                    $find->execute([(int)$uid]);
                    $cid = (int)$find->fetchColumn();
                }
            }
            if ($cid) {
                Session::set('customer_id', $cid);
            } else {
                Session::set('return_to', $_SERVER['REQUEST_URI'] ?? '/');
                $this->redirect('/login');
            }
        }

        // Re-check account status on every protected request
        $s = $this->db->prepare('SELECT 1 FROM user_account ua JOIN customer c ON c.user_account_id=ua.user_account_id
            WHERE ua.user_account_id=? AND c.customer_id=? AND ua.account_status="active" AND c.customer_status="active"');
        $s->execute([$uid, $cid]);
        if (!$s->fetchColumn()) {
            Session::destroy();
            Session::start();
            $this->redirect('/login');
        }
        return [(int)$uid, (int)$cid];
    }

    /**
     * Staff member holding $permission in $unitId (or Super Administrator), else redirect/403.
     */
    protected function requireStaff(string $permission, int $unitId): int
    {
        $uid = Session::get('user_id');
        $eid = Session::get('employee_id');
        if (!$uid || !$eid) {
            $this->redirect('/staff/login');
        }

        $baseContext = Session::get('base_context', 'employee');
        $activeContext = Session::get('active_context', $baseContext);
        $authMethod = Session::get('auth_method', 'email');
        if ($baseContext !== 'employee' || $activeContext === 'customer' || $authMethod === 'username') {
            $this->forbidden('Customer interface context cannot access staff endpoints.');
        }

        // Super Administrator has unrestricted access without requiring additional roles
        if ($this->isSuperAdmin()) {
            return (int)$eid;
        }

        $s = $this->db->prepare('SELECT 1 FROM user_account ua
            JOIN employee e ON e.user_account_id=ua.user_account_id AND e.employee_status="active"
            JOIN account_role ar ON ar.user_account_id=ua.user_account_id AND ar.business_unit_id=?
            JOIN role_permission rp ON rp.role_id=ar.role_id
            JOIN permission p ON p.permission_id=rp.permission_id AND p.permission_code=?
            WHERE ua.user_account_id=? AND ua.account_status="active" AND e.employee_id=? LIMIT 1');
        $s->execute([$unitId, $permission, $uid, $eid]);
        if (!$s->fetchColumn()) {
            $this->forbidden();
        }
        return (int)$eid;
    }
}
