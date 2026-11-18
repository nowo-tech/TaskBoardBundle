<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\App;

use Nowo\TaskBoardBundle\Provenance\WriteProvenance;
use Nowo\TaskBoardBundle\Provenance\WriteProvenanceProviderInterface;
use RuntimeException;

/**
 * A provenance provider that fails when a test says so: a write that fails after its rows were persisted, before the
 * flush (the manager stays open).
 */
final class SwitchableWriteProvenance implements WriteProvenanceProviderInterface
{
    public static bool $fail = false;

    public function current(): ?WriteProvenance
    {
        if (self::$fail) {
            throw new RuntimeException('Provenance unavailable');
        }

        return null;
    }
}
