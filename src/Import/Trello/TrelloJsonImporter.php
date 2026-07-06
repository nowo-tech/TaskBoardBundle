<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Import\Trello;

use InvalidArgumentException;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Import\Dto\ImportedTaskDto;
use Nowo\TaskBoardBundle\Import\Support\ImportFieldMapper;
use Nowo\TaskBoardBundle\Import\TaskImporterInterface;
use Nowo\TaskBoardBundle\Import\TaskImportSource;

use function is_array;
use function is_string;
use function json_decode;
use function json_last_error_msg;

/**
 * Imports cards from a Trello board JSON export.
 */
final readonly class TrelloJsonImporter implements TaskImporterInterface
{
    public function __construct(
        private ImportFieldMapper $fieldMapper = new ImportFieldMapper(),
    ) {
    }

    public function supports(TaskImportSource $source): bool
    {
        return $source === TaskImportSource::TrelloJson;
    }

    public function parse(string $content, string $filename): array
    {
        /** @var mixed $decoded */
        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            throw new InvalidArgumentException('Invalid Trello JSON: ' . json_last_error_msg());
        }

        $lists = [];
        foreach (($decoded['lists'] ?? []) as $list) {
            if (!is_array($list)) {
                continue;
            }

            $id = $this->stringValue($list['id'] ?? null);
            if ($id === '') {
                continue;
            }

            $lists[$id] = $this->stringValue($list['name'] ?? 'List');
        }

        $cards = $decoded['cards'] ?? [];
        if (!is_array($cards) || $cards === []) {
            throw new InvalidArgumentException('No cards found in the Trello JSON export.');
        }

        $tasks = [];
        foreach ($cards as $card) {
            if (!is_array($card)) {
                continue;
            }

            $title = $this->stringValue($card['name'] ?? null);
            if ($title === '') {
                continue;
            }

            $externalId = $this->stringValue($card['id'] ?? null);
            $listId     = $this->stringValue($card['idList'] ?? null);
            $status     = $lists[$listId] ?? null;
            $parentId   = $this->stringValue($card['idParent'] ?? $card['parent'] ?? null);

            $labels = [];
            foreach (($card['labels'] ?? []) as $label) {
                if (is_array($label)) {
                    $name = $this->stringValue($label['name'] ?? null);
                    if ($name !== '') {
                        $labels[] = $name;
                    }
                }
            }

            $tasks[] = new ImportedTaskDto(
                title: $title,
                externalId: $externalId !== '' ? $externalId : null,
                parentExternalId: $parentId !== '' ? $parentId : null,
                status: $status,
                priority: TaskPriority::Normal,
                description: $this->nullable($this->fieldMapper->stripHtml($this->stringValue($card['desc'] ?? null))),
                dueAt: $this->fieldMapper->parseDueDate($this->stringValue($card['due'] ?? null)),
                tags: $labels,
                sourceUrl: $this->nullable($this->stringValue($card['shortUrl'] ?? $card['url'] ?? null)),
            );
        }

        if ($tasks === []) {
            throw new InvalidArgumentException('No valid cards found in the Trello JSON export.');
        }

        return $tasks;
    }

    private function stringValue(mixed $value): string
    {
        if (is_string($value) || is_numeric($value)) {
            return trim((string) $value);
        }

        return '';
    }

    private function nullable(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
