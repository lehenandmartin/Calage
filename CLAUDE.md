# Calage

Web tool for sharing email newsletters between an agency and its clients: a "WeTransfer for newsletters".
The agency uploads an HTML newsletter (file, folder or zip), Calage hosts the images and keeps track of versions,
and the recipient previews it and downloads the files through a direct link, without an account.

The name comes from prepress: *calage* is the final press adjustment before the print run.

## Non-negotiable constraints

- **PHP 8.x only, SQLite for the database.** No MySQL, no Node on the server, no heavy framework.
- **Must run on basic shared hosting**: no guaranteed shell access, low upload and execution time limits;
  the only slightly unusual extension required is `ZipArchive`.
- **No Composer required in production.** Dependencies (PHPMailer, cheerio for the browser) are bundled in the repo.
- **The user's HTML must never be rewritten by a DOM parser** (`DOMDocument` & co.). See below.
- **Code, comments, tests and docs in English.** The interface is in English, with a French translation
  (see "Languages").
- **Vocabulary**: Calage is not limited to an agency and its clients. The interface talks about the
  "share link" and "share page" (« lien de partage », « page de partage »), never about a "client" or an "agency"
  as the recipient ("email client", the mail software, is fine). Internal code names
  (`ClientController`, `/c/{token}`, `client.php`) stay as they are.
- Spelling: "preheader", without an accent in French too.
- French translation **without "tu"**: formal "vous" or impersonal phrasing
  (« Vous consultez une ancienne version », « Choisissez un fichier », « Session expirée : rechargez la page »).

## Scope

A single account, on the agency side. Recipients have no account.

Out of scope for now (do not implement): client approval, comments, automatic checks
(Gmail weight, `alt`, links), release notes, view tracking, link revocation or expiry,
customizing the share page, dark mode preview, multiple users.

### Back office (session-protected)

**Organization**
- Folders (typically one per client) to file newsletters.
- Duplicate a newsletter: the copy starts from the latest published version (HTML, images, subject, preheader),
  and becomes a new newsletter at v1 with its own share link.
- Delete a newsletter: deletes its data, versions and share link, **but never the hosted images**
  (emails already sent point to them).

**Import**
- Upload a single HTML file, a folder or a zip. Chunked upload in the browser
  to get around `upload_max_filesize`, `post_max_size` and `max_execution_time`.
- If the zip holds several HTML files, ask the user which one to use.
- Images detected in:
  - `src` of `<img>` and `srcset`;
  - the `background` attribute (table, td…);
  - `url()` in inline CSS (`style="…"`) and in `<style>` blocks;
  - Outlook VML (`<v:image src>`, `<v:fill src>`), **including inside `<!--[if mso]>` conditional comments**.
- Path normalization: URL decoding (`%20`), accents, spaces, `./`, `../`, and **case-insensitive**
  matching with the files actually present (an `Image.JPG` created on Windows).
- Absolute external URLs (`http://…`, `https://…`, `data:`) are left as they are.
- Image referenced but missing: warning shown, not blocking.
- Image present but not referenced: ignored.
- **Rewriting by targeted text replacement**: paths are located, then replaced in the original string.
  The HTML must come out byte-for-byte identical, except for the image paths.
  `DOMDocument` removes conditional comments and "fixes" deliberately odd structures: forbidden.

**Versions**
- Two states: **draft** (freely editable), then **published** (frozen). The "Publish" button freezes the version,
  gives it the next number and makes it visible on the share page. Drafts have no number.
- New version by editing the code in a built-in editor (CodeMirror, loaded from a CDN) or by uploading
  a new file.
- Each version has a **subject** and a **preheader**. A new version automatically takes those
  of the previous one.
- Restoring an old version creates a new identical version (history is never rewritten).

**Export and sending**
- Download a zip: HTML with relative paths + `images/` folder. Duplicate names
  (two `logo.png` from different folders) are renamed automatically.
