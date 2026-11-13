<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Support\TwinPorts;

use Nowo\TaskBoardBundle\Sync\Hook\ProviderCredentialResolverInterface;
use Nowo\TaskBoardBundle\Sync\Hook\ProviderCredentialSourceInterface;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCredential;
use Nowo\TaskBoardBundle\ValueObject\TaskBoardSnapshot;

final readonly class TwinCredentialSource implements ProviderCredentialSourceInterface
{
    public function __construct(private ProviderCredentialResolverInterface $legacy, private TwinLookup $lookup)
    {
    }

    public function connectionsFor(TaskBoardSnapshot $board, object $user, string $provider): array
    {
        return $this->legacy->connectionsFor($this->lookup->board($board->id), $user, $provider);
    }

    public function credentialFor(TaskBoardSnapshot $board, string $connectionRef): ?ProviderCredential
    {
        return $this->legacy->credentialFor($this->lookup->board($board->id), $connectionRef);
    }

    public function credentialFormUrl(TaskBoardSnapshot $board, string $provider): ?string
    {
        return $this->legacy->credentialFormUrl($this->lookup->board($board->id), $provider);
    }
}
