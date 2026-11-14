<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\ValueObject;

use Nowo\TaskBoardBundle\Transition\ColumnTransitionPolicyInterface;

/**
 * Since 2.16.0 (spec 234, FR-014): a task about to enter a column, as {@see ColumnTransitionPolicyInterface}
 * sees it. Copied before anything changes; it holds no entity.
 *
 * `$task` is null when the task is being created in `$to` (FR-017). `$tags` are the task's tags as they will be (the new
 * task's, or the item's of a bulk update that also sets them). `$actor` is the host's user object that asks (the
 * `user_class` instance the bundle already passes to every write), `$actorId` its `getId()` as a string.
 */
final readonly class ColumnTransition
{
    public function __construct(
        public TaskBoardSnapshot $board,
        public ?TaskSnapshot $task,
        public ?BoardColumnSnapshot $from,
        public BoardColumnSnapshot $to,
        public object $actor,
        public ?string $actorId,
        public ColumnTransitionVia $via,
        /** @var list<string> */
        public array $tags = [],
    ) {
    }

    public function isCreation(): bool
    {
        return !$this->task instanceof TaskSnapshot;
    }

    public function channel(): string
    {
        return $this->via->channel;
    }
}
