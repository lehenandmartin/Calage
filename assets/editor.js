// Code editor: CodeMirror when it could be loaded from the CDN, otherwise the plain <textarea>.
// MJML newsletter (textarea[data-mjml]): compiled in the browser while typing (errors shown)
// and on save, when the compiled HTML is sent along with the source.
(function () {
    'use strict';
    var form = document.getElementById('editor-form');
    var textarea = document.getElementById('html');
    if (!form || !textarea) return;
    var t = window.CalageLang.t;

    var isMjml = textarea.hasAttribute('data-mjml');
    var dirty = false;
    var markDirty = function () { dirty = true; };
    var cm = null;

    if (window.CodeMirror) {
        cm = CodeMirror.fromTextArea(textarea, {
            mode: isMjml ? 'xml' : 'htmlmixed',
            lineNumbers: true,
            lineWrapping: true,
            indentUnit: 2,
            tabSize: 4,
            electricChars: false // never re-indent a line while typing
        });
        cm.on('change', markDirty);
    } else {
        textarea.addEventListener('input', markDirty);
    }
    form.querySelectorAll('input[type=text]').forEach(function (input) {
        input.addEventListener('input', markDirty);
    });
    var source = function () { return cm ? cm.getValue() : textarea.value; };

    // Cmd+S / Ctrl+S: save.
    document.addEventListener('keydown', function (e) {
        if ((e.metaKey || e.ctrlKey) && e.key === 's') {
            e.preventDefault();
            form.requestSubmit ? form.requestSubmit() : form.submit();
        }
    });
    window.addEventListener('beforeunload', function (e) {
        if (dirty) { e.preventDefault(); e.returnValue = ''; }
    });

    if (!isMjml) {
        form.addEventListener('submit', function () { dirty = false; });
        return;
    }

    // ---------- MJML ----------
    var status = document.querySelector('[data-mjml-status]');
    var part = function (name) { return status.querySelector('[data-mjml-' + name + ']'); };
    var hiddenHtml = form.querySelector('input[type=hidden][name=html]');

    function show(result, error) {
        part('fatal').hidden = !error;
        if (error) {
            part('fatal-text').textContent = t('Cannot compile: {error} Nothing will be saved until the MJML compiles.', { error: error });
            part('ok').hidden = true;
            part('errors').hidden = true;
            return;
        }
        part('ok').hidden = result.errors.length > 0;
        part('errors').hidden = result.errors.length === 0;
        part('errors-title').textContent = window.CalageLang.n(
            '{n} MJML validation warning (the compilation still succeeds):',
            '{n} MJML validation warnings (the compilation still succeeds):',
            result.errors.length
        );
        var list = part('errors-list');
        list.textContent = '';
        result.errors.forEach(function (message) {
            var li = document.createElement('li');
            li.textContent = message;
            list.appendChild(li);
        });
        part('includes').hidden = result.includes.length === 0;
        var includes = part('includes-list');
        includes.textContent = '';
        result.includes.forEach(function (path) {
            var li = document.createElement('li');
            var code = document.createElement('code');
            code.textContent = path;
            li.appendChild(code);
            includes.appendChild(li);
        });
    }

    function compile() {
        if (!window.CalageMjml) return Promise.reject(new Error(t('The MJML compiler could not be loaded.')));
        return window.CalageMjml.compile(source());
    }

    // Preheader ↔ <mj-preview>: the field and the tag stay identical.
    var preheader = form.querySelector('input[name=preheader]');
    var syncing = false;
    function applyEdit(edit) {
        if (!edit) return;
        syncing = true;
        if (cm) {
            cm.replaceRange(edit.insert, cm.posFromIndex(edit.from), cm.posFromIndex(edit.to), '+preheader');
        } else {
            textarea.value = textarea.value.slice(0, edit.from) + edit.insert + textarea.value.slice(edit.to);
        }
        syncing = false;
    }
    function codeToField() {
        if (syncing || !window.CalageMjml) return;
        var text = window.CalageMjml.readPreview(source());
        if (text !== null && text !== preheader.value.replace(/\s+/g, ' ').trim()) preheader.value = text;
    }
    if (preheader && window.CalageMjml) {
        preheader.addEventListener('input', function () {
            applyEdit(window.CalageMjml.previewEdit(source(), preheader.value));
        });
        if (cm) cm.on('change', codeToField); else textarea.addEventListener('input', codeToField);
        codeToField(); // on opening, <mj-preview> wins when it is filled in
    }

    // Check while typing, after a short pause.
    var timer = null;
    function check() {
        document.dispatchEvent(new CustomEvent('calage:compiling'));
        compile().then(function (result) {
            show(result, null);
            // Live preview (assets/live.js): same compilation, no duplicate work.
            document.dispatchEvent(new CustomEvent('calage:compiled', { detail: { html: result.html } }));
        }, function (error) {
            show(null, error.message || String(error));
            document.dispatchEvent(new CustomEvent('calage:compile-failed'));
        });
    }
    if (cm) cm.on('change', function () { clearTimeout(timer); timer = setTimeout(check, 500); });
    else textarea.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(check, 500); });
    check();

    // Saving: compile first, then send the source and the compiled HTML.
    var compiling = false;
    form.addEventListener('submit', function (e) {
        if (form.dataset.compiled === '1') { dirty = false; return; }
        e.preventDefault();
        if (compiling) return;
        compiling = true;
        var submitter = e.submitter;
        if (cm) cm.save();
        compile().then(function (result) {
            show(result, null);
            if (!result.html) throw new Error(t('MJML produced no HTML.'));
            hiddenHtml.value = result.html;
            // Keep the button that was used ("Save and view preview"), which form.submit() does not send.
            if (submitter && submitter.name) {
                var keep = document.createElement('input');
                keep.type = 'hidden';
                keep.name = submitter.name;
                keep.value = submitter.value;
                form.appendChild(keep);
            }
            form.dataset.compiled = '1';
            dirty = false;
            form.submit();
        }).catch(function (error) {
            compiling = false;
            show(null, error.message || String(error));
            part('fatal').scrollIntoView({ block: 'center', behavior: 'smooth' });
        });
    });
})();
