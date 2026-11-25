<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Hook;

use Nowo\TaskBoardBundle\Sync\OAuth\ClickUpOAuthClient;
use Nowo\TaskBoardBundle\Sync\OAuth\OAuthReturnPath;
use Nowo\TaskBoardBundle\ValueObject\TaskBoardSnapshot;

/**
 * Since 3.1.0 (CU-1, optional): implemented by the same service as {@see ProviderCredentialSourceInterface}
 * (`sync.credential_resolver`) when the host offers *Connect with OAuth* next to *Add a token*. The OAuth flow stays
 * the host's (its start and callback routes, the state in its session, the token in its encrypted store); the
 * bundle only links to the start route. See docs/sync.md, "Connecting ClickUp with OAuth".
 */
interface OAuthConnectionStartInterface
{
    /**
     * The host's start route of an OAuth connection for this board and provider (`https://` or a same-origin path),
     * or null when the host offers none for them. After the callback the host redirects to `$returnTo`: the bundle's
     * connection page of the board (a same-origin path). The host checks it with {@see OAuthReturnPath::isSafe()}
     * when its start route receives it back, then keeps it in the session with the state.
     *
     * @see ClickUpOAuthClient
     */
    public function connectUrl(TaskBoardSnapshot $board, string $provider, string $returnTo): ?string;
}
