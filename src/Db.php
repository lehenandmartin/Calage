<?php
declare(strict_types=1);

namespace Calage;

use PDO;

final class Db
{
    public static function connect(string $file): PDO
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create the database folder: $dir");
        }

        $pdo = new PDO('sqlite:' . $file, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA foreign_keys = ON');

        self::migrate($pdo);
        return $pdo;
    }

    /**
     * Updates the schema step by step (PRAGMA user_version = last step applied).
     * schema.sql is step 1; each change is added at the end of the list, without modifying the previous ones.
     */
    private static function migrate(PDO $pdo): void
    {
        $steps = [
            1 => fn() => $pdo->exec(file_get_contents(APP_ROOT . '/schema.sql')),
            // MJML source of a version (NULL for an HTML newsletter); the compiled HTML stays the reference.
            2 => fn() => $pdo->exec('ALTER TABLE versions ADD COLUMN mjml TEXT'),
            // 1 = preheader generated from the content (left empty by the user), recomputed on every save.
            3 => fn() => $pdo->exec('ALTER TABLE versions ADD COLUMN preheader_auto INTEGER NOT NULL DEFAULT 0'),
        ];
        $current = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
        foreach ($steps as $version => $apply) {
            if ($version <= $current) {
                continue;
            }
            $pdo->beginTransaction();
            try {
                $apply();
                $pdo->exec('PRAGMA user_version = ' . $version);
                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        }
    }
}
