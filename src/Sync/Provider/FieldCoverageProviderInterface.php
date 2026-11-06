<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Provider;

/**
 * Since 1.27.0 (TB-SYNC-3): a provider that declares which keys of its task answers the bundle imports, for the
 * completeness report of each run. A key the provider returns with a value but does not declare is listed as
 * *Not imported yet*.
 */
interface FieldCoverageProviderInterface
{
    public const IMPORTED = 'imported';

    /**
     * Answer key => `imported` or the reason it is not (`not_supported`, `not_needed`, `internal`).
     *
     * @return array<string, string>
     */
    public function fieldCoverage(): array;
}
