<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Event\BoardAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Sync\Entity\TaskBoardConnection;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncComment;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncDirectory;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncItem;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncMember;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncPerson;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncPersonMapping;
use Nowo\TaskBoardBundle\Sync\Hook\SyncPersonMatch;
use Nowo\TaskBoardBundle\Sync\Hook\SyncPersonResolverInterface;
use Nowo\TaskBoardBundle\Sync\People\PeopleProjection;
use Nowo\TaskBoardBundle\Sync\People\SyncUser;
use Nowo\TaskBoardBundle\Sync\People\TaskSyncPeopleDirectoryService;
use Nowo\TaskBoardBundle\Sync\People\TaskSyncPeopleProjector;
use Nowo\TaskBoardBundle\Sync\People\TaskSyncPeopleReassigner;
use Nowo\TaskBoardBundle\Sync\People\TaskSyncPersonRepository;
use Nowo\TaskBoardBundle\Sync\Provider\RemotePerson;
use Nowo\TaskBoardBundle\Sync\Provider\RemotePersonRef;
use Nowo\TaskBoardBundle\Sync\Repository\SyncStore;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use function in_array;

/**
 * Since 1.25: people fixtures written straight into the sync tables (no provider run) and the re-association services
 * built around a scriptable resolver, so a test decides who views which board and who is a candidate.
 */
abstract class SyncReassignTestCase extends SyncFunctionalTestCase
{
    /** @var array<string, list<string>> board id => e-mails that may view it (besides its creator) */
    protected array $viewers = [];

    /** @var list<string> e-mails that are not candidates of any board */
    protected array $nonCandidates = [];

    /** @var list<object> events dispatched by the people services */
    protected array $events = [];

    protected EventDispatcherInterface $dispatcher;

