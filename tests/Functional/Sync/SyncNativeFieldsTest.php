<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Entity\TaskCustomField;
use Nowo\TaskBoardBundle\Enum\CustomFieldType;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Event\BoardAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Field\TaskCustomFieldManager;
use Nowo\TaskBoardBundle\Import\Dto\ImportedTaskDto;
use Nowo\TaskBoardBundle\Sync\Dto\SyncError;
use Nowo\TaskBoardBundle\Sync\Entity\TaskBoardConnection;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncField;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncFieldValue;
use Nowo\TaskBoardBundle\Sync\Field\SyncFieldType;
use Nowo\TaskBoardBundle\Sync\Field\SyncNativeFieldWriter;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCredential;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteContainer;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteCustomField;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteTask;
use Nowo\TaskBoardBundle\Tests\Functional\App\TestCredentialResolver;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use function array_map;
use function ksort;
use function sort;
use function str_contains;
use function substr_count;

/**
 * Since 3.3.0 (CU-3, design §11): provider fields landed on native fields — created once per board and marker, values
 * written while nobody changed them here, a local edit (or a value that existed before) kept and noted once, an empty
 * provider value never clearing one, dropdown options followed by id and never recreated, the synced copy left out.
 */
final class SyncNativeFieldsTest extends SyncFunctionalTestCase
{
    public function testNativeFieldsFollowTheProviderUntilChangedHere(): void
    {
        $board  = $this->board();
        $points = $this->fields()->define($this->owner, $board, null, 'Story points', CustomFieldType::Number);
        $this->linked($board, "fText=create\nfNum=" . $points->getId() . "\nfDrop=create\nfDate=create\nfGone=00000000-0000-4000-8000-000000000000\nfMis=create");
        $this->put($this->withFields('t1', 'Website', 1, [
            new RemoteCustomField('fText', 'Contact', SyncFieldType::Text, 'short_text', [], [], 'Ana', 'Ana'),
            new RemoteCustomField('fNum', 'Points', SyncFieldType::Number, 'number', [], [], '3.50', '3.50'),
            new RemoteCustomField('fDrop', 'Stage', SyncFieldType::Dropdown, 'drop_down', [['id' => 'o1', 'name' => 'Draft', 'color' => '#ff0000'], ['id' => 'o2', 'name' => 'Live', 'color' => null]], [], 'o1', 'Draft'),
            new RemoteCustomField('fDate', 'Due', SyncFieldType::Date, 'date', [], [], '2026-09-21', '2026-09-21'),
            new RemoteCustomField('fGone', 'Gone', SyncFieldType::Text, 'text', [], [], 'x', 'x'),
            new RemoteCustomField('fMis', 'Misfit', SyncFieldType::Number, 'number', [], [], 'n/a', 'n/a', true),
            new RemoteCustomField('fEmpty', 'Empty', SyncFieldType::Text, 'text'),
        ]));
        $first = $this->sync($board);
        self::assertSame(3, $first->stat('fields.native_fields'), 'Contact, Stage and Misfit are created; Points exists');
        self::assertSame(3, $first->stat('fields.native_values'));
        self::assertSame(1, $first->stat('fields.native_misfit'));
        self::assertContains('native_field_unavailable', array_map(static fn (SyncError $w): string => $w->code, $first->warnings));
        self::assertSame(['Due', 'Empty', 'Gone'], $this->syncedNames(), 'the date, an unmapped field and an unavailable target stay synced; native ones are not copied');
        self::assertSame(['Contact' => 'Ana', 'Stage' => 'Draft', 'Story points' => '3.5'], $this->values('Website'));
        $contact = $this->fields()->sourceField($this->freshBoard($board), 'clickup:fText');
        self::assertInstanceOf(TaskCustomField::class, $contact);

        // Unchanged: nothing written. Changed there and not here: followed (renamed option keeps its id).
        self::assertSame(0, $this->sync($board, full: true)->stat('fields.native_values'));
        $this->put($this->withFields('t1', 'Website', 2, [
            new RemoteCustomField('fText', 'Contact person', SyncFieldType::Text, 'short_text', [], [], 'Ana R', 'Ana R'),
            new RemoteCustomField('fNum', 'Points', SyncFieldType::Number, 'number', [], [], '5', '5'),
            new RemoteCustomField('fDrop', 'Stage', SyncFieldType::Dropdown, 'drop_down', [['id' => 'o1', 'name' => 'Borrador', 'color' => null], ['id' => 'o2', 'name' => 'Live', 'color' => null]], [], 'o2', 'Live'),
        ]));
        $second = $this->sync($board);
        self::assertSame(3, $second->stat('fields.native_values'));
        self::assertSame(['Contact' => 'Ana R', 'Stage' => 'Live', 'Story points' => '5'], $this->values('Website'), 'a field is found by its marker, not its name');

        // Changed here: kept, the provider's value noted once; an empty provider value clears nothing.
        $task = $this->taskTitled('Website');
        $this->fields()->setField($task, $contact->getId(), 'Ana (local)', $this->owner);
        $this->put($this->withFields('t1', 'Website', 3, [
            new RemoteCustomField('fText', 'Contact', SyncFieldType::Text, 'short_text', [], [], 'Bob', 'Bob'),
            new RemoteCustomField('fNum', 'Points', SyncFieldType::Number, 'number'),
            new RemoteCustomField('fDrop', 'Stage', SyncFieldType::Dropdown, 'drop_down', [['id' => 'o2', 'name' => 'Live', 'color' => null]], [], 'o2', 'Live'),
        ]));
        $third = $this->sync($board);
        self::assertSame(1, $third->stat('fields.native_conflicts'));
        self::assertSame(['Contact' => 'Ana (local)', 'Stage' => 'Live', 'Story points' => '5'], $this->values('Website'));
        self::assertTrue($this->noted('Contact', 'Bob'));
        $this->put($this->withFields('t1', 'Website', 4, [new RemoteCustomField('fText', 'Contact', SyncFieldType::Text, 'short_text', [], [], 'Bob', 'Bob')]));
        self::assertSame(0, $this->sync($board)->stat('fields.native_conflicts'), 'the same value is noted once');

        // Both sides agree again: the baseline follows, later provider changes apply.
        $this->fields()->setField($this->taskTitled('Website'), $contact->getId(), 'Carla', $this->owner);
        $this->put($this->withFields('t1', 'Website', 5, [new RemoteCustomField('fText', 'Contact', SyncFieldType::Text, 'short_text', [], [], 'Carla', 'Carla')]));
        self::assertSame(0, $this->sync($board)->stat('fields.native_conflicts'));
        $this->put($this->withFields('t1', 'Website', 6, [new RemoteCustomField('fText', 'Contact', SyncFieldType::Text, 'short_text', [], [], 'Dora', 'Dora')]));
        $this->sync($board);
        self::assertSame('Dora', $this->values('Website')['Contact']);

        // An option deleted here is not recreated: its value is a conflict.
        $stage = $this->fields()->sourceField($this->freshBoard($board), 'clickup:fDrop');
        self::assertInstanceOf(TaskCustomField::class, $stage);
        $live = $stage->optionNamed('Live')['id'] ?? '';
        $this->fields()->update($this->owner, $stage, 'Stage', [['id' => $live, 'name' => 'Live']], $stage->getPosition());
        $this->put($this->withFields('t1', 'Website', 7, [new RemoteCustomField('fDrop', 'Stage', SyncFieldType::Dropdown, 'drop_down', [['id' => 'o1', 'name' => 'Borrador', 'color' => null]], [], 'o1', 'Borrador')]));
        $fourth = $this->sync($board);
        self::assertSame(1, $fourth->stat('fields.native_conflicts'));
        self::assertTrue($this->noted('Stage', 'Borrador'));
        self::assertSame(['Live'], array_map(static fn (array $o): string => $o['name'], $this->fields()->find($stage->getId())?->getOptions() ?? []));
    }

