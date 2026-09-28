<?php
/** @var array $newsletter  @var array $version  @var list<array> $versions  @var bool $isLatest  @var string $sharedBy */
$token = $newsletter['token'];
$number = (int) $version['number'];
?>
<header class="c-head">
    <div class="c-inner">
        <p class="c-shared"><?= icon('mail') ?><span><?= $sharedBy !== '' ? __h('{name} shared this newsletter with you', ['name' => '<strong>' . e($sharedBy) . '</strong>']) : e(__('Newsletter shared with you')) ?></span></p>
        <div class="c-mail">
            <h1 class="c-subject"><?= $version['subject'] !== '' ? e($version['subject']) : e($newsletter['name']) ?></h1>
            <?php if ($version['preheader'] !== ''): ?>
                <?php // The (?) explains what a preheader is: shown on hover, keyboard focus or tap (client.css). ?>
                <p class="mail-preheader c-preheader"><?= e($version['preheader']) ?>
                    <span class="tip">
                        <button class="tip-button" type="button" aria-label="<?= e(__('What is the preheader?')) ?>" aria-describedby="preheader-tip"><?= icon('question-circle') ?></button>
                        <span class="tip-text" role="tooltip" id="preheader-tip"><?= e(__('The preheader is the short preview text that email clients show next to or below the subject, in the inbox, before the email is opened.')) ?></span>
                    </span>
                </p>
            <?php endif ?>
        </div>
        <div class="c-row">
            <div class="mail-meta">
                <?php $chipClass = $isLatest ? 'chip-pub' : 'chip-neutral'; ?>
                <?php if (count($versions) > 1): ?>
                    <?php // The version chip doubles as the version picker. ?>
                    <form method="get" action="<?= e(url("/c/$token")) ?>" data-version-picker>
                        <label class="visually-hidden" for="version-select"><?= e(__('Version shown')) ?></label>
                        <select class="chip chip-select <?= $chipClass ?>" id="version-select" name="v">
                            <?php foreach ($versions as $i => $v): ?>
                                <option value="<?= e(url("/c/$token/v/" . (int) $v['number'])) ?>" <?= (int) $v['number'] === $number ? 'selected' : '' ?>>
                                    <?= e($i === 0 ? __('Version {n} · latest', ['n' => (int) $v['number']]) : __('Version {n}', ['n' => (int) $v['number']])) ?>
                                </option>
                            <?php endforeach ?>
                        </select>
                        <noscript><button class="btn" type="submit"><?= e(__('Show')) ?></button></noscript>
                    </form>
                <?php else: ?>
                    <span class="chip <?= $chipClass ?>"><?= e($isLatest ? __('Version {n} · latest', ['n' => $number]) : __('Version {n}', ['n' => $number])) ?></span>
                <?php endif ?>
                <span><?= e(__('published {date}', ['date' => long_date($version['published_at'])])) ?></span>
            </div>
            <div class="c-tools">
                <div class="seg" role="group" aria-label="<?= e(__('Preview width')) ?>" data-stage="#c-stage">
                    <button type="button" aria-pressed="true" data-device="desktop"><?= icon('desktop') ?><?= e(__('Desktop')) ?></button>
                    <button type="button" aria-pressed="false" data-device="mobile"><?= icon('phone') ?><?= e(__('Mobile')) ?></button>
                </div>
                <?php $degradeFrame = '#client-frame'; $degradeRight = true; require __DIR__ . '/partials/degrade.php'; ?>
                <a class="btn btn-primary" href="<?= e(url("/c/$token/v/$number/zip")) ?>" download><?= icon('arrow-download') ?><?= e(__('Download the zip')) ?></a>
            </div>
        </div>
    </div>
    <?php if (!$isLatest): ?>
        <div class="c-old">
            <div class="bar bar-warning" role="note"><?= icon('history') ?>
                <p class="bar-body"><span><?= e(__('You are viewing an older version.')) ?> <a href="<?= e(url("/c/$token")) ?>"><?= e(__('See the latest one')) ?></a></span></p>
            </div>
        </div>
    <?php endif ?>
</header>

<main class="c-stage">
    <div class="c-frame" id="c-stage">
        <iframe id="client-frame" src="<?= e(url("/c/$token/v/$number/render")) ?>" sandbox title="<?= e(__('Newsletter preview')) ?>"></iframe>
    </div>
    <p class="c-note"><?= e(__('Preview in the browser: the display may differ in Outlook, Gmail or on mobile.')) ?></p>
    <p class="c-foot"><?php require __DIR__ . '/partials/mark.php' ?><?= __h('Shared with {calage}', ['calage' => '<a href="' . e(Calage\App::REPOSITORY) . '" target="_blank" rel="noopener">Calage</a>']) ?></p>
</main>

<script src="<?= e(url('/assets/preview.js')) ?>" defer></script>
