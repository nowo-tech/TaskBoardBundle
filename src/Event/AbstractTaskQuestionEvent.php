<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Event;

use Nowo\TaskBoardBundle\Question\TaskQuestionSnapshot;
use Nowo\TaskBoardBundle\ValueObject\TaskBoardSnapshot;
use Nowo\TaskBoardBundle\ValueObject\TaskSnapshot;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Since 2.23.0 (Beacon spec 242): a question event — dispatched after the write is committed, framework-free (snapshots
 * and ids only, copied when the event is built; never an entity). The host sends its notifications, reminders and chat
 * notices from these; the bundle sends none.
 */
abstract class AbstractTaskQuestionEvent extends Event
{
    /**
     * @param string|null $actorId the signed-in user's id (`getId()`), null for an external answer or an unknown actor
     */
    public function __construct(
        public readonly TaskQuestionSnapshot $question,
        public readonly TaskSnapshot $task,
        public readonly TaskBoardSnapshot $board,
        public readonly ?string $actorId,
    ) {
    }

    public function getQuestionId(): string
    {
        return $this->question->id;
    }

    public function getTaskId(): string
    {
        return $this->task->id;
    }

    public function getBoardId(): string
    {
        return $this->board->id;
    }

    public function getTenantKey(): ?string
    {
        return $this->board->tenantKey;
    }
}
