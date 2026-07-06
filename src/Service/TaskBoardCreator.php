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
    /** @var list{array{name: string, color: ?string}} */
    private const DEFAULT_COLUMNS = [
        ['name' => 'To do', 'color' => '#94a3b8'],
        ['name' => 'In progress', 'color' => '#3b82f6'],
        ['name' => 'Done', 'color' => '#22c55e'],
    ];

    public function __construct(
        private TaskBoardRepositoryInterface $boardRepository,
    ) {
    }

    public function create(TaskBoardFormData $data, object $creator): TaskBoard
    {
        $slug = trim($data->slug) !== '' ? trim($data->slug) : SlugGenerator::fromName($data->name);

        $board = new TaskBoard(
            name: $data->name,
            slug: $slug,
            creator: $creator,
            description: $data->description,
        );

        foreach (self::DEFAULT_COLUMNS as $position => $columnDef) {
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
}
