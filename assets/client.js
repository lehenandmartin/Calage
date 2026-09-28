// Share page: desktop / mobile switch and version picker.
(function () {
    'use strict';
    var stage = document.getElementById('c-stage');
    var group = document.querySelector('[data-stage]');
    function show(device) {
        if (!stage || !group) return;
        stage.classList.toggle('is-mobile', device === 'mobile');
        group.querySelectorAll('[data-device]').forEach(function (b) {
            b.setAttribute('aria-pressed', b.getAttribute('data-device') === device ? 'true' : 'false');
        });
    }
    if (group) {
        group.addEventListener('click', function (e) {
            var button = e.target.closest('[data-device]');
            if (button) show(button.getAttribute('data-device'));
        });
    }
    // On a small screen, the desktop preview does not fit: start in mobile.
    if (window.innerWidth < 940) show('mobile');

    // Drop-down menus (<details class="menu">): closed by a click elsewhere or Escape.
    document.addEventListener('click', function (e) {
        document.querySelectorAll('details.menu[open]').forEach(function (menu) {
            if (!menu.contains(e.target)) menu.removeAttribute('open');
        });
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') document.querySelectorAll('details.menu[open]').forEach(function (m) { m.removeAttribute('open'); });
    });

    var picker = document.querySelector('[data-version-picker] select');
    if (picker) picker.addEventListener('change', function () { window.location.href = picker.value; });
})();
