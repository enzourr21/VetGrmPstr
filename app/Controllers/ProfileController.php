<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\CSRF;
use App\Core\Session;

final class ProfileController extends Controller
{
    public function show(): void
    {
        [$uid, $cid] = $this->requireCustomer();
        $this->render('profile', ['title' => 'Profile', 'c' => $this->load($uid, $cid), 'errors' => []]);
    }

    public function update(): void
    {
        [$uid, $cid] = $this->requireCustomer();
        CSRF::check();
        $f = [];
        foreach (['first_name', 'middle_name', 'last_name', 'phone', 'address_line1', 'address_line2', 'city', 'province', 'postal_code'] as $k) {
            $f[$k] = $this->post($k);
        }
        $errors = [];
        if ($f['first_name'] === '' || mb_strlen($f['first_name']) > 100) $errors['first_name'] = 'Enter your first name.';
        if ($f['middle_name'] !== '' && mb_strlen($f['middle_name']) > 100) $errors['middle_name'] = 'Maximum 100 characters.';
        if ($f['last_name'] === '' || mb_strlen($f['last_name']) > 100) $errors['last_name'] = 'Enter your last name.';
        if ($f['phone'] !== '' && !preg_match('/^[0-9+()\-\s]{6,30}$/', $f['phone'])) $errors['phone'] = 'Enter a valid phone number.';
        foreach (['address_line1' => 255, 'address_line2' => 255, 'city' => 100, 'province' => 100, 'postal_code' => 20] as $k => $max) {
            if (mb_strlen($f[$k]) > $max) $errors[$k] = "Maximum $max characters.";
        }
        if ($errors) {
            http_response_code(422);
            $c = $this->load($uid, $cid);
            $this->render('profile', ['title' => 'Profile', 'c' => array_merge($c, $f), 'errors' => $errors]);
            return;
        }
        $n = fn($v) => $v === '' ? null : $v;
        // customer_id comes from the session, never from the request
        $this->db->prepare('UPDATE customer SET first_name=?, middle_name=?, last_name=?, phone=?, address_line1=?, address_line2=?, city=?, province=?, postal_code=? WHERE customer_id=?')
            ->execute([$f['first_name'], $n($f['middle_name']), $f['last_name'], $n($f['phone']), $n($f['address_line1']), $n($f['address_line2']), $n($f['city']), $n($f['province']), $n($f['postal_code']), $cid]);
        Session::flash('success', 'Profile updated.');
        $this->redirect('/profile');
    }

    private function load(int $uid, int $cid): array
    {
        $s = $this->db->prepare('SELECT c.*, ua.email, ua.email_verified_at FROM customer c JOIN user_account ua ON ua.user_account_id=c.user_account_id WHERE c.customer_id=? AND ua.user_account_id=?');
        $s->execute([$cid, $uid]);
        return $s->fetch() ?: $this->notFound();
    }
}
