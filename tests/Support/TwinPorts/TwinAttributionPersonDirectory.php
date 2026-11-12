<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Support\TwinPorts;

use Nowo\TaskBoardBundle\Sync\Hook\AttributionOnlyPolicyInterface;
use Nowo\TaskBoardBundle\Sync\Hook\AttributionOnlyResolverInterface;
use Nowo\TaskBoardBundle\ValueObject\TaskBoardSnapshot;
use RuntimeException;

final class TwinAttributionPersonDirectory extends TwinPersonDirectory implements AttributionOnlyPolicyInterface
{
    public function isAttributionOnly(TaskBoardSnapshot $board, object $user): bool
    {
        if (!$this->legacy instanceof AttributionOnlyResolverInterface) {
            throw new RuntimeException('The legacy stub is not attribution-aware.');
        }

        return $this->legacy->isAttributionOnly($this->lookup->board($board->id), $user);
    }
}
