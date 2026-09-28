<?php
declare(strict_types=1);

use Calage\Repo\NewsletterRepo;
use Calage\Repo\VersionRepo;

test('share page: the token finds the newsletter, nothing else gets through', function (): void {
    $db = temp_db();
    $repo = new NewsletterRepo($db);
    $newsletter = $repo->find($repo->create('N', null));

    check_same((int) $newsletter['id'], (int) $repo->findByToken($newsletter['token'])['id']);
    check_same(null, $repo->findByToken(strtoupper($newsletter['token'])), 'token is case-sensitive');
    check_same(null, $repo->findByToken(substr($newsletter['token'], 0, 31)), 'truncated token');
    check_same(null, $repo->findByToken((string) $newsletter['id']), 'never by id');
    check_same(null, $repo->findByToken("' OR 1=1 --"));
});

test('share page: only published versions are visible, never the draft', function (): void {
    [$db, $id, $versions] = imported();
    check_same([], $versions->publishedList($id), 'draft only: nothing to show');
    check_same(null, $versions->latestPublished($id));

    $versions->publish($id);
    $versions->updateDraft($versions->ensureDraft($id), '<p>secret en cours', 'Brouillon', '');

    check_same([1], array_map('intval', array_column($versions->publishedList($id), 'number')));
    check_same(null, $versions->publishedByNumber($id, 2), 'the next number does not exist yet');
    check_same(null, $versions->publishedByNumber($id, 0));
    check(!str_contains($versions->latestPublished($id)['html'], 'secret'));
});

test('share page: a version of another newsletter cannot be reached by its number', function (): void {
    [$db, $id, $versions, $importer] = imported();
    $versions->publish($id);
    $otherId = $importer->createNewsletter(fixture_package('02-outlook-vml'), 'newsletter.html', 'Autre');
    check_same(null, $versions->publishedByNumber($otherId, 1));
});

test('degraded preview: fonts and images refused by the CSP header, sandbox always present', function (): void {
    check_same('sandbox', Calage\View::previewPolicy(false, false));
    check_same("sandbox; font-src 'none'", Calage\View::previewPolicy(true, false));
    check_same("sandbox; img-src 'none'", Calage\View::previewPolicy(false, true));
    check_same("sandbox; font-src 'none'; img-src 'none'", Calage\View::previewPolicy(true, true));
});
