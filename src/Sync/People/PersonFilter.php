<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\People;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncPersonMapping;

use function array_values;
use function mb_strtolower;
use function mb_substr;
use function trim;

/**
 * Since 1.25: the filters of {@see TaskSyncPersonRepository::forDirectory()} (contract §2.2): mapping states (empty =
 * every state), `q` (part of the name or of the e-mail, case-insensitive, at most 120 characters), `changed` (the
 * e-mail changed in the provider since it was first seen) and `changedSince` (the e-mail changed at or after that
 * date; implies `changed`).
 */
final readonly class PersonFilter
{
    public const MAX_QUERY_LENGTH = 120;

    /** The normalised search text ('' = no search). */
    public string $q;

    /**
     * @param list<TaskSyncPersonMapping> $mappings
     */
    public function __construct(
        public array $mappings = [],
        string $q = '',
        public bool $changed = false,
        public ?DateTimeImmutable $changedSince = null,
    ) {
        $this->q = mb_strtolower(mb_substr(trim($q), 0, self::MAX_QUERY_LENGTH));
    }

    /** People in one of these states. */
    public static function mappings(TaskSyncPersonMapping ...$mappings): self
    {
        return new self(array_values($mappings));
    }
}
