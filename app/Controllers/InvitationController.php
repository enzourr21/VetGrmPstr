<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\CSRF;
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

        // Only token, password, and password_confirm are accepted for new-account onboarding.
        $rawToken = is_string($_POST['token'] ?? null) ? trim($_POST['token']) : '';
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $passwordConfirm = is_string($_POST['password_confirm'] ?? null) ? $_POST['password_confirm'] : '';

        $service = new EmployeeInvitationService($this->db);
        $res = $service->acceptInvitation($rawToken, $password, $passwordConfirm);

        if (!$res['ok']) {
            $status = (int)$res['status'];
            http_response_code($status);

            $display = $service->validateTokenForAcceptance($rawToken);
            if ($display['ok']) {
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

    private function sendSensitiveNoCacheHeaders(): void
    {
        if (!headers_sent()) {
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('Pragma: no-cache');
            header('Referrer-Policy: no-referrer');
        }
    }
}
