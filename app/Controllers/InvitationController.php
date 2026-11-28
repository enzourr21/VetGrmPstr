<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\CSRF;
use App\Core\Session;
use App\Services\EmployeeInvitationService;

final class InvitationController extends Controller
{
    public function showAcceptForm(): void
    {
        $this->sendSensitiveNoCacheHeaders();

        $rawToken = is_string($_GET['token'] ?? null) ? trim($_GET['token']) : '';
        $service = new EmployeeInvitationService($this->db);
        $res = $service->validateTokenForAcceptance($rawToken);

        if (!$res['ok']) {
            http_response_code((int)$res['status']);
            $this->render('auth/accept_invitation', [
                'title' => 'Invitation Unavailable',
                'invalid' => true,
                'mode' => 'invalid',
                'errorMessage' => (string)$res['error'],
                'invitation' => null,
                'token' => '',
                'errors' => [],
                'signedInEmail' => null,
                'isolateSessionChrome' => true,
            ]);
            return;
        }

        $mode = (string)$res['mode'];
        $sessionUid = Session::get('user_id') ? (int)Session::get('user_id') : null;
        $signedInEmail = is_string(Session::get('email')) ? (string)Session::get('email') : null;

        if ($mode === 'existing_customer') {
            $targetUid = (int)($res['existing_user_account_id'] ?? 0);
            if ($sessionUid === null) {
                Session::set('return_to', '/invitations/accept?token=' . $rawToken);
                $this->render('auth/accept_invitation', [
                    'title' => 'Sign In to Accept Invitation',
                    'invalid' => false,
                    'mode' => 'existing_customer_signin_required',
                    'errorMessage' => null,
                    'invitation' => $res['invitation'],
                    'token' => $rawToken,
                    'errors' => [],
                    'signedInEmail' => null,
                ]);
                return;
            }

            if ($sessionUid !== $targetUid) {
                http_response_code(403);
                $this->render('auth/accept_invitation', [
                    'title' => 'Wrong Account Signed In',
                    'invalid' => false,
                    'mode' => 'existing_customer_wrong_account',
                    'errorMessage' => 'You are currently signed in to a different PawCariño account.',
                    'invitation' => $res['invitation'],
                    'token' => $rawToken,
                    'errors' => [],
                    'signedInEmail' => $signedInEmail,
                ]);
                return;
            }

            $this->render('auth/accept_invitation', [
                'title' => 'Confirm Employee Invitation',
                'invalid' => false,
                'mode' => 'existing_customer_confirm',
                'errorMessage' => null,
                'invitation' => $res['invitation'],
                'token' => $rawToken,
                'errors' => [],
                'signedInEmail' => $signedInEmail,
            ]);
            return;
        }

        $this->render('auth/accept_invitation', [
            'title' => 'Set Up Employee Account',
            'invalid' => false,
            'mode' => 'new_account',
            'errorMessage' => null,
            'invitation' => $res['invitation'],
            'token' => $rawToken,
            'errors' => [],
            'signedInEmail' => null,
            'isolateSessionChrome' => true,
        ]);
    }

