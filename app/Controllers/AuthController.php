<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\CSRF;
use App\Core\Session;
use PDOException;

final class AuthController extends Controller
{
    private const MAX_FAILS = 5;
    private const LOCK_SECONDS = 60;

    private function validEmail(string $email): bool
    {
        return strlen($email) <= 255 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    private function wantsJson(): bool
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $xhr = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
        return str_contains($accept, 'application/json') || strtolower($xhr) === 'xmlhttprequest';
    }

    private function safeReturn(): string
    {
        $to = (string)Session::get('return_to', '/');
        Session::forget('return_to');
        return (str_starts_with($to, '/') && !str_starts_with($to, '//') && !str_contains($to, "\n")) ? $to : '/';
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
        $email = strtolower($this->post('email'));
        if (!$this->validEmail($email)) {
            if ($this->wantsJson()) {
                $this->jsonError('Enter a valid email address, for example name@example.com.', 422);
            }
            http_response_code(422);
            Session::forget('pending_email');
            Session::flash('error', 'Enter a valid email address, for example name@example.com.');
            $this->render('auth/login', [
                'title' => 'Sign in',
                'email' => $email,
                'emailUnlocked' => false,
                'errors' => ['email' => 'Enter a valid email address, for example name@example.com.'],
            ]);
            return;
        }
        Session::set('pending_email', $email);
        if ($this->wantsJson()) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['ok' => true, 'email' => $email, 'csrf_token' => CSRF::token()]);
            exit;
        }
        $this->render('auth/login', [
            'title' => 'Sign in',
            'email' => $email,
            'emailUnlocked' => true,
            'errors' => [],
        ]);
    }

    public function loginPasswordForm(): void
    {
        $email = (string)Session::get('pending_email', '');
        if (!$this->validEmail($email)) {
            $this->redirect('/login');
        }
        $this->render('auth/login', [
            'title' => 'Sign in',
            'email' => $email,
            'emailUnlocked' => true,
            'errors' => [],
        ]);
    }

    public function loginPassword(): void
    {
        CSRF::check();
        $postedEmail = strtolower($this->post('email'));
        $email = $postedEmail !== '' ? $postedEmail : (string)Session::get('pending_email', '');

        // Server-side guard: always validate email syntax before proceeding to password verification
        if (!$this->validEmail($email)) {
            http_response_code(422);
            Session::forget('pending_email');
            Session::flash('error', 'Enter a valid email address, for example name@example.com.');
            $this->render('auth/login', [
                'title' => 'Sign in',
                'email' => $email,
                'emailUnlocked' => false,
                'errors' => ['email' => 'Enter a valid email address, for example name@example.com.'],
            ]);
            return;
        }
        Session::set('pending_email', $email);

        // If submitted without password field (e.g. non-JS progressive step 1), unlock password step on same page
        if (!array_key_exists('password', $_POST)) {
            $this->render('auth/login', [
                'title' => 'Sign in',
                'email' => $email,
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
                'email' => $email,
                'emailUnlocked' => true,
                'errors' => [],
            ]);
            return;
        }

        $password = $_POST['password'] ?? '';
        $password = is_string($password) ? $password : '';

        $s = $this->db->prepare('SELECT ua.user_account_id, ua.password_hash, ua.account_status,
                (SELECT customer_id FROM customer c WHERE c.user_account_id = ua.user_account_id AND c.customer_status="active") AS customer_id,
                (SELECT employee_id FROM employee e WHERE e.user_account_id = ua.user_account_id AND e.employee_status="active") AS employee_id
            FROM user_account ua WHERE ua.email = ?');
        $s->execute([$email]);
        $acct = $s->fetch();

        // Always run one password_verify so response time does not reveal whether the email exists.
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
                'email' => $email,
                'emailUnlocked' => true,
                'errors' => [],
            ]);
            return;
        }

        if (password_needs_rehash($acct['password_hash'], PASSWORD_DEFAULT)) {
            $this->db->prepare('UPDATE user_account SET password_hash=? WHERE user_account_id=?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $acct['user_account_id']]);
        }
        Session::login([
            'user_id' => (int)$acct['user_account_id'],
            'email' => $email,
            'customer_id' => $acct['customer_id'] !== null ? (int)$acct['customer_id'] : null,
            'employee_id' => $acct['employee_id'] !== null ? (int)$acct['employee_id'] : null,
            'fails' => 0,
        ]);
        Session::forget('pending_email');
        $this->redirect($this->safeReturn());
    }

    public function logout(): void
    {
        CSRF::check();
        Session::destroy();
        Session::start();
        Session::flash('info', 'You have been signed out.');
        $this->redirect('/login');
    }

    // ---- Register (single-page progressive disclosure) -----------------

    public function registerEmailForm(): void
    {
        Session::forget('register_email');
        $this->render('auth/register', [
            'title' => 'Create account',
            'email' => '',
            'unlockedStage' => 1,
            'errors' => [],
            'old' => [],
        ]);
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
        if (mb_strlen($old['postal_code']) > 20) {
            $errors['postal_code'] = 'Maximum 20 characters.';
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

        $n = fn(string $v): ?string => $v === '' ? null : $v;

        try {
            $this->db->beginTransaction();
            $this->db->prepare('INSERT INTO user_account (email, password_hash) VALUES (?, ?)')
                ->execute([$email, password_hash($password, PASSWORD_DEFAULT)]);
            $uid = (int)$this->db->lastInsertId();
            $this->db->prepare('INSERT INTO customer (user_account_id, first_name, middle_name, last_name, phone, address_line1, city, province, postal_code) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([
                    $uid,
                    $old['first_name'],
                    $n($old['middle_name']),
                    $old['last_name'],
                    $n($old['phone']),
                    $n($old['address_line1']),
                    $n($old['city']),
                    $n($old['province']),
                    $n($old['postal_code']),
                ]);
            $cid = (int)$this->db->lastInsertId();
            $this->db->commit();
        } catch (PDOException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            http_response_code(422);
            Session::flash('error', 'We could not create an account with those details. If you already registered, sign in instead.');
            $this->render('auth/register', [
                'title' => 'Create account',
                'email' => $email,
                'unlockedStage' => 4,
                'errors' => [],
                'old' => $old,
            ]);
            return;
        }

        Session::login(['user_id' => $uid, 'email' => $email, 'customer_id' => $cid, 'employee_id' => null]);
        Session::forget('register_email');
        Session::flash('success', 'Account created. Welcome!');
        $this->redirect('/profile');
    }

    private function jsonError(string $msg, int $status): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'error' => $msg, 'csrf_token' => CSRF::token()]);
        exit;
    }
}
