<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\People;

/**
 * Since 1.25: one remote person of a pre-scan, for the dialog's People step. `match` is what an automatic match
 * would do (`auto` = verified, `suggested` = unverified, `none`); `boardAccess` says whether the matched or mapped
 * user can view the board (false: the dialog warns *no access to this board*; a mapping never grants access). Names
 * and e-mails are personal data: shown in the host UI only, never logged.
 */
final readonly class ScannedPerson
{
    public const MATCH_AUTO      = 'auto';
    public const MATCH_SUGGESTED = 'suggested';
    public const MATCH_NONE      = 'none';

    /**
     * @param array<string, int> $counts occurrences per kind (member, creator, assignee, watcher, checklist, comment,
     *                                   time) in the scanned sample
     */
    public function __construct(
        public string $providerUserId,
        public string $name,
        public ?string $email,
        public ?string $role,
        public array $counts,
        public ?string $personId = null,
        public ?string $mapping = null,
        public string $match = self::MATCH_NONE,
        public ?string $matchedUserId = null,
        public ?bool $boardAccess = null,
    ) {
    }
}
