<?php
/** @var ?int $filter  @var ?array $folder  @var string $search  @var list<array> $newsletters  @var ?string $baseUrlMismatch */
use Calage\App;

$showFolder = $filter === null;
$count = count($newsletters);
$listPath = '/' . ($search !== '' ? '?q=' . rawurlencode($search) : ($filter !== null ? '?folder=' . $filter : ''));
?>
<div class="page">
    <div class="stack" style="margin-bottom: 16px">
        <div class="bar bar-error" id="data-exposed" role="alert" hidden>
            <?= icon('error-circle') ?>
            <p class="bar-body"><?= __h('The {data} folder can be read from the web: the database is exposed. Check that the {htaccess} file is taken into account (Apache 2.4 with {rewrite}), or move {data} out of the site through {paths} in {config}.', [
                'data' => '<code>data/</code>', 'htaccess' => '<code>.htaccess</code>', 'rewrite' => '<code>mod_rewrite</code>',
                'paths' => '<code>paths</code>', 'config' => '<code>config.php</code>',
            ]) ?></p>
        </div>
        <?php if ($baseUrlMismatch !== null): ?>
            <div class="bar bar-warning" role="note">
                <?= icon('warning') ?>
                <p class="bar-body"><span><?= __h('The public address in the Settings does not match the address in use ({address}). Share links and email images will use the public address.', [
                    'address' => '<code>' . e($baseUrlMismatch) . '</code>',
                ]) ?></span></p>
            </div>
        <?php endif ?>
    </div>

    <div class="page-head">
        <?php if ($search !== ''): ?>
            <h1><?= e(__('Results for “{query}”', ['query' => $search])) ?></h1>
            <span class="meta"><?= e(__n('{n} newsletter', '{n} newsletters', $count)) ?></span>
            <a class="btn btn-ghost" href="<?= e(url('/')) ?>"><?= icon('dismiss') ?> <?= e(__('Clear search')) ?></a>
        <?php else: ?>
            <h1><?= $folder !== null ? e($folder['name']) : e($filter === 0 ? __('No folder') : __('All newsletters')) ?></h1>
            <span class="meta"><?= e(__n('{n} newsletter', '{n} newsletters', $count)) ?></span>
            <?php if ($folder !== null): ?>
                <details class="menu">
                    <summary class="btn btn-ghost btn-icon" title="<?= e(__('Folder actions')) ?>"><?= icon('more-horizontal') ?><span class="visually-hidden"><?= e(__('Folder actions')) ?></span></summary>
                    <div class="menu-panel">
                        <p class="menu-label"><?= e(__('Rename folder')) ?></p>
                        <form method="post" action="<?= e(url('/folders/' . $folder['id'] . '/rename')) ?>" class="inline-form">
                            <?= csrf_field() ?>
                            <label class="visually-hidden" for="folder-name"><?= e(__('Folder name')) ?></label>
                            <input class="input" type="text" id="folder-name" name="name" value="<?= e($folder['name']) ?>" maxlength="200" required>
                            <button class="btn" type="submit"><?= e(__('Rename')) ?></button>
                        </form>
                        <hr>
                        <?php if ($count === 0): ?>
                            <form method="post" action="<?= e(url('/folders/' . $folder['id'] . '/delete')) ?>"
                                  data-confirm="<?= e(__('Delete the folder “{name}”?', ['name' => $folder['name']])) ?>">
                                <?= csrf_field() ?>
                                <button class="menu-item danger" type="submit"><?= icon('delete') ?><?= e(__('Delete folder')) ?></button>
                            </form>
                        <?php else: ?>
                            <?php // Not empty: the dialog below asks what happens to the newsletters. ?>
                            <button class="menu-item danger" type="button" data-dialog="delete-folder-dialog"><?= icon('delete') ?><?= e(__('Delete folder…')) ?></button>
                        <?php endif ?>
                    </div>
                </details>
            <?php endif ?>
        <?php endif ?>
    </div>

    <?php if ($newsletters === []): ?>
        <div class="empty">
            <div class="halo"><?= icon($search !== '' ? 'search' : 'folder-open') ?></div>
            <?php if ($search !== ''): ?>
                <h2><?= e(__('No results')) ?></h2>
                <p><?= e(__('No newsletter matches “{query}”. The search looks at the name, folder, subject and preheader.', ['query' => $search])) ?></p>
            <?php elseif ($folder !== null): ?>
                <h2><?= e(__('This folder is empty')) ?></h2>
                <p><?= e(__('Import a newsletter here, or move one from its page with “Move to”.')) ?></p>
                <a class="btn btn-primary" href="<?= e(url('/import?folder=' . $folder['id'])) ?>"><?= icon('compose') ?> <?= e(__('New newsletter')) ?></a>
            <?php else: ?>
                <h2><?= e(__('No newsletters yet')) ?></h2>
                <p><?= e(__('Upload an HTML file, a zip or a folder: Calage hosts the images and creates a share link.')) ?></p>
                <a class="btn btn-primary" href="<?= e(url('/import')) ?>"><?= icon('compose') ?> <?= e(__('New newsletter')) ?></a>
            <?php endif ?>
        </div>
    <?php else: ?>
        <ul class="inbox">
            <?php foreach ($newsletters as $n): ?>
                <li>
                    <div class="row">
                        <div class="row-main">
                            <div class="row-title">
                                <a class="row-link" href="<?= e(url('/newsletters/' . $n['id'])) ?>"><?= e($n['name']) ?></a>
                                <?php if ($showFolder && $n['folder_name'] !== null): ?><span class="row-folder"><?= e($n['folder_name']) ?></span><?php endif ?>
                            </div>
                            <div class="row-subject"><?= ($n['subject'] ?? '') !== '' ? e($n['subject']) : '<span class="not-set">' . e(__('No subject')) . '</span>' ?></div>
                            <div class="row-pre"><?= ($n['preheader'] ?? '') !== '' ? e($n['preheader']) : '&nbsp;' ?></div>
                        </div>
                        <div class="row-side">
                            <time class="row-date" datetime="<?= e($n['updated_at']) ?>" title="<?= e(__('Modified {date}', ['date' => long_date($n['updated_at'])])) ?>"><?= e(short_date($n['updated_at'])) ?></time>
                            <div class="chips">
                                <?php if ($n['has_draft']): ?><span class="chip chip-draft"><?= icon('drafts') ?><?= e(__('Draft')) ?></span><?php endif ?>
                                <?php if ($n['last_number'] !== null): ?><span class="chip chip-pub" title="<?= e(__('Live version')) ?>">v<?= (int) $n['last_number'] ?></span><?php endif ?>
                            </div>
                        </div>
                        <div class="row-actions">
                            <?php if ($n['last_number'] !== null): ?>
                                <button class="btn btn-ghost btn-icon" type="button" title="<?= e(__('Copy share link')) ?>"
                                        data-copy-text="<?= e(App::absoluteUrl('/c/' . $n['token'])) ?>" data-copied="<?= e(__('Share link copied.')) ?>">
                                    <?= icon('link') ?><span class="visually-hidden"><?= e(__('Copy the share link of {name}', ['name' => $n['name']])) ?></span>
                                </button>
                            <?php endif ?>
                            <form method="post" action="<?= e(url('/newsletters/' . $n['id'] . '/duplicate')) ?>"
                                  data-confirm="<?= e(__('Duplicate “{name}”? The copy starts from the latest published version, as a draft, with its own share link.', ['name' => $n['name']])) ?>">
                                <?= csrf_field() ?>
                                <button class="btn btn-ghost btn-icon" type="submit" title="<?= e(__('Duplicate')) ?>">
                                    <?= icon('document-copy') ?><span class="visually-hidden"><?= e(__('Duplicate {name}', ['name' => $n['name']])) ?></span>
                                </button>
                            </form>
                            <form method="post" action="<?= e(url('/newsletters/' . $n['id'] . '/delete')) ?>"
                                  data-confirm="<?= e(__('Permanently delete “{name}” and all its versions? The share link will stop working. Images in emails already sent stay online.', ['name' => $n['name']])) ?>">
                                <?= csrf_field() ?>
                                <?php // Back to this very list (all, folder or search) once deleted. ?>
                                <input type="hidden" name="back" value="<?= e($listPath) ?>">
                                <button class="btn btn-ghost btn-icon btn-danger" type="submit" title="<?= e(__('Delete newsletter')) ?>">
                                    <?= icon('delete') ?><span class="visually-hidden"><?= e(__('Delete {name}', ['name' => $n['name']])) ?></span>
                                </button>
                            </form>
                        </div>
                    </div>
                </li>
            <?php endforeach ?>
        </ul>
    <?php endif ?>
