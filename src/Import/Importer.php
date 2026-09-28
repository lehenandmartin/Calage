<?php
declare(strict_types=1);

namespace Calage\Import;

use Calage\Html\ImageScanner;
use Calage\Html\PathResolver;
use Calage\Mjml;
use Calage\Repo\NewsletterRepo;
use Calage\Repo\VersionRepo;
use Calage\Storage\ImageStore;
use Calage\Storage\Package;
use PDO;

final class Importer
{
    public function __construct(private PDO $db, private ImageStore $store)
    {
    }

    /**
     * Report on the images of an HTML file of the import, one row per distinct path.
     *
     * $previous (path as written → hash): images of the current version when a new file is uploaded
     * to an existing newsletter. A path missing from the import but identical in the previous version
     * takes its image: the HTML alone can be uploaded again after a fix.
     *
     * @return array{
     *   html: string,
     *   images: list<array{raw: string, status: 'found'|'missing'|'unsupported', path: ?string, match: ?string, hash: ?string, count: int}>,
     *   external: int,
     *   ignored: int
     * }
     */
    public static function analyze(Package $package, string $htmlPath, array $previous = []): array
    {
        $html = self::html($package, $htmlPath);
        $resolver = new PathResolver($package->files());
        $images = [];
        $external = 0;
        $ignored = 0;

        foreach (ImageScanner::scan($html) as $ref) {
            $raw = $ref['raw'];
            if (isset($images[$raw])) {
                $images[$raw]['count']++;
                continue;
            }
            $result = $resolver->resolve($raw, $ref['entities'], $htmlPath);
            if ($result['status'] === 'external') {
                $external++;
                continue;
            }
            if ($result['status'] === 'ignored') {
                $ignored++;
                continue;
            }
            if ($result['status'] === 'found' && ImageStore::inspect($package->localPath($result['path'])) === null) {
                $result['status'] = 'unsupported';
            }
            $hash = null;
            if ($result['status'] === 'missing' && isset($previous[$raw])) {
                $result = ['status' => 'found', 'path' => null, 'match' => 'previous'];
                $hash = $previous[$raw];
            }
            $images[$raw] = ['raw' => $raw] + $result + ['hash' => $hash, 'count' => 1];
        }

        return ['html' => $html, 'images' => array_values($images), 'external' => $external, 'ignored' => $ignored];
    }

    /**
     * Creates a newsletter with a first draft from the chosen HTML.
     * The HTML is saved as is; the images found are stored by content hash.
     */
    public function createNewsletter(Package $package, string $htmlPath, string $name, ?int $folderId = null): int
    {
        $report = self::analyze($package, $htmlPath);
        $map = $this->storeImages($package, $report);

        $this->db->beginTransaction();
        try {
            $newsletterId = (new NewsletterRepo($this->db))->create($name, $folderId);
            $mjml = self::mjml($package, $htmlPath);
            (new VersionRepo($this->db))->createDraft(
                $newsletterId,
                $report['html'],
                '',
                ($mjml !== null ? Mjml::preview($mjml) : null) ?? '',
                $map,
                $mjml
            );
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $newsletterId;
    }

    /**
     * New file uploaded to an existing newsletter: it becomes its draft (replacing the draft,
     * if any), with the subject and preheader of the current version.
     */
    public function createVersion(Package $package, string $htmlPath, int $newsletterId): int
    {
        $versions = new VersionRepo($this->db);
        $current = $versions->current($newsletterId);
        $previous = $current === null ? [] : $versions->hashes((int) $current['id']);

        $report = self::analyze($package, $htmlPath, $previous);
        $map = $this->storeImages($package, $report);

        // Preheader: the one from <mj-preview> when filled in, otherwise the one of the current version
        // ('' when it was generated from the content: it is then generated again from the new content).
        $mjml = self::mjml($package, $htmlPath);
        return $versions->replaceDraft(
            $newsletterId,
            $report['html'],
            $current['subject'] ?? '',
            ($mjml !== null ? Mjml::preview($mjml) : null) ?? ($current === null ? '' : VersionRepo::userPreheader($current)),
            $map,
            $mjml
        );
    }

    /**
     * HTML of the newsletter: the file itself, or, for an .mjml file, its compilation done in the browser.
     * Relative paths are resolved from the folder of the chosen file in both cases.
     */
    private static function html(Package $package, string $path): string
    {
        if (!Mjml::isMjmlPath($path)) {
            return $package->read($path);
        }
        $compiled = $package->compiled($path);
        if ($compiled === null) {
            throw new \Calage\Storage\ImportException(__('The MJML file has not been compiled yet.'));
        }
        return $compiled['html'];
    }

    /** MJML source to keep with the version (null when the newsletter is imported as HTML). */
    private static function mjml(Package $package, string $path): ?string
    {
        return Mjml::isMjmlPath($path) ? $package->read($path) : null;
    }

    /**
     * Stores the images found. Done before any database write, safely: the same content
     * always gives the same file, and images are never deleted.
     * @return array<string, string> path as written → hash
     */
    private function storeImages(Package $package, array $report): array
    {
        $map = [];
        foreach ($report['images'] as $image) {
            if ($image['status'] !== 'found') {
                continue;
            }
            if ($image['hash'] !== null) {
                $map[$image['raw']] = $image['hash']; // taken from the previous version
                continue;
            }
            $stored = $this->store->store($package->localPath($image['path']));
            if ($stored !== null) {
                $map[$image['raw']] = $stored['hash'];
            }
        }
        return $map;
    }
}
