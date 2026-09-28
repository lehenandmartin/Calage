<?php
declare(strict_types=1);

namespace Calage;

use PDO;

/** Configuration, database and paths of the application. */
final class App
{
    /** Calage version (semantic versioning: major.minor.patch). */
    public const VERSION = '1.0.1';
    public const REPOSITORY = 'https://github.com/lehenandmartin/Calage';

    private static ?array $config = null;
    private static ?PDO $db = null;
    private static string $basePath = '';

    public static function init(): void
    {
        $file = APP_ROOT . '/config.php';
        if (is_file($file)) {
            $config = require $file;
            self::$config = is_array($config) ? $config : null;
        }

        // URL prefix: '' at the root, '/calage' in a subfolder.
        // Under Apache, derived from the running script (index.php). With php -S, SCRIPT_NAME is the
        // requested file when it exists: the server's document root is used instead.
        if (PHP_SAPI === 'cli-server') {
            $dir = substr((string) realpath(APP_ROOT), strlen((string) realpath($_SERVER['DOCUMENT_ROOT'])));
        } else {
            $dir = dirname($_SERVER['SCRIPT_NAME'] ?? '/');
        }
        self::$basePath = rtrim(str_replace('\\', '/', $dir), '/.');
    }

    /** True when config.php exists and defines an admin account. */
    public static function isConfigured(): bool
    {
        $admin = self::$config['admin'] ?? [];
        return ($admin['username'] ?? '') !== '' && ($admin['password_hash'] ?? '') !== '';
    }

    /** Content of config.php as is, or null when it is missing (setup wizard). */
    public static function rawConfig(): ?array
    {
        return self::$config;
    }

    public static function config(): array
    {
        if (self::$config === null) {
            throw new \RuntimeException('config.php is missing or invalid.');
        }
        return self::$config;
    }

    public static function timezone(): string
    {
        $zone = (string) (self::$config['timezone'] ?? 'Europe/Paris');
        return in_array($zone, \DateTimeZone::listIdentifiers(), true) ? $zone : 'Europe/Paris';
    }

    public static function debug(): bool
    {
        return (bool) (self::$config['debug'] ?? false);
    }

    public static function db(): PDO
    {
        return self::$db ??= Db::connect(self::config()['paths']['database']);
    }

    /** Temporary folder (sessions, uploads). Usable even without a config. */
    public static function tmpDir(): string
    {
        return rtrim(self::$config['paths']['tmp'] ?? APP_ROOT . '/data/tmp', '/');
    }

    public static function basePath(): string
    {
        return self::$basePath;
    }

    /** Requested path, without the subfolder: always of the form '/…'. */
    public static function requestPath(): string
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $base = self::$basePath;
        if ($base !== '' && ($path === $base || str_starts_with($path, $base . '/'))) {
            $path = substr($path, strlen($base));
        }
        if ($path === '' || $path === '/index.php') {
            $path = '/';
        }
        return $path;
    }

    public static function url(string $path = '/'): string
    {
        return self::$basePath . '/' . ltrim($path, '/');
    }

    /** Absolute URL built from base_url (images, share link, emails). */
    public static function absoluteUrl(string $path = '/'): string
    {
        return rtrim((string) self::config()['base_url'], '/') . '/' . ltrim($path, '/');
    }

    /** Base URL as seen by the browser, to help fill in base_url. */
    public static function detectedBaseUrl(): string
    {
        $scheme = self::isHttps() ? 'https' : 'http';
        return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . self::$basePath;
    }

    public static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }
}
