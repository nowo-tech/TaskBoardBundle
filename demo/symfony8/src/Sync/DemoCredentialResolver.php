<?php

declare(strict_types=1);

namespace App\Sync;

use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Sync\Hook\ConnectionChoice;
use Nowo\TaskBoardBundle\Sync\Hook\ProviderCredentialResolverInterface;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCredential;

/**
 * Demo credential store: one fake ClickUp connection (the demo provider is in memory, nothing leaves the container).
 * A real host keeps encrypted tokens per board or project and never shows them again.
 */
final readonly class DemoCredentialResolver implements ProviderCredentialResolverInterface
{
    public const SECRET = 'pk_demo_not_a_real_token';

    public function connectionsFor(TaskBoard $board, object $user, string $provider): array
    {
        return [new ConnectionChoice('demo', 'Demo workspace', substr(self::SECRET, -4))];
    }

    public function credentialFor(TaskBoard $board, string $connectionRef): ?ProviderCredential
    {
        return $connectionRef === 'demo' ? new ProviderCredential(self::SECRET, $connectionRef) : null;
    }

    public function credentialFormUrl(TaskBoard $board, string $provider): ?string
    {
        return null;
    }
}
