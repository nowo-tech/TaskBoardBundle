<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\ClientApi\Text;

use Nowo\TaskBoardBundle\ClientApi\Contract\TaskClientFileResolverInterface;
use Nowo\TaskBoardBundle\ClientApi\Contract\TaskClientReferenceResolverInterface;
use Nowo\TaskBoardBundle\ClientApi\Model\TaskClientReference;

use function array_merge;
use function array_values;
use function count;
use function explode;
use function in_array;
use function min;
use function preg_match;
use function preg_replace;
use function preg_replace_callback;
use function preg_split;
use function rtrim;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function stripos;
use function strlen;
use function strtolower;
use function substr;
use function trim;

use const PREG_OFFSET_CAPTURE;
use const PREG_SPLIT_DELIM_CAPTURE;

/**
 * Since 3.4.0 (client API v1, §5.2 of the Beacon Desktop tasks design, Q-BD-25): a task's Markdown (description,
 * comment, question context) as structured tokens — the `tokens/1` format ChatKit's client API uses for message bodies,
 * so a client renders every string as a text node and no HTML ever reaches it. TaskBoard's own converter over its
 * CommonMark subset:
 *
 * - blocks: `paragraph`, `heading` (`level` 1–3; deeper headings are 3), `quote`, `list` (`ordered`, one token list per
 *   item; a task list `- [ ]` / `- [x]` adds `checked`, a list of booleans, read-only), `code` (a fenced block, as text)
 *   and `rule`;
 * - inline tokens: `text` (with `marks`: `bold`, `italic`, `strike`), `break`, `code`, `link` (`http(s)` and `mailto`
 *   only, else text), `reference` and `image`.
 *
 * A `#[title](type:id)` reference is resolved **for the reader** through the host's
 * {@see TaskClientReferenceResolverInterface}: `{visible: true, refType, id, title, url}` with the host's title, else
 * `{visible: false, text: "Content not visible"}` (no type, id or title; the title written in the text is never shown).
 * An image whose URL is `<file_scheme>:<id>` and that the host's {@see TaskClientFileResolverInterface} lets the reader
 * open is `{visible: true, fileId, alt}`; any other image is `{visible: false}` (no id, no alt). Code never resolves a
 * reference. Mentions stay text (TaskBoard keeps a comment's mentioned users as ids beside the body). Stateless: the
 * references of one call are resolved once each and nothing is kept.
 */
