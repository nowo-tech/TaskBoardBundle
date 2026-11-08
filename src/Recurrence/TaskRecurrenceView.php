<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Recurrence;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Recurrence\Entity\TaskRecurrence;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Since 2.3.0 (spec 215, `recurrence.enabled`): what the pages show of a series. The task panel's *Repeat* section costs
 * one query; the repeat icon of the board and list views one query per board, kept for the request (reset between the
 * requests of a worker).
 */
final class TaskRecurrenceView implements ResetInterface
{
    /** @var array<string, array<string, true>> board id => ids of its recurring tasks */
    private array $recurring = [];

    public function __construct(
        private readonly TaskRecurrenceManager $manager,
        private readonly TaskBoardResourceAccessChecker $access,
        private readonly ?TokenStorageInterface $tokenStorage = null,
    ) {
    }

    /**
     * The *Repeat* section of `$task`: its series (or null), the next three dates, and whether the viewer may change it.
     *
     * @return array{series: TaskRecurrence|null, upcoming: list<DateTimeImmutable>, canEdit: bool, synced: bool}
     */
    public function of(Task $task): array
    {
        $series  = $this->manager->seriesOf($task);
        $user    = $this->tokenStorage?->getToken()?->getUser();
        $canEdit = $user instanceof UserInterface && $this->access->canEditTask($user, $task);

        return [
            'series'   => $series,
            'upcoming' => $series instanceof TaskRecurrence && $series->isActive() ? $this->manager->upcoming($series) : [],
            'canEdit'  => $canEdit,
            // Asked only when it decides something: an editor looking at a task with no series.
            'synced' => !$series instanceof TaskRecurrence && $canEdit && $this->manager->isSynced($task),
        ];
    }

    public function repeats(Task $task): bool
    {
        $board = $task->getBoard()->getId();
        // @igor-ignore - Per-request cache, cleared by reset() between the requests of a worker.
        $this->recurring[$board] ??= $this->manager->recurringTaskIds([$board]);

        return isset($this->recurring[$board][$task->getId()]);
    }

    public function reset(): void
    {
        // @igor-ignore - Per-request cache, cleared here.
        $this->recurring = [];
    }
}
