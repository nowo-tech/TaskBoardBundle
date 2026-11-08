<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Value;

/**
 * Since 2.2.0 (spec 213): a ClickUp workspace: the host's account, personal or an organization.
 */
final readonly class TaskApiTeam
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $color = null,
        public ?string $avatar = null,
    ) {
    }
}
