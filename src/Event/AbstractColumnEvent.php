<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Event;

use Nowo\TaskBoardBundle\Service\TaskChangeContext;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Since 2.10.0 (spec 216, DDD audit G2/G3): a board's columns changed, with ids only. Dispatched after the write is saved
 * (after the commit of a sync run or an import that adds columns). `actorId` is null for a write with no user (a sync run
 * from a worker); `cause` is {@see TaskChangeContext::current()} when the write ran.
 */
abstract class AbstractColumnEvent extends Event
{
    public function __construct(
        public readonly string $boardId,
        public readonly ?string $tenantKey,
        public readonly ?string $actorId,
        public readonly ?string $cause,
    ) {
    }
}
