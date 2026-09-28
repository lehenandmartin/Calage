<?php
declare(strict_types=1);

namespace Calage\Controllers;

use Calage\App;
use Calage\Import\Importer;
use Calage\Mjml;
use Calage\Repo\FolderRepo;
use Calage\Repo\NewsletterRepo;
use Calage\Repo\VersionRepo;
use Calage\Session;
use Calage\Storage\ImageStore;
use Calage\Storage\ImportException;
use Calage\Storage\Package;
use Calage\View;

/**
 * Newsletter import: upload (HTML alone, folder or zip) → review → confirm.
 * Creates a new newsletter, or a new draft of an existing newsletter (?newsletter=id).
 * This is the plain upload (without JavaScript); chunked uploads go through UploadController.
 */
final class ImportController
{
    public function form(array $params): void
    {
        $target = self::target($_GET['newsletter'] ?? null);
        View::render('import', [
            'title' => $target === null ? __('Import a newsletter') : __('New version · {name}', ['name' => $target['name']]),
            'target' => $target,
            'folderId' => self::folderId($_GET['folder'] ?? null),
            'navCurrent' => $target !== null
                ? ($target['folder_id'] === null ? 'none' : (string) $target['folder_id'])
                : (string) (self::folderId($_GET['folder'] ?? null) ?? ''),
            'limits' => [
                'upload_max_filesize' => ini_get('upload_max_filesize'),
                'post_max_size' => ini_get('post_max_size'),
                'max_file_uploads' => ini_get('max_file_uploads'),
            ],
        ]);
    }

    public function upload(array $params): void
    {
        $target = self::target($_POST['newsletter'] ?? null);
        $back = '/import' . ($target === null ? '' : '?newsletter=' . $target['id']);
        $files = array_merge(self::uploadedFiles('files'), self::uploadedFiles('folder'));

        $errors = [];
        foreach ($files as $i => $file) {
            if ($file['error'] === UPLOAD_ERR_NO_FILE) {
                unset($files[$i]);
            } elseif ($file['error'] !== UPLOAD_ERR_OK) {
                $errors[] = __('{file}: {error}', ['file' => $file['name'], 'error' => self::uploadError($file['error'])]);
            }
        }
        if ($errors !== []) {
            Session::flash('error', __('Incomplete upload. {errors}', ['errors' => implode(' ; ', $errors)]));
            redirect($back);
        }
        $files = array_values($files);
        if ($files === []) {
            Session::flash('error', __('Choose an HTML file, a zip or a folder.'));
            redirect($back);
        }
        if (count($files) >= (int) ini_get('max_file_uploads')) {
            Session::flash('warning', __('The server accepts at most {n} files per upload: some may have been ignored. A zip has no such limit.', ['n' => ini_get('max_file_uploads')]));
        }

        try {
            $package = Package::fromUploads(
                App::tmpDir(),
                $files,
                $target === null ? null : (int) $target['id'],
                self::folderId($_POST['folder_id'] ?? null)
            );
        } catch (ImportException $e) {
            Session::flash('error', $e->getMessage());
            redirect($back);
        }
        redirect('/imports/' . $package->id());
    }

    public function review(array $params): void
    {
        $package = $this->package($params['import']);
        $sources = $package->sourceFiles();
        $mjmlFiles = $package->mjmlFiles();

        // Automatic choice: the only file, or the only MJML file (it is the source when there is one).
        $default = count($sources) === 1 ? $sources[0] : (count($mjmlFiles) === 1 ? $mjmlFiles[0] : null);
        $selected = $_GET['html'] ?? $default;
        if (!in_array($selected, $sources, true)) {
            $selected = null;
        }

        $target = self::target($package->newsletterId());
        $versions = new VersionRepo(App::db());
        $current = $target === null ? null : $versions->current((int) $target['id']);
        $previous = $current === null ? [] : $versions->hashes((int) $current['id']);

        $isMjml = $selected !== null && Mjml::isMjmlPath($selected);
        $compiled = $isMjml ? $package->compiled($selected) : null;
        $needsCompile = $isMjml && ($compiled === null || isset($_GET['recompile']));

        View::render('import-review', [
            'title' => __('Review the import'),
            'package' => $package,
            'target' => $target,
            'replacesDraft' => $current !== null && $current['status'] === 'draft',
            'targetIsMjml' => $current !== null && $current['mjml'] !== null,
            'folders' => (new FolderRepo(App::db()))->all(),
            'htmlFiles' => $sources,
            'selected' => $selected,
            'isMjml' => $isMjml,
            'needsCompile' => $needsCompile,
            'mjmlSource' => $needsCompile ? $package->read($selected) : null,
            'compiled' => $compiled,
            'includes' => $isMjml ? Mjml::includes($package->read($selected)) : [],
            'mjmlPreview' => $isMjml ? Mjml::preview($package->read($selected)) : null,
            'report' => $selected === null || $needsCompile ? null : Importer::analyze($package, $selected, $previous),
            'navCurrent' => $target !== null
                ? ($target['folder_id'] === null ? 'none' : (string) $target['folder_id'])
                : (string) ($package->folderId() ?? ''),
        ]);
    }

