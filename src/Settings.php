<?php
declare(strict_types=1);

namespace Calage;

/**
 * Reading and checking of the settings entered in the setup wizard and on the Settings page.
 * Each method returns [cleaned values, errors by field].
 */
final class Settings
{
    public const PASSWORD_MIN = 10;

    /** @return array{0: string, 1: array<string, string>} */
    public static function baseUrl(string $input): array
    {
        $url = rtrim(trim($input), '/');
        $parts = parse_url($url);
        if ($url === '' || $parts === false || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host'])) {
            return [$url, ['base_url' => __('Enter a full address, for example https://my-domain.com/calage.')]];
        }
        if (isset($parts['query']) || isset($parts['fragment'])) {
            return [$url, ['base_url' => __('The address must not contain “?” or “#”.')]];
        }
        return [$url, []];
    }

    /** @return array{0: array{username: string, password: string}, 1: array<string, string>} */
    public static function account(string $username, string $password, string $confirm): array
    {
        $username = trim($username);
        $errors = [];
        if ($username === '' || mb_strlen($username) > 100) {
            $errors['username'] = __('Choose a username (100 characters at most).');
        }
        $errors += self::password($password, $confirm);
        return [['username' => $username, 'password' => $password], $errors];
    }

    /** @return array{0: string, 1: array<string, string>} */
    public static function locale(string $input): array
    {
        return Lang::isSupported($input) ? [$input, []] : [Lang::DEFAULT, ['locale' => __('Choose a language from the list.')]];
    }

    /** @return array<string, string> */
    public static function password(string $password, string $confirm): array
    {
        if (mb_strlen($password) < self::PASSWORD_MIN) {
            return ['password' => __('The password must be at least {n} characters long.', ['n' => self::PASSWORD_MIN])];
        }
        if ($password !== $confirm) {
            return ['password_confirm' => __('The two passwords do not match.')];
        }
        return [];
    }

    /**
     * SMTP settings as entered. Password left empty: the one in $current is kept (never shown again).
     * Empty server: SMTP disabled, the rest is ignored.
     * @return array{0: array, 1: array<string, string>}
     */
    public static function smtp(array $input, array $current = []): array
    {
        $smtp = [
            'host' => trim((string) ($input['host'] ?? '')),
            'port' => (int) ($input['port'] ?? 587),
            'encryption' => (string) ($input['encryption'] ?? 'tls'),
            'username' => trim((string) ($input['username'] ?? '')),
            'password' => (string) ($input['password'] ?? '') !== '' ? (string) $input['password'] : (string) ($current['password'] ?? ''),
            'from_email' => trim((string) ($input['from_email'] ?? '')),
            'from_name' => trim((string) ($input['from_name'] ?? '')),
        ];
        if ($smtp['host'] === '') {
            return [array_merge($smtp, ['password' => '']), []];
        }
        $errors = [];
        if (!preg_match('/^[a-z0-9.-]+$/i', $smtp['host'])) {
            $errors['host'] = __('Invalid server name.');
        }
        if ($smtp['port'] < 1 || $smtp['port'] > 65535) {
            $errors['port'] = __('Invalid port (587 with TLS, 465 with SSL).');
        }
        if (!in_array($smtp['encryption'], ['tls', 'ssl', 'none'], true)) {
            $errors['encryption'] = __('Invalid encryption.');
        }
        if (filter_var($smtp['from_email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors['from_email'] = __('Enter the sender address for emails.');
        }
        return [$smtp, $errors];
    }
}
