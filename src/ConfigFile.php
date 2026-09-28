<?php
declare(strict_types=1);

namespace Calage;

/**
 * Writes config.php for the setup wizard and the Settings page.
 *
 * The file stays a readable, commented PHP array that can be edited by hand. Paths inside the
 * application are written relative to it (__DIR__ . '/data/…') so it can be moved;
 * any key added by hand is kept.
 */
final class ConfigFile
{
    /** Comments written above the known keys. */
    private const COMMENTS = [
        'base_url' => 'Public address of Calage, without a trailing slash (subfolder included).',
        'locale' => 'Interface language: en | fr. The share page follows the visitor\'s browser.',
        'admin' => 'Back-office account. Password: password_hash() hash, never in plain text.',
        'smtp' => 'Sending previews by email. encryption: tls | ssl | none.',
        'paths' => 'Database and temporary files (sessions, uploads, pending imports).',
        'timezone' => 'Time zone of the displayed dates (dates are stored in UTC).',
        'debug' => 'true to display PHP errors (development only).',
    ];

    public function __construct(private string $path, private string $appRoot)
    {
    }

    public static function forApp(): self
    {
        return new self(APP_ROOT . '/config.php', APP_ROOT);
    }

    /** Can PHP create or modify config.php? */
    public function isWritable(): bool
    {
        return is_file($this->path) ? is_writable($this->path) : is_writable(dirname($this->path));
    }

    /** Default configuration, completed with $values (arrays merged recursively). */
    public function defaults(array $values = []): array
    {
        return array_replace_recursive([
            'base_url' => '',
            'locale' => Lang::DEFAULT,
            'admin' => ['username' => '', 'password_hash' => ''],
            'smtp' => [
                'host' => '', 'port' => 587, 'encryption' => 'tls', 'username' => '', 'password' => '',
                'from_email' => '', 'from_name' => '',
            ],
            'paths' => ['database' => $this->appRoot . '/data/app.sqlite', 'tmp' => $this->appRoot . '/data/tmp'],
            'timezone' => 'Europe/Paris',
            'debug' => false,
        ], $values);
    }

    /** Writes the configuration (temporary file then rename: never a half-written file). */
    public function write(array $config): void
    {
        $tmp = $this->path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $this->render($config), LOCK_EX) === false || !@rename($tmp, $this->path)) {
            @unlink($tmp);
            throw new \RuntimeException(__('Cannot write {file}: PHP cannot modify the Calage folder.', ['file' => basename($this->path)]));
        }
        @chmod($this->path, 0640);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($this->path, true);
        }
    }

    /** Content of config.php for this configuration. */
    public function render(array $config): string
    {
        $out = "<?php\n"
            . "// Calage configuration, written by the setup wizard or the Settings page.\n"
            . "// This file can also be edited by hand (see config.example.php).\n"
            . "return [\n";
        foreach ($config as $key => $value) {
            if (isset(self::COMMENTS[$key])) {
                $out .= "\n    // " . self::COMMENTS[$key] . "\n";
            }
            $out .= '    ' . var_export($key, true) . ' => ' . $this->export($value, 1) . ",\n";
        }
        return $out . "];\n";
    }

    private function export(mixed $value, int $depth): string
    {
        if (is_array($value)) {
            if ($value === []) {
                return '[]';
            }
            $indent = str_repeat('    ', $depth + 1);
            $list = array_is_list($value);
            $lines = [];
            foreach ($value as $k => $v) {
                $lines[] = $indent . ($list ? '' : var_export($k, true) . ' => ') . $this->export($v, $depth + 1) . ',';
            }
            return "[\n" . implode("\n", $lines) . "\n" . str_repeat('    ', $depth) . ']';
        }
        // Path inside the application: relative, so the folder can be moved.
        if (is_string($value) && str_starts_with($value, $this->appRoot . '/')) {
            return '__DIR__ . ' . var_export(substr($value, strlen($this->appRoot)), true);
        }
        return var_export($value, true);
    }
}
