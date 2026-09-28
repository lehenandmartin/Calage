<?php
/**
 * @var string $title
 * @var string $content
 * @var ?array $nav           folder pane (null when logged out)
 * @var string $navCurrent    selected pane item: 'all', 'none', a folder id, or ''
 * @var ?int $navFolderId     folder suggested for "New newsletter"
 */
use Calage\Session;

$navCurrent ??= '';
$navFolderId ??= null;
$flashes = Session::takeFlashes();
$bars = array_filter($flashes, fn(array $f): bool => in_array($f['type'], ['error', 'warning'], true));
$toasts = array_filter($flashes, fn(array $f): bool => !in_array($f['type'], ['error', 'warning'], true));
$barIcons = ['error' => 'error-circle', 'warning' => 'warning'];
?>
<!doctype html>
<html lang="<?= e(Calage\Lang::current()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($title ?? 'Calage') ?> · Calage</title>
    <link rel="icon" href="<?= e(url('/assets/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="icon" href="<?= e(url('/assets/favicon-32.png')) ?>" type="image/png" sizes="32x32">
    <link rel="apple-touch-icon" href="<?= e(url('/assets/apple-touch-icon.png')) ?>">
    <link rel="stylesheet" href="<?= e(url('/assets/app.css')) ?>">
<?php require __DIR__ . '/partials/lang-head.php' ?>
</head>
<body>
<header class="topbar">
    <?php if ($nav !== null): ?>
        <button class="top-btn nav-toggle" type="button" data-nav-toggle aria-controls="nav" aria-expanded="false" title="<?= e(__('Folders')) ?>">
            <?= icon('navigation') ?><span class="visually-hidden"><?= e(__('Show folders')) ?></span>
        </button>
    <?php endif ?>
    <a class="brand" href="<?= e(url('/')) ?>">
        <?php require __DIR__ . '/partials/mark.php' ?>
        <span>Calage</span>
    </a>
    <?php if ($nav !== null): ?>
        <form class="search" role="search" method="get" action="<?= e(url('/')) ?>">
            <?= icon('search') ?>
            <label class="visually-hidden" for="search"><?= e(__('Search')) ?></label>
            <input type="search" id="search" name="q" value="<?= e($_GET['q'] ?? '') ?>"
                   placeholder="<?= e(__('Search newsletters, subjects…')) ?>" autocomplete="off">
        </form>
        <div class="top-actions">
            <a class="top-btn" href="<?= e(url('/settings')) ?>" title="<?= e(__('Settings')) ?>" <?= $navCurrent === 'settings' ? 'aria-current="page"' : '' ?>>
                <?= icon('settings') ?><span class="visually-hidden"><?= e(__('Settings')) ?></span>
            </a>
            <form method="post" action="<?= e(url('/logout')) ?>">
                <?= csrf_field() ?>
                <button class="top-btn" type="submit" title="<?= e(__('Sign out')) ?>"><?= icon('sign-out') ?><span class="visually-hidden"><?= e(__('Sign out')) ?></span></button>
            </form>
        </div>
    <?php endif ?>
</header>

