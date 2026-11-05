<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional;

use DateTimeImmutable;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskLink;
use Nowo\TaskBoardBundle\Entity\TaskTimeEntry;
use Nowo\TaskBoardBundle\Enum\TaskLinkType;
use Nowo\TaskBoardBundle\Repository\TaskBoardRepositoryInterface;
use Nowo\TaskBoardBundle\Service\TaskTimeLogger;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;

use function array_map;
use function sort;

/**
 * Since 1.23.2: deleting a task never deletes its tracked time. Every path that removes tasks (the entity manager,
 * the board's `remove` cascade, the database cascades of a parent task or a board, a bulk DQL delete) leaves the
 * time entries in place, detached (`task` = null) with the task title / board id / external id snapshots filled.
 */
final class TaskTimeEntryRetentionTest extends FunctionalTestCase
{
    public function testTheMappingKeepsTheEntriesWhenTheTaskGoes(): void
    {
        $entry = $this->em->getClassMetadata(TaskTimeEntry::class)->getAssociationMapping('task');
        self::assertTrue($entry->joinColumns[0]->nullable);
        self::assertSame('SET NULL', $entry->joinColumns[0]->onDelete);
        foreach (['task_title_snapshot', 'board_id_snapshot', 'task_external_id'] as $column) {
            self::assertTrue($this->em->getClassMetadata(TaskTimeEntry::class)->getFieldMapping($this->em->getClassMetadata(TaskTimeEntry::class)->getFieldForColumn($column))->nullable, $column);
        }

        $collection = $this->em->getClassMetadata(Task::class)->getAssociationMapping('timeEntries');
        self::assertFalse($collection->orphanRemoval);
        self::assertFalse($collection->isCascadeRemove());
        self::assertTrue($collection->isCascadePersist());
    }

    public function testDeletingOneTaskKeepsItsTimeDetachedWithSnapshots(): void
    {
        $board = $this->board();
        $task  = $this->task($board, 'Write the spec');
        $task->addLink(new TaskLink($task, TaskLinkType::Url, 'https://app.clickup.com/t/abc', null, 'abc', importSource: 'clickup'));
        $this->em->flush();
        $first  = $this->timeLogger()->log($task, $this->owner, 90, new DateTimeImmutable('2026-11-01'), 'Pairing');
        $second = $this->timeLogger()->log($task, $this->owner, 30, new DateTimeImmutable('2026-11-02'));
        self::assertSame('Write the spec', $first->getTaskTitleSnapshot());
        self::assertSame('abc', $first->getTaskExternalId());

        // A rename refreshes the snapshots.
        $task->setTitle('Write the final spec');
        $this->em->flush();
        $this->em->clear();
        self::assertSame('Write the final spec', $this->entry($first->getId())->getTaskTitleSnapshot());

        $task = $this->em->find(Task::class, $task->getId());
        self::assertInstanceOf(Task::class, $task);
        $this->em->remove($task);
        $this->em->flush();
        $this->em->clear();

        self::assertNull($this->em->find(Task::class, $task->getId()));
        foreach ([$first->getId() => 90, $second->getId() => 30] as $id => $minutes) {
            $kept = $this->entry($id);
            self::assertTrue($kept->isDetached());
            self::assertNull($kept->getTask());
            self::assertSame($minutes, $kept->getMinutes());
            self::assertSame('Write the final spec', $kept->getTaskTitle());
            self::assertSame($board->getId(), $kept->getBoardId());
            self::assertSame('abc', $kept->getTaskExternalId());
        }
        self::assertSame('Pairing', $this->entry($first->getId())->getDescription());
    }

    public function testRemovingAnEntryFromTheTaskStillDeletesIt(): void
    {
        $task  = $this->task($this->board(), 'Tracked');
        $entry = $this->timeLogger()->log($task, $this->owner, 15, new DateTimeImmutable('2026-11-03'));
        $kept  = $this->timeLogger()->log($task, $this->owner, 20, new DateTimeImmutable('2026-11-03'));

        self::assertTrue($this->timeLogger()->remove($task, $entry->getId(), $this->owner));
        $this->em->clear();

        self::assertNull($this->em->find(TaskTimeEntry::class, $entry->getId()));
        self::assertFalse($this->entry($kept->getId())->isDetached());
    }

