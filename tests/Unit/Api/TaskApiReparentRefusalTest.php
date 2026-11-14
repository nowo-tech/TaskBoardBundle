<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Api;

use Nowo\TaskBoardBundle\Api\TaskApiRefusal;
use Nowo\TaskBoardBundle\Tree\TaskReparentRefusedException;
use PHPUnit\Framework\TestCase;

/**
 * Beacon spec 234, T004: each refusal of a parent change has its own stable `ECODE`.
 */
final class TaskApiReparentRefusalTest extends TestCase
{
    public function testEachReasonHasItsCode(): void
    {
        $codes = [];
        foreach (['otherBoard', 'self', 'descendant', 'conflict', 'notFound'] as $factory) {
            $refusal = TaskApiRefusal::reparentRefused(TaskReparentRefusedException::$factory());
            $codes[] = [$refusal->status, $refusal->ecode];
        }

        self::assertSame([[400, 'PARENT_001'], [400, 'PARENT_002'], [409, 'PARENT_003'], [409, 'PARENT_004'], [404, 'ACCESS_001']], $codes);
    }
}
