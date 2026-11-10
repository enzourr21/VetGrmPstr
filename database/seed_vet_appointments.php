<?php
declare(strict_types=1);

/**
 * Seeds realistic, valid Veterinary Clinic sample appointments for development & testing.
 * Enforces AppointmentScheduleValidator so no conflicting appointments can ever be seeded.
 * Idempotent: safe to run repeatedly without creating duplicates.
 */
if (!isset($db)) {
    if (isset($pdo)) {
        $db = $pdo;
    } else {
        require_once dirname(__DIR__) . '/config/database.php';
        $db = $pdo;
    }
}

if (!class_exists('App\Models\AppointmentScheduleValidator')) {
    require_once dirname(__DIR__) . '/app/Models/AppointmentScheduleValidator.php';
}

use App\Models\AppointmentScheduleValidator;

echo "Seeding Veterinary Clinic medical sample appointments...\n";

// Target Unit 2 (Veterinary Clinic) and Branch 2
$unitId = 2;
$branchId = 2;

// Query Status IDs
$statusMap = [];
$statuses = $db->query("SELECT appointment_status_id, status_name FROM appointment_status")->fetchAll(PDO::FETCH_ASSOC);
foreach ($statuses as $st) {
    $statusMap[$st['status_name']] = (int)$st['appointment_status_id'];
}

// Clinicians
// Dr. Elena Vance: employee 2 (Lead Veterinarian)
// Hannah Kim: employee 6 (Veterinary Nurse)

// Pets & Owners
$petMap = [];
$pets = $db->query("SELECT p.pet_id, p.pet_name, p.customer_id FROM pet p WHERE p.pet_name IN ('Barnaby', 'Whiskers', 'Bella', 'Milo', 'Pip', 'Daisy', 'Rocky')")->fetchAll(PDO::FETCH_ASSOC);
foreach ($pets as $p) {
    $petMap[$p['pet_name']] = [
        'pet_id' => (int)$p['pet_id'],
        'customer_id' => (int)$p['customer_id'],
    ];
}

// Medical Services
// 8: Comprehensive Wellness Exam (30m, ₱48.00)
// 9: Core Vaccination Visit (20m, ₱38.00)
// 10: Diagnostic Blood & Wellness Panel (35m, ₱85.00)
// 11: Veterinary Dental Assessment & Scaling (60m, ₱140.00)
// 12: Microchip Identification Implant (15m, ₱32.00)

