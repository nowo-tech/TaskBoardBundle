<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Hook;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Api\Value\TaskApiIdempotencyClaim;

/**
 * Since 2.2.0 (spec 213): where the first answer of a write with an `Idempotency-Key` is kept (FR-015). The first caller of
 * a key wins, atomically. `$scope` is a hash of the token id and `$keyHash` of the key: no secret ever reaches a store.
 */
interface TaskApiIdempotencyStoreInterface
{
    public function claim(string $scope, string $keyHash, string $fingerprint, DateTimeImmutable $expiresAt): TaskApiIdempotencyClaim;

    public function complete(string $scope, string $keyHash, int $status, string $body, ?string $tenantKey, ?string $boardId): void;

    public function release(string $scope, string $keyHash): void;

    /** A token revoked or an account erased: its stored answers hold task text. */
    public function forget(string $scope): void;

    public function purgeExpired(DateTimeImmutable $now): int;
}
