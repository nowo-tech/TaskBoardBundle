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
        /**
         * Since 2.19.0 (audit PW-H1): the person or service account that made the change (null: a worker, an import), so
         * a listener that writes more on its behalf (the shared status set of a folder) can ask its rights. Not for
         * serialisation: copy `actorId`.
         */
        public readonly ?object $actor = null,
    ) {
    }
}
