<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Provenance;

use Nowo\TaskBoardBundle\Provenance\Attribute\AsWriteChannel;
use Nowo\TaskBoardBundle\Provenance\NoWriteProvenance;
use Nowo\TaskBoardBundle\Provenance\ScopedWriteProvenance;
use Nowo\TaskBoardBundle\Provenance\WriteChannel;
use Nowo\TaskBoardBundle\Provenance\WriteChannelControllerListener;
use Nowo\TaskBoardBundle\Provenance\WriteChannelScope;
use Nowo\TaskBoardBundle\Provenance\WriteProvenance;
use Nowo\TaskBoardBundle\Provenance\WriteProvenanceProviderInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Since 3.7.0 (Beacon spec 235, FR-004): the channel an entry point declares — call-scoped, nested, popped on failure,
 * cleared between the requests of a worker — and how the bundle's services read it after the host's provider.
 */
final class WriteChannelScopeTest extends TestCase
{
    public function testTheChannelLivesOnlyInsideItsCallAndTheInnermostWins(): void
    {
        $scope = new WriteChannelScope();
        self::assertNull($scope->current());

        $seen = $scope->run(WriteChannel::Web, static fn (): array => [
            $scope->current(),
            $scope->run(WriteChannel::Cli, static fn (): ?WriteChannel => $scope->current()),
            $scope->current(),
        ]);
        self::assertSame([WriteChannel::Web, WriteChannel::Cli, WriteChannel::Web], $seen);
        self::assertNull($scope->current());

        try {
            $scope->run(WriteChannel::Api, static fn (): never => throw new RuntimeException('write failed'));
            self::fail('Expected the failure');
        } catch (RuntimeException) {
            self::assertNull($scope->current(), 'popped when the call throws');
        }
    }

    public function testResetClearsAChannelLeftBehind(): void
    {
        $scope = new WriteChannelScope();
        $scope->run(WriteChannel::App, static function () use ($scope): void {
            $scope->reset();
            self::assertNull($scope->current());
        });
        self::assertNull($scope->current());
    }

    public function testTheHostsProviderWinsThenTheDeclaredChannel(): void
    {
        $scope = new WriteChannelScope();
        $host  = new class implements WriteProvenanceProviderInterface {
            public ?WriteProvenance $answer = null;

            public function current(): ?WriteProvenance
            {
                return $this->answer;
            }
        };
        $provenance = new ScopedWriteProvenance($host, $scope);
        self::assertNull($provenance->current(), 'neither: the columns stay NULL');
        self::assertNull((new ScopedWriteProvenance(new NoWriteProvenance(), $scope))->current());

        $declared = $scope->run(WriteChannel::Api, static fn (): ?WriteProvenance => $provenance->current());
        self::assertInstanceOf(WriteProvenance::class, $declared);
        self::assertSame(WriteChannel::Api, $declared->channel);
        self::assertNull($declared->actorLabel);

        $host->answer = new WriteProvenance(WriteChannel::Mcp, 'Claude via MCP');
        self::assertSame($host->answer, $scope->run(WriteChannel::Api, static fn (): ?WriteProvenance => $provenance->current()));
    }

    public function testTheListenerRunsADeclaringControllerInsideItsChannel(): void
    {
        self::assertSame([KernelEvents::CONTROLLER_ARGUMENTS => ['onControllerArguments', -4096]], WriteChannelControllerListener::getSubscribedEvents());
        $scope    = new WriteChannelScope();
        $listener = new WriteChannelControllerListener($scope);

        $controller = new WriteChannelScopeTestController($scope);
        // An array controller, as the kernel's controller resolver gives it for `Class::action`.
        foreach ([['page', WriteChannel::Web], ['console', WriteChannel::Cli]] as [$action, $expected]) {
            $callable = [$controller, $action];
            self::assertIsCallable($callable);
            $event = $this->event($callable, ['a', 'b']);
            $listener->onControllerArguments($event);
            self::assertNotSame($callable, $event->getController());
            self::assertSame([$expected, 'a', 'b'], ($event->getController())(...$event->getArguments()));
            self::assertNotSame([], $event->getAttributes(AsWriteChannel::class), 'the attributes are kept');
            self::assertNull($scope->current());
        }

        $plain = static fn (string $a): string => $a;
        $event = $this->event($plain, ['x']);
        $listener->onControllerArguments($event);
        self::assertSame($plain, $event->getController(), 'a controller that declares nothing is left alone');
    }

    /**
     * @param list<string> $arguments
     */
    private function event(callable $controller, array $arguments): ControllerArgumentsEvent
    {
        return new ControllerArgumentsEvent($this->createStub(HttpKernelInterface::class), $controller, $arguments, new Request(), HttpKernelInterface::MAIN_REQUEST);
    }
}

#[AsWriteChannel(WriteChannel::Web)]
final readonly class WriteChannelScopeTestController
{
    public function __construct(private WriteChannelScope $scope)
    {
    }

    /** @return list<mixed> */
    public function page(string $a, string $b): array
    {
        return [$this->scope->current(), $a, $b];
    }

    /** @return list<mixed> */
    #[AsWriteChannel(WriteChannel::Cli)]
    public function console(string $a, string $b): array
    {
        return [$this->scope->current(), $a, $b];
    }
}
