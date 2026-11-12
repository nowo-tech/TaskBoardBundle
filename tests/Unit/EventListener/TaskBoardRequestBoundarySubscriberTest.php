<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\EventListener;

use Nowo\TaskBoardBundle\EventListener\TaskBoardRequestBoundarySubscriber;
use Nowo\TaskBoardBundle\Service\TaskChangeContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use stdClass;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Worker;

/**
 * Since 2.11.1 (REQ-RUNTIME-002): the boundaries of {@see TaskBoardRequestBoundarySubscriber} — the same memo
 * instances serve consecutive requests, messages and commands of different users when the services resetter does not
 * run.
 */
#[CoversClass(TaskBoardRequestBoundarySubscriber::class)]
final class TaskBoardRequestBoundarySubscriberTest extends TestCase
{
    private TaskChangeContext $context;

    private EventDispatcher $dispatcher;

    private HttpKernelInterface $kernel;

    protected function setUp(): void
    {
        $this->context    = new TaskChangeContext();
        $this->dispatcher = new EventDispatcher();
        $this->dispatcher->addSubscriber(new TaskBoardRequestBoundarySubscriber([$this->context]));
        $this->kernel = $this->createStub(HttpKernelInterface::class);
    }

    public function testSubscribesToTheRequestMessengerAndConsoleBoundaries(): void
    {
        $events = TaskBoardRequestBoundarySubscriber::getSubscribedEvents();

        self::assertSame(['onKernelRequest', 4096], $events[KernelEvents::REQUEST]);
        self::assertSame(['onKernelFinishRequest', -4096], $events[KernelEvents::FINISH_REQUEST]);
        self::assertSame(['onKernelTerminate', -4096], $events[KernelEvents::TERMINATE]);
        self::assertSame(['onMessageReceived', 4096], $events[WorkerMessageReceivedEvent::class]);
        self::assertSame(['onMessageFinished', -4096], $events[WorkerMessageHandledEvent::class]);
        self::assertSame(['onMessageFinished', -4096], $events[WorkerMessageFailedEvent::class]);
        self::assertSame(['onWorkerStopped', -4096], $events[WorkerStoppedEvent::class]);
        self::assertSame(['onConsoleCommand', 4096], $events[ConsoleEvents::COMMAND]);
        self::assertSame(['onConsoleTerminate', -4096], $events[ConsoleEvents::TERMINATE]);
    }

    public function testMainRequestStartAndTerminateClearTheMemos(): void
    {
        $this->leak();
        $this->request(HttpKernelInterface::MAIN_REQUEST);
        self::assertNull($this->context->current(), 'the next main request starts empty');

        $this->leak();
        $this->terminate();
        self::assertNull($this->context->current(), 'terminate clears what the request left');
    }

    public function testSubRequestKeepsTheMainRequestMemos(): void
    {
        $this->request(HttpKernelInterface::MAIN_REQUEST);
        $this->leak();

        $this->request(HttpKernelInterface::SUB_REQUEST);
        $this->finishRequest(HttpKernelInterface::SUB_REQUEST);

        self::assertSame('rule:leaked', $this->context->current(), 'a fragment shares the main request memos');
    }

    public function testMessageBoundariesClearTheMemos(): void
    {
        $this->leak();
        $this->dispatcher->dispatch(new WorkerMessageReceivedEvent(new Envelope(new stdClass()), 'async'));
        self::assertNull($this->context->current(), 'a message starts empty');

        $this->leak();
        $this->dispatcher->dispatch(new WorkerMessageHandledEvent(new Envelope(new stdClass()), 'async'));
        self::assertNull($this->context->current(), 'a handled message ends empty');

        $this->dispatcher->dispatch(new WorkerMessageReceivedEvent(new Envelope(new stdClass()), 'async'));
        $this->leak();
        $this->dispatcher->dispatch(new WorkerMessageFailedEvent(new Envelope(new stdClass()), 'async', new RuntimeException('failed')));
        self::assertNull($this->context->current(), 'a failed message ends empty');
    }

    public function testConsoleTerminateOfTheOutermostCommandClearsTheMemos(): void
    {
        $this->leak();
        $this->consoleCommand();
        self::assertNull($this->context->current(), 'the outermost command starts empty');

        $this->leak();
        $this->consoleTerminate();
        self::assertNull($this->context->current(), 'the end of the outermost command clears');
    }

    public function testNestedCommandDoesNotClearTheOuterCommandMemos(): void
    {
        $this->consoleCommand();
        $this->leak();

        $this->consoleCommand(); // Application::doRun() inside the outer command
        $this->consoleTerminate();
        self::assertSame('rule:leaked', $this->context->current(), 'a nested in-process command keeps the outer state');

        $this->consoleTerminate();
        self::assertNull($this->context->current());
    }

    public function testCommandRunFromAMessageHandlerDoesNotClearTheMessageMemos(): void
    {
        $this->dispatcher->dispatch(new WorkerMessageReceivedEvent(new Envelope(new stdClass()), 'async'));
        $this->leak();

        $this->consoleCommand();
        $this->consoleTerminate();
        self::assertSame('rule:leaked', $this->context->current(), 'the handler keeps its state after the command');

        $this->dispatcher->dispatch(new WorkerMessageHandledEvent(new Envelope(new stdClass()), 'async'));
        self::assertNull($this->context->current());
    }