    public function accept(): void
    {
        $this->sendSensitiveNoCacheHeaders();
        CSRF::check(true);

        // Only token, password/password_confirm (new account), or confirm_accept (existing customer) are accepted.
        $rawToken = is_string($_POST['token'] ?? null) ? trim($_POST['token']) : '';
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $passwordConfirm = is_string($_POST['password_confirm'] ?? null) ? $_POST['password_confirm'] : '';
        $confirmAccept = (($_POST['confirm_accept'] ?? '') === '1');
        $sessionUid = Session::get('user_id') ? (int)Session::get('user_id') : null;
        $signedInEmail = is_string(Session::get('email')) ? (string)Session::get('email') : null;

        $service = new EmployeeInvitationService($this->db);
        $res = $service->acceptInvitation($rawToken, $password, $passwordConfirm, $sessionUid, $confirmAccept);

        if (!$res['ok']) {
            $status = (int)$res['status'];
            http_response_code($status);

            $display = $service->validateTokenForAcceptance($rawToken);
            if ($display['ok']) {
                if (!empty($res['requires_auth']) || ($display['mode'] === 'existing_customer' && $sessionUid === null)) {
                    Session::set('return_to', '/invitations/accept?token=' . $rawToken);
                    $this->render('auth/accept_invitation', [
                        'title' => 'Sign In to Accept Invitation',
                        'invalid' => false,
                        'mode' => 'existing_customer_signin_required',
                        'errorMessage' => (string)($res['error'] ?? 'Please sign in to your existing account to continue.'),
                        'invitation' => $display['invitation'],
                        'token' => $rawToken,
                        'errors' => [],
                        'signedInEmail' => null,
                    ]);
                    return;
                }

                if (!empty($res['wrong_account'])) {
                    $this->render('auth/accept_invitation', [
                        'title' => 'Wrong Account Signed In',
                        'invalid' => false,
                        'mode' => 'existing_customer_wrong_account',
                        'errorMessage' => (string)($res['error'] ?? 'Sign in with the invited email address to accept this invitation.'),
                        'invitation' => $display['invitation'],
                        'token' => $rawToken,
                        'errors' => [],
                        'signedInEmail' => $signedInEmail,
                    ]);
                    return;
                }

                if ($display['mode'] === 'existing_customer') {
                    $this->render('auth/accept_invitation', [
                        'title' => 'Confirm Employee Invitation',
                        'invalid' => false,
                        'mode' => 'existing_customer_confirm',
                        'errorMessage' => (string)($res['error'] ?? 'Confirm acceptance to continue.'),
                        'invitation' => $display['invitation'],
                        'token' => $rawToken,
                        'errors' => $res['errors'] ?? [],
                        'signedInEmail' => $signedInEmail,
                    ]);
                    return;
                }

                $this->render('auth/accept_invitation', [
                    'title' => 'Set Up Employee Account',
                    'invalid' => false,
                    'mode' => 'new_account',
                    'errorMessage' => (string)($res['error'] ?? 'Please review the password requirements below.'),
                    'invitation' => $display['invitation'],
                    'token' => $rawToken,
                    'errors' => $res['errors'] ?? [],
                    'signedInEmail' => null,
                    'isolateSessionChrome' => true,
                ]);
                return;
            }

            $this->render('auth/accept_invitation', [
                'title' => 'Invitation Unavailable',
                'invalid' => true,
                'mode' => 'invalid',
                'errorMessage' => (string)($res['error'] ?? 'This invitation link is invalid, expired, or has already been used.'),
                'invitation' => null,
                'token' => '',
                'errors' => [],
                'signedInEmail' => null,
                'isolateSessionChrome' => true,
            ]);
            return;
        }

        if (($res['mode'] ?? '') === 'existing_customer') {
            $uid = (int)$res['user_account_id'];
            $authMethod = (string)Session::get('auth_method', 'email');
            if ($authMethod === 'email') {
                $uaStmt = $this->db->prepare('SELECT email, username FROM user_account WHERE user_account_id = ? LIMIT 1');
                $uaStmt->execute([$uid]);
                $uaRow = $uaStmt->fetch() ?: ['email' => '', 'username' => null];
                Session::login([
                    'user_id' => $uid,
                    'email' => (string)$uaRow['email'],
                    'username' => $uaRow['username'] ?? null,
                    'customer_id' => (int)$res['customer_id'],
                    'employee_id' => (int)$res['employee_id'],
                    'auth_method' => 'email',
                    'base_context' => 'employee',
                    'active_context' => 'employee',
                    'is_super_admin' => false,
                    'fails' => 0,
                ]);
                Session::forget('inventory_manager_mode');
                Session::forget('active_workspace_interface');
                Session::flash('success', 'Employee invitation accepted. Your existing customer account and history have been preserved.');
                $landing = AuthController::resolveEmployeeLandingFor($this->db, $uid, false);
                $this->redirect($landing);
            }

            Session::flash('success', 'Employee invitation accepted! Sign in with your email address whenever you want to access your employee workspace.');
            $this->redirect('/profile');
        }

        // New-employee acceptance is strictly independent of any unrelated browser session:
        // do not destroy, replace, or write flashes into an existing authenticated session.
        http_response_code(200);
        $this->render('auth/accept_invitation', [
            'title' => 'Employee Account Activated',
            'invalid' => false,
            'mode' => 'new_account_accepted',
            'errorMessage' => null,
            'invitation' => [
                'full_name' => (string)($res['full_name'] ?? ''),
                'email' => (string)($res['email'] ?? ''),
                'business_unit_name' => (string)($res['business_unit_name'] ?? ''),
                'position' => (string)($res['position'] ?? ''),
                'roles' => (string)($res['roles'] ?? ''),
            ],
            'token' => '',
            'errors' => [],
            'signedInEmail' => null,
            'isolateSessionChrome' => true,
        ]);
    }

    /**
     * Sign out the current session while preserving the invitation return URL so the recipient
     * can sign in with the invited existing customer account.
     */
    public function switchAccount(): void
    {
        $this->sendSensitiveNoCacheHeaders();
        CSRF::check();

        $rawToken = is_string($_POST['token'] ?? null) ? trim($_POST['token']) : '';
        Session::destroy();
        Session::start();

        if (EmployeeInvitationService::isValidRawTokenFormat($rawToken)) {
            Session::set('return_to', '/invitations/accept?token=' . $rawToken);
        }
        Session::flash('info', 'Signed out. Sign in with the invited email address to continue.');
        $this->redirect('/login');
    }

    private function sendSensitiveNoCacheHeaders(): void
    {
        if (!headers_sent()) {
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('Pragma: no-cache');
            header('Referrer-Policy: no-referrer');
        }
    }
}
