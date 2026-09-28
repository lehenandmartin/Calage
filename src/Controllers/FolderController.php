<?php
declare(strict_types=1);

namespace Calage\Controllers;

use Calage\App;
use Calage\Repo\FolderRepo;
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
        if (!(new FolderRepo(App::db()))->deleteIfEmpty((int) $folder['id'])) {
            Session::flash('error', __('This folder still contains newsletters: move or delete them first.'));
            redirect('/?folder=' . $folder['id']);
        }
        Session::flash('success', __('Folder “{name}” deleted.', ['name' => $folder['name']]));
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
