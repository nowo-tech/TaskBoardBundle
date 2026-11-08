<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\TaskBoardBundle\Api\Idempotency\DoctrineTaskApiIdempotencyStore;

/**
 * Since 2.2.0 (spec 213): the first answer of a write sent with an `Idempotency-Key`, kept 24 hours. Mapped only with
 * `api.enabled` and the bundle's own store. It holds hashes, never the token or the key; the answer may hold task text, so a
 * host forgets a token's rows when it revokes the token or erases its person.
 *
 * @internal the table of {@see DoctrineTaskApiIdempotencyStore}, which writes it with DBAL
 */
#[ORM\Entity]
#[ORM\Table(name: 'task_board_api_idempotency')]
#[ORM\Index(name: 'task_board_api_idempotency_expires_idx', columns: ['expires_at'])]
class TaskApiIdempotencyRecord
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 64)]
        public string $scope,
        #[ORM\Id]
        #[ORM\Column(name: 'key_hash', type: 'string', length: 64)]
        public string $keyHash,
        #[ORM\Column(type: 'string', length: 64)]
        public string $fingerprint,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        public DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'expires_at', type: 'datetime_immutable')]
        public DateTimeImmutable $expiresAt,
        #[ORM\Column(type: 'smallint', nullable: true)]
        public ?int $status = null,
        #[ORM\Column(type: 'text', nullable: true)]
        public ?string $body = null,
        #[ORM\Column(name: 'tenant_key', type: 'string', length: 191, nullable: true)]
        public ?string $tenantKey = null,
        #[ORM\Column(name: 'board_id', type: 'string', length: 36, nullable: true)]
        public ?string $boardId = null,
    ) {
    }
}
