<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Dto\TaskFormData;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskChangeHistory;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Entity\TaskTimeEntry;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Enum\TaskDependencyType;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Event\BoardAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\BoardUpdatedEvent;
use Nowo\TaskBoardBundle\Event\TaskAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Event\TaskErasedEvent;
use Nowo\TaskBoardBundle\Repository\TaskBoardRepositoryInterface;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Service\TaskBoardEditor;
use Nowo\TaskBoardBundle\Service\TaskBoardEraser;
use Nowo\TaskBoardBundle\Service\TaskCommentPolicy;
use Nowo\TaskBoardBundle\Service\TaskDependencyManager;
use Nowo\TaskBoardBundle\Service\TaskManager;
use Nowo\TaskBoardBundle\Service\TaskTimeLogger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Since 2.2.0 (spec 213): the services other write paths share with the UI, or that the UI does not have yet.
 */
final class SharedWriteServicesTest extends FunctionalTestCase
{
    public function testEraseTaskRemovesTheSubtreeLeafFirstAndKeepsEveryMinute(): void
    {
        $board      = $this->board();
        $parent     = $this->task($board, 'Parent');
        $child      = $this->task($board, 'Child', parent: $parent);
        $grandchild = $this->task($board, 'Grandchild', parent: $child);
        $sibling    = $this->task($board, 'Sibling');
        $logger     = $this->service(TaskTimeLogger::class);
        $onChild    = $logger->log($child, $this->owner, 30, new DateTimeImmutable('2026-11-04'))->getId();
        $onLeaf     = $logger->log($grandchild, $this->owner, 15, new DateTimeImmutable('2026-11-04'))->getId();
        $erased     = [];
        $this->dispatcher()->addListener(TaskBoardEvents::TASK_ERASED, static function (TaskErasedEvent $event) use (&$erased): void {
            $erased[$event->title] = $event->manualTimeEntries;
        });
        $siblingId = $sibling->getId();

        self::assertSame(3, $this->eraser()->eraseTask($parent, $this->owner));

        self::assertSame(['Grandchild' => 1, 'Child' => 1, 'Parent' => 0], $erased);
        $this->em->clear();
        self::assertSame(1, $this->em->getRepository(Task::class)->count([]));
        self::assertInstanceOf(Task::class, $this->em->find(Task::class, $siblingId));
        foreach ([$onChild => [30, 'Child'], $onLeaf => [15, 'Grandchild']] as $id => [$minutes, $title]) {
            $entry = $this->em->find(TaskTimeEntry::class, $id);
            self::assertInstanceOf(TaskTimeEntry::class, $entry, 'tracked time is never deleted');
            self::assertTrue($entry->isDetached());
            self::assertSame($minutes, $entry->getMinutes());
            self::assertSame($title, $entry->getTaskTitleSnapshot());
        }
    }

    public function testEraseTaskNeedsTheBoardManageDecision(): void
    {
        $task = $this->task($this->board(), 'Stays');
        $this->dispatcher()->addListener(TaskBoardEvents::BOARD_ACCESS_CHECK, static function (BoardAccessCheckEvent $event): void {
            if ($event->getAction() === BoardAccessCheckEvent::ACTION_MANAGE) {
                $event->deny();
            }
        });

        try {
            $this->eraser()->eraseTask($task, $this->owner);
            self::fail('AccessDeniedException expected');
        } catch (AccessDeniedException) {
        }

        $this->em->clear();
        self::assertInstanceOf(Task::class, $this->em->find(Task::class, $task->getId()));
    }

    public function testRestoreAndArchiveRecordTheirHistoryAndAreIdempotent(): void
    {
        $task    = $this->task($this->board(), 'Back and forth');
        $manager = $this->service(TaskManager::class);

        self::assertFalse($manager->restore($task, $this->owner), 'not archived');
        self::assertTrue($manager->archive($task, $this->owner));
        self::assertTrue($task->isArchived());
        self::assertFalse($manager->archive($task, $this->owner), 'already archived');
        self::assertTrue($manager->restore($task, $this->owner));
        self::assertFalse($task->isArchived());

        $this->em->clear();
        $history = $this->em->getRepository(TaskChangeHistory::class);
        self::assertCount(1, $history->findBy(['changeType' => TaskChangeType::Archived, 'newValue' => 'manual']));
        self::assertCount(1, $history->findBy(['changeType' => TaskChangeType::Restored, 'newValue' => 'manual']));
    }

