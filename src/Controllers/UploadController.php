<?php
declare(strict_types=1);

namespace Calage\Controllers;

use Calage\App;
use Calage\Storage\ChunkedUpload;
use Calage\Storage\ImportException;
use Calage\Storage\Package;

/**
 * Chunked upload (see assets/upload.js and Storage/ChunkedUpload).
 * JSON responses: {…} on success, {"error": "…"} otherwise.
 */
final class UploadController
{
    /** Announces the files: {files: [{path, size}], newsletter?, folder_id?} → {id, chunkSize, accepted}. */
    public function start(array $params): void
    {
        $body = json_decode((string) file_get_contents('php://input'), true);
        $files = [];
        foreach ((array) ($body['files'] ?? []) as $file) {
            if (is_array($file) && isset($file['path'], $file['size'])) {
                $files[] = ['path' => (string) $file['path'], 'size' => (int) $file['size']];
            }
        }
        $target = ImportController::target($body['newsletter'] ?? null);

        try {
            $upload = ChunkedUpload::start(App::tmpDir(), $files, [
                'newsletter_id' => $target === null ? null : (int) $target['id'],
                'folder_id' => ImportController::folderId($body['folder_id'] ?? null),
            ]);
        } catch (ImportException $e) {
            json_response(['error' => $e->getMessage()], 422);
        }
        json_response(['id' => $upload->id(), 'chunkSize' => self::chunkSize(), 'accepted' => $upload->accepted()]);
    }

    /** One chunk: index and offset fields, and the "chunk" file → {received}. */
    public function chunk(array $params): void
    {
        $upload = $this->upload($params['upload']);
        $chunk = $_FILES['chunk'] ?? null;
        if (!is_array($chunk) || $chunk['error'] !== UPLOAD_ERR_OK) {
            json_response(['error' => __('Chunk not received (code {code}).', ['code' => $chunk['error'] ?? '?'])], 400);
        }
        try {
            $received = $upload->write((int) ($_POST['index'] ?? -1), (int) ($_POST['offset'] ?? -1), $chunk['tmp_name']);
        } catch (ImportException $e) {
            json_response(['error' => $e->getMessage()], 409);
        }
        json_response(['received' => $received]);
    }

    /** End of the upload: checks that everything arrived, builds the import → {redirect}. */
    public function finish(array $params): void
    {
        $upload = $this->upload($params['upload']);
        $missing = $upload->missing();
        if ($missing !== []) {
            json_response(['error' => __('Incomplete upload: {files}', ['files' => implode(', ', array_slice($missing, 0, 5))])], 409);
        }
        @set_time_limit(300); // unpacking a large zip can take a while
        try {
            $package = Package::fromUploads(
                App::tmpDir(),
                $upload->files(),
                $upload->meta('newsletter_id'),
                $upload->meta('folder_id')
            );
        } catch (ImportException $e) {
            $upload->delete(); // (json_response() ends the script: no "finally")
            json_response(['error' => $e->getMessage()], 422);
        }
        $upload->delete();
        json_response(['redirect' => url('/imports/' . $package->id())]);
    }

    private function upload(string $id): ChunkedUpload
    {
        $upload = ChunkedUpload::load(App::tmpDir(), $id);
        if ($upload === null) {
            json_response(['error' => __('Upload not found or expired. Upload the files again.')], 404);
        }
        return $upload;
    }

    /**
     * Chunk size: below the server limits (a chunk is a file sent by POST),
     * between 256 KB and 4 MB.
     */
    public static function chunkSize(): int
    {
        $limits = array_filter([self::bytes(ini_get('upload_max_filesize')), self::bytes(ini_get('post_max_size'))]);
        $limit = $limits === [] ? PHP_INT_MAX : min($limits);
        return max(256 * 1024, min(4 * 1024 * 1024, $limit - 64 * 1024));
    }

    /** "2M", "512K", "1G" → bytes (0 = no limit). */
    public static function bytes(string|false $value): int
    {
        $value = trim((string) $value);
        $number = (int) $value;
        return match (strtoupper(substr($value, -1))) {
            'G' => $number << 30,
            'M' => $number << 20,
            'K' => $number << 10,
            default => $number,
        };
    }
}
