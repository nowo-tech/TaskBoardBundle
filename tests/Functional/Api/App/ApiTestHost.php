<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Api\App;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiAuthenticatorInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiPlanGateInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiRateLimiterInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiUsersInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiWorkspaceInterface;
use Nowo\TaskBoardBundle\Api\TaskApiAuthFailure;
use Nowo\TaskBoardBundle\Api\TaskApiCredential;
use Nowo\TaskBoardBundle\Api\TaskApiLevel;
use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\Api\TaskApiWhere;
use Nowo\TaskBoardBundle\Api\Value\TaskApiRateLimitState;
use Nowo\TaskBoardBundle\Api\Value\TaskApiSpace;
use Nowo\TaskBoardBundle\Api\Value\TaskApiTeam;
use Nowo\TaskBoardBundle\Api\Value\TaskApiUser;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use Symfony\Component\HttpFoundation\Request;

use function in_array;

/**
 * The host side of the API in the tests: one scriptable object behind every hook. Its state is static, as a test sets it
 * before the kernel handles the request.
 */
final class ApiTestHost implements TaskApiAuthenticatorInterface, TaskApiRateLimiterInterface, TaskApiPlanGateInterface, TaskApiWorkspaceInterface, TaskApiUsersInterface
{
    /** @var array<string, array{email: string, level: TaskApiLevel, where: TaskApiWhere}> */
    public static array $tokens = [];

    public static bool $apiDisabled = false;

    public static bool $tokenLimitAccepts = true;

    public static bool $ipLimitAccepts = true;

    /** @var list<string> */
    public static array $failedIps = [];

    public static int $consumed = 0;

    public static bool $planAllows = true;

    /** @var list<string> tenants the plan refuses */
    public static array $planRefusesTenants = [];

    /** @var list<string|null> tenants the person reaches */
    public static array $tenants = [];

    public static bool $profilesEmpty = false;

    /** A principal the firewall does not stand behind. */
    public static ?object $forcedUser = null;

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public static function reset(): void
    {
        self::$tokens             = [];
        self::$apiDisabled        = false;
        self::$tokenLimitAccepts  = true;
        self::$ipLimitAccepts     = true;
        self::$failedIps          = [];
        self::$consumed           = 0;
        self::$planAllows         = true;
        self::$planRefusesTenants = [];
        self::$tenants            = [];
        self::$forcedUser         = null;
        self::$profilesEmpty      = false;
    }

    public function authenticate(Request $request): TaskApiPrincipal|TaskApiAuthFailure
    {
        if (self::$apiDisabled) {
            return TaskApiAuthFailure::ApiDisabled;
        }
        $token = self::$tokens[(string) TaskApiCredential::fromRequest($request)] ?? null;
        if ($token === null) {
            return TaskApiAuthFailure::Invalid;
        }
        $user = self::$forcedUser ?? $this->entityManager->getRepository(AppUser::class)->findOneBy(['email' => $token['email']]);
        if ($user === null) {
            return TaskApiAuthFailure::Invalid;
        }

        return new TaskApiPrincipal($user, $token['level'], $token['where'], 'token-id', 'token name');
    }

    public function consume(TaskApiPrincipal $principal): TaskApiRateLimitState
    {
        ++self::$consumed;

        return new TaskApiRateLimitState(self::$tokenLimitAccepts, 100, self::$tokenLimitAccepts ? 99 : 0, 1_790_000_000);
    }

    public function failedTokenState(string $clientIp): TaskApiRateLimitState
    {
        return new TaskApiRateLimitState(self::$ipLimitAccepts, 10, self::$ipLimitAccepts ? 10 : 0, 1_790_000_060);
    }

    public function registerFailedToken(string $clientIp): void
    {
        self::$failedIps[] = $clientIp;
    }

    public function allowsTasks(object $user, ?string $tenantKey): bool
    {
        return self::$planAllows && !in_array($tenantKey, self::$planRefusesTenants, true);
    }

    public function teams(TaskApiPrincipal $principal): array
    {
        return [new TaskApiTeam('team-1', 'Personal', '#000000', 'https://example.com/a.png'), new TaskApiTeam('team-2', 'Acme')];
    }

    public function spaces(TaskApiPrincipal $principal, string $teamId): array
    {
        return [];
    }

    public function space(TaskApiPrincipal $principal, string $spaceId): ?TaskApiSpace
    {
        return null;
    }

    public function spaceOfTenant(TaskApiPrincipal $principal, ?string $tenantKey): ?TaskApiSpace
    {
        return in_array($tenantKey, self::$tenants, true) ? new TaskApiSpace('space', 'Space', 'team-1', $tenantKey) : null;
    }

    public function apiId(object $user): string
    {
        return $user instanceof AppUser ? 'uuid-' . $user->getId() : 'unknown';
    }

    public function find(string $apiId): ?object
    {
        return null;
    }

    public function profiles(array $users, TaskApiPrincipal $viewer): array
    {
        $profiles = [];
        foreach (self::$profilesEmpty ? [] : $users as $user) {
            if ($user instanceof AppUser) {
                $profiles[$this->apiId($user)] = new TaskApiUser($this->apiId($user), $user->getEmail(), $user->getEmail());
            }
        }

        return $profiles;
    }
}
