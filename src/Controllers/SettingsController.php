<?php
declare(strict_types=1);

namespace Calage\Controllers;

use Calage\App;
use Calage\Auth;
use Calage\ConfigFile;
use Calage\Lang;
use Calage\Mailer;
use Calage\Session;
use Calage\Settings;
use Calage\View;

/**
 * Settings page: language, public address, account, email sending. Changes rewrite config.php
 * (see ConfigFile); when PHP cannot write it, the settings are shown read-only.
 */
final class SettingsController
{
    public function show(array $params): void
    {
        $this->render([], []);
    }

    public function saveLanguage(array $params): void
    {
        [$locale, $errors] = Settings::locale((string) ($_POST['locale'] ?? ''));
        if ($errors !== []) {
            $this->render([], $errors);
            return;
        }
        Lang::set($locale); // the confirmation is already in the new language
        $this->save(['locale' => $locale], __('Language saved.'));
    }

    public function saveAddress(array $params): void
    {
        [$baseUrl, $errors] = Settings::baseUrl((string) ($_POST['base_url'] ?? ''));
        if ($errors !== []) {
            $this->render(['base_url' => $baseUrl], $errors);
            return;
        }
        $this->save(['base_url' => $baseUrl], __('Public address saved.'));
    }

    public function saveAccount(array $params): void
    {
        $config = App::config();
        $errors = [];
        if (!Auth::verifyPassword((string) ($_POST['current_password'] ?? ''))) {
            $errors['current_password'] = __('Incorrect current password.');
        }
        $username = trim((string) ($_POST['username'] ?? ''));
        if ($username === '' || mb_strlen($username) > 100) {
            $errors['username'] = __('Choose a username (100 characters at most).');
        }
        $password = (string) ($_POST['password'] ?? '');
        $admin = ['username' => $username, 'password_hash' => $config['admin']['password_hash']];
        if ($password !== '') {
            // New password: optional, the username alone can be changed.
            $errors += Settings::password($password, (string) ($_POST['password_confirm'] ?? ''));
            $admin['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        }
        if ($errors !== []) {
            usleep(random_int(300_000, 600_000)); // slows down guesses of the current password
            $this->render(['username' => $username], $errors);
            return;
        }
        $this->save(['admin' => $admin], $password !== '' ? __('Account and password saved.') : __('Username saved.'));
    }

    public function saveSmtp(array $params): void
    {
        [$smtp, $errors] = Settings::smtp((array) ($_POST['smtp'] ?? []), App::config()['smtp'] ?? []);
        if ($errors !== []) {
            $this->render(['smtp' => $smtp], self::prefix($errors, 'smtp.'));
            return;
        }
        $this->save(['smtp' => $smtp], $smtp['host'] === '' ? __('Email sending disabled.') : __('Email settings saved.'));
    }

    /** "Send a test email" with the settings as entered (not saved yet). JSON response. */
    public function smtpTest(array $params): void
    {
        self::smtpTestResponse(App::config()['smtp'] ?? []);
    }

    /**
     * SMTP test shared by the wizard and the Settings page: smtp[…] and to fields of the form,
     * empty password = the one in $current. JSON {ok, message, log}.
     */
    public static function smtpTestResponse(array $current): never
    {
        $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
        [$smtp, $errors] = Settings::smtp((array) ($body['smtp'] ?? []), $current);
        $to = trim((string) ($body['to'] ?? ''));
        if ($smtp['host'] === '') {
            json_response(['ok' => false, 'message' => __('Enter the SMTP server first.')]);
        }
        if ($errors !== []) {
            json_response(['ok' => false, 'message' => implode(' ', $errors)]);
        }
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            json_response(['ok' => false, 'message' => __('Enter the address that should receive the test email.')]);
        }
        $log = [];
        $error = (new Mailer($smtp))->sendTest($to, $log);
        json_response($error === null
            ? ['ok' => true, 'message' => __('Test email sent to {address}. Remember to check the spam folder.', ['address' => $to])]
            : ['ok' => false, 'message' => $error, 'log' => $log]);
    }

    /** Merges $changes into config.php and goes back to the Settings page. */
    private function save(array $changes, string $message): void
    {
        $file = ConfigFile::forApp();
        if (!$file->isWritable()) {
            Session::flash('error', __('PHP cannot modify config.php: edit it by hand.'));
            redirect('/settings');
        }
        $config = App::config();
        foreach ($changes as $key => $value) {
            $config[$key] = $value; // whole section replaced (no merge: an emptied field stays empty)
        }
        $file->write($file->defaults($config));
        Session::flash('success', $message);
        redirect('/settings');
    }

    private function render(array $values, array $errors): void
    {
        $config = App::config();
        $smtp = ($values['smtp'] ?? null) ?? ($config['smtp'] ?? []);
        View::render('settings', [
            'title' => __('Settings'),
            'locale' => Lang::configured(),
            'navCurrent' => 'settings',
            'writable' => ConfigFile::forApp()->isWritable(),
            'baseUrl' => $values['base_url'] ?? (string) ($config['base_url'] ?? ''),
            'detectedBaseUrl' => App::detectedBaseUrl(),
            'localBaseUrl' => self::isLocalBaseUrl(),
            'username' => $values['username'] ?? (string) ($config['admin']['username'] ?? ''),
            'smtp' => $smtp + ['host' => '', 'port' => 587, 'encryption' => 'tls', 'username' => '', 'from_email' => '', 'from_name' => ''],
            'hasSmtpPassword' => (string) ($config['smtp']['password'] ?? '') !== '',
            'smtpConfigured' => Mailer::fromConfig()->isConfigured(),
            'errors' => $errors,
        ]);
    }

    private static function prefix(array $errors, string $prefix): array
    {
        return array_combine(array_map(fn(string $k): string => $prefix . $k, array_keys($errors)), array_values($errors));
    }

    /** base_url on localhost or a private IP: the images in emails will not be visible outside this machine. */
    public static function isLocalBaseUrl(): bool
    {
        $host = (string) parse_url((string) (App::config()['base_url'] ?? ''), PHP_URL_HOST);
        return $host === '' || $host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.test')
            || (filter_var($host, FILTER_VALIDATE_IP) !== false
                && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false);
    }
}
