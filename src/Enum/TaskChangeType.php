<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Enum;

/**
 * Types of auditable changes on a task.
 */
enum TaskChangeType: string
{
    case Created          = 'created';
    case Title            = 'title';
    case Description      = 'description';
    case Priority         = 'priority';
    case Column           = 'column';
    case DueDate          = 'due_date';
    case StartDate        = 'start_date';
    case EstimatedMinutes = 'estimated_minutes';
    case Tags             = 'tags';
    case Color            = 'color';
    case Completed        = 'completed';
    case LinkAdded        = 'link_added';
    case LinkUpdated      = 'link_updated';
    case LinkRemoved      = 'link_removed';
    case MemberAdded      = 'member_added';
    case MemberRemoved    = 'member_removed';
    // Since 1.15.
    case DependencyAdded   = 'dependency_added';
    case DependencyRemoved = 'dependency_removed';
    case TimeLogged        = 'time_logged';
    case TimeRemoved       = 'time_removed';
    /** Since 1.23: a provider sync kept a field edited here and the provider changed it too. */
    case SyncConflict = 'sync_conflict';
    /** Since 1.23. */
    case Archived = 'archived';
    /** Since 1.23. */
    case Restored = 'restored';
    /** Since 1.25: a remote person's authorship or membership was re-associated (values are person: / user: tokens). */
    case SyncPersonReassociated = 'sync_person_reassociated';
    /** Since 2.3.0 (spec 217): the task changed boards; old and new values are the board names. */
    case BoardMoved = 'board_moved';
    /** Since 2.3.0 (spec 217): the task is a copy; the old value is the source's number or title, `context` "task:<id>". */
    case Duplicated = 'duplicated';
    /** Since 2.3.0 (specs 215, 217): a member of the source left out of a copy (no longer assignable); new value: the label. */
    case MemberNotCopied = 'member_not_copied';
}
