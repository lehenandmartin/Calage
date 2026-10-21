<?php
declare(strict_types=1);

use Calage\Storage\Protection;

test('protection: the copies in the code match the files of the repository', function (): void {
    foreach (Protection::FILES as $path => $content) {
        check_same(file_get_contents(APP_ROOT . '/' . $path), $content, $path);
    }
});

test('protection: missing files put back, existing ones left as they are', function (): void {
    $root = temp_dir();
    mkdir("$root/data");
    mkdir("$root/i");
    file_put_contents("$root/i/.htaccess", 'custom');
    Protection::restore($root);
    check_same(Protection::FILES['data/.htaccess'], file_get_contents("$root/data/.htaccess"));
    check_same(Protection::FILES['data/probe.txt'], file_get_contents("$root/data/probe.txt"));
    check_same('custom', file_get_contents("$root/i/.htaccess"));
});

test('protection: missing folder left alone', function (): void {
    $root = temp_dir();
    Protection::restore($root);
    check_same([], glob("$root/*"));
});
