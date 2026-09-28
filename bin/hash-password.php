<?php
declare(strict_types=1);

// Generates the hash to paste into config.php → admin.password_hash (manual setup).
// Usage: php bin/hash-password.php   (the password is read without echo)

if (PHP_SAPI !== 'cli') {
    exit;
}

$hasTty = function_exists('posix_isatty') ? posix_isatty(STDIN) : true;

fwrite(STDERR, 'Password: ');
if ($hasTty) {
    @shell_exec('stty -echo');
}
$password = rtrim((string) fgets(STDIN), "\r\n");
if ($hasTty) {
    @shell_exec('stty echo');
    fwrite(STDERR, PHP_EOL);
}

if (mb_strlen($password) < 10) {
    fwrite(STDERR, "At least 10 characters, please." . PHP_EOL);
    exit(1);
}

echo password_hash($password, PASSWORD_DEFAULT), PHP_EOL;
