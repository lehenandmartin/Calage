<h1 align="center">Calage</h1>
<h3 align="center">Put a newsletter project online for review: no HTML file to open, no images to host.</h3>

<p align="center">
  <img src="https://img.shields.io/github/v/release/lehenandmartin/Calage" alt="Latest release">
  <img src="https://img.shields.io/github/license/lehenandmartin/Calage" alt="License">
  <img src="https://img.shields.io/badge/PHP-8.1+-777BB4?logo=php&logoColor=white" alt="PHP 8.1+">
</p>

<img width="1920" height="781" alt="Screenshot of Calage" src="https://github.com/user-attachments/assets/f741c9cf-2a76-445c-9837-622756058313" />

---

Sending an HTML newsletter to someone for review usually means a zip they have to unpack, an HTML file that opens without its images, or images you first have to upload somewhere. With Calage, you upload the HTML or MJML (a file, a folder or a zip) once: the images are hosted, and you get a link that shows the newsletter as it will look, on desktop and mobile. Each new version appears on the same link.

Along the way, Calage keeps the history of versions and gives back clean files (a zip, or an HTML with the hosted images) ready for your email platform.

**What it is not**
- Not a sending tool: no mailing lists, no campaigns, no statistics. The test send only checks the email in a few real inboxes.
- Not an email builder: build the newsletter in your usual tool. The code editor is there for small fixes.
- Not a feedback tool: no comments or approvals on the share page; feedback goes through your usual channels.
- Single-user for now: one account for the back office. People who open a share link need no account.

The name comes from printing: *calage* is the final press adjustment before the print run, the moment you check that everything is right.

## Features

### Import
- Drag and drop an HTML or MJML file, a whole folder or a zip; uploads are sent in chunks to get past the upload limits of shared hosting
- Images detected in `src`, `srcset`, `background`, `url()` (inline styles and `<style>` blocks) and Outlook VML, including inside `<!--[if mso]>` conditional comments
- Paths matched even with encoded spaces, accents, `../` or a different case (an `Image.JPG` created on Windows)
- Missing images are reported without blocking the import
- The HTML comes out byte-for-byte identical: only image paths are replaced, and no DOM parser ever rewrites it

### Hosted images
- Stored by content hash (`/i/{sha256}.{ext}`): never overwritten nor deleted, deduplicated across versions and newsletters
- Links in emails already sent keep working, even after the newsletter is deleted

### Versions
- Editable draft, then publishing freezes the version and gives it a number
- Built-in code editor (CodeMirror) for small fixes, with a subject and a preheader per version
- Empty preheader filled in from the start of the email text, as an email client does; a missing subject is asked for at publication
- Restore an older version without rewriting history
- Upload a new file on an existing newsletter: unchanged images are reused, the HTML alone is enough

### MJML
- MJML 5 compiled in the browser (`mjml-browser`): no Node on the server
- Edit the MJML source with a live preview, as-you-type checks and validation warnings
- Preheader kept in sync with `<mj-preview>`
- Export the MJML source with its images, using relative paths or hosted URLs

### Share page
- One share link per newsletter, with a long random token
- Latest published version by default, with a selector for earlier ones; drafts are never visible
- Desktop (900 px) and mobile (375 px) preview, plus a degraded preview without web fonts or without images
- Zip download: HTML with relative paths and an `images/` folder

### Exports and test sends
- Zip (relative HTML + `images/`, duplicate names renamed) or standalone HTML with hosted images, ready for your email platform
- Test copies over SMTP (up to 20 addresses, one email each), with the version’s subject: to check the rendering in real inboxes, not to send a campaign
- Test email to check the SMTP settings

### Organization
- Folders, and search on name, subject and preheader
- Duplicate a newsletter (with its own share link); delete it without touching hosted images

### Deployment
- Runs on basic shared Apache hosting, uploaded over FTP
- No Composer, no Node, no build step: dependencies are included
- Install at the root of a domain or in a subfolder (`domain.com/calage`)
- Or run it with Docker: a ready-made image (PHP + Apache), data kept in the `data/` and `i/` folders

### Languages
- Interface in English and French, chosen in the Settings (the setup wizard follows the browser and offers a language picker)
- The share page speaks the visitor's language, based on their browser

## Stack

