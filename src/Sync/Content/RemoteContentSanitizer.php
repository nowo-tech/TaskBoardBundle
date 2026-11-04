<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Content;

use Nowo\TaskBoardBundle\Text\TaskTextRendererInterface;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

use function addcslashes;
use function class_exists;
use function html_entity_decode;
use function implode;
use function in_array;
use function is_string;
use function mb_strcut;
use function preg_match;
use function preg_quote;
use function preg_replace;
use function preg_replace_callback;
use function rtrim;
use function str_contains;
use function str_replace;
use function strcspn;
use function strlen;
use function strtolower;
use function substr;
use function trim;

use const ENT_HTML5;
use const ENT_QUOTES;
use const PREG_OFFSET_CAPTURE;

/**
 * Since 1.23: makes provider text safe to store as Markdown. The host's {@see TaskTextRendererInterface}
 * still escapes or sanitizes when it renders; this is the second line of defence (audit EG-L3, delta):
 *
 * - fenced code blocks (``` or ~~~ at the very start of a line) keep their text; everything else, inline code spans
 *   included, is prose (a span's extent depends on the block structure, so it is never trusted to hide HTML);
 * - in prose every `<` is escaped (no raw HTML, no autolink: the text stays readable), every inline link or image
 *   target that is not http, https or mailto loses its link (`](` is escaped) whatever the label looks like, and every
 *   `]:` whose target is
 *   not one of those is escaped (no reference definition, also in a list item, a quote or after a code-span label);
 * - the result is sanitized again until it no longer changes (a change may move code spans); without a fixed point
 *   the text is escaped as plain text;
 * - NUL, C0 controls other than tab and newline, and Unicode bidi overrides are removed;
 * - the text is cut to a byte budget at a UTF-8 boundary.
 */
