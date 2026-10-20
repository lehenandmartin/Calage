<?php
declare(strict_types=1);

namespace Calage\Html;

/**
 * The preheader inside the HTML: the hidden block (display:none) at the top of the body, the usual way of
 * setting the preview text of an email. MJML writes its <mj-preview> the same way.
 *
 * read() gives its text; set() replaces it (or adds the block right after <body>, or removes it for an empty
 * preheader). set() is only used on HTML compiled from MJML, never on an uploaded HTML file. Targeted text
 * replacement: the rest of the HTML stays byte-for-byte identical, and no DOM parser is involved.
 */
final class PreheaderBlock
{
    /** Block added when the HTML has none (the padding keeps the body text out of the inbox preview). */
    private const STYLE = 'display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;';
    private const PADDING = '&#847;&zwnj;&nbsp;';
    private const PADDING_COUNT = 30;

    /** Padding characters that end the text of a block: &#847; / &zwnj; / &#8203;, as entities or as UTF-8 bytes. */
    private const PADDING_START = '/&#0*847;|&#x0*34f;|&zwnj;|&#0*8204;|&#x0*200c;|&#0*8203;|&#x0*200b;|\xCD\x8F|\xE2\x80\x8C|\xE2\x80\x8B/i';

    /**
     * Position of the preheader block: the first element of the body hidden with display:none, with no
     * visible text before it. Null when there is none.
     * @return array{start: int, end: int, textStart: int, textEnd: int}|null
     *   start/end: the whole element; textStart/textEnd: its text, without the padding that may follow
     */
    public static function find(string $html): ?array
    {
        $bodyEnd = self::bodyStart($html);
        $offset = $bodyEnd;
        $length = strlen($html);
        while ($offset < $length && preg_match('/<([a-z][a-z0-9:-]*)\b([^>]*)>/i', $html, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $tagStart = $m[0][1];
            $tagEnd = $tagStart + strlen($m[0][0]);
            // Visible text before this tag: no preheader block (the email starts with its content).
            if (self::visibleText(substr($html, $bodyEnd, $tagStart - $bodyEnd)) !== '') {
                return null;
            }
            $name = strtolower($m[1][0]);
            if (in_array($name, ['img', 'br', 'hr', 'meta', 'link', 'input', 'wbr', 'area', 'col', 'source'], true)) {
                $offset = $tagEnd; // void element: no content, cannot be the block
                continue;
            }
            if (preg_match('/\bstyle\s*=\s*(["\'])[^"\']*display\s*:\s*none/i', $m[2][0])) {
                $close = self::closingTag($html, $name, $tagEnd);
                if ($close === null) {
                    return null;
                }
                $content = substr($html, $tagEnd, $close[0] - $tagEnd);
                $textEnd = preg_match(self::PADDING_START, $content, $p, PREG_OFFSET_CAPTURE) ? $tagEnd + $p[0][1] : $close[0];
                return ['start' => $tagStart, 'end' => $close[1], 'textStart' => $tagEnd, 'textEnd' => $textEnd];
            }
            if (in_array($name, ['style', 'script', 'title'], true)) {
                $close = self::closingTag($html, $name, $tagEnd);
                $offset = $close === null ? $tagEnd : $close[1];
                continue;
            }
            $offset = $tagEnd;
        }
        return null;
    }

    /** Text of the preheader block (entities decoded, whitespace collapsed), or null when there is no block. */
    public static function read(string $html): ?string
    {
        $block = self::find($html);
        if ($block === null) {
            return null;
        }
        return self::visibleText(substr($html, $block['textStart'], $block['textEnd'] - $block['textStart']));
    }

    /**
     * HTML with $text as the preheader: the text of the existing block is replaced, or a block is added right
     * after <body>; an empty $text removes the block. Unchanged when it already says $text.
     */
    public static function set(string $html, string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        $block = self::find($html);
        if ($block !== null && self::read($html) === $text) {
            return $html;
        }
        if ($text === '') {
            return $block === null ? $html : self::removeBlock($html, $block['start'], $block['end']);
        }
        $escaped = htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        if (!mb_check_encoding($html, 'UTF-8')) {
            $escaped = mb_convert_encoding($escaped, 'Windows-1252', 'UTF-8'); // the HTML keeps its encoding
        }
        if ($block !== null) {
            return substr($html, 0, $block['textStart']) . $escaped . substr($html, $block['textEnd']);
        }
        $at = self::bodyStart($html);
        // On its own line when the code goes to a new line after <body> (removing it then removes that line).
        $newline = preg_match('/\G\r?\n/', $html, $nl, 0, $at) ? $nl[0] : '';
        $insert = $newline . '<div style="' . self::STYLE . '">' . $escaped . str_repeat(self::PADDING, self::PADDING_COUNT) . '</div>';
        return substr($html, 0, $at) . $insert . substr($html, $at);
    }

    /** Offset right after the <body> tag (0 for an HTML fragment without one). */
    private static function bodyStart(string $html): int
    {
        return preg_match('/<body\b[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE) ? $m[0][1] + strlen($m[0][0]) : 0;
    }

    /** [start, end] of the tag closing <$name> opened before $from, nested elements of the same name included. */
    private static function closingTag(string $html, string $name, int $from): ?array
    {
        $depth = 1;
        $pattern = '#<(/?)' . preg_quote($name, '#') . '\b[^>]*>#i';
        while (preg_match($pattern, $html, $m, PREG_OFFSET_CAPTURE, $from)) {
            $from = $m[0][1] + strlen($m[0][0]);
            if ($m[1][0] === '/') {
                if (--$depth === 0) {
                    return [$m[0][1], $from];
                }
            } elseif (!str_ends_with($m[0][0], '/>')) {
                $depth++;
            }
        }
        return null;
    }

    /** Visible text of an HTML piece: comments and tags removed, entities decoded, invisible characters dropped. */
    private static function visibleText(string $html): string
    {
        if (!mb_check_encoding($html, 'UTF-8')) {
            $html = mb_convert_encoding($html, 'UTF-8', 'Windows-1252');
        }
        $html = (string) preg_replace('/<!--.*?(-->|$)/s', ' ', $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/[\x{00AD}\x{034F}\x{200B}-\x{200F}\x{2060}-\x{2064}\x{FEFF}]/u', '', $text);
        return trim((string) preg_replace('/[\s\x{00A0}\x{2007}\x{202F}]+/u', ' ', $text));
    }

    /** Removes the element, and the line it leaves blank. */
    private static function removeBlock(string $html, int $start, int $end): string
    {
        $lineStart = strrpos(substr($html, 0, $start), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart;
        $before = substr($html, $lineStart, $start - $lineStart);
        $afterEnd = strcspn($html, "\n", $end);
        $after = substr($html, $end, $afterEnd);
        if (trim($before) === '' && trim($after) === '') {
            return substr($html, 0, $lineStart) . substr($html, $end + $afterEnd);
        }
        return substr($html, 0, $start) . substr($html, $end);
    }
}
