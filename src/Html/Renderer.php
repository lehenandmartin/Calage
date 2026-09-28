<?php
declare(strict_types=1);

namespace Calage\Html;

use Calage\Storage\ImageStore;

/**
 * Produces the variants of a version on the fly, from the original HTML
 * and its path → image table (version_images).
 */
final class Renderer
{
    /**
     * HTML with the absolute URLs of the hosted images.
     * @param array<string, array{hash: string, ext: string}> $images
     */
    public static function absolute(string $html, array $images, string $baseUrl): string
    {
        $baseUrl = rtrim($baseUrl, '/');
        return Rewriter::rewrite($html, ImageScanner::scan($html), function (array $ref) use ($images, $baseUrl): ?string {
            $image = $images[$ref['raw']] ?? null;
            return $image === null ? null : $baseUrl . ImageStore::publicPath($image['hash'], $image['ext']);
        });
    }

    /**
     * Local paths found in the HTML but with no image attached (missing at import time,
     * or added by hand in the editor).
     * @param array<string, mixed> $images
     * @return list<string>
     */
    public static function unresolved(string $html, array $images): array
    {
        $missing = [];
        foreach (ImageScanner::scan($html) as $ref) {
            if (isset($images[$ref['raw']]) || isset($missing[$ref['raw']])) {
                continue;
            }
            if (PathResolver::classify($ref['raw'], $ref['entities']) === 'local') {
                $missing[$ref['raw']] = true;
            }
        }
        return array_keys($missing);
    }
}
