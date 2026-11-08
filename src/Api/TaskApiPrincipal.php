<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api;

/**
 * Since 2.2.0 (spec 213): who a request is, as the host's authenticator answers it, for this request only. It holds no
 * secret: `$tokenId` is an opaque id.
 */
final readonly class TaskApiPrincipal
{
    public function __construct(
        public object $user,
        public TaskApiLevel $level,
        public TaskApiWhere $where,
        public string $tokenId,
        public string $tokenName,
    ) {
    }
}
