<?php
declare(strict_types=1);

namespace Calage\Html;

/**
 * Finds image references in an HTML string, without ever modifying it or parsing it into a DOM.
 *
 * Walks the raw text tag by tag, including inside <!--[if mso]> conditional comments
 * (they hold the VML for Outlook). Ordinary comments, which nobody sees, are skipped.
 * Each reference is returned with its exact offset in the original string,
 * for a rewrite by targeted replacement (see Rewriter).
 *
 * Contexts covered:
 *   - src of <img>, <input type=image>, VML <v:image>, <v:fill>, <v:imagedata>;
 *   - srcset (each candidate separately);
 *   - background attribute (table, td, body…);
 *   - url() in style attributes and in <style> blocks.
 * And in an MJML source (paths are written the same way there):
 *   - src of <mj-image>, <mj-carousel-image>, <mj-social-element>;
 *   - background-url, thumbnails-src, icon-wrapped-url, icon-unwrapped-url;
 *   - url() in <mj-style> blocks; the HTML of <mj-raw> and <mj-text> is read as HTML.
 */
final class ImageScanner
{
    /** Tags whose src attribute points to an image. */
    private const SRC_TAGS = ['img', 'image', 'input', 'v:image', 'v:fill', 'v:imagedata', 'mj-image', 'mj-carousel-image', 'mj-social-element'];

    /** Attributes that always point to an image (background in HTML, the others in MJML). */
    private const IMAGE_ATTRIBUTES = ['background', 'background-url', 'thumbnails-src', 'icon-wrapped-url', 'icon-unwrapped-url'];

    /**
     * @return list<array{offset: int, length: int, raw: string, kind: string, entities: bool}>
     *   offset/length: position of the path in the HTML; raw: the path as written;
     *   entities: true when the path is inside an attribute (HTML entities can be decoded there).
     */
    public static function scan(string $html): array
    {
        $refs = [];
        $len = strlen($html);
        $pos = 0;

        while (($pos = strpos($html, '<', $pos)) !== false) {
            // Ordinary comment: skipped entirely. Conditional comment (<!--[if mso]>…):
            // scanning goes on inside, that is where the VML for Outlook lives.
            if (substr_compare($html, '<!--', $pos, 4) === 0 && !preg_match('/\G<!--\[if\b/i', $html, $_, 0, $pos)) {
                // Search from pos + 2: "<!-->" (used by <!--[if !mso]><!-->) closes at once.
                $close = strpos($html, '-->', $pos + 2);
                $pos = $close === false ? $len : $close + 3;
                continue;
            }
            if (!preg_match('/\G<([A-Za-z][A-Za-z0-9:_-]*)/', $html, $m, 0, $pos)) {
                $pos++; // '</', '<!--', '<![endif]', text "a < b"…: just move on.
                continue;
            }
            $tag = strtolower($m[1]);
            [$attributes, $end] = self::parseAttributes($html, $pos + strlen($m[0]), $len);

            foreach ($attributes as [$name, $offset, $value]) {
                if ($value === '') {
                    continue;
                }
                if (($name === 'src' && in_array($tag, self::SRC_TAGS, true)) || in_array($name, self::IMAGE_ATTRIBUTES, true)) {
                    $refs[] = self::ref($offset, $value, $name, true);
                } elseif ($name === 'srcset') {
                    foreach (self::srcsetCandidates($value) as [$o, $url]) {
                        $refs[] = self::ref($offset + $o, $url, 'srcset', true);
                    }
                } elseif ($name === 'style') {
                    foreach (self::cssUrls($value) as [$o, $url]) {
                        $refs[] = self::ref($offset + $o, $url, 'style', true);
                    }
                }
            }

            $pos = $end;

            // <style> block: the CSS is read up to </style> (or to the end, like a browser).
            if ($tag === 'style' || $tag === 'mj-style') {
                $close = stripos($html, '</' . $tag, $pos);
                $close = $close === false ? $len : $close;
                foreach (self::cssUrls(substr($html, $pos, $close - $pos)) as [$o, $url]) {
                    $refs[] = self::ref($pos + $o, $url, 'css', false);
                }
                $pos = $close;
            }
        }

        return array_values(array_filter($refs, fn(array $r): bool => $r['raw'] !== ''));
    }

