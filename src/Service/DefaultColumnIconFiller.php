<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Service;

use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Repository\BoardColumnRepositoryInterface;
use Nowo\TaskBoardBundle\Support\ColumnIcon;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function array_column;
use function is_string;

/**
 * Since 2.9.0: gives the columns of an existing board that have no icon the icon of their `board.default_columns` entry. Fill
 * only: a column that has an icon keeps it, so running it again changes nothing. A column carries no key of the default entry
 * it was created from, so it is matched by its exact name (case and spaces included); a renamed column is left alone.
 * Only the board's own columns are read and written: the board decides the tenant.
 */
final readonly class DefaultColumnIconFiller
{
    /** @var array<string, string> name => icon, the first entry of a name winning */
    private array $icons;

    /**
     * @param list<array{name: string, color?: ?string, icon?: ?string}> $defaultColumns `board.default_columns`
     */
    public function __construct(
        private BoardColumnRepositoryInterface $columnRepository,
        array $defaultColumns = TaskBoardCreator::DEFAULT_COLUMNS,
        private LoggerInterface $logger = new NullLogger(),
    ) {
        $icons = [];
        foreach ($defaultColumns as $column) {
            $icon = $column['icon'] ?? null;
            if (is_string($icon) && ColumnIcon::isStorable($icon) && !isset($icons[$column['name']])) {
                $icons[$column['name']] = $icon;
            }
        }
        $this->icons = $icons;
    }

    /** Whether any default column has an icon to give. */
    public function hasIcons(): bool
    {
        return $this->icons !== [];
    }

    /**
     * The columns of the board without an icon whose name is a default column's with an icon, given that icon (saved unless
     * `$dryRun`).
     *
     * @return list<array{column: BoardColumn, icon: string}>
     */
    public function fill(TaskBoard $board, bool $dryRun = false): array
    {
        $filled = [];
        foreach ($board->getColumns() as $column) {
            $current = $column->getIcon();
            $icon    = $this->icons[$column->getName()] ?? null;
            if (($current !== null && $current !== '') || $icon === null) {
                continue;
            }
            $filled[] = ['column' => $column, 'icon' => $icon];
        }
        if ($dryRun || $filled === []) {
            return $filled;
        }

        foreach ($filled as $change) {
            $change['column']->setIcon($change['icon']);
        }
        $this->columnRepository->saveAll(array_column($filled, 'column'));
        foreach ($filled as $change) {
            // REQ-OBS-001: the same write line as a column edited in the dialog.
            $this->logger->info('TaskBoard write: {action}', [
                'bundle'    => 'nowo_task_board',
                'action'    => 'column_update',
                'board_id'  => $board->getId(),
                'column_id' => $change['column']->getId(),
                'cause'     => 'default_column_icons',
            ]);
        }

        return $filled;
    }
}
