<?php
declare(strict_types=1);

// Small global functions, mostly for the templates.

use Calage\App;
use Calage\Csrf;
use Calage\Lang;

/** Escapes a value for HTML output (text or attribute). */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Internal link, prefixed with the subfolder if any: url('/login') → /calage/login. */
function url(string $path = '/'): string
{
    return App::url($path);
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(Csrf::token()) . '">';
}

function redirect(string $path): never
{
    header('Location: ' . url($path), true, 303);
    exit;
}

/** Single-line text field sent by POST: extra whitespace removed, length capped. */
function post_text(string $name, int $max = 200): string
{
    $value = trim((string) preg_replace('/\s+/u', ' ', (string) ($_POST[$name] ?? '')));
    return mb_substr($value, 0, $max);
}

function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Fluent icon (assets/icons.svg), decorative by default: the text next to it carries the meaning. */
function icon(string $name, string $class = ''): string
{
    return '<svg class="icon' . ($class !== '' ? ' ' . e($class) : '') . '" aria-hidden="true" focusable="false">'
        . '<use href="' . e(url('/assets/icons.svg')) . '#i-' . e($name) . '"/></svg>';
}

/** Translated text (see Calage\Lang): __('Hello {name}', ['name' => $n]). Not escaped. */
function __(string $text, array $params = []): string
{
    return Lang::translate($text, $params);
}

/** Translated text with a count: __n('{n} image', '{n} images', $count). Not escaped. */
function __n(string $one, string $many, int $n, array $params = []): string
{
    return Lang::plural($one, $many, $n, $params);
}

/**
 * Translated text as HTML: the text is escaped, the placeholders in $html are inserted as is.
 * __h('Upload {file} over FTP.', ['file' => '<code>config.php</code>'])
 */
function __h(string $text, array $html = []): string
{
    $out = e(Lang::translate($text));
    $pairs = [];
    foreach ($html as $key => $value) {
        $pairs['{' . $key . '}'] = (string) $value;
    }
    return strtr($out, $pairs);
}

/** Short date, inbox style: "10:42", "Yesterday", "Thu Sep 24", "Sep 24, 2025". */
function short_date(string $iso): string
{
    $date = (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone(date_default_timezone_get()));
    $today = new DateTimeImmutable('today');
    $day = $date->setTime(0, 0);
    if ($day == $today) {
        return $date->format('H:i');
    }
    if ($day == $today->modify('-1 day')) {
        return __('Yesterday');
    }
    if ($date->format('Y') === $today->format('Y')) {
        return Lang::date($date, 'weekday');
    }
    return Lang::date($date, 'full');
}

/** Long date: "today at 10:42", "yesterday at 18:12", "on Sep 18, 2026 at 16:05". */
function long_date(string $iso): string
{
    $date = (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone(date_default_timezone_get()));
    $today = new DateTimeImmutable('today');
    $day = $date->setTime(0, 0);
    $time = $date->format('H:i');
    if ($day == $today) {
        return __('today at {time}', ['time' => $time]);
    }
    if ($day == $today->modify('-1 day')) {
        return __('yesterday at {time}', ['time' => $time]);
    }
    return __('on {date} at {time}', ['date' => Lang::date($date, 'full'), 'time' => $time]);
}
