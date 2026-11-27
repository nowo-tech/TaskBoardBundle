<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Api\App;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiIdempotencyStoreInterface;
use Nowo\TaskBoardBundle\Api\TaskApiWhere;
use Nowo\TaskBoardBundle\Api\Value\TaskApiIdempotencyClaim;
use Nowo\TaskBoardBundle\Api\Value\TaskApiIdempotencyOutcome;
use Nowo\TaskBoardBundle\ClientApi\Contract\TaskClientContextPolicyInterface;
use Nowo\TaskBoardBundle\ClientApi\Contract\TaskClientFileResolverInterface;
use Nowo\TaskBoardBundle\ClientApi\Contract\TaskClientPrincipalResolverInterface;
use Nowo\TaskBoardBundle\ClientApi\Contract\TaskClientReferenceResolverInterface;
use Nowo\TaskBoardBundle\ClientApi\Contract\TaskClientSourceLinkInterface;
use Nowo\TaskBoardBundle\ClientApi\Exception\TaskBoardModuleForbiddenException;
use Nowo\TaskBoardBundle\ClientApi\Model\TaskClientContext;
use Nowo\TaskBoardBundle\ClientApi\Model\TaskClientPrincipal;
use Nowo\TaskBoardBundle\ClientApi\Model\TaskClientReference;
use Nowo\TaskBoardBundle\ClientApi\Model\TaskClientRequest;
use Nowo\TaskBoardBundle\ClientApi\Model\TaskClientSource;
use Nowo\TaskBoardBundle\ClientApi\Model\TaskClientSourceLink;
use Nowo\TaskBoardBundle\Link\TaskObjectLinkVisibilityInterface;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use RuntimeException;

use function array_keys;
use function in_array;

/**
 * Since 3.4.0: the host side of the client API in the tests — one scriptable object behind every client port, with
 * static state a test sets before the kernel handles the request (as {@see ApiTestHost}).
 */
final class ClientApiTestHost implements TaskApiIdempotencyStoreInterface, TaskClientPrincipalResolverInterface, TaskClientContextPolicyInterface, TaskClientReferenceResolverInterface, TaskObjectLinkVisibilityInterface, TaskClientFileResolverInterface, TaskClientSourceLinkInterface
{
    /** @var array<string, array{email: string, scopes: list<string>, id: string, context?: string|null, forbidden?: bool}> by bearer */
    public static array $tokens = [];

    /** @var array<string, array<string, list<string>>> context key => tenant keys, by e-mail */
    public static array $contexts = [];

    /** @var array<string, array<string, TaskApiWhere>> a `where` of its own per context key, by e-mail (else limitedTo the tenants) */
    public static array $wheres = [];

    /** @var array<string, string> `type:id` => title the reader may see */
    public static array $references = [];

    /** @var list<string> `type:id` of host objects the reader may see */
    public static array $visibleLinks = [];

    /** @var list<string> file ids the reader may open */
    public static array $files = [];

    /** @var array<string, TaskClientSourceLink> by `type:messageUuid` */
    public static array $sources = [];

    /** @var list<string> the bearers the resolver was asked for */
    public static array $resolved = [];

    /** Since 3.4.0: the scripted idempotency store (`api.hooks.idempotency_store: app.client_host`): the next claim's outcome. */
    public static ?TaskApiIdempotencyOutcome $claim = null;

    public static bool $completeFails = false;

    /** @var list<array{0: int, 1: string}> status and body of each completed answer */
    public static array $completed = [];

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public static function reset(): void
    {
        self::$tokens        = [];
        self::$contexts      = [];
        self::$wheres        = [];
        self::$claim         = null;
        self::$completeFails = false;
        self::$completed     = [];
        self::$references    = [];
        self::$visibleLinks  = [];
        self::$files         = [];
        self::$sources       = [];
        self::$resolved      = [];
    }

    public function resolve(string $bearer, TaskClientRequest $request): ?TaskClientPrincipal
    {
        self::$resolved[] = $bearer;
        $token            = self::$tokens[$bearer] ?? null;
        if ($token === null) {
            return null;
        }
        $user = $this->entityManager->getRepository(AppUser::class)->findOneBy(['email' => $token['email']]);

        if ($user === null) {
            return null;
        }
        $principal = new TaskClientPrincipal($user, $token['scopes'], $token['id'], 'Beacon Desktop · Test', $token['context'] ?? null);
        if ($token['forbidden'] ?? false) {
            throw new TaskBoardModuleForbiddenException($principal);
        }

        return $principal;
    }

    public function contextsFor(TaskClientPrincipal $principal): array
    {
        $contexts = [];
        foreach (array_keys(self::$contexts[$this->email($principal)] ?? []) as $key) {
            $contexts[] = new TaskClientContext($key, $key === 'personal' ? 'Personal' : 'Org ' . $key);
        }

        return $contexts;
    }

    public function allows(TaskClientPrincipal $principal, string $contextKey): bool
    {
        return isset(self::$contexts[$this->email($principal)][$contextKey]);
    }

    public function whereFor(TaskClientPrincipal $principal, string $contextKey): TaskApiWhere
    {
        return self::$wheres[$this->email($principal)][$contextKey] ?? TaskApiWhere::limitedTo(self::$contexts[$this->email($principal)][$contextKey] ?? [], []);
    }

    public function reference(object $reader, string $type, string $id): ?TaskClientReference
    {
        $title = self::$references[$type . ':' . $id] ?? null;

        return $title === null ? null : new TaskClientReference($type, $id, $title, '/issues/' . $id);
    }

    public function visibleTo(object $viewer, string $objectType, string $objectId): bool
    {
        return in_array($objectType . ':' . $objectId, self::$visibleLinks, true);
    }

    public function canView(object $reader, string $fileId): bool
    {
        return in_array($fileId, self::$files, true);
    }

    public function link(TaskClientPrincipal $principal, TaskClientSource $source): ?TaskClientSourceLink
    {
        return self::$sources[$source->type . ':' . ($source->fields['messageUuid'] ?? '')] ?? null;
    }

    private function email(TaskClientPrincipal $principal): string
    {
        return $principal->user instanceof AppUser ? $principal->user->getEmail() : '';
    }

    public function claim(string $scope, string $keyHash, string $fingerprint, DateTimeImmutable $expiresAt): TaskApiIdempotencyClaim
    {
        return new TaskApiIdempotencyClaim(self::$claim ?? TaskApiIdempotencyOutcome::Claimed);
    }

    public function complete(string $scope, string $keyHash, int $status, string $body, ?string $tenantKey, ?string $boardId): void
    {
        if (self::$completeFails) {
            throw new RuntimeException('store down');
        }
        self::$completed[] = [$status, $body];
    }

    public function release(string $scope, string $keyHash): void
    {
    }

    public function forget(string $scope): void
    {
    }

    public function purgeExpired(DateTimeImmutable $now): int
    {
        return 0;
    }
}
