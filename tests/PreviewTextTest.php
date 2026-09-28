<?php
declare(strict_types=1);

use Calage\Html\PreviewText;
use Calage\Repo\NewsletterRepo;
use Calage\Repo\VersionRepo;

test('preview text: first visible words of the body, like an email client', function (): void {
    $html = '<html><head><title>Title</title><style>p { color: red }</style></head>'
        . '<body><table><tr><td><h1>Big&nbsp;sale</h1><p>Up to <b>50</b>% off<br>this week.</p></td></tr></table>'
        . '<script>alert(1)</script></body></html>';
    check_same('Big sale Up to 50% off this week.', PreviewText::fromHtml($html));
});

test('preview text: hidden preheader block read, invisible padding removed', function (): void {
    $html = '<body><div style="display:none;max-height:0;overflow:hidden">Hidden preheader'
        . str_repeat('&#847;&zwnj;&nbsp;', 20) . '</div><p>Visible text</p></body>';
    check_same('Hidden preheader Visible text', PreviewText::fromHtml($html));
});

test('preview text: Outlook-only blocks skipped, content for the other clients kept', function (): void {
    $html = '<body><!--[if mso]><p>Outlook only</p><![endif]--><!--[if !mso]><!--><p>Others</p><!--<![endif]-->'
        . '<!-- a comment --><p>End</p></body>';
    check_same('Others End', PreviewText::fromHtml($html));
});

test('preview text: cut at a word boundary, Windows-1252 read, no body tag', function (): void {
    $words = str_repeat('word ', 60);
    $text = PreviewText::fromHtml("<p>$words</p>");
    check(mb_strlen($text) <= PreviewText::MAX && str_ends_with($text, 'word'), $text);
    check_same('été', PreviewText::fromHtml("<p>\xE9t\xE9</p>"));
    check_same('', PreviewText::fromHtml('<body><img src="a.png" alt=""></body>'));
});

test('subject suggestion: <title> of the HTML', function (): void {
    check_same('Summer & sales', PreviewText::title("<head><title>\n  Summer &amp; sales </title></head>"));
    check_same('', PreviewText::title('<p>No title</p>'));
});

test('automatic preheader: generated when empty, updated on save, kept by copies until set by hand', function (): void {
    $db = temp_db();
    $versions = new VersionRepo($db);
    $newsletters = new NewsletterRepo($db);
    $id = $newsletters->create('N', null);

    $draftId = $versions->createDraft($id, '<body><p>First text</p></body>', '', '', []);
    $draft = $versions->find($draftId);
    check_same(['First text', 1, ''], [$draft['preheader'], (int) $draft['preheader_auto'], VersionRepo::userPreheader($draft)]);

    $versions->updateDraft($draftId, '<body><p>Second text</p></body>', 'Subject', '');
    check_same('Second text', $versions->find($draftId)['preheader'], 'recomputed from the new content');

    $versions->publish($id);
    $next = $versions->find($versions->ensureDraft($id));
    check_same(['Second text', 1], [$next['preheader'], (int) $next['preheader_auto']], 'new draft stays automatic');
    $copy = $versions->draft($newsletters->duplicate($id, 'Copy'));
    check_same(1, (int) $copy['preheader_auto'], 'duplicate stays automatic');

    $versions->updateDraft((int) $next['id'], '<body><p>Third</p></body>', 'Subject', 'Set by hand');
    $next = $versions->find((int) $next['id']);
    check_same(['Set by hand', 0, 'Set by hand'], [$next['preheader'], (int) $next['preheader_auto'], VersionRepo::userPreheader($next)]);
});
