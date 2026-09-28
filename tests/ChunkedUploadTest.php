<?php
declare(strict_types=1);

use Calage\Controllers\UploadController;
use Calage\Storage\ChunkedUpload;
use Calage\Storage\ImportException;
use Calage\Storage\Package;

/** Sends $content in chunks of $size bytes, like the browser. */
function send_chunks(ChunkedUpload $upload, int $index, string $content, int $size): int
{
    $received = 0;
    $tmp = temp_dir() . '/chunk';
    do {
        file_put_contents($tmp, substr($content, $received, $size));
        $received = $upload->write($index, $received, $tmp);
    } while ($received < strlen($content));
    return $received;
}

function expect_import_error(callable $fn, string $needle): void
{
    try {
        $fn();
        check(false, "error expected: $needle");
    } catch (ImportException $e) {
        check(str_contains($e->getMessage(), $needle), 'message: ' . $e->getMessage());
    }
}

test('chunks: sorting of the announced files', function (): void {
    $upload = ChunkedUpload::start(temp_dir(), [
        ['path' => 'Campagne/index.html', 'size' => 10],
        ['path' => 'Campagne/.DS_Store', 'size' => 10],
        ['path' => 'Campagne/images/logo.png', 'size' => 10],
        ['path' => 'Campagne/source.psd', 'size' => 10],
        ['path' => '__MACOSX/Campagne/._logo.png', 'size' => 10],
        ['path' => 'Campagne/archives.zip', 'size' => 10],
    ], []);
    check_same([0, 2], $upload->accepted(), 'HTML and images; a zip among other files is ignored');

    $zip = ChunkedUpload::start(temp_dir(), [['path' => 'newsletter.zip', 'size' => 10]], []);
    check_same([0], $zip->accepted(), 'a zip on its own is kept');
});

test('chunks: limits when announcing', function (): void {
    expect_import_error(fn() => ChunkedUpload::start(temp_dir(), [], []), 'No file');
    expect_import_error(fn() => ChunkedUpload::start(temp_dir(), [['path' => 'a.psd', 'size' => 1]], []), 'No HTML');
    expect_import_error(fn() => ChunkedUpload::start(temp_dir(), [['path' => 'a.zip', 'size' => ChunkedUpload::MAX_BYTES + 1]], []), 'exceeds');
    $many = array_fill(0, ChunkedUpload::MAX_FILES + 1, ['path' => 'a.png', 'size' => 1]);
    expect_import_error(fn() => ChunkedUpload::start(temp_dir(), $many, []), 'Too many files');
});

test('chunks: exact reassembly, harmless resend, gap and overflow refused', function (): void {
    $content = random_bytes(10_000);
    $upload = ChunkedUpload::start(temp_dir(), [['path' => 'a.png', 'size' => strlen($content)], ['path' => 'b.html', 'size' => 0]], []);
    check_same(['a.png', 'b.html'], $upload->missing());

    $tmp = temp_dir() . '/chunk';
    file_put_contents($tmp, substr($content, 0, 3000));
    check_same(3000, $upload->write(0, 0, $tmp));
    check_same(3000, $upload->write(0, 0, $tmp), 'same chunk sent again (network cut): no effect');

    file_put_contents($tmp, substr($content, 5000, 1000));
    expect_import_error(fn() => $upload->write(0, 5000, $tmp), 'out of sequence');

    file_put_contents($tmp, substr($content, 3000) . 'en trop');
    expect_import_error(fn() => $upload->write(0, 3000, $tmp), 'too long');

    check_same(10_000, send_chunks($upload, 0, $content, 3000), 'restart from the beginning: rewrite then continue');
    file_put_contents($tmp, '');
    check_same(0, $upload->write(1, 0, $tmp), 'empty file: one empty chunk');
    check_same([], $upload->missing());

    $files = $upload->files();
    check_same($content, file_get_contents($files[0]['tmp_name']));
    check_same(['name' => 'a.png', 'full_path' => 'a.png'], array_intersect_key($files[0], ['name' => 1, 'full_path' => 1]));
});

