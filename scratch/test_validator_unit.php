<?php
require 'app/bootstrap.php';
$db = \App\Core\Database::pdo();

echo "Testing AppointmentScheduleValidator...\n";
$groomingMax = \App\Models\AppointmentScheduleValidator::getMaxOverlapMinutes($db, 3);
$vetMax = \App\Models\AppointmentScheduleValidator::getMaxOverlapMinutes($db, 2);
echo "Grooming Max Overlap: {$groomingMax} minutes\n";
echo "Vet Clinic Max Overlap: {$vetMax} minutes\n";

// Test against existing appointment #7 on 2026-11-10 (10:30 - 11:15)
// Case 1: Same start time (10:30)
$res1 = \App\Models\AppointmentScheduleValidator::validateAppointment($db, 3, '2026-11-10', '10:30:00', 45);
echo "Case 1 (10:30 AM): " . ($res1['valid'] ? 'ALLOWED' : 'REJECTED: ' . $res1['error']) . "\n";

// Case 2: 10:45 AM (15m overlap with #7)
$res2 = \App\Models\AppointmentScheduleValidator::validateAppointment($db, 3, '2026-11-10', '10:45:00', 15);
echo "Case 2 (10:45 AM, 15m dur): " . ($res2['valid'] ? 'ALLOWED' : 'REJECTED: ' . $res2['error']) . "\n";

// Case 3: 10:40 AM (35m overlap with #7)
$res3 = \App\Models\AppointmentScheduleValidator::validateAppointment($db, 3, '2026-11-10', '10:40:00', 45);
echo "Case 3 (10:40 AM, 45m dur): " . ($res3['valid'] ? 'ALLOWED' : 'REJECTED: ' . $res3['error']) . "\n";

