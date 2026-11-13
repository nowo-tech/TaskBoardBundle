<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Support;

use Normalizer;

use function class_exists;
use function mb_strtolower;
use function preg_replace;
use function preg_split;
use function str_starts_with;
use function strtr;
use function trim;

/**
 * Since 2.16.0 (spec 234, FR-025): case- and accent-insensitive text for a name lookup ("Jose" finds "José Álvarez"):
 * lower case, decomposed and stripped of combining marks (intl), or a fixed table of Latin letters without intl.
 * Stateless.
 */
final class TextFold
{
    private const LATIN = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e',
        'ë' => 'e', 'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ñ' => 'n', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o',
        'õ' => 'o', 'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y',
    ];

    public static function fold(string $text): string
    {
        $text = mb_strtolower(trim($text));
        if (class_exists(Normalizer::class)) {
            $decomposed = Normalizer::normalize($text, Normalizer::FORM_D);
            if ($decomposed !== false) {
                return (string) preg_replace('/\p{Mn}+/u', '', $decomposed);
            }
        }

        // Without intl only (the test images have it).
        return strtr($text, self::LATIN); // @codeCoverageIgnore
    }

    /**
     * Whether `$query` starts the name or one of its words, both folded. An empty query matches nothing.
     */
    public static function prefixMatches(string $query, string $name): bool
    {
        $query = self::fold($query);
        if ($query === '') {
            return false;
        }
        $name = self::fold($name);
        if (str_starts_with($name, $query)) {
            return true;
        }
        foreach (preg_split('/[\s\-_.,]+/u', $name) ?: [] as $word) {
            if ($word !== '' && str_starts_with($word, $query)) {
                return true;
            }
        }

        return false;
    }
}
