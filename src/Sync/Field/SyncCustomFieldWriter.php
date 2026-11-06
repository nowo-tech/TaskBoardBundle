<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Field;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncField;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncFieldValue;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteCustomField;
use Nowo\TaskBoardBundle\Sync\SyncRunContext;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;

/**
 * Since 1.27.0 (TB-SYNC-3): writes a task's typed custom fields — the board-level definitions (created once per remote
 * field, refreshed when the provider renames or retypes them) and the task's values. The provider is the source: a
 * value it no longer gives is removed; values are read-only here. Idempotent: an unchanged value is not written.
 */
final readonly class SyncCustomFieldWriter
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock = new NativeClock(),
    ) {
    }

    /**
     * @param list<RemoteCustomField> $fields
     */
    public function write(SyncRunContext $ctx, Task $task, array $fields): void
    {
        $now      = $this->clock->now();
        $existing = [];
        foreach ($this->entityManager->getRepository(TaskSyncFieldValue::class)->findBy(['task' => $task]) as $value) {
            $existing[$value->getField()->getRemoteId()] = $value;
        }
        $kept = [];
        foreach ($fields as $position => $remote) {
            $definition = $this->definition($ctx, $remote, $position);
            if (!$remote->hasValue()) {
                continue;
            }
            $kept[$remote->id] = true;
            $value             = $existing[$remote->id] ?? null;
            if (!$value instanceof TaskSyncFieldValue) {
                $this->entityManager->persist(new TaskSyncFieldValue($definition, $task, $remote->value, $remote->text, $now));
                $ctx->count('fields.values');
                continue;
            }
            if ($value->set($remote->value, $remote->text, $now)) {
                $ctx->count('fields.values');
            }
        }
        foreach ($existing as $remoteId => $value) {
            if (!isset($kept[$remoteId])) {
                $this->entityManager->remove($value);
                $ctx->count('fields.cleared');
            }
        }
    }

    private function definition(SyncRunContext $ctx, RemoteCustomField $remote, int $position): TaskSyncField
    {
        $field = $ctx->fields[$remote->id] ?? $this->entityManager->getRepository(TaskSyncField::class)->findOneBy(['board' => $ctx->board, 'provider' => $ctx->provider->id(), 'remoteId' => $remote->id]);
        if (!$field instanceof TaskSyncField) {
            $field = new TaskSyncField($ctx->board, $ctx->provider->id(), $remote->id, $this->clock->now());
            $this->entityManager->persist($field);
            $ctx->count('fields.defined');
        }
        $field->define($remote->name, $remote->type, $remote->remoteType, $remote->choices, $remote->config, $position, $this->clock->now());
        $ctx->fields[$remote->id] = $field;

        return $field;
    }
}
