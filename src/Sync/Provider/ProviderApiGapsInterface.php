<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Provider;

/**
 * Since 1.28.0 (TB-SYNC-4), optional: what the provider's API does not give, or what the bundle does not keep, so the
 * completeness report says it on every run. Unlike {@see FieldCoverageProviderInterface} these are not keys of an answer.
 */
interface ProviderApiGapsInterface
{
    /**
     * Name => reason (`not_exposed`, `not_supported`).
     *
     * @return array<string, string>
     */
    public function apiGaps(): array;
}
