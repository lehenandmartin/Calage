<?php
/**
 * Editable settings (they rewrite config.php through SettingsController).
 * @var bool $writable  @var string $locale  @var string $baseUrl  @var string $detectedBaseUrl  @var bool $localBaseUrl
 * @var string $username  @var array $smtp  @var bool $hasSmtpPassword  @var bool $smtpConfigured  @var array<string, string> $errors
 */
require_once __DIR__ . '/partials/field-error.php';
$disabled = $writable ? '' : ' disabled';
?>
<div class="page">
    <div class="page-head"><h1><?= e(__('Settings')) ?></h1></div>
    <div class="settings">
        <?php if (!$writable): ?>
            <div class="bar bar-warning" role="note"><?= icon('warning') ?>
                <p class="bar-body"><?= __h('PHP cannot modify {config} on this hosting: the settings are read-only and can be changed by editing this file by hand.', ['config' => '<code>config.php</code>']) ?></p></div>
        <?php endif ?>

        <form method="post" action="<?= e(url('/settings/language')) ?>" class="panel panel-lg stack" aria-labelledby="language-title">
            <?= csrf_field() ?>
            <h2 id="language-title" class="panel-title"><?= icon('translate') ?><?= e(__('Language')) ?></h2>
            <fieldset class="stack"<?= $disabled ?>>
                <label class="field"><span><?= e(__('Interface language')) ?> <span class="hint"><?= e(__('the share page follows the visitor’s browser')) ?></span></span>
                    <select class="select" name="locale"<?= invalid($errors, 'locale') ?>>
                        <?php foreach (Calage\Lang::LOCALES as $code => $name): ?>
                            <option value="<?= e($code) ?>" lang="<?= e($code) ?>" <?= $code === $locale ? 'selected' : '' ?>><?= e($name) ?></option>
                        <?php endforeach ?>
                    </select>
                    <?= field_error($errors, 'locale') ?>
                </label>
                <div class="panel-actions"><button class="btn btn-primary" type="submit"><?= e(__('Save the language')) ?></button></div>
            </fieldset>
        </form>

        <form method="post" action="<?= e(url('/settings/address')) ?>" class="panel panel-lg stack" aria-labelledby="address-title">
            <?= csrf_field() ?>
            <h2 id="address-title" class="panel-title"><?= icon('globe') ?><?= e(__('Public address')) ?></h2>
            <fieldset class="stack"<?= $disabled ?>>
                <label class="field"><span><?= e(__('Calage address')) ?> <span class="hint"><?= e(__('used for share links and images in emails')) ?></span></span>
                    <input class="input" type="url" name="base_url" value="<?= e($baseUrl) ?>" required<?= invalid($errors, 'base_url') ?>>
                    <?= field_error($errors, 'base_url') ?>
                </label>
                <?php if (rtrim($baseUrl, '/') !== $detectedBaseUrl): ?>
                    <p class="small muted"><?= __h('Address in use right now: {address}', ['address' => '<code>' . e($detectedBaseUrl) . '</code>']) ?></p>
                <?php endif ?>
                <?php if ($localBaseUrl): ?>
                    <div class="bar bar-warning" role="note"><?= icon('warning') ?>
                        <p class="bar-body"><?= e(__('Local address: share links and images in emails will only work on this machine.')) ?></p></div>
                <?php endif ?>
                <div class="panel-actions"><button class="btn btn-primary" type="submit"><?= e(__('Save the address')) ?></button></div>
            </fieldset>
        </form>

        <form method="post" action="<?= e(url('/settings/account')) ?>" class="panel panel-lg stack" aria-labelledby="account-title">
            <?= csrf_field() ?>
            <h2 id="account-title" class="panel-title"><?= icon('lock-closed') ?><?= e(__('Account')) ?></h2>
            <fieldset class="stack"<?= $disabled ?>>
                <label class="field"><span><?= e(__('Username')) ?></span>
                    <input class="input" type="text" name="username" value="<?= e($username) ?>" autocomplete="username" required maxlength="100"<?= invalid($errors, 'username') ?>>
                    <?= field_error($errors, 'username') ?>
                </label>
                <div class="form-grid">
                    <label class="field"><span><?= e(__('New password')) ?> <span class="hint"><?= e(__('leave empty to keep it')) ?></span></span>
                        <input class="input" type="password" name="password" autocomplete="new-password" minlength="10"<?= invalid($errors, 'password') ?>>
                        <?= field_error($errors, 'password') ?>
                    </label>
                    <label class="field"><span><?= e(__('Confirmation')) ?></span>
                        <input class="input" type="password" name="password_confirm" autocomplete="new-password"<?= invalid($errors, 'password_confirm') ?>>
                        <?= field_error($errors, 'password_confirm') ?>
                    </label>
                </div>
                <label class="field"><span><?= e(__('Current password')) ?> <span class="hint"><?= e(__('to confirm the change')) ?></span></span>
                    <input class="input" type="password" name="current_password" autocomplete="current-password" required<?= invalid($errors, 'current_password') ?>>
                    <?= field_error($errors, 'current_password') ?>
                </label>
                <div class="panel-actions"><button class="btn btn-primary" type="submit"><?= e(__('Save the account')) ?></button></div>
            </fieldset>
        </form>

        <form method="post" action="<?= e(url('/settings/smtp')) ?>" class="panel panel-lg stack" aria-labelledby="smtp-title">
            <?= csrf_field() ?>
            <h2 id="smtp-title" class="panel-title"><?= icon('mail') ?><?= e(__('Email sending (SMTP)')) ?>
                <span class="chip <?= $smtpConfigured ? 'chip-pub' : 'chip-neutral' ?>"><?= e($smtpConfigured ? __('On') : __('Off')) ?></span></h2>
            <fieldset class="stack"<?= $disabled ?>>
                <?php
                $smtpTestUrl = url('/settings/smtp-test');
                $smtpTestTo = (string) ($smtp['from_email'] ?? '');
                require __DIR__ . '/partials/smtp-fields.php';
                ?>
                <div class="panel-actions"><button class="btn btn-primary" type="submit"><?= e(__('Save the email settings')) ?></button></div>
            </fieldset>
        </form>

        <section class="panel panel-lg stack" aria-labelledby="about-title">
            <h2 id="about-title" class="panel-title"><?= icon('info') ?><?= e(__('About')) ?></h2>
            <dl class="dl">
                <dt><?= e(__('Version')) ?></dt><dd>Calage <?= e(Calage\App::VERSION) ?></dd>
                <dt><?= e(__('Source code')) ?></dt><dd><a href="<?= e(Calage\App::REPOSITORY) ?>" target="_blank" rel="noopener"><?= e(preg_replace('#^https?://#', '', Calage\App::REPOSITORY)) ?></a></dd>
                <dt>PHP</dt><dd><?= e(PHP_VERSION) ?></dd>
                <dt><?= e(__('MJML compiler')) ?></dt><dd><?= e(__('mjml-browser {version} (in the browser)', ['version' => Calage\Mjml::VERSION])) ?></dd>
            </dl>
        </section>
    </div>
</div>
<script src="<?= e(url('/assets/smtp-test.js')) ?>" defer></script>
