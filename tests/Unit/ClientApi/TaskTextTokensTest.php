<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\ClientApi;

use Nowo\TaskBoardBundle\ClientApi\Contract\TaskClientFileResolverInterface;
use Nowo\TaskBoardBundle\ClientApi\Contract\TaskClientReferenceResolverInterface;
use Nowo\TaskBoardBundle\ClientApi\Model\TaskClientReference;
use Nowo\TaskBoardBundle\ClientApi\Text\TaskTextTokens;
use PHPUnit\Framework\TestCase;
use stdClass;

use function ini_get;
use function strlen;

/**
 * Since 3.4.0 (client API v1, tokens/1, §5.2): the converter's blocks and tokens, and R2 for references and images.
 */
final class TaskTextTokensTest extends TestCase
{
    public function testEmptyTextHasNoBlocks(): void
    {
        self::assertSame(['format' => 'tokens/1', 'blocks' => []], (new TaskTextTokens())->of(null, new stdClass()));
        self::assertSame([], (new TaskTextTokens())->of("  \n ", new stdClass())['blocks']);
    }

    public function testBlocks(): void
    {
        $text   = "## Plan\n#### Deep\nLine one\nline two\n\n> quoted\n> more\n\n1. first\n2) second\n- a\n* b\n\n***\n\n```php\necho 1;\n```\nafter";
        $blocks = (new TaskTextTokens())->of($text, new stdClass())['blocks'];
        self::assertSame([
            ['type' => 'heading', 'level' => 2, 'tokens' => [['type' => 'text', 'text' => 'Plan']]],
            ['type' => 'heading', 'level' => 3, 'tokens' => [['type' => 'text', 'text' => 'Deep']]],
            ['type' => 'paragraph', 'tokens' => [['type' => 'text', 'text' => 'Line one'], ['type' => 'break'], ['type' => 'text', 'text' => 'line two']]],
            ['type' => 'quote', 'tokens' => [['type' => 'text', 'text' => 'quoted'], ['type' => 'break'], ['type' => 'text', 'text' => 'more']]],
            ['type' => 'list', 'ordered' => true, 'items' => [[['type' => 'text', 'text' => 'first']], [['type' => 'text', 'text' => 'second']]]],
            ['type' => 'list', 'ordered' => false, 'items' => [[['type' => 'text', 'text' => 'a']], [['type' => 'text', 'text' => 'b']]]],
            ['type' => 'rule'],
            ['type' => 'code', 'text' => 'echo 1;'],
            ['type' => 'paragraph', 'tokens' => [['type' => 'text', 'text' => 'after']]],
        ], $blocks);
    }

    public function testInlineTokensAndSafeLinks(): void
    {
        $tokens = (new TaskTextTokens())->of('**b** *i* _e_ ~~s~~ `x <y>` [site](https://a.test/p) [bad](javascript:alert(1)) www.b.test, me@c.test and mailto:d@e.test', new stdClass())['blocks'][0]['tokens'];
        self::assertSame([
            ['type' => 'text', 'text' => 'b', 'marks' => ['bold']],
            ['type' => 'text', 'text' => ' '],
            ['type' => 'text', 'text' => 'i', 'marks' => ['italic']],
            ['type' => 'text', 'text' => ' '],
            ['type' => 'text', 'text' => 'e', 'marks' => ['italic']],
            ['type' => 'text', 'text' => ' '],
            ['type' => 'text', 'text' => 's', 'marks' => ['strike']],
            ['type' => 'text', 'text' => ' '],
            ['type' => 'code', 'text' => 'x <y>'],
            ['type' => 'text', 'text' => ' '],
            ['type' => 'link', 'url' => 'https://a.test/p', 'text' => 'site'],
            ['type' => 'text', 'text' => ' [bad](javascript:alert(1)) '],
            ['type' => 'link', 'url' => 'https://www.b.test', 'text' => 'www.b.test'],
            ['type' => 'text', 'text' => ', '],
            ['type' => 'link', 'url' => 'mailto:me@c.test', 'text' => 'me@c.test'],
            ['type' => 'text', 'text' => ' and '],
            ['type' => 'link', 'url' => 'mailto:d@e.test', 'text' => 'd@e.test'],
        ], $tokens);
    }

    public function testReferencesAreTheHostsForTheReaderAndNeverInCode(): void
    {
        $references = new class implements TaskClientReferenceResolverInterface {
            public int $asked = 0;

            public function reference(object $reader, string $type, string $id): ?TaskClientReference
            {
                ++$this->asked;

                return match ($id) {
                    '1'     => new TaskClientReference('issue', '1', "Real\u{202E} title", 'javascript:x'),
                    '2'     => new TaskClientReference('issue', '9', 'Another object'),
                    default => null,
                };
            }
        };
        $text   = '#[guess](issue:1) #[guess](issue:1) #[x](issue:2) #[y](issue:3) [see #[z](issue:1)](https://a.test) `#[c](issue:1)`';
        $tokens = (new TaskTextTokens($references))->of($text, new stdClass())['blocks'][0]['tokens'];
        self::assertSame(['type' => 'reference', 'visible' => true, 'refType' => 'issue', 'id' => '1', 'title' => 'Real  title', 'url' => null], $tokens[0]);
        self::assertSame(['type' => 'reference', 'visible' => false, 'text' => 'Content not visible'], $tokens[4], 'an answer for another object is not this one');
        self::assertSame(['type' => 'reference', 'visible' => false, 'text' => 'Content not visible'], $tokens[6]);
        self::assertSame(['type' => 'link', 'url' => 'https://a.test', 'text' => 'see Real  title'], $tokens[8]);
        self::assertSame(['type' => 'code', 'text' => '#[c](issue:1)'], $tokens[10]);
        self::assertSame(3, $references->asked, 'once per object and text');
    }

