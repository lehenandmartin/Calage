<?php
declare(strict_types=1);

namespace Calage\Controllers;

use Calage\App;
use Calage\Html\Renderer;
use Calage\Repo\VersionRepo;
use Calage\Session;
use Calage\View;

final class VersionController
{
    private const SUBJECT_MAX = 250;
    private const PREHEADER_MAX = 500;

    /**
     * Subject and preheader edited in place on the newsletter page (double-click), for any version, without
     * creating a new one. JSON {subject, preheader} → {subject, preheader, preheaderAuto, preheaderText}.
     */
    public function updateHeader(array $params): void
    {
        $body = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($body) || !isset($body['subject'], $body['preheader'])) {
            json_response(['error' => __('Bad request')], 400);
        }
        $clean = fn(mixed $value, int $max): string => mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) $value)), 0, $max);
        $version = (new VersionRepo(App::db()))->updateHeader(
            (int) $params['id'],
            $clean($body['subject'], self::SUBJECT_MAX),
            $clean($body['preheader'], self::PREHEADER_MAX)
        );
        if ($version === null) {
            json_response(['error' => __('Page not found')], 404);
        }
        json_response([
            'subject' => $version['subject'],
            'preheader' => VersionRepo::userPreheader($version), // '' when automatic
            'preheaderAuto' => (int) $version['preheader_auto'] === 1,
            'preheaderText' => $version['preheader'],            // what is displayed
        ]);
    }

    /** HTML of the version (drafts included) with hosted images, for the back-office preview. */
    public function render(array $params): void
    {
        $versions = new VersionRepo(App::db());
        $version = $versions->find((int) $params['id']);
        if ($version === null) {
            View::error(404);
            return;
        }

        // Domain-relative /i/… paths: the preview works even when base_url is set wrong.
        View::sandboxed(Renderer::absolute($version['html'], $versions->images((int) $version['id']), App::basePath()));
    }

    /** Restores a published version: it becomes the draft (replacing the current one). */
    public function restore(array $params): void
    {
        $versions = new VersionRepo(App::db());
        $version = $versions->find((int) $params['id']);
        if ($version === null || $version['status'] !== 'published') {
            View::error(404);
            return;
        }
        $versions->restoreAsDraft((int) $version['id']);
        Session::flash('success', __('Version {n} restored as a draft. Publish it to make it visible on the share link.', ['n' => (int) $version['number']]));
        redirect('/newsletters/' . $version['newsletter_id']);
    }
}
