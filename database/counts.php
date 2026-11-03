<?php
require_once dirname(__DIR__) . '/config/database.php';
foreach (['business_unit','branch','appointment_status','service','product','business_product','inventory','role','permission','role_permission','customer','customer_order','appointment'] as $t) {
    echo $t . '=' . $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn() . "\n";
}