    public function testTheCommentPolicyAnswersEachRole(): void
    {
        $task     = $this->task($this->board(), 'Discussed');
        $reader   = $this->user('reader@example.com');
        $readerId = $reader->getId();
        $this->dispatcher()->addListener(TaskBoardEvents::TASK_ACCESS_CHECK, static function (TaskAccessCheckEvent $event) use ($readerId): void {
            $subject = $event->getSubject();
            if (method_exists($subject, 'getId') && $subject->getId() === $readerId) {
                $event->grant();
                $event->markReadOnly();
            }
        });
        $checker  = $this->service(TaskBoardResourceAccessChecker::class);
        $open     = new TaskCommentPolicy($checker, true);
        $closed   = new TaskCommentPolicy($checker, false);
        $byReader = new TaskComment($task, $reader, 'Mine');
        $byOwner  = new TaskComment($task, $this->owner, 'Theirs');

        self::assertTrue($open->mayComment($reader, $task));
        self::assertFalse($closed->mayComment($reader, $task));
        self::assertTrue($closed->mayComment($this->owner, $task), 'an editor always may');

        self::assertTrue($open->mayEdit($reader, $task, $byReader));
        self::assertFalse($closed->mayEdit($reader, $task, $byReader), 'the author, while they may comment');
        self::assertFalse($open->mayEdit($this->owner, $task, $byReader), 'an editor does not edit someone else');

        self::assertTrue($closed->mayDelete($reader, $task, $byReader), 'the author deletes');
        self::assertFalse($open->mayDelete($reader, $task, $byOwner));
        self::assertTrue($open->mayDelete($this->owner, $task, $byReader), 'an editor deletes');
    }

    public function testTheBoardEditorChangesWhatChangedAndKeepsTheSlug(): void
    {
        $board  = $this->board();
        $slug   = $board->getSlug();
        $events = [];
        $editor = new TaskBoardEditor($this->service(TaskBoardRepositoryInterface::class), $this->dispatcher());
        $this->dispatcher()->addListener(TaskBoardEvents::BOARD_UPDATED, static function (BoardUpdatedEvent $event) use (&$events): void {
            $events[] = $event->changed;
        });

        $editor->update($board, 'Renamed', null, $this->owner);
        $editor->update($board, 'Renamed', 'Now with text', $this->owner);
        $editor->update($board, 'Renamed', 'Now with text', $this->owner);

        self::assertSame([['name'], ['description']], $events);
        $this->em->clear();
        $saved = $this->service(TaskBoardRepositoryInterface::class)->findById($board->getId());
        self::assertSame('Renamed', $saved?->getName());
        self::assertSame('Now with text', $saved?->getDescription());
        self::assertSame($slug, $saved?->getSlug());
    }

    public function testFindBetweenSeesBothDirectionsAndNothingElse(): void
    {
        $board   = $this->board();
        $a       = $this->task($board, 'A');
        $b       = $this->task($board, 'B');
        $c       = $this->task($board, 'C');
        $manager = $this->service(TaskDependencyManager::class);
        $edge    = $manager->add($a, $b, TaskDependencyType::Blocks, $this->owner);

        self::assertSame([$edge->getId()], array_map(static fn ($d): string => $d->getId(), $manager->findBetween($a, $b)));
        self::assertSame([$edge->getId()], array_map(static fn ($d): string => $d->getId(), $manager->findBetween($b, $a)));
        self::assertSame([], $manager->findBetween($a, $c));
    }

    public function testFormDataOfATaskIsTheTaskAsItStands(): void
    {
        $task = $this->task($this->board(), 'Full');
        $due  = new DateTimeImmutable('2026-12-01');
        $task->setDescription('Text')->setPriority(TaskPriority::High)->setEstimatedMinutes(90)->setDueAt($due)->setTags(['api'])->setColor('#a1b2c3')->setStartAt($due);

        $data = TaskFormData::fromTask($task);

        self::assertSame('Full', $data->title);
        self::assertSame('Text', $data->description);
        self::assertSame(TaskPriority::High, $data->priority);
        self::assertSame($task->getColumn()?->getId(), $data->columnId);
        self::assertSame(90, $data->estimatedMinutes);
        self::assertSame($due, $data->dueAt);
        self::assertSame(['api'], $data->tags);
        self::assertSame('#a1b2c3', $data->color);
        self::assertSame($due, $data->startAt);
    }

    private function eraser(): TaskBoardEraser
    {
        return $this->service('app.board_eraser');
    }

    private function dispatcher(): EventDispatcherInterface
    {
        return $this->service('event_dispatcher');
    }

    /**
     * @template T of object
     *
     * @param class-string<T>|string $id
     *
     * @return ($id is class-string<T> ? T : mixed)
     */
    private function service(string $id): mixed
    {
        return self::getContainer()->get($id);
    }
}
