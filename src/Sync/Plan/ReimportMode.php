<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Plan;

/**
 * Since 3.2.0 (CU-2, Q-CU-8): what a (re-)import does with the tasks it already imported. There is no mode that
 * overwrites local edits: *Update changed* writes only the fields nobody changed here (the field-hash rule).
 */
enum ReimportMode: string
{
    /** New tasks are created; changed ones are updated by the field-hash rule (the default). */
    case UpdateChanged = 'update';

    /** Only new tasks are created; existing ones stay untouched, deletions are not archived. */
    case AddNewOnly = 'add_new';
}
