<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Configuration;

use InvalidArgumentException;
use JsonException;

use function array_is_list;
use function array_map;
use function array_values;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Since 2.18.0 (Beacon block W, "work method presets"): a board's configuration as data — readable and appliable — so a
 * host can store presets and copy them onto boards (no versioning or inheritance: a copy). Framework-free, serialisable to
 * and from an array or JSON ({@see self::toArray()}, {@see self::fromArray()}).
 *
 * - `columns`: order, name, type (and colour, icon) — {@see BoardColumnSpec};
 * - `fields`: the board's own custom fields with their options and colours — {@see BoardFieldSpec} (tenant-wide fields are
 *   the tenant's, not the board's, and are left out);
 * - `templates`: the board's task templates, as their own array shape (filled once task templates exist; kept as given);
 * - `numberPrefix`: the board's numbering prefix, null for `#12`.
 *
 * Read with {@see BoardConfigurationExporter}, applied with {@see BoardConfigurationApplier}.
 */
final readonly class BoardConfiguration
{
    public const VERSION = 1;

    /**
     * @param list<BoardColumnSpec> $columns
     * @param list<BoardFieldSpec> $fields
     * @param list<array<string, mixed>> $templates
     */
    public function __construct(
        public array $columns,
        public array $fields = [],
        public array $templates = [],
        public ?string $numberPrefix = null,
    ) {
        if ($columns === [] || !array_is_list($columns) || !array_is_list($fields) || !array_is_list($templates)) {
            throw new InvalidArgumentException('A configuration has at least one column; columns, fields and templates are lists.');
        }
    }

    /**
     * @return array{version: int, columns: list<array<string, mixed>>, fields: list<array<string, mixed>>, templates: list<array<string, mixed>>, numberPrefix: ?string}
     */
    public function toArray(): array
    {
        return [
            'version'      => self::VERSION,
            'columns'      => array_map(static fn (BoardColumnSpec $column): array => $column->toArray(), $this->columns),
            'fields'       => array_map(static fn (BoardFieldSpec $field): array => $field->toArray(), $this->fields),
            'templates'    => $this->templates,
            'numberPrefix' => $this->numberPrefix,
        ];
    }

    /**
     * @throws InvalidArgumentException for anything it cannot read (an unknown version, a missing column name…)
     */
    public static function fromArray(mixed $data): self
    {
        if (!is_array($data) || ($data['version'] ?? self::VERSION) !== self::VERSION) {
            throw new InvalidArgumentException('Not a board configuration of version ' . self::VERSION . '.');
        }
        $columns   = $data['columns'] ?? null;
        $fields    = $data['fields'] ?? [];
        $templates = $data['templates'] ?? [];
        $prefix    = $data['numberPrefix'] ?? null;
        if (!is_array($columns) || !is_array($fields) || !is_array($templates) || ($prefix !== null && !is_string($prefix))) {
            throw new InvalidArgumentException('Invalid board configuration.');
        }
        foreach ($templates as $template) {
            if (!is_array($template)) {
                throw new InvalidArgumentException('A template is an object.');
            }
        }

        /* @var list<array<string, mixed>> $templates */
        return new self(
            array_values(array_map(BoardColumnSpec::fromArray(...), $columns)),
            array_values(array_map(BoardFieldSpec::fromArray(...), $fields)),
            array_values($templates),
            $prefix,
        );
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function fromJson(string $json): self
    {
        try {
            return self::fromArray(json_decode($json, true, 16, JSON_THROW_ON_ERROR));
        } catch (JsonException $invalid) {
            throw new InvalidArgumentException('Invalid JSON.', 0, $invalid);
        }
    }
}
