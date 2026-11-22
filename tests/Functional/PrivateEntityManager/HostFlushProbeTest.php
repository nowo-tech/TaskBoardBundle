<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\PrivateEntityManager;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Doctrine\TaskBoardHostFlushProbe;
use Nowo\TaskBoardBundle\Dto\TaskBoardFormData;
use Nowo\TaskBoardBundle\EventListener\TaskBoardRequestBoundarySubscriber;
use Nowo\TaskBoardBundle\Service\TaskBoardCreatorInterface;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use Nowo\TaskBoardBundle\Tests\Functional\App\PredictableCsrfTokenManager;
use Nowo\TaskBoardBundle\Tests\Functional\FunctionalTestCase;
use Nowo\TaskBoardBundle\Tests\Support\PrivateEntityManagerReplay;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\HttpKernelInterface;

use function array_values;
use function getenv;
use function putenv;
use function restore_error_handler;
use function set_error_handler;
use function sprintf;
use function str_contains;

use const E_USER_DEPRECATED;

/**
 * Since 2.28.0 (REQ-ARCH-001 rule 6 deprecation layer): without `database.private_entity_manager` (the 2.x default) a
 * TaskBoard write flushes the application's entity manager, and with it the application's pending changes. Right before
 * that flush the host-flush probe triggers one deprecation per application entity class and unit of work; a flush the
 * application runs itself is not reported, the state ends at every boundary (worker mode, kernel reset off), and the
 * private manager has no probe.
 */
final class HostFlushProbeTest extends FunctionalTestCase
{
    private const NOTICE = 'Your pending changes to %s were flushed by NowoTaskBoardBundle; 2.0 no longer does.';

    /** @var list<string> */
    private array $deprecations = [];

    private string|false $debug = false;

    protected function setUp(): void
    {
        if (PrivateEntityManagerReplay::enabled()) {
            self::markTestSkipped('The private-manager replay turns the option on (see testThePrivateManagerHasNoProbe).');
        }
        $this->debug = getenv('TASK_BOARD_TEST_DEBUG');
        putenv('TASK_BOARD_TEST_DEBUG=0');
        parent::setUp();
        set_error_handler(function (int $level, string $message): bool {
            if (str_contains($message, 'Your pending changes to ')) {
                $this->deprecations[] = $message;
            }

            return true;
        }, E_USER_DEPRECATED);
    }

    protected function tearDown(): void
    {
        if (!PrivateEntityManagerReplay::enabled()) {
            restore_error_handler();
            putenv($this->debug === false ? 'TASK_BOARD_TEST_DEBUG' : 'TASK_BOARD_TEST_DEBUG=' . $this->debug);
        }
        parent::tearDown();
    }

    public function testATaskBoardWriteReportsEachPendingApplicationClassOncePerUnitOfWork(): void
    {
        $host    = $this->host();
        $creator = self::getContainer()->get(TaskBoardCreatorInterface::class);
        self::assertInstanceOf(TaskBoardCreatorInterface::class, $creator);
        $this->deprecations = [];

        // The application's own flush is not TaskBoard's.
        $this->owner->setDisplayName('Edited and flushed by the application');
        $host->flush();
        self::assertSame([], $this->deprecations, 'an application flush is not reported');

        // A TaskBoard write with nothing of the application pending reports nothing.
        $creator->create(new TaskBoardFormData('Clean', 'clean'), $this->owner);
        self::assertSame([], $this->deprecations);

        // A pending insert and a pending update of the same class: one deprecation for the class.
        $host->persist(new AppUser('pending@example.com'));
        $this->owner->setDisplayName('Edited by the application');
        $creator->create(new TaskBoardFormData('First', 'first'), $this->owner);
        self::assertSame([sprintf(self::NOTICE, AppUser::class)], $this->deprecations);
        self::assertSame('Edited by the application', $this->displayName($host, 'owner@example.com'), 'the 2.x behaviour is unchanged: the change is written');

        // The same class again in the same unit of work: not reported twice.
        $this->owner->setDisplayName('Edited again');
        $creator->create(new TaskBoardFormData('Second', 'second'), $this->owner);
        self::assertCount(1, $this->deprecations);

        // A new unit of work (the bundle's boundary) reports it again.
        $boundary = self::getContainer()->get(TaskBoardRequestBoundarySubscriber::class);
        self::assertInstanceOf(TaskBoardRequestBoundarySubscriber::class, $boundary);
        $boundary->clear();
        $this->owner->setDisplayName('Edited in the next unit');
        $creator->create(new TaskBoardFormData('Third', 'third'), $this->owner);
        self::assertSame([sprintf(self::NOTICE, AppUser::class), sprintf(self::NOTICE, AppUser::class)], $this->deprecations);
        self::assertTrue($host->isOpen(), 'the probe never closes, clears or resets the manager');
        self::assertTrue($host->contains($this->owner));
    }

