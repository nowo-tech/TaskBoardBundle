<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Import;

use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Nowo\TaskBoardBundle\Dto\BoardColumnFormData;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskLink;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Enum\TaskLinkType;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Import\Dto\ImportedTaskDto;
use Nowo\TaskBoardBundle\Import\Dto\TaskImportOptions;
use Nowo\TaskBoardBundle\Import\Dto\TaskImportResult;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TaskBoardBundle\Service\BoardColumnManager;
use Nowo\TaskBoardBundle\Service\TaskChangeRecorder;

use function mb_strtolower;
use function sprintf;
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
    ) {
    }

    public function import(
        TaskBoard $board,
        TaskImportSource $source,
        string $content,
        string $filename,
        object $actor,
        TaskImportOptions $options = new TaskImportOptions(),
    ): TaskImportResult {
        $importer = $this->resolveImporter($source);

        try {
            $rows = $importer->parse($content, $filename);
        } catch (InvalidArgumentException $exception) {
            return new TaskImportResult(errors: [$exception->getMessage()]);
        }

        $existingExternalIds = $this->collectExistingExternalIds($board);
        $columnsByName       = $this->indexColumnsByName($board);
        $columnsCreated      = 0;
        $warnings            = [];

        foreach ($this->collectStatuses($rows) as $status) {
            if (isset($columnsByName[mb_strtolower($status)])) {
                continue;
            }

            if (!$options->createMissingColumns) {
                $warnings[] = sprintf('Status "%s" was not found on the board and missing columns are disabled.', $status);
                continue;
            }

            $column                                = $this->columnManager->add($board, new BoardColumnFormData(name: $status));
            $columnsByName[mb_strtolower($status)] = $column;
            ++$columnsCreated;
        }

        /** @var array<string, Task> $importedByExternalId */
        $importedByExternalId = [];
        $created              = 0;
        $skipped              = 0;
        $errors               = [];

        $orderedRows = $this->orderRowsForHierarchy($rows);

        foreach ($orderedRows as $index => $row) {
            if ($row->externalId !== null
                && $options->skipExisting
                && isset($existingExternalIds[$row->externalId])) {
                ++$skipped;
                continue;
            }

            $parent = null;
            if ($row->parentExternalId !== null) {
                $parent = $importedByExternalId[$row->parentExternalId] ?? null;
                if ($parent === null) {
                    $warnings[] = sprintf(
                        'Row %d ("%s"): parent "%s" was not imported yet; task created at root level.',
                        $index + 1,
                        $row->title,
                        $row->parentExternalId,
                    );
                }
            }

            $column = $this->resolveColumn($board, $columnsByName, $row->status);
            $task   = new Task(
                board: $board,
                title: $row->title,
                creator: $actor,
                column: $column,
                parent: $parent,
                description: $row->description,
                priority: $row->priority,
                position: $this->nextPosition($board, $column),
                estimatedMinutes: $row->estimatedMinutes,
                dueAt: $row->dueAt,
                tags: $row->tags,
            );

            if ($row->externalId !== null) {
                $task->addLink(new TaskLink(
                    task: $task,
                    linkType: TaskLinkType::Other,
                    url: $row->sourceUrl ?? sprintf('import://%s/%s', $source->value, $row->externalId),
                    label: sprintf('%s #%s', $source->value, $row->externalId),
                    externalId: $row->externalId,
                ));
            } elseif ($row->sourceUrl !== null) {
                $task->addLink(new TaskLink(
                    task: $task,
                    linkType: TaskLinkType::Url,
                    url: $row->sourceUrl,
                    label: $row->title,
                ));
            }

            if ($row->assigneeEmail !== null) {
                $assignee = $this->userResolver->resolveByEmail($row->assigneeEmail);
                if ($assignee !== null) {
                    $task->addMember(new TaskMember($task, $assignee, TaskMemberRole::Assignee));
                } else {
                    $warnings[] = sprintf('Assignee "%s" for "%s" was not found in the application.', $row->assigneeEmail, $row->title);
                }
            }

            $this->syncCompletionForColumn($task, $column, $board);
            $this->changeRecorder->recordCreated($task, $actor);
            $this->entityManager->persist($task);

            if ($row->externalId !== null) {
                $importedByExternalId[$row->externalId] = $task;
                $existingExternalIds[$row->externalId]  = true;
            }

            ++$created;
        }

        if ($created > 0) {
            $this->entityManager->flush();
        }

        return new TaskImportResult(
            created: $created,
            skipped: $skipped,
            columnsCreated: $columnsCreated,
            errors: $errors,
            warnings: $warnings,
        );
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
     * @return array<string, true>
     */
    private function collectExistingExternalIds(TaskBoard $board): array
    {
        $ids = [];
        foreach ($this->taskRepository->findByBoard($board, true) as $task) {
            foreach ($task->getLinks() as $link) {
                $externalId = $link->getExternalId();
                if ($externalId !== null && $externalId !== '') {
                    $ids[$externalId] = true;
                }
            }
        }

        return $ids;
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
                $byExternalId[$row->externalId] = $row;
            }
        }

        $ordered  = [];
        $visited  = [];
        $visiting = [];

        $visit = static function (ImportedTaskDto $row) use (&$visit, &$ordered, &$visited, &$visiting, $byExternalId): void {
            $key = $row->externalId ?? spl_object_hash($row);
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
     * @param array<string, BoardColumn> $columnsByName
     */
    private function resolveColumn(TaskBoard $board, array $columnsByName, ?string $status): ?BoardColumn
    {
        if ($status !== null && $status !== '') {
            $column = $columnsByName[mb_strtolower(trim($status))] ?? null;
            if ($column instanceof BoardColumn) {
                return $column;
            }
        }

        return $board->getColumns()->first() ?: null;
    }

    private function nextPosition(TaskBoard $board, ?BoardColumn $column): int
    {
        $max = -1;
        foreach ($this->taskRepository->findByBoard($board, true) as $task) {
            if (!$column instanceof BoardColumn || ($task->getColumn()?->getId() === $column->getId())) {
                $max = max($max, $task->getPosition());
            }
        }

        return $max + 1;
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
