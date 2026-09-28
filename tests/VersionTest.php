<?php
declare(strict_types=1);

use Calage\Html\EditableText;
use Calage\Import\Importer;
use Calage\Repo\NewsletterRepo;
use Calage\Repo\VersionRepo;
use Calage\Storage\ImageStore;
use Calage\Storage\Package;

/** Newsletter imported from a fixture, with its database and repositories. */
function imported(string $fixture = '01-basique'): array
{
    $db = temp_db();
    $importer = new Importer($db, new ImageStore($db, temp_dir() . '/i'));
    $id = $importer->createNewsletter(fixture_package($fixture), 'newsletter.html', 'Test');
    return [$db, $id, new VersionRepo($db), $importer];
}

// --- Draft and publication -------------------------------------------------------------------------

test('versions: publishing numbers 1, 2, 3 and freezes', function (): void {
    [$db, $id, $versions] = imported();
    check_same(1, $versions->publish($id));
    check_same(null, $versions->draft($id));
    check_same(null, $versions->publish($id), 'nothing to publish without a draft');

    $draftId = $versions->ensureDraft($id);
    $versions->updateDraft($draftId, '<p>v2', 'Objet', '');
    check_same(2, $versions->publish($id));
    check_same('<p>v2', $versions->latestPublished($id)['html']);

    try {
        $versions->updateDraft((int) $versions->latestPublished($id)['id'], '<p>modif', '', '');
        check(false, 'a published version must not be editable');
    } catch (LogicException) {
    }
});

test('versions: a new draft takes the HTML, subject, preheader and images of the latest published one', function (): void {
    [$db, $id, $versions] = imported();
    $first = $versions->draft($id);
    $versions->updateDraft((int) $first['id'], $first['html'], 'Soldes d’été', 'Jusqu’à -50 %');
    $versions->publish($id);

    $draftId = $versions->ensureDraft($id);
    $draft = $versions->find($draftId);
    check_same(['Soldes d’été', 'Jusqu’à -50 %', $first['html']], [$draft['subject'], $draft['preheader'], $draft['html']]);
    check_same($versions->hashes((int) $first['id']), $versions->hashes($draftId));
    check_same($draftId, $versions->ensureDraft($id), 'the existing draft is reused');
});

test('versions: restoring replaces the draft with an exact copy, then published under a new number', function (): void {
    [$db, $id, $versions] = imported();
    $v1 = $versions->draft($id);
    $versions->updateDraft((int) $v1['id'], $v1['html'], 'Objet v1', 'Pré v1');
    $versions->publish($id);
    $versions->updateDraft($versions->ensureDraft($id), '<p>v2', 'Objet v2', 'Pré v2');
    $versions->publish($id);
    $versions->updateDraft($versions->ensureDraft($id), '<p>brouillon en cours', 'x', 'x');

    $restored = $versions->restoreAsDraft((int) $v1['id']);
    $draft = $versions->draft($id);
    check_same($restored, (int) $draft['id']);
    check_same([$v1['html'], 'Objet v1', 'Pré v1'], [$draft['html'], $draft['subject'], $draft['preheader']]);
    check_same($versions->hashes((int) $v1['id']), $versions->hashes($restored));

    check_same(3, $versions->publish($id));
    check_same(3, count($versions->forNewsletter($id)), 'v1 and v2 untouched, plus v3');
    check_same('<p>v2', $versions->find((int) $versions->forNewsletter($id)[1]['id'])['html']);
});

test('versions: discarding the draft goes back to the latest published one', function (): void {
    [$db, $id, $versions] = imported();
    $versions->publish($id);
    $versions->updateDraft($versions->ensureDraft($id), '<p>essai', '', '');
    $versions->discardDraft($id);
    check_same(null, $versions->draft($id));
    check_same('published', $versions->current($id)['status']);
});

// --- New file uploaded to an existing newsletter ---------------------------------------------------

test('new version: the HTML alone takes the images of the current version', function (): void {
    [$db, $id, $versions, $importer] = imported();
    $v1 = $versions->draft($id);
    $versions->updateDraft((int) $v1['id'], $v1['html'], 'Objet', 'Preheader');
    $versions->publish($id);

    // A typo fixed, HTML uploaded without its images, plus an unknown image.
    $html = str_replace('</body>', '<img src="images/nouvelle.png"></body>', $v1['html']);
    $tmp = temp_dir() . '/newsletter.html';
    file_put_contents($tmp, $html);
    $package = Package::create(temp_dir(), 'newsletter', $id);
    $package->addFile('newsletter.html', $tmp);

    $previous = $versions->hashes((int) $v1['id']);
    $report = Importer::analyze($package, 'newsletter.html', $previous);
    $statuses = array_column($report['images'], 'match', 'raw');
    check_same('previous', $statuses['images/logo.png']);
    check_same(null, $statuses['images/nouvelle.png']);

    $draftId = $importer->createVersion($package, 'newsletter.html', $id);
    $draft = $versions->find($draftId);
    check_same(['Objet', 'Preheader', $html], [$draft['subject'], $draft['preheader'], $draft['html']]);
    check_same($previous, $versions->hashes($draftId), 'same images, nothing more');
});

