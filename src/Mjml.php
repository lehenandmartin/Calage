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

    /**
     * MJML source with <mj-preview> saying $text (same rules as previewEdit() in assets/mjml.js): the tag is
     * replaced, added in <mj-head> (created right after <mjml> if needed), or removed when $text is empty.
     * Unchanged when there is nothing to do, or no <mjml> root.
     */
    public static function setPreview(string $mjml, string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        $escaped = str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
        if (preg_match('#<mj-preview\b[^>]*?(?:/>|>(.*?)</mj-preview\s*>)#is', $mjml, $m, PREG_OFFSET_CAPTURE)) {
            if ((self::preview($mjml) ?? '') === $text) {
                return $mjml;
            }
            [$tag, $from] = $m[0];
            $to = $from + strlen($tag);
            if ($text === '') {
                // Remove the tag and, when it is alone on its line, the whole line.
                $lineStart = strrpos(substr($mjml, 0, $from), "\n");
                $lineEnd = strpos($mjml, "\n", $to);
                $before = substr($mjml, $lineStart === false ? 0 : $lineStart + 1, $from - ($lineStart === false ? 0 : $lineStart + 1));
                $after = substr($mjml, $to, ($lineEnd === false ? strlen($mjml) : $lineEnd) - $to);
                if (trim($before) === '' && trim($after) === '') {
                    $from = $lineStart === false ? 0 : $lineStart;
                    $to = $lineEnd === false ? strlen($mjml) : $lineEnd;
                }
                return substr($mjml, 0, $from) . substr($mjml, $to);
            }
            preg_match('#^<mj-preview\b[^>]*?(/?)>#i', $tag, $open);
            $openTag = $open[1] === '/' ? (string) preg_replace('#\s*/>$#', '>', $open[0]) : $open[0];
            return substr($mjml, 0, $from) . $openTag . $escaped . '</mj-preview>' . substr($mjml, $to);
        }
        if ($text === '') {
            return $mjml;
        }
        if (preg_match('#<mj-head\b[^>]*>#i', $mjml, $head, PREG_OFFSET_CAPTURE)) {
            $at = $head[0][1] + strlen($head[0][0]);
            return substr($mjml, 0, $at) . "\n" . self::indent($mjml, $head[0][1]) . '  <mj-preview>' . $escaped . '</mj-preview>' . substr($mjml, $at);
        }
        if (preg_match('#<mjml\b[^>]*>#i', $mjml, $root, PREG_OFFSET_CAPTURE)) {
            $at = $root[0][1] + strlen($root[0][0]);
            $base = self::indent($mjml, $root[0][1]) . '  ';
            return substr($mjml, 0, $at) . "\n$base<mj-head>\n$base  <mj-preview>" . $escaped . "</mj-preview>\n$base</mj-head>" . substr($mjml, $at);
        }
        return $mjml;
    }

    /** Spaces and tabs at the start of the line holding $offset. */
    private static function indent(string $source, int $offset): string
    {
        $lineStart = strrpos(substr($source, 0, $offset), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;
        preg_match('/^[ \t]*/', substr($source, $lineStart, $offset - $lineStart), $m);
        return $m[0];
    }

    public static function isMjmlPath(string $path): bool
    {
        return preg_match('/\.mjml$/i', $path) === 1;
    }
}
