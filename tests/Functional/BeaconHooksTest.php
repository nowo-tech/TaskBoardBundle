<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional;

use Nowo\TaskBoardBundle\Dto\BoardColumnFormData;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Event\ColumnCreatedEvent;
use Nowo\TaskBoardBundle\Event\ColumnDeleteCheckEvent;
use Nowo\TaskBoardBundle\Event\ColumnDeletedEvent;
use Nowo\TaskBoardBundle\Event\ColumnsReorderedEvent;
use Nowo\TaskBoardBundle\Event\ColumnUpdatedEvent;
use Nowo\TaskBoardBundle\Event\TaskArchivedEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Event\TaskCommentDeletedEvent;
use Nowo\TaskBoardBundle\Event\TaskRestoredEvent;
use Nowo\TaskBoardBundle\Import\TaskImportOrchestrator;
use Nowo\TaskBoardBundle\Import\TaskImportSource;
use Nowo\TaskBoardBundle\Service\BoardColumnManager;
use Nowo\TaskBoardBundle\Service\BoardColumnManagerInterface;
use Nowo\TaskBoardBundle\Service\TaskBoardCreator;
use Nowo\TaskBoardBundle\Service\TaskBoardCreatorInterface;
use Nowo\TaskBoardBundle\Service\TaskBoardEditor;
use Nowo\TaskBoardBundle\Service\TaskBoardEditorInterface;
use Nowo\TaskBoardBundle\Service\TaskChangeContext;
use Nowo\TaskBoardBundle\Service\TaskCommentManager;
use Nowo\TaskBoardBundle\Service\TaskCommentManagerInterface;
use Nowo\TaskBoardBundle\Service\TaskEventDeferral;
use Nowo\TaskBoardBundle\Service\TaskManager;
use Nowo\TaskBoardBundle\Service\TaskManagerInterface;
use Nowo\TaskBoardBundle\Service\TaskTimeLogger;
use Nowo\TaskBoardBundle\Service\TaskTimeLoggerInterface;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\EventDispatcher\Event;

use function array_map;
use function array_values;

/**
 * Since 2.10.0 (spec 216): the hooks Symfony Beacon's automations need — a comment's cause and its label, the warnings of
 * "Delete column", and the id-only events of archive / restore, comment deletion and column changes, after the write.
 */
final class BeaconHooksTest extends FunctionalTestCase
{
    /** @var list<array{string, Event}> */
    private array $events = [];

    public function testACommentAddedByARuleKeepsItsCauseAndShowsTheLabel(): void
    {
        self::$taskBoardConfig = ['comments' => ['cause_labeler' => 'app.cause_labeler']];
        self::ensureKernelShutdown();
        $this->setUp();
        $task     = $this->task($this->board(), 'Review');
        $comments = $this->service(TaskCommentManager::class);
        $ruled    = $this->service(TaskChangeContext::class)->run('rule:42', fn (): TaskComment => $comments->add($task, $this->owner, 'Moved by a rule'));
        $other    = $this->service(TaskChangeContext::class)->run('import:1', fn (): TaskComment => $comments->add($task, $this->owner, 'Imported'));
        $own      = $comments->add($task, $this->owner, 'My own words');
        self::assertSame('rule:42', $ruled->getCause());
        self::assertSame('import:1', $other->getCause());
        self::assertNull($own->getCause());

        $this->em->clear();
        $reloaded = $this->em->find(TaskComment::class, $ruled->getId());
        self::assertSame('rule:42', $reloaded?->getCause(), 'stored');

        $this->login($this->owner);
        $crawler = $this->client->request('GET', '/tools/task-board/task/' . $task->getId());
        self::assertResponseIsSuccessful();
        self::assertSame(['(rule: <42>)'], array_values($crawler->filter('[data-testid="nowo-task-comment-cause"]')->each(static fn ($node): string => $node->text())), 'the import cause and the own comment show nothing');
        self::assertStringContainsString('(rule: &lt;42&gt;)', (string) $this->client->getResponse()->getContent());
    }

    public function testWithoutACommentLabelerNoCauseIsShown(): void
    {
        $task = $this->task($this->board(), 'Review');
        $this->service(TaskChangeContext::class)->run('rule:42', fn (): TaskComment => $this->service(TaskCommentManager::class)->add($task, $this->owner, 'By a rule'));
        $this->login($this->owner);

        $this->client->request('GET', '/tools/task-board/task/' . $task->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-testid="nowo-task-comment-cause"]');
    }

