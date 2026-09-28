<?php
declare(strict_types=1);

use Calage\Import\Importer;
use Calage\Repo\FolderRepo;
use Calage\Repo\NewsletterRepo;
use Calage\Repo\VersionRepo;
use Calage\Storage\ImageStore;

test('folders: create, rename, filter, delete', function (): void {
    [$db, $id] = imported();
    $folders = new FolderRepo($db);
    $newsletters = new NewsletterRepo($db);

    $clientA = $folders->create('Client A');
    $folders->create('client b');
    check_same(['Client A', 'client b'], array_column($folders->all(), 'name'), 'alphabetical order ignoring case');

    $newsletters->move($id, $clientA);
    check_same([$id], array_map('intval', array_column($newsletters->all($clientA), 'id')));
    check_same([], $newsletters->all(0), 'nothing left without a folder');
    check_same('Client A', $newsletters->all()[0]['folder_name']);

    $folders->rename($clientA, 'Client A (2026)');
    check_same('Client A (2026)', $folders->find($clientA)['name']);

    check_same(0, $folders->delete($folders->create('Empty'), false), 'empty folder');
});

test('folders: deleting a folder that is not empty keeps or deletes its newsletters', function (): void {
    [$db, $id] = imported();
    $folders = new FolderRepo($db);
    $newsletters = new NewsletterRepo($db);
    $imageCount = (int) $db->query('SELECT COUNT(*) FROM images')->fetchColumn();

    $keep = $folders->create('Keep');
    $newsletters->move($id, $keep);
    check_same(1, $folders->delete($keep, false));
    check_same(null, $folders->find($keep));
    check_same(null, $newsletters->find($id)['folder_id'], 'newsletter kept, without a folder');

    $gone = $folders->create('Gone');
    $newsletters->move($id, $gone);
    $token = $newsletters->find($id)['token'];
    check_same(1, $folders->delete($gone, true));
    check_same([null, null, null], [$folders->find($gone), $newsletters->find($id), $newsletters->findByToken($token)], 'folder, newsletter and share link deleted');
    check_same(0, (int) $db->query('SELECT COUNT(*) FROM versions')->fetchColumn(), 'versions deleted');
    check_same($imageCount, (int) $db->query('SELECT COUNT(*) FROM images')->fetchColumn(), 'hosted images kept');
});

test('duplicate: starts from the latest published one, as a draft, new link, same folder', function (): void {
    [$db, $id, $versions] = imported();
    $newsletters = new NewsletterRepo($db);
    $folder = (new FolderRepo($db))->create('Client');
    $newsletters->move($id, $folder);

    $first = $versions->draft($id);
    $versions->updateDraft((int) $first['id'], $first['html'], 'Objet v1', 'Pré v1');
    $versions->publish($id);
    $versions->updateDraft($versions->ensureDraft($id), '<p>brouillon non publié', 'x', 'x');

    $copyId = $newsletters->duplicate($id, 'Copie de Test');
    $copy = $newsletters->find($copyId);
    $original = $newsletters->find($id);

    check($copy['token'] !== $original['token'], 'new share link');
    check_same($folder, (int) $copy['folder_id']);
    check_same('Copie de Test', $copy['name']);

    $list = $versions->forNewsletter($copyId);
    check_same(1, count($list), 'history not copied');
    check_same('draft', $list[0]['status']);
    $draft = $versions->draft($copyId);
    check_same([$first['html'], 'Objet v1', 'Pré v1'], [$draft['html'], $draft['subject'], $draft['preheader']]);
    check_same($versions->hashes((int) $first['id']), $versions->hashes((int) $draft['id']));

    check_same(1, $versions->publish($copyId), 'the copy starts at v1');
    check_same(2, count($versions->forNewsletter($id)), 'original untouched: v1 + draft');
});

test('duplicate: without a published version, the copy starts from the draft', function (): void {
    [$db, $id, $versions] = imported();
    $copyId = (new NewsletterRepo($db))->duplicate($id, 'Copie');
    check_same($versions->draft($id)['html'], $versions->draft($copyId)['html']);
});

test('delete: versions and share link removed, hosted images kept', function (): void {
    $db = temp_db();
    $imagesDir = temp_dir() . '/i';
    $importer = new Importer($db, new ImageStore($db, $imagesDir));
    $newsletters = new NewsletterRepo($db);
    $versions = new VersionRepo($db);

    $id = $importer->createNewsletter(fixture_package('01-basique'), 'newsletter.html', 'À supprimer');
    $versions->publish($id);
    $versions->ensureDraft($id);
    $otherId = $importer->createNewsletter(fixture_package('01-basique'), 'newsletter.html', 'Autre');
    $token = $newsletters->find($id)['token'];
    $files = glob("$imagesDir/*");
    $imageCount = (int) $db->query('SELECT COUNT(*) FROM images')->fetchColumn();

    $newsletters->delete($id);

    check_same(null, $newsletters->find($id));
    check_same(null, $newsletters->findByToken($token), 'share link dead');
    check_same(0, (int) $db->query("SELECT COUNT(*) FROM versions WHERE newsletter_id = $id")->fetchColumn());
    check_same(0, (int) $db->query(
        "SELECT COUNT(*) FROM version_images WHERE version_id NOT IN (SELECT id FROM versions)"
    )->fetchColumn(), 'pas de correspondance orpheline');

    check_same($files, glob("$imagesDir/*"), 'image files untouched');
    check_same($imageCount, (int) $db->query('SELECT COUNT(*) FROM images')->fetchColumn(), 'images table untouched');
    check_same(5, count($versions->images((int) $versions->draft($otherId)['id'])), 'the other newsletter keeps its images');
});

test('delete: with the last newsletter gone, the images stay', function (): void {
    $db = temp_db();
    $imagesDir = temp_dir() . '/i';
    $importer = new Importer($db, new ImageStore($db, $imagesDir));
    $id = $importer->createNewsletter(fixture_package('02-outlook-vml'), 'newsletter.html', 'Seule');
    (new NewsletterRepo($db))->delete($id);
    check_same(4, count(glob("$imagesDir/*")));
    check_same(4, (int) $db->query('SELECT COUNT(*) FROM images')->fetchColumn());
});

test('search: name, subject, preheader and folder, ignoring accents and case', function (): void {
    [$db, $id, $versions] = imported();
    $newsletters = new NewsletterRepo($db);
    $newsletters->rename($id, 'Coffrets de Noël');
    $newsletters->move($id, (new FolderRepo($db))->create('Maison Dupont'));
    $versions->updateDraft((int) $versions->draft($id)['id'], '<p>x', 'Nos coffrets sont arrivés', 'Livraison offerte avant le 15 décembre');
    $other = $newsletters->create('Soldes d’hiver', null);
    $versions->createDraft($other, '<p>y', 'Jusqu’à -40 %', '', []);

    $ids = fn(string $q): array => array_map('intval', array_column($newsletters->all(null, $q), 'id'));
    check_same([$id], $ids('noel'), 'accents ignored');
    check_same([$id], $ids('ARRIVES'), 'subject, case ignored');
    check_same([$id], $ids('decembre livraison'), 'preheader, several words');
    check_same([$id], $ids('dupont coffrets'), 'folder name');
    check_same([], $ids('noel soldes'), 'all words must appear');
    check_same(2, count($newsletters->all(null, '  ')), 'empty search: everything');
    check_same('Jusqu’à -40 %', $newsletters->all(null, 'hiver')[0]['subject'], 'subject of the current version');
});
