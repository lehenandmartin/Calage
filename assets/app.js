// Calage: small interface behaviors (no dependencies).
(function () {
    'use strict';

    // Short-lived notification at the bottom of the screen.
    function toast(message) {
        var box = document.getElementById('toasts');
        var tpl = document.getElementById('toast-template');
        if (!box || !tpl) return;
        var el = tpl.content.firstElementChild.cloneNode(true);
        el.querySelector('p').textContent = message;
        box.appendChild(el);
        setTimeout(function () { el.remove(); }, 5000);
    }
    window.calageToast = toast;
    document.querySelectorAll('.toast').forEach(function (el) { setTimeout(function () { el.remove(); }, 6000); });

    document.addEventListener('click', function (e) {
        var target = e.target.closest('button, a, summary');

        // Close a notification.
        if (target && target.hasAttribute('data-dismiss')) { target.closest('.toast').remove(); return; }

        // "Copy" button: data-copy="#field" or data-copy-text="…".
        if (target && (target.hasAttribute('data-copy') || target.hasAttribute('data-copy-text'))) {
            var input = target.hasAttribute('data-copy') ? document.querySelector(target.getAttribute('data-copy')) : null;
            var text = input ? input.value : target.getAttribute('data-copy-text');
            var done = function () { toast(target.getAttribute('data-copied') || window.CalageLang.t('Link copied.')); };
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(done, function () { if (input) input.select(); });
            } else if (input) {
                input.select();
                if (document.execCommand('copy')) done();
            }
            return;
        }

        // Open a dialog: data-dialog="id".
        if (target && target.hasAttribute('data-dialog')) {
            var dialog = document.getElementById(target.getAttribute('data-dialog'));
            if (dialog && dialog.showModal) { dialog.showModal(); }
            return;
        }

        // Folder pane on small screens.
        if (target && target.hasAttribute('data-nav-toggle')) {
            var open = document.getElementById('nav').classList.toggle('is-open');
            target.setAttribute('aria-expanded', open ? 'true' : 'false');
            return;
        }

        // Drop-down menus: only one open, closed by a click elsewhere.
        document.querySelectorAll('details.menu[open]').forEach(function (menu) {
            if (!menu.contains(e.target)) menu.removeAttribute('open');
        });
    });
    document.addEventListener('toggle', function (e) {
        if (e.target.matches && e.target.matches('details.menu') && e.target.open) {
            document.querySelectorAll('details.menu[open]').forEach(function (menu) {
                if (menu !== e.target) menu.removeAttribute('open');
            });
        }
    }, true);
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') document.querySelectorAll('details.menu[open]').forEach(function (m) { m.removeAttribute('open'); });
    });

    // Forms to confirm: <form data-confirm="Question?">.
    document.addEventListener('submit', function (e) {
        var message = e.target.getAttribute && e.target.getAttribute('data-confirm');
        if (message && e.target.dataset.confirmed === '1') return; // already confirmed in another dialog
        if (message && e.submitter && e.submitter.value === 'cancel') return;
        if (message && !window.confirm(message)) {
            e.preventDefault();
            e.stopImmediatePropagation();
        }
    }, true);

    // Desktop / mobile switch of a preview: <div class="seg" data-stage="#id">.
    document.querySelectorAll('[data-stage]').forEach(function (group) {
        var stage = document.querySelector(group.getAttribute('data-stage'));
        group.addEventListener('click', function (e) {
            var button = e.target.closest('[data-device]');
            if (!button || !stage) return;
            stage.classList.toggle('is-mobile', button.getAttribute('data-device') === 'mobile');
            group.querySelectorAll('[data-device]').forEach(function (b) {
                b.setAttribute('aria-pressed', b === button ? 'true' : 'false');
            });
        });
    });

    // Dashboard: warning when data/ can be read from the web.
    var probe = document.querySelector('[data-probe]');
    var banner = document.getElementById('data-exposed');
    if (probe && banner) {
        fetch(probe.getAttribute('data-probe'), { method: 'HEAD', cache: 'no-store', credentials: 'omit' })
            .then(function (r) { if (r.ok) banner.hidden = false; })
            .catch(function () {});
    }
})();
