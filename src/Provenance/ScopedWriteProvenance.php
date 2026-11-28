<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Provenance;

/**
 * Since 3.7.0 (Beacon spec 235, FR-004): the {@see WriteProvenanceProviderInterface} the bundle's services receive. The
 * host's provider (`write_provenance_provider`) answers first, unchanged; when it says nothing (null, or no provider
 * configured), the channel its entry point declared through {@see WriteChannelScope} is used, without an actor label.
 * Null outside both: the columns stay NULL. Stateless (the scope keeps the call's channel).
 */
final readonly class ScopedWriteProvenance implements WriteProvenanceProviderInterface
{
    public function __construct(
        private WriteProvenanceProviderInterface $host,
        private WriteChannelScope $scope,
    ) {
    }

    public function current(): ?WriteProvenance
    {
        $provenance = $this->host->current();
        if ($provenance instanceof WriteProvenance) {
            return $provenance;
        }
        $channel = $this->scope->current();

        return $channel instanceof WriteChannel ? new WriteProvenance($channel) : null;
    }
}
