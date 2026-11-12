<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// Since 2.12.0: TimeTrackBundle 1.21's billable default port when the installed TimeTrack is older (require-dev ^1.11).
if (!interface_exists('Nowo\TimeTrackBundle\Integration\TimeEntryBillableDefaultProviderInterface')) {
    require __DIR__ . '/phpstan-stubs/Nowo/TimeTrackBundle/Integration/TimeEntryBillableDefaultProviderInterface.php';
}
