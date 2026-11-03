<?php
declare(strict_types=1);

namespace App\Core;

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
        session_regenerate_id(true);
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
                $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
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
        header('Location: ' . $url);
        exit;
    }

    protected function post(string $key, string $default = ''): string
    {
        $v = $_POST[$key] ?? $default;
        return is_string($v) ? trim($v) : $default;
    }

    protected function forbidden(): never
    {
        http_response_code(403);
        self::renderStatic('errors/error', ['code' => 403, 'message' => 'You do not have access to this page.']);
        exit;
    }

    protected function notFound(): never
    {
        http_response_code(404);
        self::renderStatic('errors/error', ['code' => 404, 'message' => 'We could not find that.']);
        exit;
    }

    /** Signed-in customer or redirect to login. Returns [user_id, customer_id]. */
    protected function requireCustomer(): array
    {
        $uid = Session::get('user_id');
        $cid = Session::get('customer_id');
        if (!$uid || !$cid) {
            Session::set('return_to', $_SERVER['REQUEST_URI'] ?? '/');
            $this->redirect('/login');
        }
        // re-check account status on every protected request
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

    /** Staff member holding $permission in $unitId, else redirect/403. */
    protected function requireStaff(string $permission, int $unitId): int
    {
        $uid = Session::get('user_id');
        $eid = Session::get('employee_id');
        if (!$uid || !$eid) {
            $this->redirect('/staff/login');
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

