<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Service;

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
final readonly class TaskBoardEditor
{
    public function __construct(
        private TaskBoardRepositoryInterface $boardRepository,
        private ?EventDispatcherInterface $eventDispatcher = null,
    ) {
    }

    public function update(TaskBoard $board, string $name, ?string $description, object $actor): void
    {
        $changed = array_keys(array_filter([
            'name'        => $board->getName() !== $name,
            'description' => $board->getDescription() !== $description,
        ]));
        if ($changed === []) {
            return;
        }

        $board->setName($name)->setDescription($description);
        $this->boardRepository->save($board);
        $this->eventDispatcher?->dispatch(new BoardUpdatedEvent($board, $actor, $changed), TaskBoardEvents::BOARD_UPDATED);
    }
}
