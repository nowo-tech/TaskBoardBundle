<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Folder;

use Nowo\TaskBoardBundle\Event\AbstractColumnEvent;
use Nowo\TaskBoardBundle\Event\ColumnDeletedEvent;
use Nowo\TaskBoardBundle\Event\ColumnUpdatedEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Since 2.19.0 (FR-034): carries a column edit of a board in a sharing folder to the set and the other boards, after the
 * edit is committed (the column events are dispatched after the commit). A rename moves the siblings' tasks with it; a
 * deleted column's tasks go where the edited board sent its own. Stateless.
 */
final readonly class FolderStatusSetListener implements EventSubscriberInterface
{
    public function __construct(
        private FolderStatusSets $statusSets,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            TaskBoardEvents::COLUMN_CREATED    => 'onColumnChange',
            TaskBoardEvents::COLUMN_UPDATED    => 'onColumnChange',
            TaskBoardEvents::COLUMN_DELETED    => 'onColumnChange',
            TaskBoardEvents::COLUMNS_REORDERED => 'onColumnChange',
        ];
    }

    public function onColumnChange(AbstractColumnEvent $event): void
    {
        $renames = [];
        if ($event instanceof ColumnUpdatedEvent && $event->previousName !== $event->name) {
            $renames[$event->previousName] = $event->name;
        }
        // Since 2.19.0 (audit PW-H1): the actor's rights decide whether the edit is carried to the other boards.
        $this->statusSets->propagateFrom($event->boardId, $renames, $event instanceof ColumnDeletedEvent ? [$event->name => $event->targetColumnId] : [], $event->actor);
    }
}
