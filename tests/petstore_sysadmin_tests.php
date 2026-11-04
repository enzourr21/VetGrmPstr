<?php
declare(strict_types=1);

/**
 * Pet Store System Administrator improvements: verification suite.
 * Requires the dev server: tools\php83\php.exe -S 127.0.0.1:8080 -t public public\index.php
 * Usage: tools\php83\php.exe tests\petstore_sysadmin_tests.php
 */
require __DIR__ . '/../app/Core/Core.php';

use App\Core\Database;

$base = getenv('APP_TEST_URL') ?: 'http://127.0.0.1:8080';
$pdo = Database::pdo();
$failed = 0;
$total = 0;

final class Http
{
    public array $cookies = [];
    public function __construct(private string $base) {}
    public function get(string $p): array { return $this->send('GET', $p); }
    public function post(string $p, array $d): array { return $this->send('POST', $p, http_build_query($d)); }
    public function csrf(string $html): string
    {
        if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m)) {
            return $m[1];
        }
        throw new RuntimeException('no csrf');
    }
    private function send(string $method, string $path, ?string $body = null): array
    {
        $h = ['Accept: text/html'];
        if ($this->cookies) {
            $h[] = 'Cookie: ' . implode('; ', array_map(fn($k, $v) => "$k=$v", array_keys($this->cookies), $this->cookies));
        }
        if ($method === 'POST') {
            $h[] = 'Content-Type: application/x-www-form-urlencoded';
        }
        $ctx = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $h), 'content' => $body ?? '', 'follow_location' => 0, 'ignore_errors' => true, 'timeout' => 15]]);
        $raw = @file_get_contents($this->base . $path, false, $ctx);
        if ($raw === false) {
            throw new RuntimeException("unreachable $path");
        }
        $status = 0;
        $loc = null;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m)) {
                $status = (int)$m[1];
            } elseif (stripos($line, 'Set-Cookie:') === 0 && preg_match('/:\s*([^=]+)=([^;]+)/', $line, $m)) {
                $this->cookies[trim($m[1])] = trim($m[2]);
            } elseif (stripos($line, 'Location:') === 0) {
                $loc = trim(substr($line, 9));
            }
        }
        return ['status' => $status, 'location' => $loc, 'body' => $raw];
    }
    public function staffLogin(string $email, string $pw): array
    {
        $lp = $this->get('/staff/login');
        return $this->post('/staff/login', ['csrf_token' => $this->csrf($lp['body']), 'email' => $email, 'password' => $pw]);
    }
}

function check(string $name, bool $ok): void
{
    global $failed, $total;
    $total++;
    if (!$ok) {
        $failed++;
    }
    echo ($ok ? '  PASS ' : '  FAIL ') . $name . "\n";
}

function auditCount(PDO $pdo): int
{
    return (int)$pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
}

function scalar(PDO $pdo, string $sql, array $args = []): mixed
{
    $s = $pdo->prepare($sql);
    $s->execute($args);
    return $s->fetchColumn();
}

function cleanup(PDO $pdo, int $maxLogId): void
{
    $uids = $pdo->query("SELECT user_account_id FROM user_account WHERE email LIKE 'ps.test.%@vetgrpet.local'")->fetchAll(PDO::FETCH_COLUMN);
    $pdo->exec('DELETE FROM audit_log WHERE log_id > ' . $maxLogId);
    if ($uids) {
        $in = implode(',', array_map('intval', $uids));
        $pdo->exec("DELETE FROM audit_log WHERE actor_user_id IN ($in)");
        $pdo->exec("DELETE FROM account_role WHERE user_account_id IN ($in)");
        $pdo->exec("DELETE FROM employee WHERE user_account_id IN ($in)");
        $pdo->exec("DELETE FROM customer WHERE user_account_id IN ($in)");
        $pdo->exec("DELETE FROM user_account WHERE user_account_id IN ($in)");
    }
    $pdo->exec("DELETE FROM business_position WHERE position_name IN ('PS Test Position','VC Test Position')");
}

$maxLog = (int)$pdo->query('SELECT COALESCE(MAX(log_id),0) FROM audit_log')->fetchColumn();
cleanup($pdo, $maxLog);

$petUnit = (int)scalar($pdo, "SELECT business_unit_id FROM business_unit WHERE business_unit_name='Pet Store'");
$vetUnit = (int)scalar($pdo, "SELECT business_unit_id FROM business_unit WHERE business_unit_name='Veterinary Clinic'");

