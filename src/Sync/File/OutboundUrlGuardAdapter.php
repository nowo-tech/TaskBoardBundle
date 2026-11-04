<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\File;

use Nowo\OutboundUrlGuardBundle\Guard\OutboundUrlGuard;
use Nowo\TaskBoardBundle\Sync\Exception\UnsafeRemoteFileException;
use Throwable;

/**
 * Since 1.23: {@see UrlGuardInterface} through nowo-tech/outbound-url-guard-bundle (registered when it is installed):
 * private, reserved and metadata addresses are refused and the resolved address is pinned.
 */
final readonly class OutboundUrlGuardAdapter implements UrlGuardInterface
{
    public function __construct(
        private OutboundUrlGuard $guard,
    ) {
    }

    public function optionsFor(string $url): array
    {
        try {
            return $this->guard->httpClientOptions($url, false);
        } catch (Throwable) {
            throw new UnsafeRemoteFileException(UnsafeRemoteFileException::HOST_NOT_ALLOWED);
        }
    }
}
