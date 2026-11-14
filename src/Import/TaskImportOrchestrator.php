<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Import;

use Closure;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use InvalidArgumentException;
use LogicException;
use Nowo\TaskBoardBundle\Assignee\TaskAssigneeProviderInterface;
use Nowo\TaskBoardBundle\Doctrine\RecoveringFlusher;
use Nowo\TaskBoardBundle\Dto\BoardColumnFormData;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskChangeHistory;
use Nowo\TaskBoardBundle\Entity\TaskDependency;
use Nowo\TaskBoardBundle\Entity\TaskLink;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Enum\TaskLinkType;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Event\TaskBoardQuotaCheckEvent;
use Nowo\TaskBoardBundle\Exception\ImportLimitExceededException;
use Nowo\TaskBoardBundle\Exception\InvalidTaskDependencyException;
use Nowo\TaskBoardBundle\Import\Dto\ImportedTaskDto;
use Nowo\TaskBoardBundle\Import\Dto\TaskImportOptions;
use Nowo\TaskBoardBundle\Import\Dto\TaskImportResult;
use Nowo\TaskBoardBundle\Repository\ArchiveAwareTaskRepositoryInterface;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Service\BoardColumnManager;
use Nowo\TaskBoardBundle\Service\TaskBoardQuota;
use Nowo\TaskBoardBundle\Service\TaskChangeRecorder;
use Nowo\TaskBoardBundle\Service\TaskDependencyManager;
use Nowo\TaskBoardBundle\Service\TaskEventDeferral;
use Nowo\TaskBoardBundle\Support\ColumnTypes;
use Nowo\TaskBoardBundle\Support\Text;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use function array_filter;
use function array_reverse;
use function array_slice;
use function array_values;
use function count;
use function implode;
use function mb_strtolower;
use function preg_replace;
use function sha1;
use function spl_object_id;
use function sprintf;
use function strlen;
use function trim;

/**
 * Coordinates parsing, column mapping, and persistence for board imports.
 */
