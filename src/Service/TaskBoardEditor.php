<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Service;

use Nowo\TaskBoardBundle\Doctrine\TaskBoardEntityManager;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Event\BoardUpdatedEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Repository\TaskBoardRepositoryInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function array_filter;
use function array_keys;

/**
 * Since 2.2.0 (spec 213): renames a board and edits its description, with {@see TaskBoardEvents::BOARD_UPDATED} when
 * something changed. The slug stays, as links to the board may hold it. The caller checks access and validates.
 */
final readonly class TaskBoardEditor implements TaskBoardEditorInterface
{
    public function __construct(
        private TaskBoardRepositoryInterface $boardRepository,
        private ?EventDispatcherInterface $eventDispatcher = null,
        // Since 3.0.0 (audit ZAS-V1): TaskBoard rows the application's manager holds are read again by id at each write
        // entry (none: a service built by hand works on the rows it is given, as in 2.x).
        private ?TaskBoardEntityManager $rows = null,
    ) {
    }

    public function update(TaskBoard $board, string $name, ?string $description, object $actor): void
    {
        $board   = $this->own($board);
        $changed = array_keys(array_filter([
            'name'        => $board->getName() !== $name,
            'description' => $board->getDescription() !== $description,
        ]));
        if ($changed === []) {
            return;
        }

        // @igor-ignore - Request data: the row the caller passed, or TaskBoard's own copy of it (own(), audit ZAS-V1); not service state.
        $board->setName($name)->setDescription($description);
        $this->boardRepository->save($board);
        $this->eventDispatcher?->dispatch(new BoardUpdatedEvent($board, $actor, $changed), TaskBoardEvents::BOARD_UPDATED);
    }

    /**
     * @template T of object
     *
     * @param T $row
     *
     * @return T
     */
    private function own(object $row): object
    {
        return $this->rows instanceof TaskBoardEntityManager ? $this->rows->attach($row) : $row;
    }
}
