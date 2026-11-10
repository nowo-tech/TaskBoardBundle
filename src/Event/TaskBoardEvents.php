<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Event;

use Nowo\TaskBoardBundle\Sync\Event\BoardConnectionEvent;
use Nowo\TaskBoardBundle\Sync\Event\PeopleMappingChangedEvent;
use Nowo\TaskBoardBundle\Sync\Event\SyncFailedEvent;
use Nowo\TaskBoardBundle\Sync\Event\SyncFinishedEvent;
use Nowo\TaskBoardBundle\Sync\Event\SyncPageDoneEvent;
use Nowo\TaskBoardBundle\Sync\Event\SyncPeopleReprojectedEvent;
use Nowo\TaskBoardBundle\Sync\Event\SyncPeopleReviewedEvent;
use Nowo\TaskBoardBundle\Sync\Event\SyncPersonAnonymisedEvent;
use Nowo\TaskBoardBundle\Sync\Event\SyncPersonMappedEvent;
use Nowo\TaskBoardBundle\Sync\Event\SyncPersonReassignedEvent;
use Nowo\TaskBoardBundle\Sync\Event\SyncStartedEvent;
use Nowo\TaskBoardBundle\Sync\Event\TaskRemoteArchivedEvent;
use Nowo\TaskBoardBundle\Sync\Event\TaskRemoteRestoredEvent;
use Nowo\TaskBoardBundle\Sync\Event\UnmatchedColumnsResolvedEvent;

/**
 * Event names for TaskBoardBundle extensibility hooks.
 */
final class TaskBoardEvents
{
    public const BOARD_LIST_QUERY = 'nowo_task_board.board_list_query';

    /** @deprecated since 2.10.0 (DDD audit G5): never dispatched; removed in 3.0. */
    public const BOARD_LIST_RESULT = 'nowo_task_board.board_list_result';

    /** @deprecated since 2.10.0 (DDD audit G5): never dispatched; removed in 3.0. */
    public const TASK_LIST_QUERY = 'nowo_task_board.task_list_query';

    /** @deprecated since 2.10.0 (DDD audit G5): never dispatched; removed in 3.0. */
    public const TASK_LIST_RESULT = 'nowo_task_board.task_list_result';

    public const BOARD_ACCESS_CHECK = 'nowo_task_board.board_access_check';

    public const TASK_ACCESS_CHECK = 'nowo_task_board.task_access_check';

    /** @deprecated since 2.10.0 (DDD audit G5): never dispatched; removed in 3.0. */
    public const TASK_READ_ONLY_RESOLVE = 'nowo_task_board.task_read_only_resolve';

    /** @deprecated since 2.10.0 (DDD audit G5): never dispatched; removed in 3.0. */
    public const MEMBER_LIST_QUERY = 'nowo_task_board.member_list_query';

    /** {@see TaskCreatedEvent}, after flush (since 1.10). */
    public const TASK_CREATED = 'nowo_task_board.task_created';

    /** {@see TaskUpdatedEvent}, after flush (since 1.10). */
    public const TASK_UPDATED = 'nowo_task_board.task_updated';

    /** {@see TaskMovedEvent}, after flush (since 1.10). */
    public const TASK_MOVED = 'nowo_task_board.task_moved';

    /** {@see TaskCompletedEvent}, after flush (since 1.10). */
    public const TASK_COMPLETED = 'nowo_task_board.task_completed';

    /** {@see TaskReopenedEvent}, after flush (since 1.10). */
    public const TASK_REOPENED = 'nowo_task_board.task_reopened';

    /** {@see TaskMemberAddedEvent}, after flush (since 1.10). */
    public const TASK_MEMBER_ADDED = 'nowo_task_board.task_member_added';

    /** {@see TaskCommentEvent}: a comment was created or edited, after flush (since 1.16). */
    public const TASK_COMMENT = 'nowo_task_board.task_comment';

    /** {@see TaskMentionEvent}: a comment mentions people (new mentions only on an edit), after flush (since 1.16). */
    public const TASK_MENTION = 'nowo_task_board.task_mention';

