<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional;

use ArrayObject;
use Nowo\TaskBoardBundle\Avatar\TaskMemberAvatars;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\EventListener\TaskBoardRequestBoundarySubscriber;
use Nowo\TaskBoardBundle\Recurrence\TaskRecurrenceView;
use Nowo\TaskBoardBundle\Service\TaskChangeContext;
use Nowo\TaskBoardBundle\Service\TaskEventDeferral;
use Nowo\TaskBoardBundle\Sync\View\SyncPeopleViewProvider;
use Nowo\TaskBoardBundle\Tests\Functional\App\CountingAvatarResolver;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use Nowo\TaskBoardBundle\Tests\Functional\App\Worker\WorkerProbeHandler;
use Nowo\TaskBoardBundle\Tests\Functional\App\Worker\WorkerProbeMessage;
use Nowo\TaskBoardBundle\Tests\Functional\Sync\SyncFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use ReflectionProperty;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\EventListener\ResetServicesListener;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;
use Symfony\Contracts\Service\ResetInterface;

use function array_filter;
use function gc_collect_cycles;
use function getenv;
use function is_array;
use function memory_get_usage;
use function putenv;

/**
 * Since 2.11.1 (REQ-RUNTIME-002): ONE kept-alive kernel, the services resetter never called — `http_kernel` driven
 * directly and a Messenger {@see Worker} run without {@see ResetServicesListener} (`messenger:consume --no-reset`).
 * Two users (alice, bob), each on a board of their own (the tenant), alternate over 500 iterations of: a board page of
 * each plus a forbidden one (1,500 requests), a message of one that succeeds and one of the other that fails half-way (1,000 messages).
 * Each page and message sees its own user's current avatar (the resolver changes it every iteration), the other user's
 * board stays forbidden, a task event is never held by the deferral a failed message left open, every request memo of
 * the bundle is empty after each unit of work, and memory stays within 4 MiB after a warm-up of 50 iterations. The
 * kernel is built as production builds it (`TASK_BOARD_TEST_DEBUG=0`: no query logging).
 */
#[CoversClass(TaskBoardRequestBoundarySubscriber::class)]
final class WorkerStateTest extends FunctionalTestCase
{
    private const ITERATIONS = 500;

    private const WARM_UP = 50;

    private const MEMORY_MARGIN = 4 * 1024 * 1024;

    private string|false $debug = false;

    protected function setUp(): void
    {
        $this->debug = getenv('TASK_BOARD_TEST_DEBUG');
        putenv('TASK_BOARD_TEST_DEBUG=0');
        self::$taskBoardConfig = ['__worker' => true, 'recurrence' => ['enabled' => true]] + SyncFunctionalTestCase::SYNC_CONFIG;
        CountingAvatarResolver::reset();
        WorkerProbeHandler::$handled = [];
        parent::setUp();
    }

    protected function tearDown(): void
    {
        putenv($this->debug === false ? 'TASK_BOARD_TEST_DEBUG' : 'TASK_BOARD_TEST_DEBUG=' . $this->debug);
        CountingAvatarResolver::reset();
        WorkerProbeHandler::$handled = [];
        parent::tearDown();
    }

