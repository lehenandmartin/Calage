<?php
declare(strict_types=1);

namespace Calage\Controllers;

use Calage\App;
use Calage\Html\Renderer;
use Calage\Lang;
use Calage\Repo\NewsletterRepo;
use Calage\Repo\VersionRepo;
use Calage\View;

/**
 * Public share page (/c/{token}): published versions only, never drafts.
 * No session is opened here: the visitor gets no cookie.
 */
final class ClientController
{
    public function __construct()
    {
        self::useVisitorLanguage();
    }

    /** The share page speaks the visitor's language (from the browser), or the one of the settings. */
    public static function useVisitorLanguage(): void
    {
        Lang::set(Lang::fromBrowser(Lang::configured()));
        header('Vary: Accept-Language');
        header('Content-Language: ' . Lang::current());
    }
    public function latest(array $params): void
    {
        [$newsletter, $versions] = $this->newsletter($params);
        $version = $versions->latestPublished((int) $newsletter['id']);
        if ($version === null) {
            self::headers();
            View::error(404, __('This newsletter is not available yet. Please try again a little later.'), 'client-layout');
            return;
        }
        $this->page($newsletter, $versions, $version);
    }

    public function version(array $params): void
    {
        [$newsletter, $versions] = $this->newsletter($params);
        $version = $versions->publishedByNumber((int) $newsletter['id'], (int) $params['n']);
        if ($version === null) {
            self::headers();
            View::error(404, __('This version does not exist.'), 'client-layout');
            return;
        }
        $this->page($newsletter, $versions, $version);
    }

    /** HTML of the published version, for the <iframe sandbox>. */
    public function render(array $params): void
    {
        [$newsletter, $versions] = $this->newsletter($params);
        $version = $versions->publishedByNumber((int) $newsletter['id'], (int) $params['n']);
        if ($version === null) {
            http_response_code(404);
            return;
        }
        header('X-Robots-Tag: noindex, nofollow');
        View::sandboxed(Renderer::absolute($version['html'], $versions->images((int) $version['id']), App::basePath()));
    }

    private function page(array $newsletter, VersionRepo $versions, array $version): void
    {
        self::headers();
        $list = $versions->publishedList((int) $newsletter['id']);
        View::render('client', [
            'title' => $version['subject'] !== '' ? $version['subject'] : $newsletter['name'],
            'newsletter' => $newsletter,
            'version' => $version,
            'versions' => $list,
            'isLatest' => (int) $version['number'] === (int) $list[0]['number'],
            'sharedBy' => trim((string) (App::config()['smtp']['from_name'] ?? '')),
        ], 'client-layout');
    }

    /** @return array{0: array, 1: VersionRepo} */
    private function newsletter(array $params): array
    {
        $db = App::db();
        $newsletter = (new NewsletterRepo($db))->findByToken($params['token']);
        if ($newsletter === null) {
            self::headers();
            View::error(404, __('This link is not valid. Check that it was copied in full.'), 'client-layout');
            exit;
        }
        return [$newsletter, new VersionRepo($db)];
    }

    private static function headers(): void
    {
        header('X-Robots-Tag: noindex, nofollow');
        header('Referrer-Policy: no-referrer'); // the link token does not leak to other sites
        header('Cache-Control: no-cache');      // a newly published version shows up at once
    }
}