    private int $directories = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $dispatcher = static::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $this->dispatcher = $dispatcher;
        $this->dispatcher->addListener(TaskBoardEvents::BOARD_ACCESS_CHECK, function (BoardAccessCheckEvent $event): void {
            $subject = $event->getSubject();
            if ($event->getAction() === BoardAccessCheckEvent::ACTION_VIEW && $subject instanceof AppUser && in_array($subject->getEmail(), $this->viewers[$event->getBoard()->getId()] ?? [], true)) {
                $event->grant();
            }
        });
        foreach ([TaskBoardEvents::SYNC_PERSON_REASSIGNED, TaskBoardEvents::SYNC_PEOPLE_REPROJECTED, TaskBoardEvents::SYNC_PERSON_MAPPED, TaskBoardEvents::SYNC_PERSON_ANONYMISED] as $name) {
            $this->dispatcher->addListener($name, function (object $event): void {
                $this->events[] = $event;
            });
        }
    }

    protected function resolver(): SyncPersonResolverInterface
    {
        $test = $this;

        return new class($test, $this->em) implements SyncPersonResolverInterface {
            public function __construct(private readonly SyncReassignTestCase $test, private readonly EntityManagerInterface $em)
            {
            }

            public function directoryScope(TaskBoard $board): string
            {
                return 'project:test';
            }

            public function candidates(TaskBoard $board, string $query = '', int $limit = 20): iterable
            {
                return [];
            }

            public function isCandidate(TaskBoard $board, object $user): bool
            {
                return !$user instanceof AppUser || !in_array($user->getEmail(), $this->test->nonCandidateEmails(), true);
            }

            public function autoMatch(TaskBoard $board, RemotePerson|RemotePersonRef $person, ?string $normalizedEmail = null): ?SyncPersonMatch
            {
                return null;
            }

            public function candidateById(TaskBoard $board, string $userId): ?object
            {
                $user = $this->em->find(AppUser::class, (int) $userId);

                return $user !== null && $this->isCandidate($board, $user) ? $user : null;
            }
        };
    }

    /** @return list<string> */
    public function nonCandidateEmails(): array
    {
        return $this->nonCandidates;
    }

    protected function accessChecker(): TaskBoardResourceAccessChecker
    {
        $checker = static::getContainer()->get(TaskBoardResourceAccessChecker::class);
        self::assertInstanceOf(TaskBoardResourceAccessChecker::class, $checker);

        return $checker;
    }

    protected function directories(?EntityManagerInterface $em = null): TaskSyncPeopleDirectoryService
    {
        $em ??= $this->em;

        return new TaskSyncPeopleDirectoryService($em, new TaskSyncPersonRepository($em), $this->resolver(), $this->accessChecker(), $this->dispatcher);
    }

    protected function reassigner(?EntityManagerInterface $em = null, int $batchSize = 500): TaskSyncPeopleReassigner
    {
        $em ??= $this->em;
        $people = new TaskSyncPersonRepository($em);

        return new TaskSyncPeopleReassigner($em, $people, $this->directories($em), new PeopleProjection($em, $people), new SyncStore($em), $this->resolver(), $this->accessChecker(), $this->dispatcher, defaultBatchSize: $batchSize);
    }

    protected function projector(): TaskSyncPeopleProjector
    {
        return new TaskSyncPeopleProjector($this->reassigner());
    }

    protected function connect(TaskBoard $board, ?TaskSyncDirectory $directory = null, ?EntityManagerInterface $em = null): TaskBoardConnection
    {
        $em ??= $this->em;
        $connection = new TaskBoardConnection($board, 'clickup', 'scope', 'conn-' . $board->getId(), 'L-' . $board->getId(), 'Backlog');
        $connection->setDirectory($directory);
        $em->persist($connection);
        $em->flush();

        return $connection;
    }

    protected function directory(?EntityManagerInterface $em = null): TaskSyncDirectory
    {
        $em ??= $this->em;
        $directory = new TaskSyncDirectory('clickup', 'project:test', (string) (9000001 + $this->directories++));
        $em->persist($directory);
        $em->flush();

        return $directory;
    }

    protected function person(TaskSyncDirectory $directory, string $remoteId, ?AppUser $mapped = null, TaskSyncPersonMapping $mapping = TaskSyncPersonMapping::Manual, ?EntityManagerInterface $em = null): TaskSyncPerson
    {
        $em ??= $this->em;
        $person = new TaskSyncPerson($directory, $remoteId, 'User ' . $remoteId, new DateTimeImmutable());
        if ($mapped instanceof AppUser || $mapping !== TaskSyncPersonMapping::Manual) {
            $person->map($mapping, $mapped, null, new DateTimeImmutable());
        }
        $em->persist($person);
        $em->flush();

        return $person;
    }

    /**
     * A synced task created by `$creator` for the person, with a comment by `$author` and, for each role, a sync member
     * of `$memberUser` (or the person listed as unmatched when null).
     *
     * @param list<TaskMemberRole> $roles
     */
    protected function item(TaskBoardConnection $connection, string $title, TaskSyncPerson $person, object $creator, ?object $author = null, ?object $memberUser = null, array $roles = [TaskMemberRole::Assignee], ?Task $parent = null, ?EntityManagerInterface $em = null): TaskSyncItem
    {
        $em ??= $this->em;
        $board = $connection->getBoard();
        $task  = new Task(board: $board, title: $title, creator: $creator, column: $board->getColumns()->first() ?: null, parent: $parent);
        $em->persist($task);
        $item = (new TaskSyncItem($connection, $board->getId(), $task, $title, new DateTimeImmutable('@1790000000')))->setCreatorPerson($person);
        $em->persist($item);
        if ($author !== null) {
            $comment = new TaskComment($task, $author, 'Body of ' . $title);
            $em->persist($comment);
            $em->persist((new TaskSyncComment($item, $comment, 'c-' . $title, 'h', 'h'))->setAuthorPerson($person));
        }
        foreach ($roles as $role) {
            if ($memberUser !== null) {
                $member = new TaskMember($task, $memberUser, $role);
                $task->addMember($member);
                $em->persist($member);
                $em->persist(new TaskSyncMember($item, $member, $person, $role->value));
            } else {
                $item->setUnmatchedPeople($role->value, [...$item->unmatchedPeople($role->value), $person->getId()]);
            }
        }
        $em->flush();

        return $item;
    }

    protected function viewer(TaskBoard $board, AppUser ...$users): void
    {
        foreach ($users as $user) {
            $this->viewers[$board->getId()][] = $user->getEmail();
        }
    }

    protected function reload(object $entity): object
    {
        $this->em->clear();
        $this->refreshOwner();
        $id    = $this->em->getClassMetadata($entity::class)->getIdentifierValues($entity);
        $fresh = $this->em->find($entity::class, $id);
        self::assertNotNull($fresh);

        return $fresh;
    }

    protected function taskOf(TaskSyncItem $item): Task
    {
        $this->em->clear();
        $this->refreshOwner();
        $task = $this->em->find(Task::class, $item->getTask()?->getId());
        self::assertInstanceOf(Task::class, $task);

        return $task;
    }

    /**
     * @return list<string> "role:userId" of the task's members
     */
    protected function membersOf(Task $task): array
    {
        $out = [];
        foreach ($task->getMembers() as $member) {
            $out[] = $member->getMemberRole()->value . ':' . SyncUser::id($member->getUser());
        }
        sort($out);

        return $out;
    }

    protected function authorOf(TaskSyncItem $item): ?string
    {
        $comment = $this->em->getRepository(TaskSyncComment::class)->findOneBy(['item' => $item->getId()]);

        return $comment instanceof TaskSyncComment ? SyncUser::id($comment->getComment()->getAuthor()) : null;
    }

    protected function freshItem(TaskSyncItem $item): TaskSyncItem
    {
        $fresh = $this->em->find(TaskSyncItem::class, $item->getId());
        self::assertInstanceOf(TaskSyncItem::class, $fresh);

        return $fresh;
    }

    protected function freshPerson(TaskSyncPerson $person): TaskSyncPerson
    {
        $fresh = $this->em->find(TaskSyncPerson::class, $person->getId());
        self::assertInstanceOf(TaskSyncPerson::class, $fresh);

        return $fresh;
    }

    protected function freshUser(AppUser $user): AppUser
    {
        $fresh = $this->em->find(AppUser::class, $user->getId());
        self::assertInstanceOf(AppUser::class, $fresh);

        return $fresh;
    }

    protected function freshBoardOf(TaskBoard $board): TaskBoard
    {
        $fresh = $this->em->find(TaskBoard::class, $board->getId());
        self::assertInstanceOf(TaskBoard::class, $fresh);

        return $fresh;
    }
}
