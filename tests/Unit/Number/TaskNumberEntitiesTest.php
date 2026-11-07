<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Number;

use Nowo\TaskBoardBundle\Number\Entity\TaskNumberCounter;
use Nowo\TaskBoardBundle\Number\Entity\TaskNumberEntry;
use PHPUnit\Framework\TestCase;

final class TaskNumberEntitiesTest extends TestCase
{
    public function testTheyHoldWhatTheyWereGiven(): void
    {
        $counter = new TaskNumberCounter('acme', 3);
        self::assertSame('acme', $counter->getScopeKey());
        self::assertSame(3, $counter->getLastNumber());

        $entry = new TaskNumberEntry('acme', 7, null);
        self::assertSame('acme', $entry->getScopeKey());
        self::assertSame(7, $entry->getNumber());
        self::assertNull($entry->getTask());
    }
}
