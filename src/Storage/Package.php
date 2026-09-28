<?php
declare(strict_types=1);

namespace Calage\Storage;

/**
 * Pending import: the uploaded files (HTML alone, folder or zip), kept while the HTML is chosen
 * and the import confirmed. Stored in {tmp}/imports/{id}/.
 *
 * Files are saved under random names (files/3f9a…) and their original path exists only in
 * manifest.json: no name coming from outside is ever used on disk
 * (no "zip slip", no trouble with the file system's encoding or case).
 */
final class Package
{
    /** Extensions kept: HTML and anything that looks like an image. The rest is ignored. */
    private const KEEP = ['html', 'htm', 'mjml', 'jpg', 'jpeg', 'jpe', 'png', 'gif', 'webp', 'svg', 'bmp', 'tif', 'tiff', 'avif', 'ico'];

    private const ZIP_MAX_ENTRIES = 5000;
    private const ZIP_MAX_BYTES = 300 * 1024 * 1024;

    /** @var array<string, string> original path → stored file */
    private array $files = [];
    private string $sourceName = '';
    /** Existing newsletter the import will add a version to (null = new newsletter). */
    private ?int $newsletterId = null;
    /** Folder suggested for a new newsletter (import started from a folder). */
    private ?int $folderId = null;
    /** @var array<string, array{html: string, errors: list<string>, version: string}> MJML source → compilation (done in the browser) */
    private array $compiled = [];

    private function __construct(private string $dir, private string $id)
    {
    }

    public static function create(string $baseDir, string $sourceName, ?int $newsletterId = null, ?int $folderId = null): self
    {
        self::purge($baseDir);
        $id = bin2hex(random_bytes(12));
        $dir = $baseDir . '/imports/' . $id;
        if (!mkdir($dir . '/files', 0770, true)) {
            throw new \RuntimeException("Cannot create the import folder $dir");
        }
        $package = new self($dir, $id);
        $package->sourceName = $sourceName;
        $package->newsletterId = $newsletterId;
        $package->folderId = $folderId;
        return $package;
    }

    /**
     * Import built from received files (plain or chunked upload):
     * a zip on its own is unpacked, otherwise each file keeps its path (folder included).
     * @param list<array{name: string, full_path: string, tmp_name: string}> $files
     * @throws ImportException when the upload contains no HTML or the zip cannot be read
     */
    public static function fromUploads(string $baseDir, array $files, ?int $newsletterId, ?int $folderId): self
    {
        $isZip = count($files) === 1 && preg_match('/\.zip$/i', $files[0]['name']);
        $package = self::create($baseDir, self::guessName($files), $newsletterId, $folderId);
        try {
            if ($isZip) {
                $package->addZip($files[0]['tmp_name']);
            } else {
                foreach ($files as $file) {
                    $package->addFile($file['full_path'] ?: $file['name'], $file['tmp_name'], true);
                }
            }
            if ($package->htmlFiles() === [] && $package->mjmlFiles() === []) {
                throw new ImportException(__('No HTML or MJML file found in the upload.'));
            }
        } catch (\Throwable $e) {
            $package->delete();
            throw $e;
        }
        $package->save();
        return $package;
    }

    /** Name suggested for the newsletter: name of the uploaded folder, zip or HTML file. */
    private static function guessName(array $files): string
    {
        $first = str_replace('\\', '/', $files[0]['full_path'] ?? '');
        if (str_contains($first, '/')) {
            return explode('/', ltrim($first, '/'))[0];
        }
        foreach ($files as $file) {
            if (preg_match('/\.(zip|html?|mjml)$/i', $file['name'])) {
                return pathinfo($file['name'], PATHINFO_FILENAME);
            }
        }
        return 'Newsletter';
    }

    public static function load(string $baseDir, string $id): ?self
    {
        if (!preg_match('/^[a-f0-9]{24}$/', $id)) {
            return null;
        }
        $dir = $baseDir . '/imports/' . $id;
        $manifest = @file_get_contents($dir . '/manifest.json');
        if ($manifest === false) {
            return null;
        }
        $data = json_decode($manifest, true);
        $package = new self($dir, $id);
        $package->files = $data['files'] ?? [];
        $package->sourceName = $data['source'] ?? '';
        $package->newsletterId = isset($data['newsletter_id']) ? (int) $data['newsletter_id'] : null;
        $package->folderId = isset($data['folder_id']) ? (int) $data['folder_id'] : null;
        $package->compiled = $data['compiled'] ?? [];
        return $package;
    }

    /** Deletes imports abandoned for more than $maxAge seconds. */
    public static function purge(string $baseDir, int $maxAge = 86400): void
    {
        foreach (glob($baseDir . '/imports/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (filemtime($dir) < time() - $maxAge) {
                self::removeDir($dir);
            }
        }
    }

    /**
     * Adds a file under its original path. Returns false when it is ignored
     * (system file, irrelevant extension, invalid path).
     */
    public function addFile(string $originalPath, string $source, bool $move = false): bool
    {
        $path = self::cleanPath($originalPath);
        if ($path === null) {
            return false;
        }
        $stored = 'files/' . bin2hex(random_bytes(8));
        $target = $this->dir . '/' . $stored;
        $ok = $move ? move_uploaded_file($source, $target) || rename($source, $target) : copy($source, $target);
        if (!$ok) {
            throw new \RuntimeException("Cannot save $originalPath");
        }
        $this->files[$path] = $stored;
        return true;
    }

