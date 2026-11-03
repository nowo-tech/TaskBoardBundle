<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Import;

use Closure;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use InvalidArgumentException;
use Nowo\TaskBoardBundle\Assignee\TaskAssigneeProviderInterface;
use Nowo\TaskBoardBundle\Doctrine\RecoveringFlusher;
use Nowo\TaskBoardBundle\Dto\BoardColumnFormData;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskLink;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Enum\TaskLinkType;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Exception\InvalidTaskDependencyException;
use Nowo\TaskBoardBundle\Import\Dto\ImportedTaskDto;
use Nowo\TaskBoardBundle\Import\Dto\TaskImportOptions;
use Nowo\TaskBoardBundle\Import\Dto\TaskImportResult;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TaskBoardBundle\Service\BoardColumnManager;
use Nowo\TaskBoardBundle\Service\TaskChangeRecorder;
use Nowo\TaskBoardBundle\Service\TaskDependencyManager;
use Nowo\TaskBoardBundle\Support\Text;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use function array_filter;
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
        } catch (InvalidArgumentException $exception) {
            return new TaskImportResult(errors: [$exception->getMessage()]);
        }

        $scope          = $source->scope($parentTask);
        $existingTasks  = $this->taskRepository->findByBoard($board, true);
        $known          = $this->indexImportedTasks($existingTasks, $scope);
        $maxPositions   = $this->collectMaxPositions($existingTasks);
        $columnsByName  = $this->indexColumnsByName($board);
        $columnsCreated = 0;
        $warnings       = [];

        foreach ($this->collectStatuses($rows) as $status) {
            if (isset($columnsByName[mb_strtolower($status)])) {
                continue;
            }

            if (!$options->createMissingColumns) {
                $warnings[] = sprintf('Status "%s" was not found on the board and missing columns are disabled.', $status);
                continue;
            }

            // @igor-ignore - Import orchestrator runs in HTTP request context; not a shared cache.
            $column                                = $this->columnManager->add($board, new BoardColumnFormData(name: $status));
            $columnsByName[mb_strtolower($status)] = $column;
            ++$columnsCreated;
        }

        $keyed      = $this->keyRows($this->orderRowsForHierarchy($rows), $scope, $parentTask);
        $elsewhere  = $this->findOnOtherBoards($board, $source, $scope, $keyed, $known);
        $columns    = array_values($board->getColumns()->toArray());
        $positionIn = function (?BoardColumn $column) use (&$maxPositions): int {
            return $this->nextPosition($maxPositions, $column);
        };
        $assignees = new ImportAssigneeResolver($this->userResolver, $this->assigneeProvider, $board);

        /** @var array<string, Task> $imported the tasks of this run by key (created or matched) */
        $imported = [];
        /** @var array<int, bool> $matched whether each matched task (by object id) changed */
        $matched = [];
        /** @var list<array{0: Task, 1: ImportedTaskDto}> $pendingDependencies */
        $pendingDependencies = [];
        $created             = 0;
        $skipped             = 0;
        $fingerprinted       = 0;
        $legacyAdopted       = false;
        /** @var list<string> $skippedElsewhere */
        $skippedElsewhere = [];

        foreach ($keyed as $index => [$row, $key, $byFingerprint]) {
            if (isset($imported[$key])) {
                ++$skipped;
                $warnings[] = sprintf('Row %d ("%s"): id "%s" repeats an earlier row of the file; skipped.', $index + 1, $row->title, $row->externalId);
                continue;
            }
            if (isset($elsewhere[$key])) {
                ++$skipped;
                $skippedElsewhere[] = $row->title;
                continue;
            }
            if ($byFingerprint) {
                ++$fingerprinted;
            }

            $match = $known[$key] ?? null;
            if ($match !== null) {
                [$task, $link] = $match;
                if ($link->getImportSource() === null) {
                    // A pre-1.22 link: it gets its scope now, so the next import matches it directly.
                    $link->setImportSource($scope);
                    $legacyAdopted = true;
                }
                $changed                       = $this->updater->apply($task, $row, $this->matchColumn($columnsByName, $row->status), $positionIn, $columns, $actor);
                $changed                       = $this->reparent($task, $row, $index, $imported, $known, $warnings) || $changed;
                $changed                       = $this->updater->applyAssignees($task, $assignees->resolve($row), $actor) || $changed;
                $matched[spl_object_id($task)] = $changed;
            } else {
                $task = $this->create($board, $source, $scope, $row, $key, $byFingerprint, $index, $actor, $parentTask, $columnsByName, $positionIn, $imported, $known, $warnings);
                foreach ($assignees->resolve($row) as $user) {
                    $task->addMember(new TaskMember($task, $user, TaskMemberRole::Assignee));
                }
                $this->changeRecorder->recordCreated($task, $actor);
                $this->entityManager->persist($task);
                ++$created;
            }

            $imported[$key] = $task;
            if ($row->dependsOn !== []) {
                $pendingDependencies[] = [$task, $row];
            }
        }

        $updated = count(array_filter($matched));
        if ($created > 0 || $updated > 0 || $legacyAdopted) {
            RecoveringFlusher::flush($this->entityManager, $this->managerRegistry);
        }

        $dependenciesCreated = $this->linkDependencies($pendingDependencies, $imported, $known, $actor, $warnings, $matched);
        $updated             = count(array_filter($matched));

        if ($fingerprinted > 0) {
            $warnings[] = sprintf('%d row(s) have no id: they are matched by fingerprint (source, parent, title and creation date), so a row renamed in the source is imported as a new task.', $fingerprinted);
        }
        if ($skippedElsewhere !== []) {
            $warnings[] = sprintf(
                '%d row(s) were already imported into another board and were skipped (an import never moves or copies tasks between boards): %s.',
                count($skippedElsewhere),
                implode(', ', array_slice($skippedElsewhere, 0, 5)) . (count($skippedElsewhere) > 5 ? ', …' : ''),
            );
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
     * A new task for a row that matched none: in its status column (else the first), under its parent row (else the
     * task imported into, else the root), with the import link that carries its key.
     *
     * @param array<string, BoardColumn> $columnsByName
     * @param Closure(?BoardColumn): int $positionIn
     * @param array<string, Task> $imported
     * @param array<string, array{0: Task, 1: TaskLink}> $known
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
        array &$warnings,
    ): Task {
        // Since 1.18: importing into a task makes every top-level row (and every unresolved parent) its subtask.
        $parent = $parentTask;
        if ($row->parentExternalId !== null) {
            $parent = $imported[$row->parentExternalId] ?? $known[$row->parentExternalId][0] ?? null;
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
     * board); a row without a parent keeps the task where it is (the task imported into only places new tasks), and a
     * move that would make the task its own ancestor is refused with a warning.
     *
     * @param array<string, Task> $imported
     * @param array<string, array{0: Task, 1: TaskLink}> $known
     * @param list<string> $warnings
     */
    private function reparent(Task $task, ImportedTaskDto $row, int $index, array $imported, array $known, array &$warnings): bool
    {
        if ($row->parentExternalId === null) {
            return false;
        }
        $parent = $imported[$row->parentExternalId] ?? $known[$row->parentExternalId][0] ?? null;
        if (!$parent instanceof Task) {
            $warnings[] = sprintf('Row %d ("%s"): parent "%s" was not found; the task keeps its parent.', $index + 1, $row->title, $row->parentExternalId);

            return false;
        }
        if ($parent === $task->getParent()) {
            return false;
        }
        for ($ancestor = $parent, $depth = 0; $ancestor instanceof Task && $depth < 1000; $ancestor = $ancestor->getParent(), ++$depth) {
            if ($ancestor === $task) {
                $warnings[] = sprintf('Row %d ("%s"): moving it under "%s" would make it its own ancestor; the task keeps its parent.', $index + 1, $row->title, $parent->getTitle());

                return false;
            }
        }
        $task->setParent($parent);

        return true;
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
     * @param array<string, array{0: Task, 1: TaskLink}> $known
     *
     * @return array<string, true>
     */
    private function findOnOtherBoards(TaskBoard $board, TaskImportSource $source, string $scope, array $keyed, array $known): array
    {
        if (!$this->linkLookup instanceof TaskImportLinkLookupInterface || !$source->hasGlobalIds()) {
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
     * The tasks of the board an earlier import of this scope created, by external id: links with the scope, and
     * pre-1.22 links (no scope) whose label names a source of the same provider ({@see TaskImportSource::fromLegacyLabel()}).
     *
     * @param list<Task> $existingTasks
     *
     * @return array<string, array{0: Task, 1: TaskLink}>
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
                $linkScope = $link->getImportSource() ?? TaskImportSource::fromLegacyLabel($link->getLabel(), $externalId)?->provider();
                if ($linkScope === $scope) {
                    $index[$externalId] = [$task, $link];
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
     * @param list<array{0: Task, 1: ImportedTaskDto}> $pending
     * @param array<string, Task> $imported
     * @param array<string, array{0: Task, 1: TaskLink}> $known
     * @param list<string> $warnings
     * @param array<int, bool> $matched
     */
    private function linkDependencies(array $pending, array $imported, array $known, object $actor, array &$warnings, array &$matched): int
    {
        if ($pending === [] || !$this->dependencyManager instanceof TaskDependencyManager) {
            return 0;
        }
        $created = 0;
        foreach ($pending as [$task, $row]) {
            foreach ($row->dependsOn as [$otherId, $type]) {
                $other = $imported[$otherId] ?? $known[$otherId][0] ?? null;
                if ($other === null) {
                    $warnings[] = sprintf('"%s": the dependency on "%s" was skipped (that row was not imported).', $row->title, $otherId);
                    continue;
                }
                try {
                    // @igor-ignore - the dependency manager persists through Doctrine; it keeps no state of its own.
                    $this->dependencyManager->add($task, $other, $type, $actor);
                    ++$created;
                    if (isset($matched[spl_object_id($task)])) {
                        $matched[spl_object_id($task)] = true;
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

        $ordered  = [];
        $visited  = [];
        $visiting = [];

        $visit = static function (ImportedTaskDto $row) use (&$visit, &$ordered, &$visited, &$visiting, $byExternalId): void {
            $key = spl_object_id($row);
            if (isset($visited[$key])) {
                return;
            }

            if (isset($visiting[$key])) {
                $ordered[]     = $row;
                $visited[$key] = true;

                return;
            }

            $visiting[$key] = true;

            if ($row->parentExternalId !== null && isset($byExternalId[$row->parentExternalId])) {
                $visit($byExternalId[$row->parentExternalId]);
                if (isset($visited[$key])) {
                    // Its parent chain came back to it (a cycle): it was placed then.
                    return;
                }
            }

            $ordered[]     = $row;
            $visited[$key] = true;
            unset($visiting[$key]);
        };

        foreach ($rows as $row) {
            $visit($row);
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

        $lastColumn = $columns[array_key_last($columns)];
        if ($column->getId() === $lastColumn->getId()) {
            $task->markCompleted();
        }
    }
}
