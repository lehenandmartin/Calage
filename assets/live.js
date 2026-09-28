// Live preview of the MJML editor.
// Receives each compilation from assets/editor.js (calage:compiled event), replaces the image paths with
// the hosted images, and shows it without a flash or losing the position: the next frame loads behind the scenes,
// takes over the scroll position of the visible frame, then takes its place.
//
// Security: sandbox="allow-same-origin" WITHOUT allow-scripts (an exception limited to this preview, see CLAUDE.md):
// no code in the HTML runs; the page only reads the frame's scroll position.
(function () {
    'use strict';
    var split = document.querySelector('[data-live-split]');
    if (!split) return;
    var t = window.CalageLang.t;

    var preview = split.querySelector('[data-live-preview]');
    var stage = split.querySelector('[data-live-stage]');
    var frames = stage.querySelectorAll('iframe');
    var state = split.querySelector('[data-live-state]');
    var showButton = document.querySelector('[data-live-show]');
    var images = JSON.parse(document.getElementById('live-images').textContent || '{}');
    var KEY = 'calage-live-preview';

    function load() { try { return JSON.parse(localStorage.getItem(KEY)) || {}; } catch (e) { return {}; } }
    function save(patch) {
        var prefs = load();
        for (var k in patch) prefs[k] = patch[k];
        try { localStorage.setItem(KEY, JSON.stringify(prefs)); } catch (e) { /* nothing remembered */ }
    }
    var prefs = load();

    // ---------- Image paths → hosted images ----------
    var normalizedImages = {};
    Object.keys(images).forEach(function (raw) { normalizedImages[normalize(raw)] = images[raw]; });

    function normalize(path) {
        var value = path.replace(/&amp;/g, '&').replace(/[?#].*$/, '');
        try { value = decodeURIComponent(value); } catch (e) { /* %xx invalide : tel quel */ }
        var parts = [];
        value.replace(/\\/g, '/').split('/').forEach(function (segment) {
            if (segment === '' || segment === '.') return;
            if (segment === '..') parts.pop(); else parts.push(segment);
        });
        return parts.join('/');
    }
    function hosted(path) {
        if (/^([a-z][a-z0-9+.-]*:|\/\/|#)/i.test(path)) return path; // http:, https:, data:, cid:…
        return images[path] || normalizedImages[normalize(path)] || path;
    }
    function withHostedImages(html) {
        return html
            .replace(/(\b(?:src|background)\s*=\s*)(["'])([^"']*)\2/gi, function (m, attr, q, value) {
                return attr + q + hosted(value) + q;
            })
            .replace(/url\(\s*(['"]?)([^'")]+)\1\s*\)/gi, function (m, q, value) {
                return 'url(' + q + hosted(value.trim()) + q + ')';
            });
    }
    // Safeguards on top of the sandbox: no scripts, links without effect (no navigation inside the preview).
    var GUARD = '<meta http-equiv="Content-Security-Policy" content="script-src \'none\'; object-src \'none\'"><base target="_blank">';
    function withGuard(html) {
        return /<head\b[^>]*>/i.test(html) ? html.replace(/<head\b[^>]*>/i, function (head) { return head + GUARD; }) : GUARD + html;
    }

    // ---------- Display without a flash, at the same position ----------
    var current = 0;
    var pending = null;
    var busy = false;

    function scrollOf(frame) {
        try { var w = frame.contentWindow; return { x: w.scrollX, y: w.scrollY }; } catch (e) { return { x: 0, y: 0 }; }
    }
    function render(html) {
        if (busy) { pending = html; return; }
        busy = true;
        var visible = frames[current];
        var next = frames[1 - current];
        var position = scrollOf(visible);
        next.onload = function () {
            try { next.contentWindow.scrollTo(position.x, position.y); } catch (e) { /* position perdue, rien de grave */ }
            next.classList.add('is-current');
            next.removeAttribute('aria-hidden');
            next.removeAttribute('tabindex');
            visible.classList.remove('is-current');
            visible.setAttribute('aria-hidden', 'true');
            visible.setAttribute('tabindex', '-1');
            current = 1 - current;
            busy = false;
            setState(t('Up to date'), false);
            if (pending !== null) { var html = pending; pending = null; render(html); }
        };
        next.srcdoc = withGuard(withHostedImages(html));
    }
    function setState(text, working) {
        state.textContent = text;
        state.classList.toggle('is-working', working);
    }

    document.addEventListener('calage:compiling', function () { setState(t('Updating…'), true); });
    document.addEventListener('calage:compile-failed', function () { setState(t('Compilation error'), false); });
    document.addEventListener('calage:compiled', function (e) { render(e.detail.html); });

    // ---------- Desktop / mobile ----------
    var devices = split.querySelector('[data-live-devices]');
    function setDevice(device) {
        stage.classList.toggle('is-mobile', device === 'mobile');
        devices.querySelectorAll('[data-device]').forEach(function (b) {
            b.setAttribute('aria-pressed', b.getAttribute('data-device') === device ? 'true' : 'false');
        });
    }
    devices.addEventListener('click', function (e) {
        var button = e.target.closest('[data-device]');
        if (!button) return;
        setDevice(button.getAttribute('data-device'));
        save({ device: button.getAttribute('data-device') });
    });
    setDevice(prefs.device === 'mobile' ? 'mobile' : 'desktop');

    // ---------- Hide / show ----------
    function setHidden(hidden) {
        split.classList.toggle('is-preview-hidden', hidden);
        if (showButton) showButton.hidden = !hidden;
        save({ hidden: hidden });
    }
    split.querySelector('[data-live-hide]').addEventListener('click', function () { setHidden(true); });
    if (showButton) showButton.addEventListener('click', function () { setHidden(false); });
    setHidden(prefs.hidden === true);

    // ---------- Preview width (draggable splitter) ----------
    var splitter = split.querySelector('[data-live-splitter]');
    function setWidth(px) {
        var total = split.getBoundingClientRect().width;
        var width = Math.max(320, Math.min(px, total - 320));
        split.style.setProperty('--preview-width', width + 'px');
        return width;
    }
    if (prefs.width) setWidth(prefs.width);
    splitter.addEventListener('pointerdown', function (e) {
        e.preventDefault();
        splitter.setPointerCapture(e.pointerId);
        split.classList.add('is-resizing');
        var right = split.getBoundingClientRect().right;
        function move(ev) { setWidth(right - ev.clientX); }
        function up() {
            splitter.removeEventListener('pointermove', move);
            splitter.removeEventListener('pointerup', up);
            split.classList.remove('is-resizing');
            save({ width: preview.getBoundingClientRect().width });
        }
        splitter.addEventListener('pointermove', move);
        splitter.addEventListener('pointerup', up);
    });
    // Keyboard: left / right arrows.
    splitter.addEventListener('keydown', function (e) {
        if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
        e.preventDefault();
        var width = setWidth(preview.getBoundingClientRect().width + (e.key === 'ArrowLeft' ? 40 : -40));
        save({ width: width });
    });
})();
