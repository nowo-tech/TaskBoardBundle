<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Serializer;

use Nowo\TaskBoardBundle\Api\Value\TaskApiMember;
use Nowo\TaskBoardBundle\Api\Value\TaskApiTeam;
use Nowo\TaskBoardBundle\Api\Value\TaskApiUser;

use function array_map;

/**
 * Since 2.2.0 (spec 213): identity in ClickUp's field names. Since 2.16.0 a team lists its members through `api.hooks.members`.
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
            'initials'       => TaskApiReadSerializer::initials($user->username),
            'profilePicture' => $user->profilePicture,
        ];
    }

    /**
     * Since 2.16.0 (spec 234, FR-025): with the team's members (ClickUp's `[{"user": {…}}]`, plus `service_account`).
     *
     * @param list<TaskApiMember> $members
     *
     * @return array<string, mixed>
     */
    public function team(TaskApiTeam $team, array $members = []): array
    {
        return [
            'id'      => $team->id,
            'name'    => $team->name,
            'color'   => $team->color,
            'avatar'  => $team->avatar,
            'members' => array_map(fn (TaskApiMember $member): array => ['user' => $this->member($member)], $members),
        ];
    }

    /**
     * Since 2.16.0 (spec 234, FR-025): a member as ClickUp's user, plus whether it is a service account (an agent).
     *
     * @return array<string, mixed>
     */
    public function member(TaskApiMember $member): array
    {
        return [
            'id'              => $member->id,
            'username'        => $member->name,
            'email'           => $member->email,
            'color'           => $member->color,
            'initials'        => TaskApiReadSerializer::initials($member->name),
            'profilePicture'  => $member->profilePicture,
            'service_account' => $member->isServiceAccount(),
        ];
    }

    /**
     * Since 2.16.0 (spec 234, FR-025): a name lookup's match: id, name and the service-account flag only.
     *
     * @return array{id: string, username: string, service_account: bool}
     */
    public function memberMatch(TaskApiMember $member): array
    {
        return ['id' => $member->id, 'username' => $member->name, 'service_account' => $member->isServiceAccount()];
    }
}
