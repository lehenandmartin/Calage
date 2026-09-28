// "Publish without a subject?": when a form marked data-subject-prompt is sent with an empty subject field,
// offer to fill it in first (suggestion: data-subject-suggest, the <title> of the HTML). Either button goes on
// with the sending and stands for the form's data-confirm; Escape cancels it.
(function () {
    'use strict';
    var dialog = document.getElementById('subject-dialog');
    if (!dialog || !dialog.showModal) return;
    var input = dialog.querySelector('input');
    var pending = null;

    // On window, in the capture phase: before the other submit handlers (data-confirm in app.js).
    window.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form.hasAttribute || !form.hasAttribute('data-subject-prompt') || form.dataset.confirmed === '1') return;
        var field = form.elements.subject;
        if (!field || field.value.trim() !== '') return;
        e.preventDefault();
        e.stopImmediatePropagation();
        pending = { form: form, submitter: e.submitter };
        input.value = form.getAttribute('data-subject-suggest') || '';
        dialog.returnValue = '';
        dialog.showModal();
        input.focus();
        input.select();
    }, true);

    dialog.addEventListener('close', function () {
        var job = pending;
        pending = null;
        if (!job || (dialog.returnValue !== 'use' && dialog.returnValue !== 'without')) return; // Escape
        if (dialog.returnValue === 'use') job.form.elements.subject.value = input.value.trim();
        job.form.dataset.confirmed = '1'; // the dialog was the confirmation
        var submitter = job.submitter && job.submitter.form === job.form ? job.submitter : undefined;
        if (job.form.requestSubmit) job.form.requestSubmit(submitter); else job.form.submit();
    });
})();
