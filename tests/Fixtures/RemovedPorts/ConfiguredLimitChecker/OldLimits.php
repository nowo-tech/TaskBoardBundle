<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Fixtures\RemovedPorts\ConfiguredLimitChecker;

use Nowo\TaskBoardBundle\Sync\Hook\SyncLimitCheckerInterface;

/**
 * 3.0 W1-1 fixture (registered by hand, NOT loadable): the service `sync.limit_checker` names, still on the removed
 * SyncLimitCheckerInterface.
 */
final class OldLimits implements SyncLimitCheckerInterface
{
}
