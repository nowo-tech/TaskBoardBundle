<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TeamMember;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;

final readonly class DoctrineOrmTaskRepository implements TaskRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(Task $task): void
    {
        $this->entityManager->persist($task);
        $this->entityManager->flush();
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
            ->setParameter('board', $board)
            ->orderBy('t.position', 'ASC');

        if (!$includeCompleted) {
            $qb->andWhere('t.completedAt IS NULL');
        }

        /** @var list<Task> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
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
            ->setParameter('assigneeRole', TaskMemberRole::Assignee)
            ->setParameter('userId', $userId)
            ->orderBy('t.createdAt', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->setFirstResult(max(0, $offset));

        if ($search !== null && $search !== '') {
            $qb->andWhere('LOWER(t.title) LIKE :search')
                ->setParameter('search', '%' . mb_strtolower($search) . '%');
        }

        /** @var list<Task> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }
}
