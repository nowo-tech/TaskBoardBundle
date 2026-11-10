<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Dto\TaskLinkFormData;
use Nowo\TaskBoardBundle\Dto\TaskMemberFormData;
use Nowo\TaskBoardBundle\Enum\TaskDependencyType;
use Nowo\TaskBoardBundle\Enum\TaskLinkType;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Event\TaskDependencyAddedEvent;
use Nowo\TaskBoardBundle\Event\TaskDependencyRemovedEvent;
use Nowo\TaskBoardBundle\Event\TaskLinkAddedEvent;
use Nowo\TaskBoardBundle\Event\TaskLinkRemovedEvent;
use Nowo\TaskBoardBundle\Event\TaskLinkUpdatedEvent;
use Nowo\TaskBoardBundle\Event\TaskMemberRemovedEvent;
use Nowo\TaskBoardBundle\Event\TaskTimeLoggedEvent;
use Nowo\TaskBoardBundle\Event\TaskTimeRemovedEvent;
use Nowo\TaskBoardBundle\Import\TaskImportOrchestrator;
use Nowo\TaskBoardBundle\Import\TaskImportSource;
use Nowo\TaskBoardBundle\Move\TaskMover;
use Nowo\TaskBoardBundle\Move\TaskMoverInterface;
use Nowo\TaskBoardBundle\Service\TaskBoardEraser;
use Nowo\TaskBoardBundle\Service\TaskBoardEraserInterface;
use Nowo\TaskBoardBundle\Service\TaskChangeContext;
use Nowo\TaskBoardBundle\Service\TaskDependencyManager;
use Nowo\TaskBoardBundle\Service\TaskEventDeferral;
use Nowo\TaskBoardBundle\Service\TaskLinkAttacher;
use Nowo\TaskBoardBundle\Service\TaskMemberAssigner;
use Nowo\TaskBoardBundle\Service\TaskTimeLogger;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\EventDispatcher\Event;

use function array_map;

/**
 * Since 2.11.0 (DDD audit G3/G4): id-only events of time entries, dependencies, links and member removal, dispatched after
 * the write through `TaskEventDeferral`, and the ports of `TaskMover` and `TaskBoardEraser`.
 */
final class TaskChildEventsTest extends FunctionalTestCase
{
    /** @var list<array{string, Event}> */
    private array $events = [];

    public function testTimeEntriesDispatchIdOnlyEventsAfterTheSave(): void
    {
        $board = $this->board('Time', tenantKey: 'acme');
        $task  = $this->task($board, 'Track me');
        $this->listen(TaskBoardEvents::TASK_TIME_LOGGED, TaskBoardEvents::TASK_TIME_REMOVED);
        $logger = $this->service(TaskTimeLogger::class);

        $entry = $this->service(TaskChangeContext::class)->run('rule:7', fn () => $logger->log($task, $this->owner, 30, new DateTimeImmutable('2026-11-10'), 'Review'));
        self::assertFalse($logger->remove($task, 'unknown', $this->owner), 'no entry: no event');
        self::assertTrue($logger->remove($task, $entry->getId(), $this->owner));

        self::assertSame([TaskBoardEvents::TASK_TIME_LOGGED, TaskBoardEvents::TASK_TIME_REMOVED], $this->names());
        $owner  = (string) $this->owner->getId();
        $logged = $this->events[0][1];
        self::assertInstanceOf(TaskTimeLoggedEvent::class, $logged);
        self::assertSame([$entry->getId(), $task->getId(), $board->getId(), 'acme', $owner, 30, '2026-11-10', $owner, 'rule:7'], [$logged->entryId, $logged->taskId, $logged->boardId, $logged->tenantKey, $logged->userId, $logged->minutes, $logged->loggedOn, $logged->actorId, $logged->cause]);
        $removed = $this->events[1][1];
        self::assertInstanceOf(TaskTimeRemovedEvent::class, $removed);
        self::assertSame([$entry->getId(), $task->getId(), 30, null], [$removed->entryId, $removed->taskId, $removed->minutes, $removed->cause]);
    }

