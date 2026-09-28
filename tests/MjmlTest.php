<?php
declare(strict_types=1);

// tests/mjml/compiled.html is the compilation of tests/mjml/newsletter.mjml by mjml-browser 5.4.1
// (same version as Calage\Mjml::VERSION), done once and for all: the server never compiles MJML.

use Calage\Export\Exporter;
use Calage\Html\ImageScanner;
use Calage\Import\Importer;
use Calage\Mjml;
use Calage\Repo\NewsletterRepo;
use Calage\Repo\VersionRepo;
use Calage\Storage\ImageStore;
use Calage\Storage\Package;

/** Import of the MJML test set, as the browser leaves it after compiling. */
function mjml_package(): Package
{
    $root = __DIR__ . '/mjml';
    $package = Package::create(temp_dir(), 'Rentrée');
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        $relative = substr($file->getPathname(), strlen($root) + 1);
        if ($relative !== 'compiled.html') {
            $package->addFile($relative, $file->getPathname());
        }
    }
    $package->setCompiled('newsletter.mjml', file_get_contents("$root/compiled.html"), ['Line 4 — sample error'], Mjml::VERSION);
    $package->save();
    return $package;
}

function mjml_imported(): array
{
    $db = temp_db();
    $root = temp_dir();
    $importer = new Importer($db, new ImageStore($db, $root . '/i'));
    $id = $importer->createNewsletter(mjml_package(), 'newsletter.mjml', 'Rentrée');
    $versions = new VersionRepo($db);
    return [$db, $id, $versions, $versions->draft($id), $root, $importer];
}

test('migrations: the mjml and preheader_auto columns exist on a new database as on an existing one', function (): void {
    $db = temp_db();
    check_same(3, (int) $db->query('PRAGMA user_version')->fetchColumn());
    $columns = array_column($db->query('PRAGMA table_info(versions)')->fetchAll(), 'name');
    check(in_array('mjml', $columns, true) && in_array('preheader_auto', $columns, true));

    // Database created before MJML (step 1 only): updated on first load, data kept.
    $file = temp_dir() . '/ancienne.sqlite';
    $old = new PDO('sqlite:' . $file);
    $old->exec(file_get_contents(APP_ROOT . '/schema.sql'));
    $old->exec("INSERT INTO newsletters (name, token) VALUES ('Ancienne', 'abc'); INSERT INTO versions (newsletter_id, status, html) VALUES (1, 'draft', '<p>x')");
    $old->exec('PRAGMA user_version = 1');
    $old = null;
    $db = Calage\Db::connect($file);
    check_same(3, (int) $db->query('PRAGMA user_version')->fetchColumn());
    check_same(['<p>x', null, 0], array_values($db->query('SELECT html, mjml, preheader_auto FROM versions')->fetch()));
});

test('mjml: images found in the source (mj-image, backgrounds, carousel, accordion, social, mj-style, mj-raw)', function (): void {
    $refs = array_unique(array_column(ImageScanner::scan(file_get_contents(__DIR__ . '/mjml/newsletter.mjml')), 'raw'));
    sort($refs);
    check_same([
        'icones/moins.png', 'icones/plus.png', 'images/Photo%20Produit.png', 'images/absente.png', 'images/fond.png',
        'images/hero.jpg', 'images/logo.png', 'images/pixel.gif', 'images/vignette.jpg',
    ], array_values($refs));
});

test('mjml: preheader read from <mj-preview>', function (): void {
    check_same('Nouvelles tablettes et coffrets dès le 1er octobre', Mjml::preview(file_get_contents(__DIR__ . '/mjml/newsletter.mjml')));
    check_same('Soldes & promos : -40 %', Mjml::preview("<mj-preview>\n  Soldes &amp; <b>promos</b> :\n -40 %\n</mj-preview>"));
    check_same(null, Mjml::preview('<mj-preview>   </mj-preview>'));
    check_same(null, Mjml::preview('<mjml><mj-body></mj-body></mjml>'));
});

test('mjml: on import, the preheader comes from <mj-preview>, including for a new version', function (): void {
    [$db, $id, $versions, $draft, $root, $importer] = mjml_imported();
    check_same('Nouvelles tablettes et coffrets dès le 1er octobre', $draft['preheader']);
    $versions->updateDraft((int) $draft['id'], $draft['html'], 'Objet', 'Ancien preheader', $draft['mjml']);
    $versions->publish($id);
    $new = $versions->find($importer->createVersion(mjml_package(), 'newsletter.mjml', $id));
    check_same(['Objet', 'Nouvelles tablettes et coffrets dès le 1er octobre'], [$new['subject'], $new['preheader']]);
    // Without <mj-preview> (HTML file), the preheader of the current version (here the published v1) is kept.
    $versions->discardDraft($id);
    $new = $versions->find($importer->createVersion(fixture_package('01-basique'), 'newsletter.html', $id));
    check_same('Ancien preheader', $new['preheader']);
});

