<?php
declare(strict_types=1);

namespace Calage\Storage;

/**
 * Protection files of the folders Calage writes into, put back when they are missing: an FTP client
 * that skips hidden files, or an empty folder mounted by Docker. data/.htaccess keeps the database and
 * config.php away from the web, i/.htaccess sets the cache and the SVG sandbox of the hosted images,
 * probe.txt lets the dashboard check that data/ cannot be read from the web.
 * The copies in the repository stay the reference: tests/ProtectionTest.php checks they are identical.
 */
final class Protection
{
    public const FILES = [
        'data/.htaccess' => <<<'TXT'
        # Internal folder: never reachable from the web.
        <IfModule mod_authz_core.c>
            Require all denied
        </IfModule>
        <IfModule !mod_authz_core.c>
            Order allow,deny
            Deny from all
        </IfModule>

        TXT,
        'data/probe.txt' => <<<'TXT'
        Ce fichier ne doit PAS être lisible depuis le web.
        S'il l'est, le dossier data/ (base de données comprise) est exposé.

        TXT,
        'i/.htaccess' => <<<'TXT'
        # Images by content hash: the content behind a URL never changes.
        <IfModule mod_headers.c>
            Header set Cache-Control "public, max-age=31536000, immutable"
            Header set X-Content-Type-Options "nosniff"
            <FilesMatch "\.svg$">
                Header set Content-Security-Policy "sandbox"
            </FilesMatch>
        </IfModule>

        TXT,
    ];

    /** Writes the missing files. Silent when PHP cannot write: the setup wizard checks the folders. */
    public static function restore(string $root): void
    {
        foreach (self::FILES as $path => $content) {
            $file = $root . '/' . $path;
            if (!is_file($file) && is_dir(dirname($file))) {
                @file_put_contents($file, $content, LOCK_EX);
            }
        }
    }
}
