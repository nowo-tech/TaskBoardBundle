<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Content;

use Nowo\TaskBoardBundle\Text\TaskTextRendererInterface;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

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
use function strpos;
use function strspn;
use function strtolower;
use function substr;
use function trim;

use const ENT_HTML5;
use const ENT_QUOTES;
use const PREG_OFFSET_CAPTURE;

/**
 * Since 1.23: makes provider text safe to store as Markdown. The host's {@see TaskTextRendererInterface}
 * still escapes or sanitizes when it renders; this is the second line of defence:
 *
 * - raw HTML is removed outside code spans and fences (its text stays); code spans and fences are found as CommonMark
 *   finds them (a backtick run closed by a run of the same length, not escaped; fences at the start of a line);
 * - every link and image destination (`](…)`, whatever the label looks like, and reference definitions, the target
 *   on the next line included) keeps only https:, http: and mailto: — any other target is emptied (audit EG-L3);
 * - NUL, C0 controls other than tab and newline, and Unicode bidi overrides are removed;
 * - the text is cut to a byte budget at a UTF-8 boundary.
 */
final readonly class RemoteContentSanitizer
{
    private const BIDI_AND_CONTROLS = '/[\x00-\x08\x0B-\x1F\x7F\x{202A}-\x{202E}\x{2066}-\x{2069}]/u';

    private const ALLOWED_SCHEMES = ['http', 'https', 'mailto'];

    /**
     * What CommonMark reads as raw HTML (it wins over a code span that starts inside it): comments, processing
     * instructions, CDATA, declarations and tags — autolinks `<scheme:…>` look like tags and go too.
     */
    private const HTML = '<!--.*?(?:-->|$)|<\\?.*?(?:\\?>|$)|<!\\[CDATA\\[.*?(?:\\]\\]>|$)|<![A-Za-z][^>]*>|<\\/?[a-zA-Z][^>]*>';

    public function markdown(string $text, int $maxBytes): string
    {
        $text = $this->clean(str_replace(["\r\n", "\r"], "\n", $text));
        // Prose is cleaned; code spans and fences keep their text (CommonMark escapes HTML inside code).
        $out = [];
        foreach ($this->segments($text) as [$isCode, $part]) {
            $out[] = $isCode ? $part : $this->prose($part);
        }

        return $this->cut(trim(implode('', $out)), $maxBytes);
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
        $text = (string) preg_replace('/' . self::HTML . '/s', '', $text);
        // Inline link and image destinations: `](` then optional white space (new lines too) and the target, in
        // angle brackets or bare. The label is not parsed (nested brackets and new lines are legal): any unsafe target
        // is emptied.
        $text = (string) preg_replace_callback(
            '/\]\(([ \t\n]*)(<[^>\n]*>|[^\s)]*)/',
            fn (array $m): string => $m[2] === '' || $this->isSafeUrl(trim($m[2], '<>')) ? $m[0] : '](' . $m[1],
            $text,
        );

        // Reference definitions: [label]: target, the target possibly on the next line.
        $text = (string) preg_replace_callback(
            '/^( {0,3}\[(?:[^\]\\\\]|\\\\.)+\]:)([ \t]*\n?[ \t]*)(<[^>\n]*>|\S+)(.*)$/m',
            fn (array $m): string => $this->isSafeUrl(trim($m[3], '<>')) ? $m[0] : '',
            $text,
        );

        // A `<` left that could still open HTML (an unclosed tag or declaration) is escaped: CommonMark shows it.
        return (string) preg_replace('/<(?=[A-Za-z!?\/])/', '\\<', $text);
    }

    /**
     * The text cut into prose and code the way CommonMark finds code: fenced blocks (``` or ~~~, three or more, at the
     * start of a line, up to a closing fence of the same kind and at least the same length, or the end) and code spans
     * (a backtick run not preceded by a backslash, closed by the next run of exactly the same length on the same line;
     * without one the backticks are plain text). Raw HTML that starts first wins, as in CommonMark. Anything this
     * reads as prose while CommonMark reads code is only cleaned more than needed.
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
            if ($lineStart && preg_match('/\G {0,3}(`{3,}|~{3,})[^\n]*/', $text, $fence, 0, $i) === 1 && ($fence[1][0] === '~' || !str_contains(substr($fence[0], strlen($fence[1])), '`'))) {
                $close = preg_match('/^ {0,3}' . preg_quote($fence[1][0], '/') . '{' . strlen($fence[1]) . ',}[ \t]*$/m', $text, $match, PREG_OFFSET_CAPTURE, $i + strlen($fence[0])) === 1
                    ? $match[0][1] + strlen($match[0][0])
                    : $length;
                $segments[] = [false, $prose];
                $segments[] = [true, substr($text, $i, $close - $i)];
                $prose      = '';
                $i          = $close;
                continue;
            }
            if ($text[$i] === '`') {
                $run     = strspn($text, '`', $i);
                $lineEnd = strpos($text, "\n", $i);
                // A span closed on the same line only: one spread over lines (or paragraphs) stays prose.
                if (!$this->escaped($text, $i) && preg_match('/(?<!`)`{' . $run . '}(?!`)/', $text, $match, PREG_OFFSET_CAPTURE, $i + $run) === 1 && ($lineEnd === false || $match[0][1] < $lineEnd)) {
                    $end        = $match[0][1] + $run;
                    $segments[] = [false, $prose];
                    $segments[] = [true, substr($text, $i, $end - $i)];
                    $prose      = '';
                    $i          = $end;
                    continue;
                }
                // No closing run (or an escaped opener): the backticks are plain text.
                $prose .= substr($text, $i, $run);
                $i += $run;
                continue;
            }
            if ($text[$i] === '<') {
                // Raw HTML that starts before a backtick wins over the code span (CommonMark): it is prose, removed.
                $html = preg_match('/\\G(?:' . self::HTML . ')/s', $text, $match, 0, $i) === 1 ? $match[0] : '<';
                $prose .= $html;
                $i += strlen($html);
                continue;
            }
            $next = strcspn($text, "`\n<", $i);
            $prose .= substr($text, $i, $next + ($i + $next < $length && $text[$i + $next] === "\n" ? 1 : 0));
            $i += $next + ($i + $next < $length && $text[$i + $next] === "\n" ? 1 : 0);
        }
        $segments[] = [false, $prose];

        return $segments;
    }

    /** Whether the character at `$offset` is escaped (an odd number of backslashes before it). */
    private function escaped(string $text, int $offset): bool
    {
        $backslashes = 0;
        for ($k = $offset - 1; $k >= 0 && $text[$k] === '\\'; --$k) {
            ++$backslashes;
        }

        return $backslashes % 2 === 1;
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
