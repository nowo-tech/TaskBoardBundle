<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\HostPorts;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Dto\TaskFormData;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Service\TaskCommandsInterface;
use Nowo\TaskBoardBundle\Tests\Functional\FunctionalTestCase;
use Nowo\TaskBoardBundle\ValueObject\UserRef;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Since 2.29.0: `events.after_host_commit` is opt-in. With its default (false) a TaskBoard write inside the application's
 * transaction dispatches its events after the bundle's own write, inside that transaction, as up to 2.28.
 */
final class EventsInsideHostTransactionTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        self::$taskBoardConfig = ['database' => ['private_entity_manager' => true]];
        parent::setUp();
    }

    public function testTheDefaultDispatchesInsideTheApplicationsTransaction(): void
    {
        $created = 0;
        self::getContainer()->get(EventDispatcherInterface::class)->addListener(TaskBoardEvents::TASK_CREATED, static function () use (&$created): void {
            ++$created;
        });
        $host = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $host);
        $board = $this->board();

        $host->getConnection()->beginTransaction();
        self::getContainer()->get(TaskCommandsInterface::class)->createTask(UserRef::ofId((string) $this->owner->getId()), $board->getId(), new TaskFormData('Inside'));
        self::assertSame(1, $created);
        $host->getConnection()->rollBack();
    }
}