</div>

<?php if ($folder !== null && $count > 0 && $search === ''): ?>
    <dialog class="dialog" id="delete-folder-dialog" aria-labelledby="delete-folder-title">
        <form method="post" action="<?= e(url('/folders/' . $folder['id'] . '/delete')) ?>" class="form">
            <?= csrf_field() ?>
            <h2 id="delete-folder-title"><?= e(__('Delete the folder “{name}”?', ['name' => $folder['name']])) ?></h2>
            <p class="muted"><?= e(__n(
                'It contains one newsletter. What should happen to it?',
                'It contains {n} newsletters. What should happen to them?',
                $count
            )) ?></p>
            <div class="stack">
                <button class="btn" type="submit" name="newsletters" value="keep"><?= icon('folder-arrow-right') ?><?= e(__n(
                    'Delete the folder, keep the newsletter',
                    'Delete the folder, keep the newsletters',
                    $count
                )) ?></button>
                <p class="small muted"><?= e(__('They move to “No folder”; their share links keep working.')) ?></p>
                <button class="btn btn-danger" type="submit" name="newsletters" value="delete"><?= icon('delete') ?><?= e(__n(
                    'Delete the folder and its newsletter',
                    'Delete the folder and its {n} newsletters',
                    $count
                )) ?></button>
                <p class="small muted"><?= e(__('Versions and share links are deleted for good. Images in emails already sent stay online.')) ?></p>
            </div>
            <div class="actions" style="justify-content: flex-end">
                <button class="btn" type="submit" formmethod="dialog" formnovalidate value="cancel"><?= e(__('Cancel')) ?></button>
            </div>
        </form>
    </dialog>
<?php endif ?>

<div data-probe="<?= e(url('/data/probe.txt')) ?>"></div>
