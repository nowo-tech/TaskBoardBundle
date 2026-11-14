<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Provenance;

use Nowo\TaskBoardBundle\Support\Text;

use function preg_replace;
use function trim;

/**
 * Since 2.18.0 (Beacon spec 234): the provenance of the write running now, as the host tells it through
 * {@see WriteProvenanceProviderInterface::current()}: its channel and a plain-text label of the actor (an API token's
 * name, "Claude via MCP", ...). Never a secret: the bundle stores the label and shows it.
 *
 * The label is plain text. Control and format characters are removed, the edges trimmed, and a label longer than
 * {@see self::ACTOR_LABEL_MAX} characters is **cut** to it (never refused: a long token name must not block a write); an
 * empty label is null. It is personal data when it names a person: see the account erasure in USAGE.
 */
final readonly class WriteProvenance
{
    /** The longest actor label (characters; the `actor_label` columns). */
    public const ACTOR_LABEL_MAX = 120;

    public ?string $actorLabel;

    public function __construct(
        public WriteChannel $channel,
        ?string $actorLabel = null,
    ) {
        $this->actorLabel = self::normalizeLabel($actorLabel);
    }

    /**
     * The label as the bundle stores it: plain text, control and format characters removed, trimmed, at most 120
     * characters; null when nothing is left. Applied again by the entities when they store it.
     */
    public static function normalizeLabel(?string $label): ?string
    {
        if ($label === null) {
            return null;
        }
        // Invalid UTF-8 gives null from preg_replace(): no label rather than a broken one.
        $label = trim((string) preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]+/u', '', $label));

        return $label === '' ? null : Text::cut($label, self::ACTOR_LABEL_MAX);
    }
}
