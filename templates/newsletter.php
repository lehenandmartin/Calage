<?php
/** @var array $newsletter  @var list<array> $versions  @var ?array $shown  @var bool $hasDraft  @var bool $hasPublished
 *  @var string $clientUrl  @var list<array> $folders  @var ?array $sender  @var list<string> $unresolved
 *  @var ?array $draft  subject of the draft and suggestion (HTML <title>) for the "Publish without a subject?" dialog */
$id = (int) $newsletter['id'];
$nextNumber = 1 + max([0, ...array_map(fn(array $v): int => (int) $v['number'], $versions)]);
$live = null;
foreach ($versions as $v) {
    if ($v['status'] === 'published') { $live = $v; break; }
}
$folderName = null;
foreach ($folders as $f) {
    if ((int) $f['id'] === (int) $newsletter['folder_id']) { $folderName = $f['name']; }
}
$back = $newsletter['folder_id'] === null ? '/' : '/?folder=' . $newsletter['folder_id'];
?>
<div class="cmdbar" role="toolbar" aria-label="<?= e(__('Newsletter actions')) ?>">
    <a class="btn btn-ghost btn-icon" href="<?= e(url($back)) ?>" title="<?= e(__('Back to the list')) ?>"><?= icon('chevron-left') ?><span class="visually-hidden"><?= e(__('Back to the list')) ?></span></a>
    <span class="sep" aria-hidden="true"></span>
    <a class="btn btn-ghost" href="<?= e(url("/newsletters/$id/edit")) ?>"><?= icon('code') ?><span class="lbl"><?= e(__('Edit code')) ?></span></a>
    <a class="btn btn-ghost" href="<?= e(url("/import?newsletter=$id")) ?>"><?= icon('document-arrow-up') ?><span class="lbl"><?= e(__('New file')) ?></span></a>
    <?php if ($shown !== null): ?>
        <button class="btn btn-ghost" type="button" data-dialog="send-dialog"><?= icon('send') ?><span class="lbl"><?= e(__('Send a preview')) ?></span></button>
        <details class="menu">
            <summary class="btn btn-ghost"><?= icon('arrow-download') ?><span class="lbl"><?= e(__('Download')) ?></span><?= icon('chevron-down', 'small-icon') ?></summary>
            <div class="menu-panel">
                <a class="menu-item" href="<?= e(url('/versions/' . $shown['id'] . '/export.zip')) ?>" download>
                    <?= icon('folder-zip') ?><span><?= e(__('Zip')) ?><small><?= e(__('HTML with relative paths and an images/ folder')) ?></small></span></a>
                <a class="menu-item" href="<?= e(url('/versions/' . $shown['id'] . '/export.html')) ?>" download>
                    <?= icon('link') ?><span><?= e(__('HTML only')) ?><small><?= e(__('Hosted images, full addresses')) ?></small></span></a>
                <?php if ($shown['mjml'] !== null): ?>
                    <hr>
                    <p class="menu-label"><?= e(__('MJML source')) ?></p>
                    <a class="menu-item" href="<?= e(url('/versions/' . $shown['id'] . '/export-mjml.zip')) ?>" download>
                        <?= icon('folder-zip') ?><span><?= e(__('MJML zip')) ?><small><?= e(__('Source with relative paths and an images/ folder')) ?></small></span></a>
                    <a class="menu-item" href="<?= e(url('/versions/' . $shown['id'] . '/export.mjml')) ?>" download>
                        <?= icon('code') ?><span><?= e(__('MJML only')) ?><small><?= e(__('Hosted images, full addresses')) ?></small></span></a>
                <?php endif ?>
            </div>
        </details>
    <?php endif ?>
    <details class="menu">
        <summary class="btn btn-ghost btn-icon" title="<?= e(__('More actions')) ?>"><?= icon('more-horizontal') ?><span class="visually-hidden"><?= e(__('More actions')) ?></span></summary>
        <div class="menu-panel">
            <button class="menu-item" type="button" data-dialog="rename-dialog"><?= icon('rename') ?><?= e(__('Rename')) ?></button>
            <p class="menu-label"><?= e(__('Move to')) ?></p>
            <form method="post" action="<?= e(url("/newsletters/$id/move")) ?>" class="inline-form">
                <?= csrf_field() ?>
                <label class="visually-hidden" for="move-folder"><?= e(__('Folder')) ?></label>
                <select class="select" id="move-folder" name="folder_id">
                    <option value="0"><?= e(__('No folder')) ?></option>
                    <?php foreach ($folders as $f): ?>
                        <option value="<?= (int) $f['id'] ?>" <?= (int) $f['id'] === (int) $newsletter['folder_id'] ? 'selected' : '' ?>><?= e($f['name']) ?></option>
                    <?php endforeach ?>
                </select>
                <button class="btn" type="submit"><?= e(__('Move')) ?></button>
            </form>
            <hr>
            <?php if ($hasDraft && $hasPublished): ?>
                <form method="post" action="<?= e(url("/newsletters/$id/draft/discard")) ?>"
                      data-confirm="<?= e(__('Discard the draft? Its changes will be lost; the share link keeps showing the published version.')) ?>">
                    <?= csrf_field() ?>
                    <button class="menu-item" type="submit"><?= icon('dismiss') ?><span><?= e(__('Discard draft')) ?><small><?= e(__('Go back to the latest published version')) ?></small></span></button>
                </form>
            <?php endif ?>
            <form method="post" action="<?= e(url("/newsletters/$id/duplicate")) ?>"
                  data-confirm="<?= e(__('Duplicate this newsletter? The copy starts from the latest published version, as a draft, with its own share link.')) ?>">
                <?= csrf_field() ?>
                <button class="menu-item" type="submit"><?= icon('document-copy') ?><?= e(__('Duplicate')) ?></button>
            </form>
            <form method="post" action="<?= e(url("/newsletters/$id/delete")) ?>"
                  data-confirm="<?= e(__('Permanently delete “{name}” and all its versions? The share link will stop working. Images in emails already sent stay online.', ['name' => $newsletter['name']])) ?>">
                <?= csrf_field() ?>
                <button class="menu-item danger" type="submit"><?= icon('delete') ?><?= e(__('Delete newsletter')) ?></button>
            </form>
        </div>
    </details>
    <span class="grow"></span>
    <?php if ($hasDraft): ?>
        <form method="post" action="<?= e(url("/newsletters/$id/publish")) ?>"
              data-confirm="<?= e(__('Publish the draft as version {n}? It will be visible on the share link at once and can no longer be changed.', ['n' => $nextNumber])) ?>"
              data-subject-prompt data-subject-suggest="<?= e($draft['suggestion'] ?? '') ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="subject" value="<?= e($draft['subject'] ?? '') ?>">
            <button class="btn btn-primary" type="submit"><?= icon('checkmark-circle') ?><?= e(__('Publish version {n}', ['n' => $nextNumber])) ?></button>
        </form>
    <?php endif ?>
