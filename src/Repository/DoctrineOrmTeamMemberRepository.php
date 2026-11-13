<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Entity\TeamMember;
use Nowo\TaskBoardBundle\Enum\TeamRole;
use Nowo\TaskBoardBundle\Support\ActorId;
use Nowo\TaskBoardBundle\User\TaskBoardUsers;

final readonly class DoctrineOrmTeamMemberRepository implements TeamMemberRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        // Since 2.15.0 (database.user_reference).
        private ?TaskBoardUsers $users = null,
    ) {
    }

    /**
     * Since 2.15.0: the rows of a user by id: through the joined user (a relation, as 2.14) or the id column itself
     * (user_reference: id), re-checked byte for byte in PHP (BUNDLES_FIX_RULES "Identifier columns").
     *
     * @return list<TeamMember>
     */
    private function byUser(string $alias, string $userId, ?TeamRole $role = null): array
    {
        $idMode  = $this->users instanceof TaskBoardUsers && $this->users->idMode();
        $builder = $this->entityManager->createQueryBuilder()->select($alias)->from(TeamMember::class, $alias);
        if ($idMode) {
            $builder->where($alias . '.user = :userId');
        } else {
            $builder->innerJoin($alias . '.user', $alias . 'User')->where($alias . 'User.id = :userId');
        }
        $builder->setParameter('userId', $userId);
        if ($role instanceof TeamRole) {
            $builder->andWhere($alias . '.role = :role')->setParameter('role', $role);
        }
        /** @var list<TeamMember> $members */
        $members = $builder->getQuery()->getResult();

        return $idMode ? array_values(array_filter($members, static fn (TeamMember $member): bool => ActorId::of($member->getUser()) === $userId)) : $members;
    }

    public function findByUserId(string $userId): array
    {
        return $this->byUser('tm', $userId);
    }

    public function findMembersManagedBy(string $managerUserId): array
    {
        $managedTeams = $this->byUser('mgr', $managerUserId, TeamRole::Manager);

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
