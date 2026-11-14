<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Provenance;

/**
 * Since 2.18.0: the default {@see WriteProvenanceProviderInterface}: no provenance, the columns stay NULL. Stateless.
 */
final readonly class NoWriteProvenance implements WriteProvenanceProviderInterface
{
    public function current(): ?WriteProvenance
    {
        return null;
    }
}
