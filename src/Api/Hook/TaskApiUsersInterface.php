<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Hook;

use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\Api\TaskApiRefusal;
use Nowo\TaskBoardBundle\Api\Value\TaskApiUser;

/**
 * Since 2.2.0 (spec 213): how people read in the API. Ids are strings the host chooses. Default: {@see EmptyTaskApiUsers}.
 */
interface TaskApiUsersInterface
{
    /**
     * @throws TaskApiRefusal
     */
    public function apiId(object $user): string;

    public function find(string $apiId): ?object;

    /**
     * What `$viewer` may see of each person, in one call per page (FR-010). A person left out is not shown.
     *
     * @param list<object> $users
     *
     * @return array<string, TaskApiUser> by API id
     */
    public function profiles(array $users, TaskApiPrincipal $viewer): array;
}
