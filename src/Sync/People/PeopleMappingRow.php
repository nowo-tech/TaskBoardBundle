<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\People;

use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncPerson;

/**
 * Since 1.27.0 (TB-SYNC-2): one row of the People page — the remote person, what is mapped now and what the select
 * starts on. `prefill` says where that value comes from: `mapped` (the current mapping), `email` (the e-mail matched
 * one candidate or the host suggested it), `name` (the normalised name matched exactly one candidate: check it),
 * `external` (kept as external person) or `none`. A pre-filled value is only applied when the page is saved.
 */
final readonly class PeopleMappingRow
{
    public const PREFILL_MAPPED   = 'mapped';
    public const PREFILL_EMAIL    = 'email';
    public const PREFILL_NAME     = 'name';
    public const PREFILL_EXTERNAL = 'external';
    public const PREFILL_NONE     = 'none';

    public function __construct(
        public TaskSyncPerson $person,
        /** The mapped user's id ('' = not mapped). */
        public string $currentUserId,
        /** The select's initial value ('' = Not mapped). */
        public string $selectedUserId,
        public string $prefill,
        /** The re-application to already imported content is not finished. */
        public bool $pending = false,
    ) {
    }

}
