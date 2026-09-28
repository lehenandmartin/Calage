<?php
// Interface language for the scripts (assets/lang.js): translations as JSON, nothing in English.
$langMessages = Calage\Lang::jsMessages();
if ($langMessages !== []): ?>
    <script type="application/json" id="lang-messages"><?= json_encode($langMessages, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php endif ?>
    <script src="<?= e(url('/assets/lang.js')) ?>"></script>
