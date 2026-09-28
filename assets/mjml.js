// MJML compilation in the browser (mjml-browser). The server never compiles: it receives the resulting HTML.
// window.CalageMjml.compile(source) → Promise<{ html, errors: [text], includes: [path] }>
(function () {
    'use strict';
    var t = window.CalageLang.t;

    function includes(source) {
        var paths = [];
        source.replace(/<mj-include\b[^>]*>/gi, function (tag) {
            var m = /\bpath\s*=\s*(["'])(.*?)\1/i.exec(tag);
            paths.push(m ? m[2] : t('(unreadable path)'));
            return tag;
        });
        return paths;
    }

    // ---------- <mj-preview> (the preheader) ----------
    var PREVIEW = /<mj-preview\b[^>]*?(?:\/>|>([\s\S]*?)<\/mj-preview\s*>)/i;

    function decode(html) {
        var el = document.createElement('textarea');
        el.innerHTML = html;
        return el.value;
    }
    function escapeText(text) {
        return text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    /** Text of <mj-preview> (tags removed, entities decoded, whitespace collapsed), or null when the tag is missing. */
    function readPreview(source) {
        var m = PREVIEW.exec(source);
        if (!m) return null;
        return decode((m[1] || '').replace(/<[^>]*>/g, '')).replace(/\s+/g, ' ').trim();
    }

    /**
     * Edit to make to the source so that <mj-preview> contains text:
     * { from, to, insert } (offsets in the source), or null when nothing changes / not possible (no <mjml>).
     * Tag present: text replaced (tag removed when text is empty). Missing: added in <mj-head>,
     * which is created right after <mjml> if needed.
     */
    function previewEdit(source, text) {
        text = text.replace(/\s+/g, ' ').trim();
        var m = PREVIEW.exec(source);
        if (m) {
            if (readPreview(source) === text) return null;
            if (text === '') {
                // Remove the tag and, when it is alone on its line, the whole line.
                var from = m.index, to = m.index + m[0].length;
                var lineStart = source.lastIndexOf('\n', from - 1);
                var lineEnd = source.indexOf('\n', to);
                if (/^\s*$/.test(source.slice(lineStart + 1, from)) && /^\s*$/.test(source.slice(to, lineEnd === -1 ? source.length : lineEnd))) {
                    from = lineStart === -1 ? 0 : lineStart;
                    to = lineEnd === -1 ? source.length : lineEnd;
                }
                return { from: from, to: to, insert: '' };
            }
            var open = /^<mj-preview\b[^>]*?(\/?)>/i.exec(m[0]);
            var tag = open[1] ? open[0].replace(/\s*\/>$/, '>') : open[0];
            return { from: m.index, to: m.index + m[0].length, insert: tag + escapeText(text) + '</mj-preview>' };
        }
        if (text === '') return null;
        var head = /<mj-head\b[^>]*>/i.exec(source);
        if (head) {
            var indent = indentOf(source, head.index) + '  ';
            var at = head.index + head[0].length;
            return { from: at, to: at, insert: '\n' + indent + '<mj-preview>' + escapeText(text) + '</mj-preview>' };
        }
        var root = /<mjml\b[^>]*>/i.exec(source);
        if (!root) return null;
        var base = indentOf(source, root.index) + '  ';
        var pos = root.index + root[0].length;
        return {
            from: pos, to: pos,
            insert: '\n' + base + '<mj-head>\n' + base + '  <mj-preview>' + escapeText(text) + '</mj-preview>\n' + base + '</mj-head>'
        };
    }

    function indentOf(source, index) {
        var lineStart = source.lastIndexOf('\n', index - 1) + 1;
        return /^[ \t]*/.exec(source.slice(lineStart, index))[0];
    }

    window.CalageMjml = {
        readPreview: readPreview,
        previewEdit: previewEdit,
        available: typeof window.mjml === 'function',
        compile: function (source) {
            if (typeof window.mjml !== 'function') {
                return Promise.reject(new Error(t('The MJML compiler could not be loaded (connection to the jsdelivr CDN?).')));
            }
            // MJML 5: compilation is asynchronous.
            return Promise.resolve(window.mjml(source, { validationLevel: 'soft' })).then(function (result) {
                return {
                    html: result.html,
                    errors: (result.errors || []).map(function (e) {
                        return e.tagName
                            ? t('Line {line} ({tag}): {message}', { line: e.line, tag: e.tagName, message: e.message })
                            : t('Line {line}: {message}', { line: e.line, message: e.message });
                    }),
                    includes: includes(source)
                };
            });
        }
    };
})();
