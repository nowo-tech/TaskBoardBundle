<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Service;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskTimeEntry;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Event\TaskTimeBillableChangedEvent;
use Nowo\TaskBoardBundle\Exception\TaskTimeBillableRefusedException;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TaskBoardBundle\Repository\TeamMemberRepositoryInterface;
use Nowo\TaskBoardBundle\Security\NullTaskBoardTeamMembershipResolver;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Service\NoTaskTimePeriodLock;
use Nowo\TaskBoardBundle\Service\TaskChangeRecorder;
use Nowo\TaskBoardBundle\Service\TaskEventDeferral;
use Nowo\TaskBoardBundle\Service\TaskTimeBillableService;
use Nowo\TaskBoardBundle\Service\TaskTimePeriodLockInterface;
use Nowo\TaskBoardBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Since 2.12.0 (Beacon D-20261111-12): the audited billable setter of manual entries — who may change it, the period lock,
 * the history row and the event.
 */
final class TaskTimeBillableServiceTest extends TestCase
{
    private TestUser $manager;

    private TestUser $author;

    private Task $task;

    private TaskTimeEntry $entry;

    /** @var list<TaskTimeBillableChangedEvent> */
    private array $events = [];

    private EventDispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->manager = new TestUser('1', 'manager@example.com');
        $this->author  = new TestUser('2', 'author@example.com');
        $this->task    = new Task(new TaskBoard('Board', 'board', $this->manager), 'Task', $this->author);
        $this->entry   = new TaskTimeEntry($this->task, $this->author, 60, new DateTimeImmutable('2026-11-10'));
        $this->task->addTimeEntry($this->entry);
        $this->events     = [];
        $this->dispatcher = new EventDispatcher();
        $this->dispatcher->addListener(TaskBoardEvents::TASK_TIME_BILLABLE_CHANGED, function (TaskTimeBillableChangedEvent $event): void {
            $this->events[] = $event;
        });
    }

    public function testTheAuthorAndABoardManagerChangeItAndEachChangeIsAudited(): void
    {
        $service = $this->service(saves: 2);
        self::assertSame($this->entry, $service->change($this->task, $this->entry->getId(), $this->author, true));
        self::assertTrue($this->entry->getBillable());
        $history = $this->task->getChangeHistory()->last();
        self::assertNotFalse($history);
        self::assertSame([TaskChangeType::TimeBillable, null, 'true', '2026-11-10', $this->author], [$history->getChangeType(), $history->getOldValue(), $history->getNewValue(), $history->getContext(), $history->getUser()]);

        $service->change($this->task, $this->entry->getId(), $this->manager, false);
        $history = $this->task->getChangeHistory()->last();
        self::assertNotFalse($history);
        self::assertSame(['true', 'false', $this->manager], [$history->getOldValue(), $history->getNewValue(), $history->getUser()]);

        self::assertCount(2, $this->events);
        self::assertSame([$this->entry->getId(), $this->task->getId(), $this->task->getBoard()->getId(), null, '2', '2', null, true], [$this->events[0]->entryId, $this->events[0]->taskId, $this->events[0]->boardId, $this->events[0]->tenantKey, $this->events[0]->userId, $this->events[0]->actorId, $this->events[0]->oldBillable, $this->events[0]->newBillable]);
        self::assertSame(['1', true, false], [$this->events[1]->actorId, $this->events[1]->oldBillable, $this->events[1]->newBillable]);
    }

    public function testTheSameValueWritesNothing(): void
    {
        $this->entry->markBillable(true);
        $before = $this->task->getChangeHistory()->count();
        $this->service(saves: 0)->change($this->task, $this->entry->getId(), $this->author, true);

        self::assertSame($before, $this->task->getChangeHistory()->count());
        self::assertSame([], $this->events);
    }

    public function testAnotherUserIsRefused(): void
    {
        $this->expectRefusal(TaskTimeBillableRefusedException::FORBIDDEN, fn (): TaskTimeEntry => $this->service(saves: 0)->change($this->task, $this->entry->getId(), new TestUser('3', 'other@example.com'), true));
        self::assertNull($this->entry->getBillable());
        self::assertSame([], $this->events);
    }

    public function testALockedPeriodUnknownEntriesAndDeletedUsersAreRefused(): void
    {
        $lock = $this->createMock(TaskTimePeriodLockInterface::class);
        $lock->expects(self::once())->method('isLocked')->with($this->author, $this->task->getId(), $this->task->getBoard()->getId(), $this->entry->getLoggedAt())->willReturn(true);
        $this->expectRefusal(TaskTimeBillableRefusedException::PERIOD_LOCKED, fn (): TaskTimeEntry => $this->service(saves: 0, lock: $lock)->change($this->task, $this->entry->getId(), $this->manager, true));
        $this->expectRefusal(TaskTimeBillableRefusedException::NOT_FOUND, fn (): TaskTimeEntry => $this->service(saves: 0)->change($this->task, 'missing', $this->manager, true));

        $this->entry->detachUser(true);
        $this->expectRefusal(TaskTimeBillableRefusedException::ANONYMISED, fn (): TaskTimeEntry => $this->service(saves: 0)->change($this->task, $this->entry->getId(), $this->manager, true));
        self::assertNull($this->entry->getBillable());
        self::assertSame([], $this->events);
    }

    public function testTheEventWaitsForADeferredWriteAndWorksWithoutADispatcher(): void
    {
        $deferral = new TaskEventDeferral($this->dispatcher);
        $deferral->defer(function () use ($deferral): void {
            $this->service(saves: 1, deferral: $deferral)->change($this->task, $this->entry->getId(), $this->author, true);
            self::assertSame([], $this->events, 'held until the write ends');
        });
        self::assertCount(1, $this->events);

        $repo = $this->createMock(TaskRepositoryInterface::class);
        (new TaskTimeBillableService($repo, new TaskChangeRecorder(), $this->checker()))->change($this->task, $this->entry->getId(), $this->author, false);
        self::assertFalse($this->entry->getBillable());
        self::assertFalse((new NoTaskTimePeriodLock())->isLocked($this->author, 't', null, new DateTimeImmutable()));
    }

    /** Audit MV-B-L2: the lock check and the save run inside one transaction; a lock refusal leaves it without a write. */
    public function testTheLockCheckAndTheSaveShareOneTransaction(): void
    {
        $lock = $this->createMock(TaskTimePeriodLockInterface::class);
        $lock->method('isLocked')->willReturnOnConsecutiveCalls(false, true);
        $inside = 0;
        $em     = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::exactly(2))->method('wrapInTransaction')->willReturnCallback(static function (callable $write) use (&$inside): mixed {
            ++$inside;

            return $write();
        });
        $repo = $this->createMock(TaskRepositoryInterface::class);
        $repo->expects(self::once())->method('save')->willReturnCallback(static function () use (&$inside): void {
            self::assertSame(1, $inside, 'saved inside the transaction');
        });
        $service = new TaskTimeBillableService($repo, new TaskChangeRecorder(), $this->checker(), $lock, $this->dispatcher, $em);

        $service->change($this->task, $this->entry->getId(), $this->author, true);
        $this->expectRefusal(TaskTimeBillableRefusedException::PERIOD_LOCKED, fn (): TaskTimeEntry => $service->change($this->task, $this->entry->getId(), $this->author, false));
        self::assertTrue($this->entry->getBillable());
        self::assertCount(1, $this->events);
    }

    private function service(int $saves, ?TaskTimePeriodLockInterface $lock = null, ?EventDispatcher $dispatcher = null, ?TaskEventDeferral $deferral = null): TaskTimeBillableService
    {
        $repo = $this->createMock(TaskRepositoryInterface::class);
        $repo->expects(self::exactly($saves))->method('save')->with($this->task);

        return new TaskTimeBillableService($repo, new TaskChangeRecorder(), $this->checker(), $lock ?? new NoTaskTimePeriodLock(), $deferral ?? $dispatcher ?? $this->dispatcher);
    }

    private function checker(): TaskBoardResourceAccessChecker
    {
        $members = $this->createMock(TeamMemberRepositoryInterface::class);
        $members->method('findByUserId')->willReturn([]);

        return new TaskBoardResourceAccessChecker($members, new NullTaskBoardTeamMembershipResolver(), null, $this->dispatcher);
    }

    private function expectRefusal(string $code, callable $operation): void
    {
        try {
            $operation();
            self::fail('expected ' . $code);
        } catch (TaskTimeBillableRefusedException $exception) {
            self::assertSame($code, $exception->errorCode);
        }
    }
}
