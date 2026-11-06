<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Provider\ClickUp;

use Nowo\TaskBoardBundle\Sync\Content\RemoteContentSanitizer;

use function array_pop;
use function array_values;
use function count;
use function ctype_xdigit;
use function explode;
use function hexdec;
use function implode;
use function in_array;
use function is_array;
use function is_numeric;
use function is_string;
use function max;
use function mb_chr;
use function min;
use function preg_match;
use function preg_match_all;
use function rtrim;
use function sprintf;
use function str_contains;
use function str_repeat;
use function str_replace;
use function strlen;
use function strpos;
use function strtolower;
use function strtr;
use function substr;
use function trim;

/**
 * Since 1.28.0 (TB-SYNC-4): a ClickUp comment (a list of `{text, attributes, type}` segments) as GitHub-flavoured
 * Markdown. ClickUp documents a Quill delta: `list`, `indent` and `code-block` sit on the segment holding the line feed
 * that ends the line, as `{"list": {"list": "bullet"}}` and `{"code-block": {"code-block": "plain"}}`. Live data was not
 * inspected for this, so both the nested and the flat value are read, and a block attribute on the text of a line counts
 * as well as one on its line feed.
 *
 * `underline`, `color` and `background-color` have no Markdown form and the host's renderer escapes raw HTML, so the
 * text stays and the style does not. Every link goes through {@see RemoteContentSanitizer::isSafeUrl()}; a refused
 * target leaves the label as text. The result still passes through {@see RemoteContentSanitizer::markdown()}.
 */
