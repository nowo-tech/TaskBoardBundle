<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Fixtures\RemovedPorts\UnrelatedMissing;

use Nowo\TaskBoardBundle\Sync\Hook\SyncLimitCheckerPolicyInterface;

/**
 * 3.0 W1-1 fixture (autodiscovered): an application class implementing an unrelated missing interface whose name only
 * starts like a removed one; it keeps Symfony's own lazy error and the build succeeds.
 */
final class QuotaAdapter implements SyncLimitCheckerPolicyInterface
{
}
