<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

require APP_ROOT . '/app/Core/Core.php';

spl_autoload_register(function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        $file = APP_ROOT . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

function e(mixed $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function money(mixed $v): string
{
    return '$' . number_format((float)$v, 2);
}

set_exception_handler(function (Throwable $t): void {
    $dir = APP_ROOT . '/storage/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    @file_put_contents($dir . '/app.log', '[' . date('c') . '] ' . get_class($t) . ': ' . $t->getMessage() . ' @ ' . $t->getFile() . ':' . $t->getLine() . "\n", FILE_APPEND);
    if (!headers_sent()) {
        http_response_code(500);
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    App\Core\Controller::renderStatic('errors/error', ['code' => 500, 'message' => 'Something went wrong on our side. Nothing was saved. Please try again.']);
});

App\Core\Session::start();

