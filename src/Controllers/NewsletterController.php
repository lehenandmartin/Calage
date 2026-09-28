<?php
declare(strict_types=1);

namespace Calage\Controllers;

use Calage\App;
use Calage\Html\EditableText;
use Calage\Html\PreviewText;
use Calage\Html\Renderer;
use Calage\Mjml;
use Calage\Repo\FolderRepo;
use Calage\Repo\NewsletterRepo;
use Calage\Repo\VersionRepo;
use Calage\Session;
use Calage\View;

final class NewsletterController
{
    private const SUBJECT_MAX = 250;
    private const PREHEADER_MAX = 500;

    /** Newsletter page: versions, preview of the chosen version (?v=id), actions. */
    public function show(array $params): void
    {
        $newsletter = $this->newsletter($params);
        $versions = new VersionRepo(App::db());
        $list = $versions->forNewsletter((int) $newsletter['id']);

        $shownId = (int) ($_GET['v'] ?? ($list[0]['id'] ?? 0));
        $shown = $versions->find($shownId);
        if ($shown !== null && (int) $shown['newsletter_id'] !== (int) $newsletter['id']) {
            $shown = null;
        }

        View::render('newsletter', [
            'title' => $newsletter['name'],
            'newsletter' => $newsletter,
            'versions' => $list,
            'shown' => $shown,
            'hasDraft' => $list !== [] && $list[0]['status'] === 'draft',
            'draft' => ($draft = $versions->draft((int) $newsletter['id'])) === null ? null
                : ['subject' => $draft['subject'], 'suggestion' => PreviewText::title($draft['html'])],
            'hasPublished' => $list !== [] && end($list)['status'] === 'published',
            'clientUrl' => App::absoluteUrl('/c/' . $newsletter['token']),
            'folders' => (new FolderRepo(App::db()))->all(),
            'sender' => self::sender(),
            'navCurrent' => $newsletter['folder_id'] === null ? 'none' : (string) $newsletter['folder_id'],
            'navFolderId' => $newsletter['folder_id'] === null ? null : (int) $newsletter['folder_id'],
            'unresolved' => $shown === null ? [] : Renderer::unresolved($shown['html'], $versions->images((int) $shown['id'])),
        ]);
    }

    /** Editor: the draft, or the latest published version (the draft is created when saving). */
    public function edit(array $params): void
    {
        $newsletter = $this->newsletter($params);
        $versions = new VersionRepo(App::db());
        $current = $versions->current((int) $newsletter['id']);
        if ($current === null) {
            View::error(404);
            return;
        }

        $isMjml = $current['mjml'] !== null;
        View::render('editor', [
            'title' => __('Edit · {name}', ['name' => $newsletter['name']]),
            'isMjml' => $isMjml,
            'includes' => $isMjml ? Mjml::includes($current['mjml']) : [],
            'navCurrent' => $newsletter['folder_id'] === null ? 'none' : (string) $newsletter['folder_id'],
            'navFolderId' => $newsletter['folder_id'] === null ? null : (int) $newsletter['folder_id'],
            'newsletter' => $newsletter,
            'current' => $current,
            'text' => EditableText::toEditor($isMjml ? $current['mjml'] : $current['html']),
            'unresolved' => Renderer::unresolved($current['html'], $versions->images((int) $current['id'])),
            // Live preview: paths of the compiled HTML → hosted images (/i/… paths on the same domain).
            'imageUrls' => array_map(
                fn(array $image): string => App::url(\Calage\Storage\ImageStore::publicPath($image['hash'], $image['ext'])),
                $versions->images((int) $current['id'])
            ),
        ]);
    }

