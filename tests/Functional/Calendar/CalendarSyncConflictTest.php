<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Calendar;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Service\TaskManager;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use Nowo\TaskBoardBundle\Tests\Functional\Sync\SyncFunctionalTestCase;

/**
 * Spec 214, FR-032: a date changed from the calendar on a linked board is a local edit. The sync's per-field rule keeps it, and a
 * remote change on top of it is a conflict; the provider is never written to.
 */
final class CalendarSyncConflictTest extends SyncFunctionalTestCase
{
    /** Audit HY-L3: no start from the provider is no start here. The Gantt still draws from the creation day. */
    public function testASyncedTaskWithNoStartKeepsNone(): void
    {
        $board = $this->board(columns: ['To do', 'Done']);
        $this->put($this->remote('a1', 'Due only', 1, due: new DateTimeImmutable('2030-01-10')), $this->remote('a2', 'No dates'));
        $this->sync($board);
        $this->em->clear();

        $due = $this->em->getRepository(Task::class)->findOneBy(['title' => 'Due only']);
        self::assertInstanceOf(Task::class, $due);
        self::assertNull($due->getStartAt());
        self::assertSame($due->getCreatedAt()->getTimestamp(), $due->getPlannedStart()->getTimestamp());

        $owner = $this->em->find(AppUser::class, $this->owner->getId());
        self::assertInstanceOf(AppUser::class, $owner);
        $this->login($owner);
        $crawler = $this->client->request('GET', '/tools/task-board/board/' . $board->getId() . '/gantt');
        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('.nowo-task-gantt__bar'));
    }

    public function testARescheduleIsALocalEditForTheNextSync(): void
    {
        $board = $this->board(columns: ['To do', 'Done']);
        $this->put($this->remote('a1', 'Task', 1, due: new DateTimeImmutable('2026-11-10')));
        $this->sync($board);
        $task = $this->em->getRepository(Task::class)->findOneBy(['title' => 'Task']);
        self::assertInstanceOf(Task::class, $task);

        $manager = self::getContainer()->get(TaskManager::class);
        self::assertInstanceOf(TaskManager::class, $manager);
        $manager->reschedule($task, $task->getStartAt(), new DateTimeImmutable('2026-11-20'), $this->owner);

        // Nothing changed there: the local date stays.
        $this->put($this->remote('a1', 'Task', 2, due: new DateTimeImmutable('2026-11-10')));
        $report = $this->sync($board);
        self::assertSame([], $report->conflicts);
        $this->em->clear();
        self::assertSame('2026-11-20', $this->em->getRepository(Task::class)->findOneBy(['title' => 'Task'])?->getDueAt()?->format('Y-m-d'));

        // Changed on both sides: a conflict, and the local date still stays.
        $this->put($this->remote('a1', 'Task', 3, due: new DateTimeImmutable('2026-11-25')));
        $report = $this->sync($board);
        self::assertCount(1, $report->conflicts);
        $this->em->clear();
        self::assertSame('2026-11-20', $this->em->getRepository(Task::class)->findOneBy(['title' => 'Task'])?->getDueAt()?->format('Y-m-d'));
    }
}
