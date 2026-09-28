<?php
declare(strict_types=1);

namespace Calage\Storage;

/**
 * Upload split into chunks, to get around the limits of shared hosting
 * (upload_max_filesize, post_max_size, max_file_uploads, max_execution_time).
 *
 * 1. start(): the browser announces its files (path, size); the ones that will be kept are noted.
 * 2. write(): each chunk is written at its offset; sending a chunk again rewrites it harmlessly.
 * 3. files(): once complete, the list of received files is used to build the Package.
 *
 * Stored in {tmp}/uploads/{id}/: manifest.json + files/{index}.
 */
final class ChunkedUpload
{
    public const MAX_FILES = 5000;
    public const MAX_BYTES = 300 * 1024 * 1024;

    /** @var list<array{path: string, size: int, accepted: bool}> */
    private array $files = [];
    private array $meta = [];

    private function __construct(private string $dir, private string $id)
    {
    }

    /**
     * @param list<array{path: string, size: int}> $files
     * @param array{newsletter_id?: ?int, folder_id?: ?int} $meta
     */
    public static function start(string $baseDir, array $files, array $meta): self
    {
        self::purge($baseDir);
        if ($files === []) {
            throw new ImportException(__('No file to upload.'));
        }
        if (count($files) > self::MAX_FILES) {
            throw new ImportException(__('Too many files ({count}, at most {max}).', ['count' => count($files), 'max' => self::MAX_FILES]));
        }

        // A zip is kept only when it is uploaded alone; otherwise, HTML and images only.
        $singleZip = count($files) === 1 && preg_match('/\.zip$/i', (string) $files[0]['path']);
        $total = 0;
        $list = [];
        foreach ($files as $file) {
            $path = (string) $file['path'];
            $size = max(0, (int) $file['size']);
            $accepted = $singleZip || Package::cleanPath($path) !== null;
            if ($accepted) {
                $total += $size;
            }
            $list[] = ['path' => $path, 'size' => $size, 'accepted' => $accepted];
        }
        if ($total > self::MAX_BYTES) {
            throw new ImportException(__('The upload exceeds {n} MB.', ['n' => self::MAX_BYTES >> 20]));
        }
        if (!array_filter($list, fn(array $f): bool => $f['accepted'])) {
            throw new ImportException(__('No HTML, MJML, image or zip file in the upload.'));
        }

        $id = bin2hex(random_bytes(12));
        $dir = $baseDir . '/uploads/' . $id;
        if (!mkdir($dir . '/files', 0770, true)) {
            throw new \RuntimeException("Cannot create the upload folder $dir");
        }
        $upload = new self($dir, $id);
        $upload->files = $list;
        $upload->meta = $meta;
        file_put_contents($dir . '/manifest.json', json_encode(
            ['files' => $list, 'meta' => $meta],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
        return $upload;
    }

    public static function load(string $baseDir, string $id): ?self
    {
        if (!preg_match('/^[a-f0-9]{24}$/', $id)) {
            return null;
        }
        $dir = $baseDir . '/uploads/' . $id;
        $data = json_decode((string) @file_get_contents($dir . '/manifest.json'), true);
        if (!is_array($data)) {
            return null;
        }
        $upload = new self($dir, $id);
        $upload->files = $data['files'];
        $upload->meta = $data['meta'] ?? [];
        return $upload;
    }

    /** Deletes uploads abandoned for more than $maxAge seconds. */
    public static function purge(string $baseDir, int $maxAge = 86400): void
    {
        foreach (glob($baseDir . '/uploads/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (filemtime($dir) < time() - $maxAge) {
                (new self($dir, basename($dir)))->delete();
            }
        }
    }

    public function id(): string
    {
        return $this->id;
    }

    public function meta(string $key): mixed
    {
        return $this->meta[$key] ?? null;
    }

    /** @return list<int> indexes of the files to upload */
    public function accepted(): array
    {
        return array_keys(array_filter($this->files, fn(array $f): bool => $f['accepted']));
    }

    /**
     * Writes a chunk at its offset. Returns the size received so far for this file.
     * Refuses a chunk that would leave a gap or exceed the announced size.
     */
    public function write(int $index, int $offset, string $chunkFile): int
    {
        $file = $this->files[$index] ?? null;
        if ($file === null || !$file['accepted']) {
            throw new ImportException(__('Unexpected file {index}.', ['index' => $index]));
        }
        $target = $this->localPath($index);
        $received = is_file($target) ? filesize($target) : 0;
        $length = filesize($chunkFile);
        if ($offset < 0 || $offset > $received) {
            throw new ImportException(__('Chunk out of sequence for {file} (received: {received}, offset: {offset}).', ['file' => $file['path'], 'received' => $received, 'offset' => $offset]));
        }
        if ($offset + $length > $file['size']) {
            throw new ImportException(__('Chunk too long for {file}.', ['file' => $file['path']]));
        }

        $out = fopen($target, 'c+b');
        $in = fopen($chunkFile, 'rb');
        fseek($out, $offset);
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);
        clearstatcache(true, $target);
        touch($this->dir); // the upload is active: no purge
        return (int) filesize($target);
    }

    /** @return list<string> paths of the files not fully received yet */
    public function missing(): array
    {
        $missing = [];
        foreach ($this->accepted() as $index) {
            $target = $this->localPath($index);
            if (!is_file($target) || filesize($target) !== $this->files[$index]['size']) {
                $missing[] = $this->files[$index]['path'];
            }
        }
        return $missing;
    }

    /** @return list<array{name: string, full_path: string, tmp_name: string}> in the ImportController format */
    public function files(): array
    {
        $out = [];
        foreach ($this->accepted() as $index) {
            $path = $this->files[$index]['path'];
            $out[] = ['name' => basename(str_replace('\\', '/', $path)), 'full_path' => $path, 'tmp_name' => $this->localPath($index)];
        }
        return $out;
    }

    public function delete(): void
    {
        foreach (glob($this->dir . '/files/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir . '/files');
        @unlink($this->dir . '/manifest.json');
        @rmdir($this->dir);
    }

    private function localPath(int $index): string
    {
        return $this->dir . '/files/' . $index;
    }
}
