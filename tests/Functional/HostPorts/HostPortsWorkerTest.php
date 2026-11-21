<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\HostPorts;

use Nowo\TaskBoardBundle\DependencyInjection\Compiler\PrivateEntityManagerPass;
use Nowo\TaskBoardBundle\Doctrine\TaskBoardEntityManager;
use Nowo\TaskBoardBundle\Dto\TaskFormData;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Event\TaskMovedEvent;
use Nowo\TaskBoardBundle\Exception\TaskBoardAccessDeniedException;
use Nowo\TaskBoardBundle\Service\TaskBoardAccessInterface;
use Nowo\TaskBoardBundle\Service\TaskBoardQueryInterface;
use Nowo\TaskBoardBundle\Service\TaskCommandsInterface;
use Nowo\TaskBoardBundle\Tests\Functional\FunctionalTestCase;
use Nowo\TaskBoardBundle\ValueObject\UserRef;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\HttpKernelInterface;

use function gc_collect_cycles;
use function getenv;
use function memory_get_usage;
use function putenv;

/**
 * Since 2.29.0 (FrankenPHP worker mode, kernel reset false): ONE kept-alive kernel, never reset by `services_resetter`.
 * Two users alternate over 200 iterations through the id-based ports, each in the application's own transaction with
 * `events.after_host_commit` on; a request through `http_kernel` between iterations is the unit-of-work boundary. Each
 * user moves only their own task, is refused the other's, the query port reads the committed column, no event is held
 * across a boundary and memory stays flat after a warm-up.
 */
final class HostPortsWorkerTest extends FunctionalTestCase
{
    private const ITERATIONS = 200;

    private const WARM_UP = 20;

    private const MEMORY_MARGIN = 4 * 1024 * 1024;

    private string|false $debug = false;

    protected function setUp(): void
    {
        $this->debug = getenv('TASK_BOARD_TEST_DEBUG');
        putenv('TASK_BOARD_TEST_DEBUG=0');
        self::$taskBoardConfig = ['database' => ['private_entity_manager' => true], 'events' => ['after_host_commit' => true]];
        parent::setUp();
    }

    protected function tearDown(): void
    {
        putenv($this->debug === false ? 'TASK_BOARD_TEST_DEBUG' : 'TASK_BOARD_TEST_DEBUG=' . $this->debug);
        parent::tearDown();
    }

    public function testTwoUsersAlternateWithoutTheResetter(): void
    {
        $alice  = $this->user('alice@example.com');
        $bob    = $this->user('bob@example.com');
        $refs   = ['alice' => UserRef::ofId((string) $alice->getId()), 'bob' => UserRef::ofId((string) $bob->getId())];
        $boards = ['alice' => $this->board('Alice board', creator: $alice), 'bob' => $this->board('Bob board', creator: $bob)];
        $this->em->clear();

        $container = self::getContainer();
        $commands  = $container->get(TaskCommandsInterface::class);
        $access    = $container->get(TaskBoardAccessInterface::class);
        $query     = $container->get(TaskBoardQueryInterface::class);
        $http      = $container->get('http_kernel');
        $private   = $container->get('app.task_board_em');
        self::assertInstanceOf(HttpKernel::class, $http);
        self::assertInstanceOf(TaskBoardEntityManager::class, $private);
        $connection = $private->source()->getConnection();
        $moved      = [];
        $container->get(EventDispatcherInterface::class)->addListener(TaskBoardEvents::TASK_MOVED, static function (TaskMovedEvent $event) use (&$moved): void {
            $moved[] = $event->getActorId();
        });
        $tasks = [];
        foreach ($refs as $name => $ref) {
            $tasks[$name] = $commands->createTask($ref, $boards[$name]->getId(), new TaskFormData($name . ' task'))->id;
        }

        $baseline = 0;
        for ($i = 0; $i < self::ITERATIONS; ++$i) {
            foreach ($refs as $name => $ref) {
                $other  = $name === 'alice' ? 'bob' : 'alice';
                $column = $boards[$name]->getColumns()->get(($i + 1) % 3)?->getId();
                $elsewhere = $boards[$name]->getColumns()->get(($i + 2) % 3)?->getId();
                $moved  = [];
                $connection->beginTransaction();
                $commands->moveTask($ref, $tasks[$name], $column, 0);
                $connection->commit();
                $this->boundary($http);
                self::assertSame([$ref->id], $moved, 'iteration ' . $i . ': released after the commit, the actor\'s own id');
                self::assertSame($column, $query->findTaskById($tasks[$name])?->columnId, 'iteration ' . $i);
                self::assertFalse($access->canEditTask($ref, $tasks[$other]), 'iteration ' . $i);
                try {
                    $commands->moveTask($ref, $tasks[$other], null, 0);
                    self::fail('iteration ' . $i . ': the other user\'s task');
                } catch (TaskBoardAccessDeniedException) {
                }

                // A transaction the application rolls back: its event never reaches the next request.
                $moved = [];
                $connection->beginTransaction();
                $commands->moveTask($ref, $tasks[$name], $elsewhere, 0);
                $connection->rollBack();
                $this->boundary($http);
                self::assertSame(PrivateEntityManagerPass::supportsRollbackMiddleware() ? [] : [$ref->id], $moved, 'iteration ' . $i . ': nothing of a rolled-back transaction (DBAL 4)');
                self::assertFalse($private->isCreated(), 'iteration ' . $i . ': no row survives the boundary');
            }
            if ($i === self::WARM_UP) {
                $baseline = $this->collectedMemory();
            }
        }

        self::assertLessThan(self::MEMORY_MARGIN, $this->collectedMemory() - $baseline, 'memory stays flat');
    }

    private function boundary(HttpKernel $http): void
    {
        $request  = Request::create('/tools/task-board/', 'GET');
        $response = $http->handle($request, HttpKernelInterface::MAIN_REQUEST, true);
        $http->terminate($request, $response);
    }

    private function collectedMemory(): int
    {
        gc_collect_cycles();

        return memory_get_usage();
    }
}
