<?php
declare(strict_types=1);

namespace Calage\Export;

use Calage\Html\ImageScanner;
use Calage\Html\PathResolver;
use Calage\Html\Renderer;
use Calage\Html\Rewriter;
use Calage\Storage\ImageStore;

/**
 * Exports of a version, produced on the fly from the original HTML and version_images:
 *   - zip: HTML with relative paths + images/ folder;
 *   - standalone HTML with the absolute URLs of the hosted images.
 */
final class Exporter
{
    /**
     * File name of each image in images/, in order of appearance in the HTML.
     * Cleaned names (no spaces or accents), extension of the actual format, duplicates renamed
     * (logo.png, logo-2.png…) ignoring case. The same image appears only once.
     *
     * @param array<string, array{hash: string, ext: string}> $images path as written → image
     * @return array<string, array{name: string, hash: string, ext: string}> path as written → file
     */
    public static function fileNames(string $html, array $images): array
    {
        $byHash = [];  // hash → name already given
        $taken = [];   // names in use, lowercase
        $files = [];
        foreach (ImageScanner::scan($html) as $ref) {
            $raw = $ref['raw'];
            if (isset($files[$raw]) || !isset($images[$raw])) {
                continue;
            }
            $image = $images[$raw];
            if (!isset($byHash[$image['hash']])) {
                $base = self::baseName($raw, $ref['entities']);
                $name = $base . '.' . $image['ext'];
                for ($i = 2; isset($taken[strtolower($name)]); $i++) {
                    $name = $base . '-' . $i . '.' . $image['ext'];
                }
                $taken[strtolower($name)] = true;
                $byHash[$image['hash']] = $name;
            }
            $files[$raw] = ['name' => $byHash[$image['hash']]] + $image;
        }
        return $files;
    }

    /** HTML with relative images/… paths, as it will be in the zip. */
    public static function relativeHtml(string $html, array $images): string
    {
        $map = array_map(fn(array $f): string => 'images/' . $f['name'], self::fileNames($html, $images));
        return Rewriter::rewriteMap($html, $map);
    }

    /** HTML with the absolute URLs of the hosted images (base_url). */
    public static function absoluteHtml(string $html, array $images, string $baseUrl): string
    {
        return Renderer::absolute($html, $images, $baseUrl);
    }

