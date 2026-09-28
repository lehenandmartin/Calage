<?php
declare(strict_types=1);

namespace Calage;

/**
 * PHP session, started on demand (share pages never open one).
 * Session files go to data/tmp/sessions: on shared hosting, the system folder
 * is often shared and cleaned up too early.
 */
final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $dir = App::tmpDir() . '/sessions';
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        if (is_dir($dir) && is_writable($dir)) {
            session_save_path($dir);
        }

        ini_set('session.use_strict_mode', '1');
        ini_set('session.gc_maxlifetime', '43200'); // 12 h of inactivity
        session_name('calage');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => App::basePath() . '/',
            'secure' => App::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    public static function flash(string $type, string $message): void
    {
        self::start();
        $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
    }

    /** @return list<array{type: string, message: string}> */
    public static function takeFlashes(): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return [];
        }
        $flashes = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        return $flashes;
    }
}
