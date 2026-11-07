<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Serializer;

use Nowo\TaskBoardBundle\Api\Value\TaskApiTeam;
use Nowo\TaskBoardBundle\Api\Value\TaskApiUser;

/**
 * Since 2.2.0 (spec 213): identity in ClickUp's field names. A team lists no members in v1: a documented difference.
 */
final class TaskApiIdentitySerializer
{
    /**
     * @return array<string, string|null>
     */
    public function user(TaskApiUser $user): array
    {
        return [
            'id'             => $user->id,
            'username'       => $user->username,
            'email'          => $user->email,
            'color'          => $user->color,
            'profilePicture' => $user->profilePicture,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function team(TaskApiTeam $team): array
    {
        return [
            'id'      => $team->id,
            'name'    => $team->name,
            'color'   => $team->color,
            'avatar'  => $team->avatar,
            'members' => [],
        ];
    }
}