    /** Receives the HTML compiled in the browser from an MJML file of the import. */
    public function compile(array $params): void
    {
        $package = $this->package($params['import']);
        $path = (string) ($_POST['path'] ?? '');
        $html = (string) ($_POST['html'] ?? '');
        if (!in_array($path, $package->mjmlFiles(), true) || trim($html) === '') {
            View::error(400, __('Incomplete MJML compilation. Reload the page to start again.'));
            return;
        }
        $errors = json_decode((string) ($_POST['errors'] ?? '[]'), true);
        $errors = is_array($errors) ? array_slice(array_map('strval', $errors), 0, 50) : [];
        $package->setCompiled($path, $html, $errors, Mjml::VERSION);
        $package->save();
        redirect('/imports/' . $package->id() . '?html=' . rawurlencode($path));
    }

    public function confirm(array $params): void
    {
        $package = $this->package($params['import']);
        $html = (string) ($_POST['html'] ?? '');
        if (!in_array($html, $package->sourceFiles(), true)) {
            View::error(400, __('Unknown file.'));
            return;
        }
        if (Mjml::isMjmlPath($html) && $package->compiled($html) === null) {
            View::error(400, __('The MJML file has not been compiled yet. Reload the review page.'));
            return;
        }
        $db = App::db();
        $importer = new Importer($db, new ImageStore($db, APP_ROOT . '/i'));
        $target = self::target($package->newsletterId());
        if ($package->newsletterId() !== null && $target === null) {
            View::error(404, __('The newsletter this import was meant for no longer exists.'));
            return;
        }

        if ($target !== null) {
            $importer->createVersion($package, $html, (int) $target['id']);
            $package->delete();
            Session::flash('success', __('New file imported as a draft. Publish it to make it visible on the share link.'));
            redirect('/newsletters/' . $target['id']);
        }

        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            $name = $package->sourceName();
        }
        $id = $importer->createNewsletter($package, $html, mb_substr($name, 0, 200), self::folderId($_POST['folder_id'] ?? null));
        $package->delete();

        Session::flash('success', __('Newsletter imported. It is a draft: nothing is visible on the share link yet.'));
        redirect('/newsletters/' . $id);
    }

    private function package(string $id): Package
    {
        $package = Package::load(App::tmpDir(), $id);
        if ($package === null) {
            View::error(404, __('This import has expired or has already been confirmed. Upload the files again.'));
            exit;
        }
        return $package;
    }

    /** Id of an existing folder, or null. */
    public static function folderId(mixed $id): ?int
    {
        if ($id === null || !ctype_digit((string) $id) || (int) $id === 0) {
            return null;
        }
        $folder = (new FolderRepo(App::db()))->find((int) $id);
        return $folder === null ? null : (int) $folder['id'];
    }

    /** Newsletter targeted by an import (new version), or null for a new newsletter. */
    public static function target(mixed $id): ?array
    {
        if ($id === null || $id === '' || !ctype_digit((string) $id)) {
            return null;
        }
        return (new NewsletterRepo(App::db()))->find((int) $id);
    }

    /**
     * $_FILES['x'] of a multiple field, flattened: one entry per file.
     * @return list<array{name: string, full_path: string, tmp_name: string, error: int}>
     */
    private static function uploadedFiles(string $field): array
    {
        $raw = $_FILES[$field] ?? null;
        if (!is_array($raw) || !is_array($raw['name'] ?? null)) {
            return [];
        }
        $files = [];
        foreach ($raw['name'] as $i => $name) {
            $files[] = [
                'name' => (string) $name,
                'full_path' => (string) ($raw['full_path'][$i] ?? ''), // path inside the folder (PHP 8.1+)
                'tmp_name' => (string) $raw['tmp_name'][$i],
                'error' => (int) $raw['error'][$i],
            ];
        }
        return $files;
    }

    private static function uploadError(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => __('file too large for the server (limit: {size})', ['size' => ini_get('upload_max_filesize')]),
            UPLOAD_ERR_PARTIAL => __('upload interrupted'),
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => __('the server cannot save the file'),
            default => __('upload error ({code})', ['code' => $code]),
        };
    }
}