    public function testDependenciesDispatchIdOnlyEventsAndWaitForADeferredCommit(): void
    {
        $board   = $this->board('Deps');
        $blocker = $this->task($board, 'Blocker');
        $blocked = $this->task($board, 'Blocked');
        $this->listen(TaskBoardEvents::TASK_DEPENDENCY_ADDED, TaskBoardEvents::TASK_DEPENDENCY_REMOVED);
        $manager = $this->service(TaskDependencyManager::class);

        $dependency = $this->service(TaskEventDeferral::class)->defer(function () use ($manager, $blocker, $blocked) {
            $dependency = $manager->add($blocker, $blocked, TaskDependencyType::Blocks, $this->owner);
            self::assertSame([], $this->events, 'held until the deferred write returns');

            return $dependency;
        });
        self::assertSame([TaskBoardEvents::TASK_DEPENDENCY_ADDED], $this->names());
        $added = $this->events[0][1];
        self::assertInstanceOf(TaskDependencyAddedEvent::class, $added);
        self::assertSame([$dependency->getId(), $blocker->getId(), $blocker->getId(), $blocked->getId(), 'blocks', $board->getId(), null, (string) $this->owner->getId()], [$added->dependencyId, $added->taskId, $added->sourceTaskId, $added->targetTaskId, $added->type, $added->boardId, $added->tenantKey, $added->actorId]);

        // Removed from the other end: `taskId` is that task, the link keeps its direction.
        self::assertFalse($manager->remove($blocked, 'unknown', $this->owner));
        self::assertTrue($manager->remove($blocked, $dependency->getId(), $this->owner));
        $removed = $this->events[1][1];
        self::assertInstanceOf(TaskDependencyRemovedEvent::class, $removed);
        self::assertSame([$dependency->getId(), $blocked->getId(), $blocker->getId(), $blocked->getId(), 'blocks'], [$removed->dependencyId, $removed->taskId, $removed->sourceTaskId, $removed->targetTaskId, $removed->type]);

        // A failing deferred write drops its held events.
        try {
            $this->service(TaskEventDeferral::class)->defer(function () use ($manager, $blocker, $blocked): never {
                $manager->add($blocked, $blocker, TaskDependencyType::Related, $this->owner);

                throw new RuntimeException('rolled back');
            });
        } catch (RuntimeException) {
        }
        self::assertCount(2, $this->events, 'dropped on failure');
    }

    public function testAnImportDispatchesItsDependencyEventsAfterTheCommit(): void
    {
        $board      = $this->board('Import', ['To do', 'Done']);
        $connection = $this->em->getConnection();
        $inside     = [];
        $this->dispatcher()->addListener(TaskBoardEvents::TASK_DEPENDENCY_ADDED, static function (TaskDependencyAddedEvent $event) use ($connection, &$inside): void {
            $inside[] = $connection->isTransactionActive();
        });

        $result = $this->service(TaskImportOrchestrator::class)->import($board, TaskImportSource::ClickUpCsv, "Task ID,Task Name,Status,Waiting On\nt1,One,To do,\nt2,Two,To do,t1", 'deps.csv', $this->owner);

        self::assertSame(1, $result->dependenciesCreated);
        self::assertSame([false], $inside, 'held until the import committed');
    }

