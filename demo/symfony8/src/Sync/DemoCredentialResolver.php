<?php

declare(strict_types=1);

namespace App\Sync;

use Nowo\TaskBoardBundle\Sync\Hook\ConnectionChoice;
use Nowo\TaskBoardBundle\Sync\Hook\ProviderCredentialSourceInterface;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCredential;
use Nowo\TaskBoardBundle\ValueObject\TaskBoardSnapshot;

/**
 * Demo credential store: one fake ClickUp connection (the demo provider is in memory, nothing leaves the container).
 * A real host keeps encrypted tokens per board or project and never shows them again.
 */
final readonly class DemoCredentialResolver implements ProviderCredentialSourceInterface
{
    public const SECRET = 'pk_demo_not_a_real_token';

    public function connectionsFor(TaskBoardSnapshot $board, object $user, string $provider): array
    {
        return [new ConnectionChoice('demo', 'Demo workspace', substr(self::SECRET, -4))];
    }

    public function credentialFor(TaskBoardSnapshot $board, string $connectionRef): ?ProviderCredential
    {
        return $connectionRef === 'demo' ? new ProviderCredential(self::SECRET, $connectionRef) : null;
    }

    public function credentialFormUrl(TaskBoardSnapshot $board, string $provider): ?string
    {
        return null;
    }
}