    /** Since 2.3.0 (spec 217): {@see TaskBoardChangedEvent}, after the commit of a move. TASK_MOVED is not dispatched. */
    public const TASK_BOARD_CHANGED = 'nowo_task_board.task_board_changed';

    /** {@see ColumnSetQueryEvent}: scope / list of the column sets (since 1.11). */
    public const COLUMN_SET_QUERY = 'nowo_task_board.column_set_query';

    /** {@see ColumnSetAccessCheckEvent}: who may delete a column set (since 1.11). */
    public const COLUMN_SET_ACCESS_CHECK = 'nowo_task_board.column_set_access_check';

    /** {@see SyncStartedEvent} (since 1.23). */
    public const SYNC_STARTED = 'nowo_task_board.sync_started';

    /** {@see SyncPageDoneEvent} (since 1.23). */
    public const SYNC_PAGE_DONE = 'nowo_task_board.sync_page_done';

    /** {@see SyncFinishedEvent} (since 1.23). */
    public const SYNC_FINISHED = 'nowo_task_board.sync_finished';

    /** {@see SyncFailedEvent} (since 1.23). */
    public const SYNC_FAILED = 'nowo_task_board.sync_failed';

    /** {@see TaskRemoteArchivedEvent}, after flush (since 1.23). */
    public const TASK_REMOTE_ARCHIVED = 'nowo_task_board.task_remote_archived';

    /** {@see TaskRemoteRestoredEvent}, after flush (since 1.23). */
    public const TASK_REMOTE_RESTORED = 'nowo_task_board.task_remote_restored';

    /** {@see BoardConnectionEvent} (since 1.23). */
    public const BOARD_CONNECTION_LINKED = 'nowo_task_board.board_connection_linked';

    /** {@see BoardConnectionEvent} (since 1.23). */
    public const BOARD_CONNECTION_UNLINKED = 'nowo_task_board.board_connection_unlinked';

    /** Since 1.25: {@see SyncPersonMappedEvent} — every mapping change (automatic ones too). */
    public const SYNC_PERSON_MAPPED = 'nowo_task_board.sync_person_mapped';

    /** Since 1.25: {@see SyncPersonReassignedEvent} — a re-association finished. */
    public const SYNC_PERSON_REASSIGNED = 'nowo_task_board.sync_person_reassigned';

    /** Since 1.25: {@see SyncPersonAnonymisedEvent}. */
    public const SYNC_PERSON_ANONYMISED = 'nowo_task_board.sync_person_anonymised';

    /** Since 1.25: {@see SyncPeopleReviewedEvent} — the People step was saved. */
    public const SYNC_PEOPLE_REVIEWED = 'nowo_task_board.sync_people_reviewed';

    /** Since 1.25: {@see SyncPeopleReprojectedEvent} — references became real rows. */
    public const SYNC_PEOPLE_REPROJECTED = 'nowo_task_board.sync_people_reprojected';

    /** Since 1.27.0: {@see PeopleMappingChangedEvent} — the People page saved mapping changes (one event per save, for the host's audit). */
    public const SYNC_PEOPLE_MAPPING_CHANGED = 'nowo_task_board.sync_people_mapping_changed';

    /** {@see TaskErasedEvent}, before the task's batch is flushed (since 1.25). */
    public const TASK_ERASED = 'nowo_task_board.task_erased';

    /** {@see BoardErasedEvent}, once the board is empty (since 1.25). */
    public const BOARD_EMPTIED = 'nowo_task_board.board_emptied';

    /** {@see BoardCreatedEvent}, after the board is saved (since 1.30.0). */
    public const BOARD_CREATED = 'nowo_task_board.board_created';

    /** {@see BoardErasedEvent}, once the board is deleted (since 1.25). */
    public const BOARD_DELETED = 'nowo_task_board.board_deleted';

    /** Since 2.0.0: {@see TaskBoardQuotaCheckEvent}, before a board or tasks are written. */
    public const QUOTA_CHECK = 'nowo_task_board.quota_check';