    public function testLinksDispatchIdOnlyEvents(): void
    {
        $board = $this->board('Links', tenantKey: 'acme');
        $task  = $this->task($board, 'Linked');
        $this->listen(TaskBoardEvents::TASK_LINK_ADDED, TaskBoardEvents::TASK_LINK_UPDATED, TaskBoardEvents::TASK_LINK_REMOVED);
        $links = $this->service(TaskLinkAttacher::class);

        $url = $links->attach($task, new TaskLinkFormData(TaskLinkType::Url, 'https://example.com/spec', 'Spec'), $this->owner);
        self::assertTrue($links->update($task, $url->getId(), new TaskLinkFormData(TaskLinkType::Documentation, 'https://example.com/spec-2'), $this->owner));
        self::assertFalse($links->update($task, 'unknown', new TaskLinkFormData(), $this->owner));
        $object = $links->attachObject($task, 'order', '42', $this->owner, 'Order 42');
        $links->attachObject($task, 'order', '42', $this->owner);
        self::assertTrue($links->detachObject($task, 'order', '42', $this->owner));
        self::assertTrue($links->remove($task, $url->getId(), $this->owner));

        self::assertSame([TaskBoardEvents::TASK_LINK_ADDED, TaskBoardEvents::TASK_LINK_UPDATED, TaskBoardEvents::TASK_LINK_ADDED, TaskBoardEvents::TASK_LINK_REMOVED, TaskBoardEvents::TASK_LINK_REMOVED], $this->names(), 'an existing host object link dispatches nothing');
        $added = $this->events[0][1];
        self::assertInstanceOf(TaskLinkAddedEvent::class, $added);
        self::assertSame([$url->getId(), $task->getId(), $board->getId(), 'acme', 'url', null, null, (string) $this->owner->getId()], [$added->linkId, $added->taskId, $added->boardId, $added->tenantKey, $added->linkType, $added->objectType, $added->objectId, $added->actorId]);
        $updated = $this->events[1][1];
        self::assertInstanceOf(TaskLinkUpdatedEvent::class, $updated);
        self::assertSame('documentation', $updated->linkType);
        $detached = $this->events[3][1];
        self::assertInstanceOf(TaskLinkRemovedEvent::class, $detached);
        self::assertSame([$object->getId(), 'host_object', 'order', '42'], [$detached->linkId, $detached->linkType, $detached->objectType, $detached->objectId]);
    }

    public function testMemberRemovalDispatchesAnIdOnlyEvent(): void
    {
        $board = $this->board('Members', tenantKey: 'acme');
        $task  = $this->task($board, 'Shared');
        $ana   = $this->user('ana@example.com');
        $this->listen(TaskBoardEvents::TASK_MEMBER_REMOVED);
        $members = $this->service(TaskMemberAssigner::class);
        $member  = $members->assign($task, new TaskMemberFormData($ana, TaskMemberRole::Reviewer), $this->owner);

        self::assertFalse($members->unassign($task, 'unknown', $this->owner));
        self::assertTrue($this->service(TaskChangeContext::class)->run('rule:9', fn (): bool => $members->unassign($task, $member->getId(), $this->owner)));

        self::assertSame([TaskBoardEvents::TASK_MEMBER_REMOVED], $this->names());
        $removed = $this->events[0][1];
        self::assertInstanceOf(TaskMemberRemovedEvent::class, $removed);
        self::assertSame([$member->getId(), $task->getId(), $board->getId(), 'acme', (string) $ana->getId(), 'reviewer', (string) $this->owner->getId(), 'rule:9'], [$removed->memberId, $removed->taskId, $removed->boardId, $removed->tenantKey, $removed->userId, $removed->role, $removed->actorId, $removed->cause]);
    }

    public function testTheMoveAndErasePortsAreAliasedAndTheEraserStillChecksAccess(): void
    {
        self::assertInstanceOf(TaskMover::class, self::getContainer()->get(TaskMoverInterface::class));
        self::assertInstanceOf(TaskBoardEraser::class, self::getContainer()->get(TaskBoardEraserInterface::class));

        $board    = $this->board('Erase');
        $task     = $this->task($board, 'Keep me');
        $eraser   = $this->service(TaskBoardEraserInterface::class);
        $stranger = $this->user('stranger@example.com');
        foreach ([
            static fn () => $eraser->eraseTask($task, $stranger),
            static fn () => $eraser->emptyBoard($board, $stranger),
            static fn () => $eraser->deleteBoard($board, $stranger),
        ] as $call) {
            try {
                $call();
                self::fail('The port must refuse an actor who may not manage the board');
            } catch (AccessDeniedException) {
            }
        }
        $this->em->clear();
        self::assertNotNull($this->em->find($task::class, $task->getId()), 'nothing deleted');
    }

    /** @return list<string> */
    private function names(): array
    {
        return array_map(static fn (array $entry): string => $entry[0], $this->events);
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