    public function testAValueSetHereBeforeTheProviderHadOneIsKept(): void
    {
        $board = $this->board();
        $field = $this->fields()->define($this->owner, $board, null, 'Owner', CustomFieldType::Text);
        $this->linked($board, 'fOwner=' . $field->getId());
        $empty = [new RemoteCustomField('fOwner', 'Owner', SyncFieldType::Text, 'text')];
        $this->put($this->withFields('t1', 'Mine', 1, $empty), $this->withFields('t2', 'Same', 2, $empty));
        $this->sync($board);
        $this->fields()->setField($this->taskTitled('Mine'), $field->getId(), 'Set here', $this->owner);
        $this->fields()->setField($this->taskTitled('Same'), $field->getId(), 'Agreed', $this->owner);
        $this->put(
            $this->withFields('t1', 'Mine', 3, [new RemoteCustomField('fOwner', 'Owner', SyncFieldType::Text, 'text', [], [], 'Remote', 'Remote')]),
            $this->withFields('t2', 'Same', 4, [new RemoteCustomField('fOwner', 'Owner', SyncFieldType::Text, 'text', [], [], 'Agreed', 'Agreed')]),
        );
        $report = $this->sync($board);
        self::assertSame([1, 0], [$report->stat('fields.native_conflicts'), $report->stat('fields.native_values')]);
        self::assertSame(['Owner' => 'Set here'], $this->values('Mine'));
        self::assertTrue($this->noted('Owner', 'Remote'));
        // The agreed value took the provider's as its baseline: a later provider change applies.
        $this->put($this->withFields('t2', 'Same', 5, [new RemoteCustomField('fOwner', 'Owner', SyncFieldType::Text, 'text', [], [], 'Next', 'Next')]));
        $this->sync($board);
        self::assertSame(['Owner' => 'Next'], $this->values('Same'));
    }

