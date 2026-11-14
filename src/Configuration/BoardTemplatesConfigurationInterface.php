<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Configuration;

use Nowo\TaskBoardBundle\ValueObject\TaskBoardSnapshot;

/**
 * Since 2.18.0 (Beacon block W): how the task templates of a board are read into and written from a
 * {@see BoardConfiguration}. The default ({@see NoBoardTemplates}) has none, so a configuration's `templates` are kept as
 * given and applying them changes nothing; the bundle's own task templates (2.16) are not wired to it yet, so a host that
 * wants them in its presets aliases this interface to its own service. Framework-free.
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
