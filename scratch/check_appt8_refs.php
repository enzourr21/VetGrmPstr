<?php
require 'app/bootstrap.php';
$pdo = \App\Core\Database::pdo();
$tables = ['performed_service', 'service_invoice', 'appointment_pet', 'payment'];
echo "Checking references for appointment 8:\n";
$invoices = $pdo->query("SELECT invoice_id FROM service_invoice WHERE appointment_id = 8")->fetchAll(PDO::FETCH_COLUMN);
echo "Invoices: " . implode(',', $invoices) . "\n";
foreach ($invoices as $invId) {
    echo "  - invoice_item: " . $pdo->query("SELECT COUNT(*) FROM invoice_item WHERE invoice_id = $invId")->fetchColumn() . "\n";
    echo "  - payment: " . $pdo->query("SELECT COUNT(*) FROM payment WHERE invoice_id = $invId")->fetchColumn() . "\n";
}
$apIds = $pdo->query("SELECT appointment_pet_id FROM appointment_pet WHERE appointment_id = 8")->fetchAll(PDO::FETCH_COLUMN);
echo "Appointment pets: " . implode(',', $apIds) . "\n";
foreach ($apIds as $apId) {
    echo "  - performed_service: " . $pdo->query("SELECT COUNT(*) FROM performed_service WHERE appointment_pet_id = $apId")->fetchColumn() . "\n";
}

