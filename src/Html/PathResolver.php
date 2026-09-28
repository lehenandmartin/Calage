<?php
declare(strict_types=1);

namespace Calage\Html;

/**
 * Matches a path written in the HTML with a file actually present in the import.
 *
 * Normalization: HTML entities, %20 and other URL encodings, Windows backslashes, ./ and ../,
 * ?v=2 parameters and #anchors. Then lookup, from the strictest to the loosest:
 *   exact → ignoring case → ignoring accents (NFC/NFD included)
 *   → by file name alone, when it is unique in the import.
 */
final class PathResolver
{
    /** Extensions that are not images (fonts, CSS): a url() pointing to them is ignored. */
    private const NOT_IMAGES = ['woff', 'woff2', 'ttf', 'otf', 'eot', 'css', 'htc'];

    /** @var array<string, string> */ private array $exact = [];
    /** @var array<string, string> */ private array $lower = [];
    /** @var array<string, string> */ private array $folded = [];
    /** @var array<string, list<string>> */ private array $byName = [];

    /** @param list<string> $files paths of the import's files, separated by '/' */
    public function __construct(array $files)
    {
        foreach ($files as $file) {
            $this->exact[$file] = $file;
            $this->lower[mb_strtolower($file)] ??= $file;
            $this->folded[self::fold($file)] ??= $file;
            $this->byName[self::fold(basename($file))][] = $file;
        }
    }

    /**
     * @param string $raw path as written in the HTML
     * @param bool $entities true when the path comes from an attribute (HTML entities to decode)
     * @param string $htmlPath path of the HTML file in the import (for relative paths)
     * @return array{status: 'external'|'ignored'|'found'|'missing', path: ?string, match: ?string}
     *   match : exact | case | accents | name
     */
    public function resolve(string $raw, bool $entities, string $htmlPath): array
    {
        $kind = self::classify($raw, $entities);
        if ($kind !== 'local') {
            return ['status' => $kind, 'path' => null, 'match' => null];
        }

        $value = self::decode($raw, $entities);
        $candidates = [];
        foreach (array_unique([rawurldecode($value), $value]) as $variant) {
            $candidates[] = self::normalize($variant, $htmlPath);
        }

        foreach ([
            'exact' => fn(string $c): ?string => $this->exact[$c] ?? null,
            'case' => fn(string $c): ?string => $this->lower[mb_strtolower($c)] ?? null,
            'accents' => fn(string $c): ?string => $this->folded[self::fold($c)] ?? null,
            'name' => function (string $c): ?string {
                $matches = $this->byName[self::fold(basename($c))] ?? [];
                return count($matches) === 1 ? $matches[0] : null;
            },
        ] as $match => $find) {
            foreach ($candidates as $candidate) {
                if (($found = $find($candidate)) !== null) {
                    return ['status' => 'found', 'path' => $found, 'match' => $match];
                }
            }
        }

        return ['status' => 'missing', 'path' => null, 'match' => null];
    }

    /**
     * external: absolute URL, left as is;
     * ignored: merge tag or file that is not an image (font, CSS);
     * local: path to a file of the import.
     * @return 'external'|'ignored'|'local'
     */
    public static function classify(string $raw, bool $entities): string
    {
        $value = self::decode($raw, $entities);
        if (self::isExternal($value)) {
            return 'external';
        }
        $ext = strtolower(pathinfo((string) preg_replace('/[?#].*$/s', '', $value), PATHINFO_EXTENSION));
        if (self::isMergeTag($value) || in_array($ext, self::NOT_IMAGES, true)) {
            return 'ignored';
        }
        return 'local';
    }

    private static function decode(string $raw, bool $entities): string
    {
        return trim($entities ? html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8') : $raw);
    }

    /** http:, https:, //, data:, cid:, mailto:…: left as is. */
    public static function isExternal(string $value): bool
    {
        return str_starts_with($value, '//')
            || str_starts_with($value, '#')
            || preg_match('/^[a-z][a-z0-9+.-]*:/i', $value) === 1;
    }

    /** Merge tags of email platforms (Mailchimp *|…|*, {{ … }}, %%…%%, [[…]]). */
    private static function isMergeTag(string $value): bool
    {
        return preg_match('/\*\||\{\{|\{%|%%|\[\[|<%|\$\{/', $value) === 1;
    }

    /** Path relative to the HTML → path in the import, without ./, ../ or parameters. */
    public static function normalize(string $value, string $htmlPath): string
    {
        $value = preg_replace('/[?#].*$/s', '', $value);
        $value = str_replace('\\', '/', $value);
        if (!mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
        }

        $dir = str_contains($htmlPath, '/') ? dirname($htmlPath) : '';
        $path = str_starts_with($value, '/') ? $value : $dir . '/' . $value;

        $parts = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($parts); // above the import's root: stay at the root
                continue;
            }
            $parts[] = $segment;
        }
        return implode('/', $parts);
    }

    /** Loose comparison key: lowercase, no accents, NFC and NFD treated alike. */
    public static function fold(string $value): string
    {
        return mb_strtolower(self::unaccent($value));
    }

    /** Removes accents (NFC or NFD), with or without the intl extension: "Été" → "Ete". */
    public static function unaccent(string $value): string
    {
        if (class_exists(\Normalizer::class)) {
            $value = \Normalizer::normalize($value, \Normalizer::FORM_D) ?: $value;
        }
        $value = (string) preg_replace('/\p{Mn}+/u', '', $value);
        // Without the intl extension: common precomposed accented letters.
        return strtr($value, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a',
            'À' => 'A', 'Â' => 'A', 'Ä' => 'A', 'Á' => 'A', 'Ã' => 'A', 'Å' => 'A',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ì' => 'i', 'Î' => 'I', 'Ï' => 'I', 'Í' => 'I', 'Ì' => 'I',
            'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'Ô' => 'O', 'Ö' => 'O', 'Ó' => 'O', 'Ò' => 'O', 'Õ' => 'O',
            'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U', 'Ú' => 'U',
            'ç' => 'c', 'Ç' => 'C', 'ñ' => 'n', 'Ñ' => 'N', 'ÿ' => 'y', 'œ' => 'oe', 'Œ' => 'OE', 'æ' => 'ae', 'Æ' => 'AE', 'ß' => 'ss',
        ]);
    }
}