    public function testTwoUsersAlternateOverHttpAndMessengerWithoutTheResetter(): void
    {
        $alice   = $this->user('alice@example.com');
        $bob     = $this->user('bob@example.com');
        $boards  = ['alice@example.com' => $this->ownBoard('Alice board', $alice), 'bob@example.com' => $this->ownBoard('Bob board', $bob)];
        $cookies = ['alice@example.com' => $this->sessionOf($alice), 'bob@example.com' => $this->sessionOf($bob)];

        $container  = self::getContainer();
        $http       = $container->get('http_kernel');
        $bus        = $container->get('messenger.default_bus');
        $dispatcher = $container->get('event_dispatcher');
        self::assertInstanceOf(HttpKernel::class, $http);
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $this->assertNoResetListener($dispatcher);
        // One message per run: stop as soon as the worker reports it handled (or found nothing).
        $dispatcher->addListener(WorkerRunningEvent::class, static function (WorkerRunningEvent $event): void {
            $event->getWorker()->stop();
        });
        $transport = new InMemoryTransport();
        $logger    = $this->logger();

        $baseline = 0;
        for ($i = 0; $i < self::ITERATIONS; ++$i) {
            foreach ($boards as $email => $board) {
                CountingAvatarResolver::$urls[$email] = 'https://img.example.com/' . $email . '/' . $i . '.png';
            }

            foreach ($boards as $email => $board) {
                $other   = $email === 'alice@example.com' ? 'bob@example.com' : 'alice@example.com';
                $content = $this->page($http, '/tools/task-board/board/' . $board->getId(), $cookies[$email], 200, $i);
                self::assertStringContainsString('https://img.example.com/' . $email . '/' . $i . '.png', $content, 'iteration ' . $i . ': the current avatar of ' . $email);
                self::assertStringNotContainsString('https://img.example.com/' . $email . '/' . ($i - 1) . '.png', $content, 'iteration ' . $i . ': no avatar of an earlier request');
                self::assertStringNotContainsString($other, $content, 'iteration ' . $i . ': nothing of the other user');
                $this->assertMemosEmpty('page of ' . $email . ', iteration ' . $i);
            }
            // The other user's board stays forbidden in the same worker.
            $this->page($http, '/tools/task-board/board/' . $boards['bob@example.com']->getId(), $cookies['alice@example.com'], 403, $i);
            $this->assertMemosEmpty('forbidden page, iteration ' . $i);

            $succeeds = $i % 2 === 0 ? 'alice@example.com' : 'bob@example.com';
            $fails    = $i % 2 === 0 ? 'bob@example.com' : 'alice@example.com';
            $this->consume($transport, $bus, new WorkerProbeMessage($succeeds, $boards[$succeeds]->getId(), false));
            $this->assertMemosEmpty('message of ' . $succeeds . ', iteration ' . $i);
            $this->consume($transport, $bus, new WorkerProbeMessage($fails, $boards[$fails]->getId(), true));
            $this->assertMemosEmpty('failed message of ' . $fails . ', iteration ' . $i);

            self::assertCount(2, WorkerProbeHandler::$handled, 'iteration ' . $i);
            foreach (WorkerProbeHandler::$handled as $seen) {
                self::assertSame('https://img.example.com/' . $seen['email'] . '/' . $i . '.png', $seen['avatar'], 'iteration ' . $i . ': the message sees the current avatar of ' . $seen['email']);
                self::assertTrue($seen['delivered'], 'iteration ' . $i . ': a task event is not held by a deferral of an earlier message');
            }

            WorkerProbeHandler::$handled     = [];
            CountingAvatarResolver::$batches = [];
            $logger->records                 = [];
            $transport->reset();
            // Rule 7 of REQ-RUNTIME-002: a host without the resetter clears its own entity manager at every boundary.
            $this->em->clear();
            if ($i === self::WARM_UP) {
                $baseline = $this->collectedMemory();
            }
        }

        self::assertLessThan(self::MEMORY_MARGIN, $this->collectedMemory() - $baseline, 'memory stays flat');
    }

