<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\People;

/**
 * Since 1.25: what a re-association (or re-projection, or detach) changed so far. `cursor` null = finished; otherwise
 * call again with it.
 */
final readonly class ReassignReport
{
    public function __construct(
        public int $tasks = 0,
        public int $creators = 0,
        public int $comments = 0,
        public int $membersRemoved = 0,
        public int $membersAdded = 0,
        public int $notProjected = 0,
        public int $busyBoards = 0,
        public ?ReassignCursor $cursor = null,
        /** Since 1.25 (audit FP-L7): boards whose creator `detachUser()` detached. */
        public int $boards = 0,
        /** Since 1.25 (audit FP-L7): task documents whose creator `detachUser()` detached. */
        public int $documents = 0,
        /** Since 1.27.0: manual time entries `detachUser()` detached (their time stays). */
        public int $timeEntries = 0,
    ) {
    }

    /** Whether there is nothing left to do. */
    public function isDone(): bool
    {
        return !$this->cursor instanceof ReassignCursor;
    }

    /** Counts added up, with the other report's cursor. */
    public function plus(self $other): self
    {
        return new self(
            $this->tasks + $other->tasks,
            $this->creators + $other->creators,
            $this->comments + $other->comments,
            $this->membersRemoved + $other->membersRemoved,
            $this->membersAdded + $other->membersAdded,
            $this->notProjected + $other->notProjected,
            $this->busyBoards + $other->busyBoards,
            $other->cursor,
            $this->boards + $other->boards,
            $this->documents + $other->documents,
            $this->timeEntries + $other->timeEntries,
        );
    }

    /** The same counts with another cursor. */
    public function withCursor(?ReassignCursor $cursor): self
    {
        return new self($this->tasks, $this->creators, $this->comments, $this->membersRemoved, $this->membersAdded, $this->notProjected, $this->busyBoards, $cursor, $this->boards, $this->documents, $this->timeEntries);
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return ['tasks' => $this->tasks, 'creators' => $this->creators, 'comments' => $this->comments, 'membersRemoved' => $this->membersRemoved, 'membersAdded' => $this->membersAdded, 'notProjected' => $this->notProjected, 'busyBoards' => $this->busyBoards, 'boards' => $this->boards, 'documents' => $this->documents, 'timeEntries' => $this->timeEntries];
    }
}
