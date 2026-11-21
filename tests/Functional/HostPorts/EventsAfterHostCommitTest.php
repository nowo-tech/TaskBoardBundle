<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\HostPorts;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\DependencyInjection\Compiler\PrivateEntityManagerPass;
use Nowo\TaskBoardBundle\Dto\TaskFormData;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Event\TaskCreatedEvent;
use Nowo\TaskBoardBundle\Service\TaskCommandsInterface;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\HostTaskNote;
use Nowo\TaskBoardBundle\Tests\Functional\FunctionalTestCase;
use Nowo\TaskBoardBundle\ValueObject\UserRef;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Since 2.29.0 (DDD-host checklist B4, `events.after_host_commit: true` with the private entity manager): a TaskBoard
 * write inside the application's transaction dispatches its change events after that transaction commits, never when
 * it rolls back. And a host entity that points at a TaskBoard row through `getReference()` is flushed by the host alone.
 */
final class EventsAfterHostCommitTest extends FunctionalTestCase
{
    private EntityManagerInterface $host;

    private TaskCommandsInterface $commands;

    /** @var list<string> titles of the TASK_CREATED events seen */
    private array $created = [];

    protected function setUp(): void
    {
        self::$taskBoardConfig = ['database' => ['private_entity_manager' => true], 'events' => ['after_host_commit' => true]];
        parent::setUp();
        $host = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $host);
        $this->host     = $host;
        $this->commands = self::getContainer()->get(TaskCommandsInterface::class);
        self::getContainer()->get(EventDispatcherInterface::class)->addListener(TaskBoardEvents::TASK_CREATED, function (TaskCreatedEvent $event): void {
            $this->created[] = $event->getTaskSnapshot()->title;
        });
    }

    public function testEventsWaitForTheApplicationsCommit(): void
    {
        $board = $this->board();
        $owner = UserRef::ofId((string) $this->owner->getId());
        $held  = PrivateEntityManagerPass::supportsRollbackMiddleware();

        $this->host->getConnection()->beginTransaction();
        $this->commands->createTask($owner, $board->getId(), new TaskFormData('Inside'));
        self::assertSame($held ? [] : ['Inside'], $this->created, 'held while the application\'s transaction is open (DBAL 4)');
        $this->host->getConnection()->commit();

        $this->commands->createTask($owner, $board->getId(), new TaskFormData('After'));
        self::assertSame(['Inside', 'After'], $this->created, 'released at the next idle point, in order');
    }

    public function testEventsOfARolledBackTransactionAreDropped(): void
    {
        $board = $this->board();
        $owner = UserRef::ofId((string) $this->owner->getId());

        $this->host->getConnection()->beginTransaction();
        $this->commands->createTask($owner, $board->getId(), new TaskFormData('Rolled back'));
        $this->host->getConnection()->rollBack();
        $this->commands->createTask($owner, $board->getId(), new TaskFormData('Kept'));

        self::assertSame(PrivateEntityManagerPass::supportsRollbackMiddleware() ? ['Kept'] : ['Rolled back', 'Kept'], $this->created);
    }

    public function testAHostEntityPointsAtATaskThroughAReference(): void
    {
        $board = $this->board();
        $task  = $this->commands->createTask(UserRef::ofId((string) $this->owner->getId()), $board->getId(), new TaskFormData('Referenced'));
        $tasks = $this->count('task_board_tasks');

        $note       = new HostTaskNote();
        $note->task = $this->host->getReference(Task::class, $task->id);
        $this->host->persist($note);
        $this->host->flush();

        self::assertSame($task->id, $this->host->getConnection()->fetchOne('SELECT task_id FROM app_host_task_note WHERE id = ?', [$note->id]));
        self::assertSame($tasks, $this->count('task_board_tasks'), 'the host flush inserted no TaskBoard row');
        self::assertSame(1, $this->count('task_board_tasks WHERE title = \'Referenced\''));
    }

    private function count(string $from): int
    {
        return (int) $this->host->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $from);
    }
}
