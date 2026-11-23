<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Fixtures\RemovedPorts\DecoratorOfLimitChecker;

use Nowo\TaskBoardBundle\Sync\Hook\LimitRefusal;
use Nowo\TaskBoardBundle\Sync\Hook\SyncQuotaPolicyInterface;
use Nowo\TaskBoardBundle\ValueObject\TaskBoardSnapshot;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

/**
 * 3.0 W1-1 fixture (autodiscovered, loadable): a host restriction of the sync quotas moved to the framework-free
 * interface but still decorating the removed SyncLimitCheckerInterface id. Silently dropped, the quota would be lost.
 */
#[AsDecorator('Nowo\TaskBoardBundle\Sync\Hook\SyncLimitCheckerInterface')]
final class StricterQuotas implements SyncQuotaPolicyInterface
{
    public function check(TaskBoardSnapshot $board, int $newTasks, int $newBytes): ?LimitRefusal
    {
        return new LimitRefusal('quota');
    }
}
