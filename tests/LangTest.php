<?php
declare(strict_types=1);

use Calage\Lang;

/**
 * English texts passed as literals to the translation functions, by file.
 * PHP: __('…'), __n('…', '…', $n), __h('…'). JavaScript: t('…'), n('…', '…', count), CalageLang.t / .n.
 * @return array<string, list<string>> text → files where it appears
 */
function lang_literals(array $files, string $callPattern): array
{
    $string = "'((?:[^'\\\\]|\\\\.)*)'";
    $found = [];
    foreach ($files as $file) {
        // Comment lines are skipped: they hold usage examples, not interface texts.
        $code = (string) preg_replace('#^\s*(//|/\*|\*).*$#m', '', (string) file_get_contents($file));
        preg_match_all('/' . $callPattern . '\(\s*' . $string . '/s', $code, $m, PREG_SET_ORDER);
        foreach ($m as $match) {
            // For a plural, the key is the singular; the English plural is not a key of its own.
            $found[stripcslashes($match[1])][] = $file;
        }
    }
    return $found;
}

function php_sources(): array
{
    return array_merge(glob(APP_ROOT . '/src/*.php'), glob(APP_ROOT . '/src/*/*.php'), glob(APP_ROOT . '/templates/*.php'), glob(APP_ROOT . '/templates/partials/*.php'));
}

/** Placeholders of a text: {name}, sorted. */
function placeholders(string $text): array
{
    preg_match_all('/\{(\w+)\}/', $text, $m);
    $names = array_unique($m[1]);
    sort($names);
    return $names;
}

test('translation: every interface text of the PHP code has a French translation', function (): void {
    $messages = require APP_ROOT . '/src/lang/fr.php';
    $used = lang_literals(php_sources(), '(?<![\w>:$])__[nh]?');
    $missing = array_diff(array_keys($used), array_keys($messages));
    check_same([], array_values($missing), 'texts without a French translation in src/lang/fr.php');
});

test('translation: every interface text of the scripts has a French translation', function (): void {
    $messages = require APP_ROOT . '/src/lang/fr-js.php';
    $used = lang_literals(glob(APP_ROOT . '/assets/*.js'), '(?<![\w$])(?:CalageLang\.)?[tn]');
    $missing = array_diff(array_keys($used), array_keys($messages));
    check_same([], array_values($missing), 'texts without a French translation in src/lang/fr-js.php');
});

test('translation: no unused French entry', function (): void {
    // Weekday names are translated from date('D'), not from a literal.
    $dynamic = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    $php = array_keys(lang_literals(php_sources(), '(?<![\w>:$])__[nh]?'));
    $unused = array_diff(array_keys(require APP_ROOT . '/src/lang/fr.php'), $php, $dynamic);
    check_same([], array_values($unused), 'unused entries in src/lang/fr.php');

    $js = array_keys(lang_literals(glob(APP_ROOT . '/assets/*.js'), '(?<![\w$])(?:CalageLang\.)?[tn]'));
    $unused = array_diff(array_keys(require APP_ROOT . '/src/lang/fr-js.php'), $js);
    check_same([], array_values($unused), 'unused entries in src/lang/fr-js.php');
});

test('translation: French texts keep the same placeholders', function (): void {
    foreach (['fr.php', 'fr-js.php'] as $file) {
        foreach (require APP_ROOT . '/src/lang/' . $file as $english => $french) {
            foreach ((array) $french as $variant) {
                check_same(placeholders($english), placeholders($variant), "$file: “{$english}”");
            }
        }
    }
});

test('translation: French texts use no informal “tu”', function (): void {
    foreach (['fr.php', 'fr-js.php'] as $file) {
        foreach (require APP_ROOT . '/src/lang/' . $file as $english => $french) {
            foreach ((array) $french as $variant) {
                check(!preg_match('/\b(tu|te|toi|ton|ta|tes)\b/iu', $variant), "$file: “{$variant}”");
            }
        }
    }
});

test('language: chosen from the browser among the supported ones', function (): void {
    $cases = [
        'fr-FR,fr;q=0.9,en;q=0.8' => 'fr',
        'en-US,en;q=0.9,fr;q=0.8' => 'en',
        'de-DE,de;q=0.9,fr;q=0.5' => 'fr',
        'de-DE,es;q=0.9' => 'en',
        'en;q=0.5, fr;q=0.8' => 'fr',
        '' => 'en',
        'n’importe quoi' => 'en',
    ];
    foreach ($cases as $header => $expected) {
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = $header;
        check_same($expected, Lang::fromBrowser(), $header);
    }
    $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'de';
    check_same('fr', Lang::fromBrowser('fr'), 'fallback');
    unset($_SERVER['HTTP_ACCEPT_LANGUAGE']);
});

test('language: placeholders, plurals and dates in English and French', function (): void {
    try {
        Lang::set('en');
        check_same('Version 3 published: it is visible on the share link.', __('Version {n} published: it is visible on the share link.', ['n' => 3]));
        check_same('1 newsletter', __n('{n} newsletter', '{n} newsletters', 1));
        check_same('0 newsletters', __n('{n} newsletter', '{n} newsletters', 0));
        check_same('Sep 24, 2025', Lang::date(new DateTimeImmutable('2025-09-24'), 'full'));
        check_same('Wed Sep 24', Lang::date(new DateTimeImmutable('2025-09-24'), 'weekday'));
        check_same('Upload &lt;x&gt; {file}', __h('Upload <x> {file}'), 'escaped, unknown placeholder kept');

        Lang::set('fr');
        check_same('Version 3 publiée : elle est visible sur le lien de partage.', __('Version {n} published: it is visible on the share link.', ['n' => 3]));
        check_same('0 newsletter', __n('{n} newsletter', '{n} newsletters', 0), 'French: 0 is singular');
        check_same('2 newsletters', __n('{n} newsletter', '{n} newsletters', 2));
        check_same('24/09/2025', Lang::date(new DateTimeImmutable('2025-09-24'), 'full'));
        check_same('mer. 24/09', Lang::date(new DateTimeImmutable('2025-09-24'), 'weekday'));
        check_same('Texte inconnu', __('Texte inconnu'), 'missing translation: the English key is shown');

        Lang::set('xx');
        check_same('en', Lang::current(), 'unknown language → English');
    } finally {
        Lang::set('en');
    }
});
