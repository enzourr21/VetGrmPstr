<?php
require 'd:/VETGRPETproj/app/bootstrap.php';

// Set up Super Admin session
\App\Core\Session::start();
\App\Core\Session::login([
    'user_id' => 1,
    'email' => 'superadmin@vetgrpet.local',
    'auth_method' => 'email',
    'base_context' => 'employee',
    'active_context' => 'employee',
    'is_super_admin' => true,
]);

try {
    $c = new \App\Controllers\GroomingReceptionistController();
    $c->overview();
    echo "Overview executed without error!\n";
} catch (\Throwable $e) {
    echo "Overview Exception: " . $e->getMessage() . "\n";
    echo "In: " . $e->getFile() . " on line " . $e->getLine() . "\n";
    echo $e->getTraceAsString() . "\n";
}

