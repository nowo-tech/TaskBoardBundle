<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Hook;

use Nowo\TaskBoardBundle\Sync\Time\ImportedTimeEntryCandidate;

/**
 * Since 1.28.0 (TB-SYNC-4): a sink that keeps the hours of people with no host user. A sink that implements this also
 * receives their entries: {@see ImportedTimeEntryCandidate::user()} is null and the candidate carries the person, so
 * nothing is lost for a former colleague. A sink that only implements {@see ImportedTimeEntrySinkInterface} behaves as
 * before: those entries stay out, counted in `time.skippedUnmapped`.
 *
 * With TimeTrackBundle 1.15 and later, a host writes them as `new ImportedTimeEntryPerson($candidate->person->getId(),
 * $candidate->personName(), $scopeKey)` and, once the person is mapped, `new ImportedTimeEntryClaim($user,
 * $candidate->person->getId(), $scopeKey)`: a plain user row on an entry with no owner is refused. TaskBoardBundle does
 * not depend on TimeTrackBundle.
 *
 * @deprecated since 2.15.0, implement the framework-free {@see OwnerlessTimeEntryReceiverInterface} instead (REQ-ARCH-001); removed in 3.0. A
 *             service implementing this interface is still asked through it, exactly as before.
 */
interface OwnerlessTimeEntrySinkInterface extends ImportedTimeEntrySinkInterface
{
}
