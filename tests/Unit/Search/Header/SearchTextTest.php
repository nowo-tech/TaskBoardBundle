<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Search\Header;

use Nowo\TaskBoardBundle\Search\Header\SearchText;
use PHPUnit\Framework\TestCase;

use function mb_strlen;
use function str_repeat;

final class SearchTextTest extends TestCase
{
    public function testWordsAndEscapedPatterns(): void
    {
        self::assertSame(['release', 'plan'], SearchText::words('  Release   PLAN release '));
        self::assertSame('%100!%!_x!!%', SearchText::contains('100%_x!'));
    }

    public function testExcerptOfShortRichText(): void
    {
        self::assertNull(SearchText::excerpt(null, []));
        self::assertNull(SearchText::excerpt(" <p></p>\n ", []));
        self::assertSame('Title Some bold and a link here. One Two', SearchText::excerpt("# Title\n\nSome **bold** and [a link](https://example.com) here.\n- One\n- Two", []));
        self::assertSame('First Second & more', SearchText::excerpt('<p>First</p><p>Second &amp; more</p>', []));
        self::assertSame('a b', SearchText::excerpt("a\u{202E}\u{0007}b", []));
    }

    public function testExcerptOfLongTextAroundTheFirstFoundWord(): void
    {
        $text = str_repeat('alpha ', 40) . 'needle ' . str_repeat('omega ', 40);

        $middle = (string) SearchText::excerpt($text, ['missing', 'needle']);
        self::assertStringStartsWith('…', $middle);
        self::assertStringEndsWith('…', $middle);
        self::assertStringContainsString('needle', $middle);
        self::assertLessThanOrEqual(SearchText::EXCERPT_LENGTH + 2, mb_strlen($middle));

        $start = (string) SearchText::excerpt($text, ['missing']);
        self::assertStringStartsWith('alpha', $start);
        self::assertStringEndsWith('…', $start);

        $end = (string) SearchText::excerpt(str_repeat('alpha ', 40) . 'needle', ['needle']);
        self::assertStringStartsWith('…', $end);
        self::assertStringEndsWith('needle', $end);
    }

    public function testLine(): void
    {
        self::assertSame('Two words', SearchText::line("  Two \u{200B}\n words "));
    }
}
