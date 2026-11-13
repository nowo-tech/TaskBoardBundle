<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Template;

use InvalidArgumentException;

use function array_is_list;
use function array_key_exists;
use function is_array;
use function is_string;

/**
 * Since 2.16.0 (Beacon spec 234, FR-061): what a template says, as {@see TaskTemplateManager} creates, changes or
 * replaces it. Plain input: the manager trims and checks it ({@see InvalidTaskTemplateException}).
 *
 * Board template: the default column is given by id (a column of the template's board) or, to carry a template to
 * another board (a configuration preset), by its exact name: the id is tried first, then the name; an unknown column is
 * refused. Tenant template: only by name, kept as given and matched exactly on the board a task is created on (no
 * match: the board's first column).
 */
final class TaskTemplateData
{
    /**
     * @param list<string> $defaultTags
     */
    public function __construct(
        public string $name = '',
        /** The Markdown description body; null or blank = none. */
        public ?string $body = null,
        public array $defaultTags = [],
        /** A column id of the template's board (board templates only); null = by name, or the board's first column. */
        public ?string $defaultColumnId = null,
        /** The exact name of the default column: of the template's board (when no id is given), or a tenant template's. */
        public ?string $defaultColumnName = null,
    ) {
    }

    /**
     * From the array {@see TaskTemplateSnapshot::toArray()} gives (`name`, `body`, `default_tags`, `default_column_id`,
     * `default_column_name`; only `name` is required). Other keys (`id`, `scope`, ...) are ignored.
     *
     * @param array<string, mixed> $data
     *
     * @throws InvalidArgumentException when a key has the wrong type
     */
    public static function fromArray(array $data): self
    {
        $tags = $data['default_tags'] ?? [];
        if (!is_array($tags) || !array_is_list($tags)) {
            throw new InvalidArgumentException('A template\'s "default_tags" is a list of strings.');
        }
        $strings = [];
        foreach ($tags as $tag) {
            $strings[] = is_string($tag) ? $tag : throw new InvalidArgumentException('A template\'s "default_tags" is a list of strings.');
        }

        return new self(
            name: self::text($data, 'name') ?? throw new InvalidArgumentException('A template needs a "name".'),
            body: self::text($data, 'body'),
            defaultTags: $strings,
            defaultColumnId: self::text($data, 'default_column_id'),
            defaultColumnName: self::text($data, 'default_column_name'),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function text(array $data, string $key): ?string
    {
        if (!array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }

        return is_string($data[$key]) ? $data[$key] : throw new InvalidArgumentException('A template\'s "' . $key . '" is a string or null.');
    }
}
