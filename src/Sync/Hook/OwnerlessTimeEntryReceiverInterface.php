<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Hook;

/**
 * Since 2.15.0 (REQ-ARCH-001 rule 1): the framework-free twin of `OwnerlessTimeEntrySinkInterface` (the 2.14 interface, removed in 3.0): a receiver that
 * also gets the hours of people with no host user (`user()` null, the person's id and name on the candidate).
 */
interface OwnerlessTimeEntryReceiverInterface extends ImportedTimeEntryReceiverInterface
{
}
