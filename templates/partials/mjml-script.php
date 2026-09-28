<?php
// MJML compiler (mjml-browser, version pinned in Calage\Mjml) and its adapter, loaded only where MJML is compiled.
// mjml-browser 5 reads window.cheerio when it loads (inline styles, mj-html-attributes) without bundling it:
// cheerio comes from assets/vendor/cheerio.js, which must load first.
?>
<script src="<?= e(url('/assets/vendor/cheerio.js')) ?>"></script>
<script src="<?= e(Calage\Mjml::SCRIPT) ?>" integrity="<?= e(Calage\Mjml::INTEGRITY) ?>" crossorigin="anonymous"></script>
<script src="<?= e(url('/assets/mjml.js')) ?>"></script>