    /**
     * FrankenPHP worker mode with the kernel reset off: one kept-alive kernel driven through `http_kernel->handle()` /
     * `terminate()`. Each request edits a TaskBoard task while the application left a change pending: every request
     * reports it again (nothing of an earlier request suppresses the notice).
     */
    public function testEveryRequestOfAKeptAliveWorkerStartsAfresh(): void
    {
        $host  = $this->host();
        $board = $this->board();
        $task  = $this->task($board, 'Worker task');
        $this->login($this->owner);
        $this->client->request('GET', '/tools/task-board/board/' . $board->getId());
        $cookies = [];
        foreach ($this->client->getCookieJar()->all() as $cookie) {
            $cookies[$cookie->getName()] = $cookie->getValue();
        }
        $http = self::getContainer()->get('http_kernel');
        self::assertInstanceOf(HttpKernel::class, $http);
        $token = PredictableCsrfTokenManager::value('task_board_task_advance');

        for ($i = 0; $i < 3; ++$i) {
            $this->deprecations = [];
            $this->owner->setDisplayName('Pending ' . $i);
            $request  = Request::create('/tools/task-board/task/' . $task->getId() . '/advance', 'POST', ['_token' => $token, 'action' => $i % 2 === 0 ? 'next' : 'previous'], $cookies);
            $response = $http->handle($request, HttpKernelInterface::MAIN_REQUEST, true);
            self::assertSame(302, $response->getStatusCode(), 'request ' . $i);
            $http->terminate($request, $response);

            self::assertSame([sprintf(self::NOTICE, AppUser::class)], array_values($this->deprecations), 'request ' . $i);
            self::assertSame('Pending ' . $i, $this->displayName($host, 'owner@example.com'));
        }
    }

    public function testThePrivateManagerHasNoProbe(): void
    {
        self::ensureKernelShutdown();
        self::$taskBoardConfig = ['database' => ['private_entity_manager' => true]];
        parent::setUp();
        self::assertFalse(self::getContainer()->has(TaskBoardHostFlushProbe::class));
        $host = $this->host();
        $host->persist(new AppUser('pending@example.com'));
        $creator = self::getContainer()->get(TaskBoardCreatorInterface::class);
        self::assertInstanceOf(TaskBoardCreatorInterface::class, $creator);
        $this->deprecations = [];

        $creator->create(new TaskBoardFormData('Private', 'private'), $this->owner);

        self::assertSame([], $this->deprecations);
        self::assertTrue($host->getUnitOfWork()->isScheduledForInsert($this->findPending($host)), 'the private manager never flushes the application\'s');
    }

    private function host(): EntityManagerInterface
    {
        $host = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $host);

        return $host;
    }

    private function findPending(EntityManagerInterface $host): AppUser
    {
        foreach ($host->getUnitOfWork()->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof AppUser && $entity->getEmail() === 'pending@example.com') {
                return $entity;
            }
        }
        self::fail('the pending user is gone');
    }

    private function displayName(EntityManagerInterface $host, string $email): mixed
    {
        return $host->getConnection()->fetchOne('SELECT display_name FROM ' . $host->getClassMetadata(AppUser::class)->getTableName() . ' WHERE email = ?', [$email]);
    }
}
