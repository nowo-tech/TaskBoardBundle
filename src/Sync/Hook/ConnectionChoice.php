<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Hook;

use Nowo\TaskBoardBundle\Sync\Provider\CredentialScheme;

/** Since 1.23: a stored connection the user may pick (never the secret: its last four characters only). */
final readonly class ConnectionChoice
{
    /**
     * @param CredentialScheme $kind since 3.1.0: a pasted personal token (default) or an OAuth connection (the
     *                               picker labels it)
     * @param list<string> $workspaceIds since 3.1.0: the provider workspaces the connection was authorised for
     *                                   (empty = unknown, e.g. a personal token)
     */
    public function __construct(
        public string $ref,
        public string $label,
        public string $lastFour,
        public CredentialScheme $kind = CredentialScheme::Personal,
        public array $workspaceIds = [],
    ) {
    }
}
