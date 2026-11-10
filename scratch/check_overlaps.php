<?php
require 'app/bootstrap.php';
$pdo = \App\Core\Database::pdo();
$appts = $pdo->query("SELECT appointment_id, preferred_time, appointment_begin, appointment_end FROM appointment WHERE business_unit_id = 3 AND preferred_date = '2026-11-10' ORDER BY preferred_time ASC, appointment_id ASC")->fetchAll(PDO::FETCH_ASSOC);

echo "Appointments on 2026-11-10:\n";
foreach ($appts as $a) {
    echo "  #{$a['appointment_id']}: {$a['preferred_time']} ({$a['appointment_begin']} to {$a['appointment_end']})\n";
}

echo "\nPairwise overlaps:\n";
for ($i = 0; $i < count($appts); $i++) {
    for ($j = $i + 1; $j < count($appts); $j++) {
        $a = $appts[$i];
        $b = $appts[$j];
        $startA = strtotime($a['appointment_begin']);
        $endA = strtotime($a['appointment_end']);
        $startB = strtotime($b['appointment_begin']);
        $endB = strtotime($b['appointment_end']);

        $sameStart = ($startA === $startB);
        $overlapSecs = max(0, min($endA, $endB) - max($startA, $startB));
        $overlapMins = ceil($overlapSecs / 60);

        if ($sameStart || $overlapMins > 0) {
            echo "  #{$a['appointment_id']} vs #{$b['appointment_id']}: SameStart=" . ($sameStart ? 'YES' : 'NO') . ", Overlap={$overlapMins}m\n";
        }
    }
}

