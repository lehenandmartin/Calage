<?php
declare(strict_types=1);

namespace Calage\Html;

/**
 * Replaces image paths in the original HTML, by position.
 * Everything that is not a replaced path comes out byte-for-byte identical.
 */
final class Rewriter
{
    /**
     * @param list<array{offset: int, length: int, raw: string}> $refs  output of ImageScanner::scan()
     * @param callable(array): ?string $replace  new value for a reference, or null to leave it as is
     */
    public static function rewrite(string $html, array $refs, callable $replace): string
    {
        usort($refs, fn(array $a, array $b): int => $b['offset'] <=> $a['offset']);

        $limit = PHP_INT_MAX; // start of the last replaced range: ranges must not overlap
        foreach ($refs as $ref) {
            $new = $replace($ref);
            if ($new === null || $new === $ref['raw']) {
                continue;
            }
            if ($ref['offset'] + $ref['length'] > $limit) {
                throw new \LogicException('Overlapping references at offset ' . $ref['offset']);
            }
            $html = substr_replace($html, $new, $ref['offset'], $ref['length']);
            $limit = $ref['offset'];
        }
        return $html;
    }

    /** Replaces each path found in $map (path as written → new value). */
    public static function rewriteMap(string $html, array $map): string
    {
        return self::rewrite($html, ImageScanner::scan($html), fn(array $ref): ?string => $map[$ref['raw']] ?? null);
    }
}
