<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Service;

use Nowo\TaskBoardBundle\Dto\TaskBoardFormData;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\ColumnSet;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Event\BoardCreatedEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Repository\TaskBoardRepositoryInterface;
use Nowo\TaskBoardBundle\Repository\TenantBoardSlugLookupInterface;
use Nowo\TaskBoardBundle\Support\SlugGenerator;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function mb_substr;
use function strlen;

/**
 * Creates task boards with default kanban columns.
 */
final readonly class TaskBoardCreator
{
    /** @var list<array{name: string, color: ?string, icon?: ?string}> */
    public const DEFAULT_COLUMNS = [
        ['name' => 'To do', 'color' => '#94a3b8'],
        ['name' => 'In progress', 'color' => '#3b82f6'],
        ['name' => 'Done', 'color' => '#22c55e'],
    ];

    /**
     * @param list<array{name: string, color: ?string, icon?: ?string}> $defaultColumns `board.default_columns` (since 1.10; `icon` since 1.11)
     */
    public function __construct(
        private TaskBoardRepositoryInterface $boardRepository,
        private array $defaultColumns = self::DEFAULT_COLUMNS,
        // Since 1.30.0 (spec 211).
        private ?EventDispatcherInterface $eventDispatcher = null,
        private bool $transliterateSlug = false,
        private ?string $descriptionFormat = null,
    ) {
    }

    /**
     * Creates a board with the default columns, or the columns of `$columnSet` (since 1.11) when it has any;
     * `$tenantKey` puts it in a host tenant (`tenancy`).
     */
    public function create(TaskBoardFormData $data, object $creator, ?string $tenantKey = null, ?ColumnSet $columnSet = null): TaskBoard
    {
        $generated = $this->transliterateSlug ? SlugGenerator::transliterated($data->name) : SlugGenerator::fromName($data->name);
        $slug      = $this->uniqueSlug(trim($data->slug) !== '' ? trim($data->slug) : $generated, $tenantKey);

        $board = new TaskBoard(
            name: $data->name,
            slug: $slug,
            creator: $creator,
            description: $data->description,
        );
        $board->setTenantKey($tenantKey);
        $board->setIcon($data->icon !== '' ? $data->icon : null);
        $board->setDescriptionFormat($this->descriptionFormat);

        $columns = $columnSet instanceof ColumnSet && $columnSet->getItems() !== [] ? $columnSet->getItems() : $this->defaultColumns;
        foreach ($columns as $position => $columnDef) {
            $board->addColumn(new BoardColumn(
                board: $board,
                name: $columnDef['name'],
                position: $position,
                color: $columnDef['color'],
                icon: $columnDef['icon'] ?? null,
            ));
        }

        $this->boardRepository->save($board);
        $this->eventDispatcher?->dispatch(new BoardCreatedEvent($board, $creator, $data->scope), TaskBoardEvents::BOARD_CREATED);

        return $board;
    }

    /**
     * Appends a numeric suffix when the slug is taken, so the unique index never makes the flush fail.
     */
    private function uniqueSlug(string $slug, ?string $tenantKey): string
    {
        $candidate = $slug;
        $suffix    = 2;

        while ($this->takenSlug($candidate, $tenantKey)) {
            // Audit HS-L2: the column holds 255 characters, so the suffix takes its room from the slug.
            $ending    = '-' . $suffix++;
            $candidate = mb_substr($slug, 0, 255 - strlen($ending)) . $ending;
        }

        return $candidate;
    }

    private function takenSlug(string $slug, ?string $tenantKey): bool
    {
        $board = $this->boardRepository instanceof TenantBoardSlugLookupInterface
            ? $this->boardRepository->findBySlugInTenant($slug, $tenantKey)
            : $this->boardRepository->findBySlug($slug);

        return $board instanceof TaskBoard;
    }
}