</div>

<div class="page detail">
    <article class="reading">
        <?php if ($shown !== null): ?>
            <header class="mail-head">
                <div class="mail-title">
                    <h1 class="mail-subject"><?= $shown['subject'] !== '' ? e($shown['subject']) : '<span class="not-set">' . e(__('No subject')) . '</span>' ?></h1>
                    <?php // Preheader right under the subject, in grey and without a label, as an email client shows it. ?>
                    <?php if ($shown['preheader'] !== ''): ?>
                        <p class="mail-preheader"<?= (int) $shown['preheader_auto'] === 1 ? ' title="' . e(__('No preheader was entered: this is the start of the email text, as an email client shows it. It is updated on every save.')) . '"' : '' ?>><?= e($shown['preheader']) ?></p>
                    <?php endif ?>
                </div>
                <div class="mail-meta">
                    <span><strong><?= e($newsletter['name']) ?></strong><?= $folderName !== null ? ' · ' . e($folderName) : '' ?></span>
                    <?php if ($shown['mjml'] !== null): ?><span class="chip chip-neutral" title="<?= e(__('MJML source, editable in the editor')) ?>">MJML</span><?php endif ?>
                    <?php if ($shown['status'] === 'draft'): ?>
                        <span class="chip chip-draft"><?= icon('drafts') ?><?= e(__('Draft · modified {date}', ['date' => long_date($shown['updated_at'])])) ?></span>
                    <?php else: ?>
                        <span class="chip chip-pub"><?= e(__('Version {n} · published {date}', ['n' => (int) $shown['number'], 'date' => long_date($shown['published_at'])])) ?></span>
                    <?php endif ?>
                </div>
                <?php if ($sender !== null): ?>
                    <dl class="mail-from">
                        <dt><?= e(__('From')) ?></dt><dd><?= e($sender['name']) ?> <span class="muted">&lt;<?= e($sender['email']) ?>&gt;</span></dd>
                    </dl>
                <?php endif ?>
                <?php if ($unresolved !== []): ?>
                    <div class="bar bar-warning" role="note">
                        <?= icon('warning') ?>
                        <div class="bar-body">
                            <p><?= e(__n(
                                '{n} image has no hosted file; its path is left as is. To fix it, upload a new file with this image.',
                                '{n} images have no hosted file; their paths are left as is. To fix them, upload a new file with these images.',
                                count($unresolved)
                            )) ?></p>
                            <ul><?php foreach ($unresolved as $path): ?><li><code><?= e($path) ?></code></li><?php endforeach ?></ul>
                        </div>
                    </div>
                <?php endif ?>
            </header>
            <div class="preview-tools">
                <div class="seg" role="group" aria-label="<?= e(__('Preview width')) ?>" data-stage="#stage">
                    <button type="button" aria-pressed="true" data-device="desktop"><?= icon('desktop') ?><?= e(__('Desktop')) ?></button>
                    <button type="button" aria-pressed="false" data-device="mobile"><?= icon('phone') ?><?= e(__('Mobile')) ?></button>
                </div>
                <?php $degradeFrame = '#preview-frame'; $degradeRight = false; require __DIR__ . '/partials/degrade.php'; ?>
            </div>
            <div class="stage" id="stage">
                <iframe id="preview-frame" src="<?= e(url('/versions/' . $shown['id'] . '/render')) ?>" sandbox title="<?= e(__('Newsletter preview')) ?>"></iframe>
            </div>
        <?php endif ?>
    </article>

    <aside class="side">
        <section class="panel" aria-labelledby="link-title">
            <h2 id="link-title"><?= icon('link') ?><?= e(__('Share link')) ?></h2>
            <label class="visually-hidden" for="client-url"><?= e(__('Share link address')) ?></label>
            <input class="input mono" type="text" id="client-url" value="<?= e($clientUrl) ?>" readonly>
            <div class="actions" style="margin-top: 8px">
                <button class="btn" type="button" data-copy="#client-url" data-copied="<?= e(__('Share link copied.')) ?>"><?= icon('copy') ?><?= e(__('Copy link')) ?></button>
                <?php if ($hasPublished): ?>
                    <a class="btn" href="<?= e($clientUrl) ?>" target="_blank" rel="noopener"><?= icon('open') ?><?= e(__('Open')) ?></a>
                <?php endif ?>
            </div>
            <p class="small muted" style="margin-top: 8px">
                <?= e($live !== null ? __('The share link shows version {n}.', ['n' => (int) $live['number']]) : __('Active from the first publication.')) ?>
            </p>
        </section>

        <section class="panel" aria-labelledby="versions-title">
            <h2 id="versions-title"><?= icon('history') ?><?= e(__('Versions')) ?></h2>
            <ul class="versions">
                <?php foreach ($versions as $v): ?>
                    <?php $isShown = $shown !== null && (int) $v['id'] === (int) $shown['id']; ?>
                    <li class="version" <?= $isShown ? 'aria-current="true"' : '' ?>>
                        <a class="version-link" href="<?= e(url("/newsletters/$id?v=" . $v['id'])) ?>">
                            <?php if ($v['status'] === 'draft'): ?>
                                <span class="chip chip-draft"><?= e(__('Draft')) ?></span>
                            <?php else: ?>
                                <?= e(__('Version {n}', ['n' => (int) $v['number']])) ?>
                                <?php if ($v === $live): ?><span class="chip chip-pub"><?= e(__('live')) ?></span><?php endif ?>
                            <?php endif ?>
                        </a>
                        <span class="version-date"><?= e($v['status'] === 'draft'
                            ? __('modified {date}', ['date' => long_date($v['updated_at'])])
                            : __('published {date}', ['date' => long_date($v['published_at'])])) ?></span>
                        <?php if ($v['status'] === 'published' && $v !== $live): ?>
                            <form method="post" action="<?= e(url('/versions/' . $v['id'] . '/restore')) ?>"
                                  data-confirm="<?= e($hasDraft
                                      ? __('Restore version {n}? It will replace the current draft, whose changes will be lost.', ['n' => (int) $v['number']])
                                      : __('Restore version {n}? It will become the draft, to be published afterwards.', ['n' => (int) $v['number']])) ?>">
                                <?= csrf_field() ?>
                                <button class="btn" type="submit" title="<?= e(__('Restore version {n}', ['n' => (int) $v['number']])) ?>"><?= icon('arrow-reset') ?><?= e(__('Restore')) ?></button>
                            </form>
                        <?php endif ?>
                    </li>
                <?php endforeach ?>
            </ul>
        </section>
    </aside>
