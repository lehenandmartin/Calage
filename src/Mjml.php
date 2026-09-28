<?php
declare(strict_types=1);

namespace Calage;

/**
 * MJML: compiled in the browser with mjml-browser (the server never runs MJML, no Node).
 * The compiled HTML stays the reference (preview, images, exports); the MJML is stored as well, as the source.
 */
final class Mjml
{
    /** Pinned version: the same as the one used locally, for identical HTML. */
    public const VERSION = '5.4.1';
    public const SCRIPT = 'https://cdn.jsdelivr.net/npm/mjml-browser@5.4.1/lib/index.js';
    public const INTEGRITY = 'sha384-ySiUeuTIEKnz+ndRzbWUvS3i7XyNsn3WJND57wygXhN6qqtU3UY179Ij0RBWwx46';

    /** @return list<string> paths of the <mj-include> tags (not supported in the browser: ignored when compiling) */
    public static function includes(string $mjml): array
    {
        preg_match_all('/<mj-include\b[^>]*?\bpath\s*=\s*(["\'])(.*?)\1/i', $mjml, $m);
        $paths = $m[2];
        // <mj-include> without a readable path attribute: reported anyway.
        $count = preg_match_all('/<mj-include\b/i', $mjml);
        for ($i = count($paths); $i < $count; $i++) {
            $paths[] = __('(unreadable path)');
        }
        return $paths;
    }

    /**
     * Text of <mj-preview> (the newsletter preheader), cleaned: tags removed, entities decoded,
     * whitespace collapsed. Null when the tag is missing or empty.
     */
    public static function preview(string $mjml): ?string
    {
        if (!preg_match('/<mj-preview\b[^>]*>(.*?)<\/mj-preview>/is', $mjml, $m)) {
            return null;
        }
        $text = html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        return $text === '' ? null : mb_substr($text, 0, 500);
    }

    public static function isMjmlPath(string $path): bool
    {
        return preg_match('/\.mjml$/i', $path) === 1;
    }
}