    public function testNestedCommandKeepsTheOuterCommandStateAndTheOutermostEndClearsIt(): void
    {
        $container  = self::getContainer();
        $dispatcher = $container->get('event_dispatcher');
        $context    = $container->get(TaskChangeContext::class);
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        self::assertInstanceOf(TaskChangeContext::class, $context);

        $application = new Application();
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);
        $application->setDispatcher($dispatcher);
        $seen = new ArrayObject();
        $application->addCommand(new class($context, $seen) extends Command {
            /** @param ArrayObject<int, string|null> $seen */
            public function __construct(private readonly TaskChangeContext $context, private readonly ArrayObject $seen)
            {
                parent::__construct('app:inner');
            }

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                $this->seen->append($this->context->current());

                return Command::SUCCESS;
            }
        });
        $application->addCommand(new class($context, $application, $seen) extends Command {
            /** @param ArrayObject<int, string|null> $seen */
            public function __construct(private readonly TaskChangeContext $context, private readonly Application $application, private readonly ArrayObject $seen)
            {
                parent::__construct('app:outer');
            }

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                $this->context->run('rule:42', function () use ($output): void {
                    // In-process nested command (Application::doRun()): its terminate must not clear the outer cause.
                    $this->application->doRun(new ArrayInput(['command' => 'app:inner']), $output);
                    $this->seen->append($this->context->current());
                });
                // A cause left behind by a crashed write: only the end of the outermost command clears it.
                (new ReflectionProperty(TaskChangeContext::class, 'stack'))->setValue($this->context, ['rule:leaked']);

                return Command::SUCCESS;
            }
        });

        $application->run(new ArrayInput(['command' => 'app:outer']), new NullOutput());

        self::assertSame(['rule:42', 'rule:42'], $seen->getArrayCopy(), 'the nested command and the outer command after it see the outer cause');
        self::assertNull($context->current(), 'the end of the outermost command clears the context');
    }

    public function testMainRequestHandledInsideAMessageKeepsTheOpenDeferral(): void
    {
        $container  = self::getContainer();
        $http       = $container->get('http_kernel');
        $dispatcher = $container->get('event_dispatcher');
        $events     = $container->get(TaskEventDeferral::class);
        self::assertInstanceOf(HttpKernel::class, $http);
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        self::assertInstanceOf(TaskEventDeferral::class, $events);
        $delivered = new ArrayObject();
        $dispatcher->addListener('app.worker_probe_deferred', static function () use ($delivered): void {
            $delivered->append(true);
        });

        // A message handler that holds a task event, then renders a page in-process (e.g. a PDF) before its commit.
        $dispatcher->dispatch(new WorkerMessageReceivedEvent(new Envelope(new WorkerProbeMessage('alice@example.com', '1', false)), 'probe'));
        $events->defer(static function () use ($events, $http, $delivered): void {
            $events->dispatch(new GenericEvent(), 'app.worker_probe_deferred');
            $request = Request::create('/tools/task-board/no-such-page');
            $http->terminate($request, $http->handle($request));
            self::assertCount(0, $delivered, 'still held inside the write');
        });
        $dispatcher->dispatch(new WorkerMessageHandledEvent(new Envelope(new WorkerProbeMessage('alice@example.com', '1', false)), 'probe'));

        self::assertCount(1, $delivered, 'the in-process request did not drop the held event');
    }

    public function testServicesResetterStillResetsEveryMemo(): void
    {
        $container = self::getContainer();
        $resetter  = $container->get('services_resetter');
        self::assertInstanceOf(ResetInterface::class, $resetter);
        $this->fillMemos();

        $resetter->reset();

        $this->assertMemosEmpty('after services_resetter');
    }

    public function testTheBoundarySubscriberClearsEveryMemo(): void
    {
        $subscriber = self::getContainer()->get(TaskBoardRequestBoundarySubscriber::class);
        self::assertInstanceOf(TaskBoardRequestBoundarySubscriber::class, $subscriber);
        $this->fillMemos();

        $subscriber->clear();

        $this->assertMemosEmpty('after clear()');
    }

    private function ownBoard(string $name, AppUser $user): TaskBoard
    {
        $board = $this->board($name, creator: $user);
        $task  = $this->task($board, $name . ' task');
        $task->addMember(new TaskMember($task, $user, TaskMemberRole::Assignee));
        $this->em->flush();

        return $board;
    }

    private function sessionOf(AppUser $user): array
    {
        $this->client->getCookieJar()->clear();
        $this->client->loginUser($user);
        $cookies = [];
        foreach ($this->client->getCookieJar()->all() as $cookie) {
            $cookies[$cookie->getName()] = $cookie->getValue();
        }
        self::assertNotSame([], $cookies);

        return $cookies;
    }

    /**
     * @param array<string, string> $cookies
     */
    private function page(HttpKernel $http, string $uri, array $cookies, int $status, int $iteration): string
    {
        $request  = Request::create($uri, 'GET', [], $cookies);
        $response = $http->handle($request, HttpKernelInterface::MAIN_REQUEST, true);
        self::assertSame($status, $response->getStatusCode(), 'iteration ' . $iteration . ': ' . $uri);
        $http->terminate($request, $response);

        return (string) $response->getContent();
    }

    private function consume(InMemoryTransport $transport, MessageBusInterface $bus, WorkerProbeMessage $message): void
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $transport->send(new Envelope($message));
        (new Worker(['probe' => $transport], $bus, $dispatcher))->run(['sleep' => 0]);
    }

    private function assertNoResetListener(EventDispatcherInterface $dispatcher): void
    {
        $reset = array_filter($dispatcher->getListeners(WorkerRunningEvent::class), static fn (mixed $listener): bool => is_array($listener) && $listener[0] instanceof ResetServicesListener);
        self::assertSame([], $reset, 'the worker runs without ResetServicesListener (--no-reset)');
    }

    private function fillMemos(): void
    {
        $container = self::getContainer();
        $memos     = [
            [TaskMemberAvatars::class, 'urls', ['1' => 'https://img.example.com/stale.png']],
            [TaskRecurrenceView::class, 'recurring', ['1' => ['2' => true]]],
            [SyncPeopleViewProvider::class, 'items', ['1' => false]],
            [TaskChangeContext::class, 'stack', ['rule:1']],
            [TaskEventDeferral::class, 'queue', [[new WorkerProbeMessage('alice@example.com', '1', false), null]]],
            [TaskEventDeferral::class, 'depth', 1],
        ];
        foreach ($memos as [$id, $property, $value]) {
            (new ReflectionProperty($id, $property))->setValue($container->get($id), $value);
        }
    }

    private function assertMemosEmpty(string $context): void
    {
        $container = self::getContainer();
        foreach ([[TaskMemberAvatars::class, 'urls'], [TaskRecurrenceView::class, 'recurring'], [SyncPeopleViewProvider::class, 'items'], [TaskChangeContext::class, 'stack'], [TaskEventDeferral::class, 'queue']] as [$id, $property]) {
            self::assertSame([], (new ReflectionProperty($id, $property))->getValue($container->get($id)), $context . ': ' . $id . '::$' . $property);
        }
        self::assertSame(0, (new ReflectionProperty(TaskEventDeferral::class, 'depth'))->getValue($container->get(TaskEventDeferral::class)), $context . ': deferral depth');
    }

    /**
     * Memory after a cycle collection: request and envelope objects form reference cycles that PHP frees only when the
     * collector runs, so the raw figure depends on GC timing, not on retained state.
     */
    private function collectedMemory(): int
    {
        gc_collect_cycles();

        return memory_get_usage();
    }
}
