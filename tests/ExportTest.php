<?php
declare(strict_types=1);

use Calage\Export\Exporter;
use Calage\Html\Rewriter;
use Calage\Import\Importer;
use Calage\Repo\VersionRepo;
use Calage\Storage\ImageStore;
use Calage\Storage\Package;

/** Imports a package and returns [html, images, image root folder]. */
function export_setup(Package $package, string $htmlPath): array
{
    $db = temp_db();
    $root = temp_dir();
    $importer = new Importer($db, new ImageStore($db, $root . '/i'));
    $id = $importer->createNewsletter($package, $htmlPath, 'Export');
    $versions = new VersionRepo($db);
    $version = $versions->draft($id);
    return [$version['html'], $versions->images((int) $version['id']), $root];
}

/** @return array<string, string> name in the zip → content */
function zip_contents(string $file): array
{
    $zip = new ZipArchive();
    $zip->open($file);
    $out = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $out[$zip->getNameIndex($i)] = $zip->getFromIndex($i);
    }
    $zip->close();
    return $out;
}

test('export: cleaned names (spaces, accents, entities) and extension of the actual format', function (): void {
    [$html, $images] = export_setup(fixture_package('03-chemins'), 'emails/campagne/newsletter.html');
    $names = array_column(Exporter::fileNames($html, $images), 'name');
    check_same(['Mon-Image.jpg', 'ete.png', 'Logo.jpg', 'pixel.gif', 'a-b.png', 'racine.png', 'trop-haut.png', 'banniere.jpg'], $names);

    [$html, $images] = export_setup(fixture_package('04-externes-manquantes'), 'newsletter.html');
    check_same(['faux.png'], array_column(Exporter::fileNames($html, $images), 'name'), 'a PNG named .jpg comes out as .png');
});

test('export: duplicates renamed, same image only once, case ignored', function (): void {
    $fixtures = __DIR__ . '/fixtures';
    $tmp = temp_dir() . '/n.html';
    file_put_contents($tmp, '<img src="a/logo.png"><img src="b/logo.png"><img src="c/LOGO.png"><img src="a/logo.png">'
        . '<td background="copie/logo.png"><img src="a/./logo.png">');
    $package = Package::create(temp_dir(), 'x');
    $package->addFile('n.html', $tmp);
    $package->addFile('a/logo.png', "$fixtures/01-basique/images/logo.png");
    $package->addFile('b/logo.png', "$fixtures/01-basique/images/fond.png");
    $package->addFile('c/LOGO.png', "$fixtures/02-outlook-vml/img/bouton.png");
    $package->addFile('copie/logo.png', "$fixtures/01-basique/images/logo.png"); // same content as a/logo.png

    [$html, $images] = export_setup($package, 'n.html');
    $names = array_map(fn(array $f): string => $f['name'], Exporter::fileNames($html, $images));
    check_same([
        'a/logo.png' => 'logo.png',
        'b/logo.png' => 'logo-2.png',
        'c/LOGO.png' => 'LOGO-3.png',
        'copie/logo.png' => 'logo.png',
        'a/./logo.png' => 'logo.png',
    ], $names);
});

test('export: zip with relative HTML, images/ and identical bytes apart from paths', function (): void {
    [$html, $images, $root] = export_setup(fixture_package('02-outlook-vml'), 'newsletter.html');
    $zip = Exporter::zip($html, $images, 'rentree.html', $root, temp_dir());
    $files = zip_contents($zip);
    ksort($files);

    check_same(['images/Signature.png', 'images/bandeau.jpg', 'images/bouton-outlook.png', 'images/bouton.png', 'rentree.html'], array_keys($files));
    check_same(file_get_contents(__DIR__ . '/fixtures/02-outlook-vml/img/bandeau.jpg'), $files['images/bandeau.jpg']);

    $expected = str_replace(
        ['img/bandeau.jpg', 'img/bouton-outlook.png', 'img/bouton.png', 'img/Signature.PNG'],
        ['images/bandeau.jpg', 'images/bouton-outlook.png', 'images/bouton.png', 'images/Signature.png'],
        $html
    );
    check_same($expected, $files['rentree.html']);
});

test('export: missing images and external URLs left as is', function (): void {
    [$html, $images, $root] = export_setup(fixture_package('04-externes-manquantes'), 'newsletter.html');
    $files = zip_contents(Exporter::zip($html, $images, 'n.html', $root, temp_dir()));
    check_same(['n.html', 'images/faux.png'], array_keys($files));
    check_same(str_replace('src="images/faux.jpg"', 'src="images/faux.png"', $html), $files['n.html']);
});

test('export: standalone HTML with absolute URLs (base_url in a subfolder)', function (): void {
    [$html, $images] = export_setup(fixture_package('01-basique'), 'newsletter.html');
    $out = Exporter::absoluteHtml($html, $images, 'https://example.com/calage/');
    $logo = 'https://example.com/calage/i/' . hash_file('sha256', __DIR__ . '/fixtures/01-basique/images/logo.png') . '.png';
    check(substr_count($out, $logo) === 3, 'logo: src, src and srcset');
    foreach (Calage\Html\ImageScanner::scan($out) as $ref) {
        check(str_starts_with($ref['raw'], 'https://') || str_starts_with($ref['raw'], 'fonts/'), 'remaining path: ' . $ref['raw']);
    }
    check(str_contains($out, '<img src="images/ancien.jpg">'), 'ordinary comment untouched');
    check(str_contains($out, 'url(fonts/marque.woff2)'), 'font untouched');

    // Putting the original paths back gives exactly the starting HTML.
    $back = array_map(fn(array $i): string => 'https://example.com/calage/i/' . $i['hash'] . '.' . $i['ext'], $images);
    check_same($html, Rewriter::rewriteMap($out, array_flip($back)));
});

test('export: zip file name', function (): void {
    check_same('Rentree-2026-c-est-parti', Exporter::slug('Rentrée 2026 : c’est parti !'));
    check_same('Noel-Soeurs', Exporter::slug('Noël & Sœurs'));
    check_same('newsletter', Exporter::slug('???'));
});
