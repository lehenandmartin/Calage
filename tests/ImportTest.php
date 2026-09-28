<?php
declare(strict_types=1);

use Calage\Html\ImageScanner;
use Calage\Html\PathResolver;
use Calage\Html\Renderer;
use Calage\Html\Rewriter;
use Calage\Import\Importer;
use Calage\Repo\VersionRepo;
use Calage\Storage\ImageStore;
use Calage\Storage\Package;

/** Loads a folder of tests/fixtures as an import (without the expected.* files). */
function fixture_package(string $name): Package
{
    $root = __DIR__ . '/fixtures/' . $name;
    $package = Package::create(temp_dir(), $name);
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        $relative = substr($file->getPathname(), strlen($root) + 1);
        if (!str_starts_with($relative, 'expected.')) {
            $package->addFile($relative, $file->getPathname());
        }
    }
    $package->save();
    return $package;
}

function fixture_expected(string $name): array
{
    return json_decode(file_get_contents(__DIR__ . "/fixtures/$name/expected.json"), true);
}

// --- HTML test set: analysis and rewriting ------------------------------------------------------

foreach (glob(__DIR__ . '/fixtures/*', GLOB_ONLYDIR) as $dir) {
    $name = basename($dir);

    test("$name: images found and resolved", function () use ($name): void {
        $expected = fixture_expected($name);
        $report = Importer::analyze(fixture_package($name), $expected['html']);
        $actual = [];
        foreach ($report['images'] as $image) {
            $actual[$image['raw']] = [$image['status'], $image['path'], $image['match'], $image['count']];
        }
        check_same($expected['images'], $actual);
        check_same($expected['external'], $report['external'], 'external URLs');
        check_same($expected['ignored'], $report['ignored'], 'ignored references');
    });

    test("$name : positions exactes dans le HTML d'origine", function () use ($name): void {
        $html = fixture_package($name)->read(fixture_expected($name)['html']);
        foreach (ImageScanner::scan($html) as $ref) {
            check_same($ref['raw'], substr($html, $ref['offset'], $ref['length']));
        }
    });

    test("$name: byte-exact rewrite", function () use ($name, $dir): void {
        $expected = fixture_expected($name);
        $package = fixture_package($name);
        $html = $package->read($expected['html']);
        $refs = ImageScanner::scan($html);

        // Replacing each path with itself changes nothing.
        check_same($html, Rewriter::rewrite($html, $refs, fn(array $r): string => $r['raw']));

        if (!is_file("$dir/expected.html")) {
            return;
        }
        $found = [];
        foreach (Importer::analyze($package, $expected['html'])['images'] as $image) {
            if ($image['status'] === 'found') {
                $found[$image['raw']] = 'https://h.test/' . $image['path'];
            }
        }
        check_same(file_get_contents("$dir/expected.html"), Rewriter::rewriteMap($html, $found));
    });
}

// --- Scanner: special cases ----------------------------------------------------------------------

test('scanner: srcset with width descriptors and commas without spaces', function (): void {
    $refs = ImageScanner::scan('<img srcset="a.jpg 1x,b.jpg 600w ,  c.jpg, d.jpg,">');
    check_same(['a.jpg', 'b.jpg', 'c.jpg', 'd.jpg'], array_column($refs, 'raw'));
    // As in browsers: without a space, the comma is part of the URL.
    check_same(['a.jpg,b.jpg'], array_column(ImageScanner::scan('<img srcset="a.jpg,b.jpg">'), 'raw'));
});

test('scanner: url() with quotes encoded as entities', function (): void {
    $html = '<td style="background:url(&#39;a.jpg&#39;); background-image: URL(&quot;b.png&quot;)">';
    check_same(['a.jpg', 'b.png'], array_column(ImageScanner::scan($html), 'raw'));
});

test('scanner: ordinary comment ignored, conditional comment scanned', function (): void {
    $html = '<!-- <img src="non.jpg"> --><!--[if mso]><v:image src="oui.png"/><![endif]-->'
        . '<!--[if !mso]><!--><img src="aussi.png"><!--<![endif]-->';
    check_same(['oui.png', 'aussi.png'], array_column(ImageScanner::scan($html), 'raw'));
});

