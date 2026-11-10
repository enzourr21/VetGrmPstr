<?php
require 'app/bootstrap.php';
$pdo = \App\Core\Database::pdo();

echo "Cleaning up synthetic test appointment #8...\n";
$pdo->beginTransaction();
try {
    // Check if appointment #8 exists
    $appt = $pdo->query("SELECT appointment_id, notes FROM appointment WHERE appointment_id = 8")->fetch(PDO::FETCH_ASSOC);
    if ($appt && str_contains($appt['notes'], 'Should be rejected due to overlap')) {
        // Delete payment and invoice items for invoice associated with appt 8
        $invoices = $pdo->query("SELECT invoice_id FROM service_invoice WHERE appointment_id = 8")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($invoices as $invId) {
            $pdo->exec("DELETE FROM payment WHERE invoice_id = $invId");
            $pdo->exec("DELETE FROM invoice_item WHERE invoice_id = $invId");
        }
        $pdo->exec("DELETE FROM service_invoice WHERE appointment_id = 8");

        // Delete performed_service and requested_pet_service for appointment_pet of appt 8
        $apIds = $pdo->query("SELECT appointment_pet_id FROM appointment_pet WHERE appointment_id = 8")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($apIds as $apId) {
            $pdo->exec("DELETE FROM performed_service WHERE appointment_pet_id = $apId");
            $pdo->exec("DELETE FROM requested_pet_service WHERE appointment_pet_id = $apId");
        }
        $pdo->exec("DELETE FROM appointment_pet WHERE appointment_id = 8");
        $pdo->exec("DELETE FROM appointment WHERE appointment_id = 8");
        $pdo->commit();
        echo "[SUCCESS] Synthetic test appointment #8 safely removed.\n";
    } else {
        $pdo->rollBack();
        echo "[INFO] Appointment #8 not found or already cleaned up.\n";
    }
} catch (\Throwable $e) {
    $pdo->rollBack();
    echo "[ERROR] " . $e->getMessage() . "\n";
}

