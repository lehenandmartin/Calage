<?php
declare(strict_types=1);

// Tiny test runner, no PHPUnit.
//   php tests/run.php          all tests
//   php tests/run.php paths    only those whose name contains "paths"

if (PHP_SAPI !== 'cli') {
    exit;
}

require __DIR__ . '/../src/bootstrap.php';

// Tests expect English messages, whatever the language of the local install.
Calage\Lang::set('en');

final class TestFailure extends Exception
{
}

/** @var array<string, callable> $tests */
$tests = [];

function test(string $name, callable $fn): void
{
    global $tests;
    $tests[$name] = $fn;
}

function check(bool $condition, string $message = 'Condition is false'): void
{
    if (!$condition) {
        throw new TestFailure($message);
    }
}

function check_same(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected === $actual) {
        return;
    }
    if (is_string($expected) && is_string($actual)) {
        // First differing byte, with a little context.
        $i = 0;
        $max = min(strlen($expected), strlen($actual));
        while ($i < $max && $expected[$i] === $actual[$i]) {
            $i++;
        }
        $show = fn(string $s): string => json_encode(substr($s, max(0, $i - 40), 90), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        throw new TestFailure(trim("$message\n  difference at byte $i\n  expected: {$show($expected)}\n  actual:   {$show($actual)}"));
    }
    $dump = fn(mixed $v): string => json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    throw new TestFailure(trim("$message\n  expected: {$dump($expected)}\n  actual:   {$dump($actual)}"));
}

/** Temporary folder deleted at the end of the tests. */
function temp_dir(): string
{
    static $dirs = [];
    $dir = sys_get_temp_dir() . '/calage-tests-' . bin2hex(random_bytes(6));
    mkdir($dir, 0777, true);
    if ($dirs === []) {
        register_shutdown_function(function () use (&$dirs): void {
            foreach ($dirs as $d) {
                exec('rm -rf ' . escapeshellarg($d));
            }
        });
    }
    $dirs[] = $dir;
    return $dir;
}

/** Fresh SQLite database with the schema. */
function temp_db(): PDO
{
    return Calage\Db::connect(temp_dir() . '/test.sqlite');
}

foreach (glob(__DIR__ . '/*Test.php') as $file) {
    require $file;
}

$filter = $argv[1] ?? '';
$passed = 0;
$failed = 0;
foreach ($tests as $name => $fn) {
    if ($filter !== '' && stripos($name, $filter) === false) {
        continue;
    }
    try {
        $fn();
        $passed++;
        echo "  ✓ $name\n";
    } catch (Throwable $e) {
        $failed++;
        $detail = $e instanceof TestFailure ? $e->getMessage() : get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString();
        echo "  ✗ $name\n    " . str_replace("\n", "\n    ", $detail) . "\n";
    }
}

echo "\n" . ($failed === 0 ? "OK" : "FAILED") . " — $passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