    /**
     * Builds the zip in a temporary file and returns its path (to delete once sent).
     * @param array<string, array{hash: string, ext: string}> $images
     */
    public static function zip(string $html, array $images, string $htmlName, string $imagesDir, string $tmpDir): string
    {
        if (!is_dir($tmpDir) && !mkdir($tmpDir, 0770, true) && !is_dir($tmpDir)) {
            throw new \RuntimeException("Cannot create the temporary folder $tmpDir");
        }
        $files = self::fileNames($html, $images);
        // An image missing from disk (should not happen): its path stays as is.
        $files = array_filter($files, fn(array $f): bool => is_file($imagesDir . ImageStore::publicPath($f['hash'], $f['ext'])));
        $map = array_map(fn(array $f): string => 'images/' . $f['name'], $files);

        $path = $tmpDir . '/export-' . bin2hex(random_bytes(8)) . '.zip';
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::EXCL) !== true) {
            throw new \RuntimeException("Cannot create the zip $path");
        }
        $zip->addFromString($htmlName, Rewriter::rewriteMap($html, $map));
        $added = [];
        foreach ($files as $file) {
            if (!isset($added[$file['name']])) {
                $zip->addFile($imagesDir . ImageStore::publicPath($file['hash'], $file['ext']), 'images/' . $file['name']);
                $added[$file['name']] = true;
            }
        }
        $zip->close();
        return $path;
    }

    /**
     * Hosted images pointed to by the paths of an MJML source. The MJML paths are normally found
     * as is in the compiled HTML; otherwise their normalized forms are compared
     * (%20, ./, ../, entities).
     * @param array<string, array{hash: string, ext: string}> $images path in the compiled HTML → image
     * @return array<string, array{hash: string, ext: string}> path as written in the MJML → image
     */
    public static function mjmlImages(string $mjml, array $images): array
    {
        $normalized = [];
        foreach ($images as $raw => $image) {
            $normalized[self::normalizedPath((string) $raw, true)] ??= $image;
        }
        $map = [];
        foreach (ImageScanner::scan($mjml) as $ref) {
            $raw = $ref['raw'];
            if (isset($images[$raw])) {
                $map[$raw] = $images[$raw];
            } elseif (($image = $normalized[self::normalizedPath($raw, $ref['entities'])] ?? null) !== null) {
                $map[$raw] = $image;
            }
        }
        return $map;
    }

    /** MJML source with relative images/… paths, with the same file names as the HTML zip. */
    public static function relativeMjml(string $mjml, string $html, array $images): string
    {
        $names = self::namesByHash($html, $images);
        $map = [];
        foreach (self::mjmlImages($mjml, $images) as $raw => $image) {
            $map[$raw] = 'images/' . ($names[$image['hash']] ?? $image['hash'] . '.' . $image['ext']);
        }
        return Rewriter::rewriteMap($mjml, $map);
    }

    /** MJML source with the absolute URLs of the hosted images. */
    public static function absoluteMjml(string $mjml, array $images, string $baseUrl): string
    {
        $baseUrl = rtrim($baseUrl, '/');
        $map = array_map(
            fn(array $image): string => $baseUrl . ImageStore::publicPath($image['hash'], $image['ext']),
            self::mjmlImages($mjml, $images)
        );
        return Rewriter::rewriteMap($mjml, $map);
    }

    /**
     * Zip of the MJML source (relative paths), the compiled HTML (same relative paths) and their
     * images/ folder. $baseName: name without extension (gives {base}.mjml and {base}.html). Returns the temporary file path.
     */
    public static function mjmlZip(string $mjml, string $html, array $images, string $baseName, string $imagesDir, string $tmpDir): string
    {
        if (!is_dir($tmpDir) && !mkdir($tmpDir, 0770, true) && !is_dir($tmpDir)) {
            throw new \RuntimeException("Cannot create the temporary folder $tmpDir");
        }
        $names = self::namesByHash($html, $images);
        $path = $tmpDir . '/export-' . bin2hex(random_bytes(8)) . '.zip';
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::EXCL) !== true) {
            throw new \RuntimeException("Cannot create the zip $path");
        }
        $zip->addFromString($baseName . '.mjml', self::relativeMjml($mjml, $html, $images));
        $zip->addFromString($baseName . '.html', self::relativeHtml($html, $images));
        $added = [];
        // Images of the source and of the compiled HTML (usually the same).
        foreach (array_merge(array_values(self::mjmlImages($mjml, $images)), array_values(self::fileNames($html, $images))) as $image) {
            $name = $names[$image['hash']] ?? $image['hash'] . '.' . $image['ext'];
            $file = $imagesDir . ImageStore::publicPath($image['hash'], $image['ext']);
            if (!isset($added[$name]) && is_file($file)) {
                $zip->addFile($file, 'images/' . $name);
                $added[$name] = true;
            }
        }
        $zip->close();
        return $path;
    }

    /** @return array<string, string> hash → file name in images/ (see fileNames) */
    private static function namesByHash(string $html, array $images): array
    {
        $names = [];
        foreach (self::fileNames($html, $images) as $file) {
            $names[$file['hash']] = $file['name'];
        }
        return $names;
    }

    private static function normalizedPath(string $raw, bool $entities): string
    {
        $value = $entities ? html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8') : $raw;
        return PathResolver::normalize(rawurldecode(trim($value)), 'x.html');
    }

    /** Safe file name, without accents or spaces: "Rentrée 2026 !" → "Rentree-2026". */
    public static function slug(string $value, string $fallback = 'newsletter'): string
    {
        $value = (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', PathResolver::unaccent($value));
        $value = trim((string) preg_replace('/-{2,}/', '-', $value), '-._');
        return $value === '' ? $fallback : mb_substr($value, 0, 80);
    }

    /** Image name, without folder or extension, cleaned. */
    private static function baseName(string $raw, bool $entities): string
    {
        $value = $entities ? html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8') : $raw;
        $value = PathResolver::normalize(rawurldecode(trim($value)), 'x.html');
        return self::slug(pathinfo($value, PATHINFO_FILENAME), 'image');
    }
}
