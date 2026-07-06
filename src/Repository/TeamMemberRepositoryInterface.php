<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Repository;

use Nowo\TaskBoardBundle\Entity\TeamMember;

interface TeamMemberRepositoryInterface
{
    /**
     * @return list<TeamMember>
     */
    public function findByUserId(string $userId): array;

    /**
     * @return list<TeamMember>
     */
    public function findMembersManagedBy(string $managerUserId): array;

    public function isManagerOf(string $managerUserId, string $memberUserId): bool;
}
