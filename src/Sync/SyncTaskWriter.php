<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Assignee\TaskAssigneeProviderInterface;
use Nowo\TaskBoardBundle\Dto\BoardColumnFormData;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Entity\TaskDocument;
use Nowo\TaskBoardBundle\Entity\TaskLink;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Enum\TaskDependencyType;
use Nowo\TaskBoardBundle\Enum\TaskLinkType;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Exception\InvalidTaskDependencyException;
use Nowo\TaskBoardBundle\Import\Dto\ImportedTaskDto;
use Nowo\TaskBoardBundle\Import\ImportAssigneeResolver;
use Nowo\TaskBoardBundle\Import\ImportedTaskUpdater;
use Nowo\TaskBoardBundle\Import\TaskImportLinkLookupInterface;
use Nowo\TaskBoardBundle\Import\TaskImportUserResolverInterface;
use Nowo\TaskBoardBundle\Repository\ArchiveAwareTaskRepositoryInterface;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Service\BoardColumnManager;
use Nowo\TaskBoardBundle\Service\TaskChangeRecorder;
use Nowo\TaskBoardBundle\Service\TaskDependencyManager;
use Nowo\TaskBoardBundle\Support\Like;
use Nowo\TaskBoardBundle\Support\Text;
use Nowo\TaskBoardBundle\Sync\Content\RemoteContentSanitizer;
use Nowo\TaskBoardBundle\Sync\Dto\SyncConflict;
use Nowo\TaskBoardBundle\Sync\Dto\SyncOptions;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncAttachment;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncComment;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncDirectory;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncItem;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncPerson;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderException;
use Nowo\TaskBoardBundle\Sync\Exception\UnsafeRemoteFileException;
use Nowo\TaskBoardBundle\Sync\Field\SyncCustomFieldWriter;
use Nowo\TaskBoardBundle\Sync\File\RemoteFileFetcher;
use Nowo\TaskBoardBundle\Sync\Hook\InlineImageRewriterInterface;
use Nowo\TaskBoardBundle\Sync\Hook\LimitRefusal;
use Nowo\TaskBoardBundle\Sync\Hook\StoredAttachment;
use Nowo\TaskBoardBundle\Sync\Hook\SyncLimitCheckerInterface;
use Nowo\TaskBoardBundle\Sync\Hook\TaskAttachmentStorageInterface;
use Nowo\TaskBoardBundle\Sync\People\PeopleSession;
use Nowo\TaskBoardBundle\Sync\People\SyncPeopleWriter;
use Nowo\TaskBoardBundle\Sync\People\TaskSyncPeopleDirectoryService;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteAttachment;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteComment;
use Nowo\TaskBoardBundle\Sync\Provider\RemotePersonRef;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteStatusType;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteTask;
use Nowo\TaskBoardBundle\Sync\Provider\ReplyBudget;
use Nowo\TaskBoardBundle\Sync\Provider\TaskDetailProviderInterface;
use Nowo\TaskBoardBundle\Sync\Provider\ThreadedCommentProviderInterface;
use Nowo\TaskBoardBundle\Sync\Repository\SyncStore;
use Nowo\TaskBoardBundle\Sync\Status\StatusColumns;
use Stringable;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

use function addcslashes;
use function array_filter;
use function array_key_last;
use function array_keys;
use function array_map;
use function array_slice;
use function array_unique;
use function array_values;
use function count;
use function hash;
use function implode;
use function is_array;
use function is_scalar;
use function json_encode;
use function max;
use function mb_strtolower;
use function method_exists;
use function sha1;
use function sort;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function time;
use function trim;

use const JSON_PRESERVE_ZERO_FRACTION;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * @internal since 1.23: writes remote pages to a board — the per-task rules of the contract (§3): create, adopt,
 * update field by field with conflicts, archive and restore, files and comments. Stateless; the run state is the
 * {@see SyncRunContext}.
 */