</div>

<?php if ($shown !== null): ?>
    <dialog class="dialog" id="send-dialog" aria-labelledby="send-title">
        <form method="post" action="<?= e(url('/versions/' . $shown['id'] . '/send')) ?>" class="form">
            <?= csrf_field() ?>
            <h2 id="send-title"><?= e(__('Send a preview')) ?></h2>
            <label class="field">
                <span><?= e(__('Recipients')) ?> <span class="hint"><?= e(__('separated by commas or line breaks, 20 at most')) ?></span></span>
                <textarea class="textarea" name="recipients" rows="3" required
                          placeholder="<?= e(__('jane@example.com, contact@example.com')) ?>"><?= e($_SESSION['recipients'] ?? '') ?></textarea>
            </label>
            <p class="small muted"><?= e($shown['subject'] !== ''
                ? __('Each address gets its own email, with the hosted images and the subject “{subject}”.', ['subject' => $shown['subject']])
                : __('Each address gets its own email, with the hosted images and the newsletter name as the subject (no subject set).')) ?></p>
            <div class="actions" style="justify-content: flex-end">
                <button class="btn" type="submit" formmethod="dialog" formnovalidate value="cancel"><?= e(__('Cancel')) ?></button>
                <button class="btn btn-primary" type="submit"><?= icon('send') ?><?= e(__('Send')) ?></button>
            </div>
        </form>
    </dialog>
<?php endif ?>

<dialog class="dialog" id="rename-dialog" aria-labelledby="rename-title">
    <form method="post" action="<?= e(url("/newsletters/$id/rename")) ?>" class="form">
        <?= csrf_field() ?>
        <h2 id="rename-title"><?= e(__('Rename newsletter')) ?></h2>
        <label class="field"><span><?= e(__('Name')) ?></span>
            <input class="input" type="text" name="name" value="<?= e($newsletter['name']) ?>" maxlength="200" required>
        </label>
        <div class="actions" style="justify-content: flex-end">
            <button class="btn" type="submit" formmethod="dialog" formnovalidate value="cancel"><?= e(__('Cancel')) ?></button>
            <button class="btn btn-primary" type="submit"><?= e(__('Rename')) ?></button>
        </div>
    </form>
</dialog>

<?php if ($hasDraft) { require __DIR__ . '/partials/subject-dialog.php'; } ?>
<script src="<?= e(url('/assets/preview.js')) ?>" defer></script>
