<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Search\Header;

use Nowo\TaskBoardBundle\Search\DoctrineTaskTextSearch;
use Nowo\TaskBoardBundle\Support\Like;

use function html_entity_decode;
use function mb_stripos;
use function mb_strlen;
use function mb_substr;
use function preg_replace;
use function strip_tags;
use function trim;

use const ENT_HTML5;
use const ENT_QUOTES;

/**
 * Since 2.25.0 (header search): the words of a query and the plain-text excerpt of a description. Words only reach
 * `LIKE … ESCAPE '!'` with `!`, `%` and `_` escaped: no operator of the user's text reaches the database.
 *
 * @internal
 */
final class SearchText
{
    /** Characters of an excerpt, ellipses excluded. */
    public const EXCERPT_LENGTH = 140;

    /**
     * The lower-case words of a query, de-duplicated, at most {@see DoctrineTaskTextSearch::MAX_WORDS}.
     *
     * @return list<string>
     */
    public static function words(string $text): array
    {
        return DoctrineTaskTextSearch::words($text);
    }

    /** The `LIKE` pattern of "contains `$word`" (escaped, for `ESCAPE '!'`). */
    public static function contains(string $word): string
    {
        return '%' . Like::literal($word) . '%';
    }

    /**
     * A plain-text cut of `$text` (HTML tags, entities and Markdown marks removed, white space folded) of at most
     * {@see self::EXCERPT_LENGTH} characters, around the first of `$words` it holds (else its start). Null when the text
     * is empty.
     *
     * @param list<string> $words
     */
    public static function excerpt(?string $text, array $words): ?string
    {
        $plain = self::plain($text ?? '');
        if ($plain === '') {
            return null;
        }
        $length = mb_strlen($plain);
        if ($length <= self::EXCERPT_LENGTH) {
            return $plain;
        }
        $at = 0;
        foreach ($words as $word) {
            $found = mb_stripos($plain, $word);
            if ($found !== false) {
                $at = $found;
                break;
            }
        }
        $start = max(0, min($at - 40, $length - self::EXCERPT_LENGTH));
        $cut   = trim(mb_substr($plain, $start, self::EXCERPT_LENGTH));

        return ($start > 0 ? '…' : '') . $cut . ($start + self::EXCERPT_LENGTH < $length ? '…' : '');
    }

    /** A title as one line: control and format characters and runs of white space become one space. */
    public static function line(string $text): string
    {
        $text = (string) preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** The text as one plain line. */
    private static function plain(string $text): string
    {
        // Audit VL-4: what a script or style element holds is never text.
        $text = (string) preg_replace('#<(script|style)\b[^>]*>.*?(?:</\1\s*>|$)#is', ' ', $text);
        // Block tags end a word: a line break between two paragraphs must not glue their words.
        $text = (string) preg_replace('/<(?:br|\/p|\/div|\/li|\/h[1-6])\b[^>]*>/i', ' ', $text);
        // Tags go before and after decoding, so an encoded tag (`&lt;b&gt;`) never comes back as markup.
        $text = strip_tags(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        // Markdown: images and links keep their text (a URL may hold parentheses), then emphasis, headings, quotes, code
        // and list marks go.
        $text = (string) preg_replace('/!?\[([^\]]*)\]\((?:[^()\s]|\([^()]*\))*(?:\s+"[^"]*")?\)/u', '$1', $text);
        $text = (string) preg_replace('/[*_~`#>|]+/u', ' ', $text);
        $text = (string) preg_replace('/(^|\s)[-+]\s+/u', '$1', $text);

        return self::line($text);
    }
}
