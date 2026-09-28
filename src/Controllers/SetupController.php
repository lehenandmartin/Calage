<?php
declare(strict_types=1);

namespace Calage\Controllers;

use Calage\App;
use Calage\Auth;
use Calage\ConfigFile;
use Calage\Lang;
use Calage\Session;
use Calage\Settings;
use Calage\View;

/**
 * Setup wizard: environment, address and account, email sending, summary.
 * Reachable only until Calage is set up (see Router, "setup" option);
 * writes config.php, then opens the session.
 *
 * Language: the browser's, until one is chosen in the wizard (?lang=, then the "locale" field).
 */
final class SetupController
{
    public function __construct()
    {
        $chosen = (string) ($_POST['locale'] ?? $_GET['lang'] ?? '');
        if (Lang::isSupported($chosen)) {
            Lang::set($chosen);
        }
    }
    public function show(array $params): void
    {
        $this->render(self::initialValues(), []);
    }

    public function install(array $params): void
    {
        $existing = App::rawConfig() ?? [];
        [$baseUrl, $errors] = Settings::baseUrl((string) ($_POST['base_url'] ?? ''));
        [$account, $accountErrors] = Settings::account(
            (string) ($_POST['username'] ?? ''),
            (string) ($_POST['password'] ?? ''),
            (string) ($_POST['password_confirm'] ?? '')
        );
        [$smtp, $smtpErrors] = Settings::smtp((array) ($_POST['smtp'] ?? []), $existing['smtp'] ?? []);
        $errors += $accountErrors + array_combine(
            array_map(fn(string $k): string => 'smtp.' . $k, array_keys($smtpErrors)),
            array_values($smtpErrors)
        );

        $values = ['base_url' => $baseUrl, 'username' => $account['username'], 'smtp' => $smtp];
        if (self::blockingChecks() !== []) {
            $errors['env'] = __('The environment does not allow installing Calage yet (see step 1).');
        }
        if ($errors !== []) {
            $this->render($values, $errors);
            return;
        }

        $file = ConfigFile::forApp();
        $config = $file->defaults(array_replace_recursive($existing, [
            'base_url' => $baseUrl,
            'locale' => Lang::current(),
            'admin' => ['username' => $account['username'], 'password_hash' => password_hash($account['password'], PASSWORD_DEFAULT)],
            'smtp' => $smtp,
        ]));
        try {
            $file->write($config);
        } catch (\RuntimeException $e) {
            // Folder not writable: the content is displayed, to be uploaded by hand.
            View::render('setup-manual', ['title' => __('Setup'), 'configContent' => $file->render($config)]);
            return;
        }

        Auth::loginAfterSetup();
        Session::flash('success', __('Calage is installed. Welcome!'));
        redirect('/');
    }

    /** "Send a test email" with the settings as entered, before saving them. JSON response. */
    public function smtpTest(array $params): void
    {
        SettingsController::smtpTestResponse(App::rawConfig()['smtp'] ?? []);
    }

    private function render(array $values, array $errors): void
    {
        View::render('setup', [
            'title' => __('Setup'),
            'checks' => self::checks(),
            'blocking' => self::blockingChecks() !== [],
            'writable' => ConfigFile::forApp()->isWritable(),
            'values' => $values,
            'errors' => $errors,
            'hasSmtpPassword' => (App::rawConfig()['smtp']['password'] ?? '') !== '',
        ]);
    }

    /** Starting values: a partial config.php if any, otherwise the detected address. */
    private static function initialValues(): array
    {
        $existing = App::rawConfig() ?? [];
        $smtp = ($existing['smtp'] ?? []) + ['host' => '', 'port' => 587, 'encryption' => 'tls', 'username' => '', 'from_email' => '', 'from_name' => ''];
        // Example values from config.example.php are not suggested.
        if (in_array($smtp['host'], ['smtp.example.com', 'smtp.exemple.fr'], true)) {
            $smtp = ['host' => '', 'port' => 587, 'encryption' => 'tls', 'username' => '', 'from_email' => '', 'from_name' => ''];
        }
        $baseUrl = (string) ($existing['base_url'] ?? '');
        return [
            'base_url' => $baseUrl === '' || str_contains($baseUrl, 'your-domain.com') || str_contains($baseUrl, 'ton-domaine.fr') ? App::detectedBaseUrl() : $baseUrl,
            'username' => (string) ($existing['admin']['username'] ?? ''),
            'smtp' => $smtp,
        ];
    }

    /** @return list<array{label: string, ok: bool, blocking: bool}> */
    private static function checks(): array
    {
        $dataDir = APP_ROOT . '/data';
        return [
            ['label' => __('PHP 8.1 or later (current: {version})', ['version' => PHP_VERSION]), 'ok' => PHP_VERSION_ID >= 80100, 'blocking' => true],
            ['label' => __('{name} extension', ['name' => 'pdo_sqlite']), 'ok' => extension_loaded('pdo_sqlite'), 'blocking' => true],
            ['label' => __('{name} extension', ['name' => 'zip (ZipArchive)']), 'ok' => class_exists(\ZipArchive::class), 'blocking' => true],
            ['label' => __('{name} extension', ['name' => 'mbstring']), 'ok' => extension_loaded('mbstring'), 'blocking' => true],
            ['label' => __('{folder} folder writable', ['folder' => 'data/']), 'ok' => is_dir($dataDir) && is_writable($dataDir), 'blocking' => true],
            ['label' => __('{folder} folder writable', ['folder' => 'i/']), 'ok' => is_dir(APP_ROOT . '/i') && is_writable(APP_ROOT . '/i'), 'blocking' => true],
            ['label' => __('config.php writable by PHP (otherwise, its content is to be uploaded by hand)'), 'ok' => ConfigFile::forApp()->isWritable(), 'blocking' => false],
            ['label' => __('{name} extension (encrypted SMTP)', ['name' => 'openssl']), 'ok' => extension_loaded('openssl'), 'blocking' => false],
        ];
    }

    /** @return list<array> checks that prevent the installation */
    private static function blockingChecks(): array
    {
        return array_values(array_filter(self::checks(), fn(array $c): bool => $c['blocking'] && !$c['ok']));
    }
}