- Download a standalone HTML with the absolute URLs of the hosted images.
- Send a preview by email to one or more addresses (hosted images, version subject).
- "Send a test email" button to check the SMTP settings.

### Share page (public link)

- One link per newsletter, with a long random token (never an incremental ID).
- Shows the **latest published version** by default. A picker gives access to earlier versions.
- Header: subject, preheader, version number.
- 900 px preview by default, 375 px mobile switch. No "browser preview, may differ in Outlook or Gmail"
  note (removed on purpose: it did not help).
- Zip download (HTML with relative paths + `images/`).
- Drafts are never visible on the share page.

## Technical choices

### Image storage

Content-hash storage: `/i/{hash}.{ext}` (hash of the content, sha256).
- An image is never overwritten or deleted.
- Automatic deduplication across versions and newsletters.
- A public URL stays valid forever.
- Images are served from **the same domain** as the application.
  `base_url` in the config is used to build absolute URLs.

### Preview

The newsletter HTML is shown in an `<iframe sandbox>` **without** `allow-same-origin` or `allow-scripts`,
so that a booby-trapped HTML cannot run code in the application.

**Only exception (decided)**: the live preview of the MJML editor (`assets/live.js`) uses
`sandbox="allow-same-origin"` to keep the scroll position between two updates. **Never
`allow-scripts`**: no code of the HTML runs (checked with `<script>`, `onerror`, `javascript:`).
Extra safeguards: `<meta http-equiv="Content-Security-Policy" content="script-src 'none'">` and
`<base target="_blank">` injected into this preview HTML (never saved). Everywhere else (newsletter page,
share page, `/render`), the strict rule applies.

### Configuration

No settings table: everything lives in a `config.php` file outside the public folder
(or protected by `.htaccess`). A `config.example.php` is versioned, never `config.php`.

```php
<?php
return [
    'base_url' => 'https://your-domain.com',
    'locale' => 'en', // en | fr
    'admin' => [
        'username' => 'admin',
        'password_hash' => '$2y$10$...', // password_hash(), never in plain text
    ],
    'smtp' => [
        'host' => 'smtp.example.com',
        'port' => 587,
        'encryption' => 'tls', // tls | ssl | none
        'username' => '...',
        'password' => '...',
        'from_email' => 'newsletters@your-domain.com',
        'from_name' => 'My agency',
    ],
    'paths' => [
        'database' => __DIR__ . '/data/app.sqlite',
        'tmp'      => __DIR__ . '/data/tmp',
    ],
    'debug' => false,
];
```

Images are not configurable: they live in `i/` at the root of the application,
to be served statically at `{base_url}/i/{hash}.{ext}`.

**Setup wizard** (`/setup`, `SetupController`, decided): until `config.php` is filled in,
every page leads to the wizard (environment → address and account → optional SMTP with "Send a test
email" → summary). It writes `config.php` (`ConfigFile`: atomic write, 0640 permissions, paths
`__DIR__ . '/…'`, keys added by hand are kept) and opens the session. If PHP cannot write,
it shows the content to upload over FTP. No install key (a deliberate choice, like WordPress):
run the setup right after uploading the files. `bin/hash-password.php` remains for manual setup.

**Editable settings** (`SettingsController`): language, public address, account (current password required;
new password optional), SMTP (password left empty = unchanged, never shown again; test
with the values as entered before saving). Read-only when PHP cannot modify `config.php`.

### Database

SQLite in WAL mode, file outside the public folder. Five tables:

- `folders`: id, name, dates.
- `newsletters`: id, folder_id, name, share token (unique), dates.
- `versions`: id, newsletter_id, number (NULL while a draft), status (`draft` | `published`),
  html, subject, preheader, preheader_auto, mjml, dates. At most one draft per newsletter.
- `images`: hash (key), extension, mime, size, width, height, date.
- `version_images`: version_id, original path as written in the HTML, hash.
  Used to rebuild the relative export and the path mappings.