final readonly class TaskTextTokens
{
    /** Format id of a token body (`body.format`). */
    public const FORMAT = 'tokens/1';

    /** The text of a reference the reader may not see. */
    public const NOT_VISIBLE = 'Content not visible';

    /** `#[title](type:id)`, ChatKit's reference syntax. */
    public const REFERENCE = '/#\[([^\[\]\n]{1,120})\]\(([a-z][a-z0-9_-]{0,31}):([A-Za-z0-9_-]{1,64})\)/u';

    /** Longest text converted (bytes); the rest is cut. */
    public const MAX_BYTES = 65536;

    /** A placeholder in a line: kind (Code, Reference, Image, Link) and index. */
    private const PLACEHOLDER = '/\x{E000}([CRIL])(\d+)\x{E000}/u';

    private const EMPHASIS = [
        'bold'   => '/\*\*(?=\S)(.+?)(?<=\S)\*\*/u',
        'strike' => '/~~(?=\S)(.+?)(?<=\S)~~/u',
        'italic' => '/(?<![\w*])\*(?=\S)(.+?)(?<=\S)\*(?![\w*])/u',
        'em'     => '/(?<![\w_])_(?=\S)(.+?)(?<=\S)_(?![\w_])/u',
    ];

    /**
     * @param string|null $fileScheme the URL scheme of the host's files in Markdown images (`client_api.file_scheme`,
     *                                Beacon `beacon-file`); null: every image is not visible
     */
    public function __construct(
        private ?TaskClientReferenceResolverInterface $references = null,
        private ?TaskClientFileResolverInterface $files = null,
        private ?string $fileScheme = null,
    ) {
    }

    /**
     * The text as `$reader` may see it.
     *
     * @return array{format: string, blocks: list<array<string, mixed>>}
     */
    public function of(?string $markdown, object $reader): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim(substr((string) $markdown, 0, self::MAX_BYTES)));
        if ($text === '') {
            return ['format' => self::FORMAT, 'blocks' => []];
        }
        $memo = new TaskTextMemo();

        return ['format' => self::FORMAT, 'blocks' => $this->blocks($text, $reader, $memo)];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function blocks(string $text, object $reader, TaskTextMemo $memo): array
    {
        $fences = [];
        $text   = (string) preg_replace_callback('/^```[^\n`]*\n(.*?)\n?```[ \t]*$/ms', static function (array $m) use (&$fences): string {
            $fences[] = rtrim($m[1], "\n");

            return "\u{E000}B" . (count($fences) - 1) . "\u{E000}";
        }, $text);

        $blocks = [];
        /** @var list<string> $paragraph */
        $paragraph = [];
        /** @var 'ol'|'ul'|null $list */
        $list = null;
        /** @var list<string> $items */
        $items = [];
        /** @var list<bool|null> $checked */
        $checked = [];
        /** @var list<string> $quote */
        $quote = [];
        $flush = function (string $which) use (&$paragraph, &$list, &$items, &$checked, &$quote, &$blocks, $reader, $memo): void {
            if ($which === 'quote' && $quote !== []) {
                $blocks[] = ['type' => 'quote', 'tokens' => $this->lines($quote, $reader, $memo)];
                $quote    = [];
            } elseif ($which === 'paragraph' && $paragraph !== []) {
                $blocks[]  = ['type' => 'paragraph', 'tokens' => $this->lines($paragraph, $reader, $memo)];
                $paragraph = [];
            } elseif ($which === 'list' && $list !== null) {
                $rows = [];
                foreach ($items as $item) {
                    $rows[] = $this->inline($item, $reader, $memo);
                }
                $block = ['type' => 'list', 'ordered' => $list === 'ol', 'items' => $rows];
                if (in_array(true, $checked, true) || in_array(false, $checked, true)) {
                    $block['checked'] = array_values(array_map(static fn (?bool $value): bool => $value === true, $checked));
                }
                $blocks[] = $block;
                $list     = null;
                $items    = [];
                $checked  = [];
            }
        };

        foreach (explode("\n", $text) as $line) {
            if (preg_match('/^\s*>\s?(.*)$/', $line, $q) === 1) {
                $flush('paragraph');
                $flush('list');
                $quote[] = $q[1];
                continue;
            }
            $flush('quote');
            if (preg_match('/^\x{E000}B(\d+)\x{E000}$/u', trim($line), $m) === 1) {
                $flush('paragraph');
                $flush('list');
                $blocks[] = ['type' => 'code', 'text' => $fences[(int) $m[1]] ?? ''];
                continue;
            }
            if (preg_match('/^ {0,3}(#{1,6})\s+(.+?)\s*#*\s*$/', $line, $h) === 1) {
                $flush('paragraph');
                $flush('list');
                $blocks[] = ['type' => 'heading', 'level' => min(3, strlen($h[1])), 'tokens' => $this->inline($h[2], $reader, $memo)];
                continue;
            }
            if (preg_match('/^ {0,3}([-*_])(?:\s*\1){2,}\s*$/', $line) === 1) {
                $flush('paragraph');
                $flush('list');
                $blocks[] = ['type' => 'rule'];
                continue;
            }
            if (preg_match('/^\s*[-*+]\s+(.+)$/', $line, $m) === 1 || preg_match('/^\s*\d+[.)]\s+(.+)$/', $line, $n) === 1) {
                $kind = isset($m[1]) ? 'ul' : 'ol';
                $item = $m[1] ?? $n[1];
                $flush('paragraph');
                if ($list !== $kind) {
                    $flush('list');
                    $list = $kind;
                }
                $state = null;
                if (preg_match('/^\[([ xX])\]\s+(.*)$/', $item, $task) === 1) {
                    $state = $task[1] !== ' ';
                    $item  = $task[2];
                }
                $items[]   = $item;
                $checked[] = $state;
                $m         = [];
                continue;
            }
            $flush('list');
            if (trim($line) === '') {
                $flush('paragraph');
                continue;
            }
            $paragraph[] = $line;
        }
        $flush('quote');
        $flush('paragraph');
        $flush('list');

        // A fence is always a block of its own (the pattern takes whole lines).
        return $blocks;
    }

    /**
     * @param list<string> $lines
     *
     * @return list<array<string, mixed>>
     */
    private function lines(array $lines, object $reader, TaskTextMemo $memo): array
    {
        $tokens = [];
        foreach ($lines as $index => $line) {
            if ($index > 0) {
                $tokens[] = ['type' => 'break'];
            }
            $tokens = array_merge($tokens, $this->inline($line, $reader, $memo));
        }

        return $tokens;
    }

    /**
     * The inline tokens of one line: code spans, images, references, Markdown links, bare links, then emphasis.
     *
     * @return list<array<string, mixed>>
     */
    private function inline(string $text, object $reader, TaskTextMemo $memo): array
    {
        $codes = [];
        $text  = (string) preg_replace_callback('/`([^`\n]+)`/', static function (array $m) use (&$codes): string {
            $codes[] = ['type' => 'code', 'text' => $m[1]];

            return "\u{E000}C" . (count($codes) - 1) . "\u{E000}";
        }, $text);

        $images = [];
        $text   = (string) preg_replace_callback('/!\[([^\]\n]{0,200})\]\(([^\s)]{1,2048})\)/u', function (array $m) use (&$images, $reader, $memo): string {
            $images[] = $this->image($m[1], $m[2], $reader, $memo);

            return "\u{E000}I" . (count($images) - 1) . "\u{E000}";
        }, $text);

        $references = [];
        $text       = (string) preg_replace_callback(self::REFERENCE, function (array $m) use (&$references, $reader, $memo): string {
            $references[] = $this->reference($m[2], $m[3], $reader, $memo);

            return "\u{E000}R" . (count($references) - 1) . "\u{E000}";
        }, $text);

        $placeholders = ['C' => $codes, 'R' => $references, 'I' => $images];
        $links        = [];
        $text         = (string) preg_replace_callback('~\[([^\]\n]{1,200})\]\(((?:https?://|mailto:)[^\s<>"\'`)\x{E000}]+)\)~iu', static function (array $m) use (&$links, $placeholders): string {
            $links[] = ['type' => 'link', 'url' => $m[2], 'text' => self::plainOf($m[1], $placeholders)];

            return "\u{E000}L" . (count($links) - 1) . "\u{E000}";
        }, $text);
        $text = (string) preg_replace_callback('~\b(?:https?://|www\.)[^\s<>"\'`\x{E000}]+|\b(?:mailto:)?[\w.+-]+@[\w-]+(?:\.[\w-]+)+\b~iu', static function (array $m) use (&$links): string {
            $raw  = rtrim($m[0], '.,;:!?)');
            $tail = substr($m[0], strlen($raw));
            $href = match (true) {
                str_contains($raw, '@') && preg_match('~^https?://~i', $raw) !== 1 => str_starts_with(strtolower($raw), 'mailto:') ? $raw : 'mailto:' . $raw,
                stripos($raw, 'www.') === 0                                        => 'https://' . $raw,
                default                                                            => $raw,
            };
            $links[] = ['type' => 'link', 'url' => $href, 'text' => (string) preg_replace('~^mailto:~i', '', $raw)];

            return "\u{E000}L" . (count($links) - 1) . "\u{E000}" . $tail;
        }, $text);
        $placeholders['L'] = $links;

        return $this->merge($this->styled($text, [], $placeholders));
    }

    /**
     * @param list<string> $marks
     * @param array<string, list<array<string, mixed>>> $placeholders
     *
     * @return list<array<string, mixed>>
     */
    private function styled(string $text, array $marks, array $placeholders): array
    {
        $best = null;
        foreach (self::EMPHASIS as $mark => $pattern) {
            if (preg_match($pattern, $text, $match, PREG_OFFSET_CAPTURE) === 1 && ($best === null || $match[0][1] < $best[1][0][1])) {
                $best = [$mark === 'em' ? 'italic' : $mark, $match];
            }
        }
        if ($best === null) {
            return $this->atoms($text, $marks, $placeholders);
        }
        [$mark, $match] = $best;
        $start          = $match[0][1];
        $end            = $start + strlen($match[0][0]);

        return array_merge(
            $this->atoms(substr($text, 0, $start), $marks, $placeholders),
            $this->styled($match[1][0], in_array($mark, $marks, true) ? $marks : [...$marks, $mark], $placeholders),
            $this->styled(substr($text, $end), $marks, $placeholders),
        );
    }

    /**
     * @param list<string> $marks
     * @param array<string, list<array<string, mixed>>> $placeholders
     *
     * @return list<array<string, mixed>>
     */
    private function atoms(string $text, array $marks, array $placeholders): array
    {
        $tokens = [];
        $parts  = preg_split(self::PLACEHOLDER, $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        for ($i = 0, $n = count($parts); $i < $n; ++$i) {
            if ($i % 3 === 0) {
                if ($parts[$i] !== '') {
                    $tokens[] = ['type' => 'text', 'text' => $parts[$i]] + ($marks === [] ? [] : ['marks' => $marks]);
                }
                continue;
            }
            $token = $placeholders[$parts[$i]][(int) $parts[$i + 1]] ?? null;
            ++$i;
            if ($token !== null) {
                $tokens[] = $token;
            }
        }

        return $tokens;
    }

    /**
     * @param list<array<string, mixed>> $tokens
     *
     * @return list<array<string, mixed>>
     */
    private function merge(array $tokens): array
    {
        $merged = [];
        foreach ($tokens as $token) {
            $last = count($merged) - 1;
            if ($token['type'] === 'text' && $last >= 0 && $merged[$last]['type'] === 'text' && ($merged[$last]['marks'] ?? []) === ($token['marks'] ?? [])) {
                $merged[$last]['text'] .= $token['text'];
                continue;
            }
            $merged[] = $token;
        }

        return array_values($merged);
    }

    /**
     * The plain text of a link's label: code as written, a visible reference's host title, else the neutral text.
     *
     * @param array<string, list<array<string, mixed>>> $placeholders
     */
    private static function plainOf(string $text, array $placeholders): string
    {
        return (string) preg_replace_callback(self::PLACEHOLDER, static function (array $p) use ($placeholders): string {
            $token = $placeholders[$p[1]][(int) $p[2]] ?? null;

            return match ($token['type'] ?? null) {
                'code'      => (string) $token['text'],
                'reference' => (string) ($token['title'] ?? $token['text']),
                default     => '',
            };
        }, $text);
    }

    /**
     * @return array<string, mixed>
     */
    private function reference(string $type, string $id, object $reader, TaskTextMemo $memo): array
    {
        $key  = $type . ':' . $id;
        $card = $memo->references[$key] ??= ($this->references?->reference($reader, $type, $id) ?? false);
        if (!$card instanceof TaskClientReference || $card->type !== $type || $card->id !== $id) {
            return ['type' => 'reference', 'visible' => false, 'text' => self::NOT_VISIBLE];
        }

        return ['type' => 'reference', 'visible' => true, 'refType' => $card->type, 'id' => $card->id, 'title' => $card->title, 'url' => self::safeUrl($card->url)];
    }

    /**
     * @return array<string, mixed>
     */
    private function image(string $alt, string $url, object $reader, TaskTextMemo $memo): array
    {
        $prefix = $this->fileScheme === null ? null : $this->fileScheme . ':';
        if ($prefix === null || !str_starts_with($url, $prefix) || !$this->files instanceof TaskClientFileResolverInterface) {
            return ['type' => 'image', 'visible' => false];
        }
        $fileId = substr($url, strlen($prefix));
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $fileId) !== 1 || !($memo->files[$fileId] ??= $this->files->canView($reader, $fileId))) {
            return ['type' => 'image', 'visible' => false];
        }

        return ['type' => 'image', 'visible' => true, 'fileId' => $fileId, 'alt' => trim($alt)];
    }

    /** A same-origin path or an `https://` URL; anything else is dropped. */
    public static function safeUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        return preg_match('~^(?:https://[^\s<>"\'`\\\\]+|/(?![/\\\\])[^\s<>"\'`\\\\]*)$~D', $url) === 1 ? $url : null;
    }
}