    /** Unpacks a zip, entry by entry, with limits against booby-trapped archives. */
    public function addZip(string $zipFile): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipFile, \ZipArchive::RDONLY) !== true) {
            throw new ImportException(__('The zip file cannot be read or is damaged.'));
        }
        try {
            if ($zip->numFiles > self::ZIP_MAX_ENTRIES) {
                throw new ImportException(__('The zip contains too many files ({n}).', ['n' => $zip->numFiles]));
            }
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                // Without a flag, libzip converts the names in Windows zips (CP437) to UTF-8.
                $name = (string) $zip->getNameIndex($i);
                if (str_ends_with($name, '/') || self::cleanPath($name) === null) {
                    continue;
                }
                $stream = $zip->getStream($name);
                if ($stream === false) {
                    continue;
                }
                $tmp = $this->dir . '/unzip.tmp';
                $out = fopen($tmp, 'wb');
                // The size announced in the zip can lie: what is actually written is counted.
                $written = stream_copy_to_stream($stream, $out, self::ZIP_MAX_BYTES - $total + 1);
                fclose($out);
                fclose($stream);
                $total += (int) $written;
                if ($total > self::ZIP_MAX_BYTES) {
                    @unlink($tmp);
                    throw new ImportException(__('The unpacked zip exceeds {n} MB.', ['n' => self::ZIP_MAX_BYTES >> 20]));
                }
                $this->addFile($name, $tmp, true);
            }
        } finally {
            $zip->close();
        }
    }

    public function save(): void
    {
        file_put_contents($this->dir . '/manifest.json', json_encode(
            [
                'source' => $this->sourceName,
                'newsletter_id' => $this->newsletterId,
                'folder_id' => $this->folderId,
                'files' => $this->files,
                'compiled' => $this->compiled,
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
    }

    public function id(): string
    {
        return $this->id;
    }

    public function sourceName(): string
    {
        return $this->sourceName;
    }

    public function newsletterId(): ?int
    {
        return $this->newsletterId;
    }

    public function folderId(): ?int
    {
        return $this->folderId;
    }

    /** @return list<string> */
    public function files(): array
    {
        return array_keys($this->files);
    }

    /** @return list<string> HTML files, shallowest first */
    public function htmlFiles(): array
    {
        $html = array_values(array_filter(
            $this->files(),
            fn(string $p): bool => preg_match('/\.html?$/i', $p) === 1
        ));
        usort($html, fn(string $a, string $b): int => [substr_count($a, '/'), $a] <=> [substr_count($b, '/'), $b]);
        return $html;
    }

    /** @return list<string> MJML files, shallowest first */
    public function mjmlFiles(): array
    {
        $mjml = array_values(array_filter($this->files(), [\Calage\Mjml::class, 'isMjmlPath']));
        usort($mjml, fn(string $a, string $b): int => [substr_count($a, '/'), $a] <=> [substr_count($b, '/'), $b]);
        return $mjml;
    }

    /** Possible sources of the newsletter: MJML first (it is the source when there is one), then HTML. */
    public function sourceFiles(): array
    {
        return array_merge($this->mjmlFiles(), $this->htmlFiles());
    }

    /** Saves the HTML compiled in the browser from an MJML file of the import. */
    public function setCompiled(string $mjmlPath, string $html, array $errors, string $version): void
    {
        if (!in_array($mjmlPath, $this->mjmlFiles(), true)) {
            throw new \OutOfBoundsException("MJML file missing from the import: $mjmlPath");
        }
        $this->compiled[$mjmlPath] = ['html' => $html, 'errors' => array_values(array_map('strval', $errors)), 'version' => $version];
    }

    /** @return array{html: string, errors: list<string>, version: string}|null */
    public function compiled(string $mjmlPath): ?array
    {
        return $this->compiled[$mjmlPath] ?? null;
    }

    public function localPath(string $path): string
    {
        if (!isset($this->files[$path])) {
            throw new \OutOfBoundsException("File missing from the import: $path");
        }
        return $this->dir . '/' . $this->files[$path];
    }

    public function read(string $path): string
    {
        return (string) file_get_contents($this->localPath($path));
    }

    public function delete(): void
    {
        self::removeDir($this->dir);
    }

    /** Cleaned original path, or null when the file must be ignored. */
    public static function cleanPath(string $path): ?string
    {
        $path = str_replace('\\', '/', $path);
        if (!mb_check_encoding($path, 'UTF-8')) {
            $path = mb_convert_encoding($path, 'UTF-8', 'CP850');
        }
        $parts = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..' || $segment === '__MACOSX' || str_starts_with($segment, '._')) {
                return null;
            }
            $parts[] = $segment;
        }
        if ($parts === []) {
            return null;
        }
        $name = end($parts);
        if (str_starts_with($name, '.') || strcasecmp($name, 'Thumbs.db') === 0) {
            return null;
        }
        if (!in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::KEEP, true)) {
            return null;
        }
        return implode('/', $parts);
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
