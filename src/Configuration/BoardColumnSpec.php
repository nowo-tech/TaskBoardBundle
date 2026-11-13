<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Configuration;

use InvalidArgumentException;
use Nowo\TaskBoardBundle\Enum\ColumnType;

use function is_array;
use function is_bool;
use function is_string;
use function mb_strlen;
use function trim;

/**
 * Since 2.17.0 (Beacon spec 234, FR-033 / FR-034): one column of a board configuration or of a folder's shared status set,
 * as data: name, colour, icon and type. Framework-free and serialisable ({@see self::toArray()} / {@see self::fromArray()}).
 */
final readonly class BoardColumnSpec
{
    public const NAME_MAX = 128;

    public function __construct(
        public string $name,
        public ColumnType $type = ColumnType::Custom,
        public ?string $color = null,
        public ?string $icon = null,
    ) {
        $trimmed = trim($name);
        if ($trimmed === '' || mb_strlen($trimmed) > self::NAME_MAX || $trimmed !== $name) {
            throw new InvalidArgumentException('A column name is 1 to ' . self::NAME_MAX . ' characters, without edge spaces.');
        }
    }

    /**
     * @return array{name: string, type: string, color: ?string, icon: ?string}
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'type' => $this->type->value, 'color' => $this->color, 'icon' => $this->icon];
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function fromArray(mixed $data): self
    {
        if (!is_array($data) || !is_string($data['name'] ?? null)) {
            throw new InvalidArgumentException('A column needs a name.');
        }
        $type = ColumnType::tryFrom(is_string($data['type'] ?? null) ? $data['type'] : ColumnType::Custom->value);
        if (!$type instanceof ColumnType) {
            throw new InvalidArgumentException('Unknown column type.');
        }
        $color = $data['color'] ?? null;
        $icon  = $data['icon'] ?? null;
        if (($color !== null && !is_string($color)) || ($icon !== null && !is_string($icon)) || (isset($data['humansOnly']) && !is_bool($data['humansOnly']))) {
            throw new InvalidArgumentException('Invalid column colour or icon.');
        }

        return new self($data['name'], $type, $color, $icon);
    }
}