final readonly class RemoteContentSanitizer
{
    private const BIDI_AND_CONTROLS = '/[\x00-\x08\x0B-\x1F\x7F\x{202A}-\x{202E}\x{2066}-\x{2069}]/u';

    private const ALLOWED_SCHEMES = ['http', 'https', 'mailto'];

    /** Rounds of {@see self::markdown()} before the text is escaped as plain text. */
    public const MAX_ROUNDS = 8;

    /**
     * @param int<1, max> $maxRounds
     */
    public function __construct(private int $maxRounds = self::MAX_ROUNDS)
    {
    }

    public function markdown(string $text, int $maxBytes): string
    {
        $text     = $this->clean(str_replace(["\r\n", "\r"], "\n", $text));
        $original = $text;
        for ($round = 0; $round < $this->maxRounds; ++$round) {
            // Prose is neutralised; code spans and fences keep their text (CommonMark escapes HTML inside code).
            $out = [];
            foreach ($this->segments($text) as [$isCode, $part]) {
                $out[] = $isCode ? $part : $this->prose($part);
            }
            $next = $this->cut(trim(implode('', $out)), $maxBytes);
            if ($next === $text) {
                return $text;
            }
            $text = $next;
        }

        // No fixed point: every Markdown punctuation is escaped, the text is shown as it is.
        return $this->cut(addcslashes($original, '\\`*_[]()<>!#~:&|'), $maxBytes);
    }

    /**
     * HTML from a provider without Markdown: sanitized (symfony/html-sanitizer when installed), a few tags turned into
     * Markdown, every other tag removed, then {@see self::markdown()}.
     */
    public function html(string $html, int $maxBytes): string
    {
        if (class_exists(HtmlSanitizer::class)) {
            $html = (new HtmlSanitizer((new HtmlSanitizerConfig())
                ->allowElement('p')->allowElement('br')->allowElement('li')->allowElement('ul')->allowElement('ol')
                ->allowElement('strong')->allowElement('b')->allowElement('em')->allowElement('i')->allowElement('code')
                ->allowElement('a', ['href'])->allowLinkSchemes(self::ALLOWED_SCHEMES)))->sanitize($html);
        }
        $html = (string) preg_replace('#<a\s[^>]*href="([^"]*)"[^>]*>(.*?)</a>#is', '[$2]($1)', $html);
        $html = (string) preg_replace('#</?(strong|b)>#i', '**', $html);
        $html = (string) preg_replace('#</?(em|i)>#i', '*', $html);
        $html = (string) preg_replace('#<li[^>]*>#i', "\n- ", $html);
        $html = (string) preg_replace('#<br\s*/?>|</p>#i', "\n", $html);
        $html = (string) preg_replace('#<[^>]*>#', '', $html);

        return $this->markdown(html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $maxBytes);
    }

    /**
     * One line (titles, previews): controls removed, white space collapsed, cut to `$maxBytes`.
     */
    public function line(string $text, int $maxBytes): string
    {
        return $this->cut(trim((string) preg_replace('/\s+/u', ' ', $this->clean($text))), $maxBytes);
    }

    /** Whether a link target may be kept. */
    public function isSafeUrl(string $url): bool
    {
        $url = trim($url);
        if (preg_match('/^([a-z][a-z0-9+.-]*):/i', $url, $match) !== 1) {
            return false;
        }

        return in_array(strtolower($match[1]), self::ALLOWED_SCHEMES, true);
    }

    private function prose(string $text): string
    {
        // Inline link and image targets: `](`, optional white space (new lines too), then the target in angle brackets
        // or bare. The label is not parsed (nested brackets and new lines are legal): before an unsafe or empty target the
        // parenthesis is escaped, so neither a link nor an image remains (the text shows as it is).
        $text = (string) preg_replace_callback(
            '/\]\(([ \t\n]*)(<[^>\n]*>|[^\s)]*)/',
            fn (array $m): string => $this->isSafeUrl(trim($m[2], '<>')) ? $m[0] : ']\\(' . $m[1] . $m[2],
            $text,
        );
        // Reference definitions, wherever `]:` is (a list item, a quote, after a code-span label, the target on the
        // next line): unless the target is safe, the colon is escaped and no definition remains.
        $text = (string) preg_replace_callback(
            '/\]:([ \t]*\n?[ \t]*)(<[^>\n]*>|\S*)/',
            fn (array $m): string => $m[2] !== '' && $this->isSafeUrl(trim($m[2], '<>')) ? $m[0] : ']\\:' . $m[1] . $m[2],
            $text,
        );

        // Every `<` not escaped yet is escaped: no raw HTML, comment, declaration or autolink (the text stays).
        return (string) preg_replace('/(?<!\\\\)((?:\\\\\\\\)*)</', '$1\\\\<', $text);
    }

    /**
     * The fenced code blocks of the text (``` or ~~~, three or more, at the very start of a line, up to a closing fence
     * of the same kind and at least the same length indented by three spaces at most, or the end): CommonMark finds
     * exactly these, whatever comes before (a column-0 fence closes any list item or quote). Everything else is prose,
     * inline code spans too: how CommonMark pairs backtick runs depends on the block structure (spans cross lines),
     * so a span is never trusted to hide raw HTML (audit EG-L3, delta) — a `<` in it shows its backslash.
     *
     * @return list<array{0: bool, 1: string}>
     */
    private function segments(string $text): array
    {
        $segments = [];
        $prose    = '';
        $length   = strlen($text);
        for ($i = 0; $i < $length;) {
            $lineStart = $i === 0 || $text[$i - 1] === "\n";
            if ($lineStart && preg_match('/\G(`{3,}|~{3,})[^\n]*/', $text, $fence, 0, $i) === 1 && ($fence[1][0] === '~' || !str_contains(substr($fence[0], strlen($fence[1])), '`'))) {
                $close = preg_match('/^ {0,3}' . preg_quote($fence[1][0], '/') . '{' . strlen($fence[1]) . ',}[ \t]*$/m', $text, $match, PREG_OFFSET_CAPTURE, $i + strlen($fence[0])) === 1
                    ? $match[0][1] + strlen($match[0][0])
                    : $length;
                $segments[] = [false, $prose];
                $segments[] = [true, substr($text, $i, $close - $i)];
                $prose      = '';
                $i          = $close;
                continue;
            }
            $next = strcspn($text, "\n", $i);
            $step = $next + ($i + $next < $length && $text[$i + $next] === "\n" ? 1 : 0);
            $prose .= substr($text, $i, $step);
            $i += $step;
        }
        $segments[] = [false, $prose];

        return $segments;
    }

    private function clean(string $text): string
    {
        $clean = preg_replace(self::BIDI_AND_CONTROLS, '', $text);

        // Invalid UTF-8: drop the bytes preg cannot read.
        return is_string($clean) ? $clean : (string) preg_replace('/[^\x09\x0A\x20-\x7E]/', '', $text);
    }

    private function cut(string $text, int $maxBytes): string
    {
        if (strlen($text) <= $maxBytes) {
            return $text;
        }

        return rtrim(mb_strcut($text, 0, $maxBytes - 3, 'UTF-8')) . '…';
    }
}
