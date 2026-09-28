<?php
declare(strict_types=1);

namespace Calage\Storage;

use PDO;

/**
 * Images stored by content hash: {folder}/{sha256}.{ext}.
 * An image is never overwritten or deleted; the same content is stored only once.
 * The format is read from the file content, not from its extension.
 */
final class ImageStore
{
    public const FORMATS = [
        IMAGETYPE_JPEG => ['ext' => 'jpg', 'mime' => 'image/jpeg'],
        IMAGETYPE_PNG => ['ext' => 'png', 'mime' => 'image/png'],
        IMAGETYPE_GIF => ['ext' => 'gif', 'mime' => 'image/gif'],
        IMAGETYPE_WEBP => ['ext' => 'webp', 'mime' => 'image/webp'],
    ];

    public function __construct(private PDO $db, private string $dir)
    {
    }

    /**
     * Format and dimensions of a file, or null when it is not in an accepted format (SVG, BMP…).
     * @return array{ext: string, mime: string, width: int, height: int}|null
     */
    public static function inspect(string $file): ?array
    {
        $info = @getimagesize($file);
        if ($info === false || !isset(self::FORMATS[$info[2]])) {
            return null;
        }
        return self::FORMATS[$info[2]] + ['width' => (int) $info[0], 'height' => (int) $info[1]];
    }

    /**
     * Stores the file (unless it already exists) and records it in the database.
     * @return array{hash: string, ext: string}|null null when the format is not accepted
     */
    public function store(string $file): ?array
    {
        $info = self::inspect($file);
        if ($info === null) {
            return null;
        }
        $hash = hash_file('sha256', $file);
        $target = $this->dir . '/' . $hash . '.' . $info['ext'];

        if (!is_file($target)) {
            if (!is_dir($this->dir) && !mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
                throw new \RuntimeException("Cannot create the image folder: {$this->dir}");
            }
            // Copy under a temporary name, then rename: never a half-written file.
            $tmp = $target . '.' . bin2hex(random_bytes(4)) . '.tmp';
            if (!copy($file, $tmp) || !rename($tmp, $target)) {
                @unlink($tmp);
                throw new \RuntimeException("Cannot save image $hash");
            }
            @chmod($target, 0644);
        }

        $this->db->prepare(
            'INSERT OR IGNORE INTO images (hash, ext, mime, size, width, height) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$hash, $info['ext'], $info['mime'], filesize($file), $info['width'], $info['height']]);

        return ['hash' => $hash, 'ext' => $info['ext']];
    }

    public static function publicPath(string $hash, string $ext): string
    {
        return '/i/' . $hash . '.' . $ext;
    }
}
