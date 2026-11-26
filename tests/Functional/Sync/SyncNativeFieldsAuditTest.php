<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use DateTimeImmutable;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Events;
use Nowo\TaskBoardBundle\Configuration\BoardConfiguration;
use Nowo\TaskBoardBundle\Configuration\BoardConfigurationApplier;
use Nowo\TaskBoardBundle\Configuration\BoardConfigurationExporter;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Entity\TaskCustomField;
use Nowo\TaskBoardBundle\Entity\TaskCustomFieldTombstone;
use Nowo\TaskBoardBundle\Enum\CustomFieldType;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Field\TaskCustomFieldManager;
use Nowo\TaskBoardBundle\Field\TaskCustomFieldRefusedException;
use Nowo\TaskBoardBundle\Import\Dto\ImportedTaskDto;
use Nowo\TaskBoardBundle\Sync\Dto\SyncError;
use Nowo\TaskBoardBundle\Sync\Dto\SyncReport;
use Nowo\TaskBoardBundle\Sync\Entity\TaskBoardConnection;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncField;
use Nowo\TaskBoardBundle\Sync\Field\SyncFieldType;
use Nowo\TaskBoardBundle\Sync\Field\SyncNativeFieldWriter;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCredential;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteContainer;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteCustomField;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteTask;
use Nowo\TaskBoardBundle\Tests\Functional\App\FunctionalKernel;
use Nowo\TaskBoardBundle\Tests\Functional\App\ScriptedRestructurePolicy;
use Nowo\TaskBoardBundle\Tests\Functional\App\TestCredentialResolver;
use PHPUnit\Framework\Attributes\Group;
use ReflectionProperty;

use function array_map;
use function array_replace_recursive;
use function fgets;
use function implode;
use function proc_close;
use function proc_open;
use function sort;
use function str_contains;
use function stream_get_contents;
use function trim;

use const PHP_BINARY;

/**
 * Since 3.3.0 (audit ZCF): the import changes a native definition only with the Custom fields page's right (L1), a
 * deleted imported field stays deleted until it is cleared (L2), and the two losing sides of a race are real (L3): a
 * field another writer created between the first look and the board lock, a value a person changed between the import's
 * read and its locked write, and a duplicate marker refused by the unique key. The two-process cases run on MySQL,
 * MariaDB and PostgreSQL; the in-process interleavings (through the restructure policy, asked between the reads and the
 * writes) on every database.
 */
#[Group('database')]
final class SyncNativeFieldsAuditTest extends SyncFunctionalTestCase
{
    protected static function syncConfig(): array
    {
        return array_replace_recursive(parent::syncConfig(), ['restructure' => ['policy' => 'app.restructure_policy']]);
    }

    protected function setUp(): void
    {
        ScriptedRestructurePolicy::reset();
        parent::setUp();
    }

    protected function tearDown(): void
    {
        ScriptedRestructurePolicy::reset();
        parent::tearDown();
    }

    public function testATargetTheRunMayNotChangeStaysSyncedAndUntouched(): void
    {
        $board  = $this->board();
        $shared = $this->fields()->define($this->owner, null, null, 'Shared stage', CustomFieldType::Dropdown, [['name' => 'Draft']]);
        $own    = $this->fields()->define($this->owner, $board, null, 'Owner', CustomFieldType::Text);
        $this->linked($board, 'fStage=' . $shared->getId() . "\nfOwner=" . $own->getId() . "\nfNew=create");
        // The run's actor may neither manage the tenant's fields nor restructure the board.
        ScriptedRestructurePolicy::$deny = static fn (string $subject): bool => true;
        $this->put($this->withFields('t1', 'Task', 1, [
            new RemoteCustomField('fStage', 'Stage', SyncFieldType::Dropdown, 'drop_down', [['id' => 'o1', 'name' => 'Live', 'color' => null]], [], 'o1', 'Live'),
            new RemoteCustomField('fOwner', 'Owner', SyncFieldType::Text, 'text', [], [], 'Ana', 'Ana'),
            new RemoteCustomField('fNew', 'New', SyncFieldType::Text, 'text', [], [], 'x', 'x'),
        ]));
        $report = $this->sync($board);

        self::assertContains('native_field_refused', $this->warnings($report));
        self::assertSame(0, $report->stat('fields.native_values'));
        $stored = $this->fields()->find($shared->getId());
        self::assertSame([1, ['Draft'], []], [$stored?->getVersion(), array_map(static fn (array $o): string => $o['name'], $stored?->getOptions() ?? []), $stored?->getSourceOptions()], 'the tenant\'s definition is not touched');
        self::assertSame(['New', 'Owner', 'Stage'], $this->syncedNames(), 'the values stay synced, read-only fields');

        // The manager refuses the option merge itself too.
        try {
            $this->fields()->adoptSourceOptions($this->owner, $shared, [['id' => 'o1', 'name' => 'Live', 'color' => null]]);
            self::fail('not allowed');
        } catch (TaskCustomFieldRefusedException $refused) {
            self::assertSame(TaskCustomFieldRefusedException::NOT_ALLOWED, $refused->reason);
        }
        self::assertFalse($this->fields()->mayChange($this->owner, $own));
        ScriptedRestructurePolicy::$deny = null;
        self::assertTrue($this->fields()->mayChange($this->owner, $own));
    }

