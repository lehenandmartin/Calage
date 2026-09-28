// Import: a single drop zone (files, zip or folder) and an upload split into chunks,
// to get around the hosting's upload limits. Without JavaScript, the plain form still works.
(function () {
    'use strict';
    var t = window.CalageLang.t;
    var form = document.querySelector('[data-upload]');
    if (!form || !window.fetch || !window.FormData || !window.Blob || !Blob.prototype.slice) return;

    var zone = form.querySelector('[data-dropzone]');
    var inputs = form.querySelectorAll('input[type=file]');
    var selection = form.querySelector('[data-selection]');
    var progress = form.querySelector('[data-progress]');
    var bar = progress.querySelector('progress');
    var progressText = form.querySelector('[data-progress-text]');
    var errorBox = form.querySelector('[data-error]');
    var errorBar = form.querySelector('[data-error-bar]');
    var csrf = form.dataset.csrf;
    var startUrl = form.dataset.start;
    var busy = false;

    // With JavaScript, the upload starts as soon as files are chosen or dropped: no button.
    form.querySelector('[data-classic-submit]').hidden = true;

    var KEEP = /\.(html?|mjml|zip|jpe?g|jpe|png|gif|webp|svg|bmp|tiff?|avif|ico)$/i;
    function keep(path) {
        var parts = path.split('/');
        var name = parts[parts.length - 1];
        return KEEP.test(name) && name.charAt(0) !== '.' && parts.indexOf('__MACOSX') === -1;
    }

    // --- Selection ---------------------------------------------------------------------------

    inputs.forEach(function (input) {
        input.addEventListener('change', function () {
            var list = [];
            for (var i = 0; i < input.files.length; i++) {
                var f = input.files[i];
                list.push({ file: f, path: f.webkitRelativePath || f.name });
            }
            input.value = '';
            send(list);
        });
    });

    ['dragenter', 'dragover'].forEach(function (type) {
        zone.addEventListener(type, function (e) {
            e.preventDefault();
            if (!busy) zone.classList.add('is-over');
        });
    });
    ['dragleave', 'drop'].forEach(function (type) {
        zone.addEventListener(type, function (e) {
            e.preventDefault();
            zone.classList.remove('is-over');
        });
    });
    zone.addEventListener('drop', function (e) {
        if (busy) return;
        var dt = e.dataTransfer;
        var entries = [];
        if (dt.items) {
            for (var i = 0; i < dt.items.length; i++) {
                var entry = dt.items[i].webkitGetAsEntry && dt.items[i].webkitGetAsEntry();
                if (entry) entries.push(entry);
            }
        }
        if (entries.length) {
            Promise.all(entries.map(walk)).then(function (lists) {
                send([].concat.apply([], lists));
            }, function () { fail(t('The dropped files cannot be read.')); });
        } else {
            var list = [];
            for (var j = 0; j < dt.files.length; j++) list.push({ file: dt.files[j], path: dt.files[j].name });
            send(list);
        }
    });

    // Recursive walk through a dropped folder (readEntries returns the entries in batches).
    function walk(entry) {
        if (entry.isFile) {
            return new Promise(function (resolve, reject) {
                entry.file(function (file) {
                    resolve([{ file: file, path: entry.fullPath.replace(/^\//, '') }]);
                }, reject);
            });
        }
        var reader = entry.createReader();
        var all = [];
        return new Promise(function (resolve, reject) {
            (function next() {
                reader.readEntries(function (batch) {
                    if (!batch.length) {
                        Promise.all(all.map(walk)).then(function (lists) { resolve([].concat.apply([], lists)); }, reject);
                        return;
                    }
                    all = all.concat(Array.prototype.slice.call(batch));
                    next();
                }, reject);
            })();
        });
    }

    // --- Upload -------------------------------------------------------------------------------

    function send(list) {
        if (busy) return;
        errorBar.hidden = true;
        list = list.filter(function (item) { return keep(item.path); });
        if (!list.length) {
            fail(t('No HTML, MJML, image or zip file in the selection.'));
            return;
        }
        var total = list.reduce(function (sum, item) { return sum + item.file.size; }, 0);
        selection.textContent = window.CalageLang.n('{n} file', '{n} files', list.length) + ' · ' + size(total);
        busy = true;
        zone.classList.add('is-busy');
        progress.hidden = false;
        setProgress(0, total, t('Preparing…'));

        var body = { files: list.map(function (item) { return { path: item.path, size: item.file.size }; }) };
        var hidden = form.querySelector('[name=newsletter]');
        if (hidden) body.newsletter = hidden.value;
        var folder = form.querySelector('[name=folder_id]');
        if (folder) body.folder_id = folder.value;

        post(startUrl, JSON.stringify(body), 'application/json')
            .then(function (start) {
                var sent = 0;
                var accepted = start.accepted;
                var acceptedTotal = accepted.reduce(function (sum, i) { return sum + list[i].file.size; }, 0);
                var chain = Promise.resolve();
                accepted.forEach(function (index) {
                    chain = chain.then(function () {
                        return sendFile(start.id, index, list[index].file, start.chunkSize, function (bytes) {
                            sent += bytes;
                            setProgress(sent, acceptedTotal, t('Uploading {file}', { file: list[index].path }));
                        });
                    });
                });
                return chain.then(function () {
                    setProgress(acceptedTotal, acceptedTotal, t('Analyzing the newsletter…'));
                    return post(startUrl + '/' + start.id + '/finish', null);
                });
            })
            .then(function (done) {
                busy = false;
                window.location.href = done.redirect;
            })
            .catch(function (err) { fail(err.message); });
    }

    function sendFile(id, index, file, chunkSize, onProgress) {
        var offset = 0;
        function next() {
            var chunk = file.slice(offset, offset + chunkSize);
            var data = new FormData();
            data.append('index', index);
            data.append('offset', offset);
            data.append('chunk', chunk, 'chunk');
            return retry(function () { return post(startUrl + '/' + id + '/chunk', data); }, 3)
                .then(function (res) {
                    onProgress(res.received - offset);
                    offset = res.received;
                    return offset < file.size ? next() : null;
                });
        }
        return next(); // an empty file is sent too, as one empty chunk
    }

    function retry(fn, attempts) {
        return fn().catch(function (err) {
            if (attempts <= 1 || err.final) throw err;
            return new Promise(function (r) { setTimeout(r, 1000); }).then(function () { return retry(fn, attempts - 1); });
        });
    }

    function post(url, body, type) {
        var headers = { 'X-CSRF-Token': csrf, 'Accept': 'application/json' };
        if (type) headers['Content-Type'] = type;
        return fetch(url, { method: 'POST', body: body, headers: headers, credentials: 'same-origin' })
            .then(function (res) {
                return res.text().then(function (text) {
                    var json = null;
                    try { json = JSON.parse(text); } catch (e) { /* HTML page: expired session, server error… */ }
                    if (res.ok && json) return json;
                    var err = new Error(json && json.error ? json.error
                        : res.status === 400 || res.status === 403 ? t('The session has expired: reload the page and sign in again.')
                        : t('The server replied with an error ({status}).', { status: res.status }));
                    err.final = res.status >= 400 && res.status < 500; // no point retrying
                    throw err;
                });
            });
    }

    function setProgress(done, total, label) {
        var pct = total > 0 ? Math.round(done / total * 100) : 100;
        bar.value = pct;
        progressText.textContent = label + ' — ' + t('{n}%', { n: pct });
    }

    function fail(message) {
        busy = false;
        zone.classList.remove('is-busy');
        progress.hidden = true;
        errorBox.textContent = message || t('The upload failed.');
        errorBar.hidden = false;
    }

    function size(bytes) {
        if (bytes < 1024 * 1024) return t('{n} KB', { n: Math.max(1, Math.round(bytes / 1024)) });
        var mb = (bytes / 1024 / 1024).toFixed(1);
        return t('{n} MB', { n: window.CalageLang.locale === 'fr' ? mb.replace('.', ',') : mb });
    }

    window.addEventListener('beforeunload', function (e) {
        if (busy) { e.preventDefault(); e.returnValue = ''; }
    });
})();
