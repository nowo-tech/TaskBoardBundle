<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Configuration;

use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Enum\ColumnType;
use Nowo\TaskBoardBundle\Exception\TaskReopenRefusedException;
use Nowo\TaskBoardBundle\Support\ColumnIcon;
use Nowo\TaskBoardBundle\Support\ColumnTasks;
use Nowo\TaskBoardBundle\Support\ColumnTypes;
use Nowo\TaskBoardBundle\Sync\Hook\LimitRefusal;
use Nowo\TaskBoardBundle\Sync\Hook\SyncLimitCheckerInterface;
use Nowo\TaskBoardBundle\Sync\Status\StatusColumns;

use function array_map;
use function array_search;
use function array_values;
use function in_array;
use function max;

/**
 * Since 2.18.0 (Beacon spec 234, FR-034 and block W): makes a board's columns exactly a list of {@see BoardColumnSpec}, in
 * memory, never orphaning a task. The caller flushes inside its own transaction.
 *
 * Each current column is matched to a spec by the column map (its id, then its name => a spec name), else by name
 * (without case, accents or spaces). A matched column is kept (same id, so its tasks stay) and takes the spec's name,
 * colour, icon, type and order; a spec no column matches is created. A current column that matches no spec is removed
 * when it is empty; when it holds tasks the whole apply is refused ({@see BoardColumnsRefusedException}) — unless the map
 * sends it to a spec, whose column then takes its tasks. Tasks follow the type of the column they end in (done or not);
 * reopening asks the host's limit first. Stateless.
 */
