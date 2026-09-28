<?php
/**
 * "Publish without a subject?" dialog, shown by assets/subject-prompt.js when a form marked data-subject-prompt
 * is sent with an empty subject field. It replaces the form's usual confirmation. Outside of that form:
 * a dialog has its own form.
 */
?>
<dialog class="dialog" id="subject-dialog" aria-labelledby="subject-dialog-title">
    <form method="dialog" class="form">
        <h2 id="subject-dialog-title"><?= e(__('Publish without a subject?')) ?></h2>
        <p class="muted"><?= e(__('This version has no subject. It is the first thing recipients read in their inbox, and a published version can no longer be changed.')) ?></p>
        <label class="field"><span><?= e(__('Subject')) ?></span>
            <input class="input" type="text" name="subject-prompt" maxlength="250" required placeholder="<?= e(__('Email subject')) ?>">
        </label>
        <div class="actions" style="justify-content: flex-end">
            <button class="btn" type="submit" value="without" formnovalidate><?= e(__('Publish without a subject')) ?></button>
            <button class="btn btn-primary" type="submit" value="use"><?= e(__('Publish with this subject')) ?></button>
        </div>
    </form>
</dialog>
<script src="<?= e(url('/assets/subject-prompt.js')) ?>" defer></script>
