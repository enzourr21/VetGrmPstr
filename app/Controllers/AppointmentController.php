<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\CSRF;
use App\Core\Session;
use App\Models\Units;

final class AppointmentController extends Controller
{
    public function index(): void
    {
        [, $cid] = $this->requireCustomer();
        $s = $this->db->prepare('SELECT a.appointment_id, a.preferred_date, a.preferred_time, a.created_at, st.status_name,
                (SELECT GROUP_CONCAT(DISTINCT p.pet_name ORDER BY p.pet_name SEPARATOR ", ") FROM appointment_pet ap JOIN pet p ON p.pet_id=ap.pet_id WHERE ap.appointment_id=a.appointment_id) AS pets
            FROM appointment a JOIN appointment_status st ON st.appointment_status_id=a.appointment_status_id
            WHERE a.customer_id=? ORDER BY a.created_at DESC');
        $s->execute([$cid]);
        $this->render('appointments/index', ['title' => 'My appointment requests', 'rows' => $s->fetchAll()]);
    }

    public function create(): void
    {
        [, $cid] = $this->requireCustomer();
        $this->render('appointments/form', $this->formData($cid, [], []));
    }

    private function formData(int $cid, array $selected, array $errors, array $old = []): array
    {
        $p = $this->db->prepare('SELECT pet_id, pet_name, species FROM pet WHERE customer_id=? AND pet_status="active" ORDER BY pet_name');
        $p->execute([$cid]);
        $s = $this->db->query('SELECT service_id, service_name, description, base_price, estimated_duration_minutes FROM service WHERE service_category="grooming" AND service_status="active" ORDER BY service_name');
        return ['title' => 'Request a grooming appointment', 'pets' => $p->fetchAll(), 'services' => $s->fetchAll(), 'selected' => $selected, 'errors' => $errors, 'old' => $old];
    }

    public function store(): void
    {
        [, $cid] = $this->requireCustomer();
        CSRF::check();
        $unit = Units::groomingId();
        $errors = [];

        $date = $this->post('preferred_date');
        $time = $this->post('preferred_time');
        $notes = $this->post('notes');
        $d = \DateTime::createFromFormat('Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date || $d < new \DateTime('today')) $errors['preferred_date'] = 'Choose a date today or later.';
        if ($time !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) $errors['preferred_time'] = 'Enter a valid time.';
        if (mb_strlen($notes) > 1000) $errors['notes'] = 'Maximum 1000 characters.';

        // pets[petId][] = serviceId  — normalise to unique int ids
        $raw = $_POST['pets'] ?? [];
        $selected = [];
        if (is_array($raw)) {
            foreach ($raw as $petId => $svcs) {
                if (!is_array($svcs)) continue;
                $ids = array_values(array_unique(array_filter(array_map('intval', $svcs), fn($x) => $x > 0)));
                if ($ids) $selected[(int)$petId] = $ids;
            }
        }
        if (!$selected) $errors['pets'] = 'Choose at least one pet and at least one service for it.';

        if (!$errors) {
            // every pet must belong to this customer and be active
            $ph = implode(',', array_fill(0, count($selected), '?'));
            $c = $this->db->prepare("SELECT pet_id FROM pet WHERE customer_id=? AND pet_status='active' AND pet_id IN ($ph)");
            $c->execute([$cid, ...array_keys($selected)]);
            if (count($c->fetchAll()) !== count($selected)) $errors['pets'] = 'One of the selected pets is not available.';

            // every service must be an active grooming service (price comes from the DB, not the browser)
            $allSvc = array_values(array_unique(array_merge(...array_values($selected))));
            $ph2 = implode(',', array_fill(0, count($allSvc), '?'));
            $q = $this->db->prepare("SELECT service_id, base_price FROM service WHERE service_category='grooming' AND service_status='active' AND service_id IN ($ph2)");
            $q->execute($allSvc);
            $prices = array_column($q->fetchAll(), 'base_price', 'service_id');
            if (count($prices) !== count($allSvc)) $errors['pets'] = 'One of the selected services is not available.';
        }

        if ($errors) {
            http_response_code(422);
            $this->render('appointments/form', $this->formData($cid, $selected, $errors, ['preferred_date' => $date, 'preferred_time' => $time, 'notes' => $notes]));
            return;
        }

        try {
            $this->db->beginTransaction();
            $pending = (int)$this->db->query('SELECT appointment_status_id FROM appointment_status WHERE status_name="Pending"')->fetchColumn();
            $this->db->prepare('INSERT INTO appointment (business_unit_id, branch_id, customer_id, appointment_status_id, appointment_type, preferred_date, preferred_time, notes) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$unit, Units::mainBranchId($unit), $cid, $pending, 'grooming', $date, $time !== '' ? $time : null, $notes !== '' ? $notes : null]);
            $aid = (int)$this->db->lastInsertId();
            $insPet = $this->db->prepare('INSERT INTO appointment_pet (appointment_id, pet_id) VALUES (?, ?)');
            $insSvc = $this->db->prepare('INSERT INTO requested_pet_service (appointment_pet_id, service_id, requested_price) VALUES (?, ?, ?)');
            foreach ($selected as $petId => $svcIds) {
                $insPet->execute([$aid, $petId]);
                $apId = (int)$this->db->lastInsertId();
                foreach ($svcIds as $sid) {
                    $insSvc->execute([$apId, $sid, $prices[$sid]]);
                }
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e; // global handler logs it and shows a generic error; nothing was saved
        }
        Session::flash('success', 'Request submitted. It is pending and not yet confirmed.');
        $this->redirect("/appointments/$aid");
    }

    public function show(int $id): void
    {
        [, $cid] = $this->requireCustomer();
        $s = $this->db->prepare('SELECT a.*, st.status_name FROM appointment a JOIN appointment_status st ON st.appointment_status_id=a.appointment_status_id WHERE a.appointment_id=? AND a.customer_id=?');
        $s->execute([$id, $cid]);
        $a = $s->fetch() ?: $this->notFound();
        $this->render('appointments/show', ['title' => 'Appointment request #' . $id, 'a' => $a, 'lines' => self::lines($this->db, $id), 'staff' => false]);
    }

    public static function lines(\PDO $db, int $appointmentId): array
    {
        $s = $db->prepare('SELECT p.pet_name, p.species, sv.service_name, rps.requested_price, sv.estimated_duration_minutes
            FROM appointment_pet ap JOIN pet p ON p.pet_id=ap.pet_id
            JOIN requested_pet_service rps ON rps.appointment_pet_id=ap.appointment_pet_id
            JOIN service sv ON sv.service_id=rps.service_id
            WHERE ap.appointment_id=? ORDER BY p.pet_name, sv.service_name');
        $s->execute([$appointmentId]);
        return $s->fetchAll();
    }
}

