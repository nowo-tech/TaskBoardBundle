<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Template;

use Nowo\TaskBoardBundle\Template\Entity\TaskTemplate;

/**
 * Since 2.16.0 (Beacon spec 234, FR-061): a task template as plain data — for the framework-free
 * {@see TaskTemplatePermissionInterface}, the host's MCP tools and a configuration exporter
 * ({@see TaskTemplateManager::export()}, {@see self::toArray()}). Copied when asked; no entity: the board is its id.
 */
final readonly class TaskTemplateSnapshot
{
    public const SCOPE_BOARD = 'board';

    public const SCOPE_TENANT = 'tenant';

    /**
     * @param list<string> $defaultTags
     */
    public function __construct(
        public string $id,
        public string $name,
        /** {@see self::SCOPE_BOARD} or {@see self::SCOPE_TENANT}. */
        public string $scope,
        public ?string $boardId,
        public ?string $tenantKey,
        public ?string $body,
        public array $defaultTags,
        /** A column of the template's board that still exists, or null. */
        public ?string $defaultColumnId,
        public ?string $defaultColumnName = null,
    ) {
    }

    /** @internal */
    public static function of(TaskTemplate $template): self
    {
        $board  = $template->getBoard();
        $column = null;
        foreach ($board?->getColumns() ?? [] as $candidate) {
            if ($candidate->getId() === $template->getDefaultColumnId()) {
                $column = $candidate;
            }
        }

        return new self(
            id: $template->getId(),
            name: $template->getName(),
            scope: $board !== null ? self::SCOPE_BOARD : self::SCOPE_TENANT,
            boardId: $board?->getId(),
            tenantKey: $board !== null ? $board->getTenantKey() : $template->getTenantKey(),
            body: $template->getBody(),
            defaultTags: $template->getDefaultTags(),
            defaultColumnId: $column?->getId(),
            defaultColumnName: $column?->getName(),
        );
    }

    /**
     * Every field as an array of scalars, the input of {@see TaskTemplateData::fromArray()}.
     *
     * @return array{id: string, name: string, scope: string, board_id: ?string, tenant_key: ?string, body: ?string, default_tags: list<string>, default_column_id: ?string, default_column_name: ?string}
     */
    public function toArray(): array
    {
        return [
            'id'                  => $this->id,
            'name'                => $this->name,
            'scope'               => $this->scope,
            'board_id'            => $this->boardId,
            'tenant_key'          => $this->tenantKey,
            'body'                => $this->body,
            'default_tags'        => $this->defaultTags,
            'default_column_id'   => $this->defaultColumnId,
            'default_column_name' => $this->defaultColumnName,
        ];
    }
}
