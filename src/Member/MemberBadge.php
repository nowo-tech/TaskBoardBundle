<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Member;

use InvalidArgumentException;

use function is_string;
use function mb_strlen;
use function preg_match;
use function trim;

/**
 * Since 2.18.0 (Beacon spec 234): a pill the host shows next to a member's name (e.g. "Agent" for a service account), as
 * {@see MemberBadgeProviderInterface} gives it. Plain text only: the templates print `label` and `title` escaped, so a
 * host never hands the bundle HTML. `variant` is an allow-list ({@see MemberBadgeVariant}).
 *
 * Refused (`InvalidArgumentException`): an empty label, a label over {@see self::LABEL_MAX} or a title over
 * {@see self::TITLE_MAX} characters, invalid UTF-8, control or format characters, an unknown variant.
 */
final readonly class MemberBadge
{
    public const LABEL_MAX = 40;
    public const TITLE_MAX = 120;

    public string $label;
    public MemberBadgeVariant $variant;
    public ?string $title;

    public function __construct(string $label, MemberBadgeVariant|string $variant = MemberBadgeVariant::Neutral, ?string $title = null)
    {
        $label = trim($label);
        if (!$this->isPlain($label) || $label === '' || mb_strlen($label) > self::LABEL_MAX) {
            throw new InvalidArgumentException('A member badge label is plain text of 1 to 40 characters.');
        }
        $resolved = is_string($variant) ? MemberBadgeVariant::tryFrom($variant) : $variant;
        if (!$resolved instanceof MemberBadgeVariant) {
            throw new InvalidArgumentException('A member badge variant is one of neutral, info, success, warning, agent.');
        }
        $title = $title === null ? null : trim($title);
        if ($title !== null && (!$this->isPlain($title) || mb_strlen($title) > self::TITLE_MAX)) {
            throw new InvalidArgumentException('A member badge title is plain text of at most 120 characters.');
        }
        $this->label   = $label;
        $this->variant = $resolved;
        $this->title   = $title === '' ? null : $title;
    }

    /** Valid UTF-8 without control or format characters. */
    private function isPlain(string $text): bool
    {
        return preg_match('/^[^\p{Cc}\p{Cf}\p{Zl}\p{Zp}]*$/u', $text) === 1;
    }
}
