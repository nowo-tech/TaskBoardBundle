<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Field;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use InvalidArgumentException;
use Nowo\TaskBoardBundle\Doctrine\RecoveringFlusher;
use Nowo\TaskBoardBundle\Doctrine\TaskBoardEntityManager;
use Nowo\TaskBoardBundle\Doctrine\TaskBoardSavepoint;
use Nowo\TaskBoardBundle\Doctrine\TransactionRollback;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskCustomField;
use Nowo\TaskBoardBundle\Entity\TaskCustomFieldTombstone;
use Nowo\TaskBoardBundle\Entity\TaskCustomFieldValue;
use Nowo\TaskBoardBundle\Enum\CustomFieldOperator;
use Nowo\TaskBoardBundle\Enum\CustomFieldType;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Exception\TaskChangedException;
use Nowo\TaskBoardBundle\Folder\TaskBoardRestructurePolicyInterface;
use Nowo\TaskBoardBundle\Port\Snapshots;
use Nowo\TaskBoardBundle\Service\TaskChangeRecorder;
use Nowo\TaskBoardBundle\Support\Text;
use Throwable;

use function abs;
use function array_filter;
use function array_keys;
use function array_map;
use function array_values;
use function bin2hex;
use function count;
use function explode;
use function in_array;
use function is_finite;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function ltrim;
use function max;
use function mb_strlen;
use function number_format;
use function preg_match;
use function random_bytes;
use function rtrim;
use function str_contains;
use function strcmp;
use function strlen;
use function strtolower;
use function trim;
use function usort;

use const PHP_INT_MAX;

/**
 * Since 2.19.0 (Beacon spec 234, FR-028): native custom fields — definitions per tenant or per board, values per task
 * ({@see self::setField()}), and the operator filters of the Task API and the list view ({@see self::filter()}).
 *
 * Definitions are written under {@see TaskBoardRestructurePolicyInterface} (a tenant's: `mayManageFolders()`; a board's:
 * `mayRestructureBoard()` — by default the manage right); a value needs the caller's edit right on the task, which the
 * caller checks (the API guard, the task form). Every write is a transaction of its own (a savepoint inside the caller's);
 * a failed flush leaves the entity manager open. Tenant keys are compared byte for byte. Stateless.
 */
