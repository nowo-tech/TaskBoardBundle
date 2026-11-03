<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Dto;

use Nowo\TaskBoardBundle\Dto\TaskWindow;
use PHPUnit\Framework\TestCase;

final class TaskWindowTest extends TestCase
{
    public function testAWindowIsCappedWhenTheBoardHasMoreTasksThanItShows(): void
    {
        self::assertTrue((new TaskWindow(shown: 500, total: 640, limit: 500))->isCapped());
        self::assertFalse((new TaskWindow(shown: 12, total: 12, limit: 500))->isCapped());
        self::assertFalse((new TaskWindow(shown: 500, total: 500, limit: 500))->isCapped());
    }
}