    public function testAPolicyThatChangesItsAnswerBeforeTheOptionMergeStillLeavesTheDefinitionAlone(): void
    {
        $board = $this->board();
        $stage = $this->fields()->define($this->owner, $board, null, 'Stage', CustomFieldType::Dropdown, [['name' => 'Draft']]);
        $this->linked($board, 'fStage=' . $stage->getId());
        // mayChange() is allowed, the option merge asked right after is not.
        $asked                           = 0;
        ScriptedRestructurePolicy::$deny = static function (string $subject) use (&$asked): bool {
            return ++$asked > 1;
        };
        $this->put($this->withFields('t1', 'Task', 1, [new RemoteCustomField('fStage', 'Stage', SyncFieldType::Dropdown, 'drop_down', [['id' => 'o1', 'name' => 'Live', 'color' => null]], [], 'o1', 'Live')]));
        $report = $this->sync($board);
        self::assertContains('native_field_refused', $this->warnings($report));
        self::assertSame([1, ['Draft']], [$this->fields()->find($stage->getId())?->getVersion(), array_map(static fn (array $o): string => $o['name'], $this->fields()->find($stage->getId())?->getOptions() ?? [])]);
        self::assertSame(['Stage'], $this->syncedNames());
    }

    public function testADeletedImportedFieldIsNotCreatedAgainUntilCleared(): void
    {
        $board = $this->board();
        $this->linked($board, 'fOwner=create');
        $this->put($this->withFields('t1', 'Task', 1, [new RemoteCustomField('fOwner', 'Owner', SyncFieldType::Text, 'text', [], [], 'Ana', 'Ana')]));
        $this->sync($board);
        $field = $this->fields()->sourceField($this->freshBoard($board), 'clickup:fOwner');
        self::assertInstanceOf(TaskCustomField::class, $field);
        $this->fields()->delete($this->owner, $field);
        $tombstones = $this->fields()->tombstones($this->freshBoard($board));
        self::assertCount(1, $tombstones);
        self::assertSame(['clickup:fOwner', 'Owner', $board->getId()], [$tombstones[0]->getSourceRef(), $tombstones[0]->getName(), $tombstones[0]->getBoard()->getId()]);
        self::assertInstanceOf(DateTimeImmutable::class, $tombstones[0]->getDeletedAt());

        $this->put($this->withFields('t1', 'Task', 2, [new RemoteCustomField('fOwner', 'Owner', SyncFieldType::Text, 'text', [], [], 'Bob', 'Bob')]));
        $report = $this->sync($board);
        self::assertContains('native_field_deleted', $this->warnings($report));
        self::assertNull($this->fields()->sourceField($this->freshBoard($board), 'clickup:fOwner'), 'not created again');
        self::assertNull($this->fields()->defineFromSource($this->owner, $this->freshBoard($board), 'clickup:fOwner', 'Owner', CustomFieldType::Text));
        // Audit ZCFv-L1: not imported at all — no synced definition or value either.
        self::assertSame([], $this->syncedNames(), 'its values are not imported, not even read-only');
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM task_board_sync_field_values'));

        // Cleared (Custom fields page: Import it again): the next import creates it again.
        foreach (['', 'unknown'] as $bad) {
            try {
                $this->fields()->clearTombstone($this->owner, $this->freshBoard($board), $bad);
                self::fail('not found');
            } catch (TaskCustomFieldRefusedException $refused) {
                self::assertSame(TaskCustomFieldRefusedException::NOT_FOUND, $refused->reason);
            }
        }
        $this->fields()->clearTombstone($this->owner, $this->freshBoard($board), $tombstones[0]->getId());
        $this->put($this->withFields('t1', 'Task', 3, [new RemoteCustomField('fOwner', 'Owner', SyncFieldType::Text, 'text', [], [], 'Carla', 'Carla')]));
        $this->sync($board);
        self::assertInstanceOf(TaskCustomField::class, $this->fields()->sourceField($this->freshBoard($board), 'clickup:fOwner'));
        self::assertCount(0, $this->em->getRepository(TaskCustomFieldTombstone::class)->findAll());
        // A field defined here leaves no tombstone.
        $plain = $this->fields()->define($this->owner, $this->freshBoard($board), null, 'Plain', CustomFieldType::Text);
        $this->fields()->delete($this->owner, $plain);
        self::assertCount(0, $this->em->getRepository(TaskCustomFieldTombstone::class)->findAll());
    }