$sampleVetAppts = [
    // Monday 2026-11-09
    [
        'tag' => '[SAMPLE-VET-1]',
        'date' => '2026-11-09',
        'time' => '09:00:00',
        'pet_name' => 'Barnaby',
        'service_id' => 8,
        'duration' => 30,
        'price' => 48.00,
        'clinician_id' => 2,
        'status' => 'Completed',
        'notes' => '[SAMPLE-VET-1] Routine wellness physical examination. Patient vital signs normal.',
    ],
    [
        'tag' => '[SAMPLE-VET-2]',
        'date' => '2026-11-09',
        'time' => '10:00:00',
        'pet_name' => 'Bella',
        'service_id' => 11,
        'duration' => 60,
        'price' => 140.00,
        'clinician_id' => 2,
        'status' => 'Completed',
        'notes' => '[SAMPLE-VET-2] Ultrasonic dental scaling and oral hygiene polish under light sedation.',
    ],
    [
        'tag' => '[SAMPLE-VET-3]',
        'date' => '2026-11-09',
        'time' => '10:50:00',
        'pet_name' => 'Milo',
        'service_id' => 9,
        'duration' => 20,
        'price' => 38.00,
        'clinician_id' => 6,
        'status' => 'Completed',
        'notes' => '[SAMPLE-VET-3] Annual core rabies and FVRCP booster vaccination visit.',
    ],

    // Tuesday 2026-11-10 (Today)
    [
        'tag' => '[SAMPLE-VET-4]',
        'date' => '2026-11-10',
        'time' => '09:30:00',
        'pet_name' => 'Daisy',
        'service_id' => 8,
        'duration' => 30,
        'price' => 48.00,
        'clinician_id' => 2,
        'status' => 'Completed',
        'notes' => '[SAMPLE-VET-4] Comprehensive physical examination and wellness check.',
    ],
    [
        'tag' => '[SAMPLE-VET-5]',
        'date' => '2026-11-10',
        'time' => '10:45:00',
        'pet_name' => 'Rocky',
        'service_id' => 10,
        'duration' => 35,
        'price' => 85.00,
        'clinician_id' => 2,
        'status' => 'Completed',
        'notes' => '[SAMPLE-VET-5] Diagnostic blood chemistry panel and complete blood count.',
    ],
    [
        'tag' => '[SAMPLE-VET-6]',
        'date' => '2026-11-10',
        'time' => '11:10:00',
        'pet_name' => 'Whiskers',
        'service_id' => 9,
        'duration' => 20,
        'price' => 38.00,
        'clinician_id' => 6,
        'status' => 'In Progress',
        'notes' => '[SAMPLE-VET-6] Feline core vaccination booster and temperature check.',
    ],
    [
        'tag' => '[SAMPLE-VET-7]',
        'date' => '2026-11-10',
        'time' => '14:00:00',
        'pet_name' => 'Bella',
        'service_id' => 11,
        'duration' => 60,
        'price' => 140.00,
        'clinician_id' => 2,
        'status' => 'Confirmed',
        'notes' => '[SAMPLE-VET-7] Scheduled comprehensive dental check and prophylaxis.',
    ],
    [
        'tag' => '[SAMPLE-VET-8]',
        'date' => '2026-11-10',
        'time' => '14:50:00',
        'pet_name' => 'Pip',
        'service_id' => 12,
        'duration' => 15,
        'price' => 32.00,
        'clinician_id' => 6,
        'status' => 'Confirmed',
        'notes' => '[SAMPLE-VET-8] Microchip implantation and registration confirmation.',
    ],

    // Wednesday 2026-11-11
    [
        'tag' => '[SAMPLE-VET-9]',
        'date' => '2026-11-11',
        'time' => '09:00:00',
        'pet_name' => 'Barnaby',
        'service_id' => 8,
        'duration' => 30,
        'price' => 48.00,
        'clinician_id' => 2,
        'status' => 'Confirmed',
        'notes' => '[SAMPLE-VET-9] Follow-up wellness exam and musculoskeletal assessment.',
    ],
    [
        'tag' => '[SAMPLE-VET-10]',
        'date' => '2026-11-11',
        'time' => '10:30:00',
        'pet_name' => 'Rocky',
        'service_id' => 10,
        'duration' => 35,
        'price' => 85.00,
        'clinician_id' => 2,
        'status' => 'Confirmed',
        'notes' => '[SAMPLE-VET-10] Routine senior blood screening profile.',
    ],
    [
        'tag' => '[SAMPLE-VET-11]',
        'date' => '2026-11-11',
        'time' => '11:00:00',
        'pet_name' => 'Whiskers',
        'service_id' => 9,
        'duration' => 20,
        'price' => 38.00,
        'clinician_id' => 6,
        'status' => 'Confirmed',
        'notes' => '[SAMPLE-VET-11] Follow-up vaccine check and booster.',
    ],

    // Thursday 2026-11-12
    [
        'tag' => '[SAMPLE-VET-12]',
        'date' => '2026-11-12',
        'time' => '10:00:00',
        'pet_name' => 'Milo',
        'service_id' => 11,
        'duration' => 60,
        'price' => 140.00,
        'clinician_id' => 2,
        'status' => 'Confirmed',
        'notes' => '[SAMPLE-VET-12] Complete feline dental examination and tartar cleaning.',
    ],
    [
        'tag' => '[SAMPLE-VET-13]',
        'date' => '2026-11-12',
        'time' => '10:45:00',
        'pet_name' => 'Daisy',
        'service_id' => 8,
        'duration' => 30,
        'price' => 48.00,
        'clinician_id' => 2,
        'status' => 'Confirmed',
        'notes' => '[SAMPLE-VET-13] Wellness checkup and nutritional consultation.',
    ],

    // Friday 2026-11-13
    [
        'tag' => '[SAMPLE-VET-14]',
        'date' => '2026-11-13',
        'time' => '09:30:00',
        'pet_name' => 'Bella',
        'service_id' => 10,
        'duration' => 35,
        'price' => 85.00,
        'clinician_id' => 2,
        'status' => 'Confirmed',
        'notes' => '[SAMPLE-VET-14] Pre-travel diagnostic blood test panel.',
    ],
    [
        'tag' => '[SAMPLE-VET-15]',
        'date' => '2026-11-13',
        'time' => '10:00:00',
        'pet_name' => 'Rocky',
        'service_id' => 9,
        'duration' => 20,
        'price' => 38.00,
        'clinician_id' => 6,
        'status' => 'Confirmed',
        'notes' => '[SAMPLE-VET-15] Canine DHPP and leptospirosis vaccination.',
    ],
    [
        'tag' => '[SAMPLE-VET-16]',
        'date' => '2026-11-13',
        'time' => '11:00:00',
        'pet_name' => 'Barnaby',
        'service_id' => 8,
        'duration' => 30,
        'price' => 48.00,
        'clinician_id' => 2,
        'status' => 'Confirmed',
        'notes' => '[SAMPLE-VET-16] Quarterly clinical health assessment.',
    ],
];

