<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Service;

use Nowo\TaskBoardBundle\Event\ColumnDeleteCheckEvent;
use Nowo\TaskBoardBundle\Service\TaskChangeContext;
use Nowo\TaskBoardBundle\Service\TaskChangeRecorder;
use Nowo\TaskBoardBundle\Service\TaskEventDeferral;
use Nowo\TaskBoardBundle\Support\ActorId;
use Nowo\TaskBoardBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Since 2.10.0 (spec 216): the pieces of the Beacon hooks that need no kernel.
 */
final class BeaconHooksUnitTest extends TestCase
{
    public function testTheActorIdIsTheUsersScalarId(): void
    {
        self::assertNull(ActorId::of(null));
        self::assertNull(ActorId::of(new stdClass()));
        self::assertSame('7', ActorId::of(new TestUser('7', 'ana')));
        self::assertSame('12', ActorId::of(new class {
            public function getId(): int
            {
                return 12;
            }
        }));
        self::assertNull(ActorId::of(new class {
            /** @return list<int> */
            public function getId(): array
            {
                return [1];
            }
        }));
    }

    public function testTheDeleteCheckKeepsWarningsOfTheBoardsColumnsOnly(): void
    {
        $user  = new stdClass();
        $check = new ColumnDeleteCheckEvent('board-1', 'acme', ['c1', 'c2'], $user);
        $check->addWarning('c1', '  One rule uses this column.  ');
        $check->addWarning('c1', '   ');
        $check->addWarning('c3', 'Not on this board');

        self::assertSame(['One rule uses this column.'], $check->getWarnings('c1'));
        self::assertSame([], $check->getWarnings('c2'));
        self::assertSame(['c1' => ['One rule uses this column.']], $check->getAllWarnings());
        self::assertSame(['board-1', 'acme', $user], [$check->boardId, $check->tenantKey, $check->user]);
    }

    public function testAHeldTransactionDispatchesOnCommitAndDropsOnRollBack(): void
    {
        $inner = new EventDispatcher();
        $seen  = [];
        $inner->addListener('e', static function (stdClass $event) use (&$seen): void {
            $seen[] = $event->name;
        });
        $deferral = new TaskEventDeferral($inner);

        $deferral->commit();
        $deferral->rollBack();
        $deferral->begin();
        $deferral->dispatch((object) ['name' => 'a'], 'e');
        $deferral->begin();
        $deferral->dispatch((object) ['name' => 'b'], 'e');
        $deferral->commit();
        self::assertSame([], $seen, 'the outermost one dispatches');
        $deferral->commit();
        self::assertSame(['a', 'b'], $seen);

        $deferral->begin();
        $deferral->begin();
        $deferral->dispatch((object) ['name' => 'c'], 'e');
        $deferral->rollBack();
        $deferral->rollBack();
        $deferral->dispatch((object) ['name' => 'd'], 'e');
        self::assertSame(['a', 'b', 'd'], $seen, 'a rollback drops what it held');
    }

    public function testTheRecorderSaysTheCurrentCause(): void
    {
        self::assertNull((new TaskChangeRecorder())->currentCause());
        $context  = new TaskChangeContext();
        $recorder = new TaskChangeRecorder($context);
        self::assertNull($recorder->currentCause());
        self::assertSame('rule:1', $context->run('rule:1', static fn (): ?string => $recorder->currentCause()));
    }
}
