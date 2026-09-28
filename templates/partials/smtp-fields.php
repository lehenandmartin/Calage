<?php
/**
 * SMTP fields, shared by the setup wizard and the Settings page.
 * @var array $smtp  @var array $errors  @var bool $hasSmtpPassword  @var string $smtpTestUrl  @var string $smtpTestTo
 */
require_once __DIR__ . '/field-error.php';
?>
<div class="smtp-fields" data-smtp-fields>
    <div class="form-grid">
        <label class="field span-2"><span><?= e(__('SMTP server')) ?> <span class="hint"><?= e(__('empty = no email sending')) ?></span></span>
            <input class="input" type="text" name="smtp[host]" value="<?= e($smtp['host']) ?>" placeholder="<?= e(__('smtp.my-host.com')) ?>" autocomplete="off"<?= invalid($errors, 'smtp.host') ?>>
            <?= field_error($errors, 'smtp.host') ?>
        </label>
        <label class="field"><span><?= e(__('Port')) ?></span>
            <input class="input" type="number" name="smtp[port]" value="<?= (int) $smtp['port'] ?>" min="1" max="65535"<?= invalid($errors, 'smtp.port') ?>>
            <?= field_error($errors, 'smtp.port') ?>
        </label>
        <label class="field"><span><?= e(__('Encryption')) ?></span>
            <select class="select" name="smtp[encryption]">
                <?php foreach (['tls' => __('TLS (port 587)'), 'ssl' => __('SSL (port 465)'), 'none' => __('None')] as $value => $label): ?>
                    <option value="<?= $value ?>" <?= $smtp['encryption'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach ?>
            </select>
        </label>
        <label class="field"><span><?= e(__('Username')) ?></span>
            <input class="input" type="text" name="smtp[username]" value="<?= e($smtp['username']) ?>" autocomplete="off">
        </label>
        <label class="field"><span><?= e(__('Password')) ?></span>
            <input class="input" type="password" name="smtp[password]" value="" autocomplete="new-password"
                   placeholder="<?= $hasSmtpPassword ? e(__('•••••• (unchanged)')) : '' ?>">
        </label>
        <label class="field"><span><?= e(__('Sender address')) ?></span>
            <input class="input" type="email" name="smtp[from_email]" value="<?= e($smtp['from_email']) ?>" placeholder="<?= e(__('newsletters@my-domain.com')) ?>"<?= invalid($errors, 'smtp.from_email') ?>>
            <?= field_error($errors, 'smtp.from_email') ?>
        </label>
        <label class="field"><span><?= e(__('Sender name')) ?></span>
            <input class="input" type="text" name="smtp[from_name]" value="<?= e($smtp['from_name']) ?>" placeholder="<?= e(__('My agency')) ?>">
        </label>
    </div>
    <div class="smtp-test">
        <label class="visually-hidden" for="smtp-test-to"><?= e(__('Test email address')) ?></label>
        <input class="input" type="email" id="smtp-test-to" value="<?= e($smtpTestTo) ?>" placeholder="<?= e(__('Address that will receive the test email')) ?>">
        <button class="btn" type="button" data-smtp-test="<?= e($smtpTestUrl) ?>"><?= icon('send') ?><?= e(__('Send a test email')) ?></button>
    </div>
    <div data-smtp-result hidden></div>
</div>
