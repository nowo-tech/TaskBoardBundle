<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Plan;

/**
 * Since 3.2.0 (CU-2, Q-CU-5): how the chosen ClickUp containers become boards. One board per list is the default; a
 * board follows one source (a list, a folder or a space), so "one board for everything" takes exactly one container.
 */
enum ImportPlanLayout: string
{
    /** Each chosen list is a board; a chosen folder or space brings its lists, each a board. */
    case PerList = 'per_list';

    /** Each chosen folder is a board (its lists as `list:<name>` tags); a chosen list stays a board of its own. */
    case PerFolder = 'per_folder';

    /** The one chosen container (a space, a folder or a list) is one board. */
    case Single = 'single';
}