$insertedCount = 0;
$skippedCount = 0;

foreach ($sampleVetAppts as $sa) {
    // Check if appointment already exists via unique sample tag
    $chk = $db->prepare("SELECT appointment_id FROM appointment WHERE notes LIKE ? LIMIT 1");
    $chk->execute(['%' . $sa['tag'] . '%']);
    if ($chk->fetchColumn()) {
        $skippedCount++;
        continue;
    }

    if (!isset($petMap[$sa['pet_name']])) {
        echo "[WARN] Pet '{$sa['pet_name']}' not found in database; skipping.\n";
        continue;
    }

    $petInfo = $petMap[$sa['pet_name']];
    $statusId = $statusMap[$sa['status']] ?? 3;

    // Validate using AppointmentScheduleValidator before saving!
    $val = AppointmentScheduleValidator::validateAppointment(
        $db,
        $unitId,
        $sa['date'],
        $sa['time'],
        $sa['duration'],
        0
    );

    if (!$val['valid']) {
        echo "[ERROR] Seed appointment {$sa['tag']} failed validation: {$val['error']}\n";
        continue;
    }

    $beginDt = date('Y-m-d H:i:s', strtotime("{$sa['date']} {$sa['time']}"));
    $endDt = date('Y-m-d H:i:s', strtotime("{$sa['date']} {$sa['time']} + {$sa['duration']} minutes"));

    $db->beginTransaction();
    try {
        $ins = $db->prepare(
            "INSERT INTO appointment (business_unit_id, branch_id, customer_id, appointment_status_id, appointment_type, preferred_date, preferred_time, appointment_begin, appointment_end, notes)
             VALUES (?, ?, ?, ?, 'medical', ?, ?, ?, ?, ?)"
        );
        $ins->execute([
            $unitId,
            $branchId,
            $petInfo['customer_id'],
            $statusId,
            $sa['date'],
            $sa['time'],
            $beginDt,
            $endDt,
            $sa['notes'],
        ]);
        $apptId = (int)$db->lastInsertId();

        $insPet = $db->prepare("INSERT INTO appointment_pet (appointment_id, pet_id) VALUES (?, ?)");
        $insPet->execute([$apptId, $petInfo['pet_id']]);
        $apId = (int)$db->lastInsertId();

        $insRps = $db->prepare(
            "INSERT INTO requested_pet_service (appointment_pet_id, service_id, requested_price, request_status)
             VALUES (?, ?, ?, 'approved')"
        );
        $insRps->execute([$apId, $sa['service_id'], $sa['price']]);
        $rpsId = (int)$db->lastInsertId();

        $performedStatus = ($sa['status'] === 'Completed') ? 'completed' : 'pending';
        $insPs = $db->prepare(
            "INSERT INTO performed_service (requested_pet_service_id, appointment_pet_id, service_id, performed_price, quantity, performed_status, performer_employee_id, notes)
             VALUES (?, ?, ?, ?, 1, ?, ?, ?)"
        );
        $insPs->execute([
            $rpsId,
            $apId,
            $sa['service_id'],
            $sa['price'],
            $performedStatus,
            $sa['clinician_id'],
            "Clinical service for appointment #{$apptId}",
        ]);

        $db->commit();
        $insertedCount++;
        echo "[INSERTED] {$sa['tag']} {$sa['date']} {$sa['time']} (#{$apptId}) - {$sa['pet_name']}\n";
    } catch (\Throwable $e) {
        $db->rollBack();
        echo "[FAIL] Could not insert {$sa['tag']}: " . $e->getMessage() . "\n";
    }
}

echo "Veterinary Clinic appointment seeding completed: $insertedCount inserted, $skippedCount already existed.\n";
