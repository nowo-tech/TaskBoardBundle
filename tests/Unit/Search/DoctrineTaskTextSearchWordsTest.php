<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Search;

use Nowo\TaskBoardBundle\Search\DoctrineTaskTextSearch;
use PHPUnit\Framework\TestCase;

use function array_map;
use function implode;
use function range;

final class DoctrineTaskTextSearchWordsTest extends TestCase
{
    public function testWordsAreLowerCaseUniqueAndAtMostEight(): void
    {
        self::assertSame(['c-12', 'payment'], DoctrineTaskTextSearch::words("  C-12\tPayment payment "));
        self::assertSame([], DoctrineTaskTextSearch::words('   '));
        self::assertCount(8, DoctrineTaskTextSearch::words(implode(' ', array_map(static fn (int $i): string => 'w' . $i, range(1, 12)))));
    }
}
