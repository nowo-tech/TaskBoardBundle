<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Number\Entity;

use Doctrine\ORM\Mapping as ORM;
use Nowo\TaskBoardBundle\Number\TaskNumberReserver;

/**
 * Since 2.1.0 (spec 214, `task_numbers.enabled`): the last number handed out in a scope. Mapped so the schema tools know
 * the table; {@see TaskNumberReserver} is the only writer, with plain SQL in a short transaction of its own.
 */
#[ORM\Entity]
#[ORM\Table(name: self::TABLE)]
class TaskNumberCounter
{
    public const TABLE = 'task_board_number_counters';

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'scope_key', type: 'string', length: 191)]
        private string $scopeKey,
        #[ORM\Column(name: 'last_number', type: 'integer')]
        private int $lastNumber = 0,
    ) {
    }

    public function getScopeKey(): string
    {
        return $this->scopeKey;
    }

    public function getLastNumber(): int
    {
        return $this->lastNumber;
    }
}
