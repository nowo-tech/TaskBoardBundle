<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Provider;

use InvalidArgumentException;

use function array_values;
use function sprintf;

/**
 * Since 1.23: the registered providers by id (services tagged `nowo_task_board.task_provider`).
 */
final readonly class TaskProviderRegistry
{
    /** @var array<string, TaskProviderInterface> */
    private array $providers;

    /**
     * @param iterable<TaskProviderInterface> $providers
     */
    public function __construct(iterable $providers = [])
    {
        $byId = [];
        foreach ($providers as $provider) {
            $byId[$provider->id()] ??= $provider;
        }
        $this->providers = $byId;
    }

    public function has(string $id): bool
    {
        return isset($this->providers[$id]);
    }

    public function get(string $id): TaskProviderInterface
    {
        return $this->providers[$id] ?? throw new InvalidArgumentException(sprintf('No task provider "%s" is registered.', $id));
    }

    /**
     * @return list<TaskProviderInterface>
     */
    public function all(): array
    {
        return array_values($this->providers);
    }
}