    public function testTheDeleteDialogShowsTheHostsWarningsOfEachColumn(): void
    {
        $board   = $this->board('Rules', ['To do', 'Review', 'Done']);
        $review  = $board->getColumns()->get(1);
        $checked = [];
        $this->dispatcher()->addListener(TaskBoardEvents::COLUMN_DELETE_CHECK, static function (ColumnDeleteCheckEvent $event) use ($review, &$checked): void {
            $checked[] = $event->columnIds;
            $event->addWarning((string) $review?->getId(), '2 automation rules use this column; they will pause.');
            $event->addWarning((string) $review?->getId(), '<b>Second</b> line');
            $event->addWarning('not-a-column', 'ignored');
        });
        $this->login($this->owner);

        $crawler = $this->client->request('GET', '/tools/task-board/board/' . $board->getId() . '?view=board');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $checked, 'asked once per page');
        self::assertCount(3, $checked[0]);
        $warnings = $crawler->filter('[data-column-delete-warning]');
        self::assertCount(1, $warnings);
        self::assertSame((string) $review?->getId(), $warnings->attr('data-column-id'));
        self::assertSame("2 automation rules use this column; they will pause.\n<b>Second</b> line", $warnings->attr('data-column-delete-warning'));
        self::assertSelectorExists('[data-testid="nowo-task-column-delete-warning"][hidden]');
        self::assertStringNotContainsString('<b>Second</b>', (string) $this->client->getResponse()->getContent());
    }

    public function testABoardWithOneColumnAsksNothing(): void
    {
        $board = $this->board('Single', ['Only']);
        $asked = 0;
        $this->dispatcher()->addListener(TaskBoardEvents::COLUMN_DELETE_CHECK, static function () use (&$asked): void {
            ++$asked;
        });
        $this->login($this->owner);

        $this->client->request('GET', '/tools/task-board/board/' . $board->getId() . '?view=board');
        self::assertResponseIsSuccessful();
        self::assertSame(0, $asked);
        self::assertSelectorNotExists('[data-column-delete-warning]');
    }

    public function testArchiveRestoreAndCommentDeletionDispatchIdOnlyEvents(): void
    {
        $board = $this->board('Events', tenantKey: 'acme');
        $task  = $this->task($board, 'Archive me');
        $this->listen(TaskBoardEvents::TASK_ARCHIVED, TaskBoardEvents::TASK_RESTORED, TaskBoardEvents::TASK_COMMENT_DELETED);
        $tasks = $this->service(TaskManager::class);

        self::assertTrue($this->service(TaskChangeContext::class)->run('rule:5', fn (): bool => $tasks->archive($task, $this->owner)));
        self::assertFalse($tasks->archive($task, $this->owner), 'already archived: no event');
        self::assertTrue($tasks->restore($task, $this->owner));
        $comment = $this->service(TaskCommentManager::class)->add($task, $this->owner, 'Bye');
        $this->service(TaskCommentManager::class)->delete($comment, $this->owner);

        self::assertSame([TaskBoardEvents::TASK_ARCHIVED, TaskBoardEvents::TASK_RESTORED, TaskBoardEvents::TASK_COMMENT_DELETED], array_map(static fn (array $entry): string => $entry[0], $this->events));
        $archived = $this->events[0][1];
        self::assertInstanceOf(TaskArchivedEvent::class, $archived);
        self::assertSame([$task->getId(), $board->getId(), 'acme', (string) $this->owner->getId(), 'rule:5'], [$archived->taskId, $archived->boardId, $archived->tenantKey, $archived->actorId, $archived->cause]);
        $restored = $this->events[1][1];
        self::assertInstanceOf(TaskRestoredEvent::class, $restored);
        self::assertNull($restored->cause);
        $deleted = $this->events[2][1];
        self::assertInstanceOf(TaskCommentDeletedEvent::class, $deleted);
        self::assertSame([$comment->getId(), $task->getId(), $board->getId(), 'acme', (string) $this->owner->getId()], [$deleted->commentId, $deleted->taskId, $deleted->boardId, $deleted->tenantKey, $deleted->actorId]);
    }

    public function testColumnChangesDispatchAfterTheWriteAndWaitForADeferredCommit(): void
    {
        $board = $this->board('Columns', ['To do', 'Doing', 'Done']);
        $task  = $this->task($board, 'Moves', 1);
        $this->em->refresh($board);
        $this->listen(TaskBoardEvents::COLUMN_CREATED, TaskBoardEvents::COLUMN_UPDATED, TaskBoardEvents::COLUMN_DELETED, TaskBoardEvents::COLUMNS_REORDERED);
        $columns = $this->service(BoardColumnManager::class);

        $review = $this->service(TaskEventDeferral::class)->defer(function () use ($columns, $board) {
            $column = $columns->add($board, new BoardColumnFormData(name: 'Review'), $this->owner);
            self::assertSame([], $this->events, 'held until the deferred write returns');

            return $column;
        });
        self::assertCount(1, $this->events);
        $created = $this->events[0][1];
        self::assertInstanceOf(ColumnCreatedEvent::class, $created);
        self::assertSame([$review->getId(), 'Review', 3, (string) $this->owner->getId(), null], [$created->columnId, $created->name, $created->position, $created->actorId, $created->tenantKey]);

        $columns->update($review, new BoardColumnFormData(name: 'Review', color: null, icon: null), $this->owner);
        self::assertCount(1, $this->events, 'nothing changed');
        $columns->update($review, new BoardColumnFormData(name: 'QA', color: '#123456'), $this->owner);
        $updated = $this->events[1][1];
        self::assertInstanceOf(ColumnUpdatedEvent::class, $updated);
        self::assertSame(['QA', 'Review', ['name', 'color']], [$updated->name, $updated->previousName, $updated->changedFields]);
        self::assertTrue($updated->isRenamed());

        $ids = array_map(static fn ($column): string => $column->getId(), $board->getColumns()->toArray());
        $columns->reorder($board, $ids, $this->owner);
        self::assertCount(2, $this->events, 'the same order');
        $columns->reorder($board, [$ids[3], $ids[0], $ids[1], $ids[2]], $this->owner);
        $reordered = $this->events[2][1];
        self::assertInstanceOf(ColumnsReorderedEvent::class, $reordered);
        self::assertSame([$ids[3], $ids[0], $ids[1], $ids[2]], $reordered->columnIds);

        $doing = $board->getColumns()->get(1);
        self::assertNotNull($doing);
        self::assertTrue($columns->delete($doing, $this->owner));
        $deleted = $this->events[3][1];
        self::assertInstanceOf(ColumnDeletedEvent::class, $deleted);
        self::assertSame([$doing->getId(), 'Doing', $ids[3], [$task->getId()]], [$deleted->columnId, $deleted->name, $deleted->targetColumnId, $deleted->movedTaskIds]);
    }

    public function testTheCommandServicesArePublishedBehindInterfaces(): void
    {
        foreach ([
            TaskManagerInterface::class        => TaskManager::class,
            TaskBoardCreatorInterface::class   => TaskBoardCreator::class,
            TaskBoardEditorInterface::class    => TaskBoardEditor::class,
            TaskCommentManagerInterface::class => TaskCommentManager::class,
            TaskTimeLoggerInterface::class     => TaskTimeLogger::class,
            BoardColumnManagerInterface::class => BoardColumnManager::class,
        ] as $port => $class) {
            self::assertInstanceOf($class, self::getContainer()->get($port), $port);
        }

        // A host's application service depends on the port and runs on the bundle's class through it.
        $board  = $this->board('Port');
        $editor = $this->service(TaskBoardEditorInterface::class);
        $editor->update($board, 'Renamed through the port', null, $this->owner);
        self::assertSame('Renamed through the port', $board->getName());
    }

    public function testAFailingColumnListenerDoesNotFailACommittedImport(): void
    {
        $board = $this->board('Import', ['To do', 'Done']);
        $this->listen(TaskBoardEvents::COLUMN_CREATED);
        $this->dispatcher()->addListener(TaskBoardEvents::COLUMN_CREATED, static function (ColumnCreatedEvent $event): void {
            throw new RuntimeException('host listener ' . $event->name);
        });

        // Audit JY-L1: both new columns are created inside the import's transaction; their events wait for its commit.
        $result = $this->service(TaskImportOrchestrator::class)->import($board, TaskImportSource::ClickUpCsv, "Task ID,Task Name,Status\nt1,One,Review\nt2,Two,QA", 'cols.csv', $this->owner);

        self::assertFalse($result->hasErrors(), 'the rows are saved: the import is not reported as failed');
        self::assertSame(2, $result->created);
        self::assertSame(['Review', 'QA'], array_map(static fn (array $entry): string => $entry[1] instanceof ColumnCreatedEvent ? $entry[1]->name : '', $this->events), 'every held event is still sent');
        $this->em->clear();
        self::assertCount(2, $this->em->getRepository(Task::class)->findBy(['board' => $board->getId()]));
    }

    private function listen(string ...$names): void
    {
        foreach ($names as $name) {
            $this->dispatcher()->addListener($name, function (Event $event) use ($name): void {
                $this->events[] = [$name, $event];
            });
        }
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        $service = self::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }

    private function dispatcher(): EventDispatcherInterface
    {
        $service = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $service);

        return $service;
    }
}