    /**
     * Audit ZCFv2-L1 (b): after *Import it again*, the next sync brings the field and the values of every task back, also
     * those that did not change in ClickUp since the last run (an incremental run would skip them).
     */
    public function testImportItAgainBringsBackTheValuesOfUnchangedTasksOnTheNextSync(): void
    {
        $board = $this->board();
        $this->linked($board, 'fOwner=create');
        $this->put($this->withFields('t1', 'Old', 1, [new RemoteCustomField('fOwner', 'Owner', SyncFieldType::Text, 'text', [], [], 'Ana', 'Ana')]));
        $this->put($this->withFields('t2', 'New', 10_000, [new RemoteCustomField('fOwner', 'Owner', SyncFieldType::Text, 'text', [], [], 'Bob', 'Bob')]));
        $this->sync($board);
        $field = $this->fields()->sourceField($this->freshBoard($board), 'clickup:fOwner');
        self::assertInstanceOf(TaskCustomField::class, $field);
        $this->fields()->delete($this->owner, $field);
        // Only the newer task changes in ClickUp; the older one stays out of the incremental window.
        $this->put($this->withFields('t2', 'New', 10_001, [new RemoteCustomField('fOwner', 'Owner', SyncFieldType::Text, 'text', [], [], 'Bob', 'Bob')]));
        self::assertContains('native_field_deleted', $this->warnings($this->sync($board)));
        self::assertSame([[], []], [$this->displayed('Old'), $this->displayed('New')]);

        $this->fields()->clearTombstone($this->owner, $this->freshBoard($board), $this->fields()->tombstones($this->freshBoard($board))[0]->getId());
        $connection = $this->em->getRepository(TaskBoardConnection::class)->findOneBy(['board' => $board->getId()]);
        self::assertInstanceOf(TaskBoardConnection::class, $connection);
        self::assertNull($connection->getWatermark(), 'the next run reads the whole list');
        // No change in ClickUp: the next sync still brings the field and both values back.
        $report = $this->sync($board);
        self::assertNotContains('native_field_deleted', $this->warnings($report));
        self::assertSame([['Owner' => 'Ana'], ['Owner' => 'Bob']], [$this->displayed('Old'), $this->displayed('New')]);
    }