The HTML stored in the database is the **original** HTML, with its original relative paths. The
"absolute URLs" and "normalized relative paths" versions are produced on the fly from `version_images`.
(Single source of truth.)

## Languages (decided)

The interface is written in English; French is a translation. The English text is the key.
- PHP: `__('Save changes')`, `__('Hello {name}', ['name' => $n])` (not escaped: wrap in `e()`),
  `__n('{n} image', '{n} images', $count)` for plurals, `__h('Upload {file}', ['file' => '<code>…</code>'])`
  when HTML must be inserted (the text is escaped, the placeholders are not). `Calage\Lang` holds the logic.
- French texts: `src/lang/fr.php` (PHP) and `src/lang/fr-js.php` (scripts). Plural: `[singular, plural]`,
  keyed by the English singular. French plural rule: 0 and 1 are singular.
- Scripts: `assets/lang.js` (loaded in the `<head>` of both layouts) exposes `CalageLang.t()` / `.n()`; the
  French texts come as JSON in `<script id="lang-messages">` (nothing in English).
- Dates: `short_date()` / `long_date()` and `Lang::date()` (formats per language, weekday names translated).
- Which language: the back office uses `config.php` → `locale` (English when missing), changed in Settings.
  The share page follows the visitor's browser (`Accept-Language`, falling back to the settings' language),
  with `Vary: Accept-Language`. The setup wizard starts in the browser's language and has a language picker
  (`?lang=`), saved as `locale`.
- `tests/LangTest.php` checks that every literal passed to `__()`, `__n()`, `__h()`, `t()`, `n()` has a French
  entry, that no French entry is unused, that placeholders match, and that no French text uses "tu".
  Adding a text = using it in English + adding its French entry.
- Only internal errors that users never see (logic errors, missing folders) stay untranslated, in English.
  Error messages from PHPMailer and mjml-browser are shown as they come (English).

## Layout and deployment (decided)

No `public/` folder: the application is uploaded as is, at the root of a domain **or in a
subfolder** (`https://domain.com/calage/`). No URL may be hard-coded:

- the prefix of links and routes is derived from `dirname($_SERVER['SCRIPT_NAME'])` (`App::basePath()`),
  always go through `url('/path')`;
- `base_url` is only used for absolute URLs (hosted images, share link, emails).

Only `index.php`, `assets/` and `i/` can be reached from the web. Everything else (`src/`, `templates/`,
`lib/`, `data/`, `bin/`, `tests/`, `config.php`, `*.sql`, `*.md`) is blocked **twice**: by the root
`.htaccess` and by a "deny" `.htaccess` in each internal folder (in case `mod_rewrite`
is missing). Under nginx, `.htaccess` files do not apply: either a server config is needed, or
`data/` must be moved out of the web root through `paths`; the dashboard warns when `data/` can be read from the web.

```
index.php  .htaccess  config.php  config.example.php  schema.sql
assets/      interface CSS/JS (vendor/: bundled cheerio)
i/           images by content hash (served statically, long cache)
src/         App, Router, routes.php, Db, Session, Auth, Csrf, View, Lang, helpers.php
  Controllers/  Html/ (scanner, path resolution, rewriting, rendering)
  Mjml.php  Storage/ (ImageStore, Package = pending import, ChunkedUpload = chunked upload)  Import/ (Importer)  Export/ (Exporter)  Repo/
  lang/      fr.php, fr-js.php (French translation)
templates/   PHP views
lib/         bundled PHPMailer
data/        app.sqlite, tmp/ (sessions, chunked uploads/, pending imports/, exports/)
bin/         CLI scripts (hash-password.php)
tests/       run.php + fixtures/
```

Tests: `php tests/run.php` (with a filter: `php tests/run.php paths`). Each folder of
`tests/fixtures/` is an import: `expected.json` describes the expected images, `expected.html` the HTML
rewritten with `https://h.test/{file}`. Adding a case = adding a folder. The fixtures themselves
(folder names, newsletter content) are French test data and stay as they are.

