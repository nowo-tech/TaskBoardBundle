<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\ValueObject;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Port\Snapshots;

/**
 * Since 2.15.0 (REQ-ARCH-001 rules 1 and 3): a board as the framework-free host ports see it, copied when the port is
 * called. It holds no entity, no collection and no user object: the creator is its id (`getId()` as a string) and its
 * name snapshot.
 */
final readonly class TaskBoardSnapshot
{
    public function __construct(
        public string $id,
        public string $slug,
        public string $name,
        public ?string $tenantKey,
        public ?string $creatorId,
        public ?string $creatorName,
        public bool $archived,
        public DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * Since 3.0.0: the snapshot of a board row, for host code that holds a {@see TaskBoard} and asks a framework-free
     * port that takes the snapshot (for example `SyncActorProviderInterface::actorFor()`). Same mapping as the bundle's
     * own ports use. Reads getters only and keeps no reference to the entity.
     */
    public static function of(TaskBoard $board): self
    {
        return Snapshots::board($board);
    }
}
