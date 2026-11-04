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
use function preg_replace;
use function preg_replace_callback;
use function preg_split;
use function rtrim;
use function str_replace;
use function strlen;
use function strtolower;
use function trim;

use const ENT_HTML5;
use const ENT_QUOTES;
use const PREG_SPLIT_DELIM_CAPTURE;

/**
 * Since 1.23: makes provider text safe to store as Markdown. The host's {@see TaskTextRendererInterface}
 * still escapes or sanitizes when it renders; this is the second line of defence:
 *
 * - raw HTML is removed outside code spans and fences (its text stays);
 * - link and image targets keep only https:, http: and mailto: (others, protocol-relative ones included, are dropped
 *   and their label kept);
 * - NUL, C0 controls other than tab and newline, and Unicode bidi overrides are removed;
 * - the text is cut to a byte budget at a UTF-8 boundary.
 */
final readonly class RemoteContentSanitizer
{
    private const BIDI_AND_CONTROLS = '/[\x00-\x08\x0B-\x1F\x7F\x{202A}-\x{202E}\x{2066}-\x{2069}]/u';

    private const ALLOWED_SCHEMES = ['http', 'https', 'mailto'];

    public function markdown(string $text, int $maxBytes): string
    {
        $text = $this->clean(str_replace(["\r\n", "\r"], "\n", $text));
        $out  = [];
        // Odd parts are code (fences, then inline code): kept as they are.
        foreach ((array) preg_split('/(```.*?(?:```|$)|`[^`\n]*`)/s', $text, -1, PREG_SPLIT_DELIM_CAPTURE) as $index => $part) {
            $part  = (string) $part;
            $out[] = $index % 2 === 1 ? $part : $this->prose($part);
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
        // Comments and tags (HTML in Markdown); autolinks <scheme:…> look like tags and go too.
        $text = (string) preg_replace('/<!--.*?(?:-->|$)/s', '', $text);
        $text = (string) preg_replace('#</?[a-zA-Z][^>]*>#', '', $text);
        // [label](target "title") and ![alt](target): unsafe targets lose the link.
        $text = (string) preg_replace_callback(
            '/(!?)\[([^\]\n]*)\]\(\s*<?([^)\s>]*)>?(?:\s+"[^"]*")?\s*\)/',
            fn (array $m): string => $this->isSafeUrl($m[3]) ? $m[0] : $m[2],
            $text,
        );
        // Reference definitions: [id]: target
        $text = (string) preg_replace_callback(
            '/^( {0,3}\[[^\]\n]+\]:)[ \t]*(\S+)(.*)$/m',
            fn (array $m): string => $this->isSafeUrl($m[2]) ? $m[0] : '',
            $text,
        );

        return $text;
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