Local server: `php -S localhost:8000 index.php` (and, to test a subfolder, from the parent
folder: `php -S localhost:8000 -t . calage/index.php`).

## Functional decisions

- Restoring a version while a draft exists: the restore **replaces** the draft,
  after confirmation.
- The code editor cannot add images. An unknown path (not in `version_images`)
  triggers a warning; to add images, upload a file again.
- Pages that serve the raw newsletter HTML (`…/render`) send `Content-Security-Policy: sandbox`
  and `Referrer-Policy: no-referrer`, on top of the iframe's `sandbox` attribute.
- Path rewriting: the scanner returns (offset, length, raw path) and replacements go
  from the end of the string to the start.
- Accepted image formats: JPG, PNG, GIF, WebP (read from the content, not the extension).
  SVG and others: reported as "unsupported format", path left as is.
- Ordinary HTML comments are ignored by the scanner; only conditional
  `<!--[if …]>` comments are scanned.
- Path resolution, from strictest to loosest: exact → case → accents (NFC/NFD)
  → file name alone when it is unique in the import. Fonts and merge tags ignored.
- Previews (`/render`) use domain-relative `/i/…` paths; `base_url` is only used
  for exports, emails and share links.
- Versions: the draft is created on the first save (not when the editor opens), as a copy of
  the latest published one (HTML, subject, preheader, `version_images`). Saving without any change
  creates no draft. Restore = exact copy as a draft (subject and preheader of the restored version),
  to be published afterwards. "Discard draft" only exists when there is a published version.
- New file uploaded to an existing newsletter: it replaces the draft if any, takes the
  subject and preheader of the current version, and **a path missing from the upload but identical to the
  current version takes its image** (the HTML alone can be uploaded again). An uploaded image wins.
- Share page: `/c/{token}`, `/c/{token}/v/{n}`, `/c/{token}/v/{n}/render`. Published versions only
  (a draft has no number, hence no URL). No session or cookie, its own layout
  (`client-layout`, no link to the back office), `noindex`, `no-referrer`, `Cache-Control: no-cache`.
  On a small screen, the preview starts in mobile; in mobile the iframe is `min(375px, 100%)`.
- Exports (`Export/Exporter`): zip = `{name}.html` + `images/`, named `{name}-v{n}.zip` (or `-draft`, translated).
  Image names taken from the original path, cleaned (no spaces or accents), extension of the actual format;
  duplicates `logo.png`, `logo-2.png`… in order of appearance, case ignored; the same image (same hash)
  only once. Standalone HTML = absolute URLs via `base_url`. The share page only downloads published
  versions (`/c/{token}/v/{n}/zip`); the back office can also export the draft.
- Email (`Mailer`, PHPMailer 7.1.1 in `lib/PHPMailer/`, 3 files + LICENSE, see `VERSION.txt`):
  one message per recipient (addresses never visible to one another), 20 at most, over a single SMTP
  connection; subject = version subject (or newsletter name); HTML with absolute `base_url` URLs, converted
  to UTF-8 if needed; no X-Mailer header. The test email is written in the interface language; when it fails,
  the SMTP dialogue is shown with credentials hidden. Warning when `base_url` is local (recipients would not
  see the images). Tests: `tests/fake-smtp.php` is a fake SMTP server started by `tests/MailerTest.php`.
- Organization: folders sorted by name, dashboard filter `/?folder=id` (`0` = no folder).
  Deleting a folder that is not empty opens a dialog: keep its newsletters (they end up without a folder)
  or delete them with it (`FolderRepo::delete()`, in one transaction; hosted images kept). An empty folder: simple confirmation. An import started from a folder files the newsletter there
  (can be changed when confirming). Rename / move / duplicate / delete through the "More actions" menu of the newsletter page.
