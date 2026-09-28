<?php
/**
 * Setup wizard (assets/setup.js shows it step by step; without JavaScript, everything is visible).
 * @var list<array{label: string, ok: bool, blocking: bool}> $checks  @var bool $blocking  @var bool $writable
 * @var array{base_url: string, username: string, smtp: array} $values  @var array<string, string> $errors
 * @var bool $hasSmtpPassword
 */
require_once __DIR__ . '/partials/field-error.php';
$steps = [__('Environment'), __('Address and account'), __('Email sending'), __('Summary')];
// Step to reopen after an error: the one of the first field in error.
$firstStep = 1;
if (isset($errors['env'])) {
    $firstStep = 1;
} elseif (array_intersect_key($errors, array_flip(['base_url', 'username', 'password', 'password_confirm']))) {
    $firstStep = 2;
} elseif (array_filter(array_keys($errors), fn(string $k): bool => str_starts_with($k, 'smtp.'))) {
    $firstStep = 3;
}
?>
<form method="post" action="<?= e(url('/setup')) ?>" class="setup" data-setup data-start="<?= $firstStep ?>" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="locale" value="<?= e(Calage\Lang::current()) ?>">
    <div class="setup-head">
        <div class="login-mark"><?php require __DIR__ . '/partials/mark.php' ?></div>
        <h1><?= e(__('Calage setup')) ?></h1>
        <?php // The language picker belongs to the GET form below (form="lang-form"): changing it reloads the wizard. ?>
        <div class="setup-lang">
            <?= icon('translate') ?>
            <label class="visually-hidden" for="setup-lang"><?= e(__('Language')) ?></label>
            <select class="select" id="setup-lang" name="lang" form="lang-form" data-lang-switch>
                <?php foreach (Calage\Lang::LOCALES as $code => $name): ?>
                    <option value="<?= e($code) ?>" lang="<?= e($code) ?>" <?= $code === Calage\Lang::current() ? 'selected' : '' ?>><?= e($name) ?></option>
                <?php endforeach ?>
            </select>
            <noscript><button class="btn" type="submit" form="lang-form"><?= e(__('Change')) ?></button></noscript>
        </div>
        <ol class="setup-steps" data-steps hidden>
            <?php foreach ($steps as $i => $label): ?>
                <li data-step-label="<?= $i + 1 ?>"><span class="step-number"><?= $i + 1 ?></span><span class="step-label"><?= e($label) ?></span></li>
            <?php endforeach ?>
        </ol>
    </div>

    <?php if ($errors !== []): ?>
        <div class="bar bar-error" role="alert"><?= icon('error-circle') ?>
            <p class="bar-body"><?= e($errors['env'] ?? __('Some fields need to be corrected.')) ?></p></div>
    <?php endif ?>

    <section class="panel panel-lg stack" data-step="1" aria-labelledby="step-1">
        <h2 id="step-1"><?= e(__('1. Environment')) ?></h2>
        <ul class="checks">
            <?php foreach ($checks as $check): ?>
                <li class="<?= $check['ok'] ? 'is-ok' : ($check['blocking'] ? 'is-ko' : 'is-warn') ?>">
                    <?= icon($check['ok'] ? 'checkmark-circle' : ($check['blocking'] ? 'error-circle' : 'warning')) ?><?= e($check['label']) ?>
                </li>
            <?php endforeach ?>
        </ul>
        <?php if ($blocking): ?>
            <div class="bar bar-error" role="alert"><?= icon('error-circle') ?>
                <p class="bar-body"><?= __h('Fix the items in red (PHP extensions, write permissions on {data} and {images}), then reload this page.', ['data' => '<code>data/</code>', 'images' => '<code>i/</code>']) ?></p></div>
        <?php elseif (!$writable): ?>
            <div class="bar bar-warning" role="note"><?= icon('warning') ?>
                <p class="bar-body"><?= __h('PHP cannot write {config}: at the end, you will be given its content to upload over FTP.', ['config' => '<code>config.php</code>']) ?></p></div>
        <?php endif ?>
        <div class="setup-nav"><span></span><button class="btn btn-primary" type="button" data-next <?= $blocking ? 'disabled' : '' ?>><?= e(__('Continue')) ?></button></div>
    </section>

    <section class="panel panel-lg stack" data-step="2" aria-labelledby="step-2">
        <h2 id="step-2"><?= e(__('2. Address and account')) ?></h2>
        <label class="field"><span><?= e(__('Public address')) ?> <span class="hint"><?= e(__('the one of the share links and of the images in emails')) ?></span></span>
            <input class="input" type="url" name="base_url" value="<?= e($values['base_url']) ?>" required<?= invalid($errors, 'base_url') ?>>
            <?= field_error($errors, 'base_url') ?>
        </label>
        <label class="field"><span><?= e(__('Username')) ?></span>
            <input class="input" type="text" name="username" value="<?= e($values['username']) ?>" autocomplete="username" required maxlength="100"<?= invalid($errors, 'username') ?>>
            <?= field_error($errors, 'username') ?>
        </label>
        <div class="form-grid">
            <label class="field"><span><?= e(__('Password')) ?> <span class="hint"><?= e(__('at least 10 characters')) ?></span></span>
                <input class="input" type="password" name="password" autocomplete="new-password" required minlength="10"<?= invalid($errors, 'password') ?>>
                <?= field_error($errors, 'password') ?>
            </label>
            <label class="field"><span><?= e(__('Confirmation')) ?></span>
                <input class="input" type="password" name="password_confirm" autocomplete="new-password" required minlength="10"<?= invalid($errors, 'password_confirm') ?>>
                <?= field_error($errors, 'password_confirm') ?>
            </label>
        </div>
        <div class="setup-nav"><button class="btn" type="button" data-prev><?= e(__('Back')) ?></button><button class="btn btn-primary" type="button" data-next><?= e(__('Continue')) ?></button></div>
    </section>

    <section class="panel panel-lg stack" data-step="3" aria-labelledby="step-3">
        <h2 id="step-3"><?= e(__('3. Email sending')) ?> <span class="muted small"><?= e(__('(optional)')) ?></span></h2>
        <p class="muted"><?= e(__('To send previews by email. Leave the server empty to set up sending later, from the Settings page.')) ?></p>
        <?php
        $smtp = $values['smtp'];
        $smtpTestUrl = url('/setup/smtp-test');
        $smtpTestTo = '';
        require __DIR__ . '/partials/smtp-fields.php';
        ?>
        <div class="setup-nav"><button class="btn" type="button" data-prev><?= e(__('Back')) ?></button><button class="btn btn-primary" type="button" data-next><?= e(__('Continue')) ?></button></div>
    </section>

    <section class="panel panel-lg stack" data-step="4" aria-labelledby="step-4">
        <h2 id="step-4"><?= e(__('4. Summary')) ?></h2>
        <dl class="dl" data-summary hidden>
            <dt><?= e(__('Language')) ?></dt><dd><?= e(Calage\Lang::LOCALES[Calage\Lang::current()]) ?></dd>
            <dt><?= e(__('Public address')) ?></dt><dd data-show="base_url"></dd>
            <dt><?= e(__('Username')) ?></dt><dd data-show="username"></dd>
            <dt><?= e(__('Email sending')) ?></dt><dd data-show="smtp"></dd>
        </dl>
        <p class="muted"><?= __h('Calage will write {config}, then sign you in.', ['config' => '<code>config.php</code>']) ?></p>
        <div class="setup-nav">
            <button class="btn" type="button" data-prev><?= e(__('Back')) ?></button>
            <button class="btn btn-primary" type="submit" <?= $blocking ? 'disabled' : '' ?>><?= icon('checkmark-circle') ?><?= e(__('Install Calage')) ?></button>
        </div>
    </section>
</form>
<form id="lang-form" method="get" action="<?= e(url('/setup')) ?>" hidden></form>
<script src="<?= e(url('/assets/smtp-test.js')) ?>" defer></script>
<script src="<?= e(url('/assets/setup.js')) ?>" defer></script>
