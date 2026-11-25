<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\OAuth;

use function array_map;

/**
 * Since 3.1.0 (CU-1): who authorised a connection and which workspaces they authorised, for the host's connection
 * label and stored workspace list. It never carries the e-mail address.
 */
final readonly class OAuthIdentity
{
    /**
     * @param list<OAuthWorkspace> $workspaces
     */
    public function __construct(
        public string $userId,
        public string $userName,
        public array $workspaces,
    ) {
    }

    /**
     * @return list<string>
     */
    public function workspaceIds(): array
    {
        return array_map(static fn (OAuthWorkspace $workspace): string => $workspace->id, $this->workspaces);
    }
}
