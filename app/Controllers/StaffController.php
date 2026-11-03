<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\CSRF;
use App\Core\Session;
use App\Models\Units;

final class StaffController extends Controller
{
    /** Provisional receptionist transitions (BUSINESS_RULES 8.1: final transitions are still UNRESOLVED). */
    public const TRANSITIONS = [
        'Pending' => ['Awaiting Customer Response', 'Confirmed', 'Rejected'],
        'Awaiting Customer Response' => ['Confirmed', 'Rejected', 'Cancelled'],
        'Confirmed' => ['Cancelled', 'No-Show'],
    ];

    public function loginForm(): void
    {
        $this->render('staff/login', ['title' => 'Staff sign in']);
    }

    public function login(): void
    {
        CSRF::check();
        $email = strtolower($this->post('email'));
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $s = $this->db->prepare('SELECT ua.user_account_id, ua.password_hash, ua.account_status,
                (SELECT customer_id FROM customer c WHERE c.user_account_id=ua.user_account_id AND c.customer_status="active") AS customer_id,
                (SELECT employee_id FROM employee e WHERE e.user_account_id=ua.user_account_id AND e.employee_status="active") AS employee_id
            FROM user_account ua WHERE ua.email=?');
        $s->execute([$email]);
        $a = $s->fetch();
        static $dummy = null;
        $dummy ??= password_hash('not-a-real-password', PASSWORD_DEFAULT);
        $ok = password_verify($password, $a['password_hash'] ?? $dummy);
        if (!$a || !$ok || $a['account_status'] !== 'active' || $a['employee_id'] === null) {
            http_response_code(401);
            Session::flash('error', 'Incorrect email or password, or this account has no staff access.');
            $this->render('staff/login', ['title' => 'Staff sign in']);
            return;
        }
        Session::login([
            'user_id' => (int)$a['user_account_id'], 'email' => $email,
            'customer_id' => $a['customer_id'] !== null ? (int)$a['customer_id'] : null,
            'employee_id' => (int)$a['employee_id'],
        ]);
        $this->redirect('/staff/appointments');
    }

    public function appointments(): void
    {
        $unit = Units::groomingId();
        $this->requireStaff('appointment.view', $unit);
        $filter = $this->get('status');
        $sql = 'SELECT a.appointment_id, a.preferred_date, a.preferred_time, a.created_at, st.status_name,
                    CONCAT(c.first_name, " ", c.last_name) AS customer_name,
                    (SELECT GROUP_CONCAT(DISTINCT p.pet_name SEPARATOR ", ") FROM appointment_pet ap JOIN pet p ON p.pet_id=ap.pet_id WHERE ap.appointment_id=a.appointment_id) AS pets
                FROM appointment a
                JOIN appointment_status st ON st.appointment_status_id=a.appointment_status_id
                JOIN customer c ON c.customer_id=a.customer_id
                WHERE a.business_unit_id=?';
        $args = [$unit];
        if ($filter !== '') {
            $sql .= ' AND st.status_name=?';
            $args[] = $filter;
        }
        $sql .= ' ORDER BY (st.status_name="Pending") DESC, a.created_at DESC';
        $q = $this->db->prepare($sql);
        $q->execute($args);
        $statuses = $this->db->query('SELECT status_name FROM appointment_status ORDER BY sort_order')->fetchAll(\PDO::FETCH_COLUMN);
        $this->render('staff/appointments', ['title' => 'Grooming appointment requests', 'rows' => $q->fetchAll(), 'statuses' => $statuses, 'filter' => $filter]);
    }

    public function appointment(int $id): void
    {
        $unit = Units::groomingId();
        $this->requireStaff('appointment.view', $unit);
        $s = $this->db->prepare('SELECT a.*, st.status_name, c.first_name, c.last_name, c.phone, ua.email
            FROM appointment a JOIN appointment_status st ON st.appointment_status_id=a.appointment_status_id
            JOIN customer c ON c.customer_id=a.customer_id JOIN user_account ua ON ua.user_account_id=c.user_account_id
            WHERE a.appointment_id=? AND a.business_unit_id=?');
        $s->execute([$id, $unit]);
        $a = $s->fetch() ?: $this->notFound();
        $pets = $this->db->prepare('SELECT p.pet_name, p.species, p.breed, p.sex, p.weight_kg, p.notes FROM appointment_pet ap JOIN pet p ON p.pet_id=ap.pet_id WHERE ap.appointment_id=?');
        $pets->execute([$id]);
        $this->render('staff/appointment', [
            'title' => 'Request #' . $id, 'a' => $a, 'pets' => $pets->fetchAll(),
            'lines' => AppointmentController::lines($this->db, $id),
            'next' => self::TRANSITIONS[$a['status_name']] ?? [],
        ]);
    }

    public function setStatus(int $id): void
    {
        $unit = Units::groomingId();
        $this->requireStaff('appointment.process', $unit);
        CSRF::check();
        $target = $this->post('status');
        $s = $this->db->prepare('SELECT a.appointment_status_id, st.status_name FROM appointment a JOIN appointment_status st ON st.appointment_status_id=a.appointment_status_id WHERE a.appointment_id=? AND a.business_unit_id=?');
        $s->execute([$id, $unit]);
        $cur = $s->fetch() ?: $this->notFound();
        if (!in_array($target, self::TRANSITIONS[$cur['status_name']] ?? [], true)) {
            Session::flash('error', "Cannot change a request from {$cur['status_name']} to that status.");
            $this->redirect("/staff/appointments/$id");
        }
        $t = $this->db->prepare('SELECT appointment_status_id FROM appointment_status WHERE status_name=?');
        $t->execute([$target]);
        $tid = (int)$t->fetchColumn();
        // optimistic check on the current status prevents lost updates
        $u = $this->db->prepare('UPDATE appointment SET appointment_status_id=? WHERE appointment_id=? AND business_unit_id=? AND appointment_status_id=?');
        $u->execute([$tid, $id, $unit, $cur['appointment_status_id']]);
        Session::flash($u->rowCount() ? 'success' : 'error', $u->rowCount() ? "Status changed to $target." : 'The request was changed by someone else. Please review it again.');
        $this->redirect("/staff/appointments/$id");
    }

    private function get(string $k): string
    {
        $v = $_GET[$k] ?? '';
        return is_string($v) ? trim($v) : '';
    }
}

