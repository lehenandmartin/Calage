<?php
declare(strict_types=1);

namespace Calage\Controllers;

use Calage\App;
use Calage\Repo\FolderRepo;
use Calage\Repo\NewsletterRepo;
use Calage\Session;
use Calage\View;

final class FolderController
{
    public function create(array $params): void
    {
        $name = post_text('name');
        if ($name === '') {
            Session::flash('error', __('Give the folder a name.'));
            redirect('/');
        }
        $id = (new FolderRepo(App::db()))->create($name);
        redirect('/?folder=' . $id);
    }

    public function rename(array $params): void
    {
        $folder = $this->folder($params);
        $name = post_text('name');
        if ($name !== '') {
            (new FolderRepo(App::db()))->rename((int) $folder['id'], $name);
            Session::flash('success', __('Folder renamed.'));
        }
        redirect('/?folder=' . $folder['id']);
    }

    public function delete(array $params): void
    {
        $folder = $this->folder($params);
        $id = (int) $folder['id'];
        // A folder that is not empty: the dialog says what happens to its newsletters (keep or delete).
        $mode = (string) ($_POST['newsletters'] ?? '');
        $count = count((new NewsletterRepo(App::db()))->all($id));
        if ($count > 0 && !in_array($mode, ['keep', 'delete'], true)) {
            Session::flash('error', __('Choose what happens to the newsletters of this folder.'));
            redirect('/?folder=' . $id);
        }
        $count = (new FolderRepo(App::db()))->delete($id, $mode === 'delete');
        Session::flash('success', match (true) {
            $count === 0 => __('Folder “{name}” deleted.', ['name' => $folder['name']]),
            $mode === 'delete' => __n('Folder “{name}” deleted, with its newsletter.', 'Folder “{name}” deleted, with its {n} newsletters.', $count, ['name' => $folder['name']]),
            default => __n('Folder “{name}” deleted. Its newsletter is now without a folder.', 'Folder “{name}” deleted. Its {n} newsletters are now without a folder.', $count, ['name' => $folder['name']]),
        });
        redirect('/');
    }

    private function folder(array $params): array
    {
        $folder = (new FolderRepo(App::db()))->find((int) $params['id']);
        if ($folder === null) {
            View::error(404);
            exit;
        }
        return $folder;
    }
}
