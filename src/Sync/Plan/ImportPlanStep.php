<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Plan;

use function array_search;

/**
 * Since 3.2.0 (CU-2): the steps of the guided import, in order. Each is a full page without JavaScript; a step can be
 * opened once every step before it was saved.
 */
enum ImportPlanStep: string
{
    case Connect   = 'connect';
    case Workspace = 'workspace';
    case Tree      = 'tree';
    case Boards    = 'boards';
    case Statuses  = 'statuses';
    case Fields    = 'fields';
    case People    = 'people';
    case Preview   = 'preview';
    case Run       = 'run';

    /** The routes' requirement for a step that is saved by a POST. */
    public const REQUIREMENT = 'connect|workspace|tree|boards|statuses|fields|people|preview';

    public function position(): int
    {
        return (int) array_search($this, self::cases(), true);
    }

    /** The step after this one (Run is the last). */
    public function next(): self
    {
        return self::cases()[$this->position() + 1] ?? self::Run;
    }
}
