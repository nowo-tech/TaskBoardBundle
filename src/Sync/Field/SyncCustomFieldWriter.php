<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Field;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncField;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncFieldValue;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncPerson;
use Nowo\TaskBoardBundle\Sync\People\PeopleSession;
use Nowo\TaskBoardBundle\Sync\People\PersonToken;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteCustomField;
use Nowo\TaskBoardBundle\Sync\Provider\RemotePersonRef;
use Nowo\TaskBoardBundle\Sync\SyncRunContext;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;

use function is_array;
use function is_string;

/**
 * Since 1.27.0 (TB-SYNC-3): writes a task's typed custom fields — the board-level definitions (created once per remote
 * field, refreshed when the provider renames or retypes them) and the task's values. The provider is the source: a
 * value it no longer gives is removed; values are read-only here. Idempotent: an unchanged value is not written.
 * A `people` value is stored as references ({@see PersonToken}): names stay in the people directory.
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
    public function write(SyncRunContext $ctx, Task $task, array $fields, ?PeopleSession $session = null): void
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
            [$stored, $text]   = $this->stored($remote, $session);
            if (!$value instanceof TaskSyncFieldValue) {
                $this->entityManager->persist(new TaskSyncFieldValue($definition, $task, $stored, $text, $now));
                $ctx->count('fields.values');
                continue;
            }
            if ($value->set($stored, $text, $now)) {
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

    /**
     * @return array{0: mixed, 1: string}
     */
    private function stored(RemoteCustomField $remote, ?PeopleSession $session): array
    {
        if ($remote->type !== SyncFieldType::People || !is_array($remote->value)) {
            return [$remote->value, $remote->text];
        }
        $tokens = [];
        foreach ($remote->value as $person) {
            if (is_array($person) && is_string($person['id'] ?? null)) {
                $name     = is_string($person['name'] ?? null) ? $person['name'] : $person['id'];
                $tokens[] = PersonToken::of($session, new RemotePersonRef($person['id'], $name), TaskSyncPerson::KIND_MEMBER);
            }
        }

        return [$tokens, ''];
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