final readonly class TaskImportOrchestrator
{
    /** Since 1.22: default `import.max_rows`. */
    public const DEFAULT_MAX_ROWS = 5000;

    /** Since 1.22.2: rows written per flush; the batch's tasks then leave the entity manager (audit EB-L7). */
    public const DEFAULT_BATCH_SIZE = 200;

    /** The entities an import creates or loads per row: detached after each batch is written. */
    private const BATCH_CLASSES = [Task::class, TaskLink::class, TaskMember::class, TaskChangeHistory::class, TaskDependency::class];

    /**
     * @param iterable<TaskImporterInterface> $importers
     */
    public function __construct(
        private iterable $importers,
        private TaskRepositoryInterface $taskRepository,
        private BoardColumnManager $columnManager,
        private TaskChangeRecorder $changeRecorder,
        private TaskImportUserResolverInterface $userResolver,
        private EntityManagerInterface $entityManager,
        private ?ManagerRegistry $managerRegistry = null,
        private ?TaskDependencyManager $dependencyManager = null,
        // Since 1.21 (REQ-OBS-001): start, result and refusal of every import (ids and counts only).
        private LoggerInterface $logger = new NullLogger(),
        // Since 1.22: assignees given by name are matched against the board's assignable users (none: names warn).
        private ?TaskAssigneeProviderInterface $assigneeProvider = null,
        // Since 1.22: source ids already imported into another board of the tenant are skipped (none: not checked).
        private ?TaskImportLinkLookupInterface $linkLookup = null,
        private ImportedTaskUpdater $updater = new ImportedTaskUpdater(),
        // Since 1.22 (audit DL-M2): a matched task, a parent and a dependency target must be editable by the actor.
        private ?TaskBoardResourceAccessChecker $accessChecker = null,
        // Since 1.22 (audit DL-L4): the most rows one import accepts (`import.max_rows`).
        private int $maxRows = self::DEFAULT_MAX_ROWS,
        // Since 1.22.2 (audit EB-L7): rows per flush; memory stays flat whatever the size of the file.
        private int $batchSize = self::DEFAULT_BATCH_SIZE,
        // Since 2.0.0: the host's quota listeners (TaskBoardEvents::QUOTA_CHECK), asked for the tasks the file would create.
        private TaskBoardQuota $quota = new TaskBoardQuota(),
        // Since 2.10.0: holds the column events of an import until its commit (dropped on a rollback).
        private ?TaskEventDeferral $events = null,
    ) {
    }

    public function import(
        TaskBoard $board,
        TaskImportSource $source,
        string $content,
        string $filename,
        object $actor,
        TaskImportOptions $options = new TaskImportOptions(),
        ?Task $parentTask = null,
    ): TaskImportResult {
        $context = [
            'bundle'   => 'nowo_task_board',
            'board_id' => $board->getId(),
            'task_id'  => $parentTask?->getId(),
            'source'   => $source->value,
            'bytes'    => strlen($content),
        ];
        $this->logger->info('TaskBoard import started', $context);

        try {
            $result = $this->run($board, $source, $content, $filename, $actor, $options, $parentTask);
        } catch (Throwable $exception) {
            $this->logger->error('TaskBoard import failed', $context + ['exception' => $exception::class]);

            throw $exception;
        }

        $context += [
            'created'      => $result->created,
            'updated'      => $result->updated,
            'unchanged'    => $result->unchanged,
            'skipped'      => $result->skipped,
            'columns'      => $result->columnsCreated,
            'dependencies' => $result->dependenciesCreated,
            'warnings'     => count($result->warnings),
            'errors'       => count($result->errors),
        ];
        if ($result->hasErrors()) {
            $this->logger->warning('TaskBoard import refused', $context);
        } else {
            $this->logger->info('TaskBoard import finished', $context);
        }

        return $result;
    }

    /**
     * Since 1.22 an idempotent upsert: each row is matched on (import scope, source id) — or, without an id, on its
     * fingerprint — against the tasks of the board: a match is updated ({@see ImportedTaskUpdater}), anything else
     * created. A source id already imported into another board of the tenant is skipped with a warning.
     */
    private function run(
        TaskBoard $board,
        TaskImportSource $source,
        string $content,
        string $filename,
        object $actor,
        TaskImportOptions $options,
        ?Task $parentTask,
    ): TaskImportResult {
        $importer = $this->resolveImporter($source);

        try {
            $rows = $importer->parse($content, $filename);
            if (count($rows) > $this->maxRows) {
                throw ImportLimitExceededException::rows($this->maxRows, count($rows));
            }
        } catch (ImportLimitExceededException $exception) {
            // Since 1.22.1: a limit error also carries its translation (the flash is shown in the user's language).
            return new TaskImportResult(errors: [$exception->getMessage()], errorTranslations: [$exception->translatable()]);
        } catch (InvalidArgumentException $exception) {
            return new TaskImportResult(errors: [$exception->getMessage()]);
        }

        // Since 1.22.2 (audit EB-L7): the rows are written in batches (flush, then the batch's tasks leave the entity
        // manager), inside one transaction: a failure anywhere leaves nothing behind.
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        $this->events?->begin();
        try {
            $result = $this->write($board, $source, $rows, $actor, $options, $parentTask);
            $connection->commit();
        } catch (Throwable $exception) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $this->events?->rollBack();
            // A failed flush already reopened a closed entity manager (RecoveringFlusher).
            if ($this->entityManager->isOpen()) {
                // What the rollback removed must not be written by a later flush of the request.
                $this->detachBatch($parentTask);
            }

            throw $exception;
        }
        // Audit JY-L1: after the commit, outside the try: a failing host listener never reports the saved rows as failed.
        $this->events?->commit(false);

        return $result;
    }

    /**
     * @param list<ImportedTaskDto> $rows
     */
    private function write(TaskBoard $board, TaskImportSource $source, array $rows, object $actor, TaskImportOptions $options, ?Task $parentTask): TaskImportResult
    {
        $scope = $source->scope($parentTask);
        // Since 1.23: archived tasks are matched too (a re-import never duplicates them; they stay archived).
        $existingTasks = $this->taskRepository instanceof ArchiveAwareTaskRepositoryInterface
            ? $this->taskRepository->findByBoardIncludingArchived($board)
            : $this->taskRepository->findByBoard($board, true);
        $known        = $this->indexImportedTasks($existingTasks, $scope);
        $maxPositions = $this->collectMaxPositions($existingTasks);
        // Since 1.22.2 the run keeps ids; the tasks of the current batch (the board's at first) are at hand, the others
        // are found again after they left the entity manager.
        /** @var array<string, Task|null> $batch */
        $batch = [];
        foreach ($existingTasks as $existing) {
            $batch[$existing->getId()] = $existing;
        }
        unset($existingTasks);
        $find = function (string $id) use (&$batch): ?Task {
            return $batch[$id] ??= $this->entityManager->find(Task::class, $id);
        };
        /** @var bool $dirty whether the batch has something to write */
        $dirty      = false;
        $writeBatch = function () use (&$batch, &$dirty, $parentTask): void {
            if ($dirty) {
                RecoveringFlusher::flush($this->entityManager, $this->managerRegistry);
            }
            $this->detachBatch($parentTask);
            $batch = [];
            $dirty = false;
        };
        $keyed     = $this->keyRows($this->orderRowsForHierarchy($rows), $scope, $parentTask);
        $elsewhere = $this->findOnOtherBoards($board, $source, $scope, $keyed, $known, $parentTask);
        // Since 2.0.0: before anything is written (columns included). Only rows that create a task count.
        $adding = [];
        foreach ($keyed as [, $key]) {
            if (!isset($known[$key]) && !isset($elsewhere[$key])) {
                $adding[$key] = true;
            }
        }
        $refusal = $adding === [] ? null : $this->quota->refusal(TaskBoardQuotaCheckEvent::ACTION_IMPORT, $board->getTenantKey(), $board, count($adding), $actor);
        if ($refusal !== null) {
            return new TaskImportResult(errors: [$refusal]);
        }

        $columnsByName  = $this->indexColumnsByName($board);
        $columnsCreated = 0;
        $warnings       = [];
        // Since 2.5.0 (TB-13): a missing column is created only for an actor who may manage the board's columns.
        $mayCreateColumns = null;

        foreach ($this->collectStatuses($rows) as $status) {
            if (isset($columnsByName[mb_strtolower($status)])) {
                continue;
            }

            if (!$options->createMissingColumns) {
                $warnings[] = sprintf('Status "%s" was not found on the board and missing columns are disabled.', $status);
                continue;
            }

            $mayCreateColumns ??= !$this->accessChecker instanceof TaskBoardResourceAccessChecker || $this->accessChecker->canManageColumns($actor, $board);
            if (!$mayCreateColumns) {
                $warnings[] = sprintf('Status "%s" was not found on the board and you may not add columns to it.', $status);
                continue;
            }

            // @igor-ignore - Import orchestrator runs in HTTP request context; not a shared cache.
            $column                                = $this->columnManager->add($board, new BoardColumnFormData(name: $status), $actor);
            $columnsByName[mb_strtolower($status)] = $column;
            ++$columnsCreated;
        }

        /** @var array<string, bool> $editable whether the actor may edit each task (by id), asked once */
        $editable = [];
        $canEdit  = function (Task $task) use (&$editable, $actor): bool {
            return $editable[$task->getId()] ??= !$this->accessChecker instanceof TaskBoardResourceAccessChecker
                || $this->accessChecker->canEditTask($actor, $task);
        };
        $columns    = array_values($board->getColumns()->toArray());
        $positionIn = function (?BoardColumn $column) use (&$maxPositions): int {
            return $this->nextPosition($maxPositions, $column);
        };
        $assignees = new ImportAssigneeResolver($this->userResolver, $this->assigneeProvider, $board);

        /** @var array<string, string> $imported the task ids of this run by key (created or matched) */
        $imported = [];
        /** @var array<string, bool> $matched whether each matched task (by id) changed */
        $matched = [];
        /** @var list<array{0: string, 1: ImportedTaskDto}> $pendingDependencies */
        $pendingDependencies = [];
        $created             = 0;
        $skipped             = 0;
        $fingerprinted       = 0;
        $skippedElsewhere    = 0;
        $skippedReadOnly     = 0;
        $inBatch             = 0;

        foreach ($keyed as $index => [$row, $key, $byFingerprint]) {
            if (isset($imported[$key])) {
                ++$skipped;
                $warnings[] = sprintf('Row %d ("%s"): id "%s" repeats an earlier row of the file; skipped.', $index + 1, $row->title, $row->externalId);
                continue;
            }
            if (isset($elsewhere[$key])) {
                ++$skipped;
                ++$skippedElsewhere;
                continue;
            }
            $task = isset($known[$key]) ? $find($known[$key]) : null;
            if ($task instanceof Task && !$canEdit($task)) {
                // Audit DL-M2: a task the actor may not edit is not rewritten (and its title is not echoed).
                ++$skipped;
                ++$skippedReadOnly;
                continue;
            }
            if ($byFingerprint) {
                ++$fingerprinted;
            }

            if ($task instanceof Task) {
                $changed                 = $this->updater->apply($task, $row, $this->matchColumn($columnsByName, $row->status), $positionIn, $columns, $actor);
                $changed                 = $this->reparent($task, $row, $index, $imported, $known, $find, $canEdit, $warnings) || $changed;
                $changed                 = $this->updater->applyAssignees($task, $assignees->resolve($row), $actor) || $changed;
                $matched[$task->getId()] = $changed;
                $dirty                   = $dirty || $changed;
            } else {
                $task                     = $this->create($board, $source, $scope, $row, $key, $byFingerprint, $index, $actor, $parentTask, $columnsByName, $positionIn, $imported, $known, $find, $canEdit, $warnings);
                $editable[$task->getId()] = true;
                foreach ($assignees->resolve($row) as $user) {
                    // @igor-ignore - $task is a Doctrine entity of this import, not a shared service.
                    $task->addMember(new TaskMember($task, $user, TaskMemberRole::Assignee));
                }
                $this->changeRecorder->recordCreated($task, $actor);
                $this->entityManager->persist($task);
                $batch[$task->getId()] = $task;
                $dirty                 = true;
                ++$created;
            }

            $imported[$key] = $task->getId();
            if ($row->dependsOn !== []) {
                $pendingDependencies[] = [$task->getId(), $row];
            }
            if (++$inBatch >= $this->batchSize) {
                $writeBatch();
                $inBatch = 0;
            }
        }
        // The last batch is written; its tasks stay at hand for the relations (they leave after them).
        if ($dirty) {
            RecoveringFlusher::flush($this->entityManager, $this->managerRegistry);
            $dirty = false;
        }

        $dependenciesCreated = $this->linkDependencies($pendingDependencies, $imported, $known, $find, $writeBatch, $canEdit, $actor, $warnings, $matched);
        $writeBatch();
        $updated = count(array_filter($matched));

        if ($fingerprinted > 0) {
            $warnings[] = sprintf('%d row(s) have no id: they are matched by fingerprint (source, parent, title and creation date), so a row renamed in the source is imported as a new task.', $fingerprinted);
        }
        if ($skippedElsewhere > 0) {
            // Audit DL-L1: a count only — no board, no title.
            $warnings[] = sprintf('%d row(s) were skipped: already imported elsewhere (an import never moves or copies tasks between boards).', $skippedElsewhere);
        }
        if ($skippedReadOnly > 0) {
            $warnings[] = sprintf('%d row(s) were skipped: they match tasks you cannot edit.', $skippedReadOnly);
        }

        return new TaskImportResult(
            created: $created,
            skipped: $skipped,
            columnsCreated: $columnsCreated,
            warnings: [...$warnings, ...$assignees->warnings()],
            dependenciesCreated: $dependenciesCreated,
            updated: $updated,
            unchanged: count($matched) - $updated,
        );
    }

    /**
     * Since 1.22.2: after a batch is written its tasks (and their links, members, history and relations) leave the
     * entity manager; the board, its columns, the users and the task imported into stay managed.
     */
    private function detachBatch(?Task $parentTask): void
    {
        $identityMap = $this->entityManager->getUnitOfWork()->getIdentityMap();
        foreach (self::BATCH_CLASSES as $class) {
            foreach ($identityMap[$class] ?? [] as $entity) {
                if (!$this->belongsTo($entity, $parentTask)) {
                    $this->entityManager->detach($entity);
                }
            }
        }
    }

    /**
     * Whether an entity is the task imported into or hangs from it (its links, members, history, relations): those
     * stay managed, or a later flush would cascade them as new rows.
     */
    private function belongsTo(object $entity, ?Task $parentTask): bool
    {
        if (!$parentTask instanceof Task) {
            return false;
        }

        return $entity === $parentTask
            || ($entity instanceof TaskDependency && ($entity->getSourceTask() === $parentTask || $entity->getTargetTask() === $parentTask))
            || (($entity instanceof TaskLink || $entity instanceof TaskMember || $entity instanceof TaskChangeHistory) && $entity->getTask() === $parentTask);
    }

    /**
     * A new task for a row that matched none: in its status column (else the first), under its parent row (else the
     * task imported into, else the root), with the import link that carries its key.
     *
     * @param array<string, BoardColumn> $columnsByName
     * @param Closure(?BoardColumn): int $positionIn
     * @param array<string, string> $imported
     * @param array<string, string> $known
     * @param Closure(string): ?Task $find
     * @param Closure(Task): bool $canEdit
     * @param list<string> $warnings
     */
    private function create(
        TaskBoard $board,
        TaskImportSource $source,
        string $scope,
        ImportedTaskDto $row,
        string $key,
        bool $byFingerprint,
        int $index,
        object $actor,
        ?Task $parentTask,
        array $columnsByName,
        Closure $positionIn,
        array $imported,
        array $known,
        Closure $find,
        Closure $canEdit,
        array &$warnings,
    ): Task {
        // Since 1.18: importing into a task makes every top-level row (and every unresolved parent) its subtask.
        $parent = $parentTask;
        if ($row->parentExternalId !== null) {
            $parent = $this->findParent($row->parentExternalId, $imported, $known, $find, $canEdit);
            if (!$parent instanceof Task) {
                $parent     = $parentTask;
                $warnings[] = sprintf(
                    $parentTask instanceof Task ? 'Row %d ("%s"): parent "%s" was not imported yet; task created under the task you import into.' : 'Row %d ("%s"): parent "%s" was not imported yet; task created at root level.',
                    $index + 1,
                    $row->title,
                    $row->parentExternalId,
                );
            }
        }

        $column = $this->matchColumn($columnsByName, $row->status) ?? ($board->getColumns()->first() ?: null);
        $task   = new Task(
            board: $board,
            title: $row->title,
            creator: $actor,
            column: $column,
            parent: $parent,
            description: $row->description,
            priority: $row->priority,
            position: $positionIn($column),
            estimatedMinutes: $row->estimatedMinutes,
            dueAt: $row->dueAt,
            tags: $row->tags,
        );
        // Since 1.22: without a start date the source's creation date places the Gantt bar — on create only; a
        // re-import sets the start only from a start date (the task's own start is never overwritten from it).
        $startAt = $row->startAt ?? $row->createdAt;
        if ($startAt instanceof DateTimeImmutable) {
            $task->setStartAt($startAt);
        }

        $task->addLink(new TaskLink(
            task: $task,
            linkType: TaskLinkType::Other,
            url: $row->sourceUrl ?? sprintf('import://%s/%s', $source->value, $key),
            label: $byFingerprint ? sprintf('%s (no id)', $source->value) : sprintf('%s #%s', $source->value, $key),
            externalId: $key,
            importSource: $scope,
        ));

        $this->syncCompletionForColumn($task, $column, $board);

        return $task;
    }

    /**
     * Since 1.22: a matched task follows its parent row when the source names one that exists (in the file or on the
     * board) and that the actor may edit; a row without a parent keeps the task where it is (the task imported into
     * only places new tasks), and a move that would make the task its own ancestor is refused with a warning — the
     * whole ancestor chain is walked (audit DL-L5).
     *
     * @param array<string, string> $imported
     * @param array<string, string> $known
     * @param Closure(string): ?Task $find
     * @param Closure(Task): bool $canEdit
     * @param list<string> $warnings
     */
    private function reparent(Task $task, ImportedTaskDto $row, int $index, array $imported, array $known, Closure $find, Closure $canEdit, array &$warnings): bool
    {
        if ($row->parentExternalId === null) {
            return false;
        }
        $parent = $this->findParent($row->parentExternalId, $imported, $known, $find, $canEdit);
        if (!$parent instanceof Task) {
            $warnings[] = sprintf('Row %d ("%s"): parent "%s" was not found; the task keeps its parent.', $index + 1, $row->title, $row->parentExternalId);

            return false;
        }
        if ($parent === $task->getParent()) {
            return false;
        }
        $seen = [];
        for ($ancestor = $parent; $ancestor instanceof Task; $ancestor = $ancestor->getParent()) {
            if ($ancestor === $task || isset($seen[spl_object_id($ancestor)])) {
                $warnings[] = sprintf('Row %d ("%s"): moving it under "%s" would make it its own ancestor; the task keeps its parent.', $index + 1, $row->title, $row->parentExternalId);

                return false;
            }
            $seen[spl_object_id($ancestor)] = true;
        }
        $task->setParent($parent);

        return true;
    }

    /**
     * The task an id names — a row of this run or a task the scope imported before — when the actor may edit it;
     * null otherwise (the same answer for "missing" and "not yours", so the import reveals nothing).
     *
     * @param array<string, string> $imported
     * @param array<string, string> $known
     * @param Closure(string): ?Task $find
     * @param Closure(Task): bool $canEdit
     */
    private function findParent(string $id, array $imported, array $known, Closure $find, Closure $canEdit): ?Task
    {
        $taskId = $imported[$id] ?? $known[$id] ?? null;
        $task   = $taskId === null ? null : $find($taskId);

        return $task instanceof Task && $canEdit($task) ? $task : null;
    }

    /**
     * Each row with its matching key: its source id, or "fp:" and a fingerprint of (scope, parent, normalized title,
     * creation date) — the n-th identical row of a file gets "-n", so repeated rows stay apart and stable.
     *
     * @param list<ImportedTaskDto> $rows
     *
     * @return list<array{0: ImportedTaskDto, 1: string, 2: bool}>
     */
    private function keyRows(array $rows, string $scope, ?Task $parentTask): array
    {
        $keyed = [];
        $seen  = [];
        foreach ($rows as $row) {
            if ($row->externalId !== null) {
                $keyed[] = [$row, Text::cut($row->externalId, 128), false];
                continue;
            }
            $title       = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $row->title) ?? $row->title));
            $fingerprint = sha1(implode("\x1F", [
                $scope,
                $row->parentExternalId ?? $parentTask?->getId() ?? '',
                $title,
                $row->createdAt?->format('Y-m-d H:i:s') ?? '',
            ]));
            $seen[$fingerprint] = ($seen[$fingerprint] ?? 0) + 1;
            $keyed[]            = [$row, 'fp:' . $fingerprint . ($seen[$fingerprint] > 1 ? '-' . $seen[$fingerprint] : ''), true];
        }

        return $keyed;
    }

    /**
     * The keys of the file already imported into another board of the tenant and not on this one: source ids and,
     * since 1.22, fingerprints of rows without an id — for tools with global ids only (the bundle's own format is
     * board- or task-local).
     *
     * @param list<array{0: ImportedTaskDto, 1: string, 2: bool}> $keyed
     * @param array<string, string> $known
     *
     * @return array<string, true>
     */
    private function findOnOtherBoards(TaskBoard $board, TaskImportSource $source, string $scope, array $keyed, array $known, ?Task $parentTask): array
    {
        // An import into a task has a task-local scope (no other board can hold it).
        if (!$this->linkLookup instanceof TaskImportLinkLookupInterface || !$source->hasGlobalIds() || $parentTask instanceof Task) {
            return [];
        }
        $ids = [];
        foreach ($keyed as [, $key]) {
            if (!isset($known[$key])) {
                $ids[$key] = $key;
            }
        }

        // @igor-ignore - a read-only query; the lookup keeps no state.
        return $ids === [] ? [] : $this->linkLookup->findOnOtherBoards($board, $scope, array_values($ids));
    }

    /**
     * The tasks of the board an earlier import of this scope created, as ids by external id: only links whose
     * `import_source` is the scope — written by the importer, never by the link form (audit DL-L2).
     *
     * @param list<Task> $existingTasks
     *
     * @return array<string, string>
     */
    private function indexImportedTasks(array $existingTasks, string $scope): array
    {
        $index = [];
        foreach ($existingTasks as $task) {
            foreach ($task->getLinks() as $link) {
                $externalId = $link->getExternalId();
                if ($externalId === null || $externalId === '' || isset($index[$externalId])) {
                    continue;
                }
                if ($link->getImportSource() === $scope) {
                    $index[$externalId] = $task->getId();
                }
            }
        }

        return $index;
    }

    /**
     * Since 1.18: the Gantt relations of the imported rows, once every task exists. Since 1.22 the other task is looked
     * up in the file and among the tasks the scope imported before; a relation the board already has is left as it
     * is (silently: re-importing is a no-op), one to a task that cannot be found or that the rules refuse (cycle) is a
     * warning, and only relations are added — none is removed.
     *
     * @param list<array{0: string, 1: ImportedTaskDto}> $pending
     * @param array<string, string> $imported
     * @param array<string, string> $known
     * @param Closure(string): ?Task $find
     * @param Closure(): void $writeBatch
     * @param Closure(Task): bool $canEdit
     * @param list<string> $warnings
     * @param array<string, bool> $matched
     */
    private function linkDependencies(array $pending, array $imported, array $known, Closure $find, Closure $writeBatch, Closure $canEdit, object $actor, array &$warnings, array &$matched): int
    {
        if ($pending === [] || !$this->dependencyManager instanceof TaskDependencyManager) {
            return 0;
        }
        $created = 0;
        foreach ($pending as $number => [$taskId, $row]) {
            if ($number > 0 && $number % $this->batchSize === 0) {
                // Each relation is saved by the dependency manager: the batch only leaves the entity manager.
                $writeBatch();
            }
            $task = $find($taskId) ?? throw new LogicException(sprintf('The imported task "%s" disappeared during the import.', $taskId));
            foreach ($row->dependsOn as [$otherId, $type]) {
                $other = $this->findParent($otherId, $imported, $known, $find, $canEdit);
                if (!$other instanceof Task) {
                    $warnings[] = sprintf('"%s": the dependency on "%s" was skipped (that row was not imported).', $row->title, $otherId);
                    continue;
                }
                try {
                    // @igor-ignore - the dependency manager persists through Doctrine; it keeps no state of its own.
                    $this->dependencyManager->add($task, $other, $type, $actor);
                    ++$created;
                    if (isset($matched[$taskId])) {
                        $matched[$taskId] = true;
                    }
                } catch (InvalidTaskDependencyException $exception) {
                    if ($exception->reason !== InvalidTaskDependencyException::REASON_DUPLICATE) {
                        $warnings[] = sprintf('"%s": the dependency on "%s" was skipped: %s', $row->title, $otherId, $exception->getMessage());
                    }
                }
            }
        }

        return $created;
    }

    private function resolveImporter(TaskImportSource $source): TaskImporterInterface
    {
        foreach ($this->importers as $importer) {
            if ($importer->supports($source)) {
                return $importer;
            }
        }

        throw new InvalidArgumentException(sprintf('No importer registered for source "%s".', $source->value));
    }

    /**
     * @return array<string, BoardColumn>
     */
    private function indexColumnsByName(TaskBoard $board): array
    {
        $indexed = [];
        foreach ($board->getColumns() as $column) {
            $indexed[mb_strtolower(trim($column->getName()))] = $column;
        }

        return $indexed;
    }

    /**
     * @param list<ImportedTaskDto> $rows
     *
     * @return list<string>
     */
    private function collectStatuses(array $rows): array
    {
        $statuses = [];
        foreach ($rows as $row) {
            $status = trim((string) $row->status);
            if ($status !== '' && !isset($statuses[mb_strtolower($status)])) {
                $statuses[mb_strtolower($status)] = $status;
            }
        }

        return array_values($statuses);
    }

    /**
     * @param list<ImportedTaskDto> $rows
     *
     * @return list<ImportedTaskDto>
     */
    private function orderRowsForHierarchy(array $rows): array
    {
        /** @var array<string, ImportedTaskDto> $byExternalId */
        $byExternalId = [];
        foreach ($rows as $row) {
            if ($row->externalId !== null) {
                // Since 1.22: the first row of an id is the parent (a repeated id is skipped later, not dropped here).
                $byExternalId[$row->externalId] ??= $row;
            }
        }

        // Since 1.22 iterative (audit DL-L4: a long parent chain no longer recurses): each row's chain of parents in
        // the file is placed parents first; in a cycle, the row where it closes goes first, then the rest of it.
        $ordered = [];
        $placed  = [];
        foreach ($rows as $row) {
            $chain   = [];
            $onChain = [];
            $closing = null;
            for ($current = $row; $current instanceof ImportedTaskDto && !isset($placed[spl_object_id($current)]); $current = $current->parentExternalId !== null ? $byExternalId[$current->parentExternalId] ?? null : null) {
                if (isset($onChain[spl_object_id($current)])) {
                    $closing = $onChain[spl_object_id($current)];
                    break;
                }
                $onChain[spl_object_id($current)] = count($chain);
                $chain[]                          = $current;
            }
            $order = $closing === null
                ? array_reverse($chain)
                : [$chain[$closing], ...array_reverse(array_slice($chain, $closing + 1)), ...array_reverse(array_slice($chain, 0, $closing))];
            foreach ($order as $item) {
                $ordered[]                    = $item;
                $placed[spl_object_id($item)] = true;
            }
        }

        return $ordered;
    }

    /**
     * The column named by a status (case-insensitive), null without a status or when the board has none of that name.
     *
     * @param array<string, BoardColumn> $columnsByName
     */
    private function matchColumn(array $columnsByName, ?string $status): ?BoardColumn
    {
        return $status !== null && $status !== '' ? $columnsByName[mb_strtolower(trim($status))] ?? null : null;
    }

    /**
     * @param list<Task> $existingTasks
     *
     * @return array<string, int> highest position per column id; key '' holds the highest position on the board
     */
    private function collectMaxPositions(array $existingTasks): array
    {
        $maxPositions = ['' => -1];
        foreach ($existingTasks as $task) {
            $position         = $task->getPosition();
            $maxPositions[''] = max($maxPositions[''], $position);
            $columnId         = $task->getColumn()?->getId();
            if ($columnId !== null) {
                $maxPositions[$columnId] = max($maxPositions[$columnId] ?? -1, $position);
            }
        }

        return $maxPositions;
    }

    /**
     * @param array<string, int> $maxPositions
     */
    private function nextPosition(array &$maxPositions, ?BoardColumn $column): int
    {
        $key                = $column instanceof BoardColumn ? $column->getId() : '';
        $next               = ($maxPositions[$key] ?? -1) + 1;
        $maxPositions[$key] = $next;
        $maxPositions['']   = max($maxPositions[''] ?? -1, $next);

        return $next;
    }

    private function syncCompletionForColumn(Task $task, ?BoardColumn $column, TaskBoard $board): void
    {
        $columns = array_values($board->getColumns()->toArray());
        if ($columns === [] || !$column instanceof BoardColumn) {
            return;
        }

        // Since 2.18.0 (FR-033): a done or closed column (the last one on an untyped board).
        if (ColumnTypes::isDone($column, ColumnTypes::ordered($board))) {
            $task->markCompleted();
        }
    }
}
