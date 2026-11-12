<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Hook;

use Nowo\TaskBoardBundle\ValueObject\TaskBoardSnapshot;

/**
 * Since 2.15.0 (REQ-ARCH-001 rule 1): the framework-free twin of {@see AttributionOnlyResolverInterface}, optional on the
 * host's person resolver ({@see SyncPersonResolverInterface} or {@see SyncPersonDirectoryInterface}): a candidate offered
 * for attribution only, given the board as a {@see TaskBoardSnapshot}.
 */
interface AttributionOnlyPolicyInterface
{
    public function isAttributionOnly(TaskBoardSnapshot $board, object $user): bool;
}
