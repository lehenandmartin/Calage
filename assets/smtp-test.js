// "Send a test email": sends a test email with the SMTP settings as entered, without saving them.
(function () {
    'use strict';
    var t = window.CalageLang.t;
    document.addEventListener('click', function (e) {
        var button = e.target.closest('[data-smtp-test]');
        if (!button) return;
        var box = button.closest('[data-smtp-fields]');
        var result = box.querySelector('[data-smtp-result]');
        var form = button.closest('form');
        var smtp = {};
        box.querySelectorAll('[name^="smtp["]').forEach(function (field) {
            smtp[field.name.slice(5, -1)] = field.value;
        });
        var csrf = form.querySelector('[name=_csrf]').value;
        button.disabled = true;
        show('info', t('Sending the test email…'), null);
        fetch(button.getAttribute('data-smtp-test'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf, 'Accept': 'application/json' },
            body: JSON.stringify({ smtp: smtp, to: box.querySelector('#smtp-test-to').value })
        }).then(function (res) { return res.json(); }).then(function (data) {
            show(data.ok ? 'success' : 'error', data.message, data.log || null);
        }).catch(function () {
            show('error', t('The test could not be completed. Reload the page and try again.'), null);
        }).then(function () { button.disabled = false; });

        function show(type, message, log) {
            result.hidden = false;
            result.className = 'bar bar-' + type;
            result.textContent = '';
            var body = document.createElement('div');
            body.className = 'bar-body';
            var p = document.createElement('p');
            p.textContent = message;
            body.appendChild(p);
            if (log && log.length) {
                var details = document.createElement('details');
                var summary = document.createElement('summary');
                summary.textContent = t('Dialogue with the SMTP server (credentials hidden)');
                var pre = document.createElement('pre');
                pre.className = 'smtp-log mono';
                pre.textContent = log.join('\n');
                details.appendChild(summary);
                details.appendChild(pre);
                body.appendChild(details);
            }
            result.appendChild(body);
        }
    });
})();
