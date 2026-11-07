<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Api\App;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiAuthenticatorInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiPlanGateInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiRateLimiterInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiTimeEntriesInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiUsersInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiWorkspaceInterface;
use Nowo\TaskBoardBundle\Api\TaskApiAuthFailure;
use Nowo\TaskBoardBundle\Api\TaskApiCredential;
use Nowo\TaskBoardBundle\Api\TaskApiLevel;
use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\Api\TaskApiRefusal;
use Nowo\TaskBoardBundle\Api\TaskApiWhere;
use Nowo\TaskBoardBundle\Api\Value\TaskApiNewTimeEntry;
use Nowo\TaskBoardBundle\Api\Value\TaskApiRateLimitState;
use Nowo\TaskBoardBundle\Api\Value\TaskApiSpace;
use Nowo\TaskBoardBundle\Api\Value\TaskApiTeam;
use Nowo\TaskBoardBundle\Api\Value\TaskApiTimeEntry;
use Nowo\TaskBoardBundle\Api\Value\TaskApiTimePage;
use Nowo\TaskBoardBundle\Api\Value\TaskApiTimeQuery;
use Nowo\TaskBoardBundle\Api\Value\TaskApiUser;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use Symfony\Component\HttpFoundation\Request;

use function array_map;
use function in_array;

/**
 * The host side of the API in the tests: one scriptable object behind every hook. Its state is static, as a test sets it
 * before the kernel handles the request.
 */
final class ApiTestHost implements TaskApiAuthenticatorInterface, TaskApiRateLimiterInterface, TaskApiPlanGateInterface, TaskApiWorkspaceInterface, TaskApiUsersInterface, TaskApiTimeEntriesInterface
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

    /** @var list<string> people the host does not know */
    public static array $unknownEmails = [];

    /** @var list<TaskApiTimeQuery> */
    public static array $timeQueries = [];

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
        self::$timeQueries        = [];
        self::$unknownEmails      = [];
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

    /** Every tenant the person reaches is a space of team-1; team-2 has none. */
    public function spaces(TaskApiPrincipal $principal, string $teamId): array
    {
        return $teamId === 'team-1' ? array_map(self::spaceOf(...), self::$tenants) : [];
    }

    public function space(TaskApiPrincipal $principal, string $spaceId): ?TaskApiSpace
    {
        foreach (self::$tenants as $tenant) {
            if (self::spaceOf($tenant)->id === $spaceId) {
                return self::spaceOf($tenant);
            }
        }

        return null;
    }

    public function spaceOfTenant(TaskApiPrincipal $principal, ?string $tenantKey): ?TaskApiSpace
    {
        return in_array($tenantKey, self::$tenants, true) ? self::spaceOf($tenantKey) : null;
    }

    public static function spaceOf(?string $tenantKey): TaskApiSpace
    {
        return new TaskApiSpace('space-' . ($tenantKey ?? 'none'), 'Space ' . ($tenantKey ?? 'none'), 'team-1', $tenantKey);
    }

    public function list(TaskApiPrincipal $principal, TaskApiTimeQuery $query): TaskApiTimePage
    {
        self::$timeQueries[] = $query;

        return new TaskApiTimePage([new TaskApiTimeEntry('entry-1', $this->apiId($principal->user), new DateTimeImmutable('@1790000000'), 3600, $query->taskId, 'Review')]);
    }

    public function log(TaskApiPrincipal $principal, ?Task $task, TaskApiNewTimeEntry $entry): TaskApiTimeEntry
    {
        throw new LogicException('Not in the read tests.');
    }

    public function apiId(object $user): string
    {
        if ($user instanceof AppUser && in_array($user->getEmail(), self::$unknownEmails, true)) {
            throw TaskApiRefusal::notFound();
        }

        return $user instanceof AppUser ? 'uuid-' . $user->getId() : 'unknown';
    }

    public function find(string $apiId): ?object
    {
        return str_starts_with($apiId, 'uuid-') ? $this->entityManager->find(AppUser::class, (int) substr($apiId, 5)) : null;
    }

    public function profiles(array $users, TaskApiPrincipal $viewer): array
    {
        $profiles = [];
        foreach (self::$profilesEmpty ? [] : $users as $user) {
            if ($user instanceof AppUser && !in_array($user->getEmail(), self::$unknownEmails, true)) {
                $profiles[$this->apiId($user)] = new TaskApiUser($this->apiId($user), $user->getEmail(), $user->getEmail());
            }
        }

        return $profiles;
    }
}
