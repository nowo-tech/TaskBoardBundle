<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Integration;

/*
 * Since 2.12.0: TimeTrackBundle 1.21's billable default port, for PHPStan and the tests while the installed TimeTrack is
 * older (require-dev ^1.11). tests/bootstrap.php loads it only when the real interface is missing. Remove it once the
 * require-dev constraint is ^1.21.
 */
if (!interface_exists(TimeEntryBillableDefaultProviderInterface::class, false)) {
    interface TimeEntryBillableDefaultProviderInterface
    {
        public function defaultFor(string $scopeKey, ?string $taskRef): ?bool;
    }
}
