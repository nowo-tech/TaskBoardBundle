<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Hook;

/**
 * Since 1.25: an automatic e-mail match. `verified` false = the account's e-mail is not verified: the person is only
 * suggested and a human confirms it.
 */
final readonly class SyncPersonMatch
{
    public function __construct(
        public object $user,
        public bool $verified,
    ) {
    }
}