final readonly class ClickUpCommentRenderer
{
    /** The deepest `indent` read (a remote value: it only ever orders the levels, never the column of a line). */
    private const MAX_INDENT = 16;

    private const LIST_BULLET = 'bullet';

    private const LIST_ORDERED = 'ordered';

    private const LIST_CHECKED = 'checked';

    private const LIST_UNCHECKED = 'unchecked';

    private const LIST_NONE = 'none';

    private const KNOWN_LISTS = [self::LIST_BULLET, self::LIST_ORDERED, self::LIST_CHECKED, self::LIST_UNCHECKED, self::LIST_NONE, 'toggled'];

    /** The address of a task mentioned without one of its own (ids are validated first). */
    private const TASK_URL = 'https://app.clickup.com/t/%s';

    public function __construct(private RemoteContentSanitizer $sanitizer = new RemoteContentSanitizer())
    {
    }

    /**
     * @param array<mixed> $segments
     */
    public function render(array $segments): string
    {
        return $this->blocks($this->lines($segments));
    }

    /**
     * The segments as lines, each with its inline Markdown, its raw text (for code blocks) and its block attributes.
     *
     * @param array<mixed> $segments
     *
     * @return list<array{inline: string, raw: string, list: string|null, indent: int, code: string|null, quote: bool, header: int}>
     */
    private function lines(array $segments): array
    {
        $lines   = [];
        $current = $this->emptyLine();
        foreach ($segments as $segment) {
            if (!is_array($segment)) {
                continue;
            }
            $attributes = is_array($segment['attributes'] ?? null) ? $segment['attributes'] : [];
            $special    = $this->special($segment, $attributes);
            if ($special !== null) {
                $current['inline'] .= $special[0];
                $current['raw'] .= $special[1];
                $current = $this->withBlock($current, $attributes);
                continue;
            }
            $parts = explode("\n", is_string($segment['text'] ?? null) ? $segment['text'] : '');
            foreach ($parts as $index => $part) {
                if ($index > 0) {
                    // The line feed ends the line: its attributes belong to the line it ends.
                    $lines[] = $this->withBlock($current, $attributes);
                    $current = $this->emptyLine();
                }
                $current['inline'] .= $this->styled($part, $attributes);
                $current['raw'] .= $part;
            }
            if ($parts[count($parts) - 1] !== '') {
                // The other reading of the delta: the attribute sits on the text of the line itself.
                $current = $this->withBlock($current, $attributes);
            }
        }
        $lines[] = $current;

        return $lines;
    }

    /**
     * @return array{inline: string, raw: string, list: string|null, indent: int, code: string|null, quote: bool, header: int}
     */
    private function emptyLine(): array
    {
        return ['inline' => '', 'raw' => '', 'list' => null, 'indent' => 0, 'code' => null, 'quote' => false, 'header' => 0];
    }

    /**
     * @param array{inline: string, raw: string, list: string|null, indent: int, code: string|null, quote: bool, header: int} $line
     * @param array<mixed> $attributes
     *
     * @return array{inline: string, raw: string, list: string|null, indent: int, code: string|null, quote: bool, header: int}
     */
    private function withBlock(array $line, array $attributes): array
    {
        $line['list'] = $this->listKind($attributes['list'] ?? null) ?? $line['list'];
        if (is_numeric($attributes['indent'] ?? null)) {
            $line['indent'] = max(0, min(self::MAX_INDENT, (int) $attributes['indent']));
        }
        $line['code'] = $this->codeLanguage($attributes['code-block'] ?? null) ?? $line['code'];
        if (($attributes['blockquote'] ?? false) === true) {
            $line['quote'] = true;
        }
        $header = is_array($attributes['header'] ?? null) ? ($attributes['header']['header'] ?? null) : ($attributes['header'] ?? null);
        if (is_numeric($header)) {
            $line['header'] = max(0, min(6, (int) $header));
        }

        return $line;
    }

    private function listKind(mixed $value): ?string
    {
        $kind = is_array($value) ? ($value['list'] ?? null) : $value;
        if (!is_string($kind) || $kind === '') {
            return null;
        }
        $kind = strtolower($kind);

        return in_array($kind, self::KNOWN_LISTS, true) ? $kind : self::LIST_BULLET;
    }

    /** The info string of a code block ('' for plain); null when the line is not one. */
    private function codeLanguage(mixed $value): ?string
    {
        $value = is_array($value) ? ($value['code-block'] ?? true) : $value;
        if ($value === true) {
            return '';
        }
        if (!is_string($value) || $value === '') {
            return null;
        }

        return $value !== 'plain' && preg_match('/^[A-Za-z0-9+#-]{1,20}$/D', $value) === 1 ? strtolower($value) : '';
    }

    /**
     * Segments that are not plain text: [inline Markdown, raw text]; null for plain text.
     *
     * @param array<mixed> $segment
     * @param array<mixed> $attributes
     *
     * @return array{0: string, 1: string}|null
     */
    private function special(array $segment, array $attributes): ?array
    {
        $type = $segment['type'] ?? null;
        if (!is_string($type) || $type === '') {
            return null;
        }

        return match ($type) {
            // Copied as a file of the comment (ClickUpTaskProvider::commentAttachments()).
            'attachment'   => ['', ''],
            'image'        => is_array($segment['image'] ?? null) ? [$this->image($segment['image']), ''] : null,
            'tag'          => is_array($segment['user'] ?? null) ? $this->tag($segment) : null,
            'emoticon'     => $this->emoticon($segment),
            'task_mention' => $this->taskMention($segment, $attributes),
            default        => is_array($segment[$type] ?? null) ? $this->linkMention($segment, $type, $attributes) : null,
        };
    }

    /**
     * @param array<mixed> $image
     */
    private function image(array $image): string
    {
        $url = $this->safeUrl($this->text($image['url'] ?? null));

        return $url === null ? '' : sprintf('![%s](%s)', $this->label($this->text($image['name'] ?? 'image')), $url);
    }

    /**
     * @param array<mixed> $segment
     *
     * @return array{0: string, 1: string}
     */
    private function tag(array $segment): array
    {
        $user = $segment['user'];
        $name = is_array($user) ? $this->text($user['username'] ?? null) : '';
        $name = $name !== '' ? '@' . $name : $this->text($segment['text'] ?? null);

        return [$name, $name];
    }

    /**
     * `{text, type: emoticon, emoticon: {code: "1f60a"}}`: the code is a hex code point, or several joined by `-`.
     *
     * @param array<mixed> $segment
     *
     * @return array{0: string, 1: string}
     */
    private function emoticon(array $segment): array
    {
        $data = is_array($segment['emoticon'] ?? null) ? $segment['emoticon'] : [];
        $out  = '';
        foreach (explode('-', $this->text($data['code'] ?? null)) as $code) {
            $char = ctype_xdigit($code) && strlen($code) <= 6 && hexdec($code) <= 0x10FFFF ? mb_chr((int) hexdec($code), 'UTF-8') : false;
            if ($char === false) {
                $out = $this->text($segment['text'] ?? null);
                break;
            }
            $out .= $char;
        }

        return [$out, $out];
    }

    /**
     * `link_mention` and other typed segments that carry a target. The shape is not documented by ClickUp: what is
     * read here is the segment's own object (`url`, `link`, `href`; `title`, `name`), the segment's `url` and `link`
     * attribute, then the text itself. With neither a label nor a target, null: treated as plain text.
     *
     * @param array<mixed> $segment
     * @param array<mixed> $attributes
     *
     * @return array{0: string, 1: string}|null
     */
    private function linkMention(array $segment, string $type, array $attributes): ?array
    {
        $data = is_array($segment[$type] ?? null) ? $segment[$type] : [];
        $text = $this->text($segment['text'] ?? null);
        $url  = $this->first([$data['url'] ?? null, $data['link'] ?? null, $data['href'] ?? null, $segment['url'] ?? null, $attributes['link'] ?? null, $this->safeUrl($text) !== null ? $text : null]);
        if ($url === '' && $text === '') {
            return null;
        }
        $label = $this->first([$text, $data['title'] ?? null, $data['name'] ?? null, $url]);

        return [$this->styled($this->sanitizer->line($label, 512), ['link' => $url] + $attributes), $this->sanitizer->line($label, 512)];
    }

    /**
     * `task_mention`: the shape is not documented by ClickUp. The label is the segment's text or the task's name, the
     * target the segment's own address or the task's page when a valid id is present.
     *
     * @param array<mixed> $segment
     * @param array<mixed> $attributes
     *
     * @return array{0: string, 1: string}
     */
    private function taskMention(array $segment, array $attributes): array
    {
        $data = is_array($segment['task_mention'] ?? null) ? $segment['task_mention'] : (is_array($segment['task'] ?? null) ? $segment['task'] : []);
        $id   = $this->first([$data['task_id'] ?? null, $data['id'] ?? null, $segment['task_id'] ?? null]);
        $id   = preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $id) === 1 ? $id : '';
        $url  = $this->first([$data['url'] ?? null, $segment['url'] ?? null, $attributes['link'] ?? null, $id !== '' ? sprintf(self::TASK_URL, $id) : null]);
        $name = $this->sanitizer->line($this->first([$segment['text'] ?? null, $data['name'] ?? null, $data['title'] ?? null, $data['task_name'] ?? null, $id]), 512);

        return [$this->styled($name, ['link' => $url] + $attributes), $name];
    }

    /**
     * @param array<mixed> $attributes
     */
    private function styled(string $text, array $attributes): string
    {
        if (trim($text) === '') {
            return $text;
        }
        $core  = trim($text);
        $start = (int) strpos($text, $core);
        $lead  = substr($text, 0, $start);
        $trail = substr($text, $start + strlen($core));
        if (($attributes['code'] ?? false) === true) {
            $core = $this->codeSpan($core);
        }
        if (($attributes['bold'] ?? false) === true) {
            $core = '**' . $core . '**';
        }
        if (($attributes['italic'] ?? false) === true) {
            $core = '*' . $core . '*';
        }
        if (($attributes['strike'] ?? $attributes['strikethrough'] ?? false) === true) {
            $core = '~~' . $core . '~~';
        }
        $link = $attributes['link'] ?? null;
        $url  = is_string($link) ? $this->safeUrl($link) : null;
        if ($url !== null) {
            $core = sprintf('[%s](%s)', $this->label($core), $url);
        }

        return $lead . $core . $trail;
    }

    private function codeSpan(string $text): string
    {
        $ticks = str_repeat('`', $this->longestTickRun($text) + 1);

        return str_contains($text, '`') ? $ticks . ' ' . $text . ' ' . $ticks : $ticks . $text . $ticks;
    }

    private function longestTickRun(string $text): int
    {
        preg_match_all('/`+/', $text, $runs);
        $longest = 0;
        foreach ($runs[0] as $run) {
            $longest = max($longest, strlen($run));
        }

        return $longest;
    }

    /** A label inside `[...]`: its brackets stay text. */
    private function label(string $text): string
    {
        return strtr($text, ['[' => '\\[', ']' => '\\]']);
    }

    /**
     * The target of a link, or null when the scheme is refused or it holds a control character. White space and the
     * characters that would end the target are percent-encoded, so nothing leaks out of the parentheses.
     */
    private function safeUrl(string $url): ?string
    {
        $url = trim($url);
        if (preg_match('/[\x00-\x1F\x7F]/', $url) === 1 || !$this->sanitizer->isSafeUrl($url)) {
            return null;
        }

        return strtr($url, [' ' => '%20', '(' => '%28', ')' => '%29', '<' => '%3C', '>' => '%3E', '"' => '%22']);
    }

    /**
     * The lines as Markdown: consecutive code lines form a fence, list items nest by their `indent` (columns follow the
     * parents' markers, so a jump in `indent` never becomes an indented code block), and block changes get a blank line.
     * Lines with no block attribute join with a line feed, as they always did.
     *
     * @param list<array{inline: string, raw: string, list: string|null, indent: int, code: string|null, quote: bool, header: int}> $lines
     */
    private function blocks(array $lines): string
    {
        $out      = [];
        $previous = 'plain';
        /** @var list<array{indent: int, kind: string, count: int, column: int, width: int}> $stack */
        $stack = [];
        for ($i = 0, $total = count($lines); $i < $total; ++$i) {
            $line = $lines[$i];
            if ($line['code'] !== null) {
                $raw = [];
                $end = $i;
                while ($end < $total && $lines[$end]['code'] === $line['code']) {
                    $raw[] = rtrim($lines[$end]['raw'], "\r");
                    ++$end;
                }
                $i     = $end - 1;
                $stack = [];
                $this->separate($out, $previous, 'code');
                $fence = str_repeat('`', max(3, $this->longestTickRun(implode("\n", $raw)) + 1));
                $out[] = $fence . $line['code'];
                foreach ($raw as $text) {
                    $out[] = $text;
                }
                $out[]    = $fence;
                $previous = 'code';
                continue;
            }
            $kind = $line['list'];
            if ($kind !== null && $kind !== self::LIST_NONE) {
                $this->separate($out, $previous, 'list');
                $out[]    = $this->listItem($line, $kind, $stack);
                $previous = 'list';
                continue;
            }
            if ($line['inline'] === '' && $stack !== []) {
                // A blank line inside a list keeps the list going (and its numbers).
                $out[] = '';
                continue;
            }
            if (($kind === self::LIST_NONE || $line['indent'] > 0) && $stack !== []) {
                // An indented paragraph under a list item.
                $top      = $stack[count($stack) - 1];
                $out[]    = str_repeat(' ', $top['column'] + $top['width']) . $line['inline'];
                $previous = 'list';
                continue;
            }
            $stack = [];
            if ($line['quote']) {
                $this->separate($out, $previous, 'quote');
                $out[]    = '> ' . $line['inline'];
                $previous = 'quote';
                continue;
            }
            if ($line['header'] > 0) {
                $this->separate($out, $previous, 'header');
                $out[]    = str_repeat('#', $line['header']) . ' ' . $line['inline'];
                $previous = 'header';
                continue;
            }
            $this->separate($out, $previous, 'plain');
            $out[]    = $line['inline'];
            $previous = 'plain';
        }

        return implode("\n", array_values($out));
    }

    /**
     * @param list<string> $out
     */
    private function separate(array &$out, string $previous, string $next): void
    {
        if ($previous !== $next && $out !== [] && $out[count($out) - 1] !== '') {
            $out[] = '';
        }
    }

    /**
     * One list line; `$stack` holds the open levels, by the raw `indent` that opened them.
     *
     * @param array{inline: string, raw: string, list: string|null, indent: int, code: string|null, quote: bool, header: int} $line
     * @param list<array{indent: int, kind: string, count: int, column: int, width: int}> $stack
     */
    private function listItem(array $line, string $kind, array &$stack): string
    {
        while ($stack !== [] && $stack[count($stack) - 1]['indent'] > $line['indent']) {
            array_pop($stack);
        }
        $top = $stack === [] ? null : $stack[count($stack) - 1];
        if ($top === null || $top['indent'] < $line['indent']) {
            $column  = $top === null ? 0 : $top['column'] + $top['width'];
            $stack[] = ['indent' => $line['indent'], 'kind' => $kind, 'count' => 0, 'column' => $column, 'width' => 2];
        }
        $level = count($stack) - 1;
        if ($stack[$level]['kind'] !== $kind) {
            $stack[$level]['kind']  = $kind;
            $stack[$level]['count'] = 0;
        }
        ++$stack[$level]['count'];
        $marker = match ($kind) {
            self::LIST_ORDERED   => $stack[$level]['count'] . '. ',
            self::LIST_CHECKED   => '- [x] ',
            self::LIST_UNCHECKED => '- [ ] ',
            default              => '- ',
        };
        $stack[$level]['width'] = $kind === self::LIST_ORDERED ? strlen($marker) : 2;

        return str_repeat(' ', $stack[$level]['column']) . rtrim($marker . trim($line['inline']));
    }

    /**
     * The first value that is a non-empty string or number; '' when none is.
     *
     * @param list<mixed> $values
     */
    private function first(array $values): string
    {
        foreach ($values as $value) {
            $text = $this->text($value);
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    private function text(mixed $value): string
    {
        return is_string($value) || is_numeric($value) ? str_replace(["\r", "\n"], ' ', trim((string) $value)) : '';
    }
}
