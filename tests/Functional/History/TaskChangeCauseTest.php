<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\History;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\TaskBoardBundle\Dto\TaskFormData;
use Nowo\TaskBoardBundle\Dto\TaskMemberFormData;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskChangeHistory;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Event\TaskCommentEvent;
use Nowo\TaskBoardBundle\Event\TaskUpdatedEvent;
use Nowo\TaskBoardBundle\Service\TaskChangeContext;
use Nowo\TaskBoardBundle\Service\TaskCommentManager;
use Nowo\TaskBoardBundle\Service\TaskManager;
use Nowo\TaskBoardBundle\Service\TaskMemberAssigner;
use Nowo\TaskBoardBundle\Tests\Functional\FunctionalTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use function array_map;
use function array_values;

/**
 * Spec 216, FR-013: why a change happens is written on its history rows and readable by the listeners of its events;
 * TASK_UPDATED carries the old values; the history shows the cause through the host's labeler, and nothing without one.
 */
final class TaskChangeCauseTest extends FunctionalTestCase
{
    public function testEveryWriteInsideTheContextKeepsItsCause(): void
    {
        $board = $this->board();
        $task  = $this->task($board, 'Review');
        $other = $this->user('luis@example.com');
        $seen  = [];
        $this->dispatcher()->addListener(TaskBoardEvents::TASK_COMMENT, function (TaskCommentEvent $event) use (&$seen): void {
            $seen[] = $this->context()->current();
        });

        $this->context()->run('rule:7', function () use ($task, $other): void {
            $this->tasks()->moveToColumn($task, (string) $task->getBoard()->getColumns()->get(1)?->getId(), $this->owner);
            $this->members()->assign($task, new TaskMemberFormData($other, TaskMemberRole::Reviewer), $this->owner);
            $this->context()->run('rule:8', fn () => $this->tasks()->updatePriority($task, TaskPriority::High, $this->owner));
            $this->tasks()->addTag($task, 'review', $this->owner);
            $comments = self::getContainer()->get(TaskCommentManager::class);
            self::assertInstanceOf(TaskCommentManager::class, $comments);
            $comments->add($task, $this->owner, 'Assigned by a rule');
        });
        $this->tasks()->addTag($task, 'manual', $this->owner);

        $causes = [];
        foreach ($task->getChangeHistory() as $entry) {
            $causes[$entry->getChangeType()->value . ($entry->getNewValue() === '["manual","review"]' ? '+manual' : '')] = $entry->getCause();
        }
        self::assertSame('rule:7', $causes['column']);
        self::assertSame('rule:7', $causes['member_added']);
        self::assertSame('rule:8', $causes['priority'], 'the innermost cause');
        self::assertSame('rule:7', $causes['tags']);
        self::assertNull($causes['tags+manual'], 'outside the call');
        self::assertSame(['rule:7'], $seen, 'a comment\'s listeners read the cause');
        self::assertNull($this->context()->current());
    }

    public function testTheContextRefusesAnEmptyOrLongCauseAndResets(): void
    {
        $context = new TaskChangeContext();
        foreach (['', str_repeat('x', 192)] as $bad) {
            try {
                $context->run($bad, static fn (): null => null);
                self::fail('Refused');
            } catch (InvalidArgumentException) {
            }
        }
        self::assertSame(3, $context->run('a', static fn (): int => 3));
        $context->run('a', static function () use ($context): void {
            $context->reset();
            self::assertNull($context->current());
        });
    }

