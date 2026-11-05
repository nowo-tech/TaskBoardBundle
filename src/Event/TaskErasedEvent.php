<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Event;

use Nowo\TaskBoardBundle\Service\TaskBoardEraser;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Since 1.25 ({@see TaskBoardEvents::TASK_ERASED}): {@see TaskBoardEraser} is about to
 * remove a task (the flush of its batch follows). The host removes what it keeps for the task (stored files, mentions,
 * search entries). **It must not delete tracked time**: the manual entries are detached and TimeTrack entries keep
 * their task id and snapshots. The title is given for the host's own clean-up; listeners must not log it.
 */
final class TaskErasedEvent extends Event
{
    /**
     * @param list<string> $attachmentRefs storage refs of the files a provider sync stored for the task (host keys)
     */
    public function __construct(
        public readonly string $taskId,
        public readonly string $boardId,
        public readonly string $title,
        public readonly array $attachmentRefs,
        public readonly int $manualTimeEntries,
    ) {
    }
}
