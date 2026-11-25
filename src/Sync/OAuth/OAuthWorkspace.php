<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\OAuth;

/** Since 3.1.0 (CU-1): one workspace the connecting user authorised on the provider's consent screen. */
final readonly class OAuthWorkspace
{
    public function __construct(
        public string $id,
        public string $name,
    ) {
    }
}
