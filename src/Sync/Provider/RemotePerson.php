<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Provider;

/**
 * Since 1.25: a member of the remote workspace. The avatar URL is never stored by the bundle (owner decision).
 */
final readonly class RemotePerson
{
    public function __construct(
        public string $providerUserId,
        public string $name,
        public ?string $email = null,
        public ?RemoteRole $role = null,
        public ?string $avatarUrl = null,
    ) {
    }

    public function ref(): RemotePersonRef
    {
        return new RemotePersonRef($this->providerUserId, $this->name, $this->email);
    }
}
