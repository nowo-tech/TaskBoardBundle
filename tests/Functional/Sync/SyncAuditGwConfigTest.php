<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Import\Dto\ImportedTaskDto;
use Nowo\TaskBoardBundle\Sync\Dto\SyncError;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncFieldValue;
use Nowo\TaskBoardBundle\Sync\Field\SyncFieldType;
use Nowo\TaskBoardBundle\Sync\Provider\ContainerType;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteContainer;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteCustomField;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteTask;

use function array_map;
use function array_replace_recursive;

/**
 * Since 1.27.0 (audit GW): `sync.custom_fields.exclude_types`, `sync.comments.max_reply_calls_per_run` and the detail
 * budget: what a run does not read is said in the completeness report.
 */
final class SyncAuditGwConfigTest extends SyncFunctionalTestCase
{
    protected static function syncConfig(): array
    {
        return array_replace_recursive(parent::syncConfig(), ['sync' => [
            'custom_fields' => ['mode' => 'typed', 'exclude_types' => ['email']],
            'comments'      => ['max_reply_calls_per_run' => 3],
            'watchers'      => ['max_detail_calls_per_run' => 0],
        ]]);
    }

    public function testExcludedTypesAndBudgets(): void
    {
        $board    = $this->board();
        $provider = new FullTaskProvider();
        $provider->addContainer(new RemoteContainer(self::LIST, 'Backlog', ContainerType::List, null, true, false, 1));
        $this->provider    = $provider;
        $provider->threads = 2;
        $fields            = [
            new RemoteCustomField('f1', 'Contact', SyncFieldType::Email, 'email', [], [], 'zebulon@people.test', 'zebulon@people.test'),
            new RemoteCustomField('f2', 'Stage', SyncFieldType::Text, 'text', [], [], 'Draft', 'Draft'),
        ];
        $provider->putTask(self::LIST, $this->row('t1', 1, $fields));
        $provider->putTask(self::LIST, $this->row('t2', 2, []));

        $report = $this->sync($board, $provider);
        self::assertSame(1, $report->stat('fields.excluded'));
        self::assertSame(1, $this->em->getRepository(TaskSyncFieldValue::class)->count([]));
        self::assertStringNotContainsString('zebulon', (string) $this->em->getConnection()->fetchOne('SELECT value_text FROM task_board_sync_field_values'));
        // Three requests of the four the two tasks wanted; the budget holds across tasks.
        self::assertSame([3, 1], [$report->stat('comments.reply_calls'), $report->stat('comments.replies_unread')]);
        self::assertContains('replies_budget', array_map(static fn (SyncError $warning): string => $warning->code, $report->warnings));
        self::assertSame(2, $report->stat('attachments.unread'), 'no budget for the detail');
        self::assertSame(0, $provider->detailCalls);

        $this->login($this->owner);
        $crawler = $this->client->request('GET', '/tools/task-board/board/' . $board->getId() . '/connection');
        self::assertSame('not_read', $crawler->filter('[data-completeness-key="comment_replies"]')->attr('data-completeness-status'));
        self::assertSame('not_read', $crawler->filter('[data-completeness-key="attachments"]')->attr('data-completeness-status'));
    }

    /**
     * @param list<RemoteCustomField> $fields
     */
    private function row(string $id, int $updated, array $fields): RemoteTask
    {
        return new RemoteTask(
            task: new ImportedTaskDto(title: $id, externalId: $id, status: 'To do', priority: TaskPriority::Normal, hasPriority: false),
            remoteUpdatedAt: new DateTimeImmutable('@' . (1_790_000_000 + $updated)),
            customFields: $fields,
            attachmentsKnown: false,
        );
    }
}
