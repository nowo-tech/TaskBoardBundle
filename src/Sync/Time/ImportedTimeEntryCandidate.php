<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Time;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncPerson;
use Nowo\TaskBoardBundle\Sync\Hook\OwnerlessTimeEntrySinkInterface;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteTimeEntry;

/**
 * Since 1.25: one tracked-time entry handed to the host's sink — the remote entry, its person in the directory (with
 * the mapping) and the local task when the remote task is linked on the run's board (null otherwise).
 *
 * Since 2.15.0 (REQ-ARCH-001 rule 1) read it through the id accessors ({@see self::personId()},
 * {@see self::personProviderUserId()}, {@see self::taskId()}, {@see self::boardId()}, {@see self::user()},
 * {@see self::personName()}); `$person` and `$task` (entities) are deprecated, removed in 3.0.
 */
final readonly class ImportedTimeEntryCandidate
{
    public function __construct(
        public RemoteTimeEntry $entry,
        /** @deprecated since 2.15.0, use {@see self::personId()} and the other accessors; removed in 3.0 */
        public TaskSyncPerson $person,
        /** @deprecated since 2.15.0, use {@see self::taskId()} / {@see self::boardId()}; removed in 3.0 */
        public ?Task $task,
    ) {
    }

    /**
     * Since 1.27.0 (TB-SYNC-3): the host user the entry belongs to — the person's mapped user (People page or verified
     * e-mail match), else null. Since 1.28.0 null means an ownerless entry: only a
     * {@see OwnerlessTimeEntrySinkInterface} receives those, and {@see self::$person} says whose hours they are. A plain
     * sink gets mapped people only, as before, so there null means the mapping changed since.
     */
    public function user(): ?object
    {
        return $this->person->getMapping()->isMapped() ? $this->person->getMappedUser() : null;
    }

    /** Since 1.28.0: no host user stands for the person (a former colleague, or nobody mapped them yet). */
    public function isOwnerless(): bool
    {
        return $this->user() === null;
    }

    /** Since 1.28.0: the name to show for an ownerless entry; null once the person is anonymised. */
    public function personName(): ?string
    {
        return $this->person->isAnonymised() ? null : $this->person->getName();
    }

    /** Since 2.15.0: the id of the person in the bundle's people directory. */
    public function personId(): string
    {
        return $this->person->getId();
    }

    /** Since 2.15.0: the provider's id of the person. */
    public function personProviderUserId(): string
    {
        return $this->person->getProviderUserId();
    }

    /** Since 2.15.0: the id of the local task, null when the remote task is not linked on the run's board. */
    public function taskId(): ?string
    {
        return $this->task?->getId();
    }

    /** Since 2.15.0: the board of the local task, null without a local task. */
    public function boardId(): ?string
    {
        return $this->task?->getBoard()->getId();
    }
}
