<?php
declare(strict_types=1);

use Calage\ConfigFile;
use Calage\Settings;

test('config.php: written, read back identical, paths relative to the application', function (): void {
    $root = realpath(temp_dir()); // __DIR__ always gives the real path (/private/var… on macOS)
    $file = new ConfigFile("$root/config.php", $root);
    $config = $file->defaults([
        'base_url' => 'https://example.com/calage',
        'admin' => ['username' => 'martin', 'password_hash' => password_hash('motdepasse-long', PASSWORD_DEFAULT)],
        'smtp' => ['host' => 'smtp.example.com', 'password' => "d'apostrophe \"et\" \\ antislash", 'from_email' => 'n@example.com'],
        'custom' => ['key' => [1, 2, 3]], // key added by hand: kept
    ]);
    $file->write($config);

    $php = file_get_contents("$root/config.php");
    check(str_contains($php, "'database' => __DIR__ . '/data/app.sqlite'"), 'path relative to the application');
    check(str_contains($php, '// Public address of Calage'), 'commented file');
    check_same($config, require "$root/config.php");
    check_same('0640', substr(sprintf('%o', fileperms("$root/config.php")), -4), 'restricted permissions');
    check_same([], glob("$root/*.tmp"), 'no temporary file left');

    // Moving the application: the paths follow.
    $moved = realpath(temp_dir());
    copy("$root/config.php", "$moved/config.php");
    check_same("$moved/data/app.sqlite", (require "$moved/config.php")['paths']['database']);
});

test('config.php: path outside the application kept as is', function (): void {
    $root = temp_dir();
    $file = new ConfigFile("$root/config.php", $root);
    $php = $file->render($file->defaults(['paths' => ['database' => '/srv/prive/calage.sqlite']]));
    check(str_contains($php, "'database' => '/srv/prive/calage.sqlite'"));
});

test('config.php: folder not writable → clear error, nothing written', function (): void {
    $root = temp_dir();
    mkdir("$root/verrou", 0555);
    $file = new ConfigFile("$root/verrou/config.php", $root);
    check(!$file->isWritable());
    try {
        $file->write($file->defaults());
        check(false, 'exception expected');
    } catch (RuntimeException $e) {
        check(str_contains($e->getMessage(), 'cannot modify'));
    }
    check_same([], glob("$root/verrou/*"));
    chmod("$root/verrou", 0755);
});

test('settings: public address', function (): void {
    check_same(['https://example.com/calage', []], Settings::baseUrl(' https://example.com/calage/ '));
    check_same([], Settings::baseUrl('http://localhost:8000')[1]);
    check(isset(Settings::baseUrl('example.com')[1]['base_url']), 'no scheme');
    check(isset(Settings::baseUrl('ftp://example.com')[1]['base_url']), 'wrong scheme');
    check(isset(Settings::baseUrl('https://example.com/?x=1')[1]['base_url']), 'parameters');
});

test('settings: account and password', function (): void {
    check_same([], Settings::account('martin', 'motdepasse-long', 'motdepasse-long')[1]);
    check(isset(Settings::account('', 'motdepasse-long', 'motdepasse-long')[1]['username']));
    check(isset(Settings::account('martin', 'court', 'court')[1]['password']));
    check(isset(Settings::account('martin', 'motdepasse-long', 'autre-chose-long')[1]['password_confirm']));
});

test('settings: SMTP, password kept when the field is left empty', function (): void {
    $current = ['password' => 'secret-actuel'];
    [$smtp, $errors] = Settings::smtp(['host' => 'smtp.example.com', 'port' => '465', 'encryption' => 'ssl', 'from_email' => 'n@example.com', 'password' => ''], $current);
    check_same([], $errors);
    check_same(['secret-actuel', 465, 'ssl'], [$smtp['password'], $smtp['port'], $smtp['encryption']]);

    [$smtp] = Settings::smtp(['host' => 'smtp.example.com', 'from_email' => 'n@example.com', 'password' => 'nouveau'], $current);
    check_same('nouveau', $smtp['password']);

    [$smtp, $errors] = Settings::smtp(['host' => ''], $current);
    check_same(['', []], [$smtp['password'], $errors], 'empty server: SMTP disabled, password cleared');

    $errors = Settings::smtp(['host' => 'smtp example', 'port' => '99999', 'encryption' => 'starttls', 'from_email' => 'x'])[1];
    check_same(['host', 'port', 'encryption', 'from_email'], array_keys($errors));
});