// ------------------------------------------------------------------ data
echo "\nSuite A: positions, roles, permissions (data layer)\n";
$active = $pdo->prepare("SELECT position_name FROM business_position WHERE business_unit_id=? AND position_status='active'");
$active->execute([$petUnit]);
$activeNames = $active->fetchAll(PDO::FETCH_COLUMN);
check('Inventory Manager is an active Pet Store position', in_array('Inventory Manager', $activeNames, true));
check('Store Supervisor is not an active Pet Store position', !in_array('Store Supervisor', $activeNames, true));
check('Sales & Inventory Associate is not an active Pet Store position', !in_array('Sales & Inventory Associate', $activeNames, true));
check('Retired positions are preserved (inactive), not deleted', (int)scalar($pdo, "SELECT COUNT(*) FROM business_position WHERE business_unit_id=? AND position_name IN ('Store Supervisor','Sales & Inventory Associate') AND position_status='inactive'", [$petUnit]) === 2);
check('Veterinary Clinic / Grooming positions unchanged by Pet Store refresh', (int)scalar($pdo, "SELECT COUNT(*) FROM business_position WHERE business_unit_id<>? AND position_status='inactive'", [$petUnit]) === 0);
foreach (['INVENTORY_MANAGER', 'DELIVERY_PERSONNEL'] as $rn) {
    check("Role $rn exists exactly once", (int)scalar($pdo, 'SELECT COUNT(*) FROM role WHERE role_name=?', [$rn]) === 1);
}
foreach (['SUPER_ADMINISTRATOR', 'SYSTEM_ADMINISTRATOR', 'BUSINESS_MANAGER', 'RECEPTIONIST', 'VETERINARIAN', 'NURSE', 'TECHNICIAN'] as $rn) {
    check("Pre-existing role $rn preserved", (int)scalar($pdo, 'SELECT COUNT(*) FROM role WHERE role_name=?', [$rn]) === 1);
}
$recPerms = $pdo->query("SELECT p.permission_code FROM role_permission rp JOIN role r ON r.role_id=rp.role_id JOIN permission p ON p.permission_id=rp.permission_id WHERE r.role_name='RECEPTIONIST' ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
check('RECEPTIONIST permissions unchanged', $recPerms === ['appointment.process', 'appointment.view']);
$sysPerms = $pdo->query("SELECT COUNT(*) FROM role_permission rp JOIN role r ON r.role_id=rp.role_id JOIN permission p ON p.permission_id=rp.permission_id WHERE r.role_name='SYSTEM_ADMINISTRATOR' AND p.permission_code LIKE 'manager.%'")->fetchColumn();
check('SYSTEM_ADMINISTRATOR gained no Business Manager permissions', (int)$sysPerms === 0);
check('audit_log has before/after + affected employee columns', (int)scalar($pdo, "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='audit_log' AND column_name IN ('old_value','new_value','affected_employee_id')") === 3);

// ------------------------------------------------------------------ UI
echo "\nSuite B: Pet Store System Administrator pages\n";
$pet = new Http($base);
$r = $pet->staffLogin('sysadmin.petstore@vetgrpet.local', 'SysAdminPet!2026');
check('Pet Store admin logs in to /sysadmin', $r['status'] === 302 && $r['location'] === '/sysadmin');
$dash = $pet->get('/sysadmin');
check('Dashboard no longer shows "Add Unit Employee"', !str_contains($dash['body'], 'Add Unit Employee'));
check('Dashboard no longer shows "Manage Positions"', !str_contains($dash['body'], 'Manage Positions'));
check('Dashboard keeps Business Unit Scope + Unit Employees cards, form and roster', str_contains($dash['body'], 'Business Unit Scope') && str_contains($dash['body'], 'Unit Employees') && str_contains($dash['body'], 'Create Employee in Pet Store') && str_contains($dash['body'], 'Unit Employee Roster'));
check('Dashboard roster has Show All + modal', str_contains($dash['body'], 'data-modal-open="roster-modal"') && str_contains($dash['body'], 'id="roster-modal"'));
check('Roster uses vertical-scroll list (no horizontal table-wrap)', str_contains($dash['body'], 'roster-list') && !str_contains($dash['body'], 'table-wrap'));
check('Nav shows Activity Log and no Unit Positions', str_contains($dash['body'], '>Activity Log<') && !preg_match('/class="segmented-pill[^"]*">Unit Positions<\/a>/', $dash['body']));
preg_match_all('/<a [^>]*class="segmented-pill[^"]*"[^>]*>([^<]+)<\/a>/', $dash['body'], $navTabs);
$cleanTabs = array_map(fn($t) => html_entity_decode(trim($t)), $navTabs[1]);
check('Navigation tab order for Pet Store is Overview -> Unit Employees -> Roles & Permissions -> Activity Log', $cleanTabs === ['Overview', 'Unit Employees', 'Roles & Permissions', 'Activity Log']);

$emp = $pet->get('/sysadmin/employees');
check('Employee form shows no hashing implementation text', !str_contains($emp['body'], 'password_hash') && !str_contains($emp['body'], 'Hashed with'));
check('Position list uses rigid alignment classes (position-info, position-badge, position-toggle-btn)', str_contains($emp['body'], 'position-info') && str_contains($emp['body'], 'position-badge') && str_contains($emp['body'], 'position-toggle-btn'));
check('Position dropdown has Inventory Manager', str_contains($emp['body'], '<option value="Inventory Manager"'));
check('Position dropdown lacks Store Supervisor and Sales & Inventory Associate', !str_contains($emp['body'], '<option value="Store Supervisor"') && !str_contains($emp['body'], 'value="Sales &amp; Inventory Associate"'));
check('Add Role button + picker lists new and previous roles', str_contains($emp['body'], 'data-role-add') && str_contains($emp['body'], 'data-role-option="INVENTORY_MANAGER"') && str_contains($emp['body'], 'data-role-option="DELIVERY_PERSONNEL"') && str_contains($emp['body'], 'data-role-option="BUSINESS_MANAGER"') && str_contains($emp['body'], 'data-role-option="RECEPTIONIST"'));
check('Role checkboxes are no longer always visible', !str_contains($emp['body'], 'type="checkbox"'));
check('Pet Store clinical roles are not offered', !str_contains($emp['body'], 'data-role-option="VETERINARIAN"'));
check('/sysadmin/positions redirects to employees for Pet Store', ($pet->get('/sysadmin/positions')['location'] ?? '') === '/sysadmin/employees');

// ------------------------------------------------------------------ creation
echo "\nSuite C: employee creation + activity records\n";
$before = auditCount($pdo);
$form = $pet->get('/sysadmin/employees');
$res = $pet->post('/sysadmin/employees', [
    'csrf_token' => $pet->csrf($form['body']),
    'business_unit_id' => $petUnit,
    'full_name' => 'Pat Tester',
    'email' => 'ps.test.pat@vetgrpet.local',
    'username' => 'ps_test_pat',
    'position' => 'Inventory Manager',
    'roles' => ['INVENTORY_MANAGER', 'DELIVERY_PERSONNEL'],
    'password' => 'PatTester!2026',
]);
check('Creation with two roles succeeds (302)', $res['status'] === 302);
$row = $pdo->query("SELECT ua.user_account_id, ua.password_hash, e.employee_id, e.position, e.business_unit_id FROM user_account ua JOIN employee e ON e.user_account_id=ua.user_account_id WHERE ua.email='ps.test.pat@vetgrpet.local'")->fetch();
check('Employee saved in Pet Store with chosen position', (bool)$row && (int)$row['business_unit_id'] === $petUnit && $row['position'] === 'Inventory Manager');
check('Password still stored hashed', (bool)$row && password_verify('PatTester!2026', (string)$row['password_hash']) && $row['password_hash'] !== 'PatTester!2026');
$roleRows = $pdo->prepare('SELECT r.role_name FROM account_role ar JOIN role r ON r.role_id=ar.role_id WHERE ar.user_account_id=? AND ar.business_unit_id=? ORDER BY 1');
$roleRows->execute([$row['user_account_id'], $petUnit]);
check('Both roles assigned', $roleRows->fetchAll(PDO::FETCH_COLUMN) === ['DELIVERY_PERSONNEL', 'INVENTORY_MANAGER']);
$sysUid = (int)scalar($pdo, "SELECT user_account_id FROM user_account WHERE email='sysadmin.petstore@vetgrpet.local'");
$log = $pdo->prepare("SELECT * FROM audit_log WHERE action_code='employee.created' AND affected_employee_id=? ORDER BY log_id DESC LIMIT 1");
$log->execute([$row['employee_id']]);
$L = $log->fetch();
check('Creation recorded with actor, affected employee and unit', (bool)$L && (int)$L['actor_user_id'] === $sysUid && (int)$L['business_unit_id'] === $petUnit);
check('Creation record has details (position + roles) and no secrets', (bool)$L && str_contains((string)$L['new_value'], 'Inventory Manager') && !str_contains($L['summary'] . $L['new_value'], 'PatTester') && !str_contains($L['summary'] . $L['new_value'], '$2y$'));

$before = auditCount($pdo);
$form = $pet->get('/sysadmin/employees');
$dup = $pet->post('/sysadmin/employees', [
    'csrf_token' => $pet->csrf($form['body']), 'business_unit_id' => $petUnit, 'full_name' => 'Dup Person',
    'email' => 'ps.test.pat@vetgrpet.local', 'position' => 'Receptionist', 'roles' => ['RECEPTIONIST'], 'password' => 'DupPerson!2026',
]);
check('Failed creation (duplicate email) returns 422 and records nothing', $dup['status'] === 422 && auditCount($pdo) === $before);
check('Failed creation keeps chosen roles as chips after error', str_contains($dup['body'], 'data-role-chip="RECEPTIONIST"'));
$bad = $pet->post('/sysadmin/employees', [
    'csrf_token' => $pet->csrf($dup['body']), 'business_unit_id' => $petUnit, 'full_name' => 'Bad Role',
    'email' => 'ps.test.bad@vetgrpet.local', 'position' => 'Receptionist', 'roles' => ['VETERINARIAN'], 'password' => 'BadRolePerson!2026',
]);
check('Clinical role cannot be assigned in Pet Store (422, no audit)', $bad['status'] === 422 && auditCount($pdo) === $before);

// ------------------------------------------------------------------ status
echo "\nSuite D: account status, roles, positions\n";
$eid = (int)$row['employee_id'];
$pg = $pet->get('/sysadmin/employees');
$tok = $pet->csrf($pg['body']);
$b = auditCount($pdo);
$pet->post("/sysadmin/employees/$eid/status", ['csrf_token' => $tok, 'business_unit_id' => $petUnit, 'account_status' => 'inactive']);
check('Deactivation updates account and employee status', scalar($pdo, 'SELECT account_status FROM user_account WHERE user_account_id=?', [$row['user_account_id']]) === 'inactive' && scalar($pdo, 'SELECT employee_status FROM employee WHERE employee_id=?', [$eid]) === 'inactive');
$L = $pdo->query("SELECT * FROM audit_log WHERE action_code='employee.deactivated' AND affected_employee_id=$eid")->fetch();
check('Deactivation logged with previous/new value and actor', (bool)$L && $L['old_value'] === 'active' && $L['new_value'] === 'inactive' && (int)$L['actor_user_id'] === $sysUid);
$pet->post("/sysadmin/employees/$eid/status", ['csrf_token' => $tok, 'business_unit_id' => $petUnit, 'account_status' => 'active']);
$L = $pdo->query("SELECT * FROM audit_log WHERE action_code='employee.reactivated' AND affected_employee_id=$eid")->fetch();
check('Reactivation logged (inactive -> active)', (bool)$L && $L['old_value'] === 'inactive' && $L['new_value'] === 'active');
$b = auditCount($pdo);
$pet->post("/sysadmin/employees/$eid/status", ['csrf_token' => $tok, 'business_unit_id' => $petUnit, 'account_status' => 'active']);
check('No-op status change records nothing', auditCount($pdo) === $b);
$selfEid = (int)scalar($pdo, 'SELECT employee_id FROM employee WHERE user_account_id=?', [$sysUid]);
$s = $pet->post("/sysadmin/employees/$selfEid/status", ['csrf_token' => $tok, 'business_unit_id' => $petUnit, 'account_status' => 'inactive']);
check('Admin cannot deactivate own account (422, no record, still active)', $s['status'] === 422 && auditCount($pdo) === $b && scalar($pdo, 'SELECT account_status FROM user_account WHERE user_account_id=?', [$sysUid]) === 'active');
$vetEid = (int)scalar($pdo, "SELECT e.employee_id FROM employee e JOIN user_account ua ON ua.user_account_id=e.user_account_id WHERE ua.email='sysadmin.vetclinic@vetgrpet.local'");
$s = $pet->post("/sysadmin/employees/$vetEid/status", ['csrf_token' => $tok, 'business_unit_id' => $petUnit, 'account_status' => 'inactive']);
check('Cross-unit status change denied (403, no record, unchanged)', $s['status'] === 403 && auditCount($pdo) === $b && scalar($pdo, "SELECT account_status FROM user_account WHERE email='sysadmin.vetclinic@vetgrpet.local'") === 'active');

$rp = $pet->get('/sysadmin/roles');
$tok = $pet->csrf($rp['body']);
$pet->post("/sysadmin/employees/$eid/roles", ['csrf_token' => $tok, 'business_unit_id' => $petUnit, 'roles' => ['INVENTORY_MANAGER', 'BUSINESS_MANAGER']]);
$as = $pdo->query("SELECT * FROM audit_log WHERE action_code='role.assigned' AND affected_employee_id=$eid")->fetch();
$rm = $pdo->query("SELECT * FROM audit_log WHERE action_code='role.removed' AND affected_employee_id=$eid")->fetch();
check('Role assignment logged with previous and new roles', (bool)$as && str_contains($as['summary'], 'BUSINESS_MANAGER') && $as['old_value'] === 'DELIVERY_PERSONNEL, INVENTORY_MANAGER' && $as['new_value'] === 'BUSINESS_MANAGER, INVENTORY_MANAGER');
check('Role removal logged separately', (bool)$rm && str_contains($rm['summary'], 'DELIVERY_PERSONNEL'));
$b = auditCount($pdo);
$pet->post("/sysadmin/employees/$eid/roles", ['csrf_token' => $tok, 'business_unit_id' => $petUnit, 'roles' => ['INVENTORY_MANAGER', 'BUSINESS_MANAGER']]);
check('Unchanged role submit records nothing', auditCount($pdo) === $b);
$s = $pet->post("/sysadmin/employees/$eid/roles", ['csrf_token' => $tok, 'business_unit_id' => $petUnit, 'roles' => ['VETERINARIAN']]);
check('Invalid role for unit rejected, nothing recorded', auditCount($pdo) === $b);
$s = $pet->post("/sysadmin/employees/$vetEid/roles", ['csrf_token' => $tok, 'business_unit_id' => $petUnit, 'roles' => ['RECEPTIONIST']]);
check('Cross-unit role change denied (403)', $s['status'] === 403 && auditCount($pdo) === $b);

$pg = $pet->get('/sysadmin/employees');
$tok = $pet->csrf($pg['body']);
$pet->post("/sysadmin/employees/$eid/position", ['csrf_token' => $tok, 'business_unit_id' => $petUnit, 'position' => 'Receptionist']);
$L = $pdo->query("SELECT * FROM audit_log WHERE action_code='employee.position_changed' AND affected_employee_id=$eid")->fetch();
check('Default position change logged (previous -> new)', (bool)$L && $L['old_value'] === 'Inventory Manager' && $L['new_value'] === 'Receptionist' && scalar($pdo, 'SELECT position FROM employee WHERE employee_id=?', [$eid]) === 'Receptionist');
$b = auditCount($pdo);
$s = $pet->post("/sysadmin/employees/$eid/position", ['csrf_token' => $tok, 'business_unit_id' => $petUnit, 'position' => 'Store Supervisor']);
check('Retired position cannot be selected (422, no record, unchanged)', $s['status'] === 422 && auditCount($pdo) === $b && scalar($pdo, 'SELECT position FROM employee WHERE employee_id=?', [$eid]) === 'Receptionist');

$pet->post('/sysadmin/positions', ['csrf_token' => $tok, 'business_unit_id' => $petUnit, 'position_name' => 'PS Test Position', 'description' => 'test']);
$pid = (int)scalar($pdo, "SELECT position_id FROM business_position WHERE position_name='PS Test Position' AND business_unit_id=?", [$petUnit]);
check('Position creation logged', $pid > 0 && (int)scalar($pdo, "SELECT COUNT(*) FROM audit_log WHERE action_code='sysadmin.position_created' AND target_id=?", [$pid]) === 1);
$pet->post("/sysadmin/positions/$pid/toggle", ['csrf_token' => $tok, 'business_unit_id' => $petUnit]);
$L = $pdo->query("SELECT * FROM audit_log WHERE action_code='sysadmin.position_toggled' AND target_id=$pid")->fetch();
check('Position deactivation logged (active -> inactive)', (bool)$L && $L['old_value'] === 'active' && $L['new_value'] === 'inactive');
$vp = (int)scalar($pdo, 'SELECT position_id FROM business_position WHERE business_unit_id=? LIMIT 1', [$vetUnit]);
$b = auditCount($pdo);
$s = $pet->post("/sysadmin/positions/$vp/toggle", ['csrf_token' => $tok, 'business_unit_id' => $petUnit]);
check('Toggling another unit\'s position denied (403, no record)', $s['status'] === 403 && auditCount($pdo) === $b);

// ------------------------------------------------------------------ roster
echo "\nSuite E: roster\n";
$pg = $pet->get('/sysadmin/employees');
check('Roster preview limited and complete roster rendered in modal', substr_count($pg['body'], 'class="roster-item"') >= 5 && str_contains($pg['body'], 'roster-list-full'));
$empCount = (int)scalar($pdo, "SELECT COUNT(DISTINCT e.employee_id) FROM employee e JOIN user_account ua ON ua.user_account_id=e.user_account_id WHERE ua.is_super_admin=0 AND (e.business_unit_id=? OR EXISTS (SELECT 1 FROM account_role ar WHERE ar.user_account_id=ua.user_account_id AND ar.business_unit_id=?))", [$petUnit, $petUnit]);
$modalPart = substr($pg['body'], (int)strpos($pg['body'], 'roster-list-full'));
check('Modal contains every Pet Store employee', substr_count($modalPart, 'class="roster-item"') === $empCount);
check('Modal has Pet Store Employees heading with count and close control', str_contains($pg['body'], 'Pet Store Employees (' . $empCount . ')') && str_contains($pg['body'], 'data-modal-close'));
check('Roster items keep email, username, position, roles, status and actions', str_contains($pg['body'], 'ps.test.pat@vetgrpet.local') && str_contains($pg['body'], 'Default position') && str_contains($pg['body'], 'Roles in unit') && str_contains($pg['body'], 'Change position') && str_contains($pg['body'], 'Deactivate'));

// ------------------------------------------------------------------ activity page
echo "\nSuite F: Administrative Activity Log page\n";
$act = $pet->get('/sysadmin/activity');
check('Activity page renders for Pet Store admin', $act['status'] === 200 && str_contains($act['body'], 'Administrative Activity Log'));
check('Shows date, administrator, activity, affected employee columns', str_contains($act['body'], 'Date and time') && str_contains($act['body'], 'Administrator') && str_contains($act['body'], 'Affected employee'));
check('Shows previous/new values', str_contains($act['body'], 'Previous:') && str_contains($act['body'], 'New:'));
check('Activity is scrollable (activity-scroll) and paginated container present', str_contains($act['body'], 'activity-scroll'));
preg_match_all('/<tr>\s*<td class="tabular">([^<]+)<\/td>/', $act['body'], $m);
$ts = array_map('strtotime', $m[1]);
$sorted = $ts;
rsort($sorted);
check('Newest activities first', count($ts) > 1 && $ts === $sorted);
$f = $pet->get('/sysadmin/activity?type=role');
check('Type filter shows only role activity', str_contains($f['body'], 'Role assigned') && !str_contains($f['body'], 'Default position changed') && !str_contains($f['body'], 'Account deactivated'));
$f = $pet->get('/sysadmin/activity?employee_id=' . $eid . '&type=account_status');
check('Employee + type filter works', str_contains($f['body'], 'Account deactivated') && str_contains($f['body'], 'Pat Tester'));
$f = $pet->get('/sysadmin/activity?date_from=2999-01-01');
check('Empty state shown when nothing matches', str_contains($f['body'], 'No activity matches the selected filters.'));
$f = $pet->get('/sysadmin/activity?date_from=2000-01-01&date_to=2999-12-31&page=999');
check('Out-of-range page is clamped safely', $f['status'] === 200);

echo "\nSuite G: isolation and tamper resistance\n";
$vet = new Http($base);
$vet->staffLogin('sysadmin.vetclinic@vetgrpet.local', 'SysAdminVet!2026');
$vp2 = $vet->get('/sysadmin/positions');
$vtok = $vet->csrf($vp2['body']);
$vet->post('/sysadmin/positions', ['csrf_token' => $vtok, 'business_unit_id' => $vetUnit, 'position_name' => 'VC Test Position', 'description' => 'x']);
$petAct = $pet->get('/sysadmin/activity?type=position_status');
check('Pet Store log does not expose Veterinary Clinic activity', !str_contains($petAct['body'], 'VC Test Position') && str_contains($petAct['body'], 'PS Test Position'));
check('Vet admin cannot open Pet Store activity log via query (403)', $vet->get('/sysadmin/activity?unit_id=' . $petUnit)['status'] === 403);
check('Vet admin has no activity log page (404) and keeps Unit Positions', $vet->get('/sysadmin/activity')['status'] === 404 && str_contains($vp2['body'], '>Unit Positions<') && !str_contains($vp2['body'], '>Activity Log<'));
check('Pet admin cannot view Vet unit activity (403)', $pet->get('/sysadmin/activity?unit_id=' . $vetUnit)['status'] === 403);
$vemp = $vet->get('/sysadmin/employees');
check('Veterinary Clinic employee form unchanged (checkbox roles, original text, positions page)', str_contains($vemp['body'], 'type="checkbox"') && !str_contains($vemp['body'], 'data-role-add') && str_contains($vemp['body'], 'Hashed with') && !str_contains($vemp['body'], 'INVENTORY_MANAGER'));
check('Veterinary Clinic roles page keeps original (no collapsible cards)', !str_contains($vet->get('/sysadmin/roles')['body'], 'data-role-toggle'));
$vf = $vet->post('/sysadmin/employees', ['csrf_token' => $vet->csrf($vemp['body']), 'business_unit_id' => $vetUnit, 'full_name' => 'Wrong Role', 'email' => 'ps.test.wrong@vetgrpet.local', 'position' => 'Receptionist', 'roles' => ['INVENTORY_MANAGER'], 'password' => 'WrongRole!2026']);
check('Pet Store-only role rejected in Veterinary Clinic (422)', $vf['status'] === 422);
$rp = $pet->get('/sysadmin/roles');
check('Pet Store role cards collapsed by default with arrow toggles', substr_count($rp['body'], 'aria-expanded="false"') >= 4 && str_contains($rp['body'], 'data-role-panel') && str_contains($rp['body'], 'INVENTORY_MANAGER'));
check('Role cards contain chevron, short description and full description elements', str_contains($rp['body'], 'role-card-chevron') && str_contains($rp['body'], 'role-short-desc') && str_contains($rp['body'], 'role-full-desc'));

$css = file_get_contents(__DIR__ . '/../public/assets/css/app.css');
check('CSS includes green dot active indicator rules for both topbar and subnav', str_contains($css, '.topbar nav a.is-current::before') && str_contains($css, '.admin-subnav a.is-active::before'));
check('CSS includes dynamic sliding indicator and fixed position action dimensions', str_contains($css, '.nav-active-indicator') && str_contains($css, '.position-badge') && str_contains($css, '.position-toggle-btn'));

$js = file_get_contents(__DIR__ . '/../public/assets/js/app.js');
check('JS includes active navigation controller for both topbar and subnav', str_contains($js, 'initActiveNavIndicators') && str_contains($js, 'vetgrpet_topbar_active_rect') && str_contains($js, 'vetgrpet_subnav_active_rect'));

check('Business Settings/Manager access not granted to System Administrator (403)', $pet->get('/manager?unit_id=' . $petUnit)['status'] === 403);
check('No route can modify or delete audit records (POST/DELETE -> 404)', $pet->post('/sysadmin/activity', ['csrf_token' => $tok])['status'] === 404);
$cust = new Http($base);
$cust->staffLogin('sysadmin_petstore', 'SysAdminPet!2026');
check('Username (customer-only) session denied activity log (403)', $cust->get('/sysadmin/activity')['status'] === 403);
check('Anonymous visitor redirected away from activity log', (new Http($base))->get('/sysadmin/activity')['status'] === 302);
$leak = (int)scalar($pdo, "SELECT COUNT(*) FROM audit_log WHERE log_id > ? AND (summary LIKE '%\$2y\$%' OR new_value LIKE '%\$2y\$%' OR old_value LIKE '%\$2y\$%' OR summary LIKE '%!2026%')", [$maxLog]);
check('No passwords or hashes in any audit record written by this run', $leak === 0);

cleanup($pdo, $maxLog);
echo "\n";
if ($failed) {
    echo "$failed of $total ASSERTIONS FAILED\n";
    exit(1);
}
echo "ALL $total PET STORE SYSADMIN ASSERTIONS PASSED\n";

