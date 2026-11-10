<?php
require 'app/bootstrap.php';
$db = \App\Core\Database::pdo();
$stmt = $db->query("SELECT c.customer_id, c.first_name, c.last_name, ua.email, (SELECT GROUP_CONCAT(CONCAT(p.pet_name, ' (', p.species, ')') SEPARATOR ', ') FROM pet p WHERE p.customer_id = c.customer_id) as pets FROM customer c JOIN user_account ua ON ua.user_account_id = c.user_account_id ORDER BY c.customer_id ASC");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "#{$row['customer_id']} {$row['first_name']} {$row['last_name']} ({$row['email']}) - Pets: [{$row['pets']}]\n";
}
