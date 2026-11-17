<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Text;

use function min;
use function preg_match;
use function preg_replace;
use function str_starts_with;
use function strcspn;
use function stripos;
use function strlen;
use function strpos;
use function strspn;
use function strtolower;
use function substr;

use const PREG_OFFSET_CAPTURE;

/**
 * Since 2.22.0 (UiKit 2.54 SECURITY, as ChatKit 1.33's `ChatMarkdownRenderer::stripAttributes()`): user HTML must never
 * carry UiKit's context-menu attributes (`data-nowo-ui-context-menu`, `-source`, `-scope`, `-label`, `-open`…: every name
 * with that prefix) or the card marker `data-nowo-ui-card`, or a description or comment could make its content open
 * another element's kebab items on right-click. TaskBoard prints user text through the host's
 * {@see TaskTextRendererInterface} (task descriptions, comment bodies, Markdown board descriptions), whose sanitizer may
 * keep `data-*`: {@see self::strip()} runs on what it returns.
 *
 * Tags are read the way the HTML tokenizer reads them: an attribute name ends at whitespace, `/`, `>` or `=`; a quoted
 * value at its closing quote (the next attribute may follow at once); an unquoted value, backticks included, at whitespace
 * or `>`. Then, failing closed, every occurrence of the reserved names left anywhere — inside a comment, a declaration or
 * a raw-text element the reader does not model, or as text — is renamed `data-removed-…`, so no element of the result can
 * carry them. Stateless.
 */
final class ReservedAttributeStripper
{
    /** Every attribute name with this prefix is removed. */
    public const RESERVED_ATTRIBUTE_PREFIX = 'data-nowo-ui-context-menu';

    /** The UiKit card marker, also removed. */
    public const RESERVED_CARD_ATTRIBUTE = 'data-nowo-ui-card';

    /** Any occurrence of a reserved name, renamed after the tag reader (fail closed). */
    private const RESERVED_NAME = '~data-nowo-ui-(context-menu|card)~i';

    /**
     * @param string $html HTML from the host's text renderer
     *
     * @return string the HTML without those attributes
     */
    public static function strip(string $html): string
    {
        if (stripos($html, 'data-nowo-ui-') === false) {
            return $html;
        }
        $out    = '';
        $length = strlen($html);
        $offset = 0;
        while (preg_match('/<[a-zA-Z]/', $html, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $start = $match[0][1];
            $out .= substr($html, $offset, $start - $offset);
            [$tag, $offset] = self::stripTag($html, $start, $length);
            $out .= $tag;
        }

        return (string) preg_replace(self::RESERVED_NAME, 'data-removed-$1', $out . substr($html, $offset));
    }

    /**
     * One start tag from `$start` (its `<`), without the reserved attributes, and the offset after it (its `>`, or the end
     * of the HTML for a tag left open).
     *
     * @return array{string, int}
     */
    private static function stripTag(string $html, int $start, int $length): array
    {
        $i   = $start + 1 + strcspn($html, " \t\n\f\r/>", $start + 1);
        $tag = substr($html, $start, $i - $start);
        while ($i < $length && $html[$i] !== '>') {
            // Separators before an attribute: whitespace and a "/" that does not close the tag.
            $gap = strspn($html, " \t\n\f\r/", $i);
            if ($gap > 0 && ($i + $gap >= $length || $html[$i + $gap] === '>')) {
                $tag .= substr($html, $i, $gap);
                $i += $gap;
                break;
            }
            $attribute = $i + $gap;
            // The name: a leading "=" belongs to it, then anything up to whitespace, "/", ">" or "=".
            $end  = $attribute + 1 + strcspn($html, " \t\n\f\r/>=", $attribute + 1);
            $name = strtolower(substr($html, $attribute, $end - $attribute));
            $end += strspn($html, " \t\n\f\r", $end);
            if ($end < $length && $html[$end] === '=') {
                $end += 1 + strspn($html, " \t\n\f\r", $end + 1);
                $quote = $end < $length ? $html[$end] : '';
                if ($quote === '"' || $quote === "'") {
                    $close = strpos($html, $quote, $end + 1);
                    $end   = $close === false ? $length : $close + 1;
                } else {
                    $end += strcspn($html, " \t\n\f\r>", $end);
                }
            } else {
                // No value: the whitespace after the name separates the next attribute.
                $end = $attribute + strlen($name);
            }
            $reserved = str_starts_with($name, self::RESERVED_ATTRIBUTE_PREFIX) || $name === self::RESERVED_CARD_ATTRIBUTE;
            // A dropped attribute takes its whitespace with it; a "/" separator stays.
            $tag .= $reserved ? (string) preg_replace('/[ \t\n\f\r]+$/', '', substr($html, $i, $gap)) : substr($html, $i, $end - $i);
            $i = $end;
        }

        return [$i < $length ? $tag . '>' : $tag, min($i + 1, $length)];
    }
}
