<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Configuration;

use Nowo\TaskBoardBundle\ValueObject\TaskBoardSnapshot;

/**
 * Since 2.17.0 (Beacon block W): how the task templates of a board are read into and written from a
 * {@see BoardConfiguration}. The bundle's task templates answer it; the default ({@see NoBoardTemplates}) has none, so a
 * configuration's `templates` are kept as given and applying them changes nothing. Framework-free.
 */
interface BoardTemplatesConfigurationInterface
{
    /**
     * @return list<array<string, mixed>>
     */
    public function export(TaskBoardSnapshot $board): array;

    /**
     * Replaces the board's own templates with these, inside the applier's transaction (no flush, no commit).
     *
     * @param list<array<string, mixed>> $templates
     */
    public function apply(TaskBoardSnapshot $board, array $templates): void;
}
