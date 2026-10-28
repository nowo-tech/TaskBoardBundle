<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Service;

use Nowo\TaskBoardBundle\Dto\TaskBoardFormData;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Repository\TaskBoardRepositoryInterface;
use Nowo\TaskBoardBundle\Support\SlugGenerator;

/**
 * Creates task boards with default kanban columns.
 */
final readonly class TaskBoardCreator
{
    /** @var list<array{name: string, color: ?string}> */
    public const DEFAULT_COLUMNS = [
        ['name' => 'To do', 'color' => '#94a3b8'],
        ['name' => 'In progress', 'color' => '#3b82f6'],
        ['name' => 'Done', 'color' => '#22c55e'],
    ];

    /**
     * @param list<array{name: string, color: ?string}> $defaultColumns `board.default_columns` (since 1.10)
     */
    public function __construct(
        private TaskBoardRepositoryInterface $boardRepository,
        private array $defaultColumns = self::DEFAULT_COLUMNS,
    ) {
    }

    /**
     * Creates a board with the default columns; `$tenantKey` puts it in a host tenant (`tenancy`).
     */
    public function create(TaskBoardFormData $data, object $creator, ?string $tenantKey = null): TaskBoard
    {
        $slug = $this->uniqueSlug(trim($data->slug) !== '' ? trim($data->slug) : SlugGenerator::fromName($data->name));

        $board = new TaskBoard(
            name: $data->name,
            slug: $slug,
            creator: $creator,
            description: $data->description,
        );
        $board->setTenantKey($tenantKey);

        foreach ($this->defaultColumns as $position => $columnDef) {
            $board->addColumn(new BoardColumn(
                board: $board,
                name: $columnDef['name'],
                position: $position,
                color: $columnDef['color'],
            ));
        }

        $this->boardRepository->save($board);

        return $board;
    }

    /**
     * Appends a numeric suffix when the slug is taken, so the unique index never makes the flush fail.
     */
    private function uniqueSlug(string $slug): string
    {
        $candidate = $slug;
        $suffix    = 2;

        while ($this->boardRepository->findBySlug($candidate) instanceof TaskBoard) {
            $candidate = $slug . '-' . $suffix++;
        }

        return $candidate;
    }
}
