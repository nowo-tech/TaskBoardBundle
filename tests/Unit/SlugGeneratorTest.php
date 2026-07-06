<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit;

use Nowo\TaskBoardBundle\Support\SlugGenerator;
use PHPUnit\Framework\TestCase;

final class SlugGeneratorTest extends TestCase
{
    public function testGeneratesSlugFromName(): void
    {
        self::assertSame('platform-squad', SlugGenerator::fromName('Platform Squad'));
    }

    public function testFallbackWhenEmpty(): void
    {
        self::assertSame('board', SlugGenerator::fromName('   !!!   '));
    }
}
