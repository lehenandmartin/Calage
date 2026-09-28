<?php /** @var array $newsletter  @var array $current  @var string $text  @var list<string> $unresolved
 *  @var bool $isMjml  MJML source (compiled in the browser on every save)  @var list<string> $includes
 *  @var array<string, string> $imageUrls  path as written → hosted image (MJML live preview) */
$id = (int) $newsletter['id'];
$cdn = 'https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/';
?>
<link rel="stylesheet" href="<?= $cdn ?>codemirror.min.css" integrity="sha384-zaeBlB/vwYsDRSlFajnDd7OydJ0cWk+c2OWybl3eSUf6hW2EbhlCsQPqKr3gkznT" crossorigin="anonymous">
<link rel="stylesheet" href="<?= $cdn ?>addon/dialog/dialog.min.css" integrity="sha384-MomRjC6IKuGHk2XIFKXAwFx0gytd6+ZsF9pnFM3JWZV5izBqPgoLapxRqG1h5IKm" crossorigin="anonymous">

<form method="post" action="<?= e(url("/newsletters/$id/draft")) ?>" id="editor-form">
    <?= csrf_field() ?>
    <div class="cmdbar" role="toolbar" aria-label="<?= e(__('Saving')) ?>">
        <a class="btn btn-ghost btn-icon" href="<?= e(url("/newsletters/$id")) ?>" title="<?= e(__('Back to the newsletter')) ?>"><?= icon('chevron-left') ?><span class="visually-hidden"><?= e(__('Back to the newsletter')) ?></span></a>
        <span class="sep" aria-hidden="true"></span>
        <button class="btn btn-primary" type="submit"><?= icon('save') ?><?= e(__('Save')) ?></button>
        <button class="btn btn-ghost" type="submit" name="then_show" value="1"><?= icon('eye') ?><span class="lbl"><?= e(__('Save and view preview')) ?></span></button>
        <span class="cmd-hint">⌘S / Ctrl+S</span>
        <?php if ($isMjml): ?>
            <button class="btn btn-ghost" type="button" data-live-show hidden><?= icon('eye') ?><span class="lbl"><?= e(__('Show preview')) ?></span></button>
        <?php endif ?>
        <span class="grow"></span>
        <?php if ($isMjml): ?><span class="chip chip-neutral">MJML <?= e(Calage\Mjml::VERSION) ?></span><?php endif ?>
        <?php if ($current['status'] === 'draft'): ?>
            <span class="chip chip-draft"><?= icon('drafts') ?><?= e(__('Draft · modified {date}', ['date' => long_date($current['updated_at'])])) ?></span>
        <?php else: ?>
            <span class="chip chip-neutral"><?= e(__('Starting from version {n}', ['n' => (int) $current['number']])) ?></span>
        <?php endif ?>
    </div>

    <div class="page stack">
        <h1 class="visually-hidden"><?= e(__('Edit “{name}”', ['name' => $newsletter['name']])) ?></h1>
        <?php if ($current['status'] !== 'draft'): ?>
            <div class="bar bar-info" role="note"><?= icon('info') ?>
                <p class="bar-body"><?= e(__('You are starting from version {n}, which is published. A draft will be created when you save; the share link keeps showing version {n} until the draft is published.', ['n' => (int) $current['number']])) ?></p></div>
        <?php endif ?>
        <?php if ($unresolved !== []): ?>
            <div class="bar bar-warning" role="note"><?= icon('warning') ?>
                <div class="bar-body">
                    <p><?= e(__n(
                        '{n} image path without a hosted file. Images cannot be added in the editor: to do so, upload a new file with its images.',
                        '{n} image paths without a hosted file. Images cannot be added in the editor: to do so, upload a new file with its images.',
                        count($unresolved)
                    )) ?></p>
                    <ul><?php foreach ($unresolved as $path): ?><li><code><?= e($path) ?></code></li><?php endforeach ?></ul>
                </div></div>
        <?php endif ?>

        <div class="compose">
            <div class="compose-field">
                <label for="subject"><?= e(__('Subject')) ?></label>
                <input type="text" id="subject" name="subject" value="<?= e($current['subject']) ?>" maxlength="250" placeholder="<?= e(__('Email subject')) ?>">
            </div>
            <div class="compose-field">
                <label for="preheader"><?= e(__('Preheader')) ?></label>
                <?php // Empty field = preheader generated from the content on save; the current generated text is shown as the placeholder. ?>
                <input type="text" id="preheader" name="preheader" value="<?= e(Calage\Repo\VersionRepo::userPreheader($current)) ?>" maxlength="500"
                       placeholder="<?= e((int) $current['preheader_auto'] === 1 && $current['preheader'] !== ''
                           ? __('Automatic: {text}', ['text' => $current['preheader']])
                           : __('Left empty: the start of the email text, like an email client shows it')) ?>"
                       <?= $isMjml ? 'aria-describedby="preheader-sync"' : '' ?>>
                <?php if ($isMjml): ?>
                    <span class="compose-hint" id="preheader-sync" title="<?= e(__('Changing this field updates the <mj-preview> tag of the MJML (added if needed), and the other way round.')) ?>"><?= __h('linked to {tag}', ['tag' => '<code>&lt;mj-preview&gt;</code>']) ?></span>
                <?php endif ?>
            </div>
            <?php if (!$isMjml): ?>
                <div class="compose-code">
                    <label for="html" class="visually-hidden"><?= e(__('HTML code')) ?></label>
                    <textarea name="html" id="html" class="code" spellcheck="false"><?= e($text) ?></textarea>
                </div>
            <?php endif ?>
        </div>

        <?php if ($isMjml): ?>
            <?php // MJML code and live preview side by side (assets/live.js). ?>
            <div class="live-split" data-live-split>
                <div class="compose live-code">
                    <label for="html" class="visually-hidden"><?= e(__('MJML code')) ?></label>
                    <textarea name="mjml" id="html" class="code" spellcheck="false" data-mjml><?= e($text) ?></textarea>
                    <input type="hidden" name="html" value="">
                </div>
                <div class="live-splitter" data-live-splitter role="separator" aria-orientation="vertical" aria-label="<?= e(__('Preview width')) ?>" tabindex="0"></div>
                <section class="live-preview" data-live-preview aria-label="<?= e(__('Live preview')) ?>">
                    <div class="live-bar">
                        <div class="seg" role="group" aria-label="<?= e(__('Preview width')) ?>" data-live-devices>
                            <button type="button" aria-pressed="true" data-device="desktop"><?= icon('desktop') ?><span class="lbl"><?= e(__('Desktop')) ?></span></button>
                            <button type="button" aria-pressed="false" data-device="mobile"><?= icon('phone') ?><span class="lbl"><?= e(__('Mobile')) ?></span></button>
                        </div>
                        <span class="live-state" data-live-state><?= e(__('Compiling…')) ?></span>
                        <span class="grow"></span>
                        <button class="btn btn-ghost btn-icon" type="button" data-live-hide title="<?= e(__('Hide preview')) ?>"><?= icon('dismiss') ?><span class="visually-hidden"><?= e(__('Hide preview')) ?></span></button>
                    </div>
                    <div class="live-stage" data-live-stage>
                        <?php // Two frames: the next one loads behind the scenes, then takes the place of the first (no flash). ?>
                        <iframe class="is-current" sandbox="allow-same-origin" title="<?= e(__('Live preview of the newsletter')) ?>"></iframe>
                        <iframe sandbox="allow-same-origin" title="<?= e(__('Live preview of the newsletter')) ?>" aria-hidden="true" tabindex="-1"></iframe>
                    </div>
                </section>
            </div>
            <script type="application/json" id="live-images"><?= json_encode($imageUrls, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
        <?php endif ?>

        <?php if ($isMjml): ?>
            <?php // MJML compilation status, updated while typing (assets/editor.js). ?>
            <div class="stack" data-mjml-status aria-live="polite">
                <div class="bar bar-success" data-mjml-ok hidden><?= icon('checkmark-circle') ?><p class="bar-body"><?= e(__('MJML compiled without errors.')) ?></p></div>
                <div class="bar bar-warning" data-mjml-errors hidden><?= icon('warning') ?>
                    <div class="bar-body"><p data-mjml-errors-title></p><ul data-mjml-errors-list></ul></div></div>
                <div class="bar bar-warning" data-mjml-includes <?= $includes === [] ? 'hidden' : '' ?>><?= icon('warning') ?>
                    <div class="bar-body"><p><?= __h('{tag} is not supported: the included content is missing from the compiled HTML.', ['tag' => '<strong><code>&lt;mj-include&gt;</code></strong>']) ?></p>
                        <ul data-mjml-includes-list><?php foreach ($includes as $include): ?><li><code><?= e($include) ?></code></li><?php endforeach ?></ul></div></div>
                <div class="bar bar-error" data-mjml-fatal hidden><?= icon('error-circle') ?><p class="bar-body" data-mjml-fatal-text></p></div>
                <noscript><div class="bar bar-error"><?= icon('error-circle') ?><p class="bar-body"><?= e(__('Compiling MJML requires JavaScript: saving is not possible without it.')) ?></p></div></noscript>
            </div>
        <?php endif ?>
    </div>
</form>

<script src="<?= $cdn ?>codemirror.min.js" integrity="sha384-ZYmwuq4n2gOcNxMSiJ6jyTj+BbIrilr7p6dlq6q5nmSWKmsH9UU4K1qqjycMkfmR" crossorigin="anonymous"></script>
<script src="<?= $cdn ?>mode/xml/xml.min.js" integrity="sha384-xPpkMo5nDgD98fIcuRVYhxkZV6/9Y4L8s3p0J5c4MxgJkyKJ8BJr+xfRkq7kn6Tw" crossorigin="anonymous"></script>
<script src="<?= $cdn ?>mode/javascript/javascript.min.js" integrity="sha384-g0o+WW9mdIxA7LaaCKTkRm0M5TVT+Bb4s9eocxPsI2G0Xm0POG9iD6G6qP1IIsfS" crossorigin="anonymous"></script>
<script src="<?= $cdn ?>mode/css/css.min.js" integrity="sha384-fpeIC2FZuPmw7mIsTvgB5BNc8QVxQC/nWg2W+CgPYOAiBiYVuHe2E8HiTWHBMIJQ" crossorigin="anonymous"></script>
<script src="<?= $cdn ?>mode/htmlmixed/htmlmixed.min.js" integrity="sha384-xYIbc5F55vPi7pb/lUnFj3wu24HlpAMZdtBHkNrb2YhPzJV3pX7+eqXT2PXSNMrw" crossorigin="anonymous"></script>
<script src="<?= $cdn ?>addon/dialog/dialog.min.js" integrity="sha384-3COleknUtlGKoEOR9Wm7WKVRyS6ljwYU2x1ebD8nd6ujaLMqwY+q3F8+yDcefbXr" crossorigin="anonymous"></script>
<script src="<?= $cdn ?>addon/search/searchcursor.min.js" integrity="sha384-ILkploZWukdp1VMmzMnE+32H0mgy2e+w29evc4grALGOqIRGBgbBGrwkX7a6zK7y" crossorigin="anonymous"></script>
<script src="<?= $cdn ?>addon/search/search.min.js" integrity="sha384-v64L7YTJ/ullw5v36qIJcvWAxuEnRGu9E326vUV3Ro7sx4HCZHIDTphKO53htazT" crossorigin="anonymous"></script>
<?php if ($isMjml): ?>
    <?php require __DIR__ . '/partials/mjml-script.php' ?>
    <?php // Before editor.js: the preview must be listening before the first compilation. ?>
    <script src="<?= e(url('/assets/live.js')) ?>"></script>
<?php endif ?>
<script src="<?= e(url('/assets/editor.js')) ?>"></script>
