<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\PrivateEntityManager;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use LogicException;
use Nowo\TaskBoardBundle\Doctrine\TaskBoardEntityManager;
use Nowo\TaskBoardBundle\Dto\BoardColumnFormData;
use Nowo\TaskBoardBundle\Dto\TaskBoardFormData;
use Nowo\TaskBoardBundle\Dto\TaskFormData;
use Nowo\TaskBoardBundle\Dto\TaskLinkFormData;
use Nowo\TaskBoardBundle\Dto\TaskMemberFormData;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskChangeHistory;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Entity\TaskDependency;
use Nowo\TaskBoardBundle\Entity\TaskLink;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Entity\TaskTimeEntry;
use Nowo\TaskBoardBundle\Enum\TaskDependencyType;
use Nowo\TaskBoardBundle\Enum\TaskLinkType;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Event\TaskErasedEvent;
use Nowo\TaskBoardBundle\Import\TaskImportOrchestrator;
use Nowo\TaskBoardBundle\Import\TaskImportSource;
use Nowo\TaskBoardBundle\Service\BoardColumnManagerInterface;
use Nowo\TaskBoardBundle\Service\ColumnSetManager;
use Nowo\TaskBoardBundle\Service\TaskBoardCreatorInterface;
use Nowo\TaskBoardBundle\Service\TaskBoardEditorInterface;
use Nowo\TaskBoardBundle\Service\TaskBoardEraserInterface;
use Nowo\TaskBoardBundle\Service\TaskCommentManagerInterface;
use Nowo\TaskBoardBundle\Service\TaskDependencyManager;
use Nowo\TaskBoardBundle\Service\TaskLinkAttacher;
use Nowo\TaskBoardBundle\Service\TaskManagerInterface;
use Nowo\TaskBoardBundle\Service\TaskMemberAssigner;
use Nowo\TaskBoardBundle\Service\TaskTimeBillableService;
use Nowo\TaskBoardBundle\Service\TaskTimeLoggerInterface;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\HostProject;
use Nowo\TaskBoardBundle\Tests\Functional\FunctionalTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use function count;

/**
 * Since 3.0.0 (REQ-ARCH-001 rule 6, audit lessons XS / W1-2): the private manager is the only mode, so both directions
 * between the application's manager and TaskBoard's are checked on a real database (SQLite and the CI servers), with a
 * relation-mapped host user and host entities that point at TaskBoard rows.
 *
 * - An application user on a TaskBoard row is written as TaskBoard's reference to the same id (an initialised instance or
 *   an uninitialised proxy); one not saved yet fails before anything is written, and the
 *   application keeps its scheduled insert.
 * - A TaskBoard row the application's manager holds is re-resolved by id on a new TaskBoard row, and refused when it is
 *   persisted (it would be inserted again).
 * - An application entity that references a TaskBoard row through `getReference()` is flushed by the application.
 * - TaskBoard never flushes the application's manager: a change the application left pending stays pending.
 */
final class HostEntitiesAcrossManagersTest extends FunctionalTestCase
{
    private EntityManagerInterface $host;

