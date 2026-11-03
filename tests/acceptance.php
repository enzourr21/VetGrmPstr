<?php
/**
 * VETGRPET acceptance tests (17 scenarios from test_instructions.txt).
 * Drives the running app over HTTP (php -S 127.0.0.1:8080 ...) and inspects the DB.
 * Creates throwaway @test.invalid accounts with random passwords and deletes them afterwards.
 *
 * Usage: php tests/acceptance.php   -> prints results and writes tests/TEST_REPORT.md
 */
require_once dirname(__DIR__) . '/config/database.php';

const BASE = 'http://127.0.0.1:8080';

final class Client
{
    public string $jar;
    public string $body = '';
    public int $code = 0;
    public string $url = '';

    public function __construct() { $this->jar = tempnam(sys_get_temp_dir(), 'vgcj'); }

    public function __destruct() { @unlink($this->jar); }

    public function req(string $method, string $path, ?array $data = null): self
    {
        $ch = curl_init(BASE . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
            CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 30,
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data ?? []));
        }
        $this->body = (string)curl_exec($ch);
        $this->code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $this->url = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);
        return $this;
    }

    public function get(string $p): self { return $this->req('GET', $p); }

    public function csrf(): string
    {
        return preg_match('/name="csrf_token" value="([^"]+)"/', $this->body, $m) ? $m[1] : '';
    }

    /** GET $page to obtain a CSRF token, then POST $data to $post. */
    public function form(string $page, string $post, array $data): self
    {
        $this->get($page);
        $data['csrf_token'] = $this->csrf();
        return $this->req('POST', $post, $data);
    }

    public function has(string $needle): bool { return str_contains($this->body, $needle); }
    public function path(): string { return (string)parse_url($this->url, PHP_URL_PATH); }
}

$results = [];
function check(int $n, string $label, bool $ok): void
{
    global $results;
    $results[$n][] = [$label, $ok];
    echo ($ok ? '  ok   ' : '  FAIL ') . "[$n] $label\n";
}

function register(Client $c, string $email, string $pw, string $first, string $last, string $middle = 'Marie'): void
{
    $c->form('/register', '/register', [
        'email' => $email, 'first_name' => $first, 'middle_name' => $middle, 'last_name' => $last,
        'phone' => '555-0100', 'password' => $pw, 'password_confirm' => $pw,
    ]);
}

function login(Client $c, string $email, string $pw): void
{
    $c->form('/login', '/login', ['email' => $email, 'password' => $pw]);
}

function logout(Client $c): void
{
    $c->get('/');
    $c->req('POST', '/logout', ['csrf_token' => $c->csrf()]);
}

function scalar(PDO $pdo, string $sql, array $args = []): mixed
{
    $s = $pdo->prepare($sql);
    $s->execute($args);
    return $s->fetchColumn();
}

function cleanup(PDO $pdo): void
{
    $u = "(SELECT user_account_id FROM user_account WHERE email LIKE '%@test.invalid')";
    $c = "(SELECT customer_id FROM customer WHERE user_account_id IN $u)";
    $stmts = [
        "DELETE FROM customer_review WHERE customer_id IN $c",
        "DELETE oi FROM order_item oi JOIN customer_order o ON o.order_id=oi.order_id WHERE o.customer_id IN $c",
        "DELETE FROM customer_order WHERE customer_id IN $c",
        "DELETE ci FROM cart_item ci JOIN cart ct ON ct.cart_id=ci.cart_id WHERE ct.customer_id IN $c",
        "DELETE FROM cart WHERE customer_id IN $c",
        "DELETE r FROM requested_pet_service r JOIN appointment_pet ap ON ap.appointment_pet_id=r.appointment_pet_id JOIN appointment a ON a.appointment_id=ap.appointment_id WHERE a.customer_id IN $c",
        "DELETE ap FROM appointment_pet ap JOIN appointment a ON a.appointment_id=ap.appointment_id WHERE a.customer_id IN $c",
        "DELETE FROM appointment WHERE customer_id IN $c",
        "DELETE FROM pet WHERE customer_id IN $c",
        "DELETE FROM customer WHERE user_account_id IN $u",
        "DELETE FROM account_role WHERE user_account_id IN $u",
        "DELETE FROM employee WHERE user_account_id IN $u",
        "DELETE FROM user_account WHERE email LIKE '%@test.invalid'",
    ];
    foreach ($stmts as $s) {
        $pdo->exec($s);
    }
}

$run = bin2hex(random_bytes(3));
$pw = 'Tt!' . bin2hex(random_bytes(6));
$emailA = "a.$run@test.invalid";
$emailB = "b.$run@test.invalid";
$emailRec = "rec.$run@test.invalid";
$emailPS = "ps.$run@test.invalid";