    public function testCommandRunFromAControllerDoesNotClearTheRequestMemos(): void
    {
        $this->request(HttpKernelInterface::MAIN_REQUEST);
        $this->leak();

        $this->consoleCommand();
        $this->consoleTerminate();
        self::assertSame('rule:leaked', $this->context->current(), 'the request keeps its state after the command');

        $this->finishRequest(HttpKernelInterface::MAIN_REQUEST);
        $this->consoleCommand();
        self::assertNull($this->context->current(), 'after the request a command starts empty again');
    }

    public function testMainRequestHandledInsideAMessageDoesNotClearTheMessageState(): void
    {
        $this->dispatcher->dispatch(new WorkerMessageReceivedEvent(new Envelope(new stdClass()), 'async'));
        $this->leak();

        // In-process http_kernel->handle() inside the handler (e.g. rendering a PDF).
        $this->request(HttpKernelInterface::MAIN_REQUEST);
        $this->finishRequest(HttpKernelInterface::MAIN_REQUEST);
        $this->terminate();
        self::assertSame('rule:leaked', $this->context->current(), 'the message keeps its state');

        $this->dispatcher->dispatch(new WorkerMessageHandledEvent(new Envelope(new stdClass()), 'async'));
        self::assertNull($this->context->current());
    }

    public function testMainRequestHandledInsideACommandDoesNotClearTheCommandState(): void
    {
        $this->consoleCommand();
        $this->leak();

        // In-process http_kernel->handle() inside the command (e.g. rendering an e-mail).
        $this->request(HttpKernelInterface::MAIN_REQUEST);
        $this->finishRequest(HttpKernelInterface::MAIN_REQUEST);
        $this->terminate();
        self::assertSame('rule:leaked', $this->context->current(), 'the command keeps its state');

        $this->consoleTerminate();
        self::assertNull($this->context->current());
    }

    public function testSkippedMessageDoesNotBlockTheClearAfterTheWorkerStops(): void
    {
        $this->dispatcher->dispatch(new WorkerMessageReceivedEvent(new Envelope(new stdClass()), 'async'));
        $this->dispatcher->dispatch(new WorkerStoppedEvent(new Worker([], $this->createStub(MessageBusInterface::class))));
        $this->leak();

        $this->consoleTerminate();

        self::assertNull($this->context->current(), 'the stopped worker no longer counts as a message in flight');
    }

    public function testTerminateWithoutCommandEventStillClears(): void
    {
        $this->leak();
        $this->consoleTerminate();

        self::assertNull($this->context->current());
    }

    public function testBoundaryNeverBuildsAnUninitializedService(): void
    {
        $container = new ContainerBuilder();
        $container->register(TaskChangeContext::class)->setPublic(true);
        $container->register(TaskBoardRequestBoundarySubscriber::class)
            ->setPublic(true)
            ->setArguments([new IteratorArgument([new Reference(TaskChangeContext::class, ContainerInterface::IGNORE_ON_UNINITIALIZED_REFERENCE)])]);
        $container->compile(true);

        $subscriber = $container->get(TaskBoardRequestBoundarySubscriber::class);
        self::assertInstanceOf(TaskBoardRequestBoundarySubscriber::class, $subscriber);
        $subscriber->onMessageReceived();
        self::assertFalse($container->initialized(TaskChangeContext::class), 'the boundary does not build the memo');

        $context = $container->get(TaskChangeContext::class);
        self::assertInstanceOf(TaskChangeContext::class, $context);
        $this->leak($context);
        $subscriber->clear();
        self::assertNull($context->current(), 'the same subscriber clears a memo built later');
    }

    public function testResetStillClearsTheMemo(): void
    {
        $this->leak();
        $this->context->reset();

        self::assertNull($this->context->current());
    }

    /** A cause left behind by a write that crashed outside {@see TaskChangeContext::run()}'s finally. */
    private function leak(?TaskChangeContext $context = null): void
    {
        (new ReflectionProperty(TaskChangeContext::class, 'stack'))->setValue($context ?? $this->context, ['rule:leaked']);
    }

    private function request(int $type): void
    {
        $this->dispatcher->dispatch(new RequestEvent($this->kernel, new Request(), $type), KernelEvents::REQUEST);
    }

    private function terminate(): void
    {
        $this->dispatcher->dispatch(new TerminateEvent($this->kernel, new Request(), new Response()), KernelEvents::TERMINATE);
    }

    private function finishRequest(int $type): void
    {
        $this->dispatcher->dispatch(new FinishRequestEvent($this->kernel, new Request(), $type), KernelEvents::FINISH_REQUEST);
    }

    private function consoleCommand(): void
    {
        $this->dispatcher->dispatch(new ConsoleCommandEvent(new Command('app:probe'), new ArrayInput([]), new NullOutput()), ConsoleEvents::COMMAND);
    }

    private function consoleTerminate(): void
    {
        $this->dispatcher->dispatch(new ConsoleTerminateEvent(new Command('app:probe'), new ArrayInput([]), new NullOutput(), 0), ConsoleEvents::TERMINATE);
    }
}
