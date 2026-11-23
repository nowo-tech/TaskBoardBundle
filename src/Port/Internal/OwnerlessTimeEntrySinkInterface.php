<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Port\Internal;

use Nowo\TaskBoardBundle\Sync\Hook\OwnerlessTimeEntryReceiverInterface;
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
 * Since 3.0.0 the bundle's own entity-typed seam (REQ-ARCH-001 rule 3): it was the host port `Nowo\TaskBoardBundle\Sync\Hook\OwnerlessTimeEntrySinkInterface`,
 * deprecated in 2.15.0 and removed in 3.0. Hosts implement the framework-free {@see OwnerlessTimeEntryReceiverInterface};
 * the bundle asks it through its gate, and its own defaults implement this seam. A class outside the bundle that
 * implements this interface is refused when the container is built.
 *
 * @internal
 */
interface OwnerlessTimeEntrySinkInterface extends ImportedTimeEntrySinkInterface
{
}
