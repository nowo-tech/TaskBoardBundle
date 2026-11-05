<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TaskBoardBundle\Doctrine\RecoveringFlusher;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskLink;
use Nowo\TaskBoardBundle\Entity\TeamMember;
use Nowo\TaskBoardBundle\Enum\TaskLinkType;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Support\Like;
use SortDirection;

use function array_chunk;
use function array_unique;
use function array_values;
use function max;

final readonly class DoctrineOrmTaskRepository implements TaskRepositoryInterface, BoundedTaskRepositoryInterface, ArchiveAwareTaskRepositoryInterface
{
    /** Since 1.23: ids per query of {@see self::findImportedByExternalIds()}. */
    private const ID_CHUNK = 500;

    /** Collections of the task page => the to-one relation of each element fetched with it. */
    private const DETAIL_RELATIONS = [
        'members'              => 'user',
        'links'                => null,
        'documents'            => null,
        'outgoingDependencies' => 'targetTask',
        'incomingDependencies' => 'sourceTask',
        'changeHistory'        => 'user',
        'timeEntries'          => 'user',
    ];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?ManagerRegistry $managerRegistry = null,
    ) {
    }

    public function save(Task $task): void
    {
        $this->entityManager->persist($task);
        RecoveringFlusher::flush($this->entityManager, $this->managerRegistry);
    }

    public function findById(string $id): ?Task
    {
        return $this->entityManager->find(Task::class, $id);
    }

    public function findByBoard(TaskBoard $board, bool $includeCompleted = false): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from(Task::class, 't')
            ->where('t.board = :board')
            ->andWhere('t.archivedAt IS NULL')
            ->setParameter('board', $board)
            ->orderBy('t.position', SortDirection::Ascending);

        if (!$includeCompleted) {
            $qb->andWhere('t.completedAt IS NULL');
        }

        /** @var list<Task> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    public function findByBoardWithin(TaskBoard $board, bool $includeCompleted, int $limit): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from(Task::class, 't')
            ->where('t.board = :board')
            ->andWhere('t.archivedAt IS NULL')
            ->setParameter('board', $board)
            ->orderBy('t.position', SortDirection::Ascending)
            ->addOrderBy('t.id', SortDirection::Ascending)
            ->setMaxResults(max(1, $limit));

        if (!$includeCompleted) {
            $qb->andWhere('t.completedAt IS NULL');
        }

        /** @var list<Task> $result */
        $result = $qb->getQuery()->getResult();
        if ($result !== []) {
            // Members and their users of the loaded tasks in one query (the cards show the assignees).
            $this->entityManager->createQueryBuilder()
                ->select('t', 'taskMember', 'memberUser')
                ->from(Task::class, 't')
                ->leftJoin('t.members', 'taskMember')
                ->leftJoin('taskMember.user', 'memberUser')
                ->where('t IN (:tasks)')
                ->setParameter('tasks', $result)
                ->getQuery()
                ->getResult();
        }

        return $result;
    }

    public function countByBoard(TaskBoard $board, bool $includeCompleted = false): int
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('COUNT(t.id)')
            ->from(Task::class, 't')
            ->where('t.board = :board')
            ->andWhere('t.archivedAt IS NULL')
            ->setParameter('board', $board);

        if (!$includeCompleted) {
            $qb->andWhere('t.completedAt IS NULL');
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function findForDetail(string $id): ?Task
    {
        $task = $this->entityManager->createQueryBuilder()
            ->select('t', 'board', 'col', 'parent')
            ->from(Task::class, 't')
            ->innerJoin('t.board', 'board')
            ->leftJoin('t.column', 'col')
            ->leftJoin('t.parent', 'parent')
            ->where('t.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
        if (!$task instanceof Task) {
            return null;
        }

        // One query per collection (no cartesian product): each one fills the collection of the same managed task.
        foreach (self::DETAIL_RELATIONS as $relation => $nested) {
            $qb = $this->entityManager->createQueryBuilder()
                ->select('t', 'rel')
                ->from(Task::class, 't')
                ->leftJoin('t.' . $relation, 'rel')
                ->where('t = :task')
                ->setParameter('task', $task);
            if ($nested !== null) {
                $qb->addSelect('nested')->leftJoin('rel.' . $nested, 'nested');
            }

            $qb->getQuery()->getResult();
        }

        return $task;
    }

    public function findTrackableForUser(string $userId, ?string $search, int $limit, int $offset): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('DISTINCT t')
            ->from(Task::class, 't')
            ->leftJoin('t.members', 'taskMember', 'WITH', 'taskMember.memberRole = :assigneeRole')
            ->leftJoin('taskMember.user', 'assignee')
            ->leftJoin('t.board', 'board')
            ->leftJoin('board.team', 'team')
            ->leftJoin(TeamMember::class, 'tm', 'WITH', 'tm.team = team')
            ->leftJoin('tm.user', 'tmUser')
            ->where('assignee.id = :userId OR tmUser.id = :userId')
            ->andWhere('t.completedAt IS NULL')
            ->andWhere('t.archivedAt IS NULL')
            ->setParameter('assigneeRole', TaskMemberRole::Assignee)
            ->setParameter('userId', $userId)
            ->orderBy('t.createdAt', SortDirection::Descending)
            ->setMaxResults(max(1, $limit))
            ->setFirstResult(max(0, $offset));

        if ($search !== null && $search !== '') {
            // Since 1.25 (audit FP-I12): `%`, `_` and `!` in the search match literally (ESCAPE '!').
            $qb->andWhere("LOWER(t.title) LIKE :search ESCAPE '!'")
                ->setParameter('search', '%' . Like::literal(mb_strtolower($search)) . '%');
        }

        /** @var list<Task> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    public function findByHostObject(string $objectType, string $objectId): array
    {
        /** @var list<Task> $result */
        $result = $this->entityManager->createQueryBuilder()
            ->select('DISTINCT t')
            ->from(Task::class, 't')
            ->innerJoin('t.links', 'link')
            ->where('link.linkType = :linkType')
            ->andWhere('link.objectType = :objectType')
            ->andWhere('link.objectId = :objectId')
            ->setParameter('linkType', TaskLinkType::HostObject)
            ->setParameter('objectType', $objectType)
            ->setParameter('objectId', $objectId)
            ->orderBy('t.createdAt', SortDirection::Ascending)
            ->getQuery()
            ->getResult();

        return $result;
    }

    public function findByBoardIncludingArchived(TaskBoard $board): array
    {
        /** @var list<Task> $result */
        $result = $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from(Task::class, 't')
            ->where('t.board = :board')
            ->setParameter('board', $board)
            ->orderBy('t.position', SortDirection::Ascending)
            ->getQuery()
            ->getResult();

        return $result;
    }

    public function findArchivedByBoard(TaskBoard $board, int $limit): array
    {
        /** @var list<Task> $result */
        $result = $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from(Task::class, 't')
            ->where('t.board = :board')
            ->andWhere('t.archivedAt IS NOT NULL')
            ->setParameter('board', $board)
            ->orderBy('t.archivedAt', SortDirection::Descending)
            ->addOrderBy('t.id', SortDirection::Ascending)
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();

        return $result;
    }

    public function findImportedByExternalIds(TaskBoard $board, string $scope, array $externalIds): array
    {
        $found = [];
        foreach (array_chunk(array_values(array_unique($externalIds)), self::ID_CHUNK) as $chunk) {
            /** @var list<TaskLink> $links */
            $links = $this->entityManager->createQueryBuilder()
                ->select('l', 't')
                ->from(TaskLink::class, 'l')
                ->innerJoin('l.task', 't')
                ->where('t.board = :board')
                ->andWhere('l.importSource = :scope')
                ->andWhere('l.externalId IN (:ids)')
                ->setParameter('board', $board)
                ->setParameter('scope', $scope)
                ->setParameter('ids', $chunk)
                ->orderBy('l.createdAt', SortDirection::Ascending)
                ->getQuery()
                ->getResult();
            foreach ($links as $link) {
                $found[(string) $link->getExternalId()] ??= [$link->getTask(), $link];
            }
        }

        return $found;
    }
}
