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
