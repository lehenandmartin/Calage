<?php
declare(strict_types=1);

namespace Calage\Controllers;

use Calage\App;
use Calage\Repo\FolderRepo;
use Calage\Repo\NewsletterRepo;
use Calage\View;

final class DashboardController
{
    /** Newsletter list: all of them, those of a folder (?folder=id) or those without a folder (?folder=0). */
    public function index(array $params): void
    {
        $db = App::db();
        $folders = new FolderRepo($db);
        $newsletters = new NewsletterRepo($db);

        $filter = isset($_GET['folder']) && ctype_digit((string) $_GET['folder']) ? (int) $_GET['folder'] : null;
        $folder = $filter ? $folders->find($filter) : null;
        if ($filter && $folder === null) {
            View::error(404);
            return;
        }
        $search = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);

        View::render('dashboard', [
            'title' => $search !== '' ? __('Search') : ($folder['name'] ?? ($filter === 0 ? __('No folder') : __('Newsletters'))),
            'filter' => $filter,
            'folder' => $folder,
            'search' => $search,
            'newsletters' => $newsletters->all($search !== '' ? null : $filter, $search),
            'baseUrlMismatch' => $this->baseUrlMismatch(),
            'navCurrent' => $search !== '' ? '' : ($filter === null ? 'all' : ($filter === 0 ? 'none' : (string) $filter)),
            'navFolderId' => $folder === null ? null : (int) $folder['id'],
        ]);
    }

    /** base_url is used for the links sent to recipients: warn when it does not match the address in use. */
    private function baseUrlMismatch(): ?string
    {
        $configured = rtrim((string) (App::config()['base_url'] ?? ''), '/');
        $detected = App::detectedBaseUrl();
        $normalize = fn(string $u): string => strtolower((string) preg_replace('#^https?://#', '', $u));
        return $normalize($configured) === $normalize($detected) ? null : $detected;
    }
}
