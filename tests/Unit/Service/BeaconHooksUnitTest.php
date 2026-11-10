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
use Psr\Log\AbstractLogger;
use RuntimeException;
use stdClass;
use Stringable;
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
        foreach ([[1], '', 1.5, true, null] as $bad) {
            self::assertNull(ActorId::of(new class($bad) {
                public function __construct(private readonly mixed $id)
                {
                }

                public function getId(): mixed
                {
                    return $this->id;
                }
            }), 'audit JY-L3: ' . get_debug_type($bad));
        }
        // Audit JY-L3: a Stringable id (a Symfony Uuid) is kept.
        $uuid = new class implements Stringable {
            public function __toString(): string
            {
                return '0195f3b2-7c4e-7d3a-9a7e-2f4c1b9d8e01';
            }
        };
        self::assertSame('0195f3b2-7c4e-7d3a-9a7e-2f4c1b9d8e01', ActorId::of(new class($uuid) {
            public function __construct(private readonly Stringable $id)
            {
            }

            public function getId(): Stringable
            {
                return $this->id;
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

    public function testAFailingListenerDoesNotStopTheOtherHeldEvents(): void
    {
        $inner = new EventDispatcher();
        $seen  = [];
        $inner->addListener('e', static function (stdClass $event) use (&$seen): void {
            $seen[] = $event->name;
            if ($event->name !== 'ok') {
                throw new RuntimeException('listener ' . $event->name);
            }
        });
        $logged = [];
        $logger = new class($logged) extends AbstractLogger {
            /** @param list<string> $logged */
            public function __construct(private array &$logged)
            {
            }

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->logged[] = $level . ': ' . $context['event'];
            }
        };
        $deferral = new TaskEventDeferral($inner, $logger);

        // Audit JY-L1: every held event is dispatched; the first failure is rethrown after the loop...
        $deferral->begin();
        foreach (['first', 'ok', 'second'] as $name) {
            $deferral->dispatch((object) ['name' => $name], 'e');
        }
        try {
            $deferral->commit();
            self::fail('Rethrown');
        } catch (RuntimeException $failure) {
            self::assertSame('listener first', $failure->getMessage());
        }
        self::assertSame(['first', 'ok', 'second'], $seen);
        self::assertSame([], $logged);

        // ...or, after a committed write, logged and swallowed.
        $seen = [];
        $deferral->begin();
        $deferral->dispatch((object) ['name' => 'bad'], 'e');
        $deferral->dispatch((object) ['name' => 'ok'], 'e');
        $deferral->commit(false);
        self::assertSame(['bad', 'ok'], $seen);
        self::assertSame(['error: e'], $logged);
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