final readonly class BoardColumnsApplier
{
    public function __construct(
        private ?SyncLimitCheckerInterface $limits = null,
        private ?EntityManagerInterface $entityManager = null,
    ) {
    }

    /**
     * Every refusal {@see self::apply()} can make, without changing anything: run it on every board of a multi-board change
     * first, so a refusal on the last board leaves the first ones untouched in memory too.
     *
     * @param list<BoardColumnSpec> $specs
     * @param array<string, string> $columnMap
     *
     * @throws BoardColumnsRefusedException
     * @throws InvalidArgumentException
     * @throws TaskReopenRefusedException
     *
     * @return array<string, int|null> current column id => spec index (null: removed)
     */
    public function check(TaskBoard $board, array $specs, array $columnMap = []): array
    {
        $this->validate($specs);
        $names = array_map(static fn (BoardColumnSpec $spec): string => StatusColumns::normalize($spec->name), $specs);
        $map   = [];
        foreach ($columnMap as $from => $to) {
            $index = array_search(StatusColumns::normalize($to), $names, true);
            if ($index === false) {
                throw new InvalidArgumentException('The column map names a column the configuration does not have.');
            }
            $map[(string) $from] = $index;
        }

        // Current column => spec index, and the columns that hold tasks with nowhere to go.
        $current  = ColumnTypes::ordered($board);
        $target   = [];
        $orphaned = [];
        foreach ($current as $column) {
            $index = $map[$column->getId()] ?? $map[$column->getName()] ?? null;
            if ($index === null) {
                $found = array_search(StatusColumns::normalize($column->getName()), $names, true);
                $index = $found === false ? null : $found;
            }
            $target[$column->getId()] = $index;
            if ($index === null && $this->liveTasks($column) !== []) {
                $orphaned[] = $column->getName();
            }
        }
        if ($orphaned !== []) {
            throw new BoardColumnsRefusedException($board->getId(), $orphaned);
        }

        // The reopen check first, from the specs' types: nothing is changed in memory before every refusal is known.
        $reopen = 0;
        foreach ($current as $column) {
            $index = $target[$column->getId()];
            $done  = $index !== null && $specs[$index]->type->isDone();
            foreach ($this->liveTasks($column) as $task) {
                $reopen += (int) ($task->isCompleted() && !$done);
            }
        }
        $refusal = $reopen > 0 ? $this->limits?->check($board, $reopen, 0) : null;
        if ($refusal instanceof LimitRefusal) {
            throw new TaskReopenRefusedException($refusal);
        }

        return $target;
    }

    /**
     * @param list<BoardColumnSpec> $specs
     * @param array<string, string> $columnMap current column id or name => spec name
     *
     * @throws BoardColumnsRefusedException
     * @throws InvalidArgumentException duplicate spec names, no spec, more than two closed columns, a map to no spec
     * @throws TaskReopenRefusedException
     *
     * @return list<BoardColumn> the columns removed (for the caller to remove from its entity manager)
     */
    public function apply(TaskBoard $board, array $specs, array $columnMap = []): array
    {
        $target  = $this->check($board, $specs, $columnMap);
        $current = ColumnTypes::ordered($board);

        // The column kept for each spec: the first current column matched to it.
        $kept = [];
        foreach ($current as $column) {
            $index = $target[$column->getId()];
            if ($index !== null && !isset($kept[$index])) {
                $kept[$index] = $column;
            }
        }
        $columns = [];
        foreach ($specs as $index => $spec) {
            $column = $kept[$index] ?? new BoardColumn($board, $spec->name, $index);
            // @igor-ignore - A BoardColumn entity of the board being written (instance-scoped), not a shared service.
            $column->setName($spec->name)->setPosition($index)->setColor($spec->color)->setIcon(ColumnIcon::normalize($spec->icon))->setType($spec->type)->setHumansOnly($spec->humansOnly);
            if (!isset($kept[$index])) {
                $board->addColumn($column);
            }
            $columns[$index] = $column;
        }

        // Tasks of a merged column go to the kept one; then every task follows the done-ness of its column.
        $moves   = [];
        $removed = [];
        foreach ($current as $column) {
            $index = $target[$column->getId()];
            $to    = $index === null ? null : $columns[$index];
            foreach ($this->liveTasks($column) as $task) {
                $destination = $to ?? $column;
                $moves[]     = [$task, $destination, $destination->getStoredType()?->isDone() ?? false];
            }
            if ($to !== $column && !in_array($column, $columns, true)) {
                $removed[] = $column;
            }
        }
        $positions = [];
        foreach ($moves as [$task, $destination, $done]) {
            if ($task->getColumn() !== $destination) {
                $key = $destination->getId();
                $positions[$key] ??= $this->nextPosition($destination);
                $task->setColumn($destination)->setPosition($positions[$key]++);
            }
            if ($done && !$task->isCompleted()) {
                $task->markCompleted();
            } elseif (!$done && $task->isCompleted()) {
                $task->markIncomplete();
            }
        }
        foreach ($removed as $column) {
            foreach (ColumnTasks::of($this->entityManager, $column) as $task) {
                // What is still there (archived tasks) goes with the first column, never orphaned.
                if ($task->getColumn() === $column) {
                    $task->setColumn($columns[0]);
                }
            }
            $board->removeColumn($column);
        }

        return $removed;
    }

    /**
     * @param list<BoardColumnSpec> $specs
     *
     * @throws InvalidArgumentException
     */
    public function validate(array $specs): void
    {
        if ($specs === [] || array_values($specs) !== $specs) {
            throw new InvalidArgumentException('A board keeps at least one column.');
        }
        $seen = [];
        foreach ($specs as $spec) {
            $key = StatusColumns::normalize($spec->name);
            if (isset($seen[$key])) {
                throw new InvalidArgumentException('Two columns have the same name.');
            }
            $seen[$key] = true;
        }
        if (!ColumnTypes::allowed(array_map(static fn (BoardColumnSpec $spec): ColumnType => $spec->type, $specs))) {
            throw new InvalidArgumentException('A board has at most ' . ColumnType::MAX_CLOSED . ' closed columns.');
        }
    }

    /**
     * The columns of a board as specs (their effective types).
     *
     * @return list<BoardColumnSpec>
     */
    public static function specsOf(TaskBoard $board): array
    {
        $columns = ColumnTypes::ordered($board);

        return array_map(static fn (BoardColumn $column): BoardColumnSpec => new BoardColumnSpec($column->getName(), ColumnTypes::of($column, $columns), $column->getColor(), $column->getIcon(), $column->isHumansOnly()), $columns);
    }

    /**
     * @return list<Task>
     */
    private function liveTasks(BoardColumn $column): array
    {
        $tasks = [];
        foreach (ColumnTasks::of($this->entityManager, $column) as $task) {
            if (!$task->isArchived()) {
                $tasks[] = $task;
            }
        }

        return $tasks;
    }

    private function nextPosition(BoardColumn $column): int
    {
        $position = -1;
        foreach (ColumnTasks::of($this->entityManager, $column) as $task) {
            $position = max($position, $task->getPosition());
        }

        return $position + 1;
    }
}
