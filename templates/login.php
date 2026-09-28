<?php /** @var string $username */ ?>
<section class="panel panel-lg login">
    <div class="login-mark"><?php require __DIR__ . '/partials/mark.php' ?></div>
    <h1><?= e(__('Sign in to Calage')) ?></h1>
    <form method="post" action="<?= e(url('/login')) ?>" class="form">
        <?= csrf_field() ?>
        <label class="field"><span><?= e(__('Username')) ?></span>
            <input class="input" type="text" name="username" value="<?= e($username) ?>" autocomplete="username" required autofocus>
        </label>
        <label class="field"><span><?= e(__('Password')) ?></span>
            <input class="input" type="password" name="password" autocomplete="current-password" required>
        </label>
        <button class="btn btn-primary" type="submit"><?= icon('lock-closed') ?><?= e(__('Sign in')) ?></button>
    </form>
</section>
