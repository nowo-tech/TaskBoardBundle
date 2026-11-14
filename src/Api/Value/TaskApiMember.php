<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Value;

/**
 * Since 2.16.0 (spec 234, FR-025): one member of a team (the host's account) as a viewer may see them. `$email` is shown
 * only to the member themself: the bundle drops it for anyone else, whatever the host returns. A service account (an
 * agent, Beacon's flag on the user) is a plain flag: no token or credential detail is ever part of a member.
 */
final readonly class TaskApiMember
{
    public function __construct(
        public string $id,
        public string $name,
        public bool $serviceAccount = false,
        public ?string $email = null,
        public ?string $color = null,
        public ?string $profilePicture = null,
    ) {
    }

    public function isServiceAccount(): bool
    {
        return $this->serviceAccount;
    }

    /** The same member without the e-mail. */
    public function withoutEmail(): self
    {
        return new self($this->id, $this->name, $this->serviceAccount, null, $this->color, $this->profilePicture);
    }
}
