<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Event;

use Nowo\TaskBoardBundle\ValueObject\BoardColumnSnapshot;
use Nowo\TaskBoardBundle\ValueObject\ColumnTransitionVia;
use Nowo\TaskBoardBundle\ValueObject\TaskSnapshot;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Since 2.16.0 (spec 234): {@see TaskBoardEvents::TASK_STATUS_CHANGED} — a task entered another column of its board
 * (board drag, previous / next / done, the form, the Task API, a bulk update), after the write is saved (after the commit
 * of a write that holds its events). Framework-free: snapshots and ids only, copied when the event is built.
 *
 * `$toType` is the new column's type (`open`, `custom`, `done`, `closed`; also `$to->type`). It was always null in
 * 2.16 and 2.17; since 2.18.0 (Beacon spec 234, FR-033) the bundle fills it. A task in a `done` or `closed` column is
 * done; on a board none of whose columns carries a type the last column is `closed`. A listener built by other code
 * may still see null and must treat it as "unknown".
 */
final class TaskStatusChangedEvent extends Event
{
    public function __construct(
        public readonly TaskSnapshot $task,
        public readonly ?BoardColumnSnapshot $from,
        public readonly BoardColumnSnapshot $to,
        public readonly ?string $actorId,
        public readonly ColumnTransitionVia $via,
        public readonly ?string $toType = null,
    ) {
    }
}