<?php if ($nav !== null): ?>
<div class="shell">
    <nav class="nav" id="nav" aria-label="<?= e(__('Folders')) ?>">
        <a class="btn btn-primary nav-new" href="<?= e(url('/import' . ($navFolderId !== null ? '?folder=' . $navFolderId : ''))) ?>">
            <?= icon('compose') ?> <?= e(__('New newsletter')) ?>
        </a>
        <div>
            <p class="nav-title"><?= e(__('Folders')) ?></p>
            <ul class="folders">
                <li>
                    <a class="folder" href="<?= e(url('/')) ?>" <?= $navCurrent === 'all' ? 'aria-current="page"' : '' ?>>
                        <?= icon('mail-inbox') ?><span class="folder-label"><?= e(__('All newsletters')) ?></span>
                        <span class="folder-count"><?= (int) $nav['total'] ?></span>
                    </a>
                </li>
                <?php foreach ($nav['folders'] as $f): ?>
                    <?php $current = $navCurrent === (string) $f['id']; ?>
                    <li>
                        <a class="folder" href="<?= e(url('/?folder=' . $f['id'])) ?>" <?= $current ? 'aria-current="page"' : '' ?>>
                            <?= icon($current ? 'folder-open' : 'folder') ?><span class="folder-label"><?= e($f['name']) ?></span>
                            <?php if ((int) $f['newsletter_count'] > 0): ?><span class="folder-count"><?= (int) $f['newsletter_count'] ?></span><?php endif ?>
                        </a>
                    </li>
                <?php endforeach ?>
                <?php if ($nav['withoutFolder'] > 0 && $nav['folders'] !== []): ?>
                    <li>
                        <a class="folder is-muted" href="<?= e(url('/?folder=0')) ?>" <?= $navCurrent === 'none' ? 'aria-current="page"' : '' ?>>
                            <?= icon('document') ?><span class="folder-label"><?= e(__('No folder')) ?></span>
                            <span class="folder-count"><?= (int) $nav['withoutFolder'] ?></span>
                        </a>
                    </li>
                <?php endif ?>
                <li>
                    <details class="nav-add">
                        <summary class="folder is-muted"><?= icon('folder-add') ?><span class="folder-label"><?= e(__('New folder')) ?></span></summary>
                        <form method="post" action="<?= e(url('/folders')) ?>" class="nav-add-form">
                            <?= csrf_field() ?>
                            <label class="visually-hidden" for="new-folder"><?= e(__('Folder name')) ?></label>
                            <input class="input" type="text" id="new-folder" name="name" placeholder="<?= e(__('Folder name')) ?>" maxlength="200" required>
                            <button class="btn btn-primary" type="submit"><?= e(__('Create folder')) ?></button>
                        </form>
                    </details>
                </li>
            </ul>
        </div>
        <p class="nav-foot">
            <a href="<?= e(Calage\App::REPOSITORY) ?>" target="_blank" rel="noopener" title="<?= e(__('Calage source code on GitHub')) ?>">Calage <?= e(Calage\App::VERSION) ?></a>
        </p>
    </nav>
    <main class="main" id="main">
        <?php if ($bars !== []): ?>
            <div class="flash-bars">
                <?php foreach ($bars as $bar): ?>
                    <div class="bar bar-<?= e($bar['type']) ?>" role="alert"><?= icon($barIcons[$bar['type']]) ?><p class="bar-body"><?= e($bar['message']) ?></p></div>
                <?php endforeach ?>
            </div>
        <?php endif ?>
        <?= $content ?>
    </main>
</div>
<?php else: ?>
<main class="solo">
    <?php foreach ($bars as $bar): ?>
        <div class="bar bar-<?= e($bar['type']) ?>" role="alert" style="width: min(380px, 100%); margin-bottom: 16px"><?= icon($barIcons[$bar['type']]) ?><p class="bar-body"><?= e($bar['message']) ?></p></div>
    <?php endforeach ?>
    <?= $content ?>
</main>
<?php endif ?>

<div class="toasts" id="toasts" aria-live="polite">
    <?php foreach ($toasts as $toast): ?>
        <div class="toast" role="status"><?= icon('checkmark-circle') ?><p><?= e($toast['message']) ?></p>
            <button type="button" data-dismiss title="<?= e(__('Close')) ?>"><?= icon('dismiss') ?><span class="visually-hidden"><?= e(__('Close')) ?></span></button></div>
    <?php endforeach ?>
</div>
<template id="toast-template">
    <div class="toast" role="status"><?= icon('checkmark-circle') ?><p></p>
        <button type="button" data-dismiss title="<?= e(__('Close')) ?>"><?= icon('dismiss') ?><span class="visually-hidden"><?= e(__('Close')) ?></span></button></div>
</template>
<script src="<?= e(url('/assets/app.js')) ?>" defer></script>
</body>
</html>
