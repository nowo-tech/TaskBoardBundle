<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Bridge\TimeTrack;

use InvalidArgumentException;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Menu\TaskTimerStart;
use Nowo\TaskBoardBundle\Menu\TaskTimerStarterInterface;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Exception\ActiveTimerConflictException;
use Nowo\TimeTrackBundle\Service\TimerService;
use RuntimeException;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Since 2.22.0: "Start timer" of the task menu through TimeTrack's {@see TimerService::start()} (the web client, as
 * TimeTrack's own timer page), which checks the task through this bundle's task provider and TimeTrack's period lock.
 * A running timer is never replaced: the user gets "already running" and stops it in TimeTrack first. Stateless.
 */
final readonly class TimeTrackTaskTimerStarter implements TaskTimerStarterInterface
{
    /**
     * @param object|null $timers TimeTrack's {@see TimerService} (`object`: that class is final); null when the host has the
     *                            package but not TimeTrackBundle's services
     */
    public function __construct(private ?object $timers = null)
    {
    }

    public function isAvailable(): bool
    {
        return $this->timers !== null;
    }

    public function start(object $user, Task $task): TaskTimerStart
    {
        if ($this->timers === null || !$user instanceof UserInterface) {
            return TaskTimerStart::Refused;
        }
        try {
            /** @var TimerService $timers */
            $timers = $this->timers;
            $timers->start($user, $task->getId(), ClientType::Web);
        } catch (ActiveTimerConflictException) {
            return TaskTimerStart::AlreadyRunning;
        } catch (InvalidArgumentException|RuntimeException) {
            // TaskNotTrackableException (an InvalidArgumentException), PeriodLockedException, PeriodSubmittedException…
            return TaskTimerStart::Refused;
        }

        return TaskTimerStart::Started;
    }
}
