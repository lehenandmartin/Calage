<?php
declare(strict_types=1);

namespace Calage\Html;

/**
 * Round trip of the HTML between the database and the browser's editor, without altering the bytes.
 *
 * A browser always sends a <textarea> back in UTF-8 with CRLF line endings, and the BOM
 * can get lost. So the traits of the original HTML are kept in order to restore them:
 * encoding (UTF-8 or Windows-1252), BOM, LF or CRLF line endings.
 */
final class EditableText
{
    private const BOM = "\xEF\xBB\xBF";

    /** Text to put in the editor (UTF-8, no BOM). */
    public static function toEditor(string $html): string
    {
        $text = str_starts_with($html, self::BOM) ? substr($html, 3) : $html;
        return mb_check_encoding($text, 'UTF-8') ? $text : mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
    }

    /**
     * Text sent back by the editor → HTML in the format of $original.
     * Without any real change, returns $original as is (even with mixed line endings).
     */
    public static function fromEditor(string $text, string $original): string
    {
        $text = str_starts_with($text, self::BOM) ? substr($text, 3) : $text;

        // Line endings: those of the original (the browser always sends CRLF).
        $text = str_replace("\r\n", "\n", $text);
        if ($text === str_replace("\r\n", "\n", self::toEditor($original))) {
            return $original;
        }
        if (str_contains($original, "\r\n")) {
            $text = str_replace("\n", "\r\n", $text);
        }

        $body = str_starts_with($original, self::BOM) ? substr($original, 3) : $original;
        if (!mb_check_encoding($body, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
        }

        return (str_starts_with($original, self::BOM) ? self::BOM : '') . $text;
    }
}
