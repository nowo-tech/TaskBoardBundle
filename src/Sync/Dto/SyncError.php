<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Dto;

use function is_array;

/**
 * Since 1.23: an error or a warning of a run as a code, a translation key (`task_board.sync.error.<code>`) and its
 * parameters. Never a provider body, a URL or a credential.
 */
final readonly class SyncError
{
    /**
     * @param array<string, string> $params
     */
    public function __construct(
        public string $code,
        public array $params = [],
    ) {
    }

    public function messageKey(): string
    {
        return 'task_board.sync.error.' . $this->code;
    }

    /**
     * @return array{code: string, params: array<string, string>}
     */
    public function toArray(): array
    {
        return ['code' => $this->code, 'params' => $this->params];
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $params = [];
        foreach (is_array($data['params'] ?? null) ? $data['params'] : [] as $key => $value) {
            $params[(string) $key] = SyncValues::string($value);
        }

        return new self(SyncValues::string($data['code'] ?? 'internal'), $params);
    }
}
