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
                'errorMessage' => (string)$res['error'],
                'invitation' => null,
                'token' => '',
                'errors' => [],
            ]);
            return;
        }

        $this->render('auth/accept_invitation', [
            'title' => 'Set Up Employee Account',
            'invalid' => false,
            'errorMessage' => null,
            'invitation' => $res['invitation'],
            'token' => $rawToken,
            'errors' => [],
        ]);
    }

    public function accept(): void
    {
        $this->sendSensitiveNoCacheHeaders();
        CSRF::check();

        // Only token, password, and password_confirm are accepted; email, role, position, and unit are immutable.
        $rawToken = is_string($_POST['token'] ?? null) ? trim($_POST['token']) : '';
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $passwordConfirm = is_string($_POST['password_confirm'] ?? null) ? $_POST['password_confirm'] : '';

        $service = new EmployeeInvitationService($this->db);
        $res = $service->acceptInvitation($rawToken, $password, $passwordConfirm);

        if (!$res['ok']) {
            $status = (int)$res['status'];
            http_response_code($status);

            if ($status === 422) {
                $display = $service->validateTokenForAcceptance($rawToken);
                if ($display['ok']) {
                    $this->render('auth/accept_invitation', [
                        'title' => 'Set Up Employee Account',
                        'invalid' => false,
                        'errorMessage' => (string)($res['error'] ?? 'Please review the password requirements below.'),
                        'invitation' => $display['invitation'],
                        'token' => $rawToken,
                        'errors' => $res['errors'] ?? [],
                    ]);
                    return;
                }
            }

            $this->render('auth/accept_invitation', [
                'title' => 'Invitation Unavailable',
                'invalid' => true,
                'errorMessage' => (string)($res['error'] ?? 'This invitation link is invalid, expired, or has already been used.'),
                'invitation' => null,
                'token' => '',
                'errors' => [],
            ]);
            return;
        }

        if (Session::get('user_id')) {
            Session::destroy();
            Session::start();
        }
        Session::flash('success', 'Your employee account password has been set. Sign in with your email address and new password.');
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
