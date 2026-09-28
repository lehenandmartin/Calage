<?php
declare(strict_types=1);

namespace Calage;

/** A single account, defined in config.php. */
final class Auth
{
    public static function check(): bool
    {
        Session::start();
        return ($_SESSION['auth'] ?? false) === true;
    }

    public static function attempt(string $username, string $password): bool
    {
        $admin = App::config()['admin'];
        // Both checks always run, so as not to reveal which one is wrong.
        $userOk = hash_equals((string) $admin['username'], $username);
        $passOk = password_verify($password, (string) $admin['password_hash']);
        if (!$userOk || !$passOk) {
            usleep(random_int(400_000, 900_000)); // slows down repeated attempts
            return false;
        }

        Session::start();
        session_regenerate_id(true);
        $_SESSION['auth'] = true;
        return true;
    }

    /** Account password, to confirm a sensitive action (changing the password). */
    public static function verifyPassword(string $password): bool
    {
        return password_verify($password, (string) (App::config()['admin']['password_hash'] ?? ''));
    }

    /** Opens the session without a password: right after setup, by the wizard. */
    public static function loginAfterSetup(): void
    {
        Session::start();
        session_regenerate_id(true);
        $_SESSION['auth'] = true;
    }

    public static function logout(): void
    {
        Session::start();
        $_SESSION = [];
        session_regenerate_id(true);
    }
}