| Layer | Technology |
|---|---|
| Backend | Plain PHP 8.1+, no framework |
| Database | SQLite (via PDO), WAL mode |
| Templates | PHP |
| CSS | Custom stylesheet, [Fluent UI System Icons](https://github.com/microsoft/fluentui-system-icons) |
| JavaScript | Dependency-free; [CodeMirror 5](https://codemirror.net/5/) and [mjml-browser](https://www.npmjs.com/package/mjml-browser) loaded from a CDN with integrity checks |
| Emails | [PHPMailer](https://github.com/PHPMailer/PHPMailer), bundled in `lib/` |

## Installation

### Shared hosting

1. Download the latest release, or clone the repository:
   ```bash
   git clone https://github.com/lehenandmartin/Calage.git
   ```
2. Upload the files over FTP, at the root of a domain or in a subfolder. The included `.htaccess` blocks web access to everything that should stay private.
3. Open the site in a browser: a setup wizard checks the environment (PHP extensions, write permissions), then asks for the language, the public address, the account and, optionally, the SMTP settings (with a test email). It writes `config.php` and logs you in. Run it right after uploading the files: until setup is done, anyone reaching the site could run it.
4. If the host does not let PHP write into the Calage folder, the wizard shows the content of `config.php` for you to upload over FTP. You can also copy `config.example.php` to `config.php` and fill it in by hand (`php bin/hash-password.php` generates the password hash).

After setup, the language, the address, the account and the SMTP settings can be changed from the Settings page.

The `data/` (database, temporary files) and `i/` (hosted images) folders must be writable by PHP.

> **nginx**: `.htaccess` files are ignored. Deny web access to `data/`, `src/`, `templates/`, `lib/`, `bin/`, `tests/` and `config.php` in the server configuration, or move `data/` out of the web root through `paths` in `config.php`. The dashboard warns you if `data/` can be read from the web.

### Docker

The repository includes a `Dockerfile` (PHP 8.4 + Apache, the same `.htaccess` rules as on shared hosting) and a `compose.yaml`. Requires Docker with Compose v2.

1. Get the code and start the container:
   ```bash
   git clone https://github.com/lehenandmartin/Calage.git
   cd Calage
   docker compose up -d --build
   ```
2. Open http://localhost:8080 and run the setup wizard right away (until it is done, anyone reaching the site could run it). As public address, enter the URL people will use, e.g. `https://calage.your-domain.com`: it is used for the hosted images, the share links and the emails.

To use another port, set `CALAGE_PORT` (in the shell or in a `.env` file next to `compose.yaml`): `CALAGE_PORT=8000 docker compose up -d`. `TZ` sets the system time zone of the container; the dates shown by Calage follow the time zone of `config.php`.

**What is kept, and where.** Everything that is not code stays in two folders of the checkout, mounted into the container, as on shared hosting:

| Folder | Content |
|---|---|
| `data/` | `config.php`, the SQLite database (`app.sqlite`), temporary files (sessions, uploads) |
| `i/` | hosted images: never delete them, emails already sent point to them |

They are ignored by git, so `git pull` never touches them. In the image, `config.php` is written to `data/` instead of next to `index.php` (the `CALAGE_CONFIG` environment variable points to it), so the setup and the Settings survive a new container. The code is read-only for PHP; only `data/` and `i/` are writable. On start, the container puts back their `.htaccess` files if they are missing and gives the folders to the `www-data` user (uid 33): on a Linux server, `sudo` may be needed to edit their files from the host.

To keep them elsewhere (another disk, a backed-up folder), change the left-hand side of the two `volumes` lines in `compose.yaml`, e.g. `/srv/calage/data:/var/www/html/data`.

**HTTPS and reverse proxy.** The container speaks plain HTTP. Put it behind your usual reverse proxy (Caddy, Traefik, nginx…) that handles the certificate, and serve it at the root of a domain or subdomain: the subfolder install (`domain.com/calage`) is meant for shared hosting, not for the container. The proxy must pass the `Host` header and `X-Forwarded-Proto: https` (Calage relies on it for secure session cookies) and accept large enough requests (upload chunks go up to 4 MB; e.g. `client_max_body_size 64m;` with nginx). Example with Caddy:
```
calage.your-domain.com {
    reverse_proxy localhost:8080
}
```

**Updating.**
```bash
git pull
docker compose up -d --build
```
`data/` and `i/` are kept and the database migrates itself on the next page load.

**Backup.** Save the `data/` and `i/` folders, e.g. while the container is stopped (the database is in WAL mode: a copy taken while it is running may miss the latest changes):
```bash
docker compose stop
tar czf calage-backup.tar.gz data i
docker compose start
```

**Logs and commands.** `docker compose logs -f` shows the Apache and PHP logs. Commands run inside the container, e.g. `docker compose exec calage php bin/hash-password.php` to generate a password hash. To edit `config.php` by hand, open `data/config.php` (the Settings page covers the usual changes).

### Updating

On shared hosting, replace the files, keeping `config.php`, `data/` and `i/` (with Docker, see above). The database migrates itself on the next page load.

### Local development

```bash
php -S localhost:8000 index.php
```

Then open http://localhost:8000 to run the setup wizard.

To test the non-JavaScript upload with large files, raise the upload limits at launch: `php -d upload_max_filesize=64M -d post_max_size=64M -S localhost:8000 index.php`.

## Requirements

- PHP 8.1+ with `pdo_sqlite`, `zip` (`ZipArchive`) and `mbstring`; `openssl` for encrypted SMTP; `intl` recommended
- Apache 2.4 with `mod_rewrite` and `.htaccess` files enabled (`AllowOverride All`)
- *(Optional)* An SMTP server to send previews

## Tests

```bash
php tests/run.php            # all tests
php tests/run.php mjml       # only tests whose name contains "mjml"
```

The HTML test set (`tests/fixtures/`) covers image detection edge cases: Outlook conditional comments, `srcset`, encoded paths, case mismatches, deliberately malformed HTML. No dependency needed, not even PHPUnit.

## Translations

Interface texts are written in English, straight in the code: `__('Save')` in PHP, `CalageLang.t('Save')` in JavaScript. The French translation lives in `src/lang/fr.php` (PHP) and `src/lang/fr-js.php` (scripts). `tests/LangTest.php` fails when a text has no translation, when a translation is no longer used, or when placeholders such as `{name}` differ.

## About

This project was built with the assistance of [Claude](https://claude.ai), under the author's direction and review.

## License

[MIT](LICENSE) — © 2026 Martin Le Hénand

Third-party components: [PHPMailer](https://github.com/PHPMailer/PHPMailer) (LGPL-2.1, see `lib/PHPMailer/LICENSE`), [Fluent UI System Icons](https://github.com/microsoft/fluentui-system-icons) (MIT, see `assets/icons-LICENSE.txt`), [cheerio](https://github.com/cheeriojs/cheerio) and its dependencies (MIT, BSD-2-Clause and ISC, see `assets/vendor/cheerio-LICENSE.txt`), and, from their CDNs, [CodeMirror](https://codemirror.net/) and [mjml-browser](https://github.com/mjmlio/mjml) (MIT).