    protected function setUp(): void
    {
        parent::setUp();
        $host = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $host);
        $this->host = $host;
    }

    public function testAnInitialisedHostUserIsWrittenByIdAndStaysTheApplicationsOwn(): void
    {
        $owner = $this->host->find(AppUser::class, $this->owner->getId());
        self::assertInstanceOf(AppUser::class, $owner);
        $owner->setDisplayName('Changed by the application');

        $board = $this->creator()->create(new TaskBoardFormData('Held', 'held'), $owner);

        self::assertSame(1, $this->rows(TaskBoard::class, 'id = ? AND creator_id = ?', [$board->getId(), $owner->getId()]));
        self::assertTrue($this->host->contains($owner), 'the application keeps its own user object');
        self::assertNull($this->connectionValue('SELECT display_name FROM ' . $this->table(AppUser::class) . ' WHERE id = ?', [$owner->getId()]), 'TaskBoard never flushed the application\'s pending change');
        $this->host->flush();
        self::assertSame('Changed by the application', $this->connectionValue('SELECT display_name FROM ' . $this->table(AppUser::class) . ' WHERE id = ?', [$owner->getId()]));
    }

    public function testAnUninitialisedHostUserProxyIsWrittenByIdWithoutBeingLoaded(): void
    {
        $proxy = $this->host->getReference(AppUser::class, $this->owner->getId());
        self::assertInstanceOf(AppUser::class, $proxy);
        self::assertTrue($this->host->getUnitOfWork()->isUninitializedObject($proxy));

        $board = $this->creator()->create(new TaskBoardFormData('Proxy', 'proxy'), $proxy);

        self::assertSame(1, $this->rows(TaskBoard::class, 'id = ? AND creator_id = ?', [$board->getId(), $this->owner->getId()]));
        self::assertTrue($this->host->contains($proxy), 'the application keeps its own proxy');
    }

    public function testAHostUserNotFlushedYetFailsBeforeAnythingIsWritten(): void
    {
        $pending = new AppUser('pending@example.com');
        $this->host->persist($pending);

        try {
            $this->creator()->create(new TaskBoardFormData('Pending', 'pending'), $pending);
            self::fail('an unsaved user is refused');
        } catch (LogicException $failure) {
            self::assertStringContainsString('is not saved yet', $failure->getMessage());
            self::assertStringContainsString('Flush it first', $failure->getMessage());
        }

        self::assertSame(0, $this->rows(TaskBoard::class, 'slug = ?', ['pending']), 'nothing written');
        self::assertTrue($this->host->getUnitOfWork()->isScheduledForInsert($pending), 'the application keeps its scheduled insert');
        self::assertSame(0, $this->rows(AppUser::class, 'email = ?', ['pending@example.com']));
    }

    public function testATransientUserFailsClearly(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('has no identifier');

        $this->creator()->create(new TaskBoardFormData('Transient', 'transient'), new AppUser('nobody@example.com'));
    }

    public function testAnObjectOfTheWrongClassOnAToOneFailsClearly(): void
    {
        $project = new HostProject();
        $this->host->persist($project);
        $this->host->flush();
        $board = new TaskBoard('Wrong', 'wrong', null);
        $this->privateManager()->getClassMetadata(TaskBoard::class)->setFieldValue($board, 'creator', $project);
        $this->privateManager()->persist($board);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('expects a ' . AppUser::class);

        $this->privateManager()->flush();
    }

    public function testATaskTheApplicationLoadedIsReResolvedOnANewTaskBoardRow(): void
    {
        $task     = $this->task($this->board(), 'Loaded by the host');
        $hostTask = $this->host->find(Task::class, $task->getId());
        self::assertInstanceOf(Task::class, $hostTask);
        self::assertNotSame($task, $hostTask);
        $owner = $this->host->find(AppUser::class, $this->owner->getId());
        self::assertInstanceOf(AppUser::class, $owner);

        $comments = self::getContainer()->get(TaskCommentManagerInterface::class);
        self::assertInstanceOf(TaskCommentManagerInterface::class, $comments);
        $comment = $comments->add($hostTask, $owner, 'Through the host instance');

        self::assertSame(1, $this->rows(TaskComment::class, 'id = ? AND task_id = ?', [$comment->getId(), $task->getId()]));
        self::assertSame(1, $this->rows(Task::class, 'title = ?', ['Loaded by the host']), 'no duplicate task');
    }

    public function testPersistingATaskTheApplicationHoldsIsRefused(): void
    {
        $task     = $this->task($this->board(), 'Held by the host');
        $hostTask = $this->host->find(Task::class, $task->getId());
        self::assertInstanceOf(Task::class, $hostTask);

        try {
            $this->privateManager()->persist($hostTask);
            self::fail('a TaskBoard row the application holds is not inserted again');
        } catch (LogicException $failure) {
            self::assertStringContainsString('was loaded through the application\'s entity manager "default"', $failure->getMessage());
        }
        self::assertSame(1, $this->rows(Task::class, 'title = ?', ['Held by the host']));
    }

    public function testAnApplicationEntityReferencingATaskByIdIsFlushedByTheApplication(): void
    {
        $task          = $this->task($this->board(), 'Referenced');
        $project       = new HostProject();
        $project->task = $this->host->getReference(Task::class, $task->getId());
        $this->host->persist($project);
        $this->host->flush();

        self::assertSame(1, $this->rows(HostProject::class, 'id = ? AND task_id = ?', [$project->id, $task->getId()]));
    }

    /**
     * Since 3.0.0 (audit ZAS-1 / ZAS-2): each write service reads a board or task the application's manager holds again
     * by id at its entry. TaskBoard's row is written once; the application's instance and its collections are never
     * changed, so the application's next flush neither fails ("A new entity was found through ...") nor writes a row
     * TaskBoard wrote or refused, and the application's own pending change is still written by it.
     *
     * @return iterable<string, array{string}>
     */
    public static function writesOnHostHeldRows(): iterable
    {
        yield 'add a column' => ['column'];
        yield 'add a member' => ['member'];
        yield 'log time' => ['time'];
        yield 'attach a link' => ['link'];
        yield 'add a dependency' => ['dependency'];
        yield 'update (history)' => ['history'];
    }

    #[DataProvider('writesOnHostHeldRows')]
    public function testAWriteOnARowTheApplicationHoldsLeavesTheApplicationsInstanceAlone(string $write): void
    {
        $board    = $this->board();
        $task     = $this->task($board, 'Held');
        $other    = $this->task($board, 'Other');
        $hostTask = $this->host->find(Task::class, $task->getId());
        $owner    = $this->host->find(AppUser::class, $this->owner->getId());
        self::assertInstanceOf(Task::class, $hostTask);
        self::assertInstanceOf(AppUser::class, $owner);
        $hostBoard = $hostTask->getBoard();
        $before    = [count($hostBoard->getColumns()), count($hostTask->getMembers()), count($hostTask->getTimeEntries()), count($hostTask->getLinks()), count($hostTask->getOutgoingDependencies()), count($hostTask->getChangeHistory())];
        $owner->setDisplayName('Pending application change');

        [$table, $where] = match ($write) {
            'column'     => $this->write(BoardColumnManagerInterface::class, static fn (BoardColumnManagerInterface $s): object => $s->add($hostBoard, new BoardColumnFormData('Review'), $owner), BoardColumn::class, 'name = ?', ['Review']),
            'member'     => $this->write(TaskMemberAssigner::class, static fn (TaskMemberAssigner $s): object => $s->assign($hostTask, new TaskMemberFormData($owner, TaskMemberRole::Watcher), $owner), TaskMember::class, 'task_id = ?', [$task->getId()]),
            'time'       => $this->write(TaskTimeLoggerInterface::class, static fn (TaskTimeLoggerInterface $s): object => $s->log($hostTask, $owner, 30, new DateTimeImmutable('2026-11-20')), TaskTimeEntry::class, 'task_id = ?', [$task->getId()]),
            'link'       => $this->write(TaskLinkAttacher::class, static fn (TaskLinkAttacher $s): object => $s->attach($hostTask, new TaskLinkFormData(TaskLinkType::Url, 'https://example.com/spec'), $owner), TaskLink::class, 'task_id = ?', [$task->getId()]),
            'dependency' => $this->write(TaskDependencyManager::class, fn (TaskDependencyManager $s): object => $s->add($hostTask, $this->host->find(Task::class, $other->getId()) ?? $other, TaskDependencyType::Blocks, $owner), TaskDependency::class, 'source_task_id = ?', [$task->getId()]),
            'history'    => $this->write(TaskManagerInterface::class, static function (TaskManagerInterface $s) use ($hostTask, $owner): object {
                $s->update($hostTask, new TaskFormData('Renamed'), $owner);

                return $hostTask;
            }, TaskChangeHistory::class, 'task_id = ? AND change_type = ?', [$task->getId(), 'title']),
        };

        self::assertSame(1, $this->rows($table, $where[0], $where[1]), 'TaskBoard wrote its row once');
        self::assertSame($before, [count($hostBoard->getColumns()), count($hostTask->getMembers()), count($hostTask->getTimeEntries()), count($hostTask->getLinks()), count($hostTask->getOutgoingDependencies()), count($hostTask->getChangeHistory())], 'the application\'s instance is unchanged');
        self::assertSame('Held', $hostTask->getTitle());

        $this->host->flush();
        self::assertSame(1, $this->rows($table, $where[0], $where[1]), 'the application\'s flush wrote no TaskBoard row');
        self::assertSame('Pending application change', $this->connectionValue('SELECT display_name FROM ' . $this->table(AppUser::class) . ' WHERE id = ?', [$owner->getId()]));
    }

    /** Since 3.0.0 (audit ZAS-V1): renaming a board the application holds writes TaskBoard's copy only. */
    public function testRenamingABoardTheApplicationHoldsLeavesTheApplicationsInstanceAlone(): void
    {
        $board     = $this->board('Held board');
        $hostBoard = $this->host->find(TaskBoard::class, $board->getId());
        self::assertInstanceOf(TaskBoard::class, $hostBoard);

        $editor = self::getContainer()->get(TaskBoardEditorInterface::class);
        self::assertInstanceOf(TaskBoardEditorInterface::class, $editor);
        $editor->update($hostBoard, 'Renamed board', 'New text', $this->owner);

        self::assertSame('Held board', $hostBoard->getName());
        self::assertSame('Renamed board', $this->connectionValue('SELECT name FROM ' . $this->table(TaskBoard::class) . ' WHERE id = ?', [$board->getId()]));
        $this->host->flush();
        self::assertSame('Renamed board', $this->connectionValue('SELECT name FROM ' . $this->table(TaskBoard::class) . ' WHERE id = ?', [$board->getId()]));
    }

    /** Since 3.0.0 (audit ZAS-V1): the billable flag of a task the application holds is set on TaskBoard's copy. */
    public function testChangingBillableOnATaskTheApplicationHoldsLeavesTheApplicationsInstanceAlone(): void
    {
        $task   = $this->task($this->board(), 'Billed');
        $logger = self::getContainer()->get(TaskTimeLoggerInterface::class);
        self::assertInstanceOf(TaskTimeLoggerInterface::class, $logger);
        $entryId  = $logger->log($task, $this->owner, 45, new DateTimeImmutable('2026-11-20'))->getId();
        $hostTask = $this->host->find(Task::class, $task->getId());
        self::assertInstanceOf(Task::class, $hostTask);
        $history = count($hostTask->getChangeHistory());
        $initial = $hostTask->getTimeEntries()->first()?->getBillable();
        $wanted  = $initial !== true;

        $billable = self::getContainer()->get(TaskTimeBillableService::class);
        self::assertInstanceOf(TaskTimeBillableService::class, $billable);
        $billable->change($hostTask, $entryId, $this->owner, $wanted);

        self::assertSame($history, count($hostTask->getChangeHistory()), "the application's instance is unchanged");
        self::assertSame($initial, $hostTask->getTimeEntries()->first()?->getBillable());
        self::assertSame(1, $this->rows(TaskChangeHistory::class, 'task_id = ? AND change_type = ?', [$task->getId(), 'time_billable']));
        self::assertSame($wanted, (bool) $this->connectionValue('SELECT billable FROM ' . $this->table(TaskTimeEntry::class) . ' WHERE id = ?', [$entryId]));
        $this->host->flush();
        self::assertSame(1, $this->rows(TaskChangeHistory::class, 'task_id = ? AND change_type = ?', [$task->getId(), 'time_billable']));
    }

    /** Since 3.0.0 (audit ZAS-V1): a column set applied to a board the application holds adds TaskBoard's columns once. */
    public function testApplyingAColumnSetToABoardTheApplicationHoldsLeavesTheApplicationsInstanceAlone(): void
    {
        $source  = $this->board('Source', ['Backlog', 'Review']);
        $target  = $this->board('Target', ['To do']);
        $manager = self::getContainer()->get(ColumnSetManager::class);
        self::assertInstanceOf(ColumnSetManager::class, $manager);
        $set       = $manager->saveFromBoard($this->em->find(TaskBoard::class, $source->getId()) ?? $source, 'Two columns', $this->owner);
        $hostBoard = $this->host->find(TaskBoard::class, $target->getId());
        self::assertInstanceOf(TaskBoard::class, $hostBoard);
        self::assertCount(1, $hostBoard->getColumns());

        self::assertCount(2, $manager->applyToBoard($set, $hostBoard, $this->owner));

        self::assertCount(1, $hostBoard->getColumns(), 'the application\'s instance is unchanged');
        self::assertSame(3, $this->rows(BoardColumn::class, 'board_id = ?', [$target->getId()]));
        $this->host->flush();
        self::assertSame(3, $this->rows(BoardColumn::class, 'board_id = ?', [$target->getId()]));
    }

    /**
     * Since 3.0.0 (audit ZAS-V1): a task the application holds is erased through TaskBoard's copy, and its
     * {@see TaskErasedEvent} (whose file refs the host deletes) fires only once the row is gone.
     */
    public function testErasingATaskTheApplicationHoldsAnnouncesItOnlyOnceItIsGone(): void
    {
        $task     = $this->task($this->board(), 'Erased');
        $hostTask = $this->host->find(Task::class, $task->getId());
        self::assertInstanceOf(Task::class, $hostTask);
        $seen = [];
        $this->dispatcher()->addListener(TaskBoardEvents::TASK_ERASED, function (TaskErasedEvent $event) use (&$seen, $task): void {
            $seen[] = [$event->taskId, $this->rows(Task::class, 'id = ?', [$task->getId()])];
        });

        self::assertSame(1, $this->eraser()->eraseTask($hostTask, $this->owner));

        self::assertSame([[$task->getId(), 0]], $seen, 'announced once, after the row was deleted');
        self::assertSame('Erased', $hostTask->getTitle());
    }

    /** Since 3.0.0 (audit ZAS-V1): an erase of a task the application holds whose flush fails deletes nothing and announces nothing. */
    public function testAFailedEraseOfATaskTheApplicationHoldsDeletesNothingAndAnnouncesNothing(): void
    {
        $task     = $this->task($this->board(), 'Kept');
        $hostTask = $this->host->find(Task::class, $task->getId());
        self::assertInstanceOf(Task::class, $hostTask);
        $fired = 0;
        $this->dispatcher()->addListener(TaskBoardEvents::TASK_ERASED, static function () use (&$fired): void {
            ++$fired;
        });
        $this->privateManager()->getEventManager()->addEventListener(Events::onFlush, new class {
            public function onFlush(): never
            {
                throw new RuntimeException('flush failed');
            }
        });

        try {
            $this->eraser()->eraseTask($hostTask, $this->owner);
            self::fail('The erase should have failed.');
        } catch (RuntimeException $exception) {
            self::assertSame('flush failed', $exception->getMessage());
        }

        self::assertSame(0, $fired, 'no TaskErasedEvent: the host keeps the files of a task that still exists');
        self::assertSame(1, $this->rows(Task::class, 'id = ?', [$task->getId()]));
    }

    /** Since 3.0.0 (audit ZAS-V2): a TaskBoard row the application persisted but did not flush is refused as not saved yet. */
    public function testARowTheApplicationHasNotSavedYetIsRefusedAsNotSaved(): void
    {
        $board = $this->host->find(TaskBoard::class, $this->board()->getId());
        self::assertInstanceOf(TaskBoard::class, $board);
        $column = new BoardColumn($board, 'Pending', 9);
        $this->host->persist($column);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('not saved yet');

        $this->privateManager()->attach($column);
    }

    /** Since 3.0.0 (audit ZAS-X2): an import into a board the application holds writes TaskBoard's rows once. */
    public function testImportingIntoABoardTheApplicationHoldsLeavesTheApplicationsInstanceAlone(): void
    {
        $board     = $this->board('Import target');
        $hostBoard = $this->host->find(TaskBoard::class, $board->getId());
        self::assertInstanceOf(TaskBoard::class, $hostBoard);
        self::assertCount(0, $hostBoard->getTasks());
        $importer = self::getContainer()->get(TaskImportOrchestrator::class);
        self::assertInstanceOf(TaskImportOrchestrator::class, $importer);

        $importer->import($hostBoard, TaskImportSource::ClickUpCsv, "Task ID,Task Name,Status\nt1,Imported one,To do", 'one.csv', $this->owner);

        self::assertCount(0, $hostBoard->getTasks(), 'the application\'s instance is unchanged');
        self::assertSame(1, $this->rows(Task::class, 'board_id = ? AND title = ?', [$board->getId(), 'Imported one']));
        $this->host->flush();
        self::assertSame(1, $this->rows(Task::class, 'board_id = ? AND title = ?', [$board->getId(), 'Imported one']));
    }

    /** A row the application's manager holds that no longer exists is refused clearly. */
    public function testARowTheApplicationHoldsThatIsGoneIsRefused(): void
    {
        $task     = $this->task($this->board(), 'Gone');
        $hostTask = $this->host->find(Task::class, $task->getId());
        self::assertInstanceOf(Task::class, $hostTask);
        $this->host->getConnection()->executeStatement('DELETE FROM ' . $this->table(Task::class) . ' WHERE id = ?', [$task->getId()]);
        $this->privateManager()->clear();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('no longer exists');

        $this->privateManager()->attach($hostTask);
    }

    /**
     * @template S of object
     *
     * @param class-string<S> $service
     * @param callable(S): object $call
     * @param class-string $rowClass
     * @param list<mixed> $parameters
     *
     * @return array{class-string, array{string, list<mixed>}}
     */
    private function write(string $service, callable $call, string $rowClass, string $where, array $parameters): array
    {
        $instance = self::getContainer()->get($service);
        self::assertInstanceOf($service, $instance);
        $call($instance);

        return [$rowClass, [$where, $parameters]];
    }

    private function creator(): TaskBoardCreatorInterface
    {
        $creator = self::getContainer()->get(TaskBoardCreatorInterface::class);
        self::assertInstanceOf(TaskBoardCreatorInterface::class, $creator);

        return $creator;
    }

    private function eraser(): TaskBoardEraserInterface
    {
        $eraser = self::getContainer()->get(TaskBoardEraserInterface::class);
        self::assertInstanceOf(TaskBoardEraserInterface::class, $eraser);

        return $eraser;
    }

    private function dispatcher(): EventDispatcherInterface
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return $dispatcher;
    }

    private function privateManager(): TaskBoardEntityManager
    {
        $private = self::getContainer()->get('app.task_board_em');
        self::assertInstanceOf(TaskBoardEntityManager::class, $private);

        return $private;
    }

    /**
     * @param class-string $class
     */
    private function table(string $class): string
    {
        return $this->host->getClassMetadata($class)->getTableName();
    }

    /**
     * @param list<mixed> $parameters
     */
    private function connectionValue(string $sql, array $parameters): mixed
    {
        $value = $this->host->getConnection()->fetchOne($sql, $parameters);

        return $value === false ? null : $value;
    }

    /**
     * @param class-string $class
     * @param list<mixed> $parameters
     */
    private function rows(string $class, string $where, array $parameters): int
    {
        return (int) $this->host->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $this->table($class) . ' WHERE ' . $where, $parameters);
    }
}
