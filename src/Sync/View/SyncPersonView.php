<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\View;

/**
 * Since 1.25: a remote person as a template shows it — the creator of an imported task, the author of an imported
 * comment, a person "also assigned in ClickUp". `external` = no local user is written for them (unmatched,
 * suggested, external, or mapped to someone who cannot view the board); `boardAccess` false = mapped, *no access to
 * this board* (owner decision); `anonymised` = forgotten (the name is already the translated "Former … user #n").
 * No avatar URL is ever kept (Q-O7): the neutral avatar is the template's job.
 */
final readonly class SyncPersonView
{
    public function __construct(
        public string $personId,
        public string $name,
        public string $provider,
        public bool $external,
        public bool $anonymised,
        public ?bool $boardAccess = null,
        // Since 1.28.0: `name` is a host user's label shown for attribution only: no membership, no board access.
        public bool $attributionOnly = false,
    ) {
    }
}
