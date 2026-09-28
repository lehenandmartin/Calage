// Import: compiles the chosen MJML file in the browser, then sends the resulting HTML to the server.
(function () {
    'use strict';
    var t = window.CalageLang.t;
    var box = document.querySelector('[data-mjml-import]');
    if (!box) return;
    var form = box.querySelector('[data-compile-form]');
    var source = JSON.parse(document.getElementById('mjml-source').textContent);

    function fail(message) {
        box.querySelector('[data-compiling]').hidden = true;
        box.querySelector('[data-compile-message]').textContent = message;
        box.querySelector('[data-compile-error]').hidden = false;
    }

    if (!window.CalageMjml) { fail(t('The MJML compiler could not be loaded.')); return; }
    window.CalageMjml.compile(source).then(function (result) {
        if (!result.html) { fail(t('MJML produced no HTML.')); return; }
        form.elements.html.value = result.html;
        form.elements.errors.value = JSON.stringify(result.errors);
        form.submit();
    }, function (error) {
        fail(error && error.message ? error.message : String(error));
    });
})();