test('new version: uploaded images win over those of the current version', function (): void {
    [$db, $id, $versions, $importer] = imported();
    $versions->publish($id);
    $old = $versions->hashes((int) $versions->latestPublished($id)['id'])['images/logo.png'];

    // Same path, new content.
    $package = fixture_package('01-basique');
    $newLogo = __DIR__ . '/fixtures/02-outlook-vml/img/bouton.png';
    $package->addFile('images/logo.png', $newLogo);

    $draftId = $importer->createVersion($package, 'newsletter.html', $id);
    $new = $versions->hashes($draftId)['images/logo.png'];
    check($new !== $old, 'the new logo must replace the old one');
    check_same(hash_file('sha256', $newLogo), $new);
});

test('new version: replaces the existing draft', function (): void {
    [$db, $id, $versions, $importer] = imported();
    $versions->updateDraft((int) $versions->draft($id)['id'], '<p>ancien brouillon', 'Objet gardé', '');
    $importer->createVersion(fixture_package('02-outlook-vml'), 'newsletter.html', $id);
    check_same(1, count($versions->forNewsletter($id)));
    check_same('Objet gardé', $versions->draft($id)['subject']);
    check(str_contains($versions->draft($id)['html'], 'v:fill'));
});

// --- Round trip through the editor ---------------------------------------------------------------

test('editor: unchanged text → original bytes, whatever the format', function (): void {
    $cases = [
        'LF' => "<p>a</p>\n<p>é</p>\n",
        'CRLF + BOM' => "\xEF\xBB\xBF<p>a</p>\r\n<p>é</p>\r\n",
        'Windows-1252' => "<meta charset=\"windows-1252\">\n<p>\xE9t\xE9</p>\n",
        'mixed line endings' => "<p>a</p>\r\n<p>b</p>\n",
    ];
    foreach ($cases as $label => $original) {
        $fromBrowser = str_replace("\n", "\r\n", str_replace("\r\n", "\n", EditableText::toEditor($original)));
        check_same($original, EditableText::fromEditor($fromBrowser, $original), $label);
    }
});

test('editor: a change keeps the original format', function (): void {
    $lf = EditableText::fromEditor("<p>b</p>\r\n<p>c</p>", "<p>a</p>\n");
    check_same("<p>b</p>\n<p>c</p>", $lf);

    $crlfBom = EditableText::fromEditor("<p>b</p>\r\n<p>c</p>", "\xEF\xBB\xBF<p>a</p>\r\n");
    check_same("\xEF\xBB\xBF<p>b</p>\r\n<p>c</p>", $crlfBom);

    $cp1252 = EditableText::fromEditor("<p>été à Noël</p>", "<p>\xE9t\xE9</p>");
    check_same("<p>\xE9t\xE9 \xE0 No\xEBl</p>", $cp1252);

    check_same('<p>é</p>', EditableText::toEditor("\xEF\xBB\xBF<p>é</p>"), 'BOM removed for the editor');
    check_same('<p>é</p>', EditableText::toEditor("<p>\xE9</p>"), 'Windows-1252 converted for the editor');
});

test('database: unique and random share token', function (): void {
    $db = temp_db();
    $repo = new NewsletterRepo($db);
    $tokens = [];
    for ($i = 0; $i < 50; $i++) {
        $tokens[] = $repo->find($repo->create("N$i", null))['token'];
    }
    check_same(50, count(array_unique($tokens)));
});

test('versions: subject entered right before publishing, on the draft only', function (): void {
    $db = temp_db();
    $versions = new VersionRepo($db);
    $id = (new Calage\Repo\NewsletterRepo($db))->create('N', null);
    $draftId = $versions->createDraft($id, '<body><p>Text</p></body>', '', 'Preheader', []);
    $versions->setDraftSubject($draftId, 'New subject');
    $versions->publish($id);
    $published = $versions->find($draftId);
    check_same(['New subject', 'Preheader', 1], [$published['subject'], $published['preheader'], (int) $published['number']]);
    $versions->setDraftSubject($draftId, 'Too late');
    check_same('New subject', $versions->find($draftId)['subject'], 'a published version is not changed');
});