test('mjml: <mj-include> found (ignored by the compilation in the browser)', function (): void {
    check_same(['./pied-de-page.mjml'], Mjml::includes(file_get_contents(__DIR__ . '/mjml/newsletter.mjml')));
    check_same(['a.mjml', 'b.mjml'], Mjml::includes("<mj-include path='a.mjml'/><mj-include\n  path=\"b.mjml\" type=\"css\" />"));
    check_same([], Mjml::includes('<mjml><mj-body></mj-body></mjml>'));
});

test('mjml: import, compiled HTML analyzed, source kept with the version', function (): void {
    [$db, $id, $versions, $draft] = mjml_imported();
    check_same(file_get_contents(__DIR__ . '/mjml/compiled.html'), $draft['html']);
    check_same(file_get_contents(__DIR__ . '/mjml/newsletter.mjml'), $draft['mjml']);
    $report = Importer::analyze(mjml_package(), 'newsletter.mjml');
    $missing = array_column(array_filter($report['images'], fn(array $i): bool => $i['status'] !== 'found'), 'raw');
    check_same(['images/absente.png'], array_values($missing));
    check_same(8, count($versions->images((int) $draft['id'])), '8 image paths hosted');
});

test('mjml: the source follows drafts, publication, restore and duplication', function (): void {
    [$db, $id, $versions, $draft] = mjml_imported();
    $source = $draft['mjml'];
    $versions->publish($id);
    check_same($source, $versions->find($versions->ensureDraft($id))['mjml'], 'new draft');
    $versions->updateDraft((int) $versions->draft($id)['id'], '<p>v2', 'Objet', '', '<mjml>v2</mjml>');
    $versions->publish($id);
    check_same($source, $versions->find($versions->restoreAsDraft((int) $versions->publishedByNumber($id, 1)['id']))['mjml'], 'restauration');
    $copy = (new NewsletterRepo($db))->duplicate($id, 'Copie');
    check_same('<mjml>v2</mjml>', $versions->draft($copy)['mjml'], 'duplicated from the latest published one');
});

test('mjml: uploading an HTML file switches the newsletter back to HTML', function (): void {
    [$db, $id, $versions, $draft, $root, $importer] = mjml_imported();
    $draftId = $importer->createVersion(fixture_package('01-basique'), 'newsletter.html', $id);
    check_same(null, $versions->find($draftId)['mjml']);
});

test('mjml: relative export, same file names as the HTML zip, the rest byte-for-byte identical', function (): void {
    [$db, $id, $versions, $draft] = mjml_imported();
    $images = $versions->images((int) $draft['id']);
    $out = Exporter::relativeMjml($draft['mjml'], $draft['html'], $images);
    $expected = strtr($draft['mjml'], [
        'src="images/logo.png"' => 'src="images/logo.png"',
        'background-url="images/fond.png"' => 'background-url="images/fond.png"',
        "url('images/fond.png')" => "url('images/fond.png')",
        'src="images/Photo%20Produit.png"' => 'src="images/Photo-Produit.png"',
        'src="icones/plus.png"' => 'src="images/plus.png"',
        'icon-wrapped-url="icones/plus.png"' => 'icon-wrapped-url="images/plus.png"',
        'icon-unwrapped-url="icones/moins.png"' => 'icon-unwrapped-url="images/moins.png"',
    ]);
    check_same($expected, $out);

    $htmlNames = array_unique(array_column(Exporter::fileNames($draft['html'], $images), 'name'));
    foreach (ImageScanner::scan($out) as $ref) {
        if (str_starts_with($ref['raw'], 'images/') && $ref['raw'] !== 'images/absente.png') {
            check(in_array(substr($ref['raw'], 7), $htmlNames, true), 'nom absent du zip HTML : ' . $ref['raw']);
        }
    }
});

test('mjml: export with hosted URLs', function (): void {
    [$db, $id, $versions, $draft] = mjml_imported();
    $out = Exporter::absoluteMjml($draft['mjml'], $versions->images((int) $draft['id']), 'https://example.com/calage/');
    $logo = 'https://example.com/calage/i/' . hash_file('sha256', __DIR__ . '/mjml/images/logo.png') . '.png';
    check(substr_count($out, $logo) === 2, 'mj-image and mj-social-element');
    check(str_contains($out, 'src="images/absente.png"'), 'missing image left as is');
    check(str_contains($out, '<mj-include path="./pied-de-page.mjml" />'), 'rest of the source untouched');
});

test('mjml: zip of the source with its images/ folder', function (): void {
    [$db, $id, $versions, $draft, $root] = mjml_imported();
    $images = $versions->images((int) $draft['id']);
    $zip = Exporter::mjmlZip($draft['mjml'], $draft['html'], $images, 'rentree', $root, temp_dir());
    $contents = zip_contents($zip);
    $files = array_keys($contents);
    sort($files);
    check_same([
        'images/Photo-Produit.png', 'images/fond.png', 'images/hero.jpg', 'images/logo.png',
        'images/moins.png', 'images/pixel.gif', 'images/plus.png', 'images/vignette.jpg', 'rentree.html', 'rentree.mjml',
    ], $files);
    check_same(Exporter::relativeHtml($draft['html'], $images), $contents['rentree.html'], 'compiled HTML as a bonus, same paths');
    check(str_contains($contents['rentree.html'], 'src="images/Photo-Produit.png"'));
});
