<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Entity\TeamMember;
use Nowo\TaskBoardBundle\Enum\TeamRole;

final readonly class DoctrineOrmTeamMemberRepository implements TeamMemberRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function findByUserId(string $userId): array
    {
        /** @var list<TeamMember> $members */
        $members = $this->entityManager->createQueryBuilder()
            ->select('tm')
            ->from(TeamMember::class, 'tm')
            ->innerJoin('tm.user', 'u')
            ->where('u.id = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getResult();

        return $members;
    }

    public function findMembersManagedBy(string $managerUserId): array
    {
        /** @var list<TeamMember> $managedTeams */
        $managedTeams = $this->entityManager->createQueryBuilder()
            ->select('mgr')
            ->from(TeamMember::class, 'mgr')
            ->innerJoin('mgr.user', 'mgrUser')
            ->where('mgrUser.id = :managerId')
            ->andWhere('mgr.role = :managerRole')
            ->setParameter('managerId', $managerUserId)
            ->setParameter('managerRole', TeamRole::Manager)
            ->getQuery()
            ->getResult();

        if ($managedTeams === []) {
            return [];
        }

        $teamIds = array_map(static fn (TeamMember $m): string => $m->getTeam()->getId(), $managedTeams);

        /** @var list<TeamMember> $members */
        $members = $this->entityManager->createQueryBuilder()
            ->select('tm')
            ->from(TeamMember::class, 'tm')
            ->innerJoin('tm.team', 'team')
            ->where('team.id IN (:teamIds)')
            ->setParameter('teamIds', $teamIds)
            ->getQuery()
            ->getResult();

        return $members;
    }

    public function isManagerOf(string $managerUserId, string $memberUserId): bool
    {
        foreach ($this->findMembersManagedBy($managerUserId) as $member) {
            $user = $member->getUser();
            if (method_exists($user, 'getId') && (string) $user->getId() === $memberUserId) {
                return true;
            }
        }

        return false;
    }
}