$G = (int)scalar($pdo, "SELECT business_unit_id FROM business_unit WHERE business_unit_name='Grooming Services'");
$P = (int)scalar($pdo, "SELECT business_unit_id FROM business_unit WHERE business_unit_name='Pet Store'");
$bp = function (int $unit, string $name) use ($pdo): int {
    return (int)scalar($pdo, 'SELECT bp.business_product_id FROM business_product bp JOIN product p ON p.product_id=bp.product_id WHERE bp.business_unit_id=? AND p.product_name=?', [$unit, $name]);
};
$shampooG = $bp($G, 'Oatmeal Pet Shampoo 250ml');
$brushG = $bp($G, 'Deshedding Brush');
$condG = $bp($G, 'Leave-in Conditioner Spray');
$kibbleP = $bp($P, 'Adult Dog Kibble 2kg');
$svc = $pdo->query("SELECT service_id FROM service WHERE service_category='grooming' AND service_status='active' ORDER BY service_id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);

$invBefore = $pdo->query('SELECT SUM(on_hand) o, SUM(committed) c FROM inventory')->fetch();
$ordersBefore = (int)scalar($pdo, 'SELECT COUNT(*) FROM customer_order');
$constraintAdded = false;

try {
    // --- 1 -------------------------------------------------------------
    echo "Test 1: register and sign in\n";
    $A = new Client();
    $A->get('/');
    check(1, 'public homepage has refactored hero, search bar, 3 overview cards, no green badges or inline pricing tables, and no public Staff/Receptionist entry points',
        $A->has('href="/products"') && $A->has('href="/services"') && $A->has('href="/staffs"') && $A->has('href="/reviews"')
        && $A->has('Unified Pet Care &amp; Clinic Management')
        && $A->has('action="/search"') && $A->has('Search products, veterinary services, or grooming treatments...')
        && $A->has('Explore Veterinary Services') && $A->has('View Grooming Options') && $A->has('Browse Pet Store')
        && !$A->has('class="eyebrow"') && !$A->has('class="badge"') && !$A->has('Basic Bath &amp; Dry')
        && $A->has('data-scene="hero"') && $A->has('data-scene-target="services"')
        && !$A->has('href="/staff/login"') && !$A->has('Receptionist portal'));
    $A->get('/search?q=Oatmeal');
    check(1, 'hero search bar queries products and services across the platform',
        $A->code === 200 && $A->has('Oatmeal Pet Shampoo 250ml'));
    $A->get('/register');
    check(1, 'single-page register hides later stages initially (email -> name -> details -> password), includes password visibility & live match controls, and shows all 6 articulated scenic animals without bunny + sign',
        $A->has('data-progressive-register') && $A->has('id="reg-stage-2"') && $A->has('name="middle_name"')
        && preg_match('/id="reg-stage-2"[^>]*hidden/', $A->body) === 1
        && preg_match('/id="reg-stage-3"[^>]*hidden/', $A->body) === 1
        && preg_match('/id="reg-stage-4"[^>]*hidden/', $A->body) === 1
        && $A->has('data-pw-toggle="password"') && $A->has('data-pw-toggle="password_confirm"')
        && $A->has('data-pw-rule="length"') && $A->has('data-pw-rule="match"')
        && $A->has('data-scenic-rail="left"') && $A->has('scenic-canopy')
        && $A->has('data-animal="sunny"') && $A->has('data-animal="bella"') && $A->has('data-animal="coco"')
        && $A->has('data-animal="luna"') && $A->has('data-animal="clover"') && $A->has('data-animal="pip"')
        && $A->has('data-part="eyelids"') && $A->has('data-part="beak-top"') && $A->has('data-part="beak-bottom"')
        && !$A->has('M142 331 V353 M131 342 H153')
        && $A->has('anim-dog-breathe') && $A->has('anim-dialogue-bark') && $A->has('Woof!')
        && $A->has('anim-dialogue-meow') && $A->has('Meow~') && $A->has('Purr')
        && $A->has('anim-bunny-ear-left') && $A->has('anim-dachshund-tail')
        && $A->has('anim-dialogue-chirp') && $A->has('Chirp!') && $A->has('anim-eyes'));
    $A->get('/login');
    check(1, 'single-page login hides password stage initially until Continue validates email, and omits scenic rails on sign-in',
        $A->has('data-progressive-login') && $A->has('data-login-continue')
        && preg_match('/id="login-stage-password"[^>]*hidden/', $A->body) === 1
        && !$A->has('data-scenic-rail="left"'));
    register($A, $emailA, $pw, 'Alice', 'Anderson', 'Grace');
    check(1, 'registration signs the customer in and saves middle_name', $A->has('Account created') && $A->has('Sign out') && scalar($pdo, 'SELECT middle_name FROM customer c JOIN user_account u ON u.user_account_id=c.user_account_id WHERE u.email=?', [$emailA]) === 'Grace');
    $hash = (string)scalar($pdo, 'SELECT password_hash FROM user_account WHERE email=?', [$emailA]);
    check(1, 'password is stored hashed, not plain text', $hash !== $pw && str_starts_with($hash, '$') && password_verify($pw, $hash));
    logout($A);
    check(1, 'sign out works (sign-in page shown)', $A->path() === '/login');
    $A->get('/profile');
    check(1, 'protected page redirects to login when signed out', $A->path() === '/login');
    login($A, $emailA, $pw);
    check(1, 'sign in with email then password works', $A->has('Sign out') && $A->code === 200);
    $A->get('/profile');
    check(1, 'profile page shows email as NOT verified', $A->has('Email not verified'));
    $A->form('/profile', '/profile', ['first_name' => 'Alicia', 'last_name' => 'Anderson', 'phone' => '555-0100', 'city' => 'Testville']);
    check(1, 'profile can be updated', $A->has('Profile updated') && scalar($pdo, 'SELECT first_name FROM customer c JOIN user_account u ON u.user_account_id=c.user_account_id WHERE u.email=?', [$emailA]) === 'Alicia');
    $cidA = (int)scalar($pdo, 'SELECT customer_id FROM customer c JOIN user_account u ON u.user_account_id=c.user_account_id WHERE u.email=?', [$emailA]);

    // --- 2 -------------------------------------------------------------
    echo "Test 2: invalid email rejected before password step\n";
    $X = new Client();
    $X->form('/login', '/login/email', ['email' => 'not-an-email']);
    check(2, 'invalid email gets HTTP 422 and stays on the email step', $X->code === 422 && $X->path() === '/login/email');
    $X->get('/login/password');
    check(2, 'password step is not reachable after invalid email', $X->path() === '/login');
    $X->form('/register', '/register/email', ['email' => 'a@b']);
    check(2, 'registration also rejects invalid email', $X->code === 422);

    // --- 3 -------------------------------------------------------------
    echo "Test 3: credentials do not reveal registered emails\n";
    $norm = fn(Client $c, string $e) => preg_replace('/value="[^"]*"/', 'value=""', str_replace($e, 'EMAIL', $c->body));
    $X1 = new Client();
    login($X1, $emailA, 'Wrong-password-1');
    $X2 = new Client();
    login($X2, "nobody.$run@test.invalid", 'Wrong-password-1');
    check(3, 'wrong password for existing email returns 401 + generic message', $X1->code === 401 && $X1->has('Incorrect email or password.'));
    check(3, 'unknown email returns the same status and message', $X2->code === $X1->code && $X2->has('Incorrect email or password.'));
    check(3, 'both responses are identical apart from the email text', $norm($X1, $emailA) === $norm($X2, "nobody.$run@test.invalid"));

    // --- 4 -------------------------------------------------------------
    echo "Test 4: multiple pets\n";
    $A->form('/pets/new', '/pets', ['pet_name' => 'Rex', 'species' => 'Dog', 'breed' => 'Beagle', 'sex' => 'male', 'weight_kg' => '12.5']);
    $A->form('/pets/new', '/pets', ['pet_name' => 'Misty', 'species' => 'Cat', 'sex' => 'female']);
    $petIds = $pdo->prepare('SELECT pet_id, pet_name FROM pet WHERE customer_id=? ORDER BY pet_name');
    $petIds->execute([$cidA]);
    $pets = array_column($petIds->fetchAll(), 'pet_id', 'pet_name');
    check(4, 'two pets stored for the customer', count($pets) === 2);
    $A->get('/pets');
    check(4, 'both pets listed', $A->has('Rex') && $A->has('Misty'));
    $A->form('/pets/new', '/pets', ['pet_name' => '', 'species' => 'Dragon']);
    check(4, 'invalid pet is rejected (422)', $A->code === 422);
    $A->form('/pets/' . $pets['Rex'] . '/edit', '/pets/' . $pets['Rex'], ['pet_name' => 'Rex', 'species' => 'Dog', 'breed' => 'Beagle', 'sex' => 'male', 'weight_kg' => '13']);
    check(4, 'owner can edit own pet', scalar($pdo, 'SELECT weight_kg FROM pet WHERE pet_id=?', [$pets['Rex']]) == 13);

    // --- 5 -------------------------------------------------------------
    echo "Test 5: ownership of pets\n";
    $B = new Client();
    register($B, $emailB, $pw, 'Bob', 'Baker');
    $cidB = (int)scalar($pdo, 'SELECT customer_id FROM customer c JOIN user_account u ON u.user_account_id=c.user_account_id WHERE u.email=?', [$emailB]);
    $B->get('/pets/' . $pets['Rex'] . '/edit');
    check(5, "other customer cannot open someone else's pet edit page (404)", $B->code === 404);
    $B->form('/pets/new', '/pets/' . $pets['Rex'], ['pet_name' => 'Hacked', 'species' => 'Dog', 'sex' => 'male']);
    check(5, "other customer cannot update someone else's pet (404)", $B->code === 404 && scalar($pdo, 'SELECT pet_name FROM pet WHERE pet_id=?', [$pets['Rex']]) === 'Rex');
    $B->get('/pets');
    check(5, "other customer's pet list does not show Alice's pets", !$B->has('Rex') && !$B->has('Misty'));
    $B->get('/appointments/new');
    $B->req('POST', '/appointments', ['csrf_token' => $B->csrf(), 'preferred_date' => date('Y-m-d', strtotime('+2 day')), 'pets' => [(string)$pets['Rex'] => [(string)$svc[0]]]]);
    check(5, "appointment request with another customer's pet is rejected", $B->code === 422 && (int)scalar($pdo, 'SELECT COUNT(*) FROM appointment WHERE customer_id=?', [$cidB]) === 0);
    $X = new Client();
    $X->get('/pets');
    check(5, 'anonymous visitor is redirected to login', $X->path() === '/login');
    $B->get('/pets');
    $B->req('POST', '/pets', ['pet_name' => 'NoToken', 'species' => 'Dog']);
    check(5, 'signed-in POST without CSRF token is refused (419) and saves nothing', $B->code === 419 && (int)scalar($pdo, "SELECT COUNT(*) FROM pet WHERE pet_name='NoToken'") === 0);
    $B->req('POST', '/pets', ['pet_name' => 'BadToken', 'species' => 'Dog', 'csrf_token' => 'forged']);
    check(5, 'signed-in POST with a forged CSRF token is refused (419)', $B->code === 419 && (int)scalar($pdo, "SELECT COUNT(*) FROM pet WHERE pet_name='BadToken'") === 0);

    // --- 6 -------------------------------------------------------------
    echo "Test 6: grooming appointment request\n";
    $A->get('/appointments/new');
    check(6, 'form lists grooming services and own pets', $A->has('Basic Bath') && $A->has('Rex') && $A->has('Misty'));
    $date = date('Y-m-d', strtotime('+3 day'));
    $A->req('POST', '/appointments', [
        'csrf_token' => $A->csrf(), 'preferred_date' => $date, 'preferred_time' => '10:30', 'notes' => 'First visit',
        'pets' => [(string)$pets['Rex'] => [(string)$svc[0], (string)$svc[1]], (string)$pets['Misty'] => [(string)$svc[0], (string)$svc[0]]],
    ]);
    $aid = (int)scalar($pdo, 'SELECT appointment_id FROM appointment WHERE customer_id=? ORDER BY appointment_id DESC LIMIT 1', [$cidA]);
    check(6, 'request is saved and customer sees it', $aid > 0 && $A->path() === "/appointments/$aid" && $A->has('Pending'));
    check(6, 'status is Pending (not auto-confirmed) and type is grooming', scalar($pdo, 'SELECT st.status_name FROM appointment a JOIN appointment_status st ON st.appointment_status_id=a.appointment_status_id WHERE a.appointment_id=?', [$aid]) === 'Pending' && scalar($pdo, 'SELECT appointment_type FROM appointment WHERE appointment_id=?', [$aid]) === 'grooming');
    check(6, 'two pets recorded on one appointment', (int)scalar($pdo, 'SELECT COUNT(*) FROM appointment_pet WHERE appointment_id=?', [$aid]) === 2);
    check(6, 'services stored per pet; duplicate selection collapsed (3 rows)', (int)scalar($pdo, 'SELECT COUNT(*) FROM requested_pet_service r JOIN appointment_pet ap ON ap.appointment_pet_id=r.appointment_pet_id WHERE ap.appointment_id=?', [$aid]) === 3);
    $dupBlocked = false;
    try {
        $apId = (int)scalar($pdo, 'SELECT appointment_pet_id FROM appointment_pet WHERE appointment_id=? LIMIT 1', [$aid]);
        $pdo->prepare('INSERT INTO requested_pet_service (appointment_pet_id, service_id, requested_price) SELECT appointment_pet_id, service_id, requested_price FROM requested_pet_service WHERE appointment_pet_id=? LIMIT 1')->execute([$apId]);
    } catch (PDOException $e) {
        $dupBlocked = true;
    }
    check(6, 'database unique key blocks a duplicate service for the same pet/appointment', $dupBlocked);
    $A->get('/appointments/new');
    $A->req('POST', '/appointments', ['csrf_token' => $A->csrf(), 'preferred_date' => $date, 'pets' => [(string)$pets['Rex'] => ['999999']]]);
    check(6, 'unknown/non-grooming service id is rejected', $A->code === 422);
    $A->get('/appointments/new');
    $A->req('POST', '/appointments', ['csrf_token' => $A->csrf(), 'preferred_date' => $date]);
    check(6, 'request with no pet/service is rejected', $A->code === 422);
    $A->get('/appointments');
    check(6, 'customer sees request and status in their list', $A->has("/appointments/$aid") && $A->has('Pending'));
    $B->get("/appointments/$aid");
    check(6, "another customer cannot view the appointment (404)", $B->code === 404);

    // --- 7 -------------------------------------------------------------
    echo "Test 7: receptionist sees the request\n";
    $env = 'RECEPTIONIST_PASSWORD=' . $pw;
    $php = escapeshellarg(PHP_BINARY);
    $out = shell_exec("set $env&& $php " . escapeshellarg(dirname(__DIR__) . '/scripts/create_receptionist.php') . " $emailRec Rita Receptionist 2>&1");
    check(7, 'dev script creates a receptionist account', str_contains((string)$out, 'Receptionist ready'));
    $R = new Client();
    $R->form('/staff/login', '/staff/login', ['email' => $emailRec, 'password' => $pw]);
    check(7, 'receptionist signs in to staff area', $R->path() === '/staff/appointments' && $R->code === 200);
    check(7, 'submitted request appears in appointment management', $R->has("/staff/appointments/$aid") && $R->has('Alicia Anderson'));
    $R->get("/staff/appointments/$aid");
    check(7, 'detail shows customer, contact, pets, services and request time', $R->has('Alicia Anderson') && $R->has($emailA) && $R->has('Rex') && $R->has('Misty') && $R->has('Basic Bath') && $R->has('Requested at'));
    $R->req('POST', "/staff/appointments/$aid/status", ['csrf_token' => $R->csrf(), 'status' => 'Confirmed']);
    check(7, 'receptionist can move Pending to Confirmed', scalar($pdo, 'SELECT st.status_name FROM appointment a JOIN appointment_status st ON st.appointment_status_id=a.appointment_status_id WHERE a.appointment_id=?', [$aid]) === 'Confirmed');
    $R->get("/staff/appointments/$aid");
    $R->req('POST', "/staff/appointments/$aid/status", ['csrf_token' => $R->csrf(), 'status' => 'Pending']);
    check(7, 'illegal transition (Confirmed to Pending) is refused', scalar($pdo, 'SELECT st.status_name FROM appointment a JOIN appointment_status st ON st.appointment_status_id=a.appointment_status_id WHERE a.appointment_id=?', [$aid]) === 'Confirmed');
    $A->get('/appointments/' . $aid);
    check(7, 'customer sees the updated status', $A->has('Confirmed'));
    $A->get('/staff/appointments');
    check(7, 'a customer cannot open the staff area', $A->path() === '/staff/login');
    $A->req('POST', "/staff/appointments/$aid/status", ['csrf_token' => $A->csrf(), 'status' => 'Cancelled']);
    check(7, 'a customer cannot change appointment status', scalar($pdo, 'SELECT st.status_name FROM appointment a JOIN appointment_status st ON st.appointment_status_id=a.appointment_status_id WHERE a.appointment_id=?', [$aid]) === 'Confirmed');

    // --- 8 -------------------------------------------------------------
    echo "Test 8: receptionist business-unit isolation\n";
    $pdo->prepare('INSERT INTO user_account (email, password_hash) VALUES (?,?)')->execute([$emailPS, password_hash($pw, PASSWORD_DEFAULT)]);
    $uidPS = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO employee (user_account_id, first_name, last_name, position) VALUES (?, 'Pat', 'Store', 'Receptionist')")->execute([$uidPS]);
    $pdo->prepare("INSERT INTO account_role (user_account_id, role_id, business_unit_id) SELECT ?, role_id, ? FROM role WHERE role_name='RECEPTIONIST'")->execute([$uidPS, $P]);
    $S = new Client();
    $S->form('/staff/login', '/staff/login', ['email' => $emailPS, 'password' => $pw]);
    $S->get('/staff/appointments');
    check(8, 'Pet Store receptionist is denied Grooming appointment list (403)', $S->code === 403);
    $S->get("/staff/appointments/$aid");
    check(8, 'Pet Store receptionist is denied Grooming appointment detail (403)', $S->code === 403);
    $S->req('POST', "/staff/appointments/$aid/status", ['csrf_token' => $S->csrf(), 'status' => 'Cancelled']);
    check(8, 'Pet Store receptionist cannot change Grooming appointment status', scalar($pdo, 'SELECT st.status_name FROM appointment a JOIN appointment_status st ON st.appointment_status_id=a.appointment_status_id WHERE a.appointment_id=?', [$aid]) === 'Confirmed');
    $perms = $pdo->query("SELECT p.permission_code FROM role r JOIN role_permission rp ON rp.role_id=r.role_id JOIN permission p ON p.permission_id=rp.permission_id WHERE r.role_name='RECEPTIONIST' ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
    check(8, 'RECEPTIONIST role holds only appointment.view and appointment.process', $perms === ['appointment.process', 'appointment.view']);

    // --- 9 -------------------------------------------------------------
    echo "Test 9: browse Grooming products & public discovery pages\n";
    $A->get('/products');
    check(9, 'public /products catalog page renders products and business units', $A->code === 200 && $A->has('Oatmeal Pet Shampoo'));
    $A->get('/services');
    check(9, 'public /services page lists both Grooming Services and Veterinary Clinic services', $A->code === 200 && $A->has('Basic Bath &amp; Dry') && $A->has('Comprehensive Wellness Exam'));
    $A->get('/staffs');
    check(9, 'public /staffs page introduces staff and business managers without exposing private emails', $A->code === 200 && $A->has('Business Unit Managers') && $A->has('Marcus Chen') && !$A->has('@staff.sample.vetgrpet'));
    $A->form('/reviews', '/reviews', ['rating' => '5', 'business_unit_id' => (string)$G, 'review_title' => 'Wonderful spa visit', 'comment' => 'Our beagle loved the oatmeal bath and nail trim!']);
    check(9, 'customer can submit a positive review persisted in customer_review', $A->code === 200 && $A->has('Wonderful spa visit') && (int)scalar($pdo, 'SELECT COUNT(*) FROM customer_review WHERE customer_id=?', [$cidA]) === 1);
    $A->get("/shop/$G");
    check(9, 'grooming catalog lists grooming products with prices', $A->has('Oatmeal Pet Shampoo') && $A->has('Deshedding Brush') && $A->has('$12.50'));
    check(9, 'catalog does not list Pet Store-only products', !$A->has('Kibble') && !$A->has('Feather'));
    check(9, 'availability is shown (in stock / out of stock)', $A->has('In stock') && $A->has('Out of stock'));
    $A->get("/shop/$G/product/$shampooG");
    check(9, 'product detail shows price and availability', $A->has('$12.50') && $A->has('available'));
    $A->get("/shop/$G/product/$kibbleP");
    check(9, "Pet Store product is not viewable under the Grooming shop (404)", $A->code === 404);

    // --- 10 ------------------------------------------------------------
    echo "Test 10: add, update, remove cart items\n";
    $addG = fn(int $id, string $q) => $A->form("/shop/$G", "/cart/$G/add", ['business_product_id' => $id, 'quantity' => $q]);
    $addG($shampooG, '2');
    check(10, 'item added to cart', $A->has('Added to your cart') && (int)scalar($pdo, 'SELECT ci.quantity FROM cart_item ci JOIN cart c ON c.cart_id=ci.cart_id WHERE c.customer_id=? AND c.business_unit_id=? AND ci.business_product_id=?', [$cidA, $G, $shampooG]) === 2);
    $addG($shampooG, '1');
    check(10, 'adding the same product again increments (no duplicate row)', (int)scalar($pdo, 'SELECT COUNT(*) FROM cart_item ci JOIN cart c ON c.cart_id=ci.cart_id WHERE c.customer_id=? AND c.business_unit_id=? AND ci.business_product_id=?', [$cidA, $G, $shampooG]) === 1);
    $addG($brushG, '1');
    $itemId = (int)scalar($pdo, 'SELECT ci.cart_item_id FROM cart_item ci JOIN cart c ON c.cart_id=ci.cart_id WHERE c.customer_id=? AND c.business_unit_id=? AND ci.business_product_id=?', [$cidA, $G, $shampooG]);
    $A->form("/cart/$G", "/cart/$G/update", ['cart_item_id' => $itemId, 'quantity' => '5']);
    check(10, 'quantity updated', (int)scalar($pdo, 'SELECT quantity FROM cart_item WHERE cart_item_id=?', [$itemId]) === 5);
    $A->form("/cart/$G", "/cart/$G/update", ['cart_item_id' => $itemId, 'quantity' => '0']);
    check(10, 'invalid quantity 0 refused', (int)scalar($pdo, 'SELECT quantity FROM cart_item WHERE cart_item_id=?', [$itemId]) === 5);
    $brushItem = (int)scalar($pdo, 'SELECT ci.cart_item_id FROM cart_item ci JOIN cart c ON c.cart_id=ci.cart_id WHERE c.customer_id=? AND c.business_unit_id=? AND ci.business_product_id=?', [$cidA, $G, $brushG]);
    $A->form("/cart/$G", "/cart/$G/remove", ['cart_item_id' => $brushItem]);
    check(10, 'item removed', (int)scalar($pdo, 'SELECT COUNT(*) FROM cart_item WHERE cart_item_id=?', [$brushItem]) === 0);
    $addG($condG, '1');
    check(10, 'out-of-stock product cannot be added', $A->has('out of stock') && (int)scalar($pdo, 'SELECT COUNT(*) FROM cart_item ci JOIN cart c ON c.cart_id=ci.cart_id WHERE c.customer_id=? AND ci.business_product_id=?', [$cidA, $condG]) === 0);
    $B->form("/cart/$G", "/cart/$G/remove", ['cart_item_id' => $itemId]);
    check(10, "another customer cannot remove Alice's cart item", (int)scalar($pdo, 'SELECT COUNT(*) FROM cart_item WHERE cart_item_id=?', [$itemId]) === 1);

    // --- 11 ------------------------------------------------------------
    echo "Test 11: carts stay separate\n";
    $A->form("/shop/$P", "/cart/$P/add", ['business_product_id' => $kibbleP, 'quantity' => '2']);
    check(11, 'one cart row per business unit for the customer', (int)scalar($pdo, 'SELECT COUNT(DISTINCT business_unit_id) FROM cart WHERE customer_id=?', [$cidA]) === 2);
    $A->get("/cart/$G");
    check(11, 'Grooming cart has shampoo and no Pet Store kibble', $A->has('Oatmeal Pet Shampoo') && !$A->has('Kibble'));
    $A->get("/cart/$P");
    check(11, 'Pet Store cart has kibble and nothing from Grooming cart', $A->has('Kibble') && !$A->has('Oatmeal'));
    $A->form("/shop/$G", "/cart/$G/add", ['business_product_id' => $kibbleP, 'quantity' => '1']);
    check(11, "Pet Store product cannot be added to the Grooming cart (tampered request)", (int)scalar($pdo, 'SELECT COUNT(*) FROM cart_item ci JOIN cart c ON c.cart_id=ci.cart_id WHERE c.business_unit_id=? AND c.customer_id=? AND ci.business_product_id=?', [$G, $cidA, $kibbleP]) === 0);

    // --- 12 ------------------------------------------------------------
    echo "Test 12: cart persistence\n";
    $A->get('/pets');
    $A->get('/orders');
    $A->get("/cart/$G");
    check(12, 'cart survives navigating elsewhere', $A->has('Oatmeal Pet Shampoo'));
    logout($A);
    login($A, $emailA, $pw);
    $A->get("/cart/$G");
    $gOk = $A->has('Oatmeal Pet Shampoo') && $A->has('value="5"');
    $A->get("/cart/$P");
    check(12, 'both carts survive sign-out and sign-in with correct contents', $gOk && $A->has('Kibble'));

    // --- 13 + 14 -------------------------------------------------------
    echo "Test 13/14: pickup and delivery; server-side totals\n";
    $A->get("/checkout/$G");
    check(13, 'checkout offers pickup and delivery', $A->has('value="pickup"') && $A->has('value="delivery"'));
    $A->req('POST', "/checkout/$G", [
        'csrf_token' => $A->csrf(), 'fulfillment_method' => 'pickup',
        'total_amount' => '0.01', 'unit_price' => '0.01', 'selling_price' => '0.01', 'price' => ['x' => '0.01'],
    ]);
    $oid1 = (int)scalar($pdo, 'SELECT order_id FROM customer_order WHERE customer_id=? ORDER BY order_id DESC LIMIT 1', [$cidA]);
    check(13, 'pickup order is created', $oid1 > 0 && scalar($pdo, 'SELECT fulfillment_method FROM customer_order WHERE order_id=?', [$oid1]) === 'pickup' && $A->path() === "/orders/$oid1");
    check(14, 'browser-supplied prices/totals are ignored (5 x 12.50 = 62.50)', (float)scalar($pdo, 'SELECT total_amount FROM customer_order WHERE order_id=?', [$oid1]) === 62.50 && (float)scalar($pdo, 'SELECT unit_price FROM order_item WHERE order_id=?', [$oid1]) === 12.50);
    check(13, 'cart is emptied after a successful order', (int)scalar($pdo, 'SELECT COUNT(*) FROM cart_item ci JOIN cart c ON c.cart_id=ci.cart_id WHERE c.customer_id=? AND c.business_unit_id=?', [$cidA, $G]) === 0);
    $addG($shampooG, '1');
    $A->req('POST', "/checkout/$G", ['csrf_token' => $A->form("/checkout/$G", "/checkout/$G", ['fulfillment_method' => 'delivery'])->csrf(), 'fulfillment_method' => 'delivery']);
    check(13, 'delivery without an address is refused', $A->code === 422 && (int)scalar($pdo, 'SELECT COUNT(*) FROM customer_order WHERE customer_id=?', [$cidA]) === 1);
    $A->form("/checkout/$G", "/checkout/$G", ['fulfillment_method' => 'delivery', 'address_line1' => '1 Test Street', 'city' => 'Testville', 'postal_code' => 'T1T 1T1']);
    $oid2 = (int)scalar($pdo, 'SELECT order_id FROM customer_order WHERE customer_id=? ORDER BY order_id DESC LIMIT 1', [$cidA]);
    check(13, 'delivery order is created with the address', $oid2 > $oid1 && scalar($pdo, 'SELECT fulfillment_method FROM customer_order WHERE order_id=?', [$oid2]) === 'delivery' && scalar($pdo, 'SELECT delivery_city FROM customer_order WHERE order_id=?', [$oid2]) === 'Testville');
    $A->get('/orders');
    check(13, 'order history lists both orders', $A->has("/orders/$oid1") && $A->has("/orders/$oid2"));
    $A->get("/orders/$oid2");
    check(13, 'order details show items, fulfillment and total', $A->has('Oatmeal Pet Shampoo') && $A->has('Delivery') && $A->has('$12.50'));
    $B->get("/orders/$oid1");
    check(13, "another customer cannot view Alice's order (404)", $B->code === 404);

    // --- 15 ------------------------------------------------------------
    echo "Test 15: other business unit's products cannot be ordered\n";
    $addG($shampooG, '1');
    $cartG = (int)scalar($pdo, 'SELECT cart_id FROM cart WHERE customer_id=? AND business_unit_id=?', [$cidA, $G]);
    $pdo->prepare('INSERT INTO cart_item (cart_id, business_product_id, quantity) VALUES (?,?,1)')->execute([$cartG, $kibbleP]); // tampered row
    $before = (int)scalar($pdo, 'SELECT COUNT(*) FROM customer_order');
    $A->form("/checkout/$G", "/checkout/$G", ['fulfillment_method' => 'pickup']);
    check(15, 'checkout with a Pet Store product in the Grooming cart is rejected', $A->code === 422 && $A->has('does not belong to this shop'));
    check(15, 'no order was created', (int)scalar($pdo, 'SELECT COUNT(*) FROM customer_order') === $before);
    $pdo->prepare('DELETE FROM cart_item WHERE cart_id=? AND business_product_id=?')->execute([$cartG, $kibbleP]);

    // --- 16 ------------------------------------------------------------
    echo "Test 16: carts and orders do not reserve or deduct inventory\n";
    $invAfter = $pdo->query('SELECT SUM(on_hand) o, SUM(committed) c FROM inventory')->fetch();
    check(16, 'on_hand and committed totals are unchanged after carts and orders', $invAfter == $invBefore);
    check(16, 'no reservation or movement rows were written', (int)scalar($pdo, 'SELECT COUNT(*) FROM inventory_reservation') === 0 && (int)scalar($pdo, 'SELECT COUNT(*) FROM inventory_movement') === 0);
    $pdo->prepare('INSERT INTO cart_item (cart_id, business_product_id, quantity) VALUES (?,?,1)')->execute([$cartG, $condG]);
    $before = (int)scalar($pdo, 'SELECT COUNT(*) FROM customer_order');
    $A->form("/checkout/$G", "/checkout/$G", ['fulfillment_method' => 'pickup']);
    check(16, 'server re-validates stock: out-of-stock item blocks checkout', $A->code === 422 && $A->has('Not enough stock') && (int)scalar($pdo, 'SELECT COUNT(*) FROM customer_order') === $before);
    $pdo->prepare('DELETE FROM cart_item WHERE cart_id=? AND business_product_id=?')->execute([$cartG, $condG]);

    // --- 17 ------------------------------------------------------------
    echo "Test 17: database failure gives no false success and no partial order\n";
    $itemG = (int)scalar($pdo, 'SELECT cart_item_id FROM cart_item WHERE cart_id=? AND business_product_id=?', [$cartG, $shampooG]);
    $A->form("/cart/$G", "/cart/$G/update", ['cart_item_id' => $itemG, 'quantity' => '7']);
    $pdo->exec('ALTER TABLE order_item ADD CONSTRAINT chk_test_fail CHECK (quantity <> 7)');
    $constraintAdded = true;
    $ordersNow = (int)scalar($pdo, 'SELECT COUNT(*) FROM customer_order');
    $orderItemsNow = (int)scalar($pdo, 'SELECT COUNT(*) FROM order_item');
    $A->form("/checkout/$G", "/checkout/$G", ['fulfillment_method' => 'pickup']);
    check(17, 'a mid-transaction failure returns HTTP 500 with no success message', $A->code === 500 && !$A->has('submitted') && !str_contains($A->body, 'SQLSTATE'));
    check(17, 'the order header insert was rolled back (no partial order)', (int)scalar($pdo, 'SELECT COUNT(*) FROM customer_order') === $ordersNow && (int)scalar($pdo, 'SELECT COUNT(*) FROM order_item') === $orderItemsNow);
    check(17, 'cart contents are preserved after the failure', (int)scalar($pdo, 'SELECT quantity FROM cart_item WHERE cart_item_id=?', [$itemG]) === 7);
} finally {
    if ($constraintAdded) {
        try { $pdo->exec('ALTER TABLE order_item DROP CHECK chk_test_fail'); } catch (Throwable $e) { echo "WARN could not drop test constraint\n"; }
    }
    cleanup($pdo);
}

// ---- report ------------------------------------------------------------
$names = [
    1 => 'A new customer can register and sign in', 2 => 'Invalid email rejected before the password step',
    3 => 'Incorrect credentials do not reveal registered emails', 4 => 'Signed-in customer can register multiple pets',
    5 => "Customer cannot view or edit another customer's pets", 6 => 'Customer can submit a grooming appointment request',
    7 => "Request appears in the Grooming receptionist's appointment management", 8 => "Receptionist cannot access another business unit's records",
    9 => 'Customer can browse Grooming Services products', 10 => 'Customer can add, update and remove cart items',
    11 => 'Cart A and Cart B remain separate', 12 => 'Cart persists after navigating away, signing out and in',
    13 => 'Customer can select pickup or delivery at checkout', 14 => 'Checkout recalculates prices and totals on the server',
    15 => "Customer cannot order another business unit's products", 16 => 'Cart contents do not reserve or deduct inventory',
    17 => 'Database failures: no false success, no partial order',
];
$md = "# Acceptance test report\n\nGenerated: " . date('c') . " by `php tests/acceptance.php` against `" . BASE . "`.\n\n| # | Scenario | Result | Checks |\n|---|---|---|---|\n";
$failed = 0;
ksort($results);
foreach ($names as $n => $name) {
    $rows = $results[$n] ?? [];
    $pass = $rows && !in_array(false, array_column($rows, 1), true);
    $failed += $pass ? 0 : 1;
    $okc = count(array_filter(array_column($rows, 1)));
    $md .= "| $n | $name | " . ($pass ? 'PASS' : 'FAIL') . " | $okc/" . count($rows) . " |\n";
}
$md .= "\n## Failed checks\n\n";
$anyFail = false;
foreach ($results as $n => $rows) {
    foreach ($rows as [$label, $ok]) {
        if (!$ok) { $md .= "- [$n] $label\n"; $anyFail = true; }
    }
}
if (!$anyFail) $md .= "None.\n";
file_put_contents(__DIR__ . '/TEST_REPORT.md', $md);
echo "\n" . ($failed === 0 ? 'ALL 17 SCENARIOS PASSED' : "$failed SCENARIO(S) FAILED") . "\nReport: tests/TEST_REPORT.md\n";
exit($failed === 0 ? 0 : 1);