test('scanner: src ignored outside image tags, background taken everywhere', function (): void {
    $html = '<script src="app.js"></script><iframe src="x.html"></iframe><body background="fond.gif">';
    check_same(['fond.gif'], array_column(ImageScanner::scan($html), 'raw'));
});

test('scanner: truncated HTML without crashing', function (): void {
    check_same([], ImageScanner::scan('<img src="a.jpg'));
    check_same(['a.jpg'], array_column(ImageScanner::scan('<style>div{background:url(a.jpg)}'), 'raw'));
    check_same([], ImageScanner::scan('<!-- never closed <img src="a.jpg">'));
});

// --- Path resolution -----------------------------------------------------------------------------

test('paths: file name in NFD (macOS zip), HTML in NFC', function (): void {
    $nfd = "images/e\u{0301}te\u{0301}.png";
    $result = (new PathResolver([$nfd]))->resolve('images/été.png', true, 'index.html');
    check_same(['status' => 'found', 'path' => $nfd, 'match' => 'accents'], $result);
});

test('paths: HTML encoded in Windows-1252', function (): void {
    $result = (new PathResolver(['images/été.png']))->resolve("images/\xE9t\xE9.png", true, 'index.html');
    check_same('images/été.png', $result['path']);
});

test('paths: ambiguous file name alone is not resolved', function (): void {
    $resolver = new PathResolver(['a/logo.png', 'b/logo.png']);
    check_same('missing', $resolver->resolve('c/logo.png', true, 'index.html')['status']);
});

test('paths: the exact match wins over the case-insensitive one', function (): void {
    $resolver = new PathResolver(['img/Logo.png', 'img/logo.png']);
    check_same('img/logo.png', $resolver->resolve('img/logo.png', true, 'index.html')['path']);
    check_same('img/Logo.png', $resolver->resolve('img/Logo.png', true, 'index.html')['path']);
});

// --- Import: zip, folder -------------------------------------------------------------------------

test('zip: system files, dangerous paths and useless files left out', function (): void {
    $zipFile = temp_dir() . '/test.zip';
    $png = file_get_contents(__DIR__ . '/fixtures/01-basique/images/logo.png');
    $zip = new ZipArchive();
    $zip->open($zipFile, ZipArchive::CREATE);
    $zip->addFromString('Newsletter/index.html', '<img src="images/logo.png">');
    $zip->addFromString('Newsletter/images/logo.png', $png);
    $zip->addFromString('Newsletter\\images\\windows.png', $png);
    $zip->addFromString("Newsletter/\x82t\x82.png", $png, ZipArchive::FL_ENC_CP437); // "été" in CP437
    $zip->addFromString('__MACOSX/Newsletter/images/._logo.png', 'x');
    $zip->addFromString('Newsletter/.DS_Store', 'x');
    $zip->addFromString('../../evil.png', $png);
    $zip->addFromString('Newsletter/notes.txt', 'x');
    $zip->addEmptyDir('Newsletter/vide');
    $zip->close();

    $package = Package::create(temp_dir(), 'test.zip');
    $package->addZip($zipFile);
    $files = $package->files();
    sort($files);
    check_same(['Newsletter/images/logo.png', 'Newsletter/images/windows.png', 'Newsletter/index.html', 'Newsletter/été.png'], $files);
    check_same($png, file_get_contents($package->localPath('Newsletter/images/logo.png')));
});

test('zip: several HTML files, the shallowest first', function (): void {
    $package = Package::create(temp_dir(), 'x');
    $tmp = temp_dir() . '/f.html';
    file_put_contents($tmp, '<p>');
    foreach (['b/z/version-mobile.html', 'b/index.html', 'a/index.htm'] as $path) {
        $package->addFile($path, $tmp);
    }
    check_same(['a/index.htm', 'b/index.html', 'b/z/version-mobile.html'], $package->htmlFiles());
});

test('zip: an unreadable file gives a clear error', function (): void {
    $bad = temp_dir() . '/bad.zip';
    file_put_contents($bad, 'pas un zip');
    try {
        Package::create(temp_dir(), 'bad.zip')->addZip($bad);
        check(false, 'exception expected');
    } catch (Calage\Storage\ImportException $e) {
        check(str_contains($e->getMessage(), 'cannot be read'));
    }
});