test('chunks: a file refused when announced cannot be sent', function (): void {
    $upload = ChunkedUpload::start(temp_dir(), [['path' => 'a.html', 'size' => 1], ['path' => 'b.psd', 'size' => 1]], []);
    $tmp = temp_dir() . '/chunk';
    file_put_contents($tmp, 'x');
    expect_import_error(fn() => $upload->write(1, 0, $tmp), 'Unexpected file');
    expect_import_error(fn() => $upload->write(7, 0, $tmp), 'Unexpected file');
});

test('chunks: uploaded folder → import ready for review, target folder kept', function (): void {
    $base = temp_dir();
    $fixtures = __DIR__ . '/fixtures/01-basique';
    $paths = ['Rentrée/newsletter.html', 'Rentrée/images/logo.png', 'Rentrée/images/hero.jpg'];
    $sources = ["$fixtures/newsletter.html", "$fixtures/images/logo.png", "$fixtures/images/hero.jpg"];
    $upload = ChunkedUpload::start($base, array_map(
        fn(string $p, string $s): array => ['path' => $p, 'size' => filesize($s)], $paths, $sources
    ), ['newsletter_id' => null, 'folder_id' => 4]);
    foreach ($sources as $i => $source) {
        send_chunks($upload, $i, file_get_contents($source), 700);
    }
    $package = Package::fromUploads($base, $upload->files(), $upload->meta('newsletter_id'), $upload->meta('folder_id'));

    check_same('Rentrée', $package->sourceName());
    check_same(['Rentrée/newsletter.html'], $package->htmlFiles());
    check_same(4, $package->folderId());
    check_same(file_get_contents("$fixtures/images/hero.jpg"), $package->read('Rentrée/images/hero.jpg'));
    $upload->delete();
    check_same(null, ChunkedUpload::load($base, $upload->id()));
});

test('chunks: a zip on its own is unpacked', function (): void {
    $base = temp_dir();
    $zipFile = temp_dir() . '/n.zip';
    $zip = new ZipArchive();
    $zip->open($zipFile, ZipArchive::CREATE);
    $zip->addFromString('n/index.html', '<img src="a.png">');
    $zip->addFromString('n/a.png', file_get_contents(__DIR__ . '/fixtures/01-basique/images/logo.png'));
    $zip->close();

    $upload = ChunkedUpload::start($base, [['path' => 'Soldes.zip', 'size' => filesize($zipFile)]], []);
    send_chunks($upload, 0, file_get_contents($zipFile), 300);
    $package = Package::fromUploads($base, $upload->files(), null, null);
    check_same('Soldes', $package->sourceName());
    check_same(['n/index.html'], $package->htmlFiles());
});

test('chunks: chunk size below the PHP limits', function (): void {
    check_same(2 * 1024 * 1024, UploadController::bytes('2M'));
    check_same(512 * 1024, UploadController::bytes('512K'));
    check_same(1 << 30, UploadController::bytes('1G'));
    check_same(0, UploadController::bytes('0'));
    $size = UploadController::chunkSize();
    check($size >= 256 * 1024 && $size <= 4 * 1024 * 1024, "size: $size");
    $upload = UploadController::bytes(ini_get('upload_max_filesize'));
    check($upload === 0 || $size < $upload, 'below upload_max_filesize');
});

test('chunks: abandoned upload purged after 24 h', function (): void {
    $base = temp_dir();
    $old = ChunkedUpload::start($base, [['path' => 'a.html', 'size' => 1]], []);
    touch("$base/uploads/{$old->id()}", time() - 90000);
    $new = ChunkedUpload::start($base, [['path' => 'a.html', 'size' => 1]], []);
    check_same(null, ChunkedUpload::load($base, $old->id()));
    check(ChunkedUpload::load($base, $new->id()) !== null);
});