    public function saveDraft(array $params): void
    {
        $newsletter = $this->newsletter($params);
        $id = (int) $newsletter['id'];
        $subject = post_text('subject', self::SUBJECT_MAX);
        $preheader = post_text('preheader', self::PREHEADER_MAX);
        $db = App::db();
        $versions = new VersionRepo($db);
        $current = $versions->current($id);

        // MJML newsletter: the source and the HTML compiled in the browser are both received.
        $isMjml = $current !== null && $current['mjml'] !== null;
        $text = (string) ($_POST[$isMjml ? 'mjml' : 'html'] ?? '');
        $compiled = (string) ($_POST['html'] ?? '');
        if ($isMjml && (!isset($_POST['mjml']) || trim($compiled) === '')) {
            Session::flash('error', __('The compiled HTML was not received: compiling MJML requires JavaScript. Nothing was saved.'));
            redirect('/newsletters/' . $id . '/edit');
        }
        $source = $isMjml ? $current['mjml'] : $current['html'];

        // Nothing changed on a published version: no needless draft.
        if ($current !== null && $current['status'] === 'published'
            && EditableText::fromEditor($text, $source) === $source
            && $subject === $current['subject'] && $preheader === VersionRepo::userPreheader($current)) {
            Session::flash('success', __('No changes.'));
            redirect('/newsletters/' . $id . (isset($_POST['then_show']) ? '' : '/edit'));
        }

        $db->beginTransaction();
        try {
            $draftId = $versions->ensureDraft($id);
            if ($draftId === null) {
                throw new \LogicException('Newsletter without a version.');
            }
            $draft = $versions->find($draftId);
            if ($isMjml) {
                $mjml = EditableText::fromEditor($text, $draft['mjml']);
                // Unchanged source: the already compiled HTML is kept as is.
                $html = $mjml === $draft['mjml'] ? $draft['html'] : $compiled;
                $versions->updateDraft($draftId, $html, $subject, $preheader, $mjml);
            } else {
                $versions->updateDraft($draftId, EditableText::fromEditor($text, $draft['html']), $subject, $preheader);
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        Session::flash('success', __('Draft saved.'));
        redirect('/newsletters/' . $id . (isset($_POST['then_show']) ? '' : '/edit'));
    }

    public function publish(array $params): void
    {
        $newsletter = $this->newsletter($params);
        $versions = new VersionRepo(App::db());
        // Subject entered in the "Publish without a subject?" dialog, for a draft that has none.
        $subject = post_text('subject', self::SUBJECT_MAX);
        $draft = $versions->draft((int) $newsletter['id']);
        if ($draft !== null && $draft['subject'] === '' && $subject !== '') {
            $versions->setDraftSubject((int) $draft['id'], $subject);
        }
        $number = $versions->publish((int) $newsletter['id']);
        if ($number === null) {
            Session::flash('error', __('No draft to publish.'));
        } else {
            Session::flash('success', __('Version {n} published: it is visible on the share link.', ['n' => $number]));
        }
        redirect('/newsletters/' . $newsletter['id']);
    }

    public function discardDraft(array $params): void
    {
        $newsletter = $this->newsletter($params);
        $versions = new VersionRepo(App::db());
        if ($versions->latestPublished((int) $newsletter['id']) === null) {
            // Without a published version, discarding the draft would empty the newsletter.
            Session::flash('error', __('This draft is the only version: it cannot be discarded.'));
        } else {
            $versions->discardDraft((int) $newsletter['id']);
            Session::flash('success', __('Draft discarded.'));
        }
        redirect('/newsletters/' . $newsletter['id']);
    }

    public function rename(array $params): void
    {
        $newsletter = $this->newsletter($params);
        $name = post_text('name');
        if ($name !== '') {
            (new NewsletterRepo(App::db()))->rename((int) $newsletter['id'], $name);
            Session::flash('success', __('Newsletter renamed.'));
        }
        redirect('/newsletters/' . $newsletter['id']);
    }

    public function move(array $params): void
    {
        $newsletter = $this->newsletter($params);
        $folderId = (int) ($_POST['folder_id'] ?? 0);
        $folder = $folderId === 0 ? null : (new FolderRepo(App::db()))->find($folderId);
        (new NewsletterRepo(App::db()))->move((int) $newsletter['id'], $folder === null ? null : (int) $folder['id']);
        Session::flash('success', $folder === null ? __('Newsletter removed from its folder.') : __('Newsletter moved to “{name}”.', ['name' => $folder['name']]));
        redirect('/newsletters/' . $newsletter['id']);
    }

    public function duplicate(array $params): void
    {
        $newsletter = $this->newsletter($params);
        $name = mb_substr(__('Copy of {name}', ['name' => $newsletter['name']]), 0, 200);
        $copyId = (new NewsletterRepo(App::db()))->duplicate((int) $newsletter['id'], $name);
        if ($copyId === null) {
            Session::flash('error', __('This newsletter has no version to copy.'));
            redirect('/newsletters/' . $newsletter['id']);
        }
        Session::flash('success', __('Copy created as a draft, with its own share link. Remember to rename it.'));
        redirect('/newsletters/' . $copyId);
    }

    public function delete(array $params): void
    {
        $newsletter = $this->newsletter($params);
        (new NewsletterRepo(App::db()))->delete((int) $newsletter['id']);
        Session::flash('success', __('“{name}” deleted. Its share link no longer works.', ['name' => $newsletter['name']]));
        redirect($newsletter['folder_id'] === null ? '/' : '/?folder=' . $newsletter['folder_id']);
    }

    /** Sender of the previews (smtp config), shown as in a received email. */
    private static function sender(): ?array
    {
        $smtp = App::config()['smtp'] ?? [];
        $email = (string) ($smtp['from_email'] ?? '');
        return $email === '' ? null : ['name' => (string) ($smtp['from_name'] ?? ''), 'email' => $email];
    }

    private function newsletter(array $params): array
    {
        $newsletter = (new NewsletterRepo(App::db()))->find((int) $params['id']);
        if ($newsletter === null) {
            View::error(404);
            exit;
        }
        return $newsletter;
    }
}
