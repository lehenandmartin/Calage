<?php /** @var int $code  @var string $title  @var string $detail  @var bool $backOffice */ ?>
<div class="<?= $backOffice && !empty($nav) ? 'page' : '' ?>" style="display:grid;justify-items:center">
    <section class="empty" style="width: min(560px, 100%)">
        <div class="halo"><?= icon($code === 404 ? 'search' : 'error-circle') ?></div>
        <h1 style="font-size: 18px"><?= e($title) ?></h1>
        <?php if ($detail !== ''): ?>
            <?php if ($code === 500): ?>
                <pre class="smtp-log error-detail mono" style="text-align:left;width:100%"><?= e($detail) ?></pre>
            <?php else: ?>
                <p><?= e($detail) ?></p>
            <?php endif ?>
        <?php endif ?>
        <?php if ($backOffice): ?>
            <a class="btn" href="<?= e(url('/')) ?>"><?= icon('mail-inbox') ?><?= e(__('Back to newsletters')) ?></a>
        <?php endif ?>
    </section>
</div>
