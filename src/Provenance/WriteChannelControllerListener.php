<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Provenance;

use Nowo\TaskBoardBundle\Provenance\Attribute\AsWriteChannel;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Since 3.7.0 (Beacon spec 235, FR-004): runs a controller that declares {@see AsWriteChannel} inside
 * {@see WriteChannelScope::run()}, so the writes of that one call carry the controller's channel. The last listener of
 * `kernel.controller_arguments` (after the security, CSRF and argument listeners): it wraps the resolved controller
 * with its resolved arguments and changes nothing else. Nothing is kept on the service (FrankenPHP worker mode); the
 * scope is popped when the controller returns or throws.
 */
final readonly class WriteChannelControllerListener implements EventSubscriberInterface
{
    public function __construct(private WriteChannelScope $scope)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::CONTROLLER_ARGUMENTS => ['onControllerArguments', -4096]];
    }

    public function onControllerArguments(ControllerArgumentsEvent $event): void
    {
        $channel = null;
        // Class attributes come first, the action's last: the action's wins.
        foreach ($event->getAttributes(AsWriteChannel::class) as $declared) {
            if ($declared instanceof AsWriteChannel) {
                $channel = $declared->channel;
            }
        }
        if (!$channel instanceof WriteChannel) {
            return;
        }
        $controller = $event->getController();
        $scope      = $this->scope;
        $event->setController(
            // @igor-ignore - The closure runs once, for this request's controller call; nothing keeps it after the response.
            static fn (mixed ...$arguments): mixed => $scope->run($channel, static fn (): mixed => $controller(...$arguments)),
            $event->getAttributes(),
        );
    }
}