    public function testDeletingABoardThroughItsRepositoryKeepsEveryEntry(): void
    {
        $board  = $this->board();
        $parent = $this->task($board, 'Parent');
        $child  = $this->task($board, 'Child', parent: $parent);
        $other  = $this->task($board, 'Other');
        $ids    = $this->logOn($parent, $child, $other);

        $boards = static::getContainer()->get(TaskBoardRepositoryInterface::class);
        self::assertInstanceOf(TaskBoardRepositoryInterface::class, $boards);
        $this->clearAndReloadOwner();
        $fresh = $this->em->find(TaskBoard::class, $board->getId());
        self::assertInstanceOf(TaskBoard::class, $fresh);
        $boards->remove($fresh);
        $this->em->clear();

        self::assertSame(0, $this->em->getRepository(Task::class)->count([]));
        $this->assertDetached($ids, ['Child', 'Other', 'Parent'], $board->getId());
    }

    public function testDatabaseCascadesAndBulkDeletesKeepEveryEntry(): void
    {
        $this->enforceSqliteForeignKeys();
        $board  = $this->board();
        $parent = $this->task($board, 'Parent');
        $child  = $this->task($board, 'Child', parent: $parent);
        $ids    = $this->logOn($parent, $child);

        // The parent through the ORM: its subtask goes by the database cascade (parent_id ON DELETE CASCADE).
        $this->em->remove($parent);
        $this->em->flush();
        $this->clearAndReloadOwner();
        self::assertNull($this->em->find(Task::class, $child->getId()));
        $this->assertDetached($ids, ['Child', 'Parent'], $board->getId());

        // A bulk DQL delete bypasses the ORM listeners: ON DELETE SET NULL keeps the time.
        $bulk = $this->board('Bulk');
        $ids  = $this->logOn($this->task($bulk, 'Bulk one'), $this->task($bulk, 'Bulk two'));
        $this->em->createQuery('DELETE FROM ' . Task::class . ' t WHERE t.board = :board')->setParameter('board', $bulk->getId())->execute();
        $this->clearAndReloadOwner();
        $this->assertDetached($ids, ['Bulk one', 'Bulk two'], $bulk->getId());

        // A board deleted in SQL: its tasks go by the database cascade, the time stays.
        $sql = $this->board('Sql');
        $ids = $this->logOn($this->task($sql, 'Sql one'));
        $this->em->getConnection()->executeStatement('DELETE FROM ' . $this->em->getClassMetadata(TaskBoard::class)->getTableName() . ' WHERE id = ?', [$sql->getId()]);
        $this->em->clear();
        $this->assertDetached($ids, ['Sql one'], $sql->getId());
    }

    public function testTheBoardPagesStillRenderAfterATaskWithTimeIsDeleted(): void
    {
        $board = $this->board();
        $gone  = $this->task($board, 'Gone');
        $stays = $this->task($board, 'Stays');
        $this->logOn($gone, $stays);
        $this->em->remove($gone);
        $this->em->flush();
        $this->em->clear();
        $this->login($this->owner);

        foreach (['', '/list', '/gantt'] as $view) {
            $this->client->request('GET', '/tools/task-board/board/' . $board->getId() . $view);
            self::assertResponseIsSuccessful($view);
        }
        $this->client->request('GET', '/tools/task-board/task/' . $stays->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-time-entry-id]');
    }

    private function timeLogger(): TaskTimeLogger
    {
        $logger = static::getContainer()->get(TaskTimeLogger::class);
        self::assertInstanceOf(TaskTimeLogger::class, $logger);

        return $logger;
    }

    private function entry(string $id): TaskTimeEntry
    {
        $entry = $this->em->find(TaskTimeEntry::class, $id);
        self::assertInstanceOf(TaskTimeEntry::class, $entry, $id);

        return $entry;
    }

    /**
     * @return list<string> the ids of one entry logged on each task
     */
    private function logOn(Task ...$tasks): array
    {
        return array_map(fn (Task $task): string => $this->timeLogger()->log($task, $this->owner, 10, new DateTimeImmutable('2026-11-04'))->getId(), $tasks);
    }

    /**
     * @param list<string> $ids
     * @param list<string> $titles
     */
    private function assertDetached(array $ids, array $titles, string $boardId): void
    {
        $found = [];
        foreach ($ids as $id) {
            $entry = $this->entry($id);
            self::assertNull($entry->getTask(), $id);
            self::assertSame(10, $entry->getMinutes());
            self::assertSame($boardId, $entry->getBoardId());
            $found[] = (string) $entry->getTaskTitle();
        }
        sort($found);
        self::assertSame($titles, $found);
    }

    private function clearAndReloadOwner(): void
    {
        $this->em->clear();
        $owner = $this->em->find(AppUser::class, $this->owner->getId());
        self::assertInstanceOf(AppUser::class, $owner);
        $this->owner = $owner;
    }

    /** SQLite only applies ON DELETE actions with this pragma (MySQL and PostgreSQL always do). */
    private function enforceSqliteForeignKeys(): void
    {
        if ($this->em->getConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
            $this->em->getConnection()->executeStatement('PRAGMA foreign_keys = ON');
        }
    }
}
