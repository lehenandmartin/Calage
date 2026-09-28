<?php
declare(strict_types=1);

namespace Calage\Html;

/**
 * Preview text of an email, as an email client builds it when there is no preheader:
 * the first visible words of the body. Read-only: the HTML itself is never modified.
 *
 * Like email clients, a hidden preheader block (display:none) at the top of the body is read too:
 * that is the usual way of setting a preheader in HTML. Outlook-only content (<!--[if mso]>),
 * <head>, <style> and <script> are skipped, as are the invisible characters used as padding
 * (&zwnj;, &#847;, soft hyphens…).
 */
final class PreviewText
{
    public const MAX = 150;

    /** Text of the <title> tag (<mj-title> once compiled), a natural suggestion for the subject. '' when missing. */
    public static function title(string $html): string
    {
        if (!preg_match('#<title\b[^>]*>(.*?)</title\s*>#is', $html, $m)) {
            return '';
        }
        $title = mb_check_encoding($m[1], 'UTF-8') ? $m[1] : mb_convert_encoding($m[1], 'UTF-8', 'Windows-1252');
        $title = html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $title)), 0, 250);
    }

    public static function fromHtml(string $html, int $max = self::MAX): string
    {
        if (!mb_check_encoding($html, 'UTF-8')) {
            $html = mb_convert_encoding($html, 'UTF-8', 'Windows-1252');
        }
        // The body only, when there is one.
        if (preg_match('/<body\b[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE)) {
            $html = substr($html, $m[0][1] + strlen($m[0][0]));
        }
        // Comments, including Outlook conditional blocks. "<!--[if !mso]><!-->" closes at once,
        // so the content meant for the other clients is kept.
        $html = (string) preg_replace('/<!--.*?(-->|$)/s', ' ', $html);
        $html = (string) preg_replace('#<(head|style|script|title|noscript|template|svg)\b.*?(</\1\s*>|$)#is', ' ', $html);
        // Block tags separate words; inline tags do not.
        $html = (string) preg_replace('#</?(p|div|td|th|tr|table|tbody|thead|li|ul|ol|h[1-6]|br|hr|center|blockquote|section|article|header|footer)\b[^>]*>#i', ' ', $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Invisible characters used to pad preheaders, then all whitespace (non-breaking spaces included).
        $text = (string) preg_replace('/[\x{00AD}\x{034F}\x{200B}-\x{200F}\x{2060}-\x{2064}\x{FEFF}]/u', '', $text);
        $text = trim((string) preg_replace('/[\s\x{00A0}\x{2007}\x{202F}]+/u', ' ', $text));

        if (mb_strlen($text) <= $max) {
            return $text;
        }
        // Cut at a word boundary when there is one near the limit.
        $cut = mb_substr($text, 0, $max);
        $space = mb_strrpos($cut, ' ');
        return rtrim($space !== false && $space > $max - 30 ? mb_substr($cut, 0, $space) : $cut, ' ,;:–-');
    }
}