- Duplicate: new newsletter (name "Copy of …", same folder, new token) whose **draft**
  takes the latest published version (failing that, the draft): HTML, subject, preheader, images.
  History not copied; the copy's first publication is its v1. Its share link shows nothing
  until it is published.
- Delete a newsletter: cascade on `versions` and `version_images`; `images` and `i/` never touched.
- Chunked upload (`assets/upload.js`, `UploadController`, `Storage/ChunkedUpload`):
  `POST /uploads` (JSON: paths and sizes → id, chunk size, accepted indexes),
  `POST /uploads/{id}/chunk` (index, offset, `chunk` file; rewriting a chunk has no effect,
  a gap or an overflow is refused), `POST /uploads/{id}/finish` (checks, builds the Package through
  `Package::fromUploads`, returns the review URL). Chunks from 256 KB to 4 MB, always below
  `upload_max_filesize` and `post_max_size`. Sorting in the browser and on the server (HTML, images, lone zip).
  3 attempts per chunk. Without JavaScript, the plain `POST /imports` form still works.
  Abandoned uploads purged after 24 h. `json_response()` ends the script: no `finally` after it.
- Subject: when publishing a draft that has no subject, a "Publish without a subject?" dialog offers to fill it in
  (`partials/subject-dialog.php`, `assets/subject-prompt.js`, publish form marked `data-subject-prompt`), suggesting
  the HTML `<title>` (`<mj-title>` once compiled). It replaces the usual publish confirmation; Escape cancels.
  Saving in the editor and importing never ask.
- Automatic preheader: a version saved with an empty preheader gets the start of the email text, as an email
  client shows it (`Html\PreviewText`: body only, hidden preheader block included, Outlook-only blocks, head,
  styles, scripts and invisible padding characters skipped, 150 characters cut at a word). `versions.preheader_auto`
  (migration step 3) marks it: it is recomputed on every save and import, and stays automatic in copies
  (`VersionRepo::userPreheader()`). The editor shows the field empty with the generated text as placeholder;
  typing a preheader makes it manual. The HTML is never modified.
- Editor: `EditableText` restores on save the original encoding (UTF-8 / Windows-1252), BOM and
  line endings (a `<textarea>` always sends back UTF-8 with CRLF). Unchanged text
  → original bytes. CodeMirror 5 from cdnjs with SRI; without the CDN, the `<textarea>` still works.

## Interface (redesign approved on mockups)

A discreet nod to Outlook on the web, without a ribbon or copying:
- Font: `"Segoe UI Variable Text", "Segoe UI", system-ui, …` (system stack, nothing to load). Base text 14 px.
- Icons: Fluent UI System Icons (Microsoft, MIT), size 20 "regular", gathered in `assets/icons.svg`
  and used through `icon('name')`. No CSS framework (neither Tabler nor Bootstrap): custom CSS variables.
- Colors: Outlook blue `#0f6cbd` reserved for actions and selection; slightly cool neutrals;
  amber = draft, green = published, red = danger. 4 px corners (controls) / 8 px (panels), light shadows.
- Structure: blue top bar (registration mark, search, settings), folder pane on the left
  with "New newsletter", newsletter list in inbox style (name, subject, preheader, date,
  state chips), newsletter page in opened-email style (command bar, subject / preheader header, preview,
  right column with the share link and versions). Share page in the same spirit, without anything of the back office.
- **No avatars or initials** (confusing). No "To publish / Published" tabs.
- Search: on the name, subject and preheader of the current version.
- "Degraded preview" (discreet icon next to the desktop / mobile switch, back office and share page):
  `…/render?fonts=off` and/or `?images=off` add `font-src 'none'` / `img-src 'none'` to the preview's
  CSP header (`View::previewPolicy`). The HTML is never modified; the browser refuses
  fonts and images (`alt` texts shown). Choice remembered in `localStorage` (`assets/preview.js`).

