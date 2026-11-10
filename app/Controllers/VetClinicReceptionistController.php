<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\CSRF;
use App\Core\Session;
use App\Models\Audit;
use App\Models\AppointmentScheduleValidator;
use App\Models\Units;
use PDO;

final class VetClinicReceptionistController extends Controller
{
    private const UNIT_ID = 2; // Veterinary Clinic

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
     * - Or user must hold RECEPTIONIST role for Veterinary Clinic (business_unit_id = 2).
     */
    private function checkVetReceptionist(): array
    {
        $userId = Session::get('user_id');
        if (!$userId) {
            $this->redirect('/login');
        }

        $baseContext = Session::get('base_context', Session::get('employee_id') ? 'employee' : 'customer');
        $activeContext = Session::get('active_context', $baseContext);
        $authMethod = Session::get('auth_method', 'email');

        if ($baseContext !== 'employee' || $activeContext === 'customer' || $authMethod === 'username') {
            $this->forbidden('An email-authenticated Veterinary Clinic Receptionist session is required.');
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
                $this->forbidden('Veterinary Clinic Receptionist authorization is required.');
            }
        }

        return [$uid, $empId, self::UNIT_ID, $isSuper];
    }

    /**
     * 1. OVERVIEW PAGE:
     * - A. Current / Upcoming Appointment card (top, full width, 1 appointment only for TODAY)
     * - B. Services card (50% width, paginated medical services)
     * - C. Today's Appointment Summary (50% width, matching height, counts for Pending, Confirmed, In Progress, Pending Payment, Completed)
     * - D. Employees Present (duty priority: Lead/Assoc Vet > Vet Nurse > Vet Assistant > Receptionist)
     */
    public function overview(): void
    {
        [$uid, $empId, $unitId] = $this->checkVetReceptionist();

        // A. Current / Upcoming Appointment for today
        $currentAppointment = $this->fetchCurrentOrUpcomingAppointment();

        // B. Veterinary Clinic Medical Services with pagination (4 per page)
        $svcPage = max(1, (int)($this->get('service_page') ?: 1));
        $svcPerPage = 4;
        $svcOffset = ($svcPage - 1) * $svcPerPage;

        $countStmt = $this->db->query("SELECT COUNT(*) FROM service WHERE service_category = 'medical' AND service_status = 'active'");
        $totalServices = (int)$countStmt->fetchColumn();
        $totalSvcPages = max(1, (int)ceil($totalServices / $svcPerPage));

        $svcStmt = $this->db->prepare(
            "SELECT service_id, service_name, base_price, estimated_duration_minutes, description
             FROM service
             WHERE service_category = 'medical' AND service_status = 'active'
             ORDER BY service_id ASC
             LIMIT ? OFFSET ?"
        );
        $svcStmt->bindValue(1, $svcPerPage, PDO::PARAM_INT);
        $svcStmt->bindValue(2, $svcOffset, PDO::PARAM_INT);
        $svcStmt->execute();
        $services = $svcStmt->fetchAll();

        // C. Today's Appointment Summary: Pending, Confirmed, In Progress, Pending Payment, Completed
        $summaryStmt = $this->db->prepare(
            "SELECT a.appointment_id, st.status_name, si.invoice_status
             FROM appointment a
             JOIN appointment_status st ON st.appointment_status_id = a.appointment_status_id
             LEFT JOIN service_invoice si ON si.appointment_id = a.appointment_id
             WHERE a.business_unit_id = ? AND a.preferred_date = CURDATE()
               AND st.status_name NOT IN ('Cancelled', 'Rejected')"
        );
        $summaryStmt->execute([$unitId]);
        $todayRows = $summaryStmt->fetchAll();

        $pendingCount = 0;
        $confirmedCount = 0;
        $inProgressCount = 0;
        $pendingPaymentCount = 0;
        $completedCount = 0;

        foreach ($todayRows as $tr) {
            $stName = (string)$tr['status_name'];
            $invStatus = (string)($tr['invoice_status'] ?? '');

            if ($stName === 'Pending' || $stName === 'Awaiting Customer Response') {
                $pendingCount++;
            } elseif ($stName === 'Confirmed') {
                $confirmedCount++;
            } elseif ($stName === 'In Progress') {
                $inProgressCount++;
            } elseif ($stName === 'Completed') {
                if ($invStatus === 'paid') {
                    $completedCount++;
                } else {
                    $pendingPaymentCount++;
                }
            }
        }

        // D. Employees Present (Veterinary Clinic employees)
        $employeesPresent = $this->fetchEmployeesPresent($unitId);

        $this->render('vetclinic_receptionist/overview', [
            'title' => 'Overview · Veterinary Clinic Receptionist',
            'currentAppointment' => $currentAppointment,
            'services' => $services,
            'servicePage' => $svcPage,
            'totalServicePages' => $totalSvcPages,
            'totalServices' => $totalServices,
            'pendingCount' => $pendingCount,
            'confirmedCount' => $confirmedCount,
            'inProgressCount' => $inProgressCount,
            'pendingPaymentCount' => $pendingPaymentCount,
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
        [$uid, , $unitId] = $this->checkVetReceptionist();

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

        // 2. Appointments Calendar: 7-day chronological week context & overlap layout matching BM
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

        // Fetch active Veterinary Clinic medical services for walk-in modal
        $svcListStmt = $this->db->query(
            "SELECT service_id, service_name, base_price, estimated_duration_minutes, description
             FROM service
             WHERE service_category = 'medical' AND service_status = 'active'
             ORDER BY service_id ASC"
        );
        $medicalServices = $svcListStmt->fetchAll();

        // Fetch Veterinary Clinic clinicians for display
        $cliniciansStmt = $this->db->prepare(
            "SELECT e.employee_id, e.first_name, e.last_name, e.position,
                    COALESCE(ews.work_status, 'Free') AS work_status
             FROM employee e
             LEFT JOIN employee_work_status ews ON ews.employee_id = e.employee_id
             WHERE e.business_unit_id = ? AND e.employee_status = 'active'
               AND e.position IN ('Lead Veterinarian', 'Lead Vet', 'Associate Veterinarian', 'Associate Vet', 'Veterinary Nurse', 'Vet Nurse', 'Veterinary Assistant', 'Vet Assistant', 'Receptionist')
             ORDER BY e.first_name ASC"
        );
        $cliniciansStmt->execute([$unitId]);
        $clinicians = $cliniciansStmt->fetchAll();

        $selectedApptId = isset($_GET['select']) && ctype_digit((string)$_GET['select'])
            ? (int)$_GET['select']
            : null;

        $calendarData = [
            'weekDays' => $calCtx['weekDays'],
            'appointmentsByDate' => $appointmentsByDate,
            'weekStartFormatted' => $calCtx['weekDays'][0]['month_short'] . ' ' . $calCtx['weekDays'][0]['day_num'],
            'weekEndFormatted' => $calCtx['weekDays'][6]['month_short'] . ' ' . $calCtx['weekDays'][6]['day_num'],
        ];

        $this->render('vetclinic_receptionist/appointments', [
            'title' => 'Appointments · Veterinary Clinic Receptionist',
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
            'medicalServices' => $medicalServices,
            'clinicians' => $clinicians,
            'weekOffset' => $weekOffset ?? 0,
            'maxOverlapMinutes' => AppointmentScheduleValidator::getMaxOverlapMinutes($this->db, $unitId),
        ]);
    }

    /**
     * Accept Appointment Request:
     * - Validates schedule availability via server-side validator.
     * - Automatic employee allocation (Lead Vet / Vet Nurse according to medical services).
     * - Sets appointment status to 'Confirmed'.
     * - Seeds performed_service rows.
     */
    public function acceptRequest(int $id): void
    {
        [$uid, $empId, $unitId] = $this->checkVetReceptionist();
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
            $this->redirect('/vetclinic/receptionist/appointments?tab=requests');
        }

        $hydrated = $this->hydrateAppointmentDetails($appt);
        $targetDate = $appt['preferred_date'];
        $targetTime = $appt['preferred_time'] ?: '09:00:00';

        // Check availability and auto-qualify clinical employee
        $assignedEmpId = $this->findQualifiedClinicianForServices($unitId, $hydrated['service_ids'] ?? [], $targetDate, $targetTime, (int)$hydrated['duration_minutes'], $id);

        $confirmedId = (int)$this->db->query("SELECT appointment_status_id FROM appointment_status WHERE status_name = 'Confirmed'")->fetchColumn();

        $beginDt = date('Y-m-d H:i:s', strtotime("$targetDate $targetTime"));
        $endDt = date('Y-m-d H:i:s', strtotime("$targetDate $targetTime + {$hydrated['duration_minutes']} minutes"));

        $this->db->beginTransaction();
        try {
            // Concurrency-safe overlap validation with business unit lock (FOR UPDATE)
            $valResult = AppointmentScheduleValidator::validateAppointment(
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
                $this->redirect('/vetclinic/receptionist/appointments?tab=requests');
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

            Audit::log('vetclinic.appointment.accept', "Accepted Veterinary Clinic appointment request #$id for $targetDate $targetTime.", $unitId, 'appointment', $id, $uid);
            $this->db->commit();
            $this->flash('success', "Appointment request #$id accepted and confirmed for $targetDate at " . date('g:i A', strtotime($targetTime)) . ".");
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->flash('error', 'Failed to accept appointment request: ' . $e->getMessage());
        }

        $this->redirect('/vetclinic/receptionist/appointments?tab=requests');
    }

    /**
     * Propose Alternative Start Time:
     * - Flow: PENDING → Alternative Time Proposed → Awaiting Customer Response
     * - Preserves customer's original requested date and time.
     * - Stores proposed alternative date and time in notes metadata.
     * - Does NOT reserve the proposed time merely because it was proposed.
     * - Status becomes 'Awaiting Customer Response'.
     */
    public function proposeSchedule(int $id): void
    {
        [$uid, , $unitId] = $this->checkVetReceptionist();
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
            $this->redirect('/vetclinic/receptionist/appointments?tab=requests');
        }

        $newDate = trim((string)$this->post('proposed_date'));
        $newTime = trim((string)$this->post('proposed_time'));
        $proposalNote = trim((string)$this->post('proposal_note'));

        if ($newDate === '' || $newTime === '') {
            $this->flash('error', 'Both proposed date and time are required.');
            $this->redirect('/vetclinic/receptionist/appointments?tab=requests');
            return;
        }

        $hydrated = $this->hydrateAppointmentDetails($appt);
        $durationMinutes = (int)($hydrated['duration_minutes'] ?: 30);

        // Validate that proposed alternative schedule does not violate overlap rules
        $valResult = AppointmentScheduleValidator::validateAppointment(
            $this->db,
            $unitId,
            $newDate,
            $newTime,
            $durationMinutes,
            $id
        );

        if (!$valResult['valid']) {
            $this->flash('error', "Cannot propose this time: " . $valResult['error']);
            $this->redirect('/vetclinic/receptionist/appointments?tab=requests');
            return;
        }

        $awaitingStatusId = (int)$this->db->query("SELECT appointment_status_id FROM appointment_status WHERE status_name = 'Awaiting Customer Response'")->fetchColumn();

        $existingNotes = (string)($appt['notes'] ?? '');
        $noteAppend = "[Proposed Alternative Schedule by Receptionist: " . date('M j, Y g:i A') . " -> Proposed Date: $newDate, Proposed Time: $newTime. Note: " . ($proposalNote ?: 'Alternative schedule suggested') . "]";
        $updatedNotes = $existingNotes !== '' ? $existingNotes . "\n" . $noteAppend : $noteAppend;

        // Preserve original customer requested date and time in appointment table
        $this->db->prepare(
            "UPDATE appointment
             SET appointment_status_id = ?, notes = ?, updated_at = NOW()
             WHERE appointment_id = ?"
        )->execute([$awaitingStatusId, $updatedNotes, $id]);

        Audit::log('vetclinic.appointment.propose', "Proposed new schedule for Veterinary Clinic appointment #$id: $newDate at $newTime.", $unitId, 'appointment', $id, $uid);
        $this->flash('success', "Proposed alternative schedule for request #$id recorded. Booking remains pending awaiting customer response.");
        $this->redirect('/vetclinic/receptionist/appointments?tab=requests');
    }

    /**
     * Reject/Decline Appointment Request:
     */
    public function rejectRequest(int $id): void
    {
        [$uid, , $unitId] = $this->checkVetReceptionist();
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
            $this->redirect('/vetclinic/receptionist/appointments?tab=requests');
        }

        $reason = trim((string)$this->post('reason'));
        $rejectedStatusId = (int)$this->db->query("SELECT appointment_status_id FROM appointment_status WHERE status_name = 'Rejected'")->fetchColumn();

        $existingNotes = (string)($appt['notes'] ?? '');
        $noteAppend = "[Declined by Receptionist: " . date('M j, Y g:i A') . ". Reason: " . ($reason ?: 'Clinical scheduling unavailability') . "]";
        $updatedNotes = $existingNotes !== '' ? $existingNotes . "\n" . $noteAppend : $noteAppend;

        $this->db->prepare(
            "UPDATE appointment
             SET appointment_status_id = ?, notes = ?, updated_at = NOW()
             WHERE appointment_id = ?"
        )->execute([$rejectedStatusId, $updatedNotes, $id]);

        Audit::log('vetclinic.appointment.reject', "Declined Veterinary Clinic appointment request #$id: $reason.", $unitId, 'appointment', $id, $uid);
        $this->flash('info', "Appointment request #$id has been declined.");
        $this->redirect('/vetclinic/receptionist/appointments?tab=requests');
    }

    /**
     * Search Registered Customers via AJAX:
     * Returns list of customers matching suggestion format:
     * `Customer Name #CustomerID`
     */
    public function searchCustomers(): void
    {
        $this->checkVetReceptionist();

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
        $this->checkVetReceptionist();

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
     * Handles 4 parts:
     * - Step 1: Customer Information (Guest vs Registered)
     * - Step 2: Pet Information (Guest temporary info vs Registered pets)
     * - Step 3: Medical Services (Medical catalog only, no grooming)
     * - Step 4: Appointment Date (Operating hours, overlap validation, automatic clinician qualification)
     */
    public function createWalkIn(): void
    {
        [$uid, $empId, $unitId] = $this->checkVetReceptionist();
        CSRF::check();

        $custType = $this->post('customer_type') === 'registered' ? 'registered' : 'guest';
        $guestName = trim((string)$this->post('guest_name'));
        $guestPhone = trim((string)$this->post('guest_phone'));
        $registeredCustId = (int)$this->post('customer_id');

        $apptDate = trim((string)$this->post('appointment_date'));
        $apptTime = trim((string)$this->post('appointment_time'));
        $walkInNotes = trim((string)$this->post('walkin_notes'));

        // Step 1 Validation
        if ($custType === 'guest') {
            if ($guestName === '') {
                $this->flash('error', 'Guest owner name is required.');
                $this->redirect('/vetclinic/receptionist/appointments?tab=calendar');
            }
            $targetCustomerId = $this->getOrCreateGuestPlaceholderCustomer();
            $customerDisplayName = $guestName;
        } else {
            if ($registeredCustId <= 0) {
                $this->flash('error', 'Please select a registered customer from search suggestions.');
                $this->redirect('/vetclinic/receptionist/appointments?tab=calendar');
            }
            $cCheck = $this->db->prepare("SELECT customer_id, first_name, last_name, phone FROM customer WHERE customer_id = ? AND customer_status = 'active'");
            $cCheck->execute([$registeredCustId]);
            $cRow = $cCheck->fetch();
            if (!$cRow) {
                $this->flash('error', 'Selected registered customer was not found.');
                $this->redirect('/vetclinic/receptionist/appointments?tab=calendar');
            }
            $targetCustomerId = $registeredCustId;
            $customerDisplayName = trim($cRow['first_name'] . ' ' . $cRow['last_name']);
        }

        // Step 2 & 3 Validation: Pets and Services
        $rawPets = $_POST['pets'] ?? [];
        if (!is_array($rawPets) || empty($rawPets)) {
            $this->flash('error', 'At least one pet and medical service selection is required.');
            $this->redirect('/vetclinic/receptionist/appointments?tab=calendar');
        }

        $allServicesCatalogStmt = $this->db->query("SELECT service_id, service_name, base_price, estimated_duration_minutes FROM service WHERE service_category = 'medical' AND service_status = 'active'");
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
            $pBreed = trim((string)($pData['breed'] ?? ''));
            $existingPetId = isset($pData['pet_id']) ? (int)$pData['pet_id'] : 0;
            $svcIds = isset($pData['services']) && is_array($pData['services']) ? array_filter(array_map('intval', $pData['services'])) : [];

            if ($custType === 'registered') {
                if ($existingPetId <= 0) {
                    $this->flash('error', "Please select a valid registered pet for pet entry #" . ($idx + 1) . ".");
                    $this->redirect('/vetclinic/receptionist/appointments?tab=calendar');
                }
                $petCheck = $this->db->prepare("SELECT pet_id, pet_name, species, breed FROM pet WHERE pet_id = ? AND customer_id = ? AND pet_status = 'active'");
                $petCheck->execute([$existingPetId, $targetCustomerId]);
                $pRow = $petCheck->fetch();
                if (!$pRow) {
                    $this->flash('error', "Pet entry does not belong to the selected registered customer.");
                    $this->redirect('/vetclinic/receptionist/appointments?tab=calendar');
                }
                $finalPetId = $existingPetId;
                $pName = $pRow['pet_name'];
                $pSpecies = $pRow['species'];
                $pBreed = (string)($pRow['breed'] ?? '');
            } else {
                if ($pName === '') {
                    $this->flash('error', "Please provide a name for guest pet #" . ($idx + 1) . ".");
                    $this->redirect('/vetclinic/receptionist/appointments?tab=calendar');
                }
                $finalPetId = $this->getOrCreateGuestPlaceholderPet($targetCustomerId, $pName, $pSpecies);
            }

            if (empty($svcIds)) {
                $this->flash('error', "Please select at least one medical service for pet: $pName.");
                $this->redirect('/vetclinic/receptionist/appointments?tab=calendar');
            }

            // Prevent duplicate selection of same service for same pet
            $svcIds = array_values(array_unique($svcIds));

            $petDuration = 0;
            foreach ($svcIds as $sid) {
                if (!isset($catalogServices[$sid])) {
                    $this->flash('error', "Selected service is not a valid active Veterinary Clinic medical service.");
                    $this->redirect('/vetclinic/receptionist/appointments?tab=calendar');
                }
                $petDuration += max(15, (int)$catalogServices[$sid]['estimated_duration_minutes']);
                $allUniqueServiceIds[] = $sid;
            }

            $totalAppointmentDuration += $petDuration;
            $processedPets[] = [
                'pet_id' => $finalPetId,
                'name' => $pName,
                'species' => $pSpecies,
                'breed' => $pBreed,
                'services' => $svcIds,
            ];
        }

        $allUniqueServiceIds = array_values(array_unique($allUniqueServiceIds));
        if ($totalAppointmentDuration <= 0) {
            $totalAppointmentDuration = 30;
        }

        // Step 4 Validation: Appointment Date & Time
        $dObj = \DateTime::createFromFormat('Y-m-d', $apptDate);
        if (!$dObj || $dObj->format('Y-m-d') !== $apptDate || $dObj < new \DateTime('today')) {
            $this->flash('error', 'Appointment date must be today or a future date. Past dates are not allowed.');
            $this->redirect('/vetclinic/receptionist/appointments?tab=calendar');
        }

        if ($apptTime === '' || !preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $apptTime)) {
            $this->flash('error', 'Please provide a valid appointment start time.');
            $this->redirect('/vetclinic/receptionist/appointments?tab=calendar');
        }

        $timeFormatted = strlen($apptTime) === 5 ? $apptTime . ':00' : $apptTime;

        // Operating hours & cutoff validation
        $dayOfWeek = (int)$dObj->format('N'); // 1 = Mon ... 7 = Sun
        $ohStmt = $this->db->prepare("SELECT is_open, open_time, close_time FROM operating_hours WHERE business_unit_id = ? AND day_of_week = ?");
        $ohStmt->execute([$unitId, $dayOfWeek]);
        $oh = $ohStmt->fetch();

        if ($oh) {
            if (!$oh['is_open']) {
                $this->flash('error', "Veterinary Clinic is closed on " . $dObj->format('l') . "s.");
                $this->redirect('/vetclinic/receptionist/appointments?tab=calendar');
            }
            if ($oh['open_time'] && $oh['close_time']) {
                if ($timeFormatted < $oh['open_time'] || $timeFormatted >= $oh['close_time']) {
                    $this->flash('error', "Selected time falls outside operating hours (" . date('g:i A', strtotime($oh['open_time'])) . " - " . date('g:i A', strtotime($oh['close_time'])) . ").");
                    $this->redirect('/vetclinic/receptionist/appointments?tab=calendar');
                }
            }
        }

        // Automatic clinical employee availability check
        $assignedClinicianId = $this->findQualifiedClinicianForServices($unitId, $allUniqueServiceIds, $apptDate, $timeFormatted, $totalAppointmentDuration);

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
            'booked_by_role' => 'RECEPTIONIST',
            'receptionist_notes' => $walkInNotes,
            'guest_pets' => $custType === 'guest' ? $processedPets : [],
        ];
        $notesEncoded = json_encode($notesPayload, JSON_UNESCAPED_SLASHES);

        $branchId = Units::mainBranchId($unitId);

        $this->db->beginTransaction();
        try {
            // Authoritative server-side overlap validation with business unit lock (FOR UPDATE)
            $valResult = AppointmentScheduleValidator::validateAppointment(
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
                $this->flash('error', "Scheduling conflict: " . $valResult['error']);
                $this->redirect('/vetclinic/receptionist/appointments?tab=calendar');
                return;
            }

            // Insert into appointment table
            $insAppt = $this->db->prepare(
                "INSERT INTO appointment (business_unit_id, branch_id, customer_id, appointment_status_id, appointment_type, preferred_date, preferred_time, appointment_begin, appointment_end, notes, created_at, updated_at)
                 VALUES (?, ?, ?, ?, 'medical', ?, ?, ?, ?, ?, NOW(), NOW())"
            );
            $insAppt->execute([
                $unitId,
                $branchId,
                $targetCustomerId,
                $confirmedStatusId,
                $apptDate,
                $timeFormatted,
                $beginDt,
                $endDt,
                $notesEncoded,
            ]);
            $newApptId = (int)$this->db->lastInsertId();

            // Link pets and services
            foreach ($processedPets as $p) {
                $insAp = $this->db->prepare("INSERT INTO appointment_pet (appointment_id, pet_id) VALUES (?, ?)");
                $insAp->execute([$newApptId, $p['pet_id']]);
                $apptPetId = (int)$this->db->lastInsertId();

                foreach ($p['services'] as $sid) {
                    $svcPrice = (float)($catalogServices[$sid]['base_price'] ?? 0);

                    // Insert requested_pet_service
                    $insRps = $this->db->prepare(
                        "INSERT INTO requested_pet_service (appointment_pet_id, service_id, requested_price, request_status)
                         VALUES (?, ?, ?, 'approved')"
                    );
                    $insRps->execute([$apptPetId, $sid, $svcPrice]);
                    $rpsId = (int)$this->db->lastInsertId();

                    // Insert performed_service
                    $insPs = $this->db->prepare(
                        "INSERT INTO performed_service (requested_pet_service_id, appointment_pet_id, service_id, performed_price, quantity, performed_status, performer_employee_id)
                         VALUES (?, ?, ?, ?, 1, 'pending', ?)"
                    );
                    $insPs->execute([$rpsId, $apptPetId, $sid, $svcPrice, $assignedClinicianId]);
                }
            }

            Audit::log('vetclinic.appointment.walkin', "Booked walk-in medical appointment #$newApptId for $customerDisplayName on $apptDate at $timeFormatted.", $unitId, 'appointment', $newApptId, $uid);
            $this->db->commit();
            $this->flash('success', "Walk-in appointment #$newApptId successfully booked and confirmed for $apptDate at " . date('g:i A', strtotime($timeFormatted)) . ".");
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->flash('error', 'Failed to create walk-in appointment: ' . $e->getMessage());
        }

        $this->redirect('/vetclinic/receptionist/appointments?tab=calendar');
    }

    /**
     * 3. BILLING PAGE:
     * - Upcoming / Ongoing Appointments card.
     * - Recently Completed Appointments card.
     * - Add Billing modal.
     */
    public function billing(): void
    {
        [$uid, , $unitId] = $this->checkVetReceptionist();

        // 1. Ongoing / In-Progress Appointments (and Completed awaiting payment)
        $ongoingStmt = $this->db->prepare(
            "SELECT a.appointment_id, a.preferred_date, a.preferred_time, a.appointment_begin, a.appointment_end,
                    a.notes, st.status_name,
                    c.customer_id, c.first_name, c.last_name, c.phone,
                    si.invoice_id, si.invoice_status, si.total_amount AS invoice_total
             FROM appointment a
             JOIN appointment_status st ON st.appointment_status_id = a.appointment_status_id
             JOIN customer c ON c.customer_id = a.customer_id
             LEFT JOIN service_invoice si ON si.appointment_id = a.appointment_id
             WHERE a.business_unit_id = ?
               AND (
                   st.status_name = 'In Progress'
                   OR (st.status_name = 'Completed' AND (si.invoice_status IS NULL OR si.invoice_status != 'paid'))
                   OR (st.status_name = 'Confirmed' AND a.preferred_date = CURDATE())
               )
             ORDER BY a.preferred_date DESC, a.appointment_id DESC"
        );
        $ongoingStmt->execute([$unitId]);
        $rawOngoing = $ongoingStmt->fetchAll();

        $ongoingAppointments = [];
        foreach ($rawOngoing as $row) {
            $ongoingAppointments[] = $this->hydrateAppointmentDetails($row);
        }

        // 2. Recently Completed & Settled Appointments
        $completedStmt = $this->db->prepare(
            "SELECT a.appointment_id, a.preferred_date, a.preferred_time, a.appointment_begin, a.appointment_end,
                    a.notes, st.status_name,
                    c.customer_id, c.first_name, c.last_name, c.phone,
                    si.invoice_id, si.invoice_status, si.total_amount AS invoice_total,
                    p.payment_method, p.amount AS paid_amount, p.payment_date
             FROM appointment a
             JOIN appointment_status st ON st.appointment_status_id = a.appointment_status_id
             JOIN customer c ON c.customer_id = a.customer_id
             JOIN service_invoice si ON si.appointment_id = a.appointment_id
             LEFT JOIN payment p ON p.invoice_id = si.invoice_id
             WHERE a.business_unit_id = ?
               AND st.status_name = 'Completed'
               AND si.invoice_status = 'paid'
             ORDER BY COALESCE(p.payment_date, si.updated_at, a.updated_at) DESC
             LIMIT 15"
        );
        $completedStmt->execute([$unitId]);
        $rawCompleted = $completedStmt->fetchAll();

        $recentlyCompleted = [];
        foreach ($rawCompleted as $row) {
            $recentlyCompleted[] = $this->hydrateAppointmentDetails($row);
        }

        $this->render('vetclinic_receptionist/billing', [
            'title' => 'Billing · Veterinary Clinic Receptionist',
            'ongoingAppointments' => $ongoingAppointments,
            'recentlyCompleted' => $recentlyCompleted,
        ]);
    }

    /**
     * Search Appointments for Billing via AJAX:
     * - Filters strictly for PENDING_PAYMENT eligibility:
     *   * Medical services finished / completed
     *   * Not currently in progress
     *   * No settled/paid invoice
     *   * Veterinary Clinic unit only
     */
    public function searchBillingAppointments(): void
    {
        $this->checkVetReceptionist();

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

            // Filter for billing eligibility:
            // Medical services must have finished (i.e. appointment is Completed or all performed services completed, NOT In Progress)
            if ($r['status_name'] === 'In Progress') {
                // Check if all performed services are completed
                $psPendingStmt = $this->db->prepare("SELECT COUNT(*) FROM performed_service ps JOIN appointment_pet ap ON ap.appointment_pet_id = ps.appointment_pet_id WHERE ap.appointment_id = ? AND ps.performed_status != 'completed'");
                $psPendingStmt->execute([(int)$r['appointment_id']]);
                if ((int)$psPendingStmt->fetchColumn() > 0) {
                    // Still in progress and medical services not yet finished -> skip
                    continue;
                }
            }

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
        $this->checkVetReceptionist();

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
            $this->json(['ok' => false, 'error' => 'Appointment not found in Veterinary Clinic.'], 404);
        }

        if (!empty($row['invoice_status']) && $row['invoice_status'] === 'paid') {
            $this->json(['ok' => false, 'error' => 'This appointment has already been billed and paid.'], 422);
        }

        // Must not be currently in progress with unfinished services
        if ($row['status_name'] === 'In Progress') {
            $psPendingStmt = $this->db->prepare("SELECT COUNT(*) FROM performed_service ps JOIN appointment_pet ap ON ap.appointment_pet_id = ps.appointment_pet_id WHERE ap.appointment_id = ? AND ps.performed_status != 'completed'");
            $psPendingStmt->execute([$id]);
            if ((int)$psPendingStmt->fetchColumn() > 0) {
                $this->json(['ok' => false, 'error' => 'Cannot bill appointment while medical services are still in progress.'], 422);
            }
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
        [$uid, $empId, $unitId] = $this->checkVetReceptionist();
        CSRF::check();

        $apptId = (int)$this->post('appointment_id');
        $paymentMethod = strtolower(trim((string)$this->post('payment_method')));
        $paymentReceived = (float)$this->post('payment_received');

        $allowedMethods = ['cash', 'gcash', 'maya', 'other'];
        if (!in_array($paymentMethod, $allowedMethods, true)) {
            $this->flash('error', 'Please choose a valid payment method (Cash, GCash, Maya, Other).');
            $this->redirect('/vetclinic/receptionist/billing');
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
            $this->flash('error', 'Appointment not found in Veterinary Clinic.');
            $this->redirect('/vetclinic/receptionist/billing');
        }

        if (!empty($appt['invoice_status']) && $appt['invoice_status'] === 'paid') {
            $this->flash('error', 'This appointment has already been settled and paid. Duplicate billing is prohibited.');
            $this->redirect('/vetclinic/receptionist/billing');
        }

        // Calculate accurate total cost
        $breakdown = $this->calculateBillingBreakdown($apptId);
        $totalCost = (float)$breakdown['total'];

        if ($paymentReceived < $totalCost) {
            $this->flash('error', "Payment Received (₱" . number_format($paymentReceived, 2) . ") cannot be less than Total Cost (₱" . number_format($totalCost, 2) . ").");
            $this->redirect('/vetclinic/receptionist/billing');
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
            $meta = [
                'billed_by_employee_id' => $empId,
                'payment_method' => strtoupper($paymentMethod),
                'total_cost' => $totalCost,
                'payment_received' => $paymentReceived,
                'change' => $change,
                'settled_at' => date('Y-m-d H:i:s'),
            ];
            $appendNote = "\n[Billing Settled: " . date('M j, Y g:i A') . " | Method: " . strtoupper($paymentMethod) . " | Paid: ₱" . number_format($paymentReceived, 2) . " | Total: ₱" . number_format($totalCost, 2) . " | Change: ₱" . number_format($change, 2) . " | Meta: " . json_encode($meta) . "]";
            $newNotes = $rawNotes . $appendNote;

            $this->db->prepare("UPDATE appointment SET notes = ? WHERE appointment_id = ?")->execute([$newNotes, $apptId]);

            Audit::log('vetclinic.billing.settle', "Settled billing for Veterinary Clinic appointment #$apptId. Total: ₱$totalCost, Paid: ₱$paymentReceived, Method: $paymentMethod.", $unitId, 'appointment', $apptId, $uid);
            $this->db->commit();
            $this->flash('success', "Payment of ₱" . number_format($totalCost, 2) . " for appointment #$apptId settled successfully. Change: ₱" . number_format($change, 2) . ".");
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->flash('error', 'Failed to settle billing: ' . $e->getMessage());
        }

        $this->redirect('/vetclinic/receptionist/billing');
    }

    /**
     * 4. RECORDS PAGE:
     * - Only PENDING_PAYMENT and COMPLETED appointments.
     * - Filters: All, Pending Payment, Completed.
     * - Table view with Detail Modal.
     * - STRICT SERVER-SIDE RESTRICTION: No clinical data (diagnosis, SOAP notes, exam findings, prescriptions) exposed!
     */
    public function records(): void
    {
        [$uid, , $unitId] = $this->checkVetReceptionist();

        $filter = strtolower(trim((string)$this->get('status', 'all')));
        $search = trim((string)$this->get('q', ''));

        $sql = "SELECT a.appointment_id, a.preferred_date, a.preferred_time, a.appointment_begin, a.appointment_end,
                       a.created_at, a.notes, st.status_name,
                       c.customer_id, c.first_name, c.last_name, c.phone,
                       si.invoice_id, si.invoice_status, si.total_amount AS invoice_total,
                       p.payment_method, p.amount AS paid_amount
                FROM appointment a
                JOIN appointment_status st ON st.appointment_status_id = a.appointment_status_id
                JOIN customer c ON c.customer_id = a.customer_id
                LEFT JOIN service_invoice si ON si.appointment_id = a.appointment_id
                LEFT JOIN payment p ON p.invoice_id = si.invoice_id
                WHERE a.business_unit_id = ?
                  AND st.status_name IN ('Completed', 'In Progress')";

        $params = [$unitId];

        if ($filter === 'pending_payment' || $filter === 'pending payment') {
            $sql .= " AND (si.invoice_status IS NULL OR si.invoice_status != 'paid')";
        } elseif ($filter === 'completed') {
            $sql .= " AND st.status_name = 'Completed' AND si.invoice_status = 'paid'";
        }

        if ($search !== '') {
            $sql .= " AND (a.appointment_id = ? OR c.first_name LIKE ? OR c.last_name LIKE ? OR CONCAT(c.first_name, ' ', c.last_name) LIKE ? OR a.notes LIKE ?)";
            $numSearch = ctype_digit($search) ? (int)$search : 0;
            $likeSearch = '%' . $search . '%';
            $params[] = $numSearch;
            $params[] = $likeSearch;
            $params[] = $likeSearch;
            $params[] = $likeSearch;
            $params[] = $likeSearch;
        }

        $sql .= " ORDER BY a.preferred_date DESC, a.appointment_id DESC LIMIT 50";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rawRecords = $stmt->fetchAll();

        $records = [];
        foreach ($rawRecords as $row) {
            $hydrated = $this->hydrateAppointmentDetails($row);
            $breakdown = $this->calculateBillingBreakdown((int)$row['appointment_id']);
            $hydrated['total_amount'] = $breakdown['total'];
            $records[] = $hydrated;
        }

        $this->render('vetclinic_receptionist/records', [
            'title' => 'Records · Veterinary Clinic Receptionist',
            'records' => $records,
            'currentFilter' => $filter,
            'searchQuery' => $search,
        ]);
    }

    /**
     * Record Administrative Details via AJAX:
     * Exposes strictly administrative and billing data. Enforces server-side clinical restriction.
     */
    public function recordDetails(int $id): void
    {
        $this->checkVetReceptionist();

        $stmt = $this->db->prepare(
            "SELECT a.appointment_id, a.preferred_date, a.preferred_time, a.appointment_begin, a.appointment_end,
                    a.created_at, a.notes, st.status_name,
                    c.customer_id, c.first_name, c.last_name, c.phone, ua.email,
                    si.invoice_id, si.invoice_status, si.total_amount AS invoice_total,
                    p.payment_method, p.amount AS paid_amount, p.payment_date
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
        $hydrated['total_amount'] = $breakdown['total'];

        // Extract metadata for booked_by and billed_by
        $notesRaw = (string)($row['notes'] ?? '');
        $bookedBy = 'System / Online Booking';
        $billedBy = '—';
        $paymentReceived = (float)($row['paid_amount'] ?? 0);
        $change = 0.0;
        $paymentMethod = (string)($row['payment_method'] ?? '—');

        if (preg_match('/\{"walk_in":true.*?"booked_by_employee_id":(\d+)/', $notesRaw, $bm)) {
            $bEmpId = (int)$bm[1];
            $bEmpStmt = $this->db->prepare("SELECT CONCAT(first_name, ' ', last_name, ' (', position, ')') FROM employee WHERE employee_id = ?");
            $bEmpStmt->execute([$bEmpId]);
            $bName = $bEmpStmt->fetchColumn();
            if ($bName) {
                $bookedBy = $bName;
            }
        }

        if (preg_match('/"billed_by_employee_id":(\d+)/', $notesRaw, $bbm)) {
            $bldEmpId = (int)$bbm[1];
            $bldEmpStmt = $this->db->prepare("SELECT CONCAT(first_name, ' ', last_name, ' (', position, ')') FROM employee WHERE employee_id = ?");
            $bldEmpStmt->execute([$bldEmpId]);
            $bldName = $bldEmpStmt->fetchColumn();
            if ($bldName) {
                $billedBy = $bldName;
            }
        }

        if (preg_match('/"payment_received":([\d.]+)/', $notesRaw, $prm)) {
            $paymentReceived = (float)$prm[1];
        }
        if (preg_match('/"change":([\d.]+)/', $notesRaw, $chm)) {
            $change = (float)$chm[1];
        }
        if (preg_match('/"payment_method":"([^"]+)"/', $notesRaw, $pmm)) {
            $paymentMethod = $pmm[1];
        }

        $hydrated['admin_meta'] = [
            'booked_by' => $bookedBy,
            'billed_by' => $billedBy,
            'booking_date' => date('M j, Y g:i A', strtotime($row['created_at'])),
            'completion_date' => !empty($row['appointment_end']) ? date('M j, Y g:i A', strtotime($row['appointment_end'])) : (!empty($row['payment_date']) ? date('M j, Y g:i A', strtotime($row['payment_date'])) : '—'),
            'payment_method' => strtoupper($paymentMethod),
            'payment_received' => $paymentReceived,
            'change' => $change,
        ];

        $this->json(['ok' => true, 'record' => $hydrated]);
    }

    /**
     * 5. EMPLOYEES PAGE:
     * - Read-only personnel directory for Veterinary Clinic.
     * - Name/email search and work-presence filter.
     */
    public function employees(): void
    {
        [$uid, , $unitId] = $this->checkVetReceptionist();

        $q = trim((string)$this->get('q'));
        $wsFilter = trim((string)$this->get('work_status'));

        $sql = "SELECT e.employee_id, e.first_name, e.last_name, e.position, e.phone, e.employee_status,
                       ua.email, ua.account_status,
                       COALESCE(ews.work_status, 'Free') AS work_status,
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
                WHERE e.business_unit_id = ?
                  AND ua.is_super_admin = 0";

        $params = [$unitId];

        if ($q !== '') {
            $sql .= " AND (e.first_name LIKE ? OR e.last_name LIKE ? OR CONCAT(e.first_name, ' ', e.last_name) LIKE ? OR ua.email LIKE ?)";
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        if ($wsFilter !== '') {
            $sql .= " AND COALESCE(ews.work_status, 'Free') = ?";
            $params[] = $wsFilter;
        }

        $sql .= " ORDER BY CASE WHEN COALESCE(ews.work_status, 'Free') = 'On Duty' THEN 1 ELSE 2 END, e.employee_id ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $employees = $stmt->fetchAll();

        $this->render('vetclinic_receptionist/employees', [
            'title' => 'Employees · Veterinary Clinic Receptionist',
            'unitName' => 'Veterinary Clinic',
            'employees' => $employees,
            'searchQuery' => $q,
            'workStatusFilter' => $wsFilter,
        ]);
    }

    // =========================================================================
    // INTERNAL HELPER METHODS
    // =========================================================================

    /**
     * Fetch Single Current or Upcoming Appointment for TODAY:
     * - 1. Check for most recently started In Progress appointment today.
     * - 2. Otherwise, check for next upcoming Confirmed/Pending appointment scheduled for today.
     * - Only considers today's appointments.
     */
    private function fetchCurrentOrUpcomingAppointment(): ?array
    {
        // 1. In Progress today
        $inProgStmt = $this->db->prepare(
            "SELECT a.appointment_id, a.business_unit_id, a.preferred_date, a.preferred_time,
                    a.appointment_begin, a.appointment_end, a.notes,
                    st.status_name,
                    c.customer_id, c.first_name, c.last_name, c.phone,
                    si.invoice_id, si.invoice_status
             FROM appointment a
             JOIN appointment_status st ON st.appointment_status_id = a.appointment_status_id
             JOIN customer c ON c.customer_id = a.customer_id
             LEFT JOIN service_invoice si ON si.appointment_id = a.appointment_id
             WHERE a.business_unit_id = ?
               AND a.preferred_date = CURDATE()
               AND st.status_name = 'In Progress'
             ORDER BY COALESCE(a.appointment_begin, a.preferred_time) DESC, a.appointment_id DESC
             LIMIT 1"
        );
        $inProgStmt->execute([self::UNIT_ID]);
        $row = $inProgStmt->fetch();

        // 2. Next Upcoming today
        if (!$row) {
            $upcomingStmt = $this->db->prepare(
                "SELECT a.appointment_id, a.business_unit_id, a.preferred_date, a.preferred_time,
                        a.appointment_begin, a.appointment_end, a.notes,
                        st.status_name,
                        c.customer_id, c.first_name, c.last_name, c.phone,
                        si.invoice_id, si.invoice_status
                 FROM appointment a
                 JOIN appointment_status st ON st.appointment_status_id = a.appointment_status_id
                 JOIN customer c ON c.customer_id = a.customer_id
                 LEFT JOIN service_invoice si ON si.appointment_id = a.appointment_id
                 WHERE a.business_unit_id = ?
                   AND a.preferred_date = CURDATE()
                   AND st.status_name IN ('Confirmed', 'Pending', 'Awaiting Customer Response')
                 ORDER BY COALESCE(TIME(a.appointment_begin), a.preferred_time, '09:00:00') ASC, a.appointment_id ASC
                 LIMIT 1"
            );
            $upcomingStmt->execute([self::UNIT_ID]);
            $row = $upcomingStmt->fetch();
        }

        if (!$row) {
            return null;
        }

        return $this->hydrateAppointmentDetails($row);
    }

    /**
     * Fetch Employees Present for Veterinary Clinic:
     * - Order: On Duty first, then priority Lead/Assoc Vet > Vet Nurse > Vet Assistant > Receptionist
     */
    private function fetchEmployeesPresent(int $unitId): array
    {
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
             JOIN user_account ua ON ua.user_account_id = e.user_account_id
             LEFT JOIN employee_work_status ews ON ews.employee_id = e.employee_id
             WHERE e.business_unit_id = ? AND e.employee_status = 'active'
               AND ua.is_super_admin = 0
             ORDER BY CASE WHEN COALESCE(ews.work_status, 'Free') = 'On Duty' THEN 1 ELSE 2 END,
                      FIELD(e.position, 'Lead Veterinarian', 'Lead Vet', 'Associate Veterinarian', 'Associate Vet', 'Veterinary Nurse', 'Vet Nurse', 'Veterinary Assistant', 'Vet Assistant', 'Receptionist', 'Veterinary Receptionist'),
                      e.employee_id ASC"
        );
        $empStmt->execute([$unitId]);
        return $empStmt->fetchAll();
    }

    /**
     * Hydrate appointment details with guest vs registered data, pets, services, and billing status:
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
                $pSpec = trim((string)($gp['species'] ?? 'Dog'));
                $pBreed = trim((string)($gp['breed'] ?? ''));
                $petNames[] = $pName;
                $petDetails[] = [
                    'pet_id' => 0,
                    'name' => $pName,
                    'species' => $pSpec,
                    'breed' => $pBreed,
                    'display' => $pName . ' (' . ($pBreed !== '' ? $pBreed : $pSpec) . ')',
                ];
            }
        } else {
            foreach ($pets as $pt) {
                $pName = (string)$pt['pet_name'];
                $pBreed = trim((string)($pt['breed'] ?? ''));
                $pSpec = (string)($pt['species'] ?? 'Pet');
                $petNames[] = $pName;
                $petDetails[] = [
                    'pet_id' => (int)$pt['pet_id'],
                    'name' => $pName,
                    'species' => $pSpec,
                    'breed' => $pBreed,
                    'display' => $pName . ' (' . ($pBreed !== '' ? $pBreed : $pSpec) . ')',
                ];
            }
        }

        // Query Services (check performed_service first, fallback to requested_pet_service)
        $svcStmt = $this->db->prepare(
            "SELECT s.service_id, s.service_name, s.base_price, s.estimated_duration_minutes,
                    ps.performed_price, ps.performed_status, ps.performer_employee_id,
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
        $serviceRows = $svcStmt->fetchAll();

        $serviceNames = [];
        $serviceIds = [];
        $totalDuration = 0;
        $totalPrice = 0.0;
        $assignedEmployees = [];
        $primaryPerformerId = null;

        foreach ($serviceRows as $sr) {
            $sid = (int)$sr['service_id'];
            $sName = (string)$sr['service_name'];
            if (!in_array($sName, $serviceNames, true)) {
                $serviceNames[] = $sName;
            }
            if (!in_array($sid, $serviceIds, true)) {
                $serviceIds[] = $sid;
                $totalDuration += max(15, (int)($sr['estimated_duration_minutes'] ?? 0));
            }
            $totalPrice += (float)($sr['performed_price'] ?? $sr['base_price']);

            if (!empty($sr['performer_name'])) {
                $assignedEmployees[$sr['performer_name']] = $sr['performer_name'] . ' (' . ($sr['performer_position'] ?: 'Staff') . ')';
            }
            if ($primaryPerformerId === null && !empty($sr['performer_employee_id'])) {
                $primaryPerformerId = (int)$sr['performer_employee_id'];
            }
        }

        if ($totalDuration <= 0) {
            $totalDuration = 30;
        }

        // Time coverage calculation
        $prefDate = (string)($appt['preferred_date'] ?? date('Y-m-d'));
        $prefTime = (string)($appt['preferred_time'] ?? '09:00:00');
        if (!empty($appt['appointment_begin'])) {
            $startTs = strtotime((string)$appt['appointment_begin']);
        } else {
            $startTs = strtotime("$prefDate $prefTime");
        }

        if (!empty($appt['appointment_end'])) {
            $endTs = strtotime((string)$appt['appointment_end']);
        } else {
            $endTs = $startTs + ($totalDuration * 60);
        }

        $timeCoverage = date('g:i A', $startTs) . ' - ' . date('g:i A', $endTs);
        $startMinutes = (int)date('H', $startTs) * 60 + (int)date('i', $startTs);
        $endMinutes = (int)date('H', $endTs) * 60 + (int)date('i', $endTs);

        // Status mapping and badge determination
        $rawStatus = (string)($appt['status_name'] ?? 'Pending');
        $invoiceStatus = (string)($appt['invoice_status'] ?? '');

        $statusBadge = 'badge-secondary';
        $displayStatus = $rawStatus;

        if ($rawStatus === 'Confirmed') {
            $statusBadge = 'badge-success';
        } elseif ($rawStatus === 'In Progress') {
            $statusBadge = 'badge-info';
        } elseif ($rawStatus === 'Completed') {
            if ($invoiceStatus === 'paid') {
                $statusBadge = 'badge-primary';
                $displayStatus = 'Completed';
            } else {
                $statusBadge = 'badge-warning';
                $displayStatus = 'Pending Payment';
            }
        } elseif ($rawStatus === 'Pending') {
            $statusBadge = 'badge-warning';
        } elseif ($rawStatus === 'Awaiting Customer Response') {
            $statusBadge = 'badge-purple';
        } elseif ($rawStatus === 'Rejected' || $rawStatus === 'Cancelled') {
            $statusBadge = 'badge-danger';
        }

        // Check if eligible for PENDING_PAYMENT
        $isPendingPayment = false;
        if (($rawStatus === 'Completed' && $invoiceStatus !== 'paid') || ($rawStatus === 'In Progress' && $invoiceStatus !== 'paid')) {
            $displayStatus = 'Pending Payment';
            $statusBadge = 'badge-warning';
            $isPendingPayment = true;
        }

        // Status color mapping for calendar blocks (matching Business Manager)
        if ($rawStatus === 'Confirmed') {
            $colorClass = 'cal-status-green';
            $statusColorName = 'green';
            $badgeClass = 'ok';
        } elseif (in_array($rawStatus, ['Cancelled', 'Rejected', 'No-Show'], true)) {
            $colorClass = 'cal-status-red';
            $statusColorName = 'red';
            $badgeClass = 'err';
        } elseif (in_array($rawStatus, ['In Progress', 'Pending', 'Awaiting Customer Response'], true) || $isPendingPayment) {
            $colorClass = 'cal-status-yellow';
            $statusColorName = 'yellow';
            $badgeClass = 'warn';
        } else {
            // Completed -> Transparent
            $colorClass = 'cal-status-transparent';
            $statusColorName = 'transparent';
            $badgeClass = $rawStatus === 'Completed' ? 'ok' : '';
        }

        $appt['is_guest'] = $isGuest;
        $appt['customer_name'] = $customerName;
        $appt['customer_display_name'] = $customerName;
        $appt['customer_id_display'] = $customerIdDisplay;
        $appt['customer_phone'] = $customerPhone;
        $appt['pets'] = $petDetails;
        $appt['pets_summary'] = $petNames ? implode(', ', $petNames) : 'No pet recorded';
        $appt['pet_summary'] = $appt['pets_summary'];
        $appt['service_list'] = $serviceNames;
        $appt['service_ids'] = $serviceIds;
        $appt['services_summary'] = $serviceNames ? implode(', ', $serviceNames) : 'General Medical Examination';
        $appt['duration_minutes'] = $totalDuration;
        $appt['time_coverage'] = $timeCoverage;
        $appt['start_time_formatted'] = date('g:i A', $startTs);
        $appt['end_time_formatted'] = date('g:i A', $endTs);
        $appt['start_minutes'] = $startMinutes;
        $appt['end_minutes'] = $endMinutes;
        $appt['display_status'] = $displayStatus;
        $appt['status_badge_class'] = $statusBadge;
        $appt['color_class'] = $colorClass;
        $appt['status_color_name'] = $statusColorName;
        $appt['badge_class'] = $badgeClass;
        $appt['assigned_employee'] = $assignedEmployees ? implode(', ', $assignedEmployees) : 'Automatic Allocation';
        $appt['assigned_employee_display'] = $appt['assigned_employee'];
        $appt['performer_employee_id'] = $primaryPerformerId;
        $appt['total_service_cost'] = $totalPrice;
        $appt['is_pending_payment'] = $isPendingPayment;
        $appt['formatted_date'] = date('M j, Y', strtotime((string)$appt['preferred_date']));

        return $appt;
    }

    /**
     * Calculate Itemized Billing Breakdown for Veterinary Clinic:
     * - Individual medical services and prices (no service packages).
     * - Quantities reflect number of pets receiving the medical service.
     */
    private function calculateBillingBreakdown(int $appointmentId): array
    {
        $stmt = $this->db->prepare(
            "SELECT s.service_id, s.service_name, s.base_price,
                    COUNT(DISTINCT ap.appointment_pet_id) AS pet_count
             FROM appointment_pet ap
             LEFT JOIN performed_service ps ON ps.appointment_pet_id = ap.appointment_pet_id
             LEFT JOIN requested_pet_service rps ON rps.appointment_pet_id = ap.appointment_pet_id
             JOIN service s ON s.service_id = COALESCE(ps.service_id, rps.service_id)
             WHERE ap.appointment_id = ?
             GROUP BY s.service_id, s.service_name, s.base_price
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

            $items[] = [
                'service_id' => (int)$r['service_id'],
                'service_name' => $r['service_name'],
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
     * Automatic Clinician Qualification & Availability Check for Veterinary Clinic:
     * - Respects clinical roles and positions:
     *   * Lead Vet / Associate Vet (Doctor of Veterinary Medicine): qualified for all medical services.
     *   * Veterinary Nurse: qualified for nurse-level visits (vaccinations, microchipping, routine panel assistance).
     *   * Strictly excludes Business Manager, Inventory Manager, System Administrator, and Receptionist.
     */
    private function findQualifiedClinicianForServices(int $unitId, array $serviceIds, string $date, string $time, int $durationMinutes, int $excludeApptId = 0): ?int
    {
        $servicesStmt = $this->db->prepare("SELECT service_id, service_name FROM service WHERE service_id IN (" . implode(',', array_fill(0, count($serviceIds) ?: 1, '?')) . ")");
        $servicesStmt->execute($serviceIds ?: [0]);
        $services = $servicesStmt->fetchAll();

        $needsDoctor = false;
        foreach ($services as $s) {
            $name = (string)$s['service_name'];
            if (stripos($name, 'Exam') !== false || stripos($name, 'Dental') !== false || stripos($name, 'Panel') !== false) {
                $needsDoctor = true;
                break;
            }
        }

        // Active clinicians in unit 2
        $cliniciansStmt = $this->db->prepare(
            "SELECT e.employee_id, e.first_name, e.last_name, e.position,
                    GROUP_CONCAT(r.role_name) AS roles
             FROM employee e
             JOIN user_account ua ON ua.user_account_id = e.user_account_id
             LEFT JOIN account_role ar ON ar.user_account_id = ua.user_account_id AND ar.business_unit_id = ?
             LEFT JOIN role r ON r.role_id = ar.role_id
             WHERE e.business_unit_id = ? AND e.employee_status = 'active'
               AND ua.account_status = 'active' AND ua.is_super_admin = 0
               AND e.position IN ('Lead Veterinarian', 'Lead Vet', 'Associate Veterinarian', 'Associate Vet', 'Veterinary Nurse', 'Vet Nurse', 'Veterinary Assistant', 'Vet Assistant')
             GROUP BY e.employee_id, e.first_name, e.last_name, e.position
             ORDER BY FIELD(e.position, 'Lead Veterinarian', 'Lead Vet', 'Associate Veterinarian', 'Associate Vet', 'Veterinary Nurse', 'Vet Nurse', 'Veterinary Assistant', 'Vet Assistant'), e.employee_id ASC"
        );
        $cliniciansStmt->execute([$unitId, $unitId]);
        $candidates = $cliniciansStmt->fetchAll();

        if (empty($candidates)) {
            return null;
        }

        $newStart = strtotime("$date $time");
        $newEnd = $newStart + ($durationMinutes * 60);

        foreach ($candidates as $cand) {
            $pos = (string)$cand['position'];

            // Qualification Check: if service needs a doctor, Nurse/Assistant cannot perform primary
            if ($needsDoctor && (stripos($pos, 'Vet') !== false && stripos($pos, 'Nurse') !== false)) {
                continue;
            }
            if ($needsDoctor && stripos($pos, 'Assistant') !== false) {
                continue;
            }

            // Check if candidate already occupied during overlapping appointment interval on that date
            $empId = (int)$cand['employee_id'];
            $conflictStmt = $this->db->prepare(
                "SELECT a.appointment_id, a.preferred_date, a.preferred_time, a.appointment_begin, a.appointment_end
                 FROM performed_service ps
                 JOIN appointment_pet ap ON ap.appointment_pet_id = ps.appointment_pet_id
                 JOIN appointment a ON a.appointment_id = ap.appointment_id
                 JOIN appointment_status st ON st.appointment_status_id = a.appointment_status_id
                 WHERE ps.performer_employee_id = ?
                   AND a.preferred_date = ?
                   AND a.appointment_id != ?
                   AND st.status_name IN ('Confirmed', 'In Progress')"
            );
            $conflictStmt->execute([$empId, $date, $excludeApptId]);
            $existing = $conflictStmt->fetchAll();

            $hasConflict = false;
            foreach ($existing as $ex) {
                $eStart = !empty($ex['appointment_begin']) ? strtotime((string)$ex['appointment_begin']) : strtotime($ex['preferred_date'] . ' ' . ($ex['preferred_time'] ?: '09:00:00'));
                $eEnd = !empty($ex['appointment_end']) ? strtotime((string)$ex['appointment_end']) : ($eStart + 1800);

                if ($newStart < $eEnd && $eStart < $newEnd) {
                    $hasConflict = true;
                    break;
                }
            }

            if (!$hasConflict) {
                return $empId;
            }
        }

        // Fallback to highest ranked clinical employee if all occupied
        foreach ($candidates as $cand) {
            if ($needsDoctor && (stripos($cand['position'], 'Lead') !== false || stripos($cand['position'], 'Associate') !== false || stripos($cand['position'], 'Veterinarian') !== false)) {
                return (int)$cand['employee_id'];
            }
        }

        return (int)$candidates[0]['employee_id'];
    }

    /**
     * Build 7-day chronological week context matching Business Manager calendar:
     */
    private function buildCalendarWeekContext(?string $startParam, ?string $monthParam, ?int $weekOffset): array
    {
        $todayDt = new \DateTime('today');

        if ($startParam && preg_match('/^\d{4}-\d{2}-\d{2}$/', $startParam)) {
            $startDate = new \DateTime($startParam);
        } elseif ($monthParam && preg_match('/^\d{4}-\d{2}$/', $monthParam)) {
            $startDate = new \DateTime($monthParam . '-01');
        } elseif ($weekOffset !== null && $weekOffset !== 0) {
            $startDate = clone $todayDt;
            $startDate->modify(($weekOffset * 7) . ' days');
        } else {
            $startDate = clone $todayDt;
        }

        $startDateStr = $startDate->format('Y-m-d');
        $endDate = clone $startDate;
        $endDate->modify('+6 days');
        $endDateStr = $endDate->format('Y-m-d');

        $prevWeekStart = (clone $startDate)->modify('-7 days')->format('Y-m-d');
        $nextWeekStart = (clone $startDate)->modify('+7 days')->format('Y-m-d');
        $todayWeekStart = $todayDt->format('Y-m-d');

        $isCurrentWeek = ($todayDt >= $startDate && $todayDt <= $endDate);
        $monthPickerValue = $startDate->format('Y-m');

        $weekDays = [];
        $dateIndexMap = [];
        $curDay = clone $startDate;
        for ($i = 0; $i < 7; $i++) {
            $dStr = $curDay->format('Y-m-d');
            $weekDays[] = [
                'date' => $dStr,
                'day_short' => $curDay->format('D'),
                'day_label' => $curDay->format('M j'),
                'day_name' => $curDay->format('D'),
                'day_num' => (int)$curDay->format('j'),
                'month_short' => $curDay->format('M'),
                'is_today' => ($dStr === $todayDt->format('Y-m-d')),
                'is_past' => ($dStr < $todayDt->format('Y-m-d')),
            ];
            $dateIndexMap[$dStr] = $i;
            $curDay->modify('+1 day');
        }

        $rangeHeading = $weekDays[0]['month_short'] . ' ' . $weekDays[0]['day_num'] . ' – ' . $weekDays[6]['month_short'] . ' ' . $weekDays[6]['day_num'] . ', ' . $startDate->format('Y');

        return [
            'startDate' => $startDate,
            'endDate' => $endDate,
            'startDateStr' => $startDateStr,
            'endDateStr' => $endDateStr,
            'prevWeekStart' => $prevWeekStart,
            'nextWeekStart' => $nextWeekStart,
            'todayWeekStart' => $todayWeekStart,
            'isCurrentWeek' => $isCurrentWeek,
            'monthPickerValue' => $monthPickerValue,
            'rangeHeading' => $rangeHeading,
            'weekDays' => $weekDays,
            'dateIndexMap' => $dateIndexMap,
        ];
    }

    /**
     * Compute pixel coordinates and overlapping columns for 7-day schedule grid (Matching BM & Grooming Receptionist):
     */
    private function layoutAppointmentsForWeek(array $hydratedAppts, array $weekDays, array $dateIndexMap): array
    {
        $gridStartHour = 8;
        $gridEndHour = 18;

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
        $id = $pStmt->fetchColumn();
        if ($id) {
            return (int)$id;
        }

        $this->db->prepare("INSERT INTO pet (customer_id, pet_name, species, pet_status) VALUES (?, ?, ?, 'active')")->execute([$guestCustId, $petName, $species]);
        return (int)$this->db->lastInsertId();
    }
}
