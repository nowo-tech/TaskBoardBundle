<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Idempotency;

use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiIdempotencyStoreInterface;
use Nowo\TaskBoardBundle\Api\Value\TaskApiIdempotencyClaim;
use Nowo\TaskBoardBundle\Api\Value\TaskApiIdempotencyOutcome;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;

use function is_numeric;
use function is_string;

/**
 * Since 2.2.0 (spec 213): the bundle's idempotency store, one row per token and key in `task_board_api_idempotency`. The
 * primary key makes the first claim win. A claim left unfinished for {@see self::ABANDONED_SECONDS} (a process that died)
 * is taken over. Expired rows go on each claim, and with `nowo:task-board:api:purge-idempotency`.
 */
final readonly class DoctrineTaskApiIdempotencyStore implements TaskApiIdempotencyStoreInterface
{
    public const TABLE = 'task_board_api_idempotency';

    public const ABANDONED_SECONDS = 300;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock = new NativeClock(),
    ) {
    }

    public function claim(string $scope, string $keyHash, string $fingerprint, DateTimeImmutable $expiresAt): TaskApiIdempotencyClaim
    {
        $now = $this->clock->now();
        $this->purgeExpired($now);
        $connection = $this->entityManager->getConnection();
        try {
            $connection->insert(self::TABLE, [
                'scope'       => $scope,
                'key_hash'    => $keyHash,
                'fingerprint' => $fingerprint,
                'created_at'  => $now,
                'expires_at'  => $expiresAt,
            ], ['created_at' => Types::DATETIME_IMMUTABLE, 'expires_at' => Types::DATETIME_IMMUTABLE]);

            return new TaskApiIdempotencyClaim(TaskApiIdempotencyOutcome::Claimed);
        } catch (UniqueConstraintViolationException) {
        }

        /** @var array{fingerprint: string, status: mixed, body: mixed, tenant_key: mixed, board_id: mixed, created_at: string}|false $row */
        $row = $connection->fetchAssociative('SELECT fingerprint, status, body, tenant_key, board_id, created_at FROM ' . self::TABLE . ' WHERE scope = ? AND key_hash = ?', [$scope, $keyHash]);
        if ($row !== false && $row['fingerprint'] !== $fingerprint) {
            return new TaskApiIdempotencyClaim(TaskApiIdempotencyOutcome::Mismatch);
        }
        if ($row === false || !is_numeric($row['status'])) {
            // No row: purged by another request in between, so the client's next try decides. A recent claim still runs.
            if ($row === false || $now->getTimestamp() - (new DateTimeImmutable($row['created_at']))->getTimestamp() < self::ABANDONED_SECONDS) {
                return new TaskApiIdempotencyClaim(TaskApiIdempotencyOutcome::InFlight);
            }
            $taken = $connection->executeStatement(
                'UPDATE ' . self::TABLE . ' SET created_at = ? WHERE scope = ? AND key_hash = ? AND status IS NULL AND created_at = ?',
                [$now, $scope, $keyHash, $row['created_at']],
                [Types::DATETIME_IMMUTABLE, Types::STRING, Types::STRING, Types::STRING],
            );

            return new TaskApiIdempotencyClaim($taken === 1 ? TaskApiIdempotencyOutcome::Claimed : TaskApiIdempotencyOutcome::InFlight);
        }

        return new TaskApiIdempotencyClaim(
            TaskApiIdempotencyOutcome::Completed,
            (int) $row['status'],
            is_string($row['body']) ? $row['body'] : '',
            is_string($row['tenant_key']) ? $row['tenant_key'] : null,
            is_string($row['board_id']) ? $row['board_id'] : null,
        );
    }

    public function complete(string $scope, string $keyHash, int $status, string $body, ?string $tenantKey, ?string $boardId): void
    {
        $this->entityManager->getConnection()->update(self::TABLE, ['status' => $status, 'body' => $body, 'tenant_key' => $tenantKey, 'board_id' => $boardId], ['scope' => $scope, 'key_hash' => $keyHash]);
    }

    public function release(string $scope, string $keyHash): void
    {
        $this->entityManager->getConnection()->executeStatement('DELETE FROM ' . self::TABLE . ' WHERE scope = ? AND key_hash = ? AND status IS NULL', [$scope, $keyHash]);
    }

    public function forget(string $scope): void
    {
        $this->entityManager->getConnection()->delete(self::TABLE, ['scope' => $scope]);
    }

    public function purgeExpired(DateTimeImmutable $now): int
    {
        return (int) $this->entityManager->getConnection()->executeStatement('DELETE FROM ' . self::TABLE . ' WHERE expires_at < ?', [$now], [Types::DATETIME_IMMUTABLE]);
    }
}
