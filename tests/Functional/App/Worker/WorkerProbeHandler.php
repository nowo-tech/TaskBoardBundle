<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\App\Worker;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Avatar\TaskMemberAvatars;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Recurrence\TaskRecurrenceView;
use Nowo\TaskBoardBundle\Service\TaskEventDeferral;
use Nowo\TaskBoardBundle\Sync\View\SyncPeopleViewProvider;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use RuntimeException;
use Symfony\Component\EventDispatcher\GenericEvent;

/**
 * Since 2.11.1 (REQ-RUNTIME-002): the handler of {@see WorkerProbeMessage} in the functional kernel. It uses the
 * bundle's request memos as a page would (avatar URL, repeat icons, synced details of the user's tasks) and sends a
 * task event through {@see TaskEventDeferral}; a failing message opens a deferral and dies before its rollback, as a
 * write that crashed half-way would. What it saw is kept in the public statics for the test.
 */
final class WorkerProbeHandler
{
    public const EVENT = 'app.worker_probe';

    /** @var list<array{email: string, avatar: string|null, delivered: bool}> */
    public static array $handled = [];

    private bool $delivered = false;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TaskMemberAvatars $avatars,
        private readonly TaskEventDeferral $events,
        private readonly TaskRecurrenceView $recurrence,
        private readonly SyncPeopleViewProvider $syncPeople,
    ) {
    }

    public function __invoke(WorkerProbeMessage $message): void
    {
        $user = $this->entityManager->getRepository(AppUser::class)->findOneBy(['email' => $message->email]);
        if (!$user instanceof AppUser) {
            throw new RuntimeException('Unknown probe user.');
        }
        foreach ($this->entityManager->getRepository(Task::class)->findBy(['board' => $message->boardId]) as $task) {
            $this->recurrence->repeats($task);
            $this->syncPeople->detailsOf($task);
        }

        $this->delivered = false;
        $this->events->dispatch(new GenericEvent($message), self::EVENT);
        self::$handled[] = ['email' => $message->email, 'avatar' => $this->avatars->urlOf($user), 'delivered' => $this->delivered];

        if ($message->fail) {
            // A write that opened its deferral and died before rollBack() (e.g. a fatal path that skipped the catch).
            $this->events->begin();

            throw new RuntimeException('Probe failure.');
        }
    }

    public function onProbe(): void
    {
        $this->delivered = true;
    }
}
