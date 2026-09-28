<?php /** @var ?array $target  @var ?int $folderId  @var array{upload_max_filesize: string, post_max_size: string, max_file_uploads: string} $limits */ ?>
<div class="page page-narrow">
    <?php if ($target === null): ?>
        <a class="back" href="<?= e(url($folderId !== null ? '/?folder=' . $folderId : '/')) ?>"><?= icon('chevron-left') ?><?= e(__('Newsletters')) ?></a>
        <div class="page-head"><h1><?= e(__('New newsletter')) ?></h1></div>
    <?php else: ?>
        <a class="back" href="<?= e(url('/newsletters/' . $target['id'])) ?>"><?= icon('chevron-left') ?><?= e($target['name']) ?></a>
        <div class="page-head"><h1><?= e(__('New version of “{name}”', ['name' => $target['name']])) ?></h1></div>
        <div class="bar bar-info" role="note" style="margin-bottom: 16px"><?= icon('info') ?>
            <p class="bar-body"><?= e(__('The file will become the draft. The HTML alone is enough: images whose path has not changed are taken from the current version.')) ?></p></div>
    <?php endif ?>

    <?php // With JavaScript: chunked upload (assets/upload.js). Without: plain submission of this form. ?>
    <form method="post" action="<?= e(url('/imports')) ?>" enctype="multipart/form-data" class="stack"
          data-upload data-start="<?= e(url('/uploads')) ?>" data-csrf="<?= e(Calage\Csrf::token()) ?>">
        <?= csrf_field() ?>
        <?php if ($target !== null): ?>
            <input type="hidden" name="newsletter" value="<?= (int) $target['id'] ?>">
        <?php elseif ($folderId !== null): ?>
            <input type="hidden" name="folder_id" value="<?= $folderId ?>">
        <?php endif ?>

        <div class="dropzone" data-dropzone>
            <div class="halo"><?= icon('arrow-upload') ?></div>
            <p class="dropzone-title"><?= e(__('Drop the HTML or MJML file, the zip or the folder here')) ?></p>
            <p class="muted"><?= e(__('Calage finds the images, hosts them and checks that none is missing. MJML is compiled in your browser.')) ?></p>
            <div class="dropzone-actions">
                <label class="btn btn-primary" for="upload-files"><?= icon('document') ?><?= e(__('Choose files')) ?></label>
                <span class="muted"><?= e(__('or')) ?></span>
                <label class="btn" for="upload-folder"><?= icon('folder') ?><?= e(__('Choose a folder')) ?></label>
            </div>
            <input type="file" id="upload-files" name="files[]" multiple accept=".html,.htm,.mjml,.zip,image/*" class="visually-hidden">
            <input type="file" id="upload-folder" name="folder[]" webkitdirectory multiple class="visually-hidden">
            <p class="dropzone-selection" data-selection></p>
        </div>

        <div class="upload-progress" data-progress hidden>
            <progress max="100" value="0"></progress>
            <p data-progress-text></p>
        </div>
        <div class="bar bar-error" role="alert" data-error-bar hidden><?= icon('error-circle') ?><p class="bar-body" data-error></p></div>

        <div class="stack" data-classic-submit>
            <div><button class="btn btn-primary" type="submit"><?= icon('arrow-upload') ?><?= e(__('Upload')) ?></button></div>
            <p class="small muted"><?= e(__('Server limits for this upload: {file} per file, {total} in total, {count} files.', [
                'file' => $limits['upload_max_filesize'], 'total' => $limits['post_max_size'], 'count' => $limits['max_file_uploads'],
            ])) ?></p>
        </div>
    </form>
</div>
<script src="<?= e(url('/assets/upload.js')) ?>" defer></script>
