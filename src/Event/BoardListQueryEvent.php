<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Event;

use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched before loading boards for the current user.
 */
final class BoardListQueryEvent extends Event
{
    /** @var list<TaskBoard>|null */
    private ?array $overrideList = null;

    public function __construct(
        private readonly object $subject,
    ) {
    }

    public function getSubject(): object
    {
        return $this->subject;
    }

    /** @param list<TaskBoard> $boards */
    public function overrideList(array $boards): void
    {
        $this->overrideList = $boards;
    }

    /** @return list<TaskBoard>|null */
    public function getOverrideList(): ?array
    {
        return $this->overrideList;
    }
}
