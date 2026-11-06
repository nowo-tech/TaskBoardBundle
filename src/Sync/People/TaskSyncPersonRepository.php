<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\People;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Support\Like;
use Nowo\TaskBoardBundle\Sync\Entity\TaskBoardConnection;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncComment;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncDirectory;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncField;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncFieldValue;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncItem;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncMember;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncPerson;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncPersonMapping;
use Nowo\TaskBoardBundle\Sync\Field\SyncFieldType;
use SortDirection;

use function array_map;
use function count;
use function in_array;
use function is_array;
use function max;

use const DATE_ATOM;

/**
 * Since 1.25: the queries of the people directory. Every query is scoped to one directory (tenant isolation).
 */
final readonly class TaskSyncPersonRepository
{
    private const EXPORT_PAGE = 500;

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function findDirectory(string $provider, string $scopeKey, string $workspaceId): ?TaskSyncDirectory
    {
        return $this->entityManager->getRepository(TaskSyncDirectory::class)->findOneBy(['provider' => $provider, 'scopeKey' => $scopeKey, 'workspaceId' => $workspaceId]);
    }

    public function findPerson(string $id): ?TaskSyncPerson
    {
        return $this->entityManager->find(TaskSyncPerson::class, $id);
    }

    public function findOneByProviderUserId(TaskSyncDirectory $directory, string $providerUserId): ?TaskSyncPerson
    {
        return $this->entityManager->getRepository(TaskSyncPerson::class)->findOneBy(['directory' => $directory, 'providerUserId' => $providerUserId]);
    }

    /**
     * The people of a directory, by name, narrowed by the filter (mapping states, `q` on name or e-mail, e-mail
     * changed [since a date]). The search text is bound as a parameter with LIKE wildcards escaped.
     *
     * @return list<TaskSyncPerson>
     */
    public function forDirectory(TaskSyncDirectory $directory, PersonFilter $filter = new PersonFilter(), int $limit = 200, int $offset = 0): array
    {
        $builder = $this->entityManager->createQueryBuilder()
            ->select('p')->from(TaskSyncPerson::class, 'p')
            ->where('p.directory = :directory')->setParameter('directory', $directory)
            ->orderBy('p.name', SortDirection::Ascending)->addOrderBy('p.id', SortDirection::Ascending)
            ->setFirstResult(max(0, $offset))->setMaxResults(max(1, $limit));
        if ($filter->mappings !== []) {
            $builder->andWhere('p.mapping IN (:mappings)')->setParameter('mappings', $filter->mappings);
        }
        if ($filter->q !== '') {
            $builder->andWhere("(LOWER(p.name) LIKE :q ESCAPE '!' OR p.emailNormalized LIKE :q ESCAPE '!')")
                ->setParameter('q', '%' . Like::literal($filter->q) . '%');
        }
        if ($filter->changedSince instanceof DateTimeImmutable) {
            $builder->andWhere('p.emailChangedAt >= :changedSince')->setParameter('changedSince', $filter->changedSince);
        } elseif ($filter->changed) {
            $builder->andWhere('p.emailChangedAt IS NOT NULL');
        }

        /** @var list<TaskSyncPerson> $people */
        $people = $builder->getQuery()->getResult();

        return $people;
    }

    /**
     * Since 1.27.0: people whose re-association is not finished (a pending target is stored), by id.
     *
     * @return list<TaskSyncPerson>
     */
    public function pendingReassigns(TaskSyncDirectory $directory, int $limit = 50): array
    {
        /** @var list<TaskSyncPerson> $people */
        $people = $this->entityManager->createQueryBuilder()
            ->select('p')->from(TaskSyncPerson::class, 'p')
            ->where('p.directory = :directory')->andWhere('p.reassignId IS NOT NULL')
            ->setParameter('directory', $directory)
            ->orderBy('p.id', SortDirection::Ascending)->setMaxResults(max(1, $limit))
            ->getQuery()->getResult();

        return $people;
    }

    /** People nobody decided yet (unmatched or suggested, not anonymised). */
    public function unreviewedCount(TaskSyncDirectory $directory): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(p.id)')->from(TaskSyncPerson::class, 'p')
            ->where('p.directory = :directory')->andWhere('p.mapping IN (:mappings)')->andWhere('p.anonymisedAt IS NULL')
            ->setParameter('directory', $directory)
            ->setParameter('mappings', [TaskSyncPersonMapping::Unmatched, TaskSyncPersonMapping::Suggested])
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * People per mapping state.
     *
     * @return array<string, int>
     */
    public function mappingCounts(TaskSyncDirectory $directory): array
    {
        /** @var list<array{mapping: string|TaskSyncPersonMapping, n: int|string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('p.mapping AS mapping', 'COUNT(p.id) AS n')->from(TaskSyncPerson::class, 'p')
            ->where('p.directory = :directory')->setParameter('directory', $directory)
            ->groupBy('p.mapping')->getQuery()->getArrayResult();
        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['mapping'] instanceof TaskSyncPersonMapping ? $row['mapping']->value : (string) $row['mapping']] = (int) $row['n'];
        }

        return $counts;
    }

    /**
     * What each person of the directory authored or is a member of on its boards (computed, never stored):
     * person id => [creator, comment, assignee, watcher].
     *
     * @return array<string, array<string, int>>
     */
    public function counts(TaskSyncDirectory $directory): array
    {
        $counts = [];
        $add    = static function (array $rows, string $kind) use (&$counts): void {
            foreach ($rows as $row) {
                $counts[(string) $row['id']] ??= ['creator' => 0, 'comment' => 0, 'assignee' => 0, 'watcher' => 0];
                $counts[(string) $row['id']][$kind] += (int) $row['n'];
            }
        };
        $add($this->entityManager->createQueryBuilder()->select('IDENTITY(i.creatorPerson) AS id', 'COUNT(i.id) AS n')->from(TaskSyncItem::class, 'i')
            ->join(TaskSyncPerson::class, 'p', 'WITH', 'p = i.creatorPerson')->where('p.directory = :d')->setParameter('d', $directory)
            ->groupBy('i.creatorPerson')->getQuery()->getArrayResult(), 'creator');
        $add($this->entityManager->createQueryBuilder()->select('IDENTITY(c.authorPerson) AS id', 'COUNT(c.id) AS n')->from(TaskSyncComment::class, 'c')
            ->join(TaskSyncPerson::class, 'p', 'WITH', 'p = c.authorPerson')->where('p.directory = :d')->setParameter('d', $directory)
            ->groupBy('c.authorPerson')->getQuery()->getArrayResult(), 'comment');
        foreach (['assignee', 'watcher'] as $role) {
            $add($this->entityManager->createQueryBuilder()->select('IDENTITY(m.person) AS id', 'COUNT(m.id) AS n')->from(TaskSyncMember::class, 'm')
                ->join('m.person', 'p')->where('p.directory = :d')->andWhere('m.memberRole = :role')->setParameter('d', $directory)->setParameter('role', $role)
                ->groupBy('m.person')->getQuery()->getArrayResult(), $role);
        }

        return $counts;
    }

    /**
     * Every person mapped (auto / manual) or suggested to the user, in every directory (erasure, re-projection).
     *
     * @return list<TaskSyncPerson>
     */
    public function linkedToUser(object $user, bool $includeSuggested = true): array
    {
        $builder = $this->entityManager->createQueryBuilder()->select('p')->from(TaskSyncPerson::class, 'p')
            ->where('(p.mappedUser = :user AND p.mapping IN (:mapped))')
            ->setParameter('user', $user)
            ->setParameter('mapped', [TaskSyncPersonMapping::Auto, TaskSyncPersonMapping::Manual])
            ->orderBy('p.id', SortDirection::Ascending);
        if ($includeSuggested) {
            $builder->orWhere('(p.suggestedUser = :user AND p.mapping = :suggested)')->setParameter('suggested', TaskSyncPersonMapping::Suggested);
        }

        /** @var list<TaskSyncPerson> $people */
        $people = $builder->getQuery()->getResult();

        return $people;
    }

    /**
     * The connections (one per board) that use the directory, by id.
     *
     * @return list<TaskBoardConnection>
     */
    public function connectionsOf(TaskSyncDirectory $directory): array
    {
        /** @var list<TaskBoardConnection> $connections */
        $connections = $this->entityManager->getRepository(TaskBoardConnection::class)->findBy(['directory' => $directory], ['id' => 'ASC']);

        return $connections;
    }

    /**
     * The sync items of a connection after an id, by id (keyset batches).
     *
     * @return list<TaskSyncItem>
     */
    public function itemsAfter(TaskBoardConnection $connection, string $afterId, int $limit): array
    {
        /** @var list<TaskSyncItem> $items */
        $items = $this->entityManager->createQueryBuilder()->select('i', 't')->from(TaskSyncItem::class, 'i')->join('i.task', 't')
            ->where('i.connection = :c')->andWhere('i.id > :after')
            ->setParameter('c', $connection)->setParameter('after', $afterId)
            ->orderBy('i.id', SortDirection::Ascending)->setMaxResults(max(1, $limit))
            ->getQuery()->getResult();

        return $items;
    }

    /**
     * The members and the history of the items' tasks, loaded in two queries (a re-association batch then reads with a
     * constant number of queries, whatever its size).
     *
     * @param list<TaskSyncItem> $items
     */
    public function prefetchTasks(array $items): void
    {
        $tasks = [];
        foreach ($items as $item) {
            $tasks[] = $item->getTask();
        }
        if ($tasks === []) {
            return;
        }
        foreach (['members', 'changeHistory'] as $collection) {
            $this->entityManager->createQueryBuilder()->select('t', 'x')->from(Task::class, 't')->leftJoin('t.' . $collection, 'x')
                ->where('t IN (:tasks)')->setParameter('tasks', $tasks)->getQuery()->getResult();
        }
    }

    /**
     * Sync comments of these items written for the person.
     *
     * @param list<TaskSyncItem> $items
     *
     * @return list<TaskSyncComment>
     */
    public function commentsOf(TaskSyncPerson $person, array $items): array
    {
        if ($items === []) {
            return [];
        }

        /** @var list<TaskSyncComment> $comments */
        $comments = $this->entityManager->createQueryBuilder()->select('c', 'tc')->from(TaskSyncComment::class, 'c')->join('c.comment', 'tc')
            ->where('c.authorPerson = :p')->andWhere('c.item IN (:items)')
            ->setParameter('p', $person)->setParameter('items', $items)->getQuery()->getResult();

        return $comments;
    }

    /**
     * Sync members of these items added for the person.
     *
     * @param list<TaskSyncItem> $items
     *
     * @return list<TaskSyncMember>
     */
    public function membersOf(TaskSyncPerson $person, array $items): array
    {
        if ($items === []) {
            return [];
        }

        /** @var list<TaskSyncMember> $members */
        $members = $this->entityManager->createQueryBuilder()->select('m', 'tm')->from(TaskSyncMember::class, 'm')->join('m.member', 'tm')
            ->where('m.person = :p')->andWhere('m.item IN (:items)')
            ->setParameter('p', $person)->setParameter('items', $items)->getQuery()->getResult();

        return $members;
    }

    /**
     * Sync members of one item.
     *
     * @return list<TaskSyncMember>
     */
    public function membersOfItem(TaskSyncItem $item): array
    {
        /** @var list<TaskSyncMember> $members */
        $members = $this->entityManager->getRepository(TaskSyncMember::class)->findBy(['item' => $item]);

        return $members;
    }

    /** Whether any item, comment or member still references the person. */
    public function isReferenced(TaskSyncPerson $person): bool
    {
        foreach ([[TaskSyncItem::class, 'creatorPerson'], [TaskSyncComment::class, 'authorPerson'], [TaskSyncMember::class, 'person']] as [$class, $field]) {
            $n = (int) $this->entityManager->createQueryBuilder()->select('COUNT(x.id)')->from($class, 'x')
                ->where('x.' . $field . ' = :p')->setParameter('p', $person)->getQuery()->getSingleScalarResult();
            if ($n > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Since 1.25 (export, §9): the people mapped or suggested to the user, as plain rows (no other person).
     *
     * @return list<array<string, string|null>>
     */
    public function exportFor(object $user): array
    {
        return array_map($this->exportRow(...), $this->linkedToUser($user));
    }

    /**
     * Since 1.25 (export of an organization or project, §9): every person of the directory as plain rows.
     *
     * @return list<array<string, string|null>>
     */
    public function exportDirectory(TaskSyncDirectory $directory): array
    {
        /** @var list<TaskSyncPerson> $people */
        $people = $this->entityManager->getRepository(TaskSyncPerson::class)->findBy(['directory' => $directory], ['id' => 'ASC']);

        return array_map($this->exportRow(...), $people);
    }

    /**
     * Since 1.27.0 (audit I6): an access request for one person — the row, the estimates per task, the comments assigned
     * to them and the `people` custom fields that name them. Comment bodies and task text are not searched. **The host
     * authorises the caller.**.
     *
     * @return array{person: array<string, string|null>, estimates: list<array{taskId: string, minutes: int}>, commentAssignments: list<array{taskId: string, commentId: string, resolved: bool|null}>, fields: list<array{taskId: string, field: string}>}
     */
    public function exportPerson(TaskSyncPerson $person): array
    {
        $token = 'person:' . $person->getId();

        /** @var list<array{taskId: string, commentId: string, resolved: bool|null}> $assigned */
        $assigned = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(i.task) AS taskId', 'IDENTITY(c.comment) AS commentId', 'c.resolved AS resolved')
            ->from(TaskSyncComment::class, 'c')->join('c.item', 'i')
            ->where('c.assigneePerson = :person')->setParameter('person', $person)
            ->getQuery()->getArrayResult();

        // Since 1.28.0 (audit I6): the links counts() counts, by id.
        /** @var list<array{taskId: string}> $created */
        $created = $this->entityManager->createQueryBuilder()->select('IDENTITY(i.task) AS taskId')->from(TaskSyncItem::class, 'i')
            ->where('i.creatorPerson = :person')->andWhere('i.task IS NOT NULL')->setParameter('person', $person)->getQuery()->getArrayResult();
        /** @var list<array{taskId: string, commentId: string}> $written */
        $written = $this->entityManager->createQueryBuilder()->select('IDENTITY(i.task) AS taskId', 'IDENTITY(c.comment) AS commentId')->from(TaskSyncComment::class, 'c')->join('c.item', 'i')
            ->where('c.authorPerson = :person')->setParameter('person', $person)->getQuery()->getArrayResult();
        /** @var list<array{taskId: string, role: string}> $members */
        $members = $this->entityManager->createQueryBuilder()->select('IDENTITY(i.task) AS taskId', 'm.memberRole AS role')->from(TaskSyncMember::class, 'm')->join('m.item', 'i')
            ->where('m.person = :person')->setParameter('person', $person)->getQuery()->getArrayResult();
        $unlisted = [];
        foreach ($this->unmatchedTasksOf($person) as $row) {
            $unlisted[] = $row;
        }

        return ['person' => $this->exportRow($person), 'estimates' => $this->estimatesOf($person->getDirectory(), $token), 'commentAssignments' => $assigned, 'fields' => $this->fieldsNaming($person->getDirectory(), $token), 'tasksCreated' => array_column($created, 'taskId'), 'commentsWritten' => $written, 'taskRoles' => [...$members, ...$unlisted]];
    }

    /**
     * Assignee and watcher roles of a person who is listed on the task rather than a member row (`unmatched_people`).
     *
     * @return list<array{taskId: string, role: string}>
     */
    private function unmatchedTasksOf(TaskSyncPerson $person): array
    {
        $found = [];
        $after = '';
        do {
            /** @var list<TaskSyncItem> $page */
            $page = $this->entityManager->createQueryBuilder()->select('i')->from(TaskSyncItem::class, 'i')->join('i.connection', 'c')
                ->where('c.directory = :directory')->andWhere('i.id > :after')->andWhere('i.task IS NOT NULL')
                ->setParameter('directory', $person->getDirectory())->setParameter('after', $after)
                ->orderBy('i.id', 'ASC')->setMaxResults(self::EXPORT_PAGE)->getQuery()->getResult();
            foreach ($page as $item) {
                $after = $item->getId();
                foreach (PeopleProjection::ROLES as $role) {
                    if (in_array($person->getId(), $item->unmatchedPeople($role->value), true)) {
                        $found[] = ['taskId' => (string) $item->getTask()?->getId(), 'role' => $role->value];
                    }
                }
            }
        } while (count($page) === self::EXPORT_PAGE);

        return $found;
    }

    /**
     * @return list<array{taskId: string, minutes: int}>
     */
    private function estimatesOf(TaskSyncDirectory $directory, string $token): array
    {
        $found = [];
        $after = '';
        do {
            /** @var list<array{id: string, taskId: string|null, meta: array<string, mixed>|null}> $page */
            $page = $this->entityManager->createQueryBuilder()
                ->select('i.id AS id', 'IDENTITY(i.task) AS taskId', 'i.remoteMeta AS meta')->from(TaskSyncItem::class, 'i')->join('i.connection', 'c')
                ->where('c.directory = :directory')->andWhere('i.id > :after')->andWhere('i.remoteMeta IS NOT NULL')
                ->setParameter('directory', $directory)->setParameter('after', $after)
                ->orderBy('i.id', 'ASC')->setMaxResults(self::EXPORT_PAGE)->getQuery()->getArrayResult();
            foreach ($page as $row) {
                $after     = $row['id'];
                $estimates = $row['meta']['estimates'] ?? [];
                foreach (is_array($estimates) ? $estimates : [] as $estimate) {
                    if ($row['taskId'] !== null && is_array($estimate) && ($estimate['person'] ?? null) === $token) {
                        $found[] = ['taskId' => $row['taskId'], 'minutes' => (int) ($estimate['minutes'] ?? 0)];
                    }
                }
            }
        } while (count($page) === self::EXPORT_PAGE);

        return $found;
    }

    /**
     * @return list<array{taskId: string, field: string}>
     */
    private function fieldsNaming(TaskSyncDirectory $directory, string $token): array
    {
        $found = [];
        foreach ($this->connectionsOf($directory) as $connection) {
            $after = '';
            do {
                /** @var list<array{id: string, taskId: string, field: string, value: mixed}> $page */
                $page = $this->entityManager->createQueryBuilder()
                    ->select('v.id AS id', 'IDENTITY(v.task) AS taskId', 'f.name AS field', 'v.value AS value')
                    ->from(TaskSyncFieldValue::class, 'v')->join(TaskSyncField::class, 'f', 'WITH', 'f = v.field')
                    ->where('f.board = :board')->andWhere('f.type = :people')->andWhere('v.id > :after')
                    ->setParameter('board', $connection->getBoard())->setParameter('people', SyncFieldType::People->value)->setParameter('after', $after)
                    ->orderBy('v.id', 'ASC')->setMaxResults(self::EXPORT_PAGE)->getQuery()->getArrayResult();
                foreach ($page as $row) {
                    $after = $row['id'];
                    if (is_array($row['value']) && in_array($token, $row['value'], true)) {
                        $found[] = ['taskId' => $row['taskId'], 'field' => $row['field']];
                    }
                }
            } while (count($page) === self::EXPORT_PAGE);
        }

        return $found;
    }

    /**
     * @return array<string, string|null>
     */
    private function exportRow(TaskSyncPerson $person): array
    {
        return [
            'id'             => $person->getId(),
            'directoryId'    => $person->getDirectory()->getId(),
            'provider'       => $person->getDirectory()->getProvider(),
            'providerUserId' => $person->getProviderUserId(),
            'name'           => $person->getName(),
            'email'          => $person->getEmail(),
            'remoteRole'     => $person->getRemoteRole(),
            'mapping'        => $person->getMapping()->value,
            'mappedUserId'   => SyncUser::id($person->getMappedUser()),
            'mappedAt'       => $person->getMappedAt()?->format(DATE_ATOM),
            'firstSeenAt'    => $person->getFirstSeenAt()->format(DATE_ATOM),
            'lastSeenAt'     => $person->getLastSeenAt()->format(DATE_ATOM),
            'anonymisedAt'   => $person->getAnonymisedAt()?->format(DATE_ATOM),
        ];
    }
}
