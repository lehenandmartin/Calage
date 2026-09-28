<?php
/**
 * "Degraded preview": a discreet icon opening a menu with two checkboxes (see assets/preview.js).
 * @var string $degradeFrame   selector of the preview <iframe>
 * @var bool   $degradeRight   menu aligned right (button at the end of the row)
 */
?>
<details class="menu degrade" data-degrade="<?= e($degradeFrame) ?>" data-degrade-status="#degrade-status">
    <summary class="btn btn-ghost btn-icon" title="<?= e(__('Degraded preview')) ?>">
        <?= icon('options') ?><span class="visually-hidden"><?= e(__('Degraded preview')) ?></span>
    </summary>
    <div class="menu-panel<?= !empty($degradeRight) ? ' right' : '' ?>">
        <p class="menu-label"><?= e(__('Degraded preview')) ?></p>
        <label class="menu-item">
            <input type="checkbox" name="fonts">
            <?= icon('text-font') ?>
            <span><?= e(__('No web fonts')) ?><small><?= e(__('Fallback font, like Outlook on Windows')) ?></small></span>
        </label>
        <label class="menu-item">
            <input type="checkbox" name="images">
            <?= icon('image-off') ?>
            <span><?= e(__('No images')) ?><small><?= e(__('Like an email client that blocks images')) ?></small></span>
        </label>
    </div>
</details>
<span class="chip chip-draft degrade-status" id="degrade-status" hidden></span>
