<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Event\BoardAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Import\Dto\ImportedTaskDto;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncItem;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncPerson;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteChecklist;
use Nowo\TaskBoardBundle\Sync\Provider\RemotePerson;
use Nowo\TaskBoardBundle\Sync\Provider\RemotePersonRef;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteRole;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteStatus;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteTask;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use Nowo\TaskBoardBundle\Tests\Functional\App\RecordingTimeSink;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use function array_replace_recursive;
use function array_values;
use function in_array;

/**
 * Since 1.25: the sync kernel with the people directory (verified e-mail matches, the default resolver), the
 * recording time sink, and helpers for remote people, statuses and checklists.
 */
abstract class SyncPeopleFunctionalTestCase extends SyncFunctionalTestCase
{
    public const WORKSPACE = '9000001';

    /** @var list<string> e-mails of users the BOARD_ACCESS_CHECK listener lets view every board */
    protected array $viewers = [];

    protected function setUp(): void
    {
        RecordingTimeSink::reset();
        parent::setUp();
        $this->viewers = [];
        $viewers       = &$this->viewers;
        $this->dispatcher()->addListener(TaskBoardEvents::BOARD_ACCESS_CHECK, static function (BoardAccessCheckEvent $event) use (&$viewers): void {
            $subject = $event->getSubject();
            if ($event->getAction() === BoardAccessCheckEvent::ACTION_VIEW && $subject instanceof AppUser && in_array($subject->getEmail(), $viewers, true)) {
                $event->grant();
            }
        });
    }

    protected static function syncConfig(): array
    {
        return array_replace_recursive(parent::syncConfig(), ['sync' => [
            'people'       => ['require_verified_email' => false],
            'time_entries' => ['sink' => 'app.time_sink', 'window_days' => 30, 'max_assignees_per_call' => 2, 'max_entries_per_window' => 10, 'max_windows_per_run' => 5, 'fallback_max_tasks_per_run' => 2],
        ]]);
    }

    protected function dispatcher(): EventDispatcherInterface
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return $dispatcher;
    }

    /** A user who may view every board (through BOARD_ACCESS_CHECK). */
    protected function viewer(string $email): AppUser
    {
        $this->viewers[] = $email;

        return $this->user($email);
    }

    /**
     * @param list<RemotePerson> $people
     */
    protected function workspace(array $people = []): void
    {
        $this->provider->withWorkspace(self::WORKSPACE, $people);
    }

    protected static function ref(string $id, ?string $email = null, ?string $name = null): RemotePersonRef
    {
        return new RemotePersonRef($id, $name ?? 'User ' . $id, $email);
    }

    protected static function member(string $id, ?string $email = null, ?RemoteRole $role = RemoteRole::Member): RemotePerson
    {
        return new RemotePerson($id, 'User ' . $id, $email, $role);
    }

    /**
     * @param list<RemotePersonRef> $assignees
     * @param list<RemotePersonRef> $watchers
     * @param list<RemoteChecklist> $checklists
     */
    protected function row(
        string $id,
        string $title,
        int $updated = 1,
        ?string $status = 'To do',
        ?RemotePersonRef $creator = null,
        array $assignees = [],
        ?DateTimeImmutable $createdAt = null,
        ?array $watchers = null,
        ?array $checklists = null,
        ?RemoteStatus $remoteStatus = null,
        ?string $parent = null,
        ?string $listName = null,
        bool $archived = false,
    ): RemoteTask {
        return new RemoteTask(
            task: new ImportedTaskDto(
                title: $title,
                externalId: $id,
                parentExternalId: $parent,
                status: $status,
                priority: TaskPriority::Normal,
                sourceUrl: 'https://app.clickup.com/t/' . $id,
                createdAt: $createdAt,
                hasPriority: false,
                creator: $creator,
                assigneeRefs: $assignees,
            ),
            remoteUpdatedAt: new DateTimeImmutable('@' . (1_790_000_000 + $updated)),
            archived: $archived,
            watchers: $watchers ?? [],
            watchersKnown: $watchers !== null,
            checklists: $checklists ?? [],
            checklistsKnown: $checklists !== null,
            status: $remoteStatus,
            listName: $listName,
        );
    }

    protected function itemOf(string $externalId): TaskSyncItem
    {
        $item = $this->em->getRepository(TaskSyncItem::class)->findOneBy(['externalId' => $externalId]);
        self::assertInstanceOf(TaskSyncItem::class, $item, $externalId);

        return $item;
    }

    protected function personOf(string $providerUserId): TaskSyncPerson
    {
        $person = $this->em->getRepository(TaskSyncPerson::class)->findOneBy(['providerUserId' => $providerUserId]);
        self::assertInstanceOf(TaskSyncPerson::class, $person, $providerUserId);

        return $person;
    }

    /** @param class-string $class */
    protected function rows(string $class): int
    {
        return $this->em->getRepository($class)->count([]);
    }

    protected function boardWithColumns(string ...$columns): TaskBoard
    {
        return $this->board(columns: $columns === [] ? ['To do', 'Doing', 'Done'] : array_values($columns));
    }
}