## MJML

MJML 5 is compiled **in the browser** with `mjml-browser` (version pinned in `Calage\Mjml`: 5.4.1,
jsdelivr CDN + SRI, loaded only on the import page and in the editor). The server never compiles (no Node).
In v5, `mjml()` is **asynchronous** (returns a promise).
mjml-browser reads `window.cheerio` when it loads, without bundling it: without it, `<mj-style inline="inline">`
and `<mj-html-attributes>` fail ("Cannot read properties of undefined (reading 'load')"). cheerio 1.2.0
(the version of mjml-core 5.4.1) is therefore bundled in `assets/vendor/cheerio.js` (esbuild, command at the top
of the file; licenses in `cheerio-LICENSE.txt`) and loaded before mjml-browser (`partials/mjml-script.php`).
HTML checked to be identical to that of the `mjml` package under Node.
- The compiled HTML stays the reference (preview, images, exports, share page); the source is stored as well
  in `versions.mjml` (NULL = HTML newsletter). Step-by-step migrations in `Db::migrate` (step 2: mjml).
- Import: an uploaded `.mjml` (alone, folder or zip) is suggested first; the review page
  compiles it (`assets/mjml-import.js`), sends the HTML to `POST /imports/{id}/compile`, then analyzes it as usual.
- Editor: for an MJML version, the MJML is edited; compiled while typing (warnings shown)
  and on save (the compiled HTML is sent with the source; without JavaScript, nothing is saved).
- Preheader: when importing an MJML file, the text of `<mj-preview>` (when filled in) becomes the preheader,
  including for a new version (it then replaces the one of the current version); announced on the review page.
- MJML editor: the Preheader field and `<mj-preview>` are kept in sync both ways (`assets/mjml.js`:
  `readPreview` / `previewEdit`). Field changed → tag updated, added in `<mj-head>` (created
  if needed) when missing, removed when the field is emptied. Tag changed in the code → field updated; tag
  missing → field unchanged. On opening, `<mj-preview>` wins. HTML newsletters are not concerned.
- `<mj-include>` is not supported (silently ignored by mjml-browser): warning on import and in the editor.
- Uploading a `.html` to an MJML newsletter switches it back to HTML (warning on import).
- Exports: the share page never downloads the MJML. The back office offers "MJML zip" (source and compiled HTML with
  `images/…` paths, same names as the HTML zip) and "MJML only" (hosted images). `ImageScanner` also reads
  MJML attributes (`src` of mj-image / mj-carousel-image / mj-social-element, `background-url`, `thumbnails-src`,
  `icon-wrapped-url`, `icon-unwrapped-url`, `<mj-style>`).
- Tests: `tests/mjml/` (source + `compiled.html` produced once by mjml-browser 5.4.1).
- Live preview (MJML editor): code and preview side by side, draggable splitter (mouse, arrow keys),
  desktop / mobile switch, can be hidden; preferences in `localStorage`. `editor.js` fires `calage:compiled`
  on each compilation; `live.js` (loaded **before** `editor.js`) replaces the paths with the hosted images
  and loads the rendering in a second frame behind the scenes, at the same scroll position, then swaps (no flash).

## Version

`App::VERSION` (semantic versioning, currently 1.0.0) and `App::REPOSITORY`
(https://github.com/lehenandmartin/Calage). The version is shown in the back office only (bottom of the folder
pane, link to the repository, and "About" section of Settings); the share page only carries
the link to the repository ("Shared with Calage"), never the version.

## Working method

- Move forward in small, testable steps.
- Test locally with `php -S localhost:8000 index.php`.
- Simple, readable code, no magic: plain PHP, PDO, prepared statements everywhere.
- Escape every output in the interface (except the newsletter HTML, which is only rendered in the sandboxed iframe).
- Protect back-office forms with a CSRF token.
- Every new interface text goes through `__()` / `t()` with its French translation (see "Languages").
