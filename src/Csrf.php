<?php
declare(strict_types=1);

namespace Calage;

/** One token per session, checked on every POST (_csrf field or X-CSRF-Token header). */
final class Csrf
{
    public static function token(): string
    {
        Session::start();
        return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    public static function check(): bool
    {
        $sent = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        return is_string($sent) && $sent !== '' && hash_equals(self::token(), $sent);
    }
}
