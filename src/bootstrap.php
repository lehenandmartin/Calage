<?php
declare(strict_types=1);

use Calage\App;
use Calage\Lang;
use Calage\View;

define('APP_ROOT', dirname(__DIR__));

spl_autoload_register(function (string $class): void {
    $prefix = 'Calage\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require __DIR__ . '/helpers.php';

App::init();

// Interface language: the one saved in the settings, or the browser's until Calage is set up.
// The share page and the setup wizard pick their own (ClientController, SetupController).
Lang::set(App::isConfigured() ? Lang::configured() : Lang::fromBrowser());

date_default_timezone_set(App::timezone());

ini_set('display_errors', App::debug() ? '1' : '0');
error_reporting(E_ALL);

set_error_handler(function (int $level, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $level)) {
        return false;
    }
    throw new ErrorException($message, 0, $level, $file, $line);
});

set_exception_handler(function (Throwable $e): void {
    error_log('[calage] ' . $e);
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $e . PHP_EOL);
        exit(1);
    }
    View::error(500, App::debug() ? (string) $e : '');
});
