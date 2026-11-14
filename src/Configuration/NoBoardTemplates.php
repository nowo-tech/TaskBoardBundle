<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Configuration;

use Nowo\TaskBoardBundle\ValueObject\TaskBoardSnapshot;

/**
 * Since 2.18.0: {@see BoardTemplatesConfigurationInterface} without task templates — none exported, none applied.
 */
final class NoBoardTemplates implements BoardTemplatesConfigurationInterface
{
    public function export(TaskBoardSnapshot $board): array
    {
        return [];
    }

    public function apply(TaskBoardSnapshot $board, array $templates): void
    {
    }
}
