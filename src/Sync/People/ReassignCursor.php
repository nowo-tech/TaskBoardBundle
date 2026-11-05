<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\People;

use function is_numeric;
use function is_scalar;

/**
 * Since 1.25: where a re-association, re-projection or detach stopped (no personal data; serialisable with
 * {@see self::toArray()} for a Messenger message).
 */
final readonly class ReassignCursor
{
    public function __construct(
        public string $reassignId,
        public ?string $previousUserId = null,
        public int $personIndex = 0,
        public int $boardIndex = 0,
        public string $lastItemId = '',
    ) {
    }

    /**
     * @return array{reassignId: string, previousUserId: string|null, personIndex: int, boardIndex: int, lastItemId: string}
     */
    public function toArray(): array
    {
        return ['reassignId' => $this->reassignId, 'previousUserId' => $this->previousUserId, 'personIndex' => $this->personIndex, 'boardIndex' => $this->boardIndex, 'lastItemId' => $this->lastItemId];
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $string = static fn (mixed $value): string => is_scalar($value) ? (string) $value : '';

        return new self(
            $string($data['reassignId'] ?? ''),
            isset($data['previousUserId']) && is_scalar($data['previousUserId']) ? (string) $data['previousUserId'] : null,
            is_numeric($data['personIndex'] ?? null) ? (int) $data['personIndex'] : 0,
            is_numeric($data['boardIndex'] ?? null) ? (int) $data['boardIndex'] : 0,
            $string($data['lastItemId'] ?? ''),
        );
    }
}
