// "Degraded preview": reloads the preview without web fonts and/or without images.
// The server then adds font-src 'none' / img-src 'none' to the preview's CSP header (View::previewPolicy):
// the newsletter HTML is not modified. The choice is remembered in this browser.
(function () {
    'use strict';
    var t = window.CalageLang.t;
    var KEY = 'calage-degraded-preview';
    var LABELS = { fonts: t('no web fonts'), images: t('no images') };

    function load() {
        try { return JSON.parse(localStorage.getItem(KEY)) || {}; } catch (e) { return {}; }
    }
    function save(state) {
        try { localStorage.setItem(KEY, JSON.stringify(state)); } catch (e) { /* private browsing: nothing remembered */ }
    }

    document.querySelectorAll('[data-degrade]').forEach(function (menu) {
        var frame = document.querySelector(menu.getAttribute('data-degrade'));
        var status = document.querySelector(menu.getAttribute('data-degrade-status'));
        var boxes = menu.querySelectorAll('input[type=checkbox][name]');
        if (!frame) return;
        var base = frame.getAttribute('src');

        function apply() {
            var params = [], active = [];
            boxes.forEach(function (box) {
                if (box.checked) { params.push(box.name + '=off'); active.push(LABELS[box.name]); }
            });
            var url = base + (params.length ? (base.indexOf('?') === -1 ? '?' : '&') + params.join('&') : '');
            if (frame.getAttribute('src') !== url) frame.setAttribute('src', url);
            menu.classList.toggle('is-active', active.length > 0);
            if (status) {
                status.hidden = active.length === 0;
                status.textContent = t('Preview: {list}', { list: active.join(', ') });
            }
        }

        var saved = load();
        boxes.forEach(function (box) {
            box.checked = saved[box.name] === true;
            box.addEventListener('change', function () {
                var state = {};
                boxes.forEach(function (b) { state[b.name] = b.checked; });
                save(state);
                apply();
            });
        });
        apply();
    });
})();
