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
 */
interface OwnerlessTimeEntrySinkInterface extends ImportedTimeEntrySinkInterface
{
}