final readonly class TaskCustomFieldManager
{
    public const TEXT_MAX = 5000;

    /** Since 3.3.0 (CU-3): a provenance marker, `<provider>:<remote field id>`. */
    public const SOURCE_REF = '/^[a-z0-9_-]{1,32}:[A-Za-z0-9_-]{1,128}$/D';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private TaskBoardRestructurePolicyInterface $policy,
        private TaskChangeRecorder $changeRecorder,
        private ?ManagerRegistry $managerRegistry = null,
        // Since 3.0.0 (audit ZAS-V1): TaskBoard rows the application's manager holds are read again by id at each write
        // entry (none: a service built by hand works on the rows it is given, as in 2.x).
        private ?TaskBoardEntityManager $rows = null,
    ) {
    }

    /**
     * The fields of a board: its tenant's, then its own, each by position then name.
     *
     * @return list<TaskCustomField>
     */
    public function fieldsFor(TaskBoard $board): array
    {
        return $this->fieldsForBoards([$board])[$board->getId()];
    }

    /**
     * The fields of several boards in one query (a page of tasks across boards), by board id, as {@see self::fieldsFor()}.
     *
     * @param list<TaskBoard> $boards
     *
     * @return array<string, list<TaskCustomField>>
     */
    public function fieldsForBoards(array $boards): array
    {
        $byBoard = [];
        $tenants = [];
        $none    = false;
        foreach ($boards as $board) {
            $byBoard[$board->getId()]               = [];
            $board->getTenantKey() === null ? $none = true : $tenants[$board->getTenantKey()] = true;
        }
        if ($byBoard === []) {
            return [];
        }
        $query = $this->entityManager->createQueryBuilder()->select('f')->from(TaskCustomField::class, 'f')
            ->where('IDENTITY(f.board) IN (:boards)')->setParameter('boards', array_keys($byBoard));
        if ($tenants !== []) {
            $query->orWhere('f.board IS NULL AND f.tenantKey IN (:tenants)')->setParameter('tenants', array_map(strval(...), array_keys($tenants)));
        }
        if ($none) {
            $query->orWhere('f.board IS NULL AND f.tenantKey IS NULL');
        }
        /** @var list<TaskCustomField> $fields */
        $fields = $query->getQuery()->getResult();
        foreach ($boards as $board) {
            $byBoard[$board->getId()] = $this->sorted(array_values(array_filter($fields, static fn (TaskCustomField $field): bool => $field->getTenantKey() === $board->getTenantKey() && ($field->getBoardId() === null || $field->getBoardId() === $board->getId()))));
        }

        return $byBoard;
    }

    /**
     * @param list<TaskCustomField> $fields
     *
     * @return list<TaskCustomField>
     */
    private function sorted(array $fields): array
    {
        usort($fields, static fn (TaskCustomField $a, TaskCustomField $b): int => self::fieldOrder($a) <=> self::fieldOrder($b));

        return $fields;
    }

    /**
     * The order of {@see self::fieldsFor()}: tenant fields first, then by position, name and id.
     *
     * @return array{bool, int, string, string}
     */
    private static function fieldOrder(TaskCustomField $field): array
    {
        return [$field->getBoardId() !== null, $field->getPosition(), $field->getName(), $field->getId()];
    }

    /**
     * The tenant-wide fields only.
     *
     * @return list<TaskCustomField>
     */
    public function tenantFields(?string $tenantKey): array
    {
        $query = $this->entityManager->createQueryBuilder()->select('f')->from(TaskCustomField::class, 'f')->where('f.board IS NULL');
        $tenantKey === null ? $query->andWhere('f.tenantKey IS NULL') : $query->andWhere('f.tenantKey = :tenant')->setParameter('tenant', $tenantKey);
        /** @var list<TaskCustomField> $fields */
        $fields = $query->getQuery()->getResult();
        $fields = array_values(array_filter($fields, static fn (TaskCustomField $field): bool => $field->getTenantKey() === $tenantKey));
        usort($fields, static fn (TaskCustomField $a, TaskCustomField $b): int => [$a->getPosition(), $a->getName()] <=> [$b->getPosition(), $b->getName()]);

        return $fields;
    }

    /** A field of the board (its own or its tenant's), null otherwise. */
    public function fieldOfBoard(TaskBoard $board, string $fieldId): ?TaskCustomField
    {
        foreach ($this->fieldsFor($board) as $field) {
            if ($field->getId() === $fieldId) {
                return $field;
            }
        }

        return null;
    }

    public function find(string $fieldId): ?TaskCustomField
    {
        if ($fieldId === '' || mb_strlen($fieldId) > 36) {
            return null;
        }
        $field = $this->entityManager->find(TaskCustomField::class, $fieldId);

        return $field instanceof TaskCustomField && $field->getId() === $fieldId ? $field : null;
    }

    /**
     * Defines a field for one board (`$board`) or for every board of a tenant (`$board` null, `$tenantKey`).
     *
     * @param list<array{id?: ?string, name: string, color?: ?string}> $options a dropdown's
     *
     * @throws TaskCustomFieldRefusedException
     */
    public function define(object $actor, ?TaskBoard $board, ?string $tenantKey, string $name, CustomFieldType $type, array $options = [], ?int $position = null): TaskCustomField
    {
        $board     = $board instanceof TaskBoard ? $this->own($board) : null;
        $tenantKey = $board instanceof TaskBoard ? $board->getTenantKey() : $tenantKey;
        $this->requireRight($actor, $board, $tenantKey);
        try {
            $field = new TaskCustomField($name, $type, $tenantKey, $board, $position ?? $this->nextPosition($board, $tenantKey));
            $field->setOptions($options);
        } catch (InvalidArgumentException) {
            throw new TaskCustomFieldRefusedException(TaskCustomFieldRefusedException::INVALID, ['field' => 'definition']);
        }
        $this->write(function () use ($field): void {
            $this->entityManager->persist($field);
        });

        return $field;
    }

    /**
     * Renames, reorders or replaces the options of a field (the type stays). A removed option clears the values that held
     * it.
     *
     * Since 3.3.0 (REQ-RUNTIME-003): the field's row is locked and read again before the change, and every change raises
     * its version; with `$expectedVersion` (the version the caller's form showed) a definition changed meanwhile is
     * refused (`changed`) and nothing is written.
     *
     * @param list<array{id?: ?string, name: string, color?: ?string}>|null $options null keeps them
     *
     * @throws TaskCustomFieldRefusedException
     */
    public function update(object $actor, TaskCustomField $field, string $name, ?array $options, int $position, ?int $expectedVersion = null): TaskCustomField
    {
        $field = $this->own($field);
        $this->requireRight($actor, $field->getBoard(), $field->getTenantKey());
        // Checked before the transaction: a refused definition changes nothing (and keeps the caller's rows managed).
        $trimmed = trim($name);
        try {
            if ($trimmed === '' || mb_strlen($trimmed) > TaskCustomField::NAME_MAX) {
                throw new InvalidArgumentException('name');
            }
            if ($options !== null) {
                TaskCustomField::assertOptions($field->getType(), $options);
            }
        } catch (InvalidArgumentException) {
            throw new TaskCustomFieldRefusedException(TaskCustomFieldRefusedException::INVALID, ['field' => 'definition']);
        }
        $this->write(function () use (&$field, $name, $options, $position, $expectedVersion): void {
            $field = $this->lockDefinition($field, $expectedVersion);
            // Checked above: neither throws here.
            // @igor-ignore - Request data: the row the caller passed, or TaskBoard's own copy of it (own(), audit ZAS-V1); not service state.
            $field->setName($name)->setPosition($position);
            if ($options !== null) {
                // @igor-ignore - Request data: the row the caller passed, or TaskBoard's own copy of it (own(), audit ZAS-V1); not service state.
                $field->setOptions($options);
            }
            // @igor-ignore - Request data: the row the caller passed, or TaskBoard's own copy of it (own(), audit ZAS-V1); not service state.
            $field->bumpVersion();
            if ($field->getType() === CustomFieldType::Dropdown) {
                $ids = array_map(static fn (array $option): string => $option['id'], $field->getOptions());
                foreach ($this->entityManager->getRepository(TaskCustomFieldValue::class)->findBy(['field' => $field]) as $value) {
                    if (!in_array($value->getTextValue(), $ids, true)) {
                        $this->entityManager->remove($value);
                    }
                }
            }
        });

        return $field;
    }

    /**
     * Deletes the field and every value of it. Since 3.3.0 with `$expectedVersion` as {@see self::update()}.
     *
     * @throws TaskCustomFieldRefusedException
     */
    public function delete(object $actor, TaskCustomField $field, ?int $expectedVersion = null): void
    {
        $field = $this->own($field);
        $this->requireRight($actor, $field->getBoard(), $field->getTenantKey());
        $this->write(function () use ($field, $expectedVersion): void {
            $field = $this->lockDefinition($field, $expectedVersion);
            foreach ($this->entityManager->getRepository(TaskCustomFieldValue::class)->findBy(['field' => $field]) as $value) {
                $this->entityManager->remove($value);
            }
            // Since 3.3.0 (audit ZCF-L2): an imported field deleted here is not created again by a re-import.
            $board = $field->getBoard();
            if ($board instanceof TaskBoard && $field->getSourceRef() !== null && !$this->tombstone($board, $field->getSourceRef()) instanceof TaskCustomFieldTombstone) {
                $this->entityManager->persist(new TaskCustomFieldTombstone($board, $field->getSourceRef(), $field->getName()));
            }
            $this->entityManager->remove($field);
        });
    }

    /**
     * Since 3.3.0 (audit ZCF-L2): the imported fields deleted from the board (a re-import does not create them again).
     *
     * @return list<TaskCustomFieldTombstone>
     */
    public function tombstones(TaskBoard $board): array
    {
        /** @var list<TaskCustomFieldTombstone> $rows */
        $rows = $this->entityManager->getRepository(TaskCustomFieldTombstone::class)->findBy(['board' => $board->getId()], ['deletedAt' => 'ASC']);

        return array_values(array_filter($rows, static fn (TaskCustomFieldTombstone $row): bool => $row->getBoard()->getId() === $board->getId()));
    }

    /** Since 3.3.0 (audit ZCF-L2): whether a re-import may create the board's field of this marker. */
    public function isDeletedSource(TaskBoard $board, string $sourceRef): bool
    {
        return $this->tombstone($board, $sourceRef) instanceof TaskCustomFieldTombstone;
    }

    /**
     * Since 3.3.0 (audit ZCF-L2): lets a re-import create the field again (*Import it again* on the Custom fields page).
     * Under the restructure policy.
     *
     * @throws TaskCustomFieldRefusedException
     */
    public function clearTombstone(object $actor, TaskBoard $board, string $tombstoneId): void
    {
        $board = $this->own($board);
        $this->requireRight($actor, $board, $board->getTenantKey());
        $row = $tombstoneId !== '' && mb_strlen($tombstoneId) <= 36 ? $this->entityManager->find(TaskCustomFieldTombstone::class, $tombstoneId) : null;
        if (!$row instanceof TaskCustomFieldTombstone || $row->getBoard()->getId() !== $board->getId()) {
            throw new TaskCustomFieldRefusedException(TaskCustomFieldRefusedException::NOT_FOUND);
        }
        $this->write(function () use ($row): void {
            $this->entityManager->remove($row);
        });
    }

    /**
     * Since 3.3.0 (audit ZCF-L1): whether the actor may change the field's definition — what the Custom fields page asks
     * (a board's field: `mayRestructureBoard()`; a tenant's: `mayManageFolders()`).
     */
    public function mayChange(object $actor, TaskCustomField $field): bool
    {
        try {
            $this->requireRight($actor, $field->getBoard(), $field->getTenantKey());
        } catch (TaskCustomFieldRefusedException) {
            return false;
        }

        return true;
    }

    private function tombstone(TaskBoard $board, string $sourceRef): ?TaskCustomFieldTombstone
    {
        $row = $this->entityManager->getRepository(TaskCustomFieldTombstone::class)->findOneBy(['board' => $board->getId(), 'sourceRef' => $sourceRef]);

        return $row instanceof TaskCustomFieldTombstone && $row->getSourceRef() === $sourceRef ? $row : null;
    }

    /**
     * Since 3.3.0 (CU-3, design §11): the board's field an import created for `$sourceRef` (`<provider>:<remote field
     * id>`), found by that marker (a renamed field stays the same field), or a new board field of that name and type
     * (the name gets a numbered suffix when the board already has one of that name). Null when the board's field of that
     * marker has another type (a field retyped at the source is not followed). Under the restructure policy, as
     * {@see self::define()}.
     *
     * @throws TaskCustomFieldRefusedException
     */
    public function defineFromSource(object $actor, TaskBoard $board, string $sourceRef, string $name, CustomFieldType $type): ?TaskCustomField
    {
        $board    = $this->own($board);
        $existing = $this->sourceField($board, $sourceRef);
        if (!$existing instanceof TaskCustomField) {
            if ($this->isDeletedSource($board, $sourceRef)) {
                // Audit ZCF-L2: deleted here; cleared only on the Custom fields page.
                return null;
            }
            $this->requireRight($actor, $board, $board->getTenantKey());
            if (preg_match(self::SOURCE_REF, $sourceRef) !== 1) {
                throw new TaskCustomFieldRefusedException(TaskCustomFieldRefusedException::INVALID, ['field' => 'definition']);
            }
            $this->write(function () use ($board, $sourceRef, $name, $type, &$existing): void {
                // REQ-RUNTIME-003: the board's row is locked first, so two writers of one marker create one field (the
                // second finds the first's); the unique key (board, marker) stays the last guard.
                $this->entityManager->createQuery('SELECT b.id FROM ' . TaskBoard::class . ' b WHERE b.id = :id')
                    ->setParameter('id', $board->getId())
                    ->setLockMode(LockMode::PESSIMISTIC_WRITE)
                    ->getSingleColumnResult();
                $existing = $this->sourceField($board, $sourceRef, true);
                if ($existing instanceof TaskCustomField) {
                    // Another writer created it between the first look and the lock.
                    return;
                }
                $taken = [];
                foreach ($this->fieldsFor($board) as $field) {
                    $taken[$field->getName()] = true;
                }
                $base   = Text::cut(trim($name) !== '' ? trim($name) : $sourceRef, TaskCustomField::NAME_MAX - 6);
                $unique = $base;
                for ($n = 2; isset($taken[$unique]); ++$n) {
                    $unique = $base . ' (' . $n . ')';
                }
                $existing = $this->insertSourceField(new TaskCustomField($unique, $type, $board->getTenantKey(), $board, $this->nextPosition($board, $board->getTenantKey())), $board, $sourceRef);
            });
        }

        return $existing instanceof TaskCustomField && $existing->getType() === $type ? $existing : null;
    }

    /**
     * Audit ZCF-L3: the new field's row, written in a savepoint of its own (not through the unit of work): a writer that
     * skipped the board lock (another engine, a host writer) and won the unique key (board, marker) only undoes this
     * savepoint — the caller's transaction and the private manager's rows stay (a sync page goes on) — and its field is
     * read again. Audit ZCFv-I1: a named savepoint of its own, not a nested beginTransaction(), so the recovery holds on
     * DBAL 3 without `use_savepoints` too. When rolling back to it fails, the original failure is rethrown and the caller's
     * unit rolls back to its entry level ({@see TransactionRollback}). Always called inside {@see self::write()}.
     */
    private function insertSourceField(TaskCustomField $field, TaskBoard $board, string $sourceRef): ?TaskCustomField
    {
        $connection = $this->entityManager->getConnection();
        $savepoint  = TaskBoardSavepoint::PREFIX . bin2hex(random_bytes(6));
        $connection->createSavepoint($savepoint);
        try {
            $connection->insert($this->entityManager->getClassMetadata(TaskCustomField::class)->getTableName(), [
                'id'             => $field->getId(),
                'created_at'     => $field->getCreatedAt(),
                'name'           => $field->getName(),
                'options'        => [],
                'field_type'     => $field->getType()->value,
                'tenant_key'     => $field->getTenantKey(),
                'board_id'       => $board->getId(),
                'position'       => $field->getPosition(),
                'source_ref'     => $sourceRef,
                'source_options' => null,
                'version'        => 1,
            ], ['created_at' => Types::DATETIME_IMMUTABLE, 'options' => Types::JSON, 'position' => Types::INTEGER, 'version' => Types::INTEGER]);
        } catch (UniqueConstraintViolationException $duplicate) {
            try {
                $connection->rollbackSavepoint($savepoint);
                // @codeCoverageIgnoreStart
            } catch (Throwable) {
                // The duplicate is what the caller must see; its unit then rolls back to its entry level (and closes the
                // connection at level 0). Only a connection lost between the insert and the rollback gets here.
                throw $duplicate;
            }
            // @codeCoverageIgnoreEnd

            return $this->sourceField($board, $sourceRef, true);
        }
        if ($connection->getDatabasePlatform()->supportsReleaseSavepoints()) {
            $connection->releaseSavepoint($savepoint);
        }

        return $this->entityManager->find(TaskCustomField::class, $field->getId());
    }

    /**
     * Since 3.3.0 (CU-3, design §11): this dropdown field's option for each of the provider's options, by the provider's
     * option id. An option met before keeps its option (renamed here or there, it stays the same one); an option met
     * before and deleted here maps to null (never recreated: the caller notes a conflict); a new provider option takes this
     * field's option of the same name, or is added (with its colour) while the field has room. Under the field's row lock;
     * the version rises only when something changed. Since the audit ZCF-L1 under the restructure policy, as {@see self::update()}.
     *
     * @param list<array{id: string, name: string, color: string|null}> $remoteOptions
     *
     * @throws TaskCustomFieldRefusedException `not_allowed`
     *
     * @return array<string, string|null> provider option id => this field's option id (null: none)
     */
    public function adoptSourceOptions(object $actor, TaskCustomField $field, array $remoteOptions): array
    {
        $field = $this->own($field);
        if ($field->getType() !== CustomFieldType::Dropdown || $remoteOptions === []) {
            return [];
        }
        // Audit ZCF-L1: the import changes the definition only with the right the Custom fields page asks.
        $this->requireRight($actor, $field->getBoard(), $field->getTenantKey());
        $result = [];
        $this->write(function () use (&$field, $remoteOptions, &$result): void {
            $field   = $this->lockDefinition($field, null);
            $result  = [];
            $options = $field->getOptions();
            $known   = $field->getSourceOptions();
            $changed = false;
            foreach ($remoteOptions as $remote) {
                $remoteId = $remote['id'];
                if (isset($known[$remoteId])) {
                    $result[$remoteId] = $field->option($known[$remoteId]) !== null ? $known[$remoteId] : null;
                    continue;
                }
                $name  = Text::cut(trim($remote['name']), TaskCustomField::NAME_MAX);
                $match = $name !== '' ? $field->optionNamed($name) : null;
                if ($match === null && $name !== '' && count($options) < TaskCustomField::OPTIONS_MAX) {
                    // A colour the field would refuse is left out.
                    $color     = is_string($remote['color']) && preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/D', $remote['color']) === 1 ? $remote['color'] : null;
                    $options[] = ['name' => $name, 'color' => $color];
                    // @igor-ignore - Request data: TaskBoard's own copy of the row, read again under its lock; not service state.
                    $field->setOptions($options);
                    $options = $field->getOptions();
                    $match   = $field->optionNamed($name);
                }
                $result[$remoteId] = $match['id'] ?? null;
                if ($match !== null) {
                    // @igor-ignore - Request data: TaskBoard's own copy of the row, read again under its lock; not service state.
                    $field->mapSourceOption($remoteId, $match['id']);
                    $changed = true;
                }
            }
            if ($changed) {
                // @igor-ignore - Request data: TaskBoard's own copy of the row, read again under its lock; not service state.
                $field->bumpVersion();
            }
        });

        return $result;
    }

    /** Since 3.3.0 (CU-3): the board's own field of a provenance marker, null when none; `$locking`: a locking (current) read. */
    public function sourceField(TaskBoard $board, string $sourceRef, bool $locking = false): ?TaskCustomField
    {
        if ($locking) {
            // Audit ZCFv-I3: a current read (under the board lock, or after a lost unique key), not the transaction's snapshot.
            $id = $this->entityManager->createQuery('SELECT f.id FROM ' . TaskCustomField::class . ' f WHERE IDENTITY(f.board) = :board AND f.sourceRef = :ref')
                ->setParameter('board', $board->getId())->setParameter('ref', $sourceRef)
                ->setLockMode(LockMode::PESSIMISTIC_WRITE)
                ->getSingleColumnResult();
            $field = $id === [] ? null : $this->entityManager->find(TaskCustomField::class, (string) $id[0]);
            if ($field instanceof TaskCustomField) {
                $this->entityManager->refresh($field);
            }
        } else {
            $field = $this->entityManager->getRepository(TaskCustomField::class)->findOneBy(['board' => $board->getId(), 'sourceRef' => $sourceRef]);
        }

        // Byte for byte (the column's collation may fold case on a host without the bundle's key collation).
        return $field instanceof TaskCustomField && $field->getSourceRef() === $sourceRef && $field->getBoardId() === $board->getId() ? $field : null;
    }

    /**
     * Since 3.3.0 (REQ-RUNTIME-003): locks the field's row and reads it again; refuses (`changed`) when `$expectedVersion`
     * is given and the stored version is another one. Inside {@see self::write()}.
     */
    private function lockDefinition(TaskCustomField $field, ?int $expectedVersion): TaskCustomField
    {
        // Audit ZCFv-L2: the version is read by the locking read itself (a current read), not by refresh() — inside a
        // caller's REPEATABLE READ transaction that already read the row, a plain read returns that snapshot.
        $locked = $this->entityManager->createQuery('SELECT f.version FROM ' . TaskCustomField::class . ' f WHERE f.id = :id')
            ->setParameter('id', $field->getId())
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getSingleColumnResult();
        // A row this manager no longer holds (cleared after an earlier failure) is read again by id.
        $managed = $this->entityManager->contains($field) ? $field : $this->entityManager->find(TaskCustomField::class, $field->getId());
        if ($locked === [] || !$managed instanceof TaskCustomField) {
            throw new TaskCustomFieldRefusedException(TaskCustomFieldRefusedException::NOT_FOUND);
        }
        $version = (int) $locked[0];
        $this->entityManager->refresh($managed);
        if (($expectedVersion !== null && $version !== $expectedVersion) || $managed->getVersion() !== $version) {
            // Changed since the caller read it, or this transaction's snapshot is older than the locked row (the copy would
            // write over the newer one): refused, nothing written.
            throw new TaskCustomFieldRefusedException(TaskCustomFieldRefusedException::CHANGED);
        }

        return $managed;
    }

    /**
     * Sets a task's value (null or "" clears it): text (at most {@see self::TEXT_MAX} characters), a number (int, float or a
     * decimal string), or a dropdown option by id or exact name. Recorded in the task's history. The caller checked the
     * edit right on the task.
     *
     * @throws TaskCustomFieldRefusedException `not_found` (not a field of the task's board), `invalid` (`value`)
     */
    public function setField(Task $task, string $fieldId, mixed $value, object $actor): void
    {
        $task = $this->own($task);
        $this->writeValue($task, $fieldId, $value, $actor, null);
    }

    /**
     * Since 2.31.0 (REQ-RUNTIME-003 rule 4): {@see self::setField()} only while the task's value is still the one the
     * caller's form showed: `$unchanged` gets the current value (read under the task's row lock) and answers whether it
     * is still that one; when it answers false nothing is written.
     *
     * @param callable(TaskCustomFieldValue|null): bool $unchanged
     *
     * @throws TaskChangedException when the value changed meanwhile (nothing written)
     * @throws TaskCustomFieldRefusedException as setField()
     */
    public function setFieldUnlessChanged(Task $task, string $fieldId, mixed $value, object $actor, callable $unchanged): void
    {
        $task = $this->own($task);
        if (!$this->writeValue($task, $fieldId, $value, $actor, $unchanged)) {
            throw new TaskChangedException($task->getRevision());
        }
    }

    /**
     * Since 3.3.0 (audit ZCF-L3): {@see self::setFieldUnlessChanged()} that answers instead of throwing — false when the
     * value changed meanwhile (nothing written, and nothing rolled back: the transaction commits empty, so the caller's
     * rows stay managed, e.g. a sync page's).
     *
     * @param callable(TaskCustomFieldValue|null): bool $unchanged
     *
     * @throws TaskCustomFieldRefusedException as setField()
     */
    public function setFieldIfUnchanged(Task $task, string $fieldId, mixed $value, object $actor, callable $unchanged): bool
    {
        return $this->writeValue($this->own($task), $fieldId, $value, $actor, $unchanged);
    }

    /**
     * Since 2.31.0 (REQ-RUNTIME-003 rule 4): the task's row is locked before its value is read, so two writes of one
     * task's field (two forms, the Task API) run one after the other: the second one reads what the first one stored
     * (no lost update, no duplicate first value on the unique key).
     *
     * @param (callable(TaskCustomFieldValue|null): bool)|null $unchanged
     */
    private function writeValue(Task $task, string $fieldId, mixed $value, object $actor, ?callable $unchanged): bool
    {
        $field           = $this->fieldOfBoard($task->getBoard(), $fieldId) ?? throw new TaskCustomFieldRefusedException(TaskCustomFieldRefusedException::NOT_FOUND);
        [$text, $number] = $this->normalize($field, $value);
        $written         = true;
        $this->write(function () use ($task, $field, $text, $number, $actor, $unchanged, &$written): void {
            $this->entityManager->createQuery('SELECT t.id FROM ' . Task::class . ' t WHERE t.id = :id')
                ->setParameter('id', $task->getId())
                ->setLockMode(LockMode::PESSIMISTIC_WRITE)
                ->getSingleColumnResult();
            $current = $this->entityManager->getRepository(TaskCustomFieldValue::class)->findOneBy(['task' => $task, 'field' => $field]);
            if ($current instanceof TaskCustomFieldValue) {
                $this->entityManager->refresh($current);
            }
            if ($unchanged !== null && !$unchanged($current)) {
                $written = false;

                return;
            }
            $before = $current instanceof TaskCustomFieldValue ? $this->display($current) : null;
            if ($text === null && $number === null) {
                if ($current instanceof TaskCustomFieldValue) {
                    $this->entityManager->remove($current);
                }
                $after = null;
            } else {
                $current ??= new TaskCustomFieldValue($task, $field);
                $current->change($text, $number);
                $this->entityManager->persist($current);
                $after = $this->display($current);
            }
            $this->changeRecorder->record($task, $actor, TaskChangeType::CustomField, $before, $after, 'field:' . $field->getId() . ' ' . $field->getName());
        });

        return $written;
    }

    /**
     * Since 2.19.0: whether any task holds a value of the field; for a board's field, a task still on that board (audit
     * PW-L6: a value a move left elsewhere is not the board's).
     */
    public function hasValues(TaskCustomField $field): bool
    {
        $query = $this->entityManager->createQueryBuilder()->select('COUNT(v.id)')->from(TaskCustomFieldValue::class, 'v')
            ->join('v.task', 't')->where('v.field = :field')->setParameter('field', $field->getId());
        if ($field->getBoardId() !== null) {
            $query->andWhere('IDENTITY(t.board) = :board')->setParameter('board', $field->getBoardId());
        }

        return (int) $query->getQuery()->getSingleScalarResult() > 0;
    }

    /**
     * Since 2.19.0 (audit PW-L6): after `$task` moved from `$from` to another board (its board now), the values of `$from`'s own fields go
     * to the new board's own field of the same name and type (a dropdown value to the option of the same name), else they
     * are dropped with a `custom_field` history row. Values of tenant fields stay as they are. In the caller's transaction
     * (no flush).
     *
     * @return list<string> the names of the fields whose value was dropped
     */
    public function followBoardMove(Task $task, TaskBoard $from, object $actor): array
    {
        $task   = $this->own($task);
        $from   = $this->own($from);
        $target = $task->getBoard();
        $theirs = [];
        foreach ($this->fieldsFor($target) as $field) {
            if ($field->getBoardId() === $target->getId()) {
                $theirs[$field->getName() . "\n" . $field->getType()->value] = $field;
            }
        }
        $dropped = [];
        /** @var list<TaskCustomFieldValue> $values */
        $values = $this->entityManager->getRepository(TaskCustomFieldValue::class)->findBy(['task' => $task]);
        foreach ($values as $value) {
            $field = $value->getField();
            if ($field->getBoardId() !== $from->getId()) {
                continue;
            }
            $match = $theirs[$field->getName() . "\n" . $field->getType()->value] ?? null;
            $text  = $value->getTextValue();
            if ($match instanceof TaskCustomField && $field->getType() === CustomFieldType::Dropdown) {
                $text = $match->optionNamed((string) ($field->option((string) $text)['name'] ?? ''))['id'] ?? null;
            }
            $carried = $match instanceof TaskCustomField && ($field->getType() !== CustomFieldType::Dropdown || $text !== null);
            if ($carried) {
                $copy = new TaskCustomFieldValue($task, $match);
                $copy->change($text, $value->getNumberValue());
                $this->entityManager->persist($copy);
            } else {
                $dropped[] = $field->getName();
                $this->changeRecorder->record($task, $actor, TaskChangeType::CustomField, $this->display($value), null, 'field:' . $field->getId() . ' ' . $field->getName());
            }
            $this->entityManager->remove($value);
        }

        return $dropped;
    }

    /**
     * The values of these tasks, by task id, each list in the order of the fields ({@see self::fieldsFor()}).
     *
     * @param list<string> $taskIds
     *
     * @return array<string, list<TaskCustomFieldValue>>
     */
    public function valuesOf(array $taskIds): array
    {
        if ($taskIds === []) {
            return [];
        }
        /** @var list<TaskCustomFieldValue> $values */
        $values = $this->entityManager->createQueryBuilder()->select('v', 'f')->from(TaskCustomFieldValue::class, 'v')->join('v.field', 'f')
            ->where('IDENTITY(v.task) IN (:tasks)')->setParameter('tasks', $taskIds)->getQuery()->getResult();
        $byTask = [];
        foreach ($values as $value) {
            $byTask[$value->getTask()->getId()][] = $value;
        }
        // The query has no order (the database's own differs: SQLite, MySQL, PostgreSQL): the fields' order, as fieldsFor().
        foreach ($byTask as $taskId => $list) {
            usort($list, static fn (TaskCustomFieldValue $a, TaskCustomFieldValue $b): int => self::fieldOrder($a->getField()) <=> self::fieldOrder($b->getField()));
            $byTask[$taskId] = $list;
        }

        return $byTask;
    }

    /** The value as people read it: the text, the number without trailing zeros, the option's name. */
    public function display(TaskCustomFieldValue $value): string
    {
        return match ($value->getField()->getType()) {
            CustomFieldType::Text     => (string) $value->getTextValue(),
            CustomFieldType::Number   => self::trimDecimal((string) $value->getNumberValue()),
            CustomFieldType::Dropdown => $value->getField()->option((string) $value->getTextValue())['name'] ?? '',
        };
    }

    /**
     * Narrows a task query (`$alias` the task) to the tasks every condition holds for. A condition on a field the board does
     * not have, or a malformed one, is refused, never ignored.
     *
     * @param list<TaskCustomFieldCondition> $conditions
     * @param array<string, TaskCustomField> $fields the fields the conditions may name, by id
     *
     * @throws TaskCustomFieldRefusedException `not_found`, `invalid` (`value`)
     */
    public function filter(QueryBuilder $qb, string $alias, array $conditions, array $fields): void
    {
        foreach (array_values($conditions) as $index => $condition) {
            $field = $fields[$condition->fieldId] ?? throw new TaskCustomFieldRefusedException(TaskCustomFieldRefusedException::NOT_FOUND);
            $op    = $condition->operator;
            if (($op === CustomFieldOperator::LessThan || $op === CustomFieldOperator::GreaterThan) && $field->getType() !== CustomFieldType::Number) {
                throw new TaskCustomFieldRefusedException(TaskCustomFieldRefusedException::INVALID, ['field' => 'operator']);
            }
            $v      = 'cfv' . $index;
            $column = $field->getType() === CustomFieldType::Number ? $v . '.numberValue' : $v . '.textValue';
            $sub    = 'SELECT ' . $v . '.id FROM ' . TaskCustomFieldValue::class . ' ' . $v . ' WHERE ' . $v . '.task = ' . $alias . ' AND IDENTITY(' . $v . '.field) = :cff' . $index;
            $qb->setParameter('cff' . $index, $field->getId());
            $values = array_map(fn (string $raw): string => $this->comparable($field, $raw), $condition->values);
            match ($op) {
                CustomFieldOperator::IsSet       => $qb->andWhere('EXISTS (' . $sub . ')'),
                CustomFieldOperator::IsNotSet    => $qb->andWhere('NOT EXISTS (' . $sub . ')'),
                CustomFieldOperator::Equals      => $qb->andWhere('EXISTS (' . $sub . ' AND ' . $column . ' = :cfx' . $index . ')')->setParameter('cfx' . $index, $values[0] ?? ''),
                CustomFieldOperator::NotEquals   => $qb->andWhere('NOT EXISTS (' . $sub . ' AND ' . $column . ' = :cfx' . $index . ')')->setParameter('cfx' . $index, $values[0] ?? ''),
                CustomFieldOperator::LessThan    => $qb->andWhere('EXISTS (' . $sub . ' AND ' . $column . ' < :cfx' . $index . ')')->setParameter('cfx' . $index, $values[0] ?? ''),
                CustomFieldOperator::GreaterThan => $qb->andWhere('EXISTS (' . $sub . ' AND ' . $column . ' > :cfx' . $index . ')')->setParameter('cfx' . $index, $values[0] ?? ''),
                CustomFieldOperator::In          => $qb->andWhere('EXISTS (' . $sub . ' AND ' . $column . ' IN (:cfx' . $index . '))')->setParameter('cfx' . $index, $values),
            };
        }
    }

    /**
     * @throws TaskCustomFieldRefusedException
     *
     * @return array{0: ?string, 1: ?string} text, number
     */
    private function normalize(TaskCustomField $field, mixed $value): array
    {
        if ($value === null || $value === '') {
            return [null, null];
        }
        $invalid = new TaskCustomFieldRefusedException(TaskCustomFieldRefusedException::INVALID, ['field' => 'value']);

        return match ($field->getType()) {
            CustomFieldType::Text     => is_string($value) && mb_strlen($value) <= self::TEXT_MAX ? [$value, null] : throw $invalid,
            CustomFieldType::Number   => [null, $this->number($value) ?? throw $invalid],
            CustomFieldType::Dropdown => [is_string($value) || is_int($value) ? ($this->optionId($field, (string) $value) ?? throw $invalid) : throw $invalid, null],
        };
    }

    /** The value a filter compares with: a decimal for a number, an option id for a dropdown (by id or name). */
    private function comparable(TaskCustomField $field, string $raw): string
    {
        return match ($field->getType()) {
            CustomFieldType::Number   => $this->number($raw) ?? throw new TaskCustomFieldRefusedException(TaskCustomFieldRefusedException::INVALID, ['field' => 'value']),
            CustomFieldType::Dropdown => $this->optionId($field, $raw) ?? '',
            CustomFieldType::Text     => $raw,
        };
    }

    /**
     * A number as the decimal string stored, null when it is not one. Since 2.19.0 (audit PW-L1) its integer part is at most
     * PHP_INT_MAX in absolute value, so the Task API answers exactly the integer written (a larger one is refused, never
     * saturated).
     */
    private function number(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            $value = is_finite($value) && abs($value) < 1e19 ? number_format($value, 10, '.', '') : null;
        }
        if (!is_string($value) || !is_numeric(trim($value)) || str_contains(strtolower($value), 'e')) {
            return null;
        }
        $value = trim($value);

        return $this->fitsAnInt($value) ? $value : null;
    }

    /** Whether the integer part of a plain decimal string is at most PHP_INT_MAX in absolute value. */
    private function fitsAnInt(string $decimal): bool
    {
        $integer = ltrim(explode('.', ltrim($decimal, '+-'))[0], '0');
        $max     = (string) PHP_INT_MAX;

        return strlen($integer) < strlen($max) || (strlen($integer) === strlen($max) && strcmp($integer, $max) <= 0);
    }

    private function optionId(TaskCustomField $field, string $value): ?string
    {
        return $field->option($value)['id'] ?? $field->optionNamed($value)['id'] ?? null;
    }

    /** Since 3.3.0: a stored decimal without its trailing zeros ("12.5000000000" → "12.5"), as people read it. */
    public static function trimDecimal(string $number): string
    {
        return str_contains($number, '.') ? rtrim(rtrim($number, '0'), '.') : $number;
    }

    private function nextPosition(?TaskBoard $board, ?string $tenantKey): int
    {
        $last = -1;
        foreach ($board instanceof TaskBoard ? $this->fieldsFor($board) : $this->tenantFields($tenantKey) as $field) {
            if ($field->getBoardId() === $board?->getId()) {
                $last = max($last, $field->getPosition());
            }
        }

        return $last + 1;
    }

    /**
     * @throws TaskCustomFieldRefusedException
     */
    private function requireRight(object $actor, ?TaskBoard $board, ?string $tenantKey): void
    {
        $allowed = $board instanceof TaskBoard ? $this->policy->mayRestructureBoard($actor, Snapshots::board($board)) : $this->policy->mayManageFolders($actor, $tenantKey);
        if (!$allowed) {
            throw new TaskCustomFieldRefusedException(TaskCustomFieldRefusedException::NOT_ALLOWED);
        }
    }

    /**
     * @param callable(): void $change
     */
    private function write(callable $change): void
    {
        $connection = $this->entityManager->getConnection();
        $level      = $connection->getTransactionNestingLevel();
        $connection->beginTransaction();
        try {
            $change();
            RecoveringFlusher::flush($this->entityManager, $this->managerRegistry);
            $connection->commit();
        } catch (Throwable $failure) {
            // Since 2.20.0 (audit PX-L6): back to the entry level; the connection is closed only if the server ended it.
            TransactionRollback::toLevel($connection, $level);
            RecoveringFlusher::resetIfClosed($this->entityManager, $this->managerRegistry);

            throw $failure;
        }
    }

    /**
     * @template T of object
     *
     * @param T $row
     *
     * @return T
     */
    private function own(object $row): object
    {
        return $this->rows instanceof TaskBoardEntityManager ? $this->rows->attach($row) : $row;
    }
}