final readonly class SyncTaskWriter
{
    /** The synced fields. */
    /** The longest description stored (bytes). */
    public const MAX_DESCRIPTION_BYTES = 100_000;

    /** Since 1.27.0: tracked files of comments, `comment:<sha1 of the comment id>:<sha1 of the file id>`. */
    private const COMMENT_FILE_PREFIX = 'comment:';

    public const FIELDS = ['title', 'description', 'priority', 'column', 'start', 'due', 'estimate', 'tags', 'assignees', 'parent'];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private SyncStore $store,
        private ArchiveAwareTaskRepositoryInterface $tasks,
        private TaskBoardResourceAccessChecker $accessChecker,
        private TaskImportUserResolverInterface $userResolver,
        private BoardColumnManager $columnManager,
        private RemoteFileFetcher $fetcher,
        private TaskAttachmentStorageInterface $storage,
        private InlineImageRewriterInterface $rewriter,
        private SyncLimitCheckerInterface $limitChecker,
        private TaskImportLinkLookupInterface $linkLookup,
        private TaskDependencyManager $dependencyManager,
        private ImportedTaskUpdater $updater = new ImportedTaskUpdater(),
        private TaskChangeRecorder $changeRecorder = new TaskChangeRecorder(),
        private RemoteContentSanitizer $sanitizer = new RemoteContentSanitizer(),
        private ?TaskAssigneeProviderInterface $assigneeProvider = null,
        private ?TranslatorInterface $translator = null,
        private bool $storageEnabled = false,
        private int $maxFileBytes = 26_214_400,
        // Since 1.25: the people directory (creator, assignees, watchers, comment authors through the mapping).
        private ?TaskSyncPeopleDirectoryService $peopleDirectory = null,
        private ?SyncPeopleWriter $peopleWriter = null,
        // Since 1.25: `sync.list_as_tag` (tag "list:<name>" on tasks of a multi-list source), checklists, watchers.
        private bool $listAsTag = false,
        private bool $checklistsEnabled = true,
        private bool $watchersEnabled = true,
        private ?SyncChecklistWriter $checklistWriter = null,
        private int $maxDetailCalls = 500,
        // Since 1.27.0 (TB-SYNC-3): typed custom fields (`sync.custom_fields.mode`: typed, text or both).
        private ?SyncCustomFieldWriter $fieldWriter = null,
        private string $fieldsMode = 'typed',
        // Since 1.27.0 (TB-SYNC-3): details, status history and completeness; archived tasks imported archived.
        private ?SyncTaskDetailsWriter $detailsWriter = null,
        private bool $importArchived = true,
        // Since 1.27.0 (audit GW-L6): reply requests per run.
        private int $maxReplyCalls = 500,
    ) {
    }

    /**
     * One page: plans every task, asks the host's limit, writes the tasks in one transaction (rolled back on a dry
     * run), then the files and comments of the tasks it created or changed.
     *
     * @param list<RemoteTask> $remoteTasks
     *
     * @throws SyncRefusedException when the host's limit refuses the page (nothing is written)
     */
    public function writePage(SyncRunContext $ctx, array $remoteTasks): void
    {
        /** @var array<string, RemoteTask> $rows */
        $rows = [];
        foreach ($remoteTasks as $remote) {
            $id = Text::cut($remote->externalId(), 128);
            if (isset($ctx->seen[$id])) {
                ++$ctx->skipRepeated;
                continue;
            }
            $ctx->seen[$id] = true;
            $rows[$id]      = $remote;
            ++$ctx->tasksSeen;
            // Since 1.27.0: the completeness report covers every task read, written or not.
            $this->detailsWriter?->countKeys($ctx, $remote);
            if (!$ctx->highWatermark instanceof DateTimeImmutable || $remote->remoteUpdatedAt > $ctx->highWatermark) {
                $ctx->highWatermark = $remote->remoteUpdatedAt;
            }
        }
        if ($rows === []) {
            return;
        }

        $ids   = array_map(strval(...), array_keys($rows));
        $items = $this->store->itemsByExternalIds($ctx->connection, $ids);
        $links = $this->tasks->findImportedByExternalIds($ctx->board, $ctx->scope, $ids);
        $known = $links;
        foreach ($items as $id => $item) {
            $task = $item->getTask();
            if ($task instanceof Task) {
                $known[$id] = [$task, null];
            }
        }
        $elsewhere = $this->elsewhere($ctx, $ids, $items, $links);
        $related   = $this->relatedTasks($ctx, $rows, $known);

        // Plan first (no write): the host's limit is asked with the page's new tasks.
        $plan     = [];
        $newTasks = 0;
        foreach ($this->parentsFirst($rows) as $id => $remote) {
            $item = $items[$id] ?? null;
            if ($item instanceof TaskSyncItem && $item->isTombstone()) {
                ++$ctx->skipLocallyDeleted;
                continue;
            }
            // Since 1.25: a status the dialog's map skips is not imported (nor updated).
            if (StatusColumns::target($ctx->connection->getStatusColumnMap(), $remote->task->status) === StatusColumns::SKIP) {
                $ctx->count('skipStatus');
                continue;
            }
            $task = $known[$id][0] ?? null;
            if (!$task instanceof Task) {
                if (isset($elsewhere[$id])) {
                    ++$ctx->skipElsewhere;
                } elseif ($remote->archived && !$this->importArchived) {
                    ++$ctx->unchanged;
                } else {
                    $plan[] = ['create', $id, $remote, null, $item];
                    // An archived task is not an active one for the host's limit (like the restore below, in reverse).
                    $newTasks += $remote->archived ? 0 : 1;
                }
                continue;
            }
            if (!$this->canEdit($ctx, $task)) {
                ++$ctx->skipReadOnly;
                continue;
            }
            if ($task->isArchived() && $task->getArchiveReason() !== Task::ARCHIVE_REASON_REMOTE) {
                ++$ctx->skipLocallyArchived;
                continue;
            }
            if ($task->isArchived() && $remote->archived) {
                ++$ctx->unchanged;
                continue;
            }
            if ($task->isArchived()) {
                ++$newTasks;
            }
            $plan[] = [$task->isArchived() ? 'restore' : ($remote->archived ? 'archive' : 'update'), $id, $remote, $task, $item];
        }

        if ($newTasks > 0) {
            $refusal = $this->limitChecker->check($ctx->board, $newTasks, 0);
            if ($refusal instanceof LimitRefusal) {
                throw new SyncRefusedException($refusal);
            }
        }

        $post       = [];
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $columns   = $this->columns($ctx, $plan);
            $assignees = new ImportAssigneeResolver($this->userResolver, $this->assigneeProvider, $ctx->board);
            $session   = $this->session($ctx);
            foreach ($plan as [$action, $id, $remote, $task, $item]) {
                if ($action === 'create') {
                    $post[] = $created = $this->create($ctx, $id, $remote, $columns, $assignees, $related, $session);
                    if ($remote->archived) {
                        // Since 1.27.0: an archived provider task is imported archived.
                        $this->markArchived($ctx, $created[1], $created[0], $id);
                    }
                } elseif ($action === 'archive' && $task instanceof Task) {
                    $this->markArchived($ctx, $task, $item ?? $this->adopt($ctx, $id, $task), $id);
                } elseif ($task instanceof Task) {
                    if ($action === 'restore') {
                        $item ??= $this->adopt($ctx, $id, $task);
                        $this->markRestored($ctx, $task, $item, $id);
                    }
                    $result = $this->update($ctx, $id, $remote, $task, $item, $columns, $assignees, $related, $action === 'restore', $session);
                    if ($result !== null) {
                        $post[] = $result;
                    }
                }
            }
            $this->entityManager->flush();
            if ($assignees->warnings() !== []) {
                $ctx->warn('assignee_unresolved');
            }
            if ($ctx->options->dryRun) {
                $connection->rollBack();
                $ctx->taskEvents = [];
                $ctx->pending    = [];
                $this->entityManager->clear();
                $this->closeSession($ctx, $session);

                return;
            }
            $connection->commit();
        } catch (Throwable $exception) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $ctx->taskEvents = [];

            throw $exception;
        }

        // Files, inline images, comments and the fields document of the tasks this page created or changed.
        try {
            foreach ($post as $index => [$item, $task, $remote, $descriptionWritten]) {
                try {
                    $this->afterWrite($ctx, $item, $task, $remote, $descriptionWritten, $assignees, $session);
                } catch (Throwable $exception) {
                    // Audit GW-L2: the tasks not reached keep nothing of this write: all are read again next time.
                    foreach (array_slice($post, $index) as [$deferred]) {
                        $deferred->setRemoteUpdatedAt(new DateTimeImmutable('@0'));
                    }

                    throw $exception;
                }
            }
        } finally {
            $this->closeSession($ctx, $session);
            $deletes          = $ctx->fileDeletes;
            $ctx->fileDeletes = [];
            $this->entityManager->flush();
            // Audit EG-I3: a replaced or removed file leaves the host's storage once the rows no longer point at it.
            foreach ($deletes as [$task, $stored]) {
                $this->storage->delete($task, $stored);
            }
        }
    }

    /**
     * The relations (parents, Gantt dependencies) whose other task arrived on a later page of the run.
     */
    public function resolvePending(SyncRunContext $ctx): void
    {
        if ($ctx->pending === []) {
            return;
        }
        $others = $this->tasks->findImportedByExternalIds($ctx->board, $ctx->scope, array_values(array_unique(array_map(static fn (array $p): string => $p[1], $ctx->pending))));
        foreach ($ctx->pending as [$taskId, $otherId, $type]) {
            $task  = $this->entityManager->find(Task::class, $taskId);
            $other = $others[$otherId][0] ?? null;
            if (!$task instanceof Task || !$other instanceof Task || !$this->canEdit($ctx, $other)) {
                $ctx->warn('relation_skipped');
                continue;
            }
            if ($type === 'parent') {
                $this->reparent($ctx, $task, $other);
            } else {
                $this->addDependency($ctx, $task, $other, TaskDependencyType::tryFrom($type) ?? TaskDependencyType::BlockedBy);
            }
        }
        $ctx->pending = [];
        $this->entityManager->flush();
    }

    /**
     * The deletions phase: archives the linked tasks a complete id scan no longer lists and restores the ones that
     * came back, behind the mass-archive guard.
     *
     * @param array<string, true> $remoteIds
     */
    public function applyDeletions(SyncRunContext $ctx, array $remoteIds, float $maxArchiveRatio): void
    {
        // Since 1.25: checklist subtasks ("<task>#<item>") are archived by the checklist writer, never by the id scan.
        $linked  = array_values(array_filter($this->store->linkedItems($ctx->connection), static fn (array $i): bool => !str_contains($i['externalId'], '#')));
        $active  = array_values(array_filter($linked, static fn (array $i): bool => !$i['archived']));
        $missing = array_values(array_filter($active, static fn (array $i): bool => !isset($remoteIds[$i['externalId']])));
        $back    = array_values(array_filter($linked, static fn (array $i): bool => $i['archived'] && $i['remoteArchived'] && $i['reason'] === Task::ARCHIVE_REASON_REMOTE && isset($remoteIds[$i['externalId']])));

        if ($missing !== [] && !$ctx->options->allowMassArchive && ($remoteIds === [] || count($missing) > $maxArchiveRatio * count($active))) {
            $ctx->warn('mass_archive_guard', ['%count%' => (string) count($missing), '%total%' => (string) count($active)]);
            $missing = [];
        }
        if ($back !== [] && $this->limitChecker->check($ctx->board, count($back), 0) instanceof LimitRefusal) {
            $ctx->warn('restore_refused');
            $back = [];
        }
        if ($ctx->options->dryRun) {
            $ctx->archived += count($missing);
            $ctx->restored += count($back);

            return;
        }
        foreach ([...array_map(static fn (array $i): array => [true, $i], $missing), ...array_map(static fn (array $i): array => [false, $i], $back)] as [$archive, $row]) {
            $item = $this->store->findItem($row['id']);
            $task = $item?->getTask();
            if (!$item instanceof TaskSyncItem || !$task instanceof Task || !$this->canEdit($ctx, $task)) {
                continue;
            }
            if ($archive) {
                $this->markArchived($ctx, $task, $item, $row['externalId']);
            } else {
                $this->markRestored($ctx, $task, $item, $row['externalId']);
            }
        }
        $this->entityManager->flush();
    }

    /**
     * @param array{byName: array<string, BoardColumn>, list: list<BoardColumn>, fallback: BoardColumn|null} $columns
     * @param array<string, Task> $related
     *
     * @return array{0: TaskSyncItem, 1: Task, 2: RemoteTask, 3: bool}
     */
    private function create(SyncRunContext $ctx, string $id, RemoteTask $remote, array $columns, ImportAssigneeResolver $assignees, array &$related, ?PeopleSession $session = null): array
    {
        $row     = $remote->task;
        $column  = $this->columnFor($ctx, $remote, $columns) ?? $columns['fallback'] ?? ($columns['list'][0] ?? null);
        $parent  = $this->parentOf($ctx, $row, $related);
        $creator = $session instanceof PeopleSession && $row->creator instanceof RemotePersonRef ? $session->person($row->creator, TaskSyncPerson::KIND_CREATOR) : null;
        $task    = new Task(
            board: $ctx->board,
            title: $row->title,
            creator: $session?->projected($creator) ?? $ctx->actor,
            column: $column,
            parent: $parent,
            priority: $row->priority,
            position: $this->nextPosition($ctx, $column),
            estimatedMinutes: $row->estimatedMinutes,
            dueAt: $row->dueAt,
            tags: $this->tagsOf($remote),
            createdAt: $this->createdAt($row->createdAt),
        );
        if ($this->description($row) !== null) {
            // No provider URL is ever stored: the images are copied after the page, then rewritten again.
            $task->setDescription($this->rewriter->rewrite($task, $this->description($row), []));
        }
        $startAt = $row->startAt ?? $row->createdAt;
        if ($startAt instanceof DateTimeImmutable) {
            $task->setStartAt($startAt);
        }
        $task->addLink(new TaskLink(
            task: $task,
            linkType: TaskLinkType::Other,
            url: $row->sourceUrl !== null && $this->sanitizer->isSafeUrl($row->sourceUrl) ? $row->sourceUrl : sprintf('import://%s/%s', $ctx->scope, $id),
            label: sprintf('%s #%s', $ctx->provider->id(), $id),
            externalId: $id,
            importSource: $ctx->scope,
        ));
        $peopleMode = $this->peopleMode($session, $row);
        $users      = $peopleMode ? [] : $assignees->resolve($row);
        foreach ($users as $user) {
            $task->addMember(new TaskMember($task, $user, TaskMemberRole::Assignee));
        }
        // Since 1.25 (Q-B15): a status type says whether the task is done; the last column only without one.
        $done = $remote->status?->type instanceof RemoteStatusType
            ? $remote->status->type->isDone()
            : $column instanceof BoardColumn && $columns['list'] !== [] && $columns['list'][array_key_last($columns['list'])]->getId() === $column->getId();
        if ($done) {
            $task->markCompleted();
            $ctx->completedBySync[$task->getId()] = true;
        }
        $this->changeRecorder->recordCreated($task, $ctx->actor);
        $this->entityManager->persist($task);
        $related[$id] = $task;

        // A row with an item is linked (planned as an update) or a tombstone (skipped): a new task has none.
        $item = new TaskSyncItem($ctx->connection, $ctx->board->getId(), $task, $id, $remote->remoteUpdatedAt);
        $this->entityManager->persist($item);
        $item->setRemoteUpdatedAt($remote->remoteUpdatedAt)->setLastSeenRunId($ctx->runId)->setCreatorPerson($creator);
        if ($peopleMode && $session instanceof PeopleSession && $this->peopleWriter instanceof SyncPeopleWriter) {
            $this->peopleWriter->syncMembers($session, $item, $task, TaskMemberRole::Assignee, $row->assigneeRefs, TaskSyncPerson::KIND_ASSIGNEE, $ctx->actor);
        }
        foreach ($this->remoteValues($row, $column, $users, $parent) as $field => $value) {
            if ($value !== null) {
                $item->setBaseline($field, $this->hash($value), $this->hash($this->localValue($task, $field)));
            }
        }
        $this->queueRelations($ctx, $task, $row, $related);
        ++$ctx->created;

        return [$item, $task, $remote, $this->description($row) !== null];
    }

    /**
     * The per-field rule (§3.2) on a task already linked; `$restoring` counts the row as restored, not updated.
     *
     * @param array{byName: array<string, BoardColumn>, list: list<BoardColumn>, fallback: BoardColumn|null} $columns
     * @param array<string, Task> $related
     *
     * @return array{0: TaskSyncItem, 1: Task, 2: RemoteTask, 3: bool}|null
     */
    private function update(SyncRunContext $ctx, string $id, RemoteTask $remote, Task $task, ?TaskSyncItem $item, array $columns, ImportAssigneeResolver $assignees, array &$related, bool $restoring, ?PeopleSession $session = null): ?array
    {
        $related[$id] = $task;
        if ($item instanceof TaskSyncItem && $item->getBackfillLevel() === 0) {
            $this->backfill($item, $task, $remote, $session);
        }
        if ($item instanceof TaskSyncItem && !$ctx->options->full && !$restoring && $remote->remoteUpdatedAt <= $item->getRemoteUpdatedAt()) {
            $item->setLastSeenRunId($ctx->runId);
            ++$ctx->unchanged;

            return null;
        }
        $item ??= $this->adopt($ctx, $id, $task);
        $row          = $remote->task;
        $column       = $this->columnFor($ctx, $remote, $columns);
        $parent       = $this->parentOf($ctx, $row, $related);
        $peopleMode   = $this->peopleMode($session, $row);
        $users        = $peopleMode ? [] : $assignees->resolve($row);
        $remoteValues = $this->remoteValues($row, $column, $users, $parent);

        /** @var array<string, true> $allowed */
        $allowed   = [];
        $conflicts = [];
        foreach ($remoteValues as $field => $value) {
            if ($value === null) {
                continue;
            }
            $remoteHash = $this->hash($value);
            $localHash  = $this->hash($this->localValue($task, $field));
            $baseline   = $item->baseline($field);
            if ($baseline === null || ($remoteHash !== $baseline[0] && $localHash === $baseline[1])) {
                $allowed[$field] = true;
            } elseif ($remoteHash === $baseline[0]) {
                continue;
            } elseif ($localHash === $remoteHash) {
                $item->setBaseline($field, $remoteHash, $localHash)->setConflictHash($field, null);
            } elseif ($item->conflictHash($field) !== $remoteHash) {
                $item->setConflictHash($field, $remoteHash);
                $conflicts[$field] = $this->preview($field, $value, $column, $row);
            }
        }

        $changed = $this->updater->apply(
            $task,
            $row,
            $column,
            fn (BoardColumn $target): int => $this->nextPosition($ctx, $target),
            $columns['list'],
            $ctx->actor,
            static fn (string $field): bool => $field !== 'description' && isset($allowed[$field]),
        );
        if (isset($allowed['description'])) {
            $description = $this->rewriter->rewrite($task, (string) $this->description($row), []);
            if ($description !== $task->getDescription()) {
                $this->changeRecorder->recordIfChanged($task, $ctx->actor, TaskChangeType::Description, $task->getDescription(), $description);
                $task->setDescription($description);
                $changed = true;
            }
        }
        if (isset($allowed['assignees'])) {
            $changed = $this->updater->applyAssignees($task, $users, $ctx->actor) || $changed;
        }
        if (isset($allowed['parent']) && $parent instanceof Task) {
            $changed = $this->reparent($ctx, $task, $parent) || $changed;
        }
        if ($peopleMode && $session instanceof PeopleSession && $this->peopleWriter instanceof SyncPeopleWriter) {
            $changed = $this->peopleWriter->syncMembers($session, $item, $task, TaskMemberRole::Assignee, $row->assigneeRefs, TaskSyncPerson::KIND_ASSIGNEE, $ctx->actor) || $changed;
        }
        // Since 1.25 (Q-B15): the status type decides completion when the column may be written.
        if ($remote->status?->type instanceof RemoteStatusType && ($allowed['column'] ?? false) === true && $remote->status->type->isDone() !== $task->isCompleted()) {
            $remote->status->type->isDone() ? $task->markCompleted() : $task->markIncomplete();
            if ($task->isCompleted()) {
                $ctx->completedBySync[$task->getId()] = true;
            }
            $changed = true;
        }
        foreach (array_keys($allowed) as $field) {
            $item->setBaseline($field, $this->hash($remoteValues[$field]), $this->hash($this->localValue($task, $field)))->setConflictHash($field, null);
        }
        if ($conflicts !== []) {
            $this->noteConflicts($ctx, $task, $id, $conflicts);
        }
        $this->queueRelations($ctx, $task, $row, $related);
        $item->setRemoteUpdatedAt($remote->remoteUpdatedAt)->setLastSeenRunId($ctx->runId);
        if (!$restoring) {
            $changed ? ++$ctx->updated : ++$ctx->unchanged;
        }

        return [$item, $task, $remote, isset($allowed['description'])];
    }

    /** A sync item for a task an earlier file import (or an item-less link) put on the board. */
    private function adopt(SyncRunContext $ctx, string $id, Task $task): TaskSyncItem
    {
        $item = new TaskSyncItem($ctx->connection, $ctx->board->getId(), $task, $id, new DateTimeImmutable('@0'));
        $item->setLastSeenRunId($ctx->runId);
        $this->entityManager->persist($item);

        return $item;
    }

    private function markArchived(SyncRunContext $ctx, Task $task, TaskSyncItem $item, string $externalId): void
    {
        $task->archive(Task::ARCHIVE_REASON_REMOTE);
        $item->setRemoteArchivedAt(new DateTimeImmutable());
        $this->changeRecorder->record($task, $ctx->actor, TaskChangeType::Archived, null, $ctx->provider->id());
        $ctx->taskEvents[] = ['archived', $task->getId(), $externalId];
        ++$ctx->archived;
        // Since 1.25 (§5.5): a parent archived remotely archives its checklist subtasks.
        foreach ($this->checklistTasks($ctx, $externalId) as $subtask) {
            if (!$subtask->isArchived()) {
                $subtask->archive(Task::ARCHIVE_REASON_REMOTE);
                $ctx->count('checklist.archived');
            }
        }
    }

    /**
     * Since 1.25: the tasks of a parent's checklist items (`<parent>#<item>` sync items of the board).
     *
     * @return list<Task>
     */
    private function checklistTasks(SyncRunContext $ctx, string $parentExternalId): array
    {
        /** @var list<Task> $tasks */
        $tasks = $this->entityManager->createQueryBuilder()->select('t')->from(TaskSyncItem::class, 'i')->join(Task::class, 't', 'WITH', 't = i.task')
            ->where('i.boardId = :board')->andWhere('i.itemKind = :kind')->andWhere("i.externalId LIKE :prefix ESCAPE '!'")
            ->setParameter('board', $ctx->board->getId())->setParameter('kind', TaskSyncItem::KIND_CHECKLIST_ITEM)
            ->setParameter('prefix', Like::literal($parentExternalId) . '#%')
            ->getQuery()->getResult();

        return $tasks;
    }

    private function markRestored(SyncRunContext $ctx, Task $task, TaskSyncItem $item, string $externalId): void
    {
        $task->restore();
        $item->setRemoteArchivedAt(null);
        $this->changeRecorder->record($task, $ctx->actor, TaskChangeType::Restored, null, $ctx->provider->id());
        $ctx->taskEvents[] = ['restored', $task->getId(), $externalId];
        ++$ctx->restored;
        foreach ($this->checklistTasks($ctx, $externalId) as $subtask) {
            if ($subtask->getArchiveReason() === Task::ARCHIVE_REASON_REMOTE) {
                $subtask->restore();
            }
        }
    }

    /**
     * One comment and one change-history entry listing the fields kept here although the provider changed them.
     *
     * @param array<string, string> $conflicts field => preview of the remote value
     */
    private function noteConflicts(SyncRunContext $ctx, Task $task, string $externalId, array $conflicts): void
    {
        $lines = [];
        foreach ($conflicts as $field => $preview) {
            $ctx->conflicts[] = new SyncConflict($task->getId(), $this->sanitizer->line($task->getTitle(), 120), $externalId, $field, $preview);
            $lines[]          = sprintf('- **%s**: %s', $this->trans('task_board.sync.field.' . $field, $field), $preview === '' ? '—' : $preview);
        }
        $header = $this->trans('task_board.sync.conflict_note', 'Changed here and in %provider%: these values from %provider% were not applied.', ['%provider%' => $this->providerName($ctx)]);
        $body   = $this->sanitizer->markdown($header . "\n\n" . implode("\n", $lines), TaskComment::MAX_LENGTH);
        $this->entityManager->persist(new TaskComment($task, $ctx->actor, $body));
        $this->changeRecorder->record($task, $ctx->actor, TaskChangeType::SyncConflict, null, Text::cut(implode(', ', array_keys($conflicts)), 255), $ctx->provider->id());
    }

    /**
     * Files, inline images, comments and the fields document of a task this page wrote. A provider error here defers
     * the task to the next run (its remote date is reset) instead of losing its comments or files.
     */
    private function afterWrite(SyncRunContext $ctx, TaskSyncItem $item, Task $task, RemoteTask $remote, bool $descriptionWritten, ImportAssigneeResolver $assignees, ?PeopleSession $session = null): void
    {
        $capabilities      = $ctx->provider->capabilities();
        $remoteDescription = $this->description($remote->task);
        if ($descriptionWritten && $remoteDescription !== null) {
            $stored      = $remote->inlineImageUrls === [] ? [] : $this->storeImages($ctx, $task, $item, $remote->inlineImageUrls);
            $description = $this->rewriter->rewrite($task, $remoteDescription, $stored);
            if ($description !== $task->getDescription()) {
                $task->setDescription($description);
            }
            $item->setBaseline('description', $this->hash($remoteDescription), $this->hash($task->getDescription()));
        }
        try {
            $detail = $this->detail($ctx, $item, $remote);
            $this->taskFiles($ctx, $task, $item, $remote, $detail);
            $repliesRead = $ctx->options->importComments && $capabilities->comments && $this->syncComments($ctx, $task, $item, $remote, $assignees, $session);
            if ($session instanceof PeopleSession && $this->peopleWriter instanceof SyncPeopleWriter && $this->watchers($ctx) && $detail->watchersKnown) {
                $this->peopleWriter->syncMembers($session, $item, $task, TaskMemberRole::Watcher, $detail->watchers, TaskSyncPerson::KIND_WATCHER, $ctx->actor);
            }
            if ($this->checklists($ctx) && $detail->checklistsKnown && $this->checklistWriter instanceof SyncChecklistWriter) {
                $this->checklistWriter->sync($ctx, $session, $item, $task, $detail);
            }
            $this->detailsWriter?->write($ctx, $item, $task, $remote, $session, $detail !== $remote, $repliesRead);
        } catch (ProviderException $exception) {
            $item->setRemoteUpdatedAt(new DateTimeImmutable('@0'));
            $ctx->warn('task_deferred');

            throw $exception;
        }
        if ($remote->customFieldsText !== null && $this->fieldsMode !== 'typed') {
            $this->fieldsDocument($ctx, $task, $remote->customFieldsText);
        }
        if ($this->fieldsMode !== 'text' && $this->fieldWriter instanceof SyncCustomFieldWriter) {
            $this->fieldWriter->write($ctx, $task, $remote->customFields, $session);
        }
    }

    /**
     * Since 1.27.0: the task's files, from the row or (ClickUp list rows have no `attachments`) its detail. A task whose
     * files could not be read is counted (`attachments.unread`) so the report does not claim them.
     */
    private function taskFiles(SyncRunContext $ctx, Task $task, TaskSyncItem $item, RemoteTask $remote, RemoteTask $detail): void
    {
        if (!$ctx->options->importAttachments || !$ctx->provider->capabilities()->attachments) {
            return;
        }
        if ($remote->attachmentsKnown) {
            $item->setPendingRead(TaskSyncItem::PENDING_ATTACHMENTS, false);
            $this->syncAttachments($ctx, $task, $item, $remote->attachments);
        } elseif ($detail !== $remote && $detail->attachmentsKnown) {
            if ($detail->attachments !== []) {
                $ctx->count('keys.attachments');
            }
            $item->setPendingRead(TaskSyncItem::PENDING_ATTACHMENTS, false);
            $this->syncAttachments($ctx, $task, $item, $detail->attachments);
        } elseif (!$this->read($item, $remote, SyncTaskDetailsWriter::META_DETAIL_READ) || $item->hasPendingRead(TaskSyncItem::PENDING_ATTACHMENTS)) {
            $item->setPendingRead(TaskSyncItem::PENDING_ATTACHMENTS, true);
            $ctx->count('attachments.unread');
        }
    }

    /** Since 1.27.0: this version of the task was already read (repeated full syncs make progress). */
    private function read(TaskSyncItem $item, RemoteTask $remote, string $marker): bool
    {
        return ($item->getRemoteMeta()[$marker] ?? null) === $remote->remoteUpdatedAt->getTimestamp();
    }

    /**
     * @param list<RemoteAttachment> $attachments
     */
    private function syncAttachments(SyncRunContext $ctx, Task $task, TaskSyncItem $item, array $attachments): void
    {
        $existing = $this->store->attachmentsOf($task);
        $listed   = [];
        foreach ($attachments as $attachment) {
            $listed[$attachment->id] = true;
            $known                   = $existing[$attachment->id] ?? null;
            if ($known instanceof TaskSyncAttachment && $known->matches($attachment->size, $attachment->updatedAt)) {
                ++$ctx->filesReused;
                continue;
            }
            $stored = $this->download($ctx, $task, $attachment, false);
            if (!$stored instanceof StoredAttachment) {
                continue;
            }
            $link = $known?->getLink();
            if ($link instanceof TaskLink) {
                $link->setUrl($stored->url)->setLabel($stored->name);
            } else {
                $link = new TaskLink($task, TaskLinkType::Attachment, $stored->url, $stored->name);
                $task->addLink($link);
            }
            if ($known instanceof TaskSyncAttachment) {
                // Updated in place: a remove plus an insert of the same (task, remote id) would hit the unique key.
                $ctx->fileDeletes[] = [$task, $known->stored()];
                $known->replace($attachment->size, $attachment->updatedAt, $stored, Text::cut($stored->name, 255), Text::cut($stored->mime, 127), $link);
                continue;
            }
            $this->entityManager->persist(new TaskSyncAttachment($item, $task, $attachment->id, $attachment->size, $attachment->updatedAt, $stored->ref, $stored->url, Text::cut($stored->name, 255), Text::cut($stored->mime, 127), $stored->size, $stored->sha256, $link));
        }
        // A file the provider no longer lists (only when it lists some: an empty list may just be absent).
        if ($attachments === []) {
            return;
        }
        foreach ($existing as $remoteId => $known) {
            if (!isset($listed[$remoteId]) && !str_starts_with($remoteId, 'img:') && !str_starts_with($remoteId, self::COMMENT_FILE_PREFIX)) {
                $ctx->fileDeletes[] = [$task, $known->stored()];
                $link               = $known->getLink();
                if ($link instanceof TaskLink) {
                    $task->removeLink($link);
                }
                $this->entityManager->remove($known);
            }
        }
    }

    /**
     * Inline images: each allowed URL copied once per task (key "img:<sha1 of the URL>").
     *
     * @param list<string> $urls
     *
     * @return array<string, StoredAttachment>
     */
    private function storeImages(SyncRunContext $ctx, Task $task, TaskSyncItem $item, array $urls): array
    {
        $existing = $this->store->attachmentsOf($task);
        $stored   = [];
        foreach ($urls as $url) {
            $key   = 'img:' . sha1($url);
            $known = $ctx->images[$task->getId() . '|' . $key] ?? ($existing[$key] ?? null)?->stored();
            if ($known instanceof StoredAttachment) {
                // The same image in the description and a comment (or twice) is stored once per task.
                ++$ctx->filesReused;
                $stored[$url] = $known;
                continue;
            }
            $file = $this->download($ctx, $task, new RemoteAttachment($key, 'image', null, null, $url), true);
            if ($file instanceof StoredAttachment) {
                $stored[$url]                             = $file;
                $ctx->images[$task->getId() . '|' . $key] = $file;
                $this->entityManager->persist(new TaskSyncAttachment($item, $task, $key, null, null, $file->ref, $file->url, Text::cut($file->name, 255), Text::cut($file->mime, 127), $file->size, $file->sha256));
            }
        }

        return $stored;
    }

    /**
     * Fetches a remote file safely and hands it to the host's storage; null (counted as refused, with a warning that
     * names the file and the reason only) when anything refuses it.
     */
    private function download(SyncRunContext $ctx, Task $task, RemoteAttachment $attachment, bool $imagesOnly): ?StoredAttachment
    {
        $name = $this->sanitizer->line($attachment->name, 120);
        if (!$this->storageEnabled || $ctx->filesBlocked) {
            ++$ctx->filesRefused;
            $ctx->warn($this->storageEnabled ? 'file_quota' : 'storage_disabled');

            return null;
        }
        $refusal = $this->limitChecker->check($ctx->board, 0, $attachment->size ?? $this->maxFileBytes);
        if ($refusal instanceof LimitRefusal) {
            $ctx->filesBlocked = true;
            ++$ctx->filesRefused;
            $ctx->warn('file_quota');

            return null;
        }
        try {
            $file = $this->fetcher->fetch($ctx->provider->capabilities()->attachmentHosts, $attachment->url, $attachment->name, $attachment->size, max(0, $ctx->budgetBytes - $ctx->bytes), $imagesOnly);
        } catch (UnsafeRemoteFileException $exception) {
            ++$ctx->filesRefused;
            $ctx->warn('file_refused', ['%name%' => $name, '%reason%' => $exception->reason]);

            return null;
        }
        try {
            $stored = $this->storage->store($task, $attachment, $file->stream(...));
        } catch (Throwable) {
            ++$ctx->filesRefused;
            $ctx->warn('file_store_failed', ['%name%' => $name]);

            return null;
        } finally {
            $file->delete();
        }
        ++$ctx->filesCopied;
        $ctx->bytes += $file->size;

        return $stored;
    }

    /**
     * @return bool whether a threaded provider read every thread of this version of the task
     */
    private function syncComments(SyncRunContext $ctx, Task $task, TaskSyncItem $item, RemoteTask $remoteTask, ImportAssigneeResolver $assignees, ?PeopleSession $session = null): bool
    {
        $existing = $this->store->commentsOf($item);
        $provider = $ctx->provider;
        // Audit GWd-L3: threads already read for this version spend nothing, so the budget reaches the next tasks.
        $done     = $this->read($item, $remoteTask, SyncTaskDetailsWriter::META_REPLIES_READ);
        $replies  = $done ? ReplyBudget::alreadyRead() : new ReplyBudget(max(0, $this->maxReplyCalls - $ctx->stat('comments.reply_calls')));
        $comments = $provider instanceof ThreadedCommentProviderInterface
            ? $provider->fetchCommentsWithin($ctx->credential, $item->getExternalId(), $replies)
            : $provider->fetchComments($ctx->credential, $item->getExternalId());
        $files = null;
        foreach ($comments as $remote) {
            // Since 1.27.0: the files of the comment are part of what makes it change.
            $remoteHash = $this->hash($remote->attachments === [] ? $remote->body : [$remote->body, array_map(static fn (RemoteAttachment $file): array => [$file->id, $file->size, $file->updatedAt?->getTimestamp()], $remote->attachments)]);
            $known      = $existing[$remote->id] ?? null;
            // Since 1.27.0 (TB-SYNC-3): thread, action item and edit date, refreshed on every fetch.
            $assignee = $session instanceof PeopleSession && $remote->assignee !== null ? $session->person($remote->assignee, TaskSyncPerson::KIND_COMMENT) : null;
            if ($known instanceof TaskSyncComment && $known->getBodyHash() === $remoteHash) {
                $known->describe($remote->parentId, $remote->resolved, $assignee, $remote->updatedAt);
                continue;
            }
            if ($known instanceof TaskSyncComment) {
                $known->describe($remote->parentId, $remote->resolved, $assignee, $remote->updatedAt);
                $comment = $known->getComment();
                // Audit GW-M2: before the body is built, so a conflict downloads nothing.
                if ($comment->isDeleted() || $this->hash($comment->getBody()) !== $known->getLocalHash()) {
                    $ctx->conflicts[] = new SyncConflict($task->getId(), $this->sanitizer->line($task->getTitle(), 120), $item->getExternalId(), 'comment', $this->sanitizer->line($remote->body, 200));
                    $known->written($remoteHash, $known->getLocalHash());
                    continue;
                }
            }
            // Since 1.25: through the directory (no "Originally by" line: the template shows the person reference).
            $person = $session instanceof PeopleSession && $remote->author !== null ? $session->person($remote->author, TaskSyncPerson::KIND_COMMENT) : null;
            $author = $person instanceof TaskSyncPerson ? $session?->projected($person) : ($remote->authorEmail !== null ? $assignees->userByEmail($remote->authorEmail) : null);
            $files ??= $this->store->attachmentsOf($task);
            $body = $this->commentBody($ctx, $task, $item, $remote, $author === null && !$person instanceof TaskSyncPerson, $files);
            if (!$known instanceof TaskSyncComment) {
                $comment = new TaskComment($task, $author ?? $ctx->actor, $body, [], $remote->createdAt);
                $this->entityManager->persist($comment);
                $this->entityManager->persist((new TaskSyncComment($item, $comment, Text::cut($remote->id, 128), $remoteHash, $this->hash($body)))->setAuthorPerson($person)->describe($remote->parentId, $remote->resolved, $assignee, $remote->updatedAt));
                ++$ctx->commentsCreated;
                continue;
            }
            $known->getComment()->edit($body, []);
            $known->written($remoteHash, $this->hash($body));
            ++$ctx->commentsUpdated;
        }
        // Audit GW-L6: the replies past the run's budget are said, not lost silently.
        $ctx->count('comments.reply_calls', $replies->used);
        if ($replies->skipped > 0) {
            $ctx->count('comments.replies_unread', $replies->skipped);
            $ctx->warn('replies_budget');
        }
        $item->setPendingRead(TaskSyncItem::PENDING_REPLIES, $replies->skipped > 0);

        return !$done && $replies->skipped === 0 && $provider instanceof ThreadedCommentProviderInterface;
    }

    /**
     * @param array<string, TaskSyncAttachment> $files the task's tracked files (new ones are added)
     */
    private function commentBody(SyncRunContext $ctx, Task $task, TaskSyncItem $item, RemoteComment $remote, bool $prefix, array &$files): string
    {
        $body = $remote->body;
        if ($remote->inlineImageUrls !== []) {
            $body = $this->rewriter->rewrite($task, $body, $this->storeImages($ctx, $task, $item, $remote->inlineImageUrls));
        }
        if ($prefix) {
            $body = $this->trans('task_board.sync.comment_prefix', '**%name%** (%provider%):', [
                '%name%'     => $remote->authorName ?? '?',
                '%provider%' => $this->providerName($ctx),
            ]) . "\n\n" . $body;
        }
        // Since 1.27.0 (TB-SYNC-3): the comment's files are copied like task attachments (same host allow-list, size
        // caps and type sniffing) and linked under the comment; a refused file is named without a link.
        $lines = [];
        $kept  = [];
        foreach ($remote->attachments as $attachment) {
            $key        = $this->commentFileKey($remote->id, $attachment->id);
            $kept[$key] = true;
            $stored     = $this->commentFile($ctx, $task, $item, $attachment, $key, $files);
            $name       = addcslashes($this->sanitizer->line($attachment->name, 120), '\\`*_[]()<>!#~|');
            $lines[]    = $stored instanceof StoredAttachment ? sprintf('- [%s](%s)', $name, $stored->url) : '- ' . $name;
        }
        // Audit GW-M2: a file the comment no longer has leaves the host's storage.
        foreach ($files as $key => $file) {
            if (str_starts_with($key, $this->commentFilePrefix($remote->id)) && !isset($kept[$key])) {
                $ctx->fileDeletes[] = [$task, $file->stored()];
                $this->entityManager->remove($file);
                unset($files[$key]);
            }
        }
        $body = $this->sanitizer->markdown($body, TaskComment::MAX_LENGTH);
        if ($lines === []) {
            return $body;
        }

        // The stored URLs are the host's own (not remote input): appended after the remote text is sanitised.
        return Text::cut($body . "\n\n" . $this->trans('task_board.sync.comment_attachments', 'Attachments:') . "\n" . implode("\n", $lines), TaskComment::MAX_LENGTH);
    }

    /**
     * Audit GW-M2: a comment's file is tracked like a task's (erasure, quota): reused while unchanged, its old copy
     * deleted when replaced. When the new one is refused the old copy stays linked.
     *
     * @param array<string, TaskSyncAttachment> $files
     */
    private function commentFile(SyncRunContext $ctx, Task $task, TaskSyncItem $item, RemoteAttachment $attachment, string $key, array &$files): ?StoredAttachment
    {
        $known = $files[$key] ?? null;
        if ($known instanceof TaskSyncAttachment && $known->matches($attachment->size, $attachment->updatedAt)) {
            ++$ctx->filesReused;

            return $known->stored();
        }
        $stored = $ctx->options->importAttachments ? $this->download($ctx, $task, $attachment, false) : null;
        if (!$stored instanceof StoredAttachment) {
            return $known?->stored();
        }
        if ($known instanceof TaskSyncAttachment) {
            $ctx->fileDeletes[] = [$task, $known->stored()];
            $known->replace($attachment->size, $attachment->updatedAt, $stored, Text::cut($stored->name, 255), Text::cut($stored->mime, 127), null);

            return $stored;
        }
        $files[$key] = new TaskSyncAttachment($item, $task, $key, $attachment->size, $attachment->updatedAt, $stored->ref, $stored->url, Text::cut($stored->name, 255), Text::cut($stored->mime, 127), $stored->size, $stored->sha256);
        $this->entityManager->persist($files[$key]);

        return $stored;
    }

    private function commentFilePrefix(string $commentId): string
    {
        return self::COMMENT_FILE_PREFIX . sha1($commentId) . ':';
    }

    private function commentFileKey(string $commentId, string $fileId): string
    {
        return $this->commentFilePrefix($commentId) . sha1($fileId);
    }

    private function fieldsDocument(SyncRunContext $ctx, Task $task, string $text): void
    {
        $title = Text::cut(sprintf('%s (%s)', $this->trans('task_board.sync.fields_document', 'Fields'), $this->providerName($ctx)), 255);
        foreach ($task->getDocuments() as $document) {
            if ($document->getTitle() === $title) {
                if ($document->getContent() !== $text) {
                    $document->setContent($text);
                }

                return;
            }
        }
        $document = new TaskDocument($task, $title, $text, $ctx->actor, count($task->getDocuments()));
        $task->getDocuments()->add($document);
        $this->entityManager->persist($document);
    }

    /**
     * The value of each synced field the provider gives (null: no value, never written).
     *
     * @param list<object> $users
     *
     * @return array<string, mixed>
     */
    private function remoteValues(ImportedTaskDto $row, ?BoardColumn $column, array $users, ?Task $parent): array
    {
        $tags = $this->normalizedTags($row->tags);

        return [
            'title'       => Text::cut($row->title, Task::TITLE_MAX),
            'description' => $this->description($row),
            'priority'    => $row->hasPriority ? $row->priority->value : null,
            'column'      => $column?->getId(),
            'start'       => $row->startAt?->format('Y-m-d H:i:s'),
            'due'         => $row->dueAt?->format('Y-m-d H:i:s'),
            'estimate'    => $row->estimatedMinutes,
            'tags'        => $tags === [] ? null : $tags,
            'assignees'   => $users === [] ? null : $this->userIds($users),
            'parent'      => $parent?->getId(),
        ];
    }

    /**
     * The remote description as it is stored: sanitized Markdown (whatever the provider adapter did), null when empty.
     */
    private function description(ImportedTaskDto $row): ?string
    {
        $description = $row->description === null ? '' : $this->sanitizer->markdown($row->description, self::MAX_DESCRIPTION_BYTES);

        return $description === '' ? null : $description;
    }

    private function localValue(Task $task, string $field): mixed
    {
        return match ($field) {
            'title'       => $task->getTitle(),
            'description' => $task->getDescription(),
            'priority'    => $task->getPriority()->value,
            'column'      => $task->getColumn()?->getId(),
            'start'       => $task->getStartAt()?->format('Y-m-d H:i:s'),
            'due'         => $task->getDueAt()?->format('Y-m-d H:i:s'),
            'estimate'    => $task->getEstimatedMinutes(),
            'tags'        => $task->getTags(),
            'assignees'   => $this->userIds(array_values(array_map(
                static fn (TaskMember $member): object => $member->getUser(),
                array_filter($task->getMembers()->toArray(), static fn (TaskMember $member): bool => $member->getMemberRole() === TaskMemberRole::Assignee),
            ))),
            default => $task->getParent()?->getId(),
        };
    }

    /** What a conflict note shows of a remote value (at most 200 bytes, one line). */
    private function preview(string $field, mixed $value, ?BoardColumn $column, ImportedTaskDto $row): string
    {
        return match ($field) {
            'column'    => $this->sanitizer->line((string) $column?->getName(), 200),
            'assignees' => $this->sanitizer->line(implode(', ', [...$row->assigneeEmails, ...$row->assigneeNames]), 200),
            'parent'    => (string) $row->parentExternalId,
            'tags'      => $this->sanitizer->line(implode(', ', is_array($value) ? array_map(strval(...), $value) : []), 200),
            default     => $this->sanitizer->line(is_scalar($value) ? (string) $value : '', 200),
        };
    }

    /**
     * The board's columns by lower-cased name, in order, and the fallback; creates the missing status columns when
     * the option is on.
     *
     * @param list<array{0: string, 1: string, 2: RemoteTask, 3: Task|null, 4: TaskSyncItem|null}> $plan
     *
     * @return array{byName: array<string, BoardColumn>, list: list<BoardColumn>, fallback: BoardColumn|null}
     */
    private function columns(SyncRunContext $ctx, array $plan): array
    {
        $byName   = [];
        $fallback = null;
        foreach ($ctx->board->getColumns() as $column) {
            $byName[mb_strtolower(trim($column->getName()))] ??= $column;
            if ($column->getId() === $ctx->options->fallbackColumnId) {
                $fallback = $column;
            }
        }
        $map = $ctx->connection->getStatusColumnMap();
        foreach ($plan as [$action, , $remote]) {
            $status = trim((string) $remote->task->status);
            if ($action === 'archive' || $status === '' || isset($byName[mb_strtolower($status)]) || StatusColumns::mapped($map, $status, $ctx->board)) {
                continue;
            }
            if (!$ctx->options->createMissingColumns) {
                $ctx->warn('status_without_column');
                continue;
            }
            $column                         = $this->columnManager->add($ctx->board, new BoardColumnFormData(name: Text::cut($this->sanitizer->line($status, 255), 255)));
            $byName[mb_strtolower($status)] = $column;
            ++$ctx->columnsCreated;
        }

        return ['byName' => $byName, 'list' => array_values($ctx->board->getColumns()->toArray()), 'fallback' => $fallback];
    }

    /**
     * Since 1.25: the column of a remote task — the dialog's status → column map first, then the 1.23 rules (name match,
     * fallback). A status the map does not know is warned about (`unmapped_status`).
     *
     * @param array{byName: array<string, BoardColumn>, list: list<BoardColumn>, fallback: BoardColumn|null} $columns
     */
    private function columnFor(SyncRunContext $ctx, RemoteTask $remote, array $columns): ?BoardColumn
    {
        $map    = $ctx->connection->getStatusColumnMap();
        $status = $remote->task->status;
        $target = StatusColumns::target($map, $status);
        if ($target !== null) {
            foreach ($columns['list'] as $column) {
                if ($column->getId() === $target) {
                    return $column;
                }
            }
        }
        if ($map !== [] && $target === null && trim((string) $status) !== '') {
            $ctx->warn('unmapped_status');
        }

        return $this->columnOf($status, $columns);
    }

    /**
     * Since 1.25 (E5): the task's detail when its list row left out watchers, checklists or (since 1.27.0) files, within
     * `sync.watchers.max_detail_calls_per_run` per run; else the row itself.
     */
    private function detail(SyncRunContext $ctx, TaskSyncItem $item, RemoteTask $remote): RemoteTask
    {
        $provider = $ctx->provider;
        $missing  = ($this->watchers($ctx) && !$remote->watchersKnown) || ($this->checklists($ctx) && !$remote->checklistsKnown)
            || ($ctx->options->importAttachments && $provider->capabilities()->attachments && !$remote->attachmentsKnown);
        // A version already read spends no request, whatever the row lacks: the budget reaches the next tasks (audit GW).
        if (!$missing || !$provider instanceof TaskDetailProviderInterface || $this->read($item, $remote, SyncTaskDetailsWriter::META_DETAIL_READ)) {
            return $remote;
        }
        if (($ctx->stats['detail.calls'] ?? 0) >= $this->maxDetailCalls) {
            $item->setPendingRead(TaskSyncItem::PENDING_DETAIL, true);

            return $remote;
        }
        $ctx->count('detail.calls');
        try {
            $detail = $provider->fetchTaskDetail($ctx->credential, $item->getExternalId());
        } catch (ProviderException $exception) {
            if ($exception->errorCode->isPassing()) {
                throw $exception;
            }
            // Audit GWd-L1: one task the provider will always refuse does not stop the board; it is reported.
            $ctx->count('detail.failed');
            $item->setPendingRead(TaskSyncItem::PENDING_DETAIL_REFUSED, true);

            return $remote;
        }
        $item->setPendingRead(TaskSyncItem::PENDING_DETAIL | TaskSyncItem::PENDING_DETAIL_REFUSED, false);

        return $detail ?? $remote;
    }

    /** Since 1.25: watchers are imported — `sync.watchers.enabled` and the run's {@see SyncOptions::$watchers}. */
    private function watchers(SyncRunContext $ctx): bool
    {
        return $this->watchersEnabled && $ctx->options->watchers;
    }

    /** Since 1.25: checklists are imported — `sync.checklists.enabled` and the run's {@see SyncOptions::$checklists}. */
    private function checklists(SyncRunContext $ctx): bool
    {
        return $this->checklistsEnabled && $ctx->options->checklists;
    }

    /** Since 1.25: whether assignees go through the people directory (a session and person refs on the row). */
    private function peopleMode(?PeopleSession $session, ImportedTaskDto $row): bool
    {
        return $session instanceof PeopleSession && $this->peopleWriter instanceof SyncPeopleWriter && $row->assigneeRefs !== [];
    }

    /** Since 1.25: the people session of a page (null without a directory). */
    private function session(SyncRunContext $ctx): ?PeopleSession
    {
        if ($ctx->directoryId === null || !$this->peopleDirectory instanceof TaskSyncPeopleDirectoryService) {
            return null;
        }
        $directory = $this->entityManager->find(TaskSyncDirectory::class, $ctx->directoryId);

        return $directory instanceof TaskSyncDirectory ? $this->peopleDirectory->session($directory, $ctx->board) : null;
    }

    /** Since 1.25: the session's counters and warnings into the run. */
    private function closeSession(SyncRunContext $ctx, ?PeopleSession $session): void
    {
        if (!$session instanceof PeopleSession) {
            return;
        }
        foreach ($session->stats as $name => $value) {
            $ctx->count($name, $value);
        }
        $session->stats = [];
        foreach ($session->warnings as $code) {
            $ctx->warn($code);
        }
        $session->warnings = [];
    }

    /** Since 1.25: the creation date of the source when it is plausible (2000-01-01 … now + 5 min). */
    private function createdAt(?DateTimeImmutable $at): ?DateTimeImmutable
    {
        if (!$at instanceof DateTimeImmutable || $at->getTimestamp() < 946_684_800 || $at->getTimestamp() > time() + 300) {
            return null;
        }

        return $at;
    }

    /**
     * Since 1.25: the row's tags, plus "list:<name>" for a multi-list source when `sync.list_as_tag` is on.
     *
     * @return list<string>
     */
    private function tagsOf(RemoteTask $remote): array
    {
        $tags = $remote->task->tags;
        if ($this->listAsTag && $remote->listName !== null && $remote->listName !== '') {
            $tags[] = Text::cut('list:' . $this->sanitizer->line($remote->listName, 120), 64);
        }

        return $tags;
    }

    /**
     * Since 1.25: a task written by 1.23 gets its creator and creation date once (no conflict note: never editable).
     */
    private function backfill(TaskSyncItem $item, Task $task, RemoteTask $remote, ?PeopleSession $session): void
    {
        $createdAt = $this->createdAt($remote->task->createdAt);
        if ($createdAt instanceof DateTimeImmutable) {
            $task->importCreatedAt($createdAt);
        }
        if ($session instanceof PeopleSession && $remote->task->creator instanceof RemotePersonRef) {
            $person = $session->person($remote->task->creator, TaskSyncPerson::KIND_CREATOR);
            $item->setCreatorPerson($person);
            $user = $session->projected($person);
            if ($user !== null) {
                $task->reassignCreator($user);
            }
        }
        $item->setBackfillLevel(1);
    }

    /**
     * @param array{byName: array<string, BoardColumn>, list: list<BoardColumn>, fallback: BoardColumn|null} $columns
     */
    private function columnOf(?string $status, array $columns): ?BoardColumn
    {
        $status = trim((string) $status);
        if ($status === '') {
            return null;
        }

        return $columns['byName'][mb_strtolower($status)] ?? $columns['fallback'];
    }

    private function nextPosition(SyncRunContext $ctx, ?BoardColumn $column): int
    {
        $key = $column?->getId() ?? '';
        if (!isset($ctx->positions[$key])) {
            $builder = $this->entityManager->createQueryBuilder()->select('MAX(t.position)')->from(Task::class, 't')->where('t.board = :board')->setParameter('board', $ctx->board);
            if ($column instanceof BoardColumn) {
                $builder->andWhere('t.column = :column')->setParameter('column', $column);
            }
            $max                  = $builder->getQuery()->getSingleScalarResult();
            $ctx->positions[$key] = $max === null ? -1 : (int) $max;
        }

        return ++$ctx->positions[$key];
    }

    /**
     * The parent a row names: a task of this page or of an earlier one, editable by the actor; null otherwise.
     *
     * @param array<string, Task> $related
     */
    private function parentOf(SyncRunContext $ctx, ImportedTaskDto $row, array $related): ?Task
    {
        $parent = $row->parentExternalId === null ? null : ($related[$row->parentExternalId] ?? null);

        return $parent instanceof Task && $this->canEdit($ctx, $parent) ? $parent : null;
    }

    /**
     * Parents and dependency targets the page names that are not in the page: one query.
     *
     * @param array<string, RemoteTask> $rows
     * @param array<string, array{0: Task, 1: TaskLink|null}> $known
     *
     * @return array<string, Task>
     */
    private function relatedTasks(SyncRunContext $ctx, array $rows, array $known): array
    {
        $related = [];
        foreach ($known as $id => [$task]) {
            $related[(string) $id] = $task;
        }
        $wanted = [];
        foreach ($rows as $remote) {
            foreach ([$remote->task->parentExternalId, ...array_map(static fn (array $d): string => $d[0], $remote->task->dependsOn)] as $other) {
                if ($other !== null && !isset($rows[$other]) && !isset($related[$other])) {
                    $wanted[$other] = $other;
                }
            }
        }
        if ($wanted !== []) {
            foreach ($this->tasks->findImportedByExternalIds($ctx->board, $ctx->scope, array_values($wanted)) as $id => [$task]) {
                $related[(string) $id] = $task;
            }
        }

        return $related;
    }

    /**
     * Gantt relations and a parent whose other task is not on the board yet wait for the end of the tasks phase.
     *
     * @param array<string, Task> $related
     */
    private function queueRelations(SyncRunContext $ctx, Task $task, ImportedTaskDto $row, array $related): void
    {
        if ($row->parentExternalId !== null && !$task->getParent() instanceof Task && !isset($related[$row->parentExternalId])) {
            $ctx->pending[] = [$task->getId(), $row->parentExternalId, 'parent'];
        }
        foreach ($row->dependsOn as [$otherId, $type]) {
            $other = $related[$otherId] ?? null;
            if ($other instanceof Task && $this->canEdit($ctx, $other)) {
                $this->addDependency($ctx, $task, $other, $type);
            } elseif (!$other instanceof Task) {
                $ctx->pending[] = [$task->getId(), $otherId, $type->value];
            }
        }
    }

    private function addDependency(SyncRunContext $ctx, Task $task, Task $other, TaskDependencyType $type): void
    {
        try {
            $this->dependencyManager->add($task, $other, $type, $ctx->actor);
            ++$ctx->dependenciesCreated;
        } catch (InvalidTaskDependencyException $exception) {
            if ($exception->reason !== InvalidTaskDependencyException::REASON_DUPLICATE) {
                $ctx->warn('relation_skipped');
            }
        }
    }

    /** Moves a task under a parent, unless that makes it its own ancestor. */
    private function reparent(SyncRunContext $ctx, Task $task, Task $parent): bool
    {
        if ($parent === $task->getParent()) {
            return false;
        }
        $seen = [];
        for ($ancestor = $parent; $ancestor instanceof Task; $ancestor = $ancestor->getParent()) {
            if ($ancestor === $task || isset($seen[$ancestor->getId()])) {
                $ctx->warn('parent_cycle');

                return false;
            }
            $seen[$ancestor->getId()] = true;
        }
        $task->setParent($parent);

        return true;
    }

    /**
     * Rows whose parent is on the same page come after it.
     *
     * @param array<string, RemoteTask> $rows
     *
     * @return array<string, RemoteTask>
     */
    private function parentsFirst(array $rows): array
    {
        $ordered = [];
        $pending = $rows;
        while ($pending !== []) {
            $progress = false;
            foreach ($pending as $id => $remote) {
                $parent = $remote->task->parentExternalId;
                if ($parent === null || !isset($pending[$parent]) || $parent === (string) $id) {
                    $ordered[(string) $id] = $remote;
                    unset($pending[$id]);
                    $progress = true;
                }
            }
            if (!$progress) {
                // A cycle within the page: the rest in file order (reparent() refuses the closing move).
                foreach ($pending as $id => $remote) {
                    $ordered[(string) $id] = $remote;
                }
                break;
            }
        }

        return $ordered;
    }

    /**
     * Ids of the page already imported into another board of the tenant (and on none of this one).
     *
     * @param list<string> $ids
     * @param array<string, TaskSyncItem> $items
     * @param array<string, array{0: Task, 1: TaskLink}> $links
     *
     * @return array<string, true>
     */
    private function elsewhere(SyncRunContext $ctx, array $ids, array $items, array $links): array
    {
        $unknown = array_values(array_filter($ids, static fn (string $id): bool => !isset($items[$id]) && !isset($links[$id])));

        return $unknown === [] ? [] : $this->linkLookup->findOnOtherBoards($ctx->board, $ctx->scope, $unknown);
    }

    private function canEdit(SyncRunContext $ctx, Task $task): bool
    {
        return $this->accessChecker->canEditTask($ctx->actor, $task);
    }

    /**
     * @param list<string> $tags
     *
     * @return list<string>
     */
    private function normalizedTags(array $tags): array
    {
        $normalized = array_values(array_unique(array_filter(array_map(trim(...), $tags), static fn (string $tag): bool => $tag !== '')));
        sort($normalized);

        return $normalized;
    }

    /**
     * @param list<object> $users
     *
     * @return list<string>
     */
    private function userIds(array $users): array
    {
        $ids = [];
        foreach ($users as $user) {
            $id    = method_exists($user, 'getId') ? $user->getId() : null;
            $ids[] = is_scalar($id) || $id instanceof Stringable ? (string) $id : sha1(spl_object_hash($user));
        }
        sort($ids);

        return $ids;
    }

    private function hash(mixed $value): string
    {
        return hash('sha256', (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function providerName(SyncRunContext $ctx): string
    {
        return $this->trans($ctx->provider->labelKey(), $ctx->provider->id());
    }

    /**
     * @param array<string, string> $params
     */
    private function trans(string $key, string $fallback, array $params = []): string
    {
        $text = $this->translator?->trans($key, $params, 'NowoTaskBoardBundle');

        return $text === null || $text === $key ? strtr($fallback, $params) : $text;
    }
}