    public function testAnActorWhoMayNotRestructureGetsNoNewFieldAndAnInvalidValueIsCounted(): void
    {
        $board = $this->board();
        $this->linked($board, "fNew=create\nfNum=create");
        $num = $this->fields()->defineFromSource($this->owner, $this->freshBoard($board), 'clickup:fNum', 'Points', CustomFieldType::Number);
        self::assertInstanceOf(TaskCustomField::class, $num);
        $this->put($this->withFields('t1', 'Task', 1, [
            new RemoteCustomField('fNew', 'New', SyncFieldType::Text, 'text', [], [], 'v', 'v'),
            new RemoteCustomField('fNum', 'Points', SyncFieldType::Number, 'number', [], [], '1e5', '1e5'),
        ]));
        // The owner keeps the connection but loses the manage right (the restructure policy's default).
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(TaskBoardEvents::BOARD_ACCESS_CHECK, static function (BoardAccessCheckEvent $event): void {
            if ($event->getAction() === BoardAccessCheckEvent::ACTION_MANAGE) {
                $event->deny();
            }
        });
        $report = $this->sync($board);
        self::assertContains('native_field_refused', array_map(static fn (SyncError $w): string => $w->code, $report->warnings));
        self::assertSame(1, $report->stat('fields.native_invalid'));
        self::assertSame([], $this->values('Task'));
        self::assertTrue(SyncNativeFieldWriter::nativeType('drop_down') === CustomFieldType::Dropdown && !SyncNativeFieldWriter::nativeType('labels') instanceof CustomFieldType);
    }

    private function linked(TaskBoard $board, string $native): void
    {
        $credential = new ProviderCredential(TestCredentialResolver::$secret, 'conn-1');
        $container  = $this->provider->findContainer($credential, self::LIST);
        self::assertInstanceOf(RemoteContainer::class, $container);
        $this->service()->link($this->freshBoard($board), $this->provider, $credential, $container, $this->owner, [TaskBoardConnection::OPTION_NATIVE_FIELDS => $native . "\nmalformed"]);
        self::assertCount(substr_count($native, "\n") + 1, $this->connectionOf($board)?->nativeFields() ?? []);
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
     * @return array<string, string> field name => value as people read it
     */
    private function values(string $title): array
    {
        $this->em->clear();
        $this->refreshOwner();
        $task   = $this->taskTitled($title);
        $values = [];
        foreach ($this->fields()->valuesOf([$task->getId()])[$task->getId()] ?? [] as $value) {
            $values[$value->getField()->getName()] = $this->fields()->display($value);
        }
        ksort($values);

        return $values;
    }

    /**
     * @return list<string>
     */
    private function syncedNames(): array
    {
        $names = array_map(static fn (TaskSyncField $field): string => $field->getName(), $this->em->getRepository(TaskSyncField::class)->findAll());
        sort($names);
        self::assertCount(2, $this->em->getRepository(TaskSyncFieldValue::class)->findAll());

        return $names;
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
