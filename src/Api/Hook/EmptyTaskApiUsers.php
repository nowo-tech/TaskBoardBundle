<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Hook;

use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\Api\TaskApiRefusal;

/**
 * Since 2.2.0 (spec 213): the default: nobody is known, until the host sets `api.hooks.users`.
 */
final readonly class EmptyTaskApiUsers implements TaskApiUsersInterface
{
    public function apiId(object $user): string
    {
        throw TaskApiRefusal::notFound();
    }

    public function find(string $apiId): ?object
    {
        return null;
    }

    public function profiles(array $users, TaskApiPrincipal $viewer): array
    {
        return [];
    }
}
