<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Configuration;

use InvalidArgumentException;
use Nowo\TaskBoardBundle\Enum\CustomFieldType;

use function array_is_list;
use function is_array;
use function is_string;
use function mb_strlen;
use function trim;

/**
 * Since 2.19.0 (Beacon block W): one board custom field of a {@see BoardConfiguration} — name, type and, for a dropdown,
 * its options (name and colour). Framework-free and serialisable.
 */
final readonly class BoardFieldSpec
{
    /**
     * @param list<array{name: string, color: ?string}> $options
     */
    public function __construct(
        public string $name,
        public CustomFieldType $type,
        public array $options = [],
    ) {
        if (trim($name) === '' || mb_strlen($name) > 128) {
            throw new InvalidArgumentException('A field name is 1 to 128 characters.');
        }
        if ($type !== CustomFieldType::Dropdown && $options !== []) {
            throw new InvalidArgumentException('Only a dropdown field has options.');
        }
    }

    /**
     * @return array{name: string, type: string, options: list<array{name: string, color: ?string}>}
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'type' => $this->type->value, 'options' => $this->options];
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function fromArray(mixed $data): self
    {
        if (!is_array($data) || !is_string($data['name'] ?? null) || !is_string($data['type'] ?? null)) {
            throw new InvalidArgumentException('A field needs a name and a type.');
        }
        $type = CustomFieldType::tryFrom($data['type']) ?? throw new InvalidArgumentException('Unknown field type.');
        $raw  = $data['options'] ?? [];
        if (!is_array($raw) || !array_is_list($raw)) {
            throw new InvalidArgumentException('Field options are a list.');
        }
        $options = [];
        foreach ($raw as $option) {
            $color = is_array($option) ? ($option['color'] ?? null) : null;
            if (!is_array($option) || !is_string($option['name'] ?? null) || ($color !== null && !is_string($color))) {
                throw new InvalidArgumentException('An option needs a name.');
            }
            $options[] = ['name' => $option['name'], 'color' => $color];
        }

        return new self($data['name'], $type, $options);
    }
}