    /**
     * Audit ZCFv3-L1: *Import it again* is refused while a sync of the board holds its run lock (that run would cache the
     * field as deleted and write over the reset); a dead run's stale lock does not block it.
     */
    public function testImportItAgainIsRefusedWhileASyncOfTheBoardRuns(): void
    {
        $board = $this->board();
        $this->linked($board, 'fOwner=create');
        $this->put($this->withFields('t1', 'Task', 1, [new RemoteCustomField('fOwner', 'Owner', SyncFieldType::Text, 'text', [], [], 'Ana', 'Ana')]));
        $this->sync($board);
        $field = $this->fields()->sourceField($this->freshBoard($board), 'clickup:fOwner');
        self::assertInstanceOf(TaskCustomField::class, $field);
        $this->fields()->delete($this->owner, $field);
        $tombstone = $this->fields()->tombstones($this->freshBoard($board))[0]->getId();
        $db        = $this->em->getConnection();
        $running   = "UPDATE task_board_connections SET running_run_id = 'other-run', heartbeat_at = ? WHERE board_id = ?";

        $db->executeStatement($running, [(new DateTimeImmutable())->format('Y-m-d H:i:s'), $board->getId()]);
        try {
            $this->fields()->clearTombstone($this->owner, $this->freshBoard($board), $tombstone);
            self::fail('refused while a sync runs');
        } catch (TaskCustomFieldRefusedException $refused) {
            self::assertSame(TaskCustomFieldRefusedException::SYNC_RUNNING, $refused->reason);
        }
        $this->em->clear();
        self::assertCount(1, $this->fields()->tombstones($this->freshBoard($board)), 'nothing written');
        self::assertNotNull($db->fetchOne('SELECT watermark FROM task_board_connections WHERE board_id = ?', [$board->getId()]));

        // A dead run (no heartbeat for longer than the lock's stale time) does not block it.
        $db->executeStatement($running, [(new DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s'), $board->getId()]);
        $this->fields()->clearTombstone($this->owner, $this->freshBoard($board), $tombstone);
        $this->em->clear();
        self::assertSame([], $this->fields()->tombstones($this->freshBoard($board)));
        self::assertNull($db->fetchOne('SELECT watermark FROM task_board_connections WHERE board_id = ?', [$board->getId()]));
    }

    /**
     * Audit ZCFv2-L1 (a): a definition that changed under the run (`native_field_changed`) defers the tasks that carry the
     * field: the run keeps the previous watermark and the next sync, with no change in ClickUp, imports their values.
     */
    public function testAFieldChangedUnderTheRunDefersItsTasksToTheNextSync(): void
    {
        $board   = $this->board();
        $options = [['id' => 'o1', 'name' => 'Draft', 'color' => null], ['id' => 'o2', 'name' => 'Live', 'color' => null]];
        $this->linked($board, 'fStage=create');
        $this->put($this->withFields('t1', 'Task', 1, [new RemoteCustomField('fStage', 'Stage', SyncFieldType::Dropdown, 'drop_down', $options, [], 'o1', 'Draft')]));
        $this->sync($board);
        self::assertSame(['Stage' => 'Draft'], $this->displayed('Task'));
        $before = $this->em->getRepository(TaskBoardConnection::class)->findOneBy(['board' => $board->getId()])?->getWatermark();
        self::assertInstanceOf(DateTimeImmutable::class, $before);

        // The option merge of the next run finds the field changed under its snapshot (as inside a host's older REPEATABLE
        // READ transaction): the row it reads again is older than the locked one.
        $stale = $this->staleSnapshot();
        $this->put($this->withFields('t1', 'Task', 10_000, [new RemoteCustomField('fStage', 'Stage', SyncFieldType::Dropdown, 'drop_down', $options, [], 'o2', 'Live')]));
        try {
            $report = $this->sync($board);
        } finally {
            $this->forget($stale);
        }
        self::assertTrue($stale->done);
        self::assertContains('native_field_changed', $this->warnings($report));
        self::assertSame([1, 0], [$report->stat(SyncNativeFieldWriter::DEFERRED), $report->stat('fields.native_values')]);
        self::assertSame(['Stage' => 'Draft'], $this->displayed('Task'), 'neither native nor synced in that run');
        self::assertSame([], $this->syncedNames());
        $this->em->clear();
        self::assertEquals($before, $this->em->getRepository(TaskBoardConnection::class)->findOneBy(['board' => $board->getId()])?->getWatermark(), 'the watermark is kept');

        // No change in ClickUp: the next sync imports the value.
        $report = $this->sync($board);
        self::assertNotContains('native_field_changed', $this->warnings($report));
        self::assertSame(['Stage' => 'Live'], $this->displayed('Task'));
    }

    public function testAFieldCreatedByAnotherWriterBeforeTheLockIsTaken(): void
    {
        $board = $this->freshBoard($this->board());
        $db    = $this->em->getConnection();
        // Asked by defineFromSource() after its first look, before it locks the board: the other writer creates the field.
        ScriptedRestructurePolicy::$meanwhile = static function () use ($db, $board): void {
            $db->executeStatement("INSERT INTO task_board_custom_fields (id, created_at, name, options, field_type, tenant_key, board_id, position, source_ref, source_options, version) VALUES ('00000000-0000-4000-8000-0000000000aa', '2026-11-26 10:00:00', 'Theirs', '[]', 'text', NULL, ?, 5, 'clickup:race', NULL, 1)", [$board->getId()]);
        };
        $field = $this->fields()->defineFromSource($this->owner, $board, 'clickup:race', 'Mine', CustomFieldType::Text);
        self::assertSame(['00000000-0000-4000-8000-0000000000aa', 'Theirs'], [$field?->getId(), $field?->getName()], 'the winner\'s field, no second one');
    }

    public function testADuplicateMarkerOnTheUniqueKeyIsReadAgainNotAFailure(): void
    {
        $board = $this->board();
        $this->fields()->define($this->owner, $this->freshBoard($board), null, 'Already here', CustomFieldType::Text);
        $this->em->clear();
        // A writer that skipped the board lock inserts the marker inside this write, after the look under the lock
        // (here: while the board's fields are loaded to pick a free name), before the insert.
        $listener = $this->duplicateWriter($board->getId(), 'clickup:dup', '00000000-0000-4000-8000-0000000000bb');
        try {
            $field = $this->fields()->defineFromSource($this->owner, $this->freshBoard($board), 'clickup:dup', 'Mine', CustomFieldType::Text);
        } finally {
            $this->forget($listener);
        }
        self::assertTrue($listener->done);
        self::assertSame(['00000000-0000-4000-8000-0000000000bb', 'Theirs'], [$field?->getId(), $field?->getName()], 'the winner\'s field, read again');
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM task_board_custom_fields WHERE source_ref = 'clickup:dup'"));
    }

    public function testADuplicateMarkerDuringASyncIsReadAgainAndThePageGoesOn(): void
    {
        $board = $this->board();
        $this->fields()->define($this->owner, $this->freshBoard($board), null, 'Already here', CustomFieldType::Text);
        $this->linked($board, 'fOwner=create');
        $this->put($this->withFields('t1', 'Task', 1, [new RemoteCustomField('fOwner', 'Owner', SyncFieldType::Text, 'text', [], [], 'Ana', 'Ana')]));
        $this->em->clear();
        $listener = $this->duplicateWriter($board->getId(), 'clickup:fOwner', '00000000-0000-4000-8000-0000000000cc');
        try {
            $report = $this->sync($board);
        } finally {
            $this->forget($listener);
        }
        self::assertTrue($listener->done);
        self::assertSame(['succeeded', 1], [$report->status->value, $report->created], 'the page is saved');
        $task = $this->taskTitled('Task');
        self::assertSame('Ana', $this->valueOf($task->getId(), '00000000-0000-4000-8000-0000000000cc'), 'the value lands on the winner\'s field');
    }

    public function testAPersonsChangeBetweenTheImportsReadAndItsWriteWins(): void
    {
        $board = $this->board();
        $a     = $this->fields()->define($this->owner, $board, null, 'Alpha', CustomFieldType::Text);
        $b     = $this->fields()->define($this->owner, $board, null, 'Beta', CustomFieldType::Text);
        $this->linked($board, 'fA=' . $a->getId() . "\nfB=" . $b->getId());
        $this->put($this->withFields('t1', 'Task', 1, [
            new RemoteCustomField('fA', 'A', SyncFieldType::Text, 'text', [], [], 'one', 'one'),
            new RemoteCustomField('fB', 'B', SyncFieldType::Text, 'text', [], [], 'one', 'one'),
        ]));
        $this->sync($board);
        $task = $this->taskTitled('Task');
        $db   = $this->em->getConnection();
        // The next run reads Alpha, then asks the policy for Beta: a person saves Alpha right then.
        ScriptedRestructurePolicy::$after     = 1;
        ScriptedRestructurePolicy::$meanwhile = static function () use ($db, $task, $a): void {
            $db->executeStatement('UPDATE task_board_custom_field_values SET text_value = ? WHERE task_id = ? AND field_id = ?', ['person', $task->getId(), $a->getId()]);
        };
        $this->put($this->withFields('t1', 'Task', 2, [
            new RemoteCustomField('fA', 'A', SyncFieldType::Text, 'text', [], [], 'two', 'two'),
            new RemoteCustomField('fB', 'B', SyncFieldType::Text, 'text', [], [], 'two', 'two'),
        ]));
        $report = $this->sync($board);
        self::assertSame([1, 1], [$report->stat('fields.native_conflicts'), $report->stat('fields.native_values')]);
        self::assertSame(['person', 'two'], [$this->valueOf($task->getId(), $a->getId()), $this->valueOf($task->getId(), $b->getId())]);
        self::assertTrue($this->noted('Alpha', 'two'), implode(' | ', array_map(static fn (TaskComment $c): string => $c->getBody(), $this->em->getRepository(TaskComment::class)->findAll())));
    }

    public function testTwoProcessesCreatingOneMarkerMakeOneField(): void
    {
        $this->requireServer();
        $board   = $this->freshBoard($this->board());
        $process = proc_open([PHP_BINARY, __DIR__ . '/../Field/marker-race-worker.php', FunctionalKernel::databaseUrl(), $board->getId(), 'clickup:proc', '1.5'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        self::assertSame('locked', trim((string) fgets($pipes[1])), (string) stream_get_contents($pipes[2]));

        // This writer's first look sees nothing (not committed), then waits on the board's row and finds the other's field.
        $field = $this->fields()->defineFromSource($this->owner, $board, 'clickup:proc', 'Mine', CustomFieldType::Text);

        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        self::assertSame(0, proc_close($process), $output . $errors);
        self::assertSame('committed', trim($output));
        self::assertSame('00000000-0000-4000-8000-0000000000ff', $field?->getId());
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM task_board_custom_fields WHERE source_ref = 'clickup:proc'"));
    }

    public function testAValueAnotherProcessHoldsIsNotOverwrittenByTheImport(): void
    {
        $this->requireServer();
        $board = $this->board();
        $field = $this->fields()->define($this->owner, $board, null, 'Owner', CustomFieldType::Text);
        $this->linked($board, 'fOwner=' . $field->getId());
        $this->put($this->withFields('t1', 'Task', 1, [new RemoteCustomField('fOwner', 'Owner', SyncFieldType::Text, 'text', [], [], 'one', 'one')]));
        $this->sync($board);
        $task = $this->taskTitled('Task');

        // A person's save holds the task's row with "theirs" written, not committed: the import reads "one" (committed),
        // decides to write, waits on the row, then finds "theirs" and keeps it.
        $process = proc_open([PHP_BINARY, __DIR__ . '/../Field/field-race-worker.php', FunctionalKernel::databaseUrl(), $task->getId(), $field->getId(), 'theirs', '1.5'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        self::assertSame('locked', trim((string) fgets($pipes[1])), (string) stream_get_contents($pipes[2]));
        $this->put($this->withFields('t1', 'Task', 2, [new RemoteCustomField('fOwner', 'Owner', SyncFieldType::Text, 'text', [], [], 'two', 'two')]));
        $report = $this->sync($board);

        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        self::assertSame(0, proc_close($process), $output . $errors);
        self::assertSame('committed', trim($output));
        self::assertSame(1, $report->stat('fields.native_conflicts'));
        self::assertSame('theirs', $this->valueOf($task->getId(), $field->getId()));
    }

    public function testApplyingABoardConfigurationThatDropsAnImportedFieldKeepsItsTombstone(): void
    {
        $board = $this->freshBoard($this->board());
        $field = $this->fields()->defineFromSource($this->owner, $board, 'clickup:cfg', 'Imported', CustomFieldType::Text);
        self::assertInstanceOf(TaskCustomField::class, $field);
        $applier = self::getContainer()->get(BoardConfigurationApplier::class);
        self::assertInstanceOf(BoardConfigurationApplier::class, $applier);
        $exporter = self::getContainer()->get(BoardConfigurationExporter::class);
        self::assertInstanceOf(BoardConfigurationExporter::class, $exporter);
        $configuration = $exporter->export($this->freshBoard($board));
        $applier->apply($this->owner, $this->freshBoard($board), BoardConfiguration::fromArray(['fields' => []] + $configuration->toArray()));
        self::assertTrue($this->fields()->isDeletedSource($this->freshBoard($board), 'clickup:cfg'));
    }

    /**
     * Inserts a field of the marker on the first entity load of a TaskCustomField inside a transaction.
     */
    private function duplicateWriter(string $boardId, string $marker, string $id): object
    {
        $em = self::getContainer()->get('app.task_board_em');
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $listener = new class($boardId, $marker, $id) {
            public bool $done = false;

            public function __construct(private readonly string $boardId, private readonly string $marker, private readonly string $id)
            {
            }

            public function postLoad(PostLoadEventArgs $args): void
            {
                if ($this->done || !$args->getObject() instanceof TaskCustomField || $args->getObjectManager()->getConnection()->getTransactionNestingLevel() === 0) {
                    return;
                }
                $this->done = true;
                $args->getObjectManager()->getConnection()->executeStatement("INSERT INTO task_board_custom_fields (id, created_at, name, options, field_type, tenant_key, board_id, position, source_ref, source_options, version) VALUES (?, '2026-11-26 10:00:00', 'Theirs', '[]', 'text', NULL, ?, 5, ?, NULL, 1)", [$this->id, $this->boardId, $this->marker]);
            }
        };
        $em->getEventManager()->addEventListener([Events::postLoad], $listener);

        return $listener;
    }

    /** Audit ZCFv2-L1: the next locked re-read of a definition (inside a write) returns an older version, once. */
    private function staleSnapshot(): object
    {
        $em = self::getContainer()->get('app.task_board_em');
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $listener = new class {
            public bool $done = false;

            public function postLoad(PostLoadEventArgs $args): void
            {
                $field = $args->getObject();
                if ($this->done || !$field instanceof TaskCustomField || $args->getObjectManager()->getConnection()->getTransactionNestingLevel() === 0) {
                    return;
                }
                $this->done = true;
                (new ReflectionProperty(TaskCustomField::class, 'version'))->setValue($field, $field->getVersion() - 1);
            }
        };
        $em->getEventManager()->addEventListener([Events::postLoad], $listener);

        return $listener;
    }

    /**
     * @return array<string, string>
     */
    private function displayed(string $title): array
    {
        $this->em->clear();
        $this->refreshOwner();
        $task   = $this->taskTitled($title);
        $values = [];
        foreach ($this->fields()->valuesOf([$task->getId()])[$task->getId()] ?? [] as $value) {
            $values[$value->getField()->getName()] = $this->fields()->display($value);
        }

        return $values;
    }

    private function forget(object $listener): void
    {
        $em = self::getContainer()->get('app.task_board_em');
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->getEventManager()->removeEventListener([Events::postLoad], $listener);
    }

    private function requireServer(): void
    {
        if ($this->em->getConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped('Two processes need a database server (TASK_BOARD_TEST_DATABASE_URL: MySQL, MariaDB, PostgreSQL).');
        }
    }

    private function linked(TaskBoard $board, string $native): void
    {
        $credential = new ProviderCredential(TestCredentialResolver::$secret, 'conn-1');
        $container  = $this->provider->findContainer($credential, self::LIST);
        self::assertInstanceOf(RemoteContainer::class, $container);
        $this->service()->link($this->freshBoard($board), $this->provider, $credential, $container, $this->owner, [TaskBoardConnection::OPTION_NATIVE_FIELDS => $native]);
    }

    /**
     * @param list<RemoteCustomField> $fields
     */
    private function withFields(string $id, string $title, int $updated, array $fields): RemoteTask
    {
        return new RemoteTask(
            task: new ImportedTaskDto(title: $title, externalId: $id, status: 'To do', priority: TaskPriority::Normal, hasPriority: false),
            remoteUpdatedAt: new DateTimeImmutable('@' . (1_790_000_000 + $updated)),
            customFields: $fields,
        );
    }

    /**
     * @return list<string>
     */
    private function warnings(SyncReport $report): array
    {
        return array_map(static fn (SyncError $warning): string => $warning->code, $report->warnings);
    }

    /**
     * @return list<string>
     */
    private function syncedNames(): array
    {
        $names = array_map(static fn (TaskSyncField $field): string => $field->getName(), $this->em->getRepository(TaskSyncField::class)->findAll());
        sort($names);

        return $names;
    }

    private function valueOf(string $taskId, string $fieldId): mixed
    {
        return $this->em->getConnection()->fetchOne('SELECT text_value FROM task_board_custom_field_values WHERE task_id = ? AND field_id = ?', [$taskId, $fieldId]);
    }

    private function noted(string $field, string $value): bool
    {
        foreach ($this->em->getRepository(TaskComment::class)->findAll() as $comment) {
            if (str_contains($comment->getBody(), '**' . $field . '**: ' . $value)) {
                return true;
            }
        }

        return false;
    }

    private function fields(): TaskCustomFieldManager
    {
        $service = self::getContainer()->get(TaskCustomFieldManager::class);
        self::assertInstanceOf(TaskCustomFieldManager::class, $service);

        return $service;
    }
}
