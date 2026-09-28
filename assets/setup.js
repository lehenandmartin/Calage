// Setup wizard: one step at a time, with the fields checked before moving on.
// Without JavaScript, all steps stay visible and the form is sent in one go.
(function () {
    'use strict';
    var form = document.querySelector('[data-setup]');
    if (!form) return;
    var t = window.CalageLang.t;

    // Language picker: reloads the wizard in the chosen language.
    var langSwitch = document.querySelector('[data-lang-switch]');
    if (langSwitch) {
        langSwitch.addEventListener('change', function () { document.getElementById('lang-form').submit(); });
    }
    var sections = form.querySelectorAll('[data-step]');
    var labels = form.querySelectorAll('[data-step-label]');
    var current = Number(form.getAttribute('data-start')) || 1;

    form.querySelector('[data-steps]').hidden = false;
    form.querySelector('[data-summary]').hidden = false;

    function show(step) {
        current = step;
        sections.forEach(function (s) { s.hidden = Number(s.getAttribute('data-step')) !== step; });
        labels.forEach(function (l) {
            var n = Number(l.getAttribute('data-step-label'));
            l.classList.toggle('is-current', n === step);
            l.classList.toggle('is-done', n < step);
        });
        if (step === 4) summarize();
        var first = form.querySelector('[data-step="' + step + '"] input:not([type=hidden]), [data-step="' + step + '"] button[data-next]');
        if (first) first.focus({ preventScroll: true });
        window.scrollTo(0, 0);
    }

    // Checks the fields of the step (and the confirmed password) before moving to the next one.
    function valid(step) {
        var section = form.querySelector('[data-step="' + step + '"]');
        var fields = section.querySelectorAll('input[required], input[type=url], input[type=email][name]');
        for (var i = 0; i < fields.length; i++) {
            if (!fields[i].checkValidity()) { fields[i].reportValidity(); return false; }
        }
        if (step === 2) {
            var confirm = form.elements.password_confirm;
            if (form.elements.password.value !== confirm.value) {
                confirm.setCustomValidity(t('The two passwords do not match.'));
                confirm.reportValidity();
                confirm.setCustomValidity('');
                return false;
            }
        }
        if (step === 3 && form.elements['smtp[host]'].value.trim() !== '' && form.elements['smtp[from_email]'].value.trim() === '') {
            var from = form.elements['smtp[from_email]'];
            from.setCustomValidity(t('Enter the sender address for emails.'));
            from.reportValidity();
            from.setCustomValidity('');
            return false;
        }
        return true;
    }

    function summarize() {
        var host = form.elements['smtp[host]'].value.trim();
        var values = {
            base_url: form.elements.base_url.value.trim(),
            username: form.elements.username.value.trim(),
            smtp: host === '' ? t('to be set up later') : t('{server}, sender {address}', {
                server: host + ':' + form.elements['smtp[port]'].value,
                address: form.elements['smtp[from_email]'].value.trim()
            })
        };
        form.querySelectorAll('[data-show]').forEach(function (dd) { dd.textContent = values[dd.getAttribute('data-show')]; });
    }

    form.addEventListener('click', function (e) {
        if (e.target.closest('[data-next]') && valid(current)) show(current + 1);
        if (e.target.closest('[data-prev]')) show(current - 1);
    });
    // Enter in a field: go to the next step rather than sending the form.
    form.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && e.target.matches('input') && current < 4) {
            e.preventDefault();
            if (valid(current)) show(current + 1);
        }
    });
    show(current);
})();