    public function testImagesAreHostFilesTheReaderMayOpen(): void
    {
        $files = new class implements TaskClientFileResolverInterface {
            public function canView(object $reader, string $fileId): bool
            {
                return $fileId === 'ok';
            }
        };
        $text = '![a](beacon-file:ok) ![b](beacon-file:no) ![c](beacon-file:bad/id) ![d](https://x.test/i.png)';
        self::assertSame(
            [['type' => 'image', 'visible' => true, 'fileId' => 'ok', 'alt' => 'a'], ['type' => 'image', 'visible' => false], ['type' => 'image', 'visible' => false], ['type' => 'image', 'visible' => false]],
            array_values(array_filter((new TaskTextTokens(null, $files, 'beacon-file'))->of($text, new stdClass())['blocks'][0]['tokens'], static fn (array $token): bool => $token['type'] === 'image')),
        );
        self::assertSame(['type' => 'image', 'visible' => false], (new TaskTextTokens(null, $files))->of('![a](beacon-file:ok)', new stdClass())['blocks'][0]['tokens'][0]);
    }

    public function testTaskListsAndAFenceInsideALine(): void
    {
        $blocks = (new TaskTextTokens())->of("- [x] done\n- [ ] open\n- plain\n\n```\ncode\n```", new stdClass())['blocks'];
        self::assertSame([true, false, false], $blocks[0]['checked']);
        self::assertSame([[['type' => 'text', 'text' => 'done']], [['type' => 'text', 'text' => 'open']], [['type' => 'text', 'text' => 'plain']]], $blocks[0]['items']);
        self::assertSame(['type' => 'code', 'text' => 'code'], $blocks[1]);
    }

    public function testBareLinksAdjacentMarksAndCodeInALabel(): void
    {
        self::assertSame(
            [['type' => 'link', 'url' => 'https://x.test/a', 'text' => 'https://x.test/a'], ['type' => 'text', 'text' => '. '], ['type' => 'text', 'text' => 'ab', 'marks' => ['bold']], ['type' => 'text', 'text' => ' '], ['type' => 'link', 'url' => 'https://y.test', 'text' => 'run x']],
            (new TaskTextTokens())->of('https://x.test/a. **a****b** [run `x`](https://y.test)', new stdClass())['blocks'][0]['tokens'],
        );
    }

    /**
     * Audit ZCS-M1: adversarial emphasis stays linear and bounded — 64 KB in one line, in 4 KB lines, closed and unclosed
     * markers — under a 128 MB limit and in well under a second each.
     */
    public function testAdversarialEmphasisIsBounded(): void
    {
        $limit = ini_get('memory_limit');
        ini_set('memory_limit', '128M');
        try {
            foreach (['*a* ', '**a ', '_a ', '~~a ', '*a* **b** _c_ '] as $unit) {
                $line  = str_repeat($unit, intdiv(65536, strlen($unit)));
                $lines = implode("\n", str_split(str_repeat($unit, intdiv(65536, strlen($unit))), 4000));
                foreach ([$line, $lines] as $text) {
                    $started = microtime(true);
                    $blocks  = (new TaskTextTokens())->of($text, new stdClass())['blocks'];
                    self::assertNotSame([], $blocks);
                    self::assertLessThan(0.5, microtime(true) - $started, $unit);
                }
            }
            self::assertLessThan(128 * 1024 * 1024, memory_get_peak_usage());
        } finally {
            ini_set('memory_limit', $limit);
        }
        // Within the budget, emphasis is read; past MAX_MARKS spans the rest is plain text.
        $tokens = (new TaskTextTokens())->of(str_repeat('*a* ', 300), new stdClass())['blocks'][0]['tokens'];
        self::assertSame(['type' => 'text', 'text' => 'a', 'marks' => ['italic']], $tokens[0]);
        self::assertStringContainsString('*a*', (string) $tokens[array_key_last($tokens)]['text']);
    }

    public function testALongTextIsCutOnACharacterAndSaysSo(): void
    {
        $text = (new TaskTextTokens())->of(str_repeat('é', 40000), new stdClass());
        self::assertTrue($text['truncated'] ?? false);
        self::assertSame(32768, mb_strlen((string) $text['blocks'][0]['tokens'][0]['text']));
        self::assertArrayNotHasKey('truncated', (new TaskTextTokens())->of('short', new stdClass()));
    }

    public function testSafeUrls(): void
    {
        self::assertSame('https://a.test/x', TaskTextTokens::safeUrl('https://a.test/x'));
        self::assertSame('/issues/1', TaskTextTokens::safeUrl('/issues/1'));
        foreach (['http://a.test', '//evil', 'javascript:x', '/\\evil', '/a b', null] as $url) {
            self::assertNull(TaskTextTokens::safeUrl($url));
        }
    }
}
