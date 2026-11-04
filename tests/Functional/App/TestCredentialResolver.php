<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\App;

use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Sync\Hook\ConnectionChoice;
use Nowo\TaskBoardBundle\Sync\Hook\ProviderCredentialResolverInterface;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCredential;

/**
 * Since 1.23: the host's credential store of the functional kernel — one connection "conn-1" whose secret tests may
 * change (the canary token test).
 */
final class TestCredentialResolver implements ProviderCredentialResolverInterface
{
    public static string $secret = 'pk_test_token';

    public static bool $available = true;

    public static string $formUrl = '/settings/connections/clickup';

    /** @var list<ConnectionChoice>|null */
    public static ?array $choices = null;

    public function connectionsFor(TaskBoard $board, object $user, string $provider): array
    {
        return self::$choices ?? [new ConnectionChoice('conn-1', 'Team token', '…' . substr(self::$secret, -4))];
    }

    public function credentialFor(TaskBoard $board, string $connectionRef): ?ProviderCredential
    {
        return self::$available && $connectionRef === 'conn-1' ? new ProviderCredential(self::$secret, $connectionRef) : null;
    }

    public function credentialFormUrl(TaskBoard $board, string $provider): string
    {
        return self::$formUrl;
    }

    public static function reset(): void
    {
        self::$secret    = 'pk_test_token';
        self::$available = true;
        self::$formUrl   = '/settings/connections/clickup';
        self::$choices   = null;
    }
}
