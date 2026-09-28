<?php
/** @var Calage\Storage\Package $package  @var ?array $target  @var bool $replacesDraft  @var bool $targetIsMjml
 *  @var list<array> $folders  @var list<string> $htmlFiles  (sources: MJML, then HTML)  @var ?string $selected
 *  @var bool $isMjml  @var bool $needsCompile  @var ?string $mjmlSource  @var ?array $compiled  @var list<string> $includes
 *  @var ?string $mjmlPreview  text of <mj-preview>, used as the preheader
 *  @var ?array $report  (see Importer::analyze) */
$reviewUrl = url('/imports/' . $package->id());
$labels = ['found' => __('Found'), 'missing' => __('Missing'), 'unsupported' => __('Unsupported format')];
$icons = ['found' => 'checkmark-circle', 'missing' => 'error-circle', 'unsupported' => 'error-circle'];
$matchNotes = [
    'case' => __('different capitalization'),
    'accents' => __('different accents'),
    'name' => __('found by its name, in another folder'),
    'previous' => __('taken from the current version'),
];
?>
<div class="page page-narrow">
    <a class="back" href="<?= e(url('/import' . ($target !== null ? '?newsletter=' . $target['id'] : ''))) ?>"><?= icon('chevron-left') ?><?= e(__('Start the upload again')) ?></a>
    <div class="page-head">
        <h1><?= e($target !== null ? __('New version of “{name}”', ['name' => $target['name']]) : __('Review the import')) ?></h1>
    </div>

    <?php if ($selected === null): ?>
        <form method="get" action="<?= e(url('/imports/' . $package->id())) ?>" class="panel panel-lg form">
            <div>
                <h2><?= e(__('Which file is the newsletter?')) ?></h2>
                <p class="muted"><?= e(__('The upload contains several HTML or MJML files.')) ?></p>
            </div>
            <div class="stack" style="gap: 2px">
                <?php foreach ($htmlFiles as $i => $file): ?>
                    <label class="choice">
                        <input type="radio" name="html" value="<?= e($file) ?>" <?= $i === 0 ? 'checked' : '' ?>>
                        <code><?= e($file) ?></code>
                        <?php if (Calage\Mjml::isMjmlPath($file)): ?><span class="chip chip-neutral">MJML</span><?php endif ?>
                    </label>
                <?php endforeach ?>
            </div>
            <div><button class="btn btn-primary" type="submit"><?= e(__('Continue')) ?></button></div>
        </form>
    <?php elseif ($needsCompile): ?>
        <?php // MJML compiled in the browser (assets/mjml-import.js), then the resulting HTML is sent. ?>
        <div class="panel panel-lg form" data-mjml-import>
            <p class="mail-meta"><span><?= icon('code', 'small-icon') ?> <code><?= e($selected) ?></code></span><span class="chip chip-neutral">MJML <?= e(Calage\Mjml::VERSION) ?></span></p>
            <div class="stack" data-compiling>
                <h2><?= e(__('Compiling MJML…')) ?></h2>
                <p class="muted"><?= e(__('The MJML is compiled in your browser; the resulting HTML is then checked like any other newsletter.')) ?></p>
            </div>
            <div class="bar bar-error" role="alert" data-compile-error hidden><?= icon('error-circle') ?>
                <div class="bar-body"><p><strong><?= e(__('Compilation failed.')) ?></strong> <span data-compile-message></span></p>
                    <p><a href="<?= e($reviewUrl . '?html=' . rawurlencode($selected) . '&recompile=1') ?>"><?= e(__('Try again')) ?></a></p></div></div>
            <script type="application/json" id="mjml-source"><?= json_encode($mjmlSource, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
            <form method="post" action="<?= e($reviewUrl . '/compile') ?>" data-compile-form hidden>
                <?= csrf_field() ?>
                <input type="hidden" name="path" value="<?= e($selected) ?>">
                <input type="hidden" name="html">
                <input type="hidden" name="errors" value="[]">
            </form>
            <noscript><div class="bar bar-error"><?= icon('error-circle') ?><p class="bar-body"><?= e(__('Compiling MJML requires JavaScript.')) ?></p></div></noscript>
        </div>
        <?php require __DIR__ . '/partials/mjml-script.php' ?>
        <script src="<?= e(url('/assets/mjml-import.js')) ?>"></script>
    <?php else: ?>
        <?php $problems = array_filter($report['images'], fn(array $i): bool => $i['status'] !== 'found'); ?>
        <div class="panel panel-lg form">
            <p class="mail-meta">
                <span><?= icon($isMjml ? 'code' : 'document', 'small-icon') ?> <code><?= e($selected) ?></code></span>
                <?php if ($isMjml): ?><span class="chip chip-neutral">MJML <?= e($compiled['version']) ?></span><?php endif ?>
                <?php if (count($htmlFiles) > 1): ?>
                    <a href="<?= e($reviewUrl . '?html=') ?>"><?= e(__('Choose another file')) ?></a>
                <?php endif ?>
            </p>

            <?php if ($isMjml): ?>
                <div class="bar bar-info" role="note"><?= icon('info') ?>
                    <p class="bar-body"><span><?= e(__('Compiled in your browser with MJML {version}. The MJML source will be kept and editable.', ['version' => $compiled['version']])) ?>
                        <a href="<?= e($reviewUrl . '?html=' . rawurlencode($selected) . '&recompile=1') ?>"><?= e(__('Compile again')) ?></a></span></p></div>
                <?php if ($mjmlPreview !== null): ?>
                    <div class="bar bar-info" role="note"><?= icon('info') ?>
                        <?php $previewParams = ['tag' => '<code>&lt;mj-preview&gt;</code>', 'text' => e($mjmlPreview)]; ?>
                        <p class="bar-body"><?= $target !== null
                            ? __h('Preheader taken from {tag}: “{text}” (it replaces the one of the current version).', $previewParams)
                            : __h('Preheader taken from {tag}: “{text}”.', $previewParams) ?></p></div>
                <?php endif ?>
                <?php if ($includes !== []): ?>
                    <div class="bar bar-warning" role="note"><?= icon('warning') ?>
                        <div class="bar-body"><p><?= __h('{tag} is not supported: the included content is missing from the compiled HTML.', ['tag' => '<strong><code>&lt;mj-include&gt;</code></strong>']) ?>
                            <?= e(__('Put the whole newsletter in a single MJML file.')) ?></p>
                            <ul><?php foreach ($includes as $include): ?><li><code><?= e($include) ?></code></li><?php endforeach ?></ul></div></div>
                <?php endif ?>
                <?php if ($compiled['errors'] !== []): ?>
                    <div class="bar bar-warning" role="note"><?= icon('warning') ?>
                        <div class="bar-body"><p><?= e(__n(
                            '{n} MJML validation warning (the compilation still succeeded):',
                            '{n} MJML validation warnings (the compilation still succeeded):',
                            count($compiled['errors'])
                        )) ?></p>
                            <ul><?php foreach ($compiled['errors'] as $error): ?><li><?= e($error) ?></li><?php endforeach ?></ul></div></div>
                <?php endif ?>
            <?php endif ?>
            <?php if ($target !== null && $targetIsMjml && !$isMjml): ?>
                <div class="bar bar-warning" role="note"><?= icon('warning') ?>
                    <p class="bar-body"><?= e(__('This newsletter is in MJML. With this HTML file, the new version will be in HTML: its MJML can no longer be edited in Calage (earlier versions keep theirs).')) ?></p></div>
            <?php endif ?>

            <?php if ($problems !== []): ?>
                <div class="bar bar-warning" role="note"><?= icon('warning') ?>
                    <p class="bar-body"><?= e(__n(
                        '{n} image cannot be hosted. The import is still possible: this path will be left as is in the HTML.',
                        '{n} images cannot be hosted. The import is still possible: these paths will be left as is in the HTML.',
                        count($problems)
                    )) ?></p></div>
            <?php endif ?>

            <?php if ($report['images'] === []): ?>
                <p class="muted"><?= e(__('No local images in this HTML.')) ?></p>
            <?php else: ?>
                <div>
                    <h2 style="margin-bottom: 8px"><?= e(__('Images ({n})', ['n' => count($report['images'])])) ?></h2>
                    <ul class="report">
                        <?php foreach ($report['images'] as $image): ?>
                            <li class="is-<?= e($image['status']) ?>">
                                <?= icon($icons[$image['status']]) ?>
                                <code><?= e($image['raw']) ?></code>
                                <span class="file">
                                    <?php if ($image['path'] !== null): ?>→ <?= e($image['path']) ?><?php elseif ($image['match'] === 'previous'): ?><?= e(__('already hosted')) ?><?php else: ?><?= e(__('no matching file')) ?><?php endif ?>
                                    <?= $image['count'] > 1 ? ' · ' . e(__('used {n} times', ['n' => (int) $image['count']])) : '' ?>
                                    <?= isset($matchNotes[$image['match'] ?? '']) ? ' · ' . e($matchNotes[$image['match']]) : '' ?>
                                </span>
                                <span class="status"><?= e($labels[$image['status']]) ?></span>
                            </li>
                        <?php endforeach ?>
                    </ul>
                </div>
            <?php endif ?>
            <?php if ($report['external'] > 0): ?>
                <p class="small muted"><?= e(__n(
                    '{n} image already online (http, https, data:…), left as is.',
                    '{n} images already online (http, https, data:…), left as is.',
                    (int) $report['external']
                )) ?></p>
            <?php endif ?>

            <?php if ($target !== null && $replacesDraft): ?>
                <div class="bar bar-warning" role="note"><?= icon('warning') ?>
                    <p class="bar-body"><?= e(__('This newsletter already has a draft: it will be replaced by this file.')) ?></p></div>
            <?php endif ?>

            <form method="post" action="<?= e(url('/imports/' . $package->id() . '/confirm')) ?>" class="form"
                  <?= $target !== null && $replacesDraft ? 'data-confirm="' . e(__('Replace the current draft with this file?')) . '"' : '' ?>>
                <?= csrf_field() ?>
                <input type="hidden" name="html" value="<?= e($selected) ?>">
                <?php if ($target === null): ?>
                    <label class="field"><span><?= e(__('Newsletter name')) ?></span>
                        <input class="input" type="text" name="name" value="<?= e($package->sourceName()) ?>" maxlength="200" required>
                    </label>
                    <?php if ($folders !== []): ?>
                        <label class="field"><span><?= e(__('Folder')) ?></span>
                            <select class="select" name="folder_id">
                                <option value="0"><?= e(__('No folder')) ?></option>
                                <?php foreach ($folders as $f): ?>
                                    <option value="<?= (int) $f['id'] ?>" <?= (int) $f['id'] === $package->folderId() ? 'selected' : '' ?>><?= e($f['name']) ?></option>
                                <?php endforeach ?>
                            </select>
                        </label>
                    <?php endif ?>
                    <div><button class="btn btn-primary" type="submit"><?= icon('checkmark-circle') ?><?= e(__('Create newsletter')) ?></button></div>
                <?php else: ?>
                    <div><button class="btn btn-primary" type="submit"><?= icon('checkmark-circle') ?><?= e(__('Import as a draft')) ?></button></div>
                <?php endif ?>
            </form>
        </div>
    <?php endif ?>
</div>
