<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Value;

/**
 * Since 2.2.0 (spec 213): a person as a viewer may see them. A field the viewer may not see is null.
 */
final readonly class TaskApiUser
{
    public function __construct(
        public string $id,
        public string $username,
        public ?string $email = null,
        public ?string $color = null,
        public ?string $profilePicture = null,
    ) {
    }
}
