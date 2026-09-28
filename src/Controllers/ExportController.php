<?php
declare(strict_types=1);

namespace Calage\Controllers;

use Calage\App;
use Calage\Export\Exporter;
use Calage\Repo\NewsletterRepo;
use Calage\Repo\VersionRepo;
use Calage\View;

/** Downloads: zip (relative paths + images/) and standalone HTML (absolute URLs). */
final class ExportController
{
    /** Back office: zip of any version, drafts included. */
    public function zip(array $params): void
    {
        [$newsletter, $version, $images] = $this->version((int) $params['id']);
        self::sendZip($newsletter, $version, $images);
    }

    /** Back office: HTML with the absolute URLs of the hosted images (base_url). */
    public function html(array $params): void
    {
        [$newsletter, $version, $images] = $this->version((int) $params['id']);
        $html = Exporter::absoluteHtml($version['html'], $images, App::absoluteUrl('/'));

        ini_set('default_charset', ''); // keep the newsletter's original encoding
        header('Content-Type: text/html');
        header('Content-Disposition: attachment; filename="' . self::fileName($newsletter, $version) . '.html"');
        header('Content-Length: ' . strlen($html));
        header('Cache-Control: private, no-store');
        echo $html;
    }

    /** Back office: zip of the MJML source with relative paths, and its images/ folder. */
    public function mjmlZip(array $params): void
    {
        [$newsletter, $version, $images] = $this->mjmlVersion((int) $params['id']);
        $name = self::fileName($newsletter, $version);
        $zip = Exporter::mjmlZip($version['mjml'], $version['html'], $images, Exporter::slug($newsletter['name']), APP_ROOT, App::tmpDir() . '/exports');
        try {
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $name . '-mjml.zip"');
            header('Content-Length: ' . filesize($zip));
            header('Cache-Control: private, no-store');
            readfile($zip);
        } finally {
            @unlink($zip);
        }
    }

    /** Back office: MJML source with the absolute URLs of the hosted images. */
    public function mjml(array $params): void
    {
        [$newsletter, $version, $images] = $this->mjmlVersion((int) $params['id']);
        $mjml = Exporter::absoluteMjml($version['mjml'], $images, App::absoluteUrl('/'));
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . self::fileName($newsletter, $version) . '.mjml"');
        header('Content-Length: ' . strlen($mjml));
        header('Cache-Control: private, no-store');
        echo $mjml;
    }

    /** @return array{0: array, 1: array, 2: array} newsletter, MJML version, images */
    private function mjmlVersion(int $id): array
    {
        $found = $this->version($id);
        if ($found[1]['mjml'] === null) {
            View::error(404, __('This version has no MJML source.'));
            exit;
        }
        return $found;
    }

    /** Share page: zip of a published version only. */
    public function clientZip(array $params): void
    {
        ClientController::useVisitorLanguage();
        $db = App::db();
        $newsletter = (new NewsletterRepo($db))->findByToken($params['token']);
        $versions = new VersionRepo($db);
        $version = $newsletter === null ? null : $versions->publishedByNumber((int) $newsletter['id'], (int) $params['n']);
        if ($version === null) {
            header('X-Robots-Tag: noindex, nofollow');
            View::error(404, __('This file is not available.'), 'client-layout');
            return;
        }
        header('X-Robots-Tag: noindex, nofollow');
        self::sendZip($newsletter, $version, $versions->images((int) $version['id']));
    }

    /** @return array{0: array, 1: array, 2: array} newsletter, version, images */
    private function version(int $id): array
    {
        $db = App::db();
        $versions = new VersionRepo($db);
        $version = $versions->find($id);
        $newsletter = $version === null ? null : (new NewsletterRepo($db))->find((int) $version['newsletter_id']);
        if ($version === null || $newsletter === null) {
            View::error(404);
            exit;
        }
        return [$newsletter, $version, $versions->images($id)];
    }

    private static function sendZip(array $newsletter, array $version, array $images): void
    {
        $name = self::fileName($newsletter, $version);
        $zip = Exporter::zip(
            $version['html'],
            $images,
            Exporter::slug($newsletter['name']) . '.html',
            APP_ROOT,
            App::tmpDir() . '/exports'
        );
        try {
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $name . '.zip"');
            header('Content-Length: ' . filesize($zip));
            header('Cache-Control: private, no-store');
            readfile($zip);
        } finally {
            @unlink($zip);
        }
    }

    /** "Back-to-school-v3", or "Back-to-school-draft". */
    private static function fileName(array $newsletter, array $version): string
    {
        $suffix = $version['status'] === 'draft' ? Exporter::slug(__('draft')) : 'v' . (int) $version['number'];
        return Exporter::slug($newsletter['name']) . '-' . $suffix;
    }
}
