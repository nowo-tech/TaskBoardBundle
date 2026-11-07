<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Service;

use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Event\TaskBoardQuotaCheckEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Since 2.0.0: asks the host's quota listeners before a write ({@see TaskBoardQuotaCheckEvent}). The write paths that ask
 * are listed in docs/USAGE.md; provider syncs and restores ask `SyncLimitCheckerInterface` instead.
 */
final readonly class TaskBoardQuota
{
    public function __construct(private ?EventDispatcherInterface $dispatcher = null)
    {
    }

    /** The reason a listener refused the write, or null when it may go ahead. */
    public function refusal(string $action, ?string $tenantKey, ?TaskBoard $board, int $count, object $actor): ?string
    {
        if (!$this->dispatcher instanceof EventDispatcherInterface) {
            return null;
        }
        $event = new TaskBoardQuotaCheckEvent($action, $tenantKey, $board, $count, $actor);
        $this->dispatcher->dispatch($event, TaskBoardEvents::QUOTA_CHECK);

        return $event->getReason();
    }
}