    /** Since 2.2.0 (spec 213): {@see BoardUpdatedEvent}. */
    public const BOARD_UPDATED = 'nowo_task_board.board_updated';

    /** Since 2.2.0 (spec 213): {@see TaskApiWriteEvent}, one per write through the API. */
    public const API_WRITE = 'nowo_task_board.api_write';

    /** Since 1.28.0: {@see UnmatchedColumnsResolvedEvent}. */
    public const SYNC_UNMATCHED_COLUMNS_RESOLVED = 'nowo_task_board.sync_unmatched_columns_resolved';

    /** Since 2.10.0 (spec 216): {@see TaskArchivedEvent}, ids only, after a task is archived by hand (board or API). */
    public const TASK_ARCHIVED = 'nowo_task_board.task_archived';

    /** Since 2.10.0 (spec 216): {@see TaskRestoredEvent}, ids only, after an archived task is restored by hand. */
    public const TASK_RESTORED = 'nowo_task_board.task_restored';

    /** Since 2.10.0 (spec 216): {@see TaskCommentDeletedEvent}, ids only, after a comment is deleted. */
    public const TASK_COMMENT_DELETED = 'nowo_task_board.task_comment_deleted';

    /** Since 2.10.0 (spec 216): {@see ColumnCreatedEvent}, after a column is added. */
    public const COLUMN_CREATED = 'nowo_task_board.column_created';

    /** Since 2.10.0 (spec 216): {@see ColumnUpdatedEvent}, after a column is renamed or its colour or icon changes. */
    public const COLUMN_UPDATED = 'nowo_task_board.column_updated';

    /** Since 2.10.0 (spec 216): {@see ColumnDeletedEvent}, after a column is deleted. */
    public const COLUMN_DELETED = 'nowo_task_board.column_deleted';

    /** Since 2.10.0 (spec 216): {@see ColumnsReorderedEvent}, after a board's columns get a new order. */
    public const COLUMNS_REORDERED = 'nowo_task_board.columns_reordered';

    /** Since 2.10.0 (spec 216, FR-010): {@see ColumnDeleteCheckEvent}, warnings the column delete dialog shows. */
    public const COLUMN_DELETE_CHECK = 'nowo_task_board.column_delete_check';

    /** Since 2.11.0 (DDD audit G3): {@see TaskTimeLoggedEvent}, ids only, after a manual time entry is logged. */
    public const TASK_TIME_LOGGED = 'nowo_task_board.task_time_logged';

    /** Since 2.11.0 (DDD audit G3): {@see TaskTimeRemovedEvent}, ids only, after a manual time entry is removed. */
    public const TASK_TIME_REMOVED = 'nowo_task_board.task_time_removed';

    /** Since 2.11.0 (DDD audit G3): {@see TaskDependencyAddedEvent}, ids only, after a dependency is added. */
    public const TASK_DEPENDENCY_ADDED = 'nowo_task_board.task_dependency_added';

    /** Since 2.11.0 (DDD audit G3): {@see TaskDependencyRemovedEvent}, ids only, after a dependency is removed. */
    public const TASK_DEPENDENCY_REMOVED = 'nowo_task_board.task_dependency_removed';

    /** Since 2.11.0 (DDD audit G3): {@see TaskLinkAddedEvent}, ids only, after a link (or a host object link) is added. */
    public const TASK_LINK_ADDED = 'nowo_task_board.task_link_added';

    /** Since 2.11.0 (DDD audit G3): {@see TaskLinkUpdatedEvent}, ids only, after a link is edited. */
    public const TASK_LINK_UPDATED = 'nowo_task_board.task_link_updated';

    /** Since 2.11.0 (DDD audit G3): {@see TaskLinkRemovedEvent}, ids only, after a link (or a host object link) is removed. */
    public const TASK_LINK_REMOVED = 'nowo_task_board.task_link_removed';

    /** Since 2.11.0 (DDD audit G3): {@see TaskMemberRemovedEvent}, ids only, after a member leaves a task. */
    public const TASK_MEMBER_REMOVED = 'nowo_task_board.task_member_removed';
}
