<?php
declare(strict_types=1);

// PHP built-in server: let it serve existing static files (CSS, JS, images) directly.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if ($file !== __DIR__ . '/' && is_file($file)) {
        return false;
    }
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Router;

$r = new Router();

$r->get('/', 'HomeController', 'index');
$r->get('/search', 'HomeController', 'search');
$r->get('/products', 'ShopController', 'index');
$r->get('/services', 'HomeController', 'services');
$r->get('/staffs', 'HomeController', 'staffs');
$r->get('/reviews', 'HomeController', 'reviews');
$r->post('/reviews', 'HomeController', 'storeReview');

// Customer authentication (single-page progressive disclosure, email-first)
$r->get('/login', 'AuthController', 'loginEmailForm');
$r->post('/login', 'AuthController', 'loginPassword');
$r->post('/login/email', 'AuthController', 'loginEmail');
$r->get('/login/password', 'AuthController', 'loginPasswordForm');
$r->post('/login/password', 'AuthController', 'loginPassword');
$r->get('/register', 'AuthController', 'registerEmailForm');
$r->post('/register', 'AuthController', 'registerDetails');
$r->post('/register/email', 'AuthController', 'registerEmail');
$r->get('/register/details', 'AuthController', 'registerDetailsForm');
$r->post('/register/details', 'AuthController', 'registerDetails');
$r->post('/logout', 'AuthController', 'logout');

// Profile + pets
$r->get('/profile', 'ProfileController', 'show');
$r->post('/profile', 'ProfileController', 'update');
$r->get('/pets', 'PetController', 'index');
$r->get('/pets/new', 'PetController', 'create');
$r->post('/pets', 'PetController', 'store');
$r->get('/pets/{id}/edit', 'PetController', 'edit');
$r->post('/pets/{id}', 'PetController', 'update');

// Grooming appointments (customer)
$r->get('/appointments', 'AppointmentController', 'index');
$r->get('/appointments/new', 'AppointmentController', 'create');
$r->post('/appointments', 'AppointmentController', 'store');
$r->get('/appointments/{id}', 'AppointmentController', 'show');

// Shop, carts, orders
$r->get('/shop', 'ShopController', 'index');
$r->get('/shop/{bu}', 'ShopController', 'catalog');
$r->get('/shop/{bu}/product/{id}', 'ShopController', 'product');
$r->get('/cart/{bu}', 'CartController', 'show');
$r->post('/cart/{bu}/add', 'CartController', 'add');
$r->post('/cart/{bu}/update', 'CartController', 'update');
$r->post('/cart/{bu}/remove', 'CartController', 'remove');
$r->get('/checkout/{bu}', 'OrderController', 'checkout');
$r->post('/checkout/{bu}', 'OrderController', 'place');
$r->get('/orders', 'OrderController', 'index');
$r->get('/orders/{id}', 'OrderController', 'show');

// Staff (receptionist)
$r->get('/staff/login', 'StaffController', 'loginForm');
$r->post('/staff/login', 'StaffController', 'login');
$r->get('/staff/appointments', 'StaffController', 'appointments');
$r->get('/staff/appointments/{id}', 'StaffController', 'appointment');
$r->post('/staff/appointments/{id}/status', 'StaffController', 'setStatus');

$r->dispatch($_SERVER['REQUEST_URI'] ?? '/', $_SERVER['REQUEST_METHOD'] ?? 'GET');

