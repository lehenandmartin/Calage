<?php
declare(strict_types=1);

use Calage\Html\PreheaderBlock;
use Calage\Mjml;

test('preheader block: found and replaced in the block written by MJML, rest of the HTML untouched', function (): void {
    $html = file_get_contents(__DIR__ . '/mjml/compiled.html');
    check_same('Nouvelles tablettes et coffrets dès le 1er octobre', PreheaderBlock::read($html));
    $out = PreheaderBlock::set($html, 'Offres d’octobre & coffrets');
    check_same('Offres d’octobre & coffrets', PreheaderBlock::read($out));
    check(str_contains($out, '>Offres d’octobre &amp; coffrets</div>'), 'escaped');
    $back = PreheaderBlock::set($out, 'Nouvelles tablettes et coffrets dès le 1er octobre');
    check_same($html, $back, 'putting the text back gives the original bytes');
});

test('preheader block: hand-written block with padding, the padding is kept', function (): void {
    $html = "<html><body>\n<span class=\"preheader\" style=\"display: none !important\">Old text&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;</span>\n<table><tr><td>Hello</td></tr></table></body></html>";
    check_same('Old text', PreheaderBlock::read($html));
    $out = PreheaderBlock::set($html, 'New text');
    check(str_contains($out, '>New text&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;</span>'), $out);
});

test('preheader block: added after <body> when missing, removed when emptied', function (): void {
    $html = "<html>\n<body style=\"margin:0\">\n<table><tr><td>Hello</td></tr></table>\n</body></html>";
    check_same(null, PreheaderBlock::read($html));
    $out = PreheaderBlock::set($html, 'Preview text');
    check_same('Preview text', PreheaderBlock::read($out));
    check(str_starts_with(substr($out, strpos($out, '<body style="margin:0">') + 23), "\n<div style=\"display:none;"), 'right after <body>');
    check_same('Preview text Hello', Calage\Html\PreviewText::fromHtml($out), 'what an email client shows');
    check_same($html, PreheaderBlock::set($out, ''), 'emptied: the block and its line are removed');
    check_same($html, PreheaderBlock::set($html, ''), 'nothing to remove');
});

test('preheader block: not taken from the content, hidden elements after visible text ignored', function (): void {
    $html = '<body><p>Visible first</p><div style="display:none">Hidden later</div></body>';
    check_same(null, PreheaderBlock::read($html));
    check_same(null, PreheaderBlock::read('<body><img src="a.png" style="display:none"><p>Text</p></body>'));
    check_same('Inside', PreheaderBlock::read('<body><!--[if mso]><table><![endif]--><table><tr><td><div style="display:none"><b>Inside</b></div>Text</td></tr></table></body>'));
});

test('preheader block: Windows-1252 HTML keeps its encoding, CRLF line endings kept', function (): void {
    $html = "<html>\r\n<body>\r\n<p>caf\xE9</p>\r\n</body></html>";
    $out = PreheaderBlock::set($html, 'Été');
    check(!mb_check_encoding($out, 'UTF-8'), 'still Windows-1252');
    check(str_contains($out, "<body>\r\n<div style=\"display:none;"), 'CRLF');
    check_same('Été', PreheaderBlock::read($out));
});

test('mjml: <mj-preview> replaced, added in <mj-head>, <mj-head> created, removed', function (): void {
    $source = "<mjml>\n  <mj-head>\n    <mj-preview>Old</mj-preview>\n  </mj-head>\n</mjml>";
    check_same("<mjml>\n  <mj-head>\n    <mj-preview>A &amp; B</mj-preview>\n  </mj-head>\n</mjml>", Mjml::setPreview($source, 'A & B'));
    check_same("<mjml>\n  <mj-head>\n  </mj-head>\n</mjml>", Mjml::setPreview($source, ''));
    check_same($source, Mjml::setPreview($source, 'Old'));
    check_same("<mjml>\n  <mj-head>\n    <mj-preview>New</mj-preview>\n    <mj-title>T</mj-title>\n  </mj-head>\n</mjml>",
        Mjml::setPreview("<mjml>\n  <mj-head>\n    <mj-title>T</mj-title>\n  </mj-head>\n</mjml>", 'New'));
    check_same("<mjml>\n  <mj-head>\n    <mj-preview>New</mj-preview>\n  </mj-head>\n  <mj-body></mj-body>\n</mjml>",
        Mjml::setPreview("<mjml>\n  <mj-body></mj-body>\n</mjml>", 'New'));
});
