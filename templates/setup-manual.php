<?php /** @var string $configContent  content of config.php, to be uploaded by hand */ ?>
<div class="setup">
    <div class="setup-head">
        <div class="login-mark"><?php require __DIR__ . '/partials/mark.php' ?></div>
        <h1><?= e(__('Last step: upload config.php')) ?></h1>
    </div>
    <section class="panel panel-lg stack">
        <div class="bar bar-warning" role="note"><?= icon('warning') ?>
            <p class="bar-body"><?= __h('The hosting does not let PHP write {config}. Copy the content below into this file, upload it over FTP into the {data} folder, then reload this page.', [
                'config' => '<code>config.php</code>', 'data' => '<code>data/</code>',
            ]) ?></p></div>
        <textarea class="textarea mono" id="config-content" rows="18" readonly><?= e($configContent) ?></textarea>
        <div class="actions" style="justify-content: flex-end">
            <button class="btn btn-primary" type="button" data-copy="#config-content" data-copied="<?= e(__('Content copied.')) ?>"><?= icon('copy') ?><?= e(__('Copy the content')) ?></button>
        </div>
        <p class="small muted"><?= e(__('This content holds the password hash and, if you entered it, the SMTP password: do not share it.')) ?></p>
    </section>
</div>