    /**
     * Reads the attributes of a tag from $pos, honoring quotes
     * (a '>' inside a quoted value does not close the tag).
     *
     * @return array{0: list<array{0: string, 1: int, 2: string}>, 1: int} [[name, value offset, value]], offset after the tag
     */
    private static function parseAttributes(string $html, int $pos, int $len): array
    {
        $attributes = [];
        while ($pos < $len) {
            $pos += strspn($html, " \t\r\n\f/", $pos);
            if ($pos >= $len || $html[$pos] === '>') {
                return [$attributes, $pos + 1];
            }

            $nameLen = strcspn($html, " \t\r\n\f=>/", $pos);
            if ($nameLen === 0) { // unexpected character (stray quote…): ignore it
                $pos++;
                continue;
            }
            $name = strtolower(substr($html, $pos, $nameLen));
            $pos += $nameLen;
            $pos += strspn($html, " \t\r\n\f", $pos);

            if ($pos >= $len || $html[$pos] !== '=') {
                continue; // attribute without a value
            }
            $pos++;
            $pos += strspn($html, " \t\r\n\f", $pos);
            if ($pos >= $len) {
                break;
            }

            $quote = $html[$pos];
            if ($quote === '"' || $quote === "'") {
                $close = strpos($html, $quote, $pos + 1);
                if ($close === false) {
                    break; // quote never closed: give up on this tag
                }
                $attributes[] = [$name, $pos + 1, substr($html, $pos + 1, $close - $pos - 1)];
                $pos = $close + 1;
            } else {
                $valueLen = strcspn($html, " \t\r\n\f>", $pos);
                $attributes[] = [$name, $pos, substr($html, $pos, $valueLen)];
                $pos += $valueLen;
            }
        }
        return [$attributes, $len];
    }

    /**
     * Candidates of a srcset: "a.jpg 1x, b.jpg 2x".
     * @return list<array{0: int, 1: string}> [offset in the value, url]
     */
    private static function srcsetCandidates(string $value): array
    {
        $out = [];
        $len = strlen($value);
        $pos = 0;
        while ($pos < $len) {
            $pos += strspn($value, " \t\r\n\f,", $pos);
            if ($pos >= $len) {
                break;
            }
            $urlLen = strcspn($value, " \t\r\n\f", $pos);
            $url = rtrim(substr($value, $pos, $urlLen), ',');
            if ($url !== '') {
                $out[] = [$pos, $url];
            }
            $pos += $urlLen;
            // Descriptors ("2x", "600w") up to the next comma.
            if (!str_ends_with(substr($value, $pos - 1, 1), ',')) {
                $comma = strpos($value, ',', $pos);
                $pos = $comma === false ? $len : $comma + 1;
            }
        }
        return $out;
    }

    /**
     * url(…) in CSS. Quotes may be encoded as entities in a style attribute
     * (url(&quot;a.jpg&quot;)): those are recognized too.
     * @return list<array{0: int, 1: string}> [offset in the CSS, url]
     */
    private static function cssUrls(string $css): array
    {
        $out = [];
        $pos = 0;
        while (preg_match('/url\(\s*/i', $css, $m, PREG_OFFSET_CAPTURE, $pos)) {
            $start = $m[0][1] + strlen($m[0][0]);
            $quote = null;
            if (preg_match('/\G(?:"|\'|&quot;|&apos;|&#0*39;|&#0*34;|&#x0*27;|&#x0*22;)/i', $css, $q, 0, $start)) {
                $quote = $q[0];
                $start += strlen($quote);
                $end = stripos($css, $quote, $start);
            } else {
                $end = strpos($css, ')', $start);
            }
            if ($end === false) {
                break;
            }
            $url = substr($css, $start, $end - $start);
            if ($quote === null) {
                $url = rtrim($url);
            }
            if ($url !== '') {
                $out[] = [$start, $url];
            }
            $pos = $end + 1;
        }
        return $out;
    }

    private static function ref(int $offset, string $value, string $kind, bool $entities): array
    {
        // Keep the exact offset, but without the spaces that sometimes surround the value.
        $trimmedLeft = ltrim($value);
        $offset += strlen($value) - strlen($trimmedLeft);
        $raw = rtrim($trimmedLeft);
        return ['offset' => $offset, 'length' => strlen($raw), 'raw' => $raw, 'kind' => $kind, 'entities' => $entities];
    }
}
