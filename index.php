<?php
declare(strict_types=1);

// Single entry point. Works at the root of a domain as well as in a subfolder.

require __DIR__ . '/src/bootstrap.php';

use Calage\App;

$path = App::requestPath();

// Development server (php -S): only assets/ and i/ are served as is.
// In production, the .htaccess takes care of it.
// Subfolders included (assets/vendor/), never a ".." segment.
if (PHP_SAPI === 'cli-server' && preg_match('#^/(assets|i)(/[\w-][\w.-]*)+$#', $path) && is_file(__DIR__ . $path)) {
    return false;
}

$router = require __DIR__ . '/src/routes.php';
$router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $path);