    public function testTaskUpdatedCarriesTheOldValues(): void
    {
        $task = $this->task($this->board(), 'Plan');
        $task->setTags(['a', 'b']);
        $this->em->flush();
        /** @var list<TaskUpdatedEvent> $events */
        $events = [];
        $this->dispatcher()->addListener(TaskBoardEvents::TASK_UPDATED, static function (TaskUpdatedEvent $event) use (&$events): void {
            $events[] = $event;
        });

        $data           = TaskFormData::fromTask($task);
        $data->priority = TaskPriority::Urgent;
        $data->tags     = ['b', 'c'];
        $this->tasks()->update($task, $data, $this->owner);
        self::assertSame(TaskPriority::Normal, $events[0]->getPreviousValue('priority'));
        self::assertSame(['c'], $events[0]->getAddedTags());
        self::assertSame(['a'], $events[0]->getRemovedTags());
        self::assertNull($events[0]->getPreviousValue('title'), 'not changed');

        $this->tasks()->updatePriority($task, TaskPriority::Low, $this->owner);
        self::assertSame(TaskPriority::Urgent, $events[1]->getPreviousValue('priority'));
        self::assertSame([], $events[1]->getAddedTags());

        $this->tasks()->reschedule($task, null, new DateTimeImmutable('2026-12-01'), $this->owner);
        self::assertSame(['dueAt'], $events[2]->getChangedFields());
        self::assertNull($events[2]->getPreviousValue('dueAt'));

        self::assertTrue($this->tasks()->addTag($task, ' d ', $this->owner));
        self::assertSame(['d'], $events[3]->getAddedTags());
        self::assertSame(['b', 'c', 'd'], $task->getTags());
        self::assertFalse($this->tasks()->addTag($task, 'd', $this->owner));
        self::assertFalse($this->tasks()->addTag($task, '  ', $this->owner));
        self::assertCount(4, $events);
        self::assertContains(TaskChangeType::Tags, array_map(static fn (TaskChangeHistory $entry): TaskChangeType => $entry->getChangeType(), $task->getChangeHistory()->toArray()));
        self::assertInstanceOf(BoardColumn::class, $task->getColumn());
    }

    public function testWithoutALabelerNoCauseIsShown(): void
    {
        $task = $this->task($this->board(), 'Labelled');
        $this->context()->run('rule:42', fn () => $this->tasks()->updatePriority($task, TaskPriority::High, $this->owner));
        $this->login($this->owner);

        $this->client->request('GET', '/tools/task-board/task/' . $task->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-testid="nowo-task-history-cause"]');
    }

    public function testTheHistoryShowsTheLabelersTextEscaped(): void
    {
        self::$taskBoardConfig = ['history' => ['cause_labeler' => 'app.cause_labeler']];
        self::ensureKernelShutdown();
        $this->setUp();
        $task = $this->task($this->board(), 'Labelled');
        $this->context()->run('rule:42', fn () => $this->tasks()->updatePriority($task, TaskPriority::High, $this->owner));
        $this->context()->run('import:1', fn () => $this->tasks()->addTag($task, 'x', $this->owner));
        $this->login($this->owner);

        $crawler = $this->client->request('GET', '/tools/task-board/task/' . $task->getId());
        self::assertSame(['(rule: <42>)'], array_values($crawler->filter('[data-testid="nowo-task-history-cause"]')->each(static fn ($node): string => $node->text())), 'another cause shows nothing');
        self::assertStringContainsString('(rule: &lt;42&gt;)', (string) $this->client->getResponse()->getContent());
        self::assertInstanceOf(Task::class, $task);
    }

    private function context(): TaskChangeContext
    {
        $service = self::getContainer()->get(TaskChangeContext::class);
        self::assertInstanceOf(TaskChangeContext::class, $service);

        return $service;
    }

    private function tasks(): TaskManager
    {
        $service = self::getContainer()->get(TaskManager::class);
        self::assertInstanceOf(TaskManager::class, $service);

        return $service;
    }

    private function members(): TaskMemberAssigner
    {
        $service = self::getContainer()->get(TaskMemberAssigner::class);
        self::assertInstanceOf(TaskMemberAssigner::class, $service);

        return $service;
    }

    private function dispatcher(): EventDispatcherInterface
    {
        $service = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $service);

        return $service;
    }
}
