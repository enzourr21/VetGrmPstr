<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\CSRF;
use App\Core\Session;
use App\Models\Audit;
use App\Models\Units;
use PDO;

final class GroomingReceptionistController extends Controller
{
    private const UNIT_ID = 3; // Grooming Services

    /**
     * Helper to read query parameters.
     */
    private function get(string $key, string $default = ''): string
    {
        $v = $_GET[$key] ?? $default;
        return is_string($v) ? trim($v) : (string)$default;
    }

    /**
     * Helper to flash messages to session.
     */
    private function flash(string $type, string $message): void
    {
        Session::flash($type, $message);
    }

    /**
     * Helper to output JSON response.
     */
    private function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($data);
        exit;
    }

    /**
     * Strict Authorization Guard:
     * - Must be email-authenticated employee session.
     * - Super Administrator is authorized.
     * - Or user must hold RECEPTIONIST role for Grooming Services (business_unit_id = 3).
     */
    private function checkGroomingReceptionist(): array
    {
        $userId = Session::get('user_id');
        if (!$userId) {
            $this->redirect('/login');
        }

        $baseContext = Session::get('base_context', Session::get('employee_id') ? 'employee' : 'customer');
        $activeContext = Session::get('active_context', $baseContext);
        $authMethod = Session::get('auth_method', 'email');

        if ($baseContext !== 'employee' || $activeContext === 'customer' || $authMethod === 'username') {
            $this->forbidden('An email-authenticated Grooming Services Receptionist session is required.');
        }

        $uid = (int)$userId;
        $isSuper = (bool)Session::get('is_super_admin');

        $empStmt = $this->db->prepare(
            "SELECT employee_id, first_name, last_name, position, business_unit_id, employee_status
             FROM employee WHERE user_account_id = ? AND business_unit_id = ? AND employee_status = 'active'
             LIMIT 1"
        );
        $empStmt->execute([$uid, self::UNIT_ID]);
        $emp = $empStmt->fetch();
        $empId = $emp ? (int)$emp['employee_id'] : null;

        if (!$isSuper) {
            $roleStmt = $this->db->prepare(
                "SELECT 1 FROM account_role ar
                 JOIN role r ON r.role_id = ar.role_id
                 WHERE ar.user_account_id = ? AND ar.business_unit_id = ? AND r.role_name = 'RECEPTIONIST'
                 LIMIT 1"
            );
            $roleStmt->execute([$uid, self::UNIT_ID]);
            if (!$roleStmt->fetchColumn()) {
                $this->forbidden('Grooming Services Receptionist authorization is required.');
            }
        }

        return [$uid, $empId, self::UNIT_ID, $isSuper];
    }

    /**
     * 1. OVERVIEW PAGE:
     * - A. Current / Upcoming Appointment card (top, full width, 1 appointment only)
     * - B. Services card (50% width, 3-5 paginated)
     * - C. Today's Appointment Summary (50% width, matching height, pending and completed counts, "See Appointments" link)
     * - D. Employees Present (work_status = 'On Duty')
     */
    public function overview(): void
    {
        [$uid, $empId, $unitId] = $this->checkGroomingReceptionist();

        // A. Current / Upcoming Appointment for today
        $currentAppointment = $this->fetchCurrentOrUpcomingAppointment();

        // B. Grooming Services with pagination (4 per page)
        $svcPage = max(1, (int)($this->get('service_page') ?: 1));
        $svcPerPage = 4;
        $svcOffset = ($svcPage - 1) * $svcPerPage;

        $countStmt = $this->db->query("SELECT COUNT(*) FROM service WHERE service_category = 'grooming' AND service_status = 'active'");
        $totalServices = (int)$countStmt->fetchColumn();
        $totalSvcPages = max(1, (int)ceil($totalServices / $svcPerPage));

        $svcStmt = $this->db->prepare(
            "SELECT service_id, service_name, grooming_category, base_price, estimated_duration_minutes, description
             FROM service
             WHERE service_category = 'grooming' AND service_status = 'active'
             ORDER BY service_id ASC
             LIMIT ? OFFSET ?"
        );
        $svcStmt->bindValue(1, $svcPerPage, PDO::PARAM_INT);
        $svcStmt->bindValue(2, $svcOffset, PDO::PARAM_INT);
        $svcStmt->execute();
        $services = $svcStmt->fetchAll();

        // C. Today's Appointment Summary: Pending and Completed counts
        $pendingStmt = $this->db->prepare(
            "SELECT COUNT(*) FROM appointment a
             JOIN appointment_status st ON st.appointment_status_id = a.appointment_status_id
             WHERE a.business_unit_id = ? AND a.preferred_date = CURDATE() AND st.status_name = 'Pending'"
        );
        $pendingStmt->execute([$unitId]);
        $pendingCount = (int)$pendingStmt->fetchColumn();

        $completedStmt = $this->db->prepare(
            "SELECT COUNT(*) FROM appointment a
             JOIN appointment_status st ON st.appointment_status_id = a.appointment_status_id
             WHERE a.business_unit_id = ? AND a.preferred_date = CURDATE() AND st.status_name = 'Completed'"
        );
        $completedStmt->execute([$unitId]);
        $completedCount = (int)$completedStmt->fetchColumn();

        // D. Employees Present (Grooming unit employees on duty)
        $empStmt = $this->db->prepare(
            "SELECT e.employee_id, e.first_name, e.last_name, e.position, e.phone,
                    COALESCE(ews.work_status, 'Free') AS work_status,
                    (SELECT COUNT(DISTINCT ap.appointment_id)
                     FROM performed_service ps
                     JOIN appointment_pet ap ON ap.appointment_pet_id = ps.appointment_pet_id
                     JOIN appointment a ON a.appointment_id = ap.appointment_id
                     WHERE ps.performer_employee_id = e.employee_id
                       AND a.preferred_date = CURDATE()
                       AND a.appointment_status_id NOT IN (4, 5)) AS active_assignments
             FROM employee e
             LEFT JOIN employee_work_status ews ON ews.employee_id = e.employee_id
             WHERE e.business_unit_id = ? AND e.employee_status = 'active'
             ORDER BY CASE WHEN COALESCE(ews.work_status, 'Free') = 'On Duty' THEN 1 ELSE 2 END, e.employee_id ASC"
        );
        $empStmt->execute([$unitId]);
        $employeesPresent = $empStmt->fetchAll();

        $this->render('grooming_receptionist/overview', [
            'title' => 'Overview · Grooming Services Receptionist',
            'currentAppointment' => $currentAppointment,
            'services' => $services,
            'servicePage' => $svcPage,
            'totalServicePages' => $totalSvcPages,
            'totalServices' => $totalServices,
            'pendingCount' => $pendingCount,
            'completedCount' => $completedCount,
            'employeesPresent' => $employeesPresent,
        ]);
    }

    /**
     * 2. APPOINTMENTS PAGE:
     * - Tab 1: Appointment Requests (Pending / Awaiting Customer Response)
     * - Tab 2: Appointments Calendar (+ Walk-in Appointment button)
     */
    public function appointments(): void
    {
        [$uid, , $unitId] = $this->checkGroomingReceptionist();

        $activeTab = $this->get('tab') === 'calendar' ? 'calendar' : 'requests';

        // 1. Appointment Requests: online incoming requests
        $reqStmt = $this->db->prepare(
            "SELECT a.appointment_id, a.preferred_date, a.preferred_time, a.appointment_begin, a.appointment_end,
                    a.notes, a.created_at, st.status_name,
                    c.customer_id, c.first_name AS cust_first, c.last_name AS cust_last, c.phone AS cust_phone,
                    ua.email AS cust_email
             FROM appointment a
             JOIN appointment_status st ON st.appointment_status_id = a.appointment_status_id
             JOIN customer c ON c.customer_id = a.customer_id
             LEFT JOIN user_account ua ON ua.user_account_id = c.user_account_id
             WHERE a.business_unit_id = ?
               AND st.status_name IN ('Pending', 'Awaiting Customer Response')
             ORDER BY a.preferred_date ASC, COALESCE(a.preferred_time, '09:00:00') ASC, a.appointment_id ASC"
        );
        $reqStmt->execute([$unitId]);
        $rawRequests = $reqStmt->fetchAll();

        $requests = [];
        foreach ($rawRequests as $row) {
            $requests[] = $this->hydrateAppointmentDetails($row);
        }

        // 2. Appointments Calendar: 7-day chronological week context & overlap layout matching Grooming BM
        $weekParam = $this->get('week');
        $weekOffset = ($weekParam !== '' && is_numeric($weekParam)) ? (int)$weekParam : null;
        $calCtx = $this->buildCalendarWeekContext($this->get('start'), $this->get('month'), $weekOffset);

        $calStmt = $this->db->prepare(
            "SELECT a.appointment_id, a.business_unit_id, a.preferred_date, a.preferred_time,
                    a.appointment_begin, a.appointment_end, a.notes,
                    st.status_name,
                    c.customer_id, c.first_name, c.last_name, c.phone
             FROM appointment a
             JOIN appointment_status st ON st.appointment_status_id = a.appointment_status_id
             JOIN customer c ON c.customer_id = a.customer_id
             WHERE a.business_unit_id = ?
               AND a.preferred_date BETWEEN ? AND ?
             ORDER BY a.preferred_date ASC, COALESCE(TIME(a.appointment_begin), a.preferred_time, '09:00:00') ASC, a.appointment_id ASC"
        );
        $calStmt->execute([$unitId, $calCtx['startDateStr'], $calCtx['endDateStr']]);
        $rawCalAppts = $calStmt->fetchAll();

        $hydratedCalAppts = [];
        foreach ($rawCalAppts as $ra) {
            $hydratedCalAppts[] = $this->hydrateAppointmentDetails($ra);
        }

        [
            $gridStartHour,
            $gridEndHour,
            $gridHeightPx,
            $appointmentsByDate,
            $allVisibleAppointments,
        ] = $this->layoutAppointmentsForWeek($hydratedCalAppts, $calCtx['weekDays'], $calCtx['dateIndexMap']);

        // Fetch active Grooming services for walk-in modal
        $svcListStmt = $this->db->query(
            "SELECT service_id, service_name, grooming_category, base_price, estimated_duration_minutes, description
             FROM service
             WHERE service_category = 'grooming' AND service_status = 'active'
             ORDER BY service_id ASC"
        );
        $groomingServices = $svcListStmt->fetchAll();

        // Fetch Grooming staff for availability display
        $groomersStmt = $this->db->prepare(
            "SELECT e.employee_id, e.first_name, e.last_name, e.position,
                    COALESCE(ews.work_status, 'Free') AS work_status
             FROM employee e
             LEFT JOIN employee_work_status ews ON ews.employee_id = e.employee_id
             WHERE e.business_unit_id = ? AND e.employee_status = 'active'
             ORDER BY e.first_name ASC"
        );
        $groomersStmt->execute([$unitId]);
        $groomers = $groomersStmt->fetchAll();

        $selectedApptId = isset($_GET['select']) && ctype_digit((string)$_GET['select'])
            ? (int)$_GET['select']
            : null;

        // Legacy compatibility package for existing callers
        $calendarData = [
            'weekDays' => $calCtx['weekDays'],
            'appointmentsByDate' => $appointmentsByDate,
            'weekStartFormatted' => $calCtx['weekDays'][0]['month_short'] . ' ' . $calCtx['weekDays'][0]['day_num'],
            'weekEndFormatted' => $calCtx['weekDays'][6]['month_short'] . ' ' . $calCtx['weekDays'][6]['day_num'],
        ];

        $this->render('grooming_receptionist/appointments', [
            'title' => 'Appointments · Grooming Services Receptionist',
            'activeTab' => $activeTab,
            'requests' => $requests,
            'weekDays' => $calCtx['weekDays'],
            'startDateStr' => $calCtx['startDateStr'],
            'endDateStr' => $calCtx['endDateStr'],
            'prevWeekStart' => $calCtx['prevWeekStart'],
            'nextWeekStart' => $calCtx['nextWeekStart'],
            'todayWeekStart' => $calCtx['todayWeekStart'],
            'isCurrentWeek' => $calCtx['isCurrentWeek'],
            'monthPickerValue' => $calCtx['monthPickerValue'],
            'rangeHeading' => $calCtx['rangeHeading'],
            'gridStartHour' => $gridStartHour,
            'gridEndHour' => $gridEndHour,
            'gridHeightPx' => $gridHeightPx,
            'appointmentsByDate' => $appointmentsByDate,
            'allVisibleAppointments' => $allVisibleAppointments,
            'selectedApptId' => $selectedApptId,
            'calendarData' => $calendarData,
            'groomingServices' => $groomingServices,
            'groomers' => $groomers,
            'weekOffset' => $weekOffset ?? 0,
            'maxOverlapMinutes' => \App\Models\AppointmentScheduleValidator::getMaxOverlapMinutes($this->db, $unitId),
        ]);
    }

    /**
     * Accept Appointment Request:
     * - Validates schedule availability.
     * - Sets appointment status to 'Confirmed'.
     * - Computes duration and sets appointment_begin / appointment_end.
     * - Seeds performed_service records and auto-assigns qualified employee.
     */
    public function acceptRequest(int $id): void
    {
        [$uid, $empId, $unitId] = $this->checkGroomingReceptionist();
        CSRF::check();

        $stmt = $this->db->prepare(
            "SELECT a.*, st.status_name
             FROM appointment a
             JOIN appointment_status st ON st.appointment_status_id = a.appointment_status_id
             WHERE a.appointment_id = ? AND a.business_unit_id = ?"
        );
        $stmt->execute([$id, $unitId]);
        $appt = $stmt->fetch();

        if (!$appt || !in_array($appt['status_name'], ['Pending', 'Awaiting Customer Response'], true)) {
            $this->flash('error', 'Appointment request is not eligible for acceptance.');
            $this->redirect('/grooming/receptionist/appointments?tab=requests');
        }

        $hydrated = $this->hydrateAppointmentDetails($appt);
        $targetDate = $appt['preferred_date'];
        $targetTime = $appt['preferred_time'] ?: '09:00:00';

        // Check availability and auto-qualify employee
        $assignedEmpId = $this->findQualifiedEmployeeForServices($unitId, $hydrated['service_ids'] ?? [], $targetDate, $targetTime, (int)$hydrated['duration_minutes'], $id);

        $confirmedId = (int)$this->db->query("SELECT appointment_status_id FROM appointment_status WHERE status_name = 'Confirmed'")->fetchColumn();

        $beginDt = date('Y-m-d H:i:s', strtotime("$targetDate $targetTime"));
        $endDt = date('Y-m-d H:i:s', strtotime("$targetDate $targetTime + {$hydrated['duration_minutes']} minutes"));

        $this->db->beginTransaction();
        try {
            // Concurrency-safe overlap validation with business unit lock (FOR UPDATE)
            $valResult = \App\Models\AppointmentScheduleValidator::validateAppointment(
                $this->db,
                $unitId,
                $targetDate,
                $targetTime,
                (int)$hydrated['duration_minutes'],
                $id,
                null,
                true
            );

            if (!$valResult['valid']) {
                $this->db->rollBack();
                $this->flash('error', "Cannot accept appointment #$id: " . $valResult['error']);
                $this->redirect('/grooming/receptionist/appointments?tab=requests');
                return;
            }

            $up = $this->db->prepare(
                "UPDATE appointment
                 SET appointment_status_id = ?, appointment_begin = ?, appointment_end = ?, updated_at = NOW()
                 WHERE appointment_id = ?"
            );
            $up->execute([$confirmedId, $beginDt, $endDt, $id]);

            // Seed performed_service rows if not existing
            $checkPs = $this->db->prepare("SELECT COUNT(*) FROM performed_service ps JOIN appointment_pet ap ON ap.appointment_pet_id = ps.appointment_pet_id WHERE ap.appointment_id = ?");
            $checkPs->execute([$id]);
            if ((int)$checkPs->fetchColumn() === 0) {
                $seedPs = $this->db->prepare(
                    "INSERT INTO performed_service (requested_pet_service_id, appointment_pet_id, service_id, performed_price, quantity, performed_status, performer_employee_id)
                     SELECT rps.requested_pet_service_id, rps.appointment_pet_id, rps.service_id, rps.requested_price, 1, 'pending', ?
                     FROM requested_pet_service rps
                     JOIN appointment_pet ap ON ap.appointment_pet_id = rps.appointment_pet_id
                     WHERE ap.appointment_id = ?"
                );
                $seedPs->execute([$assignedEmpId, $id]);
            } else if ($assignedEmpId) {
                $upPs = $this->db->prepare("UPDATE performed_service ps JOIN appointment_pet ap ON ap.appointment_pet_id = ps.appointment_pet_id SET ps.performer_employee_id = ? WHERE ap.appointment_id = ?");
                $upPs->execute([$assignedEmpId, $id]);
            }

            Audit::log('grooming.appointment.accept', "Accepted Grooming appointment request #$id for $targetDate $targetTime.", $unitId, 'appointment', $id, $uid);
            $this->db->commit();
            $this->flash('success', "Appointment request #$id accepted and confirmed for $targetDate at " . date('g:i A', strtotime($targetTime)) . ".");
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->flash('error', 'Failed to accept appointment request: ' . $e->getMessage());
        }

        $this->redirect('/grooming/receptionist/appointments?tab=requests');
    }

    /**
     * Propose Alternative Start Time:
     * - Status becomes 'Awaiting Customer Response'.
     * - Updates preferred_date / preferred_time / notes.
     * - Booking remains pending until customer replies.
     */
    public function proposeSchedule(int $id): void
    {
        [$uid, , $unitId] = $this->checkGroomingReceptionist();
        CSRF::check();

        $stmt = $this->db->prepare(
            "SELECT a.*, st.status_name
             FROM appointment a
             JOIN appointment_status st ON st.appointment_status_id = a.appointment_status_id
             WHERE a.appointment_id = ? AND a.business_unit_id = ?"
        );
        $stmt->execute([$id, $unitId]);
        $appt = $stmt->fetch();

        if (!$appt || !in_array($appt['status_name'], ['Pending', 'Awaiting Customer Response'], true)) {
            $this->flash('error', 'Appointment request is not eligible for time proposal.');
            $this->redirect('/grooming/receptionist/appointments?tab=requests');
        }

        $newDate = trim((string)$this->post('proposed_date'));
        $newTime = trim((string)$this->post('proposed_time'));
        $proposalNote = trim((string)$this->post('proposal_note'));

        if ($newDate === '' || $newTime === '') {
            $this->flash('error', 'Both proposed date and time are required.');
            $this->redirect('/grooming/receptionist/appointments?tab=requests');
            return;
        }

        $hydrated = $this->hydrateAppointmentDetails($appt);
        $durationMinutes = (int)($hydrated['duration_minutes'] ?: 45);

        // Validate that proposed alternative schedule does not violate overlap rules
        $valResult = \App\Models\AppointmentScheduleValidator::validateAppointment(
            $this->db,
            $unitId,
            $newDate,
            $newTime,
            $durationMinutes,
            $id
        );

        if (!$valResult['valid']) {
            $this->flash('error', "Cannot propose this time: " . $valResult['error']);
            $this->redirect('/grooming/receptionist/appointments?tab=requests');
            return;
        }

        $awaitingStatusId = (int)$this->db->query("SELECT appointment_status_id FROM appointment_status WHERE status_name = 'Awaiting Customer Response'")->fetchColumn();

        $existingNotes = (string)($appt['notes'] ?? '');
        $noteAppend = "[Proposed by Receptionist: " . date('M j, Y g:i A') . " -> New Date: $newDate, New Time: $newTime. Note: " . ($proposalNote ?: 'Alternative schedule suggested') . "]";
        $updatedNotes = $existingNotes !== '' ? $existingNotes . "\n" . $noteAppend : $noteAppend;

        $this->db->prepare(
            "UPDATE appointment
             SET appointment_status_id = ?, preferred_date = ?, preferred_time = ?, notes = ?, updated_at = NOW()
             WHERE appointment_id = ?"
        )->execute([$awaitingStatusId, $newDate, $newTime, $updatedNotes, $id]);

        Audit::log('grooming.appointment.propose', "Proposed new schedule for Grooming appointment #$id: $newDate at $newTime.", $unitId, 'appointment', $id, $uid);
        $this->flash('success', "Proposed schedule for request #$id sent. Booking remains pending awaiting customer response.");
        $this->redirect('/grooming/receptionist/appointments?tab=requests');
    }

    /**
     * Reject/Decline Appointment Request:
     */
    public function rejectRequest(int $id): void
    {
        [$uid, , $unitId] = $this->checkGroomingReceptionist();
        CSRF::check();

        $stmt = $this->db->prepare(
            "SELECT a.*, st.status_name
             FROM appointment a
             JOIN appointment_status st ON st.appointment_status_id = a.appointment_status_id
             WHERE a.appointment_id = ? AND a.business_unit_id = ?"
        );
        $stmt->execute([$id, $unitId]);
        $appt = $stmt->fetch();

        if (!$appt || !in_array($appt['status_name'], ['Pending', 'Awaiting Customer Response'], true)) {
            $this->flash('error', 'Appointment request is not eligible for rejection.');
            $this->redirect('/grooming/receptionist/appointments?tab=requests');
        }

        $reason = trim((string)$this->post('reason'));
        $rejectedStatusId = (int)$this->db->query("SELECT appointment_status_id FROM appointment_status WHERE status_name = 'Rejected'")->fetchColumn();

        $existingNotes = (string)($appt['notes'] ?? '');
        $noteAppend = "[Declined by Receptionist: " . date('M j, Y g:i A') . ". Reason: " . ($reason ?: 'Scheduling unavailability') . "]";
        $updatedNotes = $existingNotes !== '' ? $existingNotes . "\n" . $noteAppend : $noteAppend;

        $this->db->prepare(
            "UPDATE appointment
             SET appointment_status_id = ?, notes = ?, updated_at = NOW()
             WHERE appointment_id = ?"
        )->execute([$rejectedStatusId, $updatedNotes, $id]);

        Audit::log('grooming.appointment.reject', "Declined Grooming appointment request #$id: $reason.", $unitId, 'appointment', $id, $uid);
        $this->flash('info', "Appointment request #$id has been declined.");
        $this->redirect('/grooming/receptionist/appointments?tab=requests');
    }

    /**
     * Search Registered Customers via AJAX:
     * Returns list of customers matching suggestion format:
     * `Customer Name #CustomerID`
     */
    public function searchCustomers(): void
    {
        $this->checkGroomingReceptionist();

        $q = trim((string)$this->get('q'));
        if ($q === '') {
            $this->json(['ok' => true, 'customers' => []]);
        }

        $stmt = $this->db->prepare(
            "SELECT c.customer_id, c.first_name, c.last_name, c.phone, ua.email
             FROM customer c
             LEFT JOIN user_account ua ON ua.user_account_id = c.user_account_id
             WHERE c.customer_status = 'active'
               AND (c.first_name LIKE ? OR c.last_name LIKE ? OR CONCAT(c.first_name, ' ', c.last_name) LIKE ? OR ua.email LIKE ? OR c.phone LIKE ? OR c.customer_id = ?)
             ORDER BY c.first_name ASC, c.last_name ASC
             LIMIT 10"
        );
        $like = '%' . $q . '%';
        $exactId = ctype_digit($q) ? (int)$q : 0;
        $stmt->execute([$like, $like, $like, $like, $like, $exactId]);
        $rows = $stmt->fetchAll();

        $customers = [];
        foreach ($rows as $r) {
            $name = trim($r['first_name'] . ' ' . $r['last_name']);
            $id = (int)$r['customer_id'];
            $customers[] = [
                'id' => $id,
                'name' => $name,
                'label' => "$name #$id", // Strict format: `Customer Name #CustomerID`
                'phone' => (string)($r['phone'] ?? ''),
                'email' => (string)($r['email'] ?? ''),
            ];
        }

        $this->json(['ok' => true, 'customers' => $customers]);
    }

    /**
     * Load Registered Customer's Pets via AJAX:
     */
    public function customerPets(int $id): void
    {
        $this->checkGroomingReceptionist();

        $stmt = $this->db->prepare(
            "SELECT pet_id, pet_name, species, breed, sex, weight_kg
             FROM pet
             WHERE customer_id = ? AND pet_status = 'active'
             ORDER BY pet_name ASC"
        );
        $stmt->execute([$id]);
        $pets = $stmt->fetchAll();

        $this->json(['ok' => true, 'pets' => $pets]);
    }

    /**
     * Create Walk-in Appointment (Modal Submission):
     * Handles 3 parts:
     * - Part 1: Customer Info (Guest vs Registered)
     * - Part 2: Pets & Services (Multi-pet, separate services per pet, package logic)
     * - Part 3: Appointment Date (Operating hours, cutoff, overlap, employee qualifications)
     */
    public function createWalkIn(): void
    {
        [$uid, $empId, $unitId] = $this->checkGroomingReceptionist();
        CSRF::check();

        $custType = $this->post('customer_type') === 'registered' ? 'registered' : 'guest';
        $guestName = trim((string)$this->post('guest_name'));
        $guestPhone = trim((string)$this->post('guest_phone'));
        $registeredCustId = (int)$this->post('customer_id');

        $apptDate = trim((string)$this->post('appointment_date'));
        $apptTime = trim((string)$this->post('appointment_time'));
        $walkInNotes = trim((string)$this->post('walkin_notes'));

        // Part 1 Validation
        if ($custType === 'guest') {
            if ($guestName === '') {
                $this->flash('error', 'Guest customer name is required.');
                $this->redirect('/grooming/receptionist/appointments?tab=calendar');
            }
            $targetCustomerId = $this->getOrCreateGuestPlaceholderCustomer();
            $customerDisplayName = $guestName;
        } else {
            if ($registeredCustId <= 0) {
                $this->flash('error', 'Please select a registered customer from search suggestions.');
                $this->redirect('/grooming/receptionist/appointments?tab=calendar');
            }
            $cCheck = $this->db->prepare("SELECT customer_id, first_name, last_name, phone FROM customer WHERE customer_id = ? AND customer_status = 'active'");
            $cCheck->execute([$registeredCustId]);
            $cRow = $cCheck->fetch();
            if (!$cRow) {
                $this->flash('error', 'Selected registered customer was not found.');
                $this->redirect('/grooming/receptionist/appointments?tab=calendar');
            }
            $targetCustomerId = $registeredCustId;
            $customerDisplayName = trim($cRow['first_name'] . ' ' . $cRow['last_name']);
        }

        // Part 2 Validation: Pets and Services
        $rawPets = $_POST['pets'] ?? [];
        if (!is_array($rawPets) || empty($rawPets)) {
            $this->flash('error', 'At least one pet and service selection is required.');
            $this->redirect('/grooming/receptionist/appointments?tab=calendar');
        }

        $allServicesCatalogStmt = $this->db->query("SELECT service_id, service_name, grooming_category, base_price, estimated_duration_minutes FROM service WHERE service_category = 'grooming' AND service_status = 'active'");
        $catalogServices = [];
        foreach ($allServicesCatalogStmt->fetchAll() as $s) {
            $catalogServices[(int)$s['service_id']] = $s;
        }

        $processedPets = [];
        $totalAppointmentDuration = 0;
        $allUniqueServiceIds = [];

        foreach ($rawPets as $idx => $pData) {
            $pName = trim((string)($pData['name'] ?? ''));
            $pSpecies = trim((string)($pData['species'] ?? 'Dog'));
            $existingPetId = isset($pData['pet_id']) ? (int)$pData['pet_id'] : 0;
            $svcIds = isset($pData['services']) && is_array($pData['services']) ? array_filter(array_map('intval', $pData['services'])) : [];

            if ($custType === 'registered') {
                if ($existingPetId <= 0) {
                    $this->flash('error', "Please select a valid registered pet for pet entry #" . ($idx + 1) . ".");
                    $this->redirect('/grooming/receptionist/appointments?tab=calendar');
                }
                $petCheck = $this->db->prepare("SELECT pet_id, pet_name, species FROM pet WHERE pet_id = ? AND customer_id = ? AND pet_status = 'active'");
                $petCheck->execute([$existingPetId, $targetCustomerId]);
                $pRow = $petCheck->fetch();
                if (!$pRow) {
                    $this->flash('error', "Pet entry does not belong to the selected registered customer.");
                    $this->redirect('/grooming/receptionist/appointments?tab=calendar');
                }
                $finalPetId = $existingPetId;
                $pName = $pRow['pet_name'];
                $pSpecies = $pRow['species'];
            } else {
                if ($pName === '') {
                    $this->flash('error', "Please provide a name for guest pet #" . ($idx + 1) . ".");
                    $this->redirect('/grooming/receptionist/appointments?tab=calendar');
                }
                $finalPetId = $this->getOrCreateGuestPlaceholderPet($targetCustomerId, $pName, $pSpecies);
            }

            if (empty($svcIds)) {
                $this->flash('error', "Please select at least one grooming service for pet: $pName.");
                $this->redirect('/grooming/receptionist/appointments?tab=calendar');
            }

            // Prevent duplicate selection of same service for same pet
            $svcIds = array_values(array_unique($svcIds));

            $petDuration = 0;
            foreach ($svcIds as $sid) {
                if (!isset($catalogServices[$sid])) {
                    $this->flash('error', "Selected service is not a valid active Grooming service.");
                    $this->redirect('/grooming/receptionist/appointments?tab=calendar');
                }
                $petDuration += max(15, (int)$catalogServices[$sid]['estimated_duration_minutes']);
                $allUniqueServiceIds[] = $sid;
            }

            $totalAppointmentDuration += $petDuration;
            $processedPets[] = [
                'pet_id' => $finalPetId,
                'name' => $pName,
                'species' => $pSpecies,
                'services' => $svcIds,
            ];
        }

        $allUniqueServiceIds = array_values(array_unique($allUniqueServiceIds));
        if ($totalAppointmentDuration <= 0) {
            $totalAppointmentDuration = 60;
        }

        // Part 3 Validation: Appointment Date & Time
        $dObj = \DateTime::createFromFormat('Y-m-d', $apptDate);
        if (!$dObj || $dObj->format('Y-m-d') !== $apptDate || $dObj < new \DateTime('today')) {
            $this->flash('error', 'Appointment date must be today or a future date. Past dates are not allowed.');
            $this->redirect('/grooming/receptionist/appointments?tab=calendar');
        }

        if ($apptTime === '' || !preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $apptTime)) {
            $this->flash('error', 'Please provide a valid appointment start time.');
            $this->redirect('/grooming/receptionist/appointments?tab=calendar');
        }

        $timeFormatted = strlen($apptTime) === 5 ? $apptTime . ':00' : $apptTime;

        // Operating hours & cutoff validation
        $dayOfWeek = (int)$dObj->format('N'); // 1 = Mon ... 7 = Sun
        $ohStmt = $this->db->prepare("SELECT is_open, open_time, close_time FROM operating_hours WHERE business_unit_id = ? AND day_of_week = ?");
        $ohStmt->execute([$unitId, $dayOfWeek]);
        $oh = $ohStmt->fetch();

        if ($oh) {
            if (!$oh['is_open']) {
                $this->flash('error', "Grooming Services is closed on " . $dObj->format('l') . "s.");
                $this->redirect('/grooming/receptionist/appointments?tab=calendar');
            }
            if ($oh['open_time'] && $oh['close_time']) {
                if ($timeFormatted < $oh['open_time'] || $timeFormatted >= $oh['close_time']) {
                    $this->flash('error', "Selected time falls outside operating hours (" . date('g:i A', strtotime($oh['open_time'])) . " - " . date('g:i A', strtotime($oh['close_time'])) . ").");
                    $this->redirect('/grooming/receptionist/appointments?tab=calendar');
                }
            }
        }

        // Automatic employee availability check
        $assignedEmpId = $this->findQualifiedEmployeeForServices($unitId, $allUniqueServiceIds, $apptDate, $timeFormatted, $totalAppointmentDuration);

        $confirmedStatusId = (int)$this->db->query("SELECT appointment_status_id FROM appointment_status WHERE status_name = 'Confirmed'")->fetchColumn();
        $beginDt = date('Y-m-d H:i:s', strtotime("$apptDate $timeFormatted"));
        $endDt = date('Y-m-d H:i:s', strtotime("$apptDate $timeFormatted + {$totalAppointmentDuration} minutes"));

        // Store guest details in structured notes payload
        $notesPayload = [
            'walk_in' => true,
            'customer_type' => $custType,
            'customer_name' => $customerDisplayName,
            'customer_phone' => $custType === 'guest' ? $guestPhone : '',
            'booked_by_employee_id' => $empId,
            'receptionist_notes' => $walkInNotes,
            'guest_pets' => $custType === 'guest' ? $processedPets : [],
        ];
        $notesEncoded = json_encode($notesPayload, JSON_UNESCAPED_SLASHES);

        $this->db->beginTransaction();
        try {
            // Authoritative server-side overlap validation with business unit lock (FOR UPDATE)
            $valResult = \App\Models\AppointmentScheduleValidator::validateAppointment(
                $this->db,
                $unitId,
                $apptDate,
                $timeFormatted,
                $totalAppointmentDuration,
                0,
                null,
                true
            );

            if (!$valResult['valid']) {
                $this->db->rollBack();
                $this->flash('error', $valResult['error']);
                $this->redirect('/grooming/receptionist/appointments?tab=calendar');
                return;
            }
            $branchId = Units::mainBranchId($unitId);
            $insAppt = $this->db->prepare(
                "INSERT INTO appointment (business_unit_id, branch_id, customer_id, appointment_status_id, appointment_type, preferred_date, preferred_time, appointment_begin, appointment_end, notes)
                 VALUES (?, ?, ?, ?, 'grooming', ?, ?, ?, ?, ?)"
            );
            $insAppt->execute([$unitId, $branchId, $targetCustomerId, $confirmedStatusId, $apptDate, $timeFormatted, $beginDt, $endDt, $notesEncoded]);
            $newApptId = (int)$this->db->lastInsertId();

            $insAp = $this->db->prepare("INSERT INTO appointment_pet (appointment_id, pet_id) VALUES (?, ?)");
            $insRps = $this->db->prepare("INSERT INTO requested_pet_service (appointment_pet_id, service_id, requested_price, request_status) VALUES (?, ?, ?, 'approved')");
            $insPs = $this->db->prepare("INSERT INTO performed_service (requested_pet_service_id, appointment_pet_id, service_id, performed_price, quantity, performed_status, performer_employee_id) VALUES (?, ?, ?, ?, 1, 'pending', ?)");

            foreach ($processedPets as $petItem) {
                $insAp->execute([$newApptId, $petItem['pet_id']]);
                $apId = (int)$this->db->lastInsertId();

                foreach ($petItem['services'] as $sId) {
                    $sPrice = (float)$catalogServices[$sId]['base_price'];
                    $insRps->execute([$apId, $sId, $sPrice]);
                    $rpsId = (int)$this->db->lastInsertId();
                    $insPs->execute([$rpsId, $apId, $sId, $sPrice, $assignedEmpId]);
                }
            }

            Audit::log('grooming.walkin.create', "Created walk-in Grooming appointment #$newApptId for $customerDisplayName on $apptDate at $timeFormatted.", $unitId, 'appointment', $newApptId, $uid);
            $this->db->commit();
            $this->flash('success', "Walk-in appointment #$newApptId booked successfully for $customerDisplayName on " . date('M j, Y', strtotime($apptDate)) . " at " . date('g:i A', strtotime($timeFormatted)) . ".");
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->flash('error', 'Failed to save walk-in appointment: ' . $e->getMessage());
        }

        $this->redirect('/grooming/receptionist/appointments?tab=calendar');
    }

    /**
     * 3. BILLING PAGE:
     * - Upcoming / Ongoing Appointments card
     * - Recently Completed Appointments card
     * - + Add Billing modal (Walk-in Customer vs Reserved Booking)
     */
    public function billing(): void
    {
        [$uid, , $unitId] = $this->checkGroomingReceptionist();

        // 1. Upcoming / Ongoing Appointments for today (eligible for finishing services and billing)
        $ongoingStmt = $this->db->prepare(
            "SELECT a.appointment_id, a.preferred_date, a.preferred_time, a.appointment_begin, a.appointment_end,
                    a.notes, st.status_name, c.customer_id, c.first_name, c.last_name, c.phone,
                    (SELECT COUNT(*) FROM performed_service ps JOIN appointment_pet ap ON ap.appointment_pet_id = ps.appointment_pet_id WHERE ap.appointment_id = a.appointment_id AND ps.performed_status = 'completed') AS completed_svc_count,
                    (SELECT COUNT(*) FROM performed_service ps JOIN appointment_pet ap ON ap.appointment_pet_id = ps.appointment_pet_id WHERE ap.appointment_id = a.appointment_id) AS total_svc_count,
                    (SELECT si.invoice_status FROM service_invoice si WHERE si.appointment_id = a.appointment_id LIMIT 1) AS invoice_status
             FROM appointment a
             JOIN appointment_status st ON st.appointment_status_id = a.appointment_status_id
             JOIN customer c ON c.customer_id = a.customer_id
             WHERE a.business_unit_id = ?
               AND a.preferred_date = CURDATE()
               AND st.status_name IN ('Confirmed', 'In Progress')
             ORDER BY a.appointment_begin DESC, a.appointment_id DESC"
        );
        $ongoingStmt->execute([$unitId]);
        $rawOngoing = $ongoingStmt->fetchAll();

        $ongoingAppointments = [];
        foreach ($rawOngoing as $row) {
            $hydrated = $this->hydrateAppointmentDetails($row);
            $ongoingAppointments[] = $hydrated;
        }

        // 2. Recently Completed Appointments (paid today)
        $completedStmt = $this->db->prepare(
            "SELECT a.appointment_id, a.preferred_date, a.preferred_time, a.appointment_begin, a.appointment_end,
                    a.notes, st.status_name, c.customer_id, c.first_name, c.last_name, c.phone,
                    si.invoice_id, si.total_amount, si.invoice_date, si.invoice_status,
                    p.payment_id, p.payment_method, p.amount AS payment_amount, p.payment_status
             FROM appointment a
             JOIN appointment_status st ON st.appointment_status_id = a.appointment_status_id
             JOIN customer c ON c.customer_id = a.customer_id
             JOIN service_invoice si ON si.appointment_id = a.appointment_id
             LEFT JOIN payment p ON p.invoice_id = si.invoice_id
             WHERE a.business_unit_id = ?
               AND si.invoice_status = 'paid'
             ORDER BY si.invoice_date DESC, a.appointment_id DESC
             LIMIT 10"
        );
        $completedStmt->execute([$unitId]);
        $rawCompleted = $completedStmt->fetchAll();

        $recentlyCompleted = [];
        foreach ($rawCompleted as $row) {
            $recentlyCompleted[] = $this->hydrateAppointmentDetails($row);
        }

        $this->render('grooming_receptionist/billing', [
            'title' => 'Billing · Grooming Services Receptionist',
            'ongoingAppointments' => $ongoingAppointments,
            'recentlyCompleted' => $recentlyCompleted,
        ]);
    }

    /**
     * Search Appointments for Billing via AJAX:
     * - Filters strictly for PENDING_PAYMENT eligibility:
     *   * Services finished / completed
     *   * No settled/paid invoice
     *   * Grooming Services unit only
     */
    public function searchBillingAppointments(): void
    {
        $this->checkGroomingReceptionist();

        $type = $this->get('type') === 'walkin' ? 'walkin' : 'reserved';
        $q = trim((string)$this->get('q'));

        $sql = "SELECT a.appointment_id, a.preferred_date, a.preferred_time, a.appointment_begin, a.appointment_end,
                       a.notes, st.status_name, c.customer_id, c.first_name, c.last_name, c.phone,
                       si.invoice_id, si.invoice_status
                FROM appointment a
                JOIN appointment_status st ON st.appointment_status_id = a.appointment_status_id
                JOIN customer c ON c.customer_id = a.customer_id
                LEFT JOIN service_invoice si ON si.appointment_id = a.appointment_id
                WHERE a.business_unit_id = ?
                  AND st.status_name NOT IN ('Cancelled', 'Rejected')
                  AND (si.invoice_status IS NULL OR si.invoice_status != 'paid')";

        $params = [self::UNIT_ID];

        if ($type === 'walkin') {
            if ($q !== '') {
                $sql .= " AND (a.notes LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ?)";
                $like = '%' . $q . '%';
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
            }
        } else {
            if ($q !== '') {
                // If contains #ID or digit
                if (preg_match('/#(\d+)/', $q, $m)) {
                    $sql .= " AND (c.customer_id = ? OR a.appointment_id = ?)";
                    $params[] = (int)$m[1];
                    $params[] = (int)$m[1];
                } elseif (ctype_digit($q)) {
                    $sql .= " AND (a.appointment_id = ? OR c.customer_id = ?)";
                    $params[] = (int)$q;
                    $params[] = (int)$q;
                } else {
                    $sql .= " AND (c.first_name LIKE ? OR c.last_name LIKE ? OR CONCAT(c.first_name, ' ', c.last_name) LIKE ?)";
                    $like = '%' . $q . '%';
                    $params[] = $like;
                    $params[] = $like;
                    $params[] = $like;
                }
            }
        }

        $sql .= " ORDER BY a.preferred_date DESC, a.appointment_id DESC LIMIT 15";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $results = [];
        foreach ($rows as $r) {
            $hydrated = $this->hydrateAppointmentDetails($r);
            // Calculate billing breakdown
            $breakdown = $this->calculateBillingBreakdown((int)$r['appointment_id']);
            $hydrated['billing_breakdown'] = $breakdown;
            $hydrated['total_amount'] = $breakdown['total'];
            $results[] = $hydrated;
        }

        $this->json(['ok' => true, 'appointments' => $results]);
    }

    /**
     * Get Appointment Billing Details via AJAX:
     */
    public function billingAppointmentDetails(int $id): void
    {
        $this->checkGroomingReceptionist();

        $stmt = $this->db->prepare(
            "SELECT a.appointment_id, a.preferred_date, a.preferred_time, a.appointment_begin, a.appointment_end,
                    a.notes, st.status_name, c.customer_id, c.first_name, c.last_name, c.phone,
                    si.invoice_id, si.invoice_status
             FROM appointment a
             JOIN appointment_status st ON st.appointment_status_id = a.appointment_status_id
             JOIN customer c ON c.customer_id = a.customer_id
             LEFT JOIN service_invoice si ON si.appointment_id = a.appointment_id
             WHERE a.appointment_id = ? AND a.business_unit_id = ?"
        );
        $stmt->execute([$id, self::UNIT_ID]);
        $row = $stmt->fetch();

        if (!$row) {
            $this->json(['ok' => false, 'error' => 'Appointment not found.'], 404);
        }

        if (!empty($row['invoice_status']) && $row['invoice_status'] === 'paid') {
            $this->json(['ok' => false, 'error' => 'This appointment has already been billed and paid.'], 422);
        }

        $hydrated = $this->hydrateAppointmentDetails($row);
        $breakdown = $this->calculateBillingBreakdown($id);
        $hydrated['billing_breakdown'] = $breakdown;
        $hydrated['total_amount'] = $breakdown['total'];

        $this->json(['ok' => true, 'appointment' => $hydrated]);
    }

    /**
     * Settle Billing (Submit Payment):
     * - Validates Payment Method before Total Cost.
     * - Validates Payment Received >= Total Cost.
     * - Calculates Change = Payment Received - Total Cost.
     * - Marks service_invoice as 'paid', marks appointment as 'Completed'.
     * - Prevents duplicate billing.
     */
    public function settleBilling(): void
    {
        [$uid, $empId, $unitId] = $this->checkGroomingReceptionist();
        CSRF::check();

        $apptId = (int)$this->post('appointment_id');
        $paymentMethod = strtolower(trim((string)$this->post('payment_method')));
        $paymentReceived = (float)$this->post('payment_received');

        $allowedMethods = ['cash', 'gcash', 'maya', 'other'];
        if (!in_array($paymentMethod, $allowedMethods, true)) {
            $this->flash('error', 'Please choose a valid payment method (Cash, GCash, Maya, Other).');
            $this->redirect('/grooming/receptionist/billing');
        }

        // Verify appointment existence and eligibility
        $stmt = $this->db->prepare(
            "SELECT a.*, st.status_name,
                    si.invoice_id, si.invoice_status
             FROM appointment a
             JOIN appointment_status st ON st.appointment_status_id = a.appointment_status_id
             LEFT JOIN service_invoice si ON si.appointment_id = a.appointment_id
             WHERE a.appointment_id = ? AND a.business_unit_id = ?"
        );
        $stmt->execute([$apptId, $unitId]);
        $appt = $stmt->fetch();

        if (!$appt) {
            $this->flash('error', 'Appointment not found in Grooming Services.');
            $this->redirect('/grooming/receptionist/billing');
        }

        if (!empty($appt['invoice_status']) && $appt['invoice_status'] === 'paid') {
            $this->flash('error', 'This appointment has already been settled and paid. Duplicate billing is prohibited.');
            $this->redirect('/grooming/receptionist/billing');
        }

        // Calculate accurate total cost
        $breakdown = $this->calculateBillingBreakdown($apptId);
        $totalCost = (float)$breakdown['total'];

        if ($paymentReceived < $totalCost) {
            $this->flash('error', "Payment Received (₱" . number_format($paymentReceived, 2) . ") cannot be less than Total Cost (₱" . number_format($totalCost, 2) . ").");
            $this->redirect('/grooming/receptionist/billing');
        }

        $change = $paymentReceived - $totalCost;

        $completedStatusId = (int)$this->db->query("SELECT appointment_status_id FROM appointment_status WHERE status_name = 'Completed'")->fetchColumn();

        $this->db->beginTransaction();
        try {
            // 1. Mark performed_service rows as completed if still pending
            $upPs = $this->db->prepare(
                "UPDATE performed_service ps
                 JOIN appointment_pet ap ON ap.appointment_pet_id = ps.appointment_pet_id
                 SET ps.performed_status = 'completed', ps.updated_at = NOW()
                 WHERE ap.appointment_id = ? AND ps.performed_status != 'completed'"
            );
            $upPs->execute([$apptId]);

            // 2. Create or update service_invoice
            $invoiceId = !empty($appt['invoice_id']) ? (int)$appt['invoice_id'] : 0;
            if ($invoiceId > 0) {
                $upInv = $this->db->prepare(
                    "UPDATE service_invoice
                     SET total_amount = ?, invoice_status = 'paid', updated_at = NOW()
                     WHERE invoice_id = ?"
                );
                $upInv->execute([$totalCost, $invoiceId]);
            } else {
                $insInv = $this->db->prepare(
                    "INSERT INTO service_invoice (appointment_id, invoice_date, total_amount, invoice_status)
                     VALUES (?, NOW(), ?, 'paid')"
                );
                $insInv->execute([$apptId, $totalCost]);
                $invoiceId = (int)$this->db->lastInsertId();
            }

            // 3. Record invoice_item rows
            $psRowsStmt = $this->db->prepare(
                "SELECT ps.performed_service_id, ps.performed_price, ps.quantity
                 FROM performed_service ps
                 JOIN appointment_pet ap ON ap.appointment_pet_id = ps.appointment_pet_id
                 WHERE ap.appointment_id = ?"
            );
            $psRowsStmt->execute([$apptId]);
            $performedRows = $psRowsStmt->fetchAll();

            $checkIi = $this->db->prepare("SELECT COUNT(*) FROM invoice_item WHERE invoice_id = ?");
            $checkIi->execute([$invoiceId]);
            if ((int)$checkIi->fetchColumn() === 0) {
                $insItem = $this->db->prepare("INSERT INTO invoice_item (invoice_id, performed_service_id, unit_price, quantity) VALUES (?, ?, ?, ?)");
                foreach ($performedRows as $prow) {
                    $insItem->execute([$invoiceId, $prow['performed_service_id'], $prow['performed_price'], $prow['quantity'] ?: 1]);
                }
            }

            // 4. Create payment record
            $insPay = $this->db->prepare(
                "INSERT INTO payment (invoice_id, payment_type, amount, payment_method, payment_status)
                 VALUES (?, 'payment', ?, ?, 'completed')"
            );
            $insPay->execute([$invoiceId, $totalCost, $paymentMethod]);

            // 5. Update appointment status to Completed
            $upAppt = $this->db->prepare(
                "UPDATE appointment
                 SET appointment_status_id = ?, appointment_end = COALESCE(appointment_end, NOW()), updated_at = NOW()
                 WHERE appointment_id = ?"
            );
            $upAppt->execute([$completedStatusId, $apptId]);

            // 6. Record billing metadata into notes
            $rawNotes = (string)($appt['notes'] ?? '');
            $notesObj = json_decode($rawNotes, true);
            if (!is_array($notesObj)) {
                $notesObj = ['raw' => $rawNotes];
            }
            $notesObj['billing'] = [
                'invoice_id' => $invoiceId,
                'total_cost' => $totalCost,
                'payment_method' => $paymentMethod,
                'payment_received' => $paymentReceived,
                'change' => $change,
                'billed_by_employee_id' => $empId,
                'billed_at' => date('Y-m-d H:i:s'),
            ];
            $upNotes = $this->db->prepare("UPDATE appointment SET notes = ? WHERE appointment_id = ?");
            $upNotes->execute([json_encode($notesObj, JSON_UNESCAPED_SLASHES), $apptId]);

            Audit::log('grooming.billing.settle', "Settled payment of ₱" . number_format($totalCost, 2) . " via " . ucfirst($paymentMethod) . " for Appointment #$apptId (Change: ₱" . number_format($change, 2) . ").", $unitId, 'appointment', $apptId, $uid);
            $this->db->commit();
            $this->flash('success', "Payment settled successfully for Appointment #$apptId. Total: ₱" . number_format($totalCost, 2) . ", Change: ₱" . number_format($change, 2) . ".");
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->flash('error', 'Failed to settle billing: ' . $e->getMessage());
        }

        $this->redirect('/grooming/receptionist/billing');
    }

    /**
     * 4. RECORDS PAGE (Read-only transaction log):
     * - Filters: All, Pending Payment, Completed
     * - Table view with Detail Modal
     */
    public function records(): void
    {
        [$uid, , $unitId] = $this->checkGroomingReceptionist();

        $filter = strtolower(trim((string)($this->get('status') ?: 'all')));

        $sql = "SELECT a.appointment_id, a.preferred_date, a.preferred_time, a.appointment_begin, a.appointment_end,
                       a.notes, a.created_at, st.status_name,
                       c.customer_id, c.first_name, c.last_name, c.phone,
                       si.invoice_id, si.total_amount, si.invoice_date, si.invoice_status,
                       p.payment_id, p.payment_method, p.amount AS payment_amount, p.payment_status
                FROM appointment a
                JOIN appointment_status st ON st.appointment_status_id = a.appointment_status_id
                JOIN customer c ON c.customer_id = a.customer_id
                LEFT JOIN service_invoice si ON si.appointment_id = a.appointment_id
                LEFT JOIN payment p ON p.invoice_id = si.invoice_id
                WHERE a.business_unit_id = ?
                  AND st.status_name NOT IN ('Cancelled', 'Rejected')";

        $params = [$unitId];

        if ($filter === 'pending_payment' || $filter === 'pending payment') {
            $sql .= " AND (st.status_name = 'Pending' OR (si.invoice_status IS NULL OR si.invoice_status != 'paid'))";
        } elseif ($filter === 'completed') {
            $sql .= " AND (st.status_name = 'Completed' OR si.invoice_status = 'paid')";
        }

        $sql .= " ORDER BY a.preferred_date DESC, a.appointment_id DESC LIMIT 50";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rawRecords = $stmt->fetchAll();

        $records = [];
        foreach ($rawRecords as $row) {
            $hydrated = $this->hydrateAppointmentDetails($row);
            $breakdown = $this->calculateBillingBreakdown((int)$row['appointment_id']);
            $hydrated['billing_breakdown'] = $breakdown;
            $hydrated['total_amount'] = !empty($row['total_amount']) ? (float)$row['total_amount'] : (float)$breakdown['total'];
            $records[] = $hydrated;
        }

        $this->render('grooming_receptionist/records', [
            'title' => 'Records · Grooming Services Receptionist',
            'records' => $records,
            'statusFilter' => $filter,
        ]);
    }

    /**
     * AJAX Endpoint for Record Details Modal:
     */
    public function recordDetails(int $id): void
    {
        $this->checkGroomingReceptionist();

        $stmt = $this->db->prepare(
            "SELECT a.appointment_id, a.preferred_date, a.preferred_time, a.appointment_begin, a.appointment_end,
                    a.notes, a.created_at, st.status_name,
                    c.customer_id, c.first_name, c.last_name, c.phone, ua.email,
                    si.invoice_id, si.total_amount, si.invoice_date, si.invoice_status,
                    p.payment_id, p.payment_method, p.amount AS payment_amount, p.payment_status
             FROM appointment a
             JOIN appointment_status st ON st.appointment_status_id = a.appointment_status_id
             JOIN customer c ON c.customer_id = a.customer_id
             LEFT JOIN user_account ua ON ua.user_account_id = c.user_account_id
             LEFT JOIN service_invoice si ON si.appointment_id = a.appointment_id
             LEFT JOIN payment p ON p.invoice_id = si.invoice_id
             WHERE a.appointment_id = ? AND a.business_unit_id = ?"
        );
        $stmt->execute([$id, self::UNIT_ID]);
        $row = $stmt->fetch();

        if (!$row) {
            $this->json(['ok' => false, 'error' => 'Record not found.'], 404);
        }

        $hydrated = $this->hydrateAppointmentDetails($row);
        $breakdown = $this->calculateBillingBreakdown($id);
        $hydrated['billing_breakdown'] = $breakdown;
        $hydrated['total_amount'] = !empty($row['total_amount']) ? (float)$row['total_amount'] : (float)$breakdown['total'];

        $this->json(['ok' => true, 'record' => $hydrated]);
    }

    /**
     * 5. EMPLOYEES PAGE (Read-only Directory for Grooming Services):
     */
    public function employees(): void
    {
        [$uid, , $unitId] = $this->checkGroomingReceptionist();

        $q = trim((string)$this->get('q'));
        $wsFilter = trim((string)$this->get('work_status'));

        $sql = "SELECT e.employee_id, e.first_name, e.last_name, e.position, e.phone, e.employee_status,
                       COALESCE(ews.work_status, 'Free') AS work_status,
                       ua.email,
                       (SELECT COUNT(DISTINCT ap.appointment_id)
                        FROM performed_service ps
                        JOIN appointment_pet ap ON ap.appointment_pet_id = ps.appointment_pet_id
                        JOIN appointment a ON a.appointment_id = ap.appointment_id
                        WHERE ps.performer_employee_id = e.employee_id
                          AND a.preferred_date = CURDATE()
                          AND a.appointment_status_id NOT IN (4, 5)) AS today_assignments
                FROM employee e
                JOIN user_account ua ON ua.user_account_id = e.user_account_id
                LEFT JOIN employee_work_status ews ON ews.employee_id = e.employee_id
                WHERE e.business_unit_id = ? AND e.employee_status = 'active'";

        $params = [$unitId];

        if ($q !== '') {
            $sql .= " AND (e.first_name LIKE ? OR e.last_name LIKE ? OR CONCAT(e.first_name, ' ', e.last_name) LIKE ? OR ua.email LIKE ? OR e.phone LIKE ?)";
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        if ($wsFilter !== '') {
            $sql .= " AND COALESCE(ews.work_status, 'Free') = ?";
            $params[] = $wsFilter;
        }

        $sql .= " ORDER BY e.first_name ASC, e.last_name ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $employees = $stmt->fetchAll();

        $this->render('grooming_receptionist/employees', [
            'title' => 'Employees · Grooming Services Receptionist',
            'unitName' => 'Grooming Services',
            'employees' => $employees,
            'searchQuery' => $q,
            'workStatusFilter' => $wsFilter,
        ]);
    }

    // =========================================================================
    // HELPER METHODS: Hydration, Availability, Guest Handling, Calculations
    // =========================================================================

    /**
     * Fetch Single Current or Upcoming Appointment for Overview top section:
     */
    private function fetchCurrentOrUpcomingAppointment(): ?array
    {
        // 1. Current In Progress today
        $inProgStmt = $this->db->prepare(
            "SELECT a.appointment_id, a.preferred_date, a.preferred_time, a.appointment_begin, a.appointment_end,
                    a.notes, st.status_name, c.customer_id, c.first_name, c.last_name, c.phone, ua.email
             FROM appointment a
             JOIN appointment_status st ON st.appointment_status_id = a.appointment_status_id
             JOIN customer c ON c.customer_id = a.customer_id
             LEFT JOIN user_account ua ON ua.user_account_id = c.user_account_id
             WHERE a.business_unit_id = ?
               AND a.preferred_date = CURDATE()
               AND st.status_name = 'In Progress'
             ORDER BY a.appointment_begin DESC, a.appointment_id DESC
             LIMIT 1"
        );
        $inProgStmt->execute([self::UNIT_ID]);
        $row = $inProgStmt->fetch();
        if ($row) {
            return $this->hydrateAppointmentDetails($row);
        }

        // 2. Next Upcoming appointment today
        $upStmt = $this->db->prepare(
            "SELECT a.appointment_id, a.preferred_date, a.preferred_time, a.appointment_begin, a.appointment_end,
                    a.notes, st.status_name, c.customer_id, c.first_name, c.last_name, c.phone, ua.email
             FROM appointment a
             JOIN appointment_status st ON st.appointment_status_id = a.appointment_status_id
             JOIN customer c ON c.customer_id = a.customer_id
             LEFT JOIN user_account ua ON ua.user_account_id = c.user_account_id
             WHERE a.business_unit_id = ?
               AND a.preferred_date = CURDATE()
               AND st.status_name IN ('Confirmed', 'Pending', 'Awaiting Customer Response')
             ORDER BY a.preferred_time ASC, a.appointment_id ASC
             LIMIT 1"
        );
        $upStmt->execute([self::UNIT_ID]);
        $row = $upStmt->fetch();
        if ($row) {
            return $this->hydrateAppointmentDetails($row);
        }

        return null;
    }

    /**
     * Comprehensive Hydration of Appointment Data:
     */
    private function hydrateAppointmentDetails(array $appt): array
    {
        $apptId = (int)$appt['appointment_id'];

        // Parse structured notes if present
        $notesRaw = (string)($appt['notes'] ?? '');
        $notesData = json_decode($notesRaw, true);
        $isGuest = is_array($notesData) && !empty($notesData['walk_in']) && ($notesData['customer_type'] ?? '') === 'guest';

        if ($isGuest) {
            $customerName = trim((string)($notesData['customer_name'] ?? 'Walk-in Guest'));
            $customerPhone = trim((string)($notesData['customer_phone'] ?? ''));
            $customerIdDisplay = null; // Strictly NULL for guest
        } else {
            $customerName = trim(($appt['first_name'] ?? '') . ' ' . ($appt['last_name'] ?? ''));
            if ($customerName === '' && !empty($appt['cust_first'])) {
                $customerName = trim($appt['cust_first'] . ' ' . ($appt['cust_last'] ?? ''));
            }
            if ($customerName === '') {
                $customerName = 'Registered Customer';
            }
            $customerPhone = (string)($appt['phone'] ?? ($appt['cust_phone'] ?? ''));
            $customerIdDisplay = !empty($appt['customer_id']) ? (int)$appt['customer_id'] : null;
        }

        // Query Pets
        $petStmt = $this->db->prepare(
            "SELECT p.pet_id, p.pet_name, p.species, p.breed, p.sex, p.weight_kg
             FROM appointment_pet ap
             JOIN pet p ON p.pet_id = ap.pet_id
             WHERE ap.appointment_id = ?
             ORDER BY ap.appointment_pet_id ASC"
        );
        $petStmt->execute([$apptId]);
        $pets = $petStmt->fetchAll();

        $petNames = [];
        $petDetails = [];
        if ($isGuest && !empty($notesData['guest_pets']) && is_array($notesData['guest_pets'])) {
            foreach ($notesData['guest_pets'] as $gp) {
                $pName = trim((string)($gp['name'] ?? 'Pet'));
                $pSpecies = trim((string)($gp['species'] ?? 'Dog'));
                $petNames[] = $pName . ' (' . $pSpecies . ')';
                $petDetails[] = ['name' => $pName, 'species' => $pSpecies, 'breed' => $pSpecies, 'sex' => 'Unknown', 'weight_kg' => ''];
            }
        } else {
            foreach ($pets as $p) {
                $species = ucfirst((string)($p['species'] ?? 'Pet'));
                $breed = trim((string)($p['breed'] ?? ''));
                $display = $p['pet_name'] . ' (' . ($breed !== '' ? $breed : $species) . ')';
                $petNames[] = $display;
                $petDetails[] = [
                    'pet_id' => (int)$p['pet_id'],
                    'name' => $p['pet_name'],
                    'species' => $species,
                    'breed' => $breed,
                    'sex' => (string)($p['sex'] ?? 'Unknown'),
                    'weight_kg' => (string)($p['weight_kg'] ?? ''),
                ];
            }
        }

        // Query Services & Performer Employee
        $svcStmt = $this->db->prepare(
            "SELECT s.service_id, s.service_name, s.grooming_category, s.base_price, s.estimated_duration_minutes,
                    ps.performed_status, ps.performer_employee_id,
                    CONCAT(e.first_name, ' ', e.last_name) AS performer_name, e.position AS performer_position
             FROM appointment_pet ap
             LEFT JOIN performed_service ps ON ps.appointment_pet_id = ap.appointment_pet_id
             LEFT JOIN requested_pet_service rps ON rps.appointment_pet_id = ap.appointment_pet_id
             JOIN service s ON s.service_id = COALESCE(ps.service_id, rps.service_id)
             LEFT JOIN employee e ON e.employee_id = ps.performer_employee_id
             WHERE ap.appointment_id = ?
             ORDER BY s.service_id ASC"
        );
        $svcStmt->execute([$apptId]);
        $servicesRows = $svcStmt->fetchAll();

        $serviceNames = [];
        $serviceIds = [];
        $assignedEmployees = [];
        $totalDuration = 0;

        foreach ($servicesRows as $sr) {
            $sId = (int)$sr['service_id'];
            $sName = trim((string)$sr['service_name']);
            if (!in_array($sName, $serviceNames, true)) {
                $serviceNames[] = $sName;
                $serviceIds[] = $sId;
                $totalDuration += max(15, (int)($sr['estimated_duration_minutes'] ?? 30));
            }
            if (!empty($sr['performer_name'])) {
                $assignedEmployees[$sr['performer_name']] = $sr['performer_name'] . ' (' . ($sr['performer_position'] ?: 'Staff') . ')';
            }
        }

        if ($totalDuration <= 0) {
            $totalDuration = 60;
        }

        // Calculate start & end times and minutes
        $timeStr = '09:00:00';
        if (!empty($appt['appointment_begin'])) {
            $timeStr = date('H:i:s', strtotime((string)$appt['appointment_begin']));
        } elseif (!empty($appt['preferred_time'])) {
            $timeStr = (string)$appt['preferred_time'];
        }

        [$startH, $startM] = array_map('intval', array_pad(explode(':', $timeStr), 2, 0));
        $startMinutes = ($startH * 60) + $startM;
        $endMinutes = $startMinutes + $totalDuration;
        if (!empty($appt['appointment_end'])) {
            $endTs = strtotime((string)$appt['appointment_end']);
            if ($endTs !== false) {
                $endH = (int)date('H', $endTs);
                $endM = (int)date('i', $endTs);
                $calcEnd = ($endH * 60) + $endM;
                if ($calcEnd > $startMinutes) {
                    $endMinutes = $calcEnd;
                    $totalDuration = $endMinutes - $startMinutes;
                }
            }
        }

        $startFormatted = date('g:i A', mktime((int)floor($startMinutes / 60), $startMinutes % 60, 0));
        $endFormatted = date('g:i A', mktime((int)floor($endMinutes / 60), $endMinutes % 60, 0));

        // Derived Status & BM Calendar Status Colors
        $rawStatus = (string)($appt['status_name'] ?? 'Pending');
        $invoiceStatus = (string)($appt['invoice_status'] ?? '');
        $displayStatus = $rawStatus;
        $statusBadge = 'badge-secondary';

        if ($rawStatus === 'Confirmed') {
            $statusBadge = 'badge-success';
            $colorClass = 'cal-status-green';
            $statusColorName = 'green';
            $badgeClass = 'ok';
        } elseif ($rawStatus === 'In Progress') {
            $statusBadge = 'badge-warning';
            $colorClass = 'cal-status-yellow';
            $statusColorName = 'yellow';
            $badgeClass = 'warn';
        } elseif ($rawStatus === 'Completed') {
            $statusBadge = 'badge-success';
            $colorClass = 'cal-status-transparent';
            $statusColorName = 'transparent';
            $badgeClass = 'ok';
        } elseif (in_array($rawStatus, ['Rejected', 'Cancelled'], true)) {
            $statusBadge = 'badge-danger';
            $colorClass = 'cal-status-red';
            $statusColorName = 'red';
            $badgeClass = 'err';
        } elseif ($rawStatus === 'Awaiting Customer Response') {
            $statusBadge = 'badge-info';
            $colorClass = 'cal-status-yellow';
            $statusColorName = 'yellow';
            $badgeClass = 'warn';
        } else {
            $colorClass = 'cal-status-transparent';
            $statusColorName = 'transparent';
            $badgeClass = '';
        }

        // Check if eligible for PENDING_PAYMENT
        $isPendingPayment = false;
        if (($rawStatus === 'Completed' && $invoiceStatus !== 'paid') || ($rawStatus === 'In Progress' && $invoiceStatus !== 'paid')) {
            $isPendingPayment = true;
            $displayStatus = 'Pending Payment';
            $statusBadge = 'badge-warning';
        }

        $appt['customer_name'] = $customerName;
        $appt['customer_display_name'] = $customerName;
        $appt['customer_phone'] = $customerPhone;
        $appt['customer_id_display'] = $customerIdDisplay;
        $appt['is_guest'] = $isGuest;
        $appt['pets_summary'] = $petNames ? implode(', ', $petNames) : 'No pet recorded';
        $appt['pet_summary'] = $appt['pets_summary'];
        $appt['pet_items'] = $petDetails;
        $appt['services_summary'] = $serviceNames ? implode(', ', $serviceNames) : 'Standard Grooming';
        $appt['service_list'] = $serviceNames;
        $appt['service_ids'] = $serviceIds;
        $appt['duration_minutes'] = $totalDuration;
        $appt['start_minutes'] = $startMinutes;
        $appt['end_minutes'] = $endMinutes;
        $appt['time_coverage'] = "$startFormatted - $endFormatted";
        $appt['assigned_employee'] = $assignedEmployees ? implode(', ', $assignedEmployees) : 'Unassigned';
        $appt['assigned_employee_display'] = $appt['assigned_employee'];
        $appt['display_status'] = $displayStatus;
        $appt['status_badge_class'] = $statusBadge;
        $appt['color_class'] = $colorClass;
        $appt['status_color_name'] = $statusColorName;
        $appt['badge_class'] = $badgeClass;
        $appt['is_pending_payment'] = $isPendingPayment;
        $appt['formatted_date'] = date('M j, Y', strtotime((string)$appt['preferred_date']));

        return $appt;
    }

    /**
     * Calculate Itemized Billing Breakdown without double-charging:
     * - Packages (e.g. Full Grooming) kept separate.
     * - Quantities reflect number of pets receiving the item.
     * - Separate add-ons kept as distinct items.
     */
    private function calculateBillingBreakdown(int $appointmentId): array
    {
        $stmt = $this->db->prepare(
            "SELECT s.service_id, s.service_name, s.grooming_category, s.base_price,
                    COUNT(DISTINCT ap.appointment_pet_id) AS pet_count
             FROM appointment_pet ap
             LEFT JOIN performed_service ps ON ps.appointment_pet_id = ap.appointment_pet_id
             LEFT JOIN requested_pet_service rps ON rps.appointment_pet_id = ap.appointment_pet_id
             JOIN service s ON s.service_id = COALESCE(ps.service_id, rps.service_id)
             WHERE ap.appointment_id = ?
             GROUP BY s.service_id, s.service_name, s.grooming_category, s.base_price
             ORDER BY s.base_price DESC"
        );
        $stmt->execute([$appointmentId]);
        $rows = $stmt->fetchAll();

        $items = [];
        $total = 0.0;

        foreach ($rows as $r) {
            $unitPrice = (float)$r['base_price'];
            $qty = max(1, (int)$r['pet_count']);
            $subtotal = $unitPrice * $qty;
            $total += $subtotal;

            $isPackage = stripos($r['service_name'], 'Full Grooming') !== false || stripos($r['service_name'], 'Package') !== false;

            $items[] = [
                'service_id' => (int)$r['service_id'],
                'service_name' => $r['service_name'],
                'category' => (string)($r['grooming_category'] ?: 'Grooming'),
                'is_package' => $isPackage,
                'unit_price' => $unitPrice,
                'quantity' => $qty,
                'subtotal' => $subtotal,
            ];
        }

        return [
            'items' => $items,
            'total' => $total,
        ];
    }

    /**
     * Automatic Employee Qualification & Availability Check:
     * - Bather can do 'Bathing & Brushing'.
     * - Stylist can do 'Styling'.
     * - Master Stylist can do both.
     * - Specialized services performed by qualified staff.
     */
    private function findQualifiedEmployeeForServices(int $unitId, array $serviceIds, string $date, string $time, int $durationMinutes, int $excludeApptId = 0): ?int
    {
        $servicesStmt = $this->db->prepare("SELECT service_id, service_name, grooming_category FROM service WHERE service_id IN (" . implode(',', array_fill(0, count($serviceIds) ?: 1, '?')) . ")");
        $servicesStmt->execute($serviceIds ?: [0]);
        $services = $servicesStmt->fetchAll();

        $needsBather = false;
        $needsStylist = false;
        foreach ($services as $svc) {
            $cat = (string)($svc['grooming_category'] ?? '');
            $name = (string)($svc['service_name'] ?? '');
            if ($cat === 'Bathing & Brushing' || stripos($name, 'Bath') !== false) {
                $needsBather = true;
            }
            if ($cat === 'Styling' || stripos($name, 'Haircut') !== false || stripos($name, 'Grooming') !== false) {
                $needsStylist = true;
            }
        }

        // Fetch active employees with roles in unit 3
        $empStmt = $this->db->prepare(
            "SELECT e.employee_id, e.first_name, e.last_name, e.position,
                    GROUP_CONCAT(r.role_name) AS roles
             FROM employee e
             LEFT JOIN account_role ar ON ar.user_account_id = e.user_account_id AND ar.business_unit_id = e.business_unit_id
             LEFT JOIN role r ON r.role_id = ar.role_id
             WHERE e.business_unit_id = ? AND e.employee_status = 'active'
             GROUP BY e.employee_id
             ORDER BY e.employee_id ASC"
        );
        $empStmt->execute([$unitId]);
        $candidates = $empStmt->fetchAll();

        $startTs = strtotime("$date $time");
        $endTs = $startTs + ($durationMinutes * 60);

        foreach ($candidates as $cand) {
            $empId = (int)$cand['employee_id'];
            $pos = (string)$cand['position'];
            $roles = array_filter(explode(',', (string)$cand['roles']));

            $isMaster = stripos($pos, 'Master') !== false || (in_array('STYLIST_WORKSPACE', $roles, true) && in_array('BATHER_WORKSPACE', $roles, true));
            $isStylist = in_array('STYLIST_WORKSPACE', $roles, true) || stripos($pos, 'Stylist') !== false;
            $isBather = in_array('BATHER_WORKSPACE', $roles, true) || stripos($pos, 'Bather') !== false;

            // Qualification Check
            if ($needsBather && $needsStylist) {
                if (!$isMaster && !($isStylist && $isBather)) {
                    continue;
                }
            } elseif ($needsStylist && !$isStylist && !$isMaster) {
                continue;
            } elseif ($needsBather && !$isBather && !$isMaster) {
                continue;
            }

            // Conflict check
            $confStmt = $this->db->prepare(
                "SELECT a.appointment_id, a.preferred_date, a.preferred_time, a.appointment_begin, a.appointment_end
                 FROM appointment a
                 JOIN appointment_pet ap ON ap.appointment_id = a.appointment_id
                 JOIN performed_service ps ON ps.appointment_pet_id = ap.appointment_pet_id
                 JOIN appointment_status st ON st.appointment_status_id = a.appointment_status_id
                 WHERE a.business_unit_id = ?
                   AND a.preferred_date = ?
                   AND a.appointment_id != ?
                   AND ps.performer_employee_id = ?
                   AND st.status_name IN ('Confirmed', 'In Progress')"
            );
            $confStmt->execute([$unitId, $date, $excludeApptId, $empId]);
            $conflicts = $confStmt->fetchAll();

            $hasConflict = false;
            foreach ($conflicts as $cf) {
                $cfStart = strtotime(!empty($cf['appointment_begin']) ? (string)$cf['appointment_begin'] : "{$cf['preferred_date']} {$cf['preferred_time']}");
                $cfEnd = !empty($cf['appointment_end']) ? strtotime((string)$cf['appointment_end']) : $cfStart + 3600;
                if ($startTs < $cfEnd && $cfStart < $endTs) {
                    $hasConflict = true;
                    break;
                }
            }

            if (!$hasConflict) {
                return $empId;
            }
        }

        // Fallback to first available Master Stylist or groomer if all busy
        foreach ($candidates as $cand) {
            if (stripos($cand['position'], 'Master') !== false || stripos($cand['position'], 'Stylist') !== false) {
                return (int)$cand['employee_id'];
            }
        }

        return !empty($candidates) ? (int)$candidates[0]['employee_id'] : null;
    }

    /**
     * Build 7-day chronological week context for weekly calendar views matching Grooming BM.
     */
    private function buildCalendarWeekContext(?string $startParam, ?string $monthParam, ?int $weekOffset = null): array
    {
        $today = new \DateTimeImmutable('today');
        // Current week start (Monday of current week)
        $dow = (int)$today->format('N'); // 1 (Mon) .. 7 (Sun)
        $todayWeekStartObj = $today->modify('-' . ($dow - 1) . ' days');

        $startParam = trim((string)$startParam);
        $monthParam = trim((string)$monthParam);

        $startDate = $todayWeekStartObj;
        if ($startParam !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $startParam)) {
            try {
                $parsed = new \DateTimeImmutable($startParam);
                if ($parsed->format('Y-m-d') === $startParam) {
                    $startDate = $parsed;
                }
            } catch (\Throwable $e) {
                $startDate = $todayWeekStartObj;
            }
        } elseif ($monthParam !== '' && preg_match('/^\d{4}-\d{2}$/', $monthParam)) {
            $firstOfMonthStr = $monthParam . '-01';
            try {
                $parsed = new \DateTimeImmutable($firstOfMonthStr);
                if ($parsed->format('Y-m-d') === $firstOfMonthStr) {
                    $startDate = $parsed;
                }
            } catch (\Throwable $e) {
                $startDate = $todayWeekStartObj;
            }
        } elseif ($weekOffset !== null && $weekOffset !== 0) {
            $startDate = $todayWeekStartObj->modify(($weekOffset > 0 ? "+$weekOffset" : "$weekOffset") . " week");
        }

        // Build the 7 chronological dates
        $weekDays = [];
        $dateIndexMap = [];
        for ($i = 0; $i < 7; $i++) {
            $d = $startDate->modify("+{$i} days");
            $dStr = $d->format('Y-m-d');
            $dateIndexMap[$dStr] = $i;
            $weekDays[] = [
                'date' => $dStr,
                'day_short' => $d->format('D'),
                'day_label' => $d->format('M j'),
                'day_num' => $d->format('j'),
                'month_short' => $d->format('M'),
                'is_today' => ($dStr === $today->format('Y-m-d')),
            ];
        }

        $endDate = $startDate->modify('+6 days');
        $startDateStr = $startDate->format('Y-m-d');
        $endDateStr = $endDate->format('Y-m-d');

        $prevWeekStart = $startDate->modify('-7 days')->format('Y-m-d');
        $nextWeekStart = $startDate->modify('+7 days')->format('Y-m-d');
        $todayWeekStart = $todayWeekStartObj->format('Y-m-d');
        $isCurrentWeek = ($today->format('Y-m-d') >= $startDateStr && $today->format('Y-m-d') <= $endDateStr);

        $monthPickerValue = $startDate->format('Y-m');

        if ($startDate->format('Y') !== $endDate->format('Y')) {
            $rangeHeading = $startDate->format('M j, Y') . ' – ' . $endDate->format('M j, Y');
        } elseif ($startDate->format('m') !== $endDate->format('m')) {
            $rangeHeading = $startDate->format('M j') . ' – ' . $endDate->format('M j, Y');
        } else {
            $rangeHeading = $startDate->format('M j') . ' – ' . $endDate->format('j, Y');
        }

        return [
            'startDate' => $startDate,
            'endDate' => $endDate,
            'startDateStr' => $startDateStr,
            'endDateStr' => $endDateStr,
            'weekDays' => $weekDays,
            'dateIndexMap' => $dateIndexMap,
            'prevWeekStart' => $prevWeekStart,
            'nextWeekStart' => $nextWeekStart,
            'todayWeekStart' => $todayWeekStart,
            'isCurrentWeek' => $isCurrentWeek,
            'monthPickerValue' => $monthPickerValue,
            'rangeHeading' => $rangeHeading,
        ];
    }

    /**
     * Layout appointments across the 7-day grid, expanding hours dynamically and computing overlap columns.
     */
    private function layoutAppointmentsForWeek(array $hydratedAppts, array $weekDays, array $dateIndexMap): array
    {
        $gridStartHour = 8;
        $gridEndHour = 20;

        foreach ($hydratedAppts as $h) {
            $apptStartHour = (int)floor($h['start_minutes'] / 60);
            $apptEndHour = (int)ceil($h['end_minutes'] / 60);
            if ($apptStartHour < $gridStartHour) {
                $gridStartHour = max(0, $apptStartHour);
            }
            if ($apptEndHour > $gridEndHour) {
                $gridEndHour = min(24, $apptEndHour);
            }
        }

        $gridHeightPx = ($gridEndHour - $gridStartHour) * 60;

        $appointmentsByDate = [];
        foreach ($weekDays as $wd) {
            $appointmentsByDate[$wd['date']] = [];
        }

        foreach ($hydratedAppts as $ha) {
            $dKey = (string)$ha['preferred_date'];
            if (!isset($appointmentsByDate[$dKey])) {
                continue;
            }
            $ha['day_index'] = $dateIndexMap[$dKey] ?? 0;
            $ha['top_px'] = max(0, $ha['start_minutes'] - ($gridStartHour * 60));
            $ha['height_px'] = max(38, $ha['end_minutes'] - $ha['start_minutes']);
            $appointmentsByDate[$dKey][] = $ha;
        }

        $allVisibleAppointments = [];
        foreach ($appointmentsByDate as $dKey => &$dayList) {
            $dayList = $this->layoutDayOverlappingAppointments($dayList);
            foreach ($dayList as $item) {
                $allVisibleAppointments[] = $item;
            }
        }
        unset($dayList);

        return [
            $gridStartHour,
            $gridEndHour,
            $gridHeightPx,
            $appointmentsByDate,
            $allVisibleAppointments,
        ];
    }

    /**
     * Compute side-by-side column indices for overlapping appointments on the same day.
     */
    private function layoutDayOverlappingAppointments(array $dayAppts): array
    {
        if (empty($dayAppts)) {
            return [];
        }

        usort($dayAppts, static function (array $a, array $b): int {
            if ($a['start_minutes'] !== $b['start_minutes']) {
                return $a['start_minutes'] <=> $b['start_minutes'];
            }
            if ($a['end_minutes'] !== $b['end_minutes']) {
                return $b['end_minutes'] <=> $a['end_minutes'];
            }
            return $a['appointment_id'] <=> $b['appointment_id'];
        });

        $clusters = [];
        $currentCluster = [];
        $clusterMaxEnd = -1;

        foreach ($dayAppts as $appt) {
            if (empty($currentCluster) || $appt['start_minutes'] < $clusterMaxEnd) {
                $currentCluster[] = $appt;
                $clusterMaxEnd = max($clusterMaxEnd, (int)$appt['end_minutes']);
            } else {
                $clusters[] = $currentCluster;
                $currentCluster = [$appt];
                $clusterMaxEnd = (int)$appt['end_minutes'];
            }
        }
        if (!empty($currentCluster)) {
            $clusters[] = $currentCluster;
        }

        $result = [];
        foreach ($clusters as $cluster) {
            $colEndTimes = [];
            foreach ($cluster as &$ev) {
                $placedCol = null;
                foreach ($colEndTimes as $cIdx => $cEnd) {
                    if ($ev['start_minutes'] >= $cEnd) {
                        $placedCol = $cIdx;
                        $colEndTimes[$cIdx] = (int)$ev['end_minutes'];
                        break;
                    }
                }
                if ($placedCol === null) {
                    $placedCol = count($colEndTimes);
                    $colEndTimes[$placedCol] = (int)$ev['end_minutes'];
                }
                $ev['overlap_col_index'] = $placedCol;
            }
            unset($ev);

            $totalCols = max(1, count($colEndTimes));
            foreach ($cluster as $ev) {
                $ev['overlap_total_cols'] = $totalCols;
                $result[] = $ev;
            }
        }

        return $result;
    }

    /**
     * Legacy compatibility wrapper:
     */
    private function buildCalendarData(int $unitId, int $weekOffset = 0): array
    {
        $calCtx = $this->buildCalendarWeekContext(null, null, $weekOffset);
        $stmt = $this->db->prepare(
            "SELECT a.appointment_id, a.preferred_date, a.preferred_time, a.appointment_begin, a.appointment_end,
                    a.notes, st.status_name, c.customer_id, c.first_name, c.last_name, c.phone
             FROM appointment a
             JOIN appointment_status st ON st.appointment_status_id = a.appointment_status_id
             JOIN customer c ON c.customer_id = a.customer_id
             WHERE a.business_unit_id = ?
               AND a.preferred_date BETWEEN ? AND ?
               AND st.status_name NOT IN ('Cancelled', 'Rejected')
             ORDER BY a.preferred_date ASC, a.preferred_time ASC"
        );
        $stmt->execute([$unitId, $calCtx['startDateStr'], $calCtx['endDateStr']]);
        $rows = $stmt->fetchAll();

        $apptsByDate = [];
        foreach ($calCtx['weekDays'] as $wd) {
            $apptsByDate[$wd['date']] = [];
        }

        foreach ($rows as $r) {
            $hydrated = $this->hydrateAppointmentDetails($r);
            $apptsByDate[$r['preferred_date']][] = $hydrated;
        }

        return [
            'weekDays' => $calCtx['weekDays'],
            'appointmentsByDate' => $apptsByDate,
            'weekStartFormatted' => $calCtx['weekDays'][0]['month_short'] . ' ' . $calCtx['weekDays'][0]['day_num'],
            'weekEndFormatted' => $calCtx['weekDays'][6]['month_short'] . ' ' . $calCtx['weekDays'][6]['day_num'] . ', ' . $calCtx['startDate']->format('Y'),
        ];
    }

    /**
     * Internal System Guest Placeholder Customer:
     * Satisfies foreign key constraints for guest walk-ins without creating new accounts.
     */
    private function getOrCreateGuestPlaceholderCustomer(): int
    {
        $stmt = $this->db->query("SELECT customer_id FROM customer WHERE first_name = 'Walk-in' AND last_name = 'Guest' LIMIT 1");
        $id = $stmt->fetchColumn();
        if ($id) {
            return (int)$id;
        }

        $uaStmt = $this->db->query("SELECT user_account_id FROM user_account WHERE email = 'guest.walkin@vetgrpet.local' LIMIT 1");
        $uaId = $uaStmt->fetchColumn();
        if (!$uaId) {
            $this->db->prepare("INSERT INTO user_account (email, username, password_hash, account_status) VALUES ('guest.walkin@vetgrpet.local', 'guest_walkin', 'SYSTEM_GUEST_NO_LOGIN', 'inactive')")->execute();
            $uaId = (int)$this->db->lastInsertId();
        }

        $this->db->prepare("INSERT INTO customer (user_account_id, first_name, last_name, customer_status) VALUES (?, 'Walk-in', 'Guest', 'active')")->execute([(int)$uaId]);
        return (int)$this->db->lastInsertId();
    }

    /**
     * Internal System Guest Placeholder Pet:
     */
    private function getOrCreateGuestPlaceholderPet(int $guestCustId, string $petName, string $species): int
    {
        $pStmt = $this->db->prepare("SELECT pet_id FROM pet WHERE customer_id = ? AND pet_name = ? AND species = ? LIMIT 1");
        $pStmt->execute([$guestCustId, $petName, $species]);
        $pId = $pStmt->fetchColumn();
        if ($pId) {
            return (int)$pId;
        }

        $this->db->prepare("INSERT INTO pet (customer_id, pet_name, species, pet_status) VALUES (?, ?, ?, 'active')")->execute([$guestCustId, $petName, $species]);
        return (int)$this->db->lastInsertId();
    }
}
