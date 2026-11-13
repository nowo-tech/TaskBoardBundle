<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Support;

use Nowo\TaskBoardBundle\Support\TextFold;
use PHPUnit\Framework\TestCase;

final class TextFoldTest extends TestCase
{
    public function testFoldsCaseAndAccents(): void
    {
        self::assertSame('jose alvarez', TextFold::fold('  José ÁLVAREZ '));
        self::assertSame('nunez', TextFold::fold('Núñez'));
    }

    public function testPrefixOfTheNameOrOfAWord(): void
    {
        self::assertTrue(TextFold::prefixMatches('jose', 'José Álvarez'));
        self::assertTrue(TextFold::prefixMatches('ALV', 'José Álvarez'));
        self::assertTrue(TextFold::prefixMatches('decomp', 'ai-story-decomposer'));
        self::assertTrue(TextFold::prefixMatches('jose alv', 'José Álvarez'));
        self::assertFalse(TextFold::prefixMatches('varez', 'José Álvarez'));
        self::assertFalse(TextFold::prefixMatches('', 'José'));
        self::assertFalse(TextFold::prefixMatches('   ', 'José'));
    }
}
