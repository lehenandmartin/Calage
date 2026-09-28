<?php
declare(strict_types=1);

namespace Calage;

/**
 * Interface language. The English text is the key: __('Save changes') returns it as is in English,
 * or its translation from src/lang/{locale}.php. Strings used by the JavaScript files live in
 * src/lang/{locale}-js.php and are handed to assets/lang.js as JSON.
 *
 * Placeholders are written {name} and filled from $params. Plurals: __n('{n} image', '{n} images', $n),
 * translated by a [singular, plural] pair keyed by the English singular.
 */
final class Lang
{
    /** Supported locales, English first (the default and the source language). */
    public const LOCALES = ['en' => 'English', 'fr' => 'Français'];
    public const DEFAULT = 'en';

    /** Date formats (PHP date()); weekday names come from the translations. */
    private const DATE_FORMATS = [
        'en' => ['day' => 'M j', 'full' => 'M j, Y'],
        'fr' => ['day' => 'd/m', 'full' => 'd/m/Y'],
    ];

    private static string $locale = self::DEFAULT;
    /** @var array<string, string|array{0: string, 1: string}> */
    private static array $messages = [];
    private static ?array $jsMessages = null;

    public static function set(string $locale): void
    {
        $locale = self::isSupported($locale) ? $locale : self::DEFAULT;
        self::$locale = $locale;
        self::$jsMessages = null;
        self::$messages = [];
        if ($locale !== self::DEFAULT) {
            $messages = require APP_ROOT . "/src/lang/$locale.php";
            self::$messages = is_array($messages) ? $messages : [];
        }
    }

    public static function current(): string
    {
        return self::$locale;
    }

    public static function isSupported(string $locale): bool
    {
        return isset(self::LOCALES[$locale]);
    }

    /** Language chosen in the settings (English when none has been saved yet). */
    public static function configured(): string
    {
        $locale = (string) (App::rawConfig()['locale'] ?? self::DEFAULT);
        return self::isSupported($locale) ? $locale : self::DEFAULT;
    }

    /** Best supported language from the browser's Accept-Language header, or $fallback. */
    public static function fromBrowser(string $fallback = self::DEFAULT): string
    {
        $header = (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
        $best = null;
        $bestQuality = 0.0;
        foreach (explode(',', $header) as $position => $part) {
            if (!preg_match('/^\s*([a-z]{1,8})(?:-[a-z0-9]{1,8})*\s*(?:;\s*q\s*=\s*([01](?:\.\d{0,3})?))?\s*$/i', $part, $m)) {
                continue;
            }
            $quality = isset($m[2]) && $m[2] !== '' ? (float) $m[2] : 1.0;
            $quality -= $position / 1000; // equal weights: the first one listed wins
            $language = strtolower($m[1]);
            if ($quality > $bestQuality && self::isSupported($language)) {
                $best = $language;
                $bestQuality = $quality;
            }
        }
        return $best ?? $fallback;
    }

    public static function translate(string $text, array $params = []): string
    {
        $translation = self::$messages[$text] ?? $text;
        if (is_array($translation)) {
            $translation = $translation[0];
        }
        return self::fill($translation, $params);
    }

    public static function plural(string $one, string $many, int $n, array $params = []): string
    {
        $params += ['n' => $n];
        $translation = self::$messages[$one] ?? null;
        if (is_array($translation)) {
            return self::fill($translation[self::$locale === 'fr' ? ($n < 2 ? 0 : 1) : ($n === 1 ? 0 : 1)], $params);
        }
        return self::fill(self::$locale === 'fr' ? ($n < 2 ? $one : $many) : ($n === 1 ? $one : $many), $params);
    }

    /** Date in the current language: 'day' (Sep 24 / 24/09), 'full' (Sep 24, 2026 / 24/09/2026), 'weekday' (Thu Sep 24 / jeu. 24/09). */
    public static function date(\DateTimeInterface $date, string $format): string
    {
        $formats = self::DATE_FORMATS[self::$locale] ?? self::DATE_FORMATS[self::DEFAULT];
        if ($format === 'weekday') {
            return self::translate($date->format('D')) . ' ' . $date->format($formats['day']);
        }
        return $date->format($formats[$format]);
    }

    /** Translations for assets/lang.js ([] in English). */
    public static function jsMessages(): array
    {
        if (self::$jsMessages === null) {
            $file = APP_ROOT . '/src/lang/' . self::$locale . '-js.php';
            $messages = self::$locale !== self::DEFAULT && is_file($file) ? require $file : [];
            self::$jsMessages = is_array($messages) ? $messages : [];
        }
        return self::$jsMessages;
    }

    private static function fill(string $text, array $params): string
    {
        if ($params === []) {
            return $text;
        }
        $pairs = [];
        foreach ($params as $key => $value) {
            $pairs['{' . $key . '}'] = (string) $value;
        }
        return strtr($text, $pairs);
    }
}
