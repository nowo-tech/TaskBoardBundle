<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Event;

use function in_array;

/**
 * Since 2.10.0: {@see TaskBoardEvents::COLUMN_UPDATED} — a column's name, colour or icon changed (a rename is
 * {@see self::isRenamed()}). Not dispatched when a save changes nothing.
 */
final class ColumnUpdatedEvent extends AbstractColumnEvent
{
    public const FIELD_NAME  = 'name';
    public const FIELD_COLOR = 'color';
    public const FIELD_ICON  = 'icon';

    /** Since 2.19.0 (FR-033). */
    public const FIELD_TYPE = 'type';
    /** Since 2.16.0 (spec 234): the column's humans-only flag. */
    public const FIELD_HUMANS_ONLY = 'humansOnly';

    /**
     * @param list<string> $changedFields of `name`, `color`, `icon`
     */
    public function __construct(
        string $boardId,
        ?string $tenantKey,
        public readonly string $columnId,
        public readonly string $name,
        public readonly string $previousName,
        public readonly array $changedFields,
        ?string $actorId,
        ?string $cause = null,
    ) {
        parent::__construct($boardId, $tenantKey, $actorId, $cause);
    }

    public function isRenamed(): bool
    {
        return in_array(self::FIELD_NAME, $this->changedFields, true);
    }
}