// --- Storage by content hash ---------------------------------------------------------------------

test('images: deduplication, format read from the content, SVG refused', function (): void {
    $db = temp_db();
    $dir = temp_dir() . '/i';
    $store = new ImageStore($db, $dir);
    $fixtures = __DIR__ . '/fixtures';

    $a = $store->store("$fixtures/01-basique/images/logo.png");
    $b = $store->store("$fixtures/01-basique/images/logo.png");
    check_same($a, $b);
    check_same(hash_file('sha256', "$fixtures/01-basique/images/logo.png"), $a['hash']);
    check_same('png', $store->store("$fixtures/04-externes-manquantes/images/faux.jpg")['ext']);
    check_same('webp', $store->store("$fixtures/01-basique/images/inutilisee.webp")['ext']);
    check_same(null, $store->store("$fixtures/04-externes-manquantes/images/vecteur.svg"));

    check_same(3, count(glob("$dir/*")));
    check_same(3, (int) $db->query('SELECT COUNT(*) FROM images')->fetchColumn());
    $row = $db->query("SELECT * FROM images WHERE hash = '{$a['hash']}'")->fetch();
    check_same([4, 3, 'image/png'], [$row['width'], $row['height'], $row['mime']]);
});

// --- End to end ----------------------------------------------------------------------------------

test('full import: newsletter, draft, hosted images, absolute rendering', function (): void {
    $db = temp_db();
    $store = new ImageStore($db, temp_dir() . '/i');
    $importer = new Importer($db, $store);
    $package = fixture_package('02-outlook-vml');

    $id = $importer->createNewsletter($package, 'newsletter.html', 'Outlook');

    $newsletter = $db->query("SELECT * FROM newsletters WHERE id = $id")->fetch();
    check(preg_match('/^[a-f0-9]{32}$/', $newsletter['token']) === 1, 'random 32-character share token');
    $version = $db->query("SELECT * FROM versions WHERE newsletter_id = $id")->fetch();
    check_same(['draft', null], [$version['status'], $version['number']]);
    check_same($package->read('newsletter.html'), $version['html'], 'HTML stored as is');

    $images = (new VersionRepo($db))->images((int) $version['id']);
    $paths = array_keys($images);
    sort($paths);
    check_same(['img/Signature.PNG', 'img/bandeau.jpg', 'img/bouton-outlook.png', 'img/bouton.png'], $paths);

    $html = Renderer::absolute($version['html'], $images, 'https://example.com/calage/');
    $bandeau = 'https://example.com/calage/i/' . hash_file('sha256', __DIR__ . '/fixtures/02-outlook-vml/img/bandeau.jpg') . '.jpg';
    check(substr_count($html, $bandeau) === 2, 'banner replaced in the <td> and in the VML');
    check_same([], Renderer::unresolved($version['html'], $images));
});

test('full import: missing and unsupported images reported', function (): void {
    $db = temp_db();
    $importer = new Importer($db, new ImageStore($db, temp_dir() . '/i'));
    $id = $importer->createNewsletter(fixture_package('04-externes-manquantes'), 'newsletter.html', 'Manques');
    $version = $db->query("SELECT * FROM versions WHERE newsletter_id = $id")->fetch();
    $images = (new VersionRepo($db))->images((int) $version['id']);
    check_same(['images/faux.jpg'], array_keys($images));
    check_same(['images/absente.png', 'images/vecteur.svg'], Renderer::unresolved($version['html'], $images));
});

test('database: a single draft per newsletter', function (): void {
    $db = temp_db();
    $repo = new VersionRepo($db);
    $id = (new Calage\Repo\NewsletterRepo($db))->create('N', null);
    $repo->createDraft($id, '<p>1', '', '', []);
    try {
        $repo->createDraft($id, '<p>2', '', '', []);
        check(false, 'a second draft should have been refused');
    } catch (PDOException $e) {
        check(str_contains($e->getMessage(), 'UNIQUE'));
    }
});
