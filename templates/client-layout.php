<?php /** @var string $title  @var string $content */ ?>
<!doctype html>
<html lang="<?= e(Calage\Lang::current()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title><?= e($title ?? 'Newsletter') ?></title>
    <link rel="icon" href="<?= e(url('/assets/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="icon" href="<?= e(url('/assets/favicon-32.png')) ?>" type="image/png" sizes="32x32">
    <link rel="apple-touch-icon" href="<?= e(url('/assets/apple-touch-icon.png')) ?>">
    <link rel="stylesheet" href="<?= e(url('/assets/app.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('/assets/client.css')) ?>">
<?php require __DIR__ . '/partials/lang-head.php' ?>
</head>
<body class="client">
<?php if (isset($code)): /* error page (invalid link, version not found) */ ?>
    <main class="solo"><?= $content ?></main>
<?php else: ?>
    <?= $content ?>
<?php endif ?>
<script src="<?= e(url('/assets/client.js')) ?>" defer></script>
</body>
</html>
