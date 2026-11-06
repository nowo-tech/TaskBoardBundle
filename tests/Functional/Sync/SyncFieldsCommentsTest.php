<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Entity\TaskDocument;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Import\Dto\ImportedTaskDto;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncComment;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncField;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncFieldValue;
use Nowo\TaskBoardBundle\Sync\Field\SyncFieldType;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteAttachment;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteComment;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteCustomField;
use Nowo\TaskBoardBundle\Sync\Provider\RemotePerson;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteRole;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteTask;
use Nowo\TaskBoardBundle\Tests\Functional\App\FixtureHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function array_replace_recursive;
use function str_contains;
use function strlen;

/**
 * Since 1.27.0 (TB-SYNC-3): typed custom fields (definitions per board, values per task, shown in the task panel and
 * found by the list search; a re-sync is idempotent and a removed value goes) and comments in full (threads, files,
 * assigned comments, resolved state, edit date).
 */
final class SyncFieldsCommentsTest extends SyncPeopleFunctionalTestCase
{
    private const BASE = '/tools/task-board/';

    protected static function syncConfig(): array
    {
        return array_replace_recursive(parent::syncConfig(), ['sync' => ['custom_fields' => ['mode' => 'typed']]]);
    }

    public function testTypedFieldsArePanelValuesSearchableAndIdempotent(): void
    {
        $board = $this->board();
        $this->put($this->withFields('t1', 'Website', 1, [
            new RemoteCustomField('f1', 'Budget', SyncFieldType::Money, 'currency', [], ['currency' => 'EUR'], '1500', '1500 EUR'),
            new RemoteCustomField('f2', 'Stage', SyncFieldType::Dropdown, 'drop_down', [['id' => 'o1', 'name' => 'Draft', 'color' => '#ff0000'], ['id' => 'o2', 'name' => 'Live', 'color' => null]], [], 'o2', 'Live'),
            new RemoteCustomField('f3', 'Done', SyncFieldType::Checkbox, 'checkbox', [], [], true, ''),
            new RemoteCustomField('f4', 'Site', SyncFieldType::Url, 'url', [], [], 'https://example.com/<x>', 'https://example.com/<x>'),
            new RemoteCustomField('f5', 'Stars', SyncFieldType::Rating, 'emoji', [], ['max' => 3], 2, '2/3'),
            new RemoteCustomField('f6', 'Progress', SyncFieldType::Progress, 'manual_progress', [], [], 40, '40%'),
            new RemoteCustomField('f7', 'Due', SyncFieldType::Date, 'date', [], [], '2026-09-21T14:13:20+00:00', '2026-09-21'),
            new RemoteCustomField('f8', 'Mail', SyncFieldType::Email, 'email', [], [], 'ana@example.com', 'ana@example.com'),
            new RemoteCustomField('f9', 'Areas', SyncFieldType::Labels, 'labels', [['id' => 'l1', 'name' => 'Web', 'color' => null]], [], ['l1'], 'Web'),
            new RemoteCustomField('f10', 'Notes', SyncFieldType::Text, 'formula', [], [], '<script>x</script>', '<script>x</script>'),
            new RemoteCustomField('f11', 'Unused', SyncFieldType::Text, 'text'),
            new RemoteCustomField('f12', 'Off', SyncFieldType::Checkbox, 'checkbox', [], [], false, ''),
        ]));
        $report = $this->sync($board);
        self::assertSame(12, $report->stat('fields.defined'));
        self::assertSame(11, $report->stat('fields.values'));
        self::assertSame(0, $this->rows(TaskDocument::class), 'typed mode: no "Fields" document');

        $task = $this->taskTitled('Website');
        $this->login($this->owner);
        $crawler = $this->client->request('GET', self::BASE . 'task/' . $task->getId());
        self::assertResponseIsSuccessful();
        $section = $crawler->filter('[data-testid="nowo-task-sync-fields"]');
        self::assertCount(11, $section->filter('[data-field-type]'));
        self::assertStringContainsString('1500 EUR', $section->text());
        self::assertStringContainsString('Live', $section->filter('[data-field-type="dropdown"]')->text());
        self::assertSame('https://example.com/<x>', $section->filter('[data-field-type="url"] a')->attr('href'));
        self::assertSame('noopener noreferrer nofollow', $section->filter('[data-field-type="url"] a')->attr('rel'));
        self::assertStringContainsString('&lt;script&gt;', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('★★☆', $section->filter('[data-field-type="rating"]')->text());
        self::assertSame('40', $section->filter('progress')->attr('value'));
        self::assertStringContainsString('Yes', $section->filter('[data-field-type="checkbox"]')->first()->text());
        self::assertStringContainsString('No', $section->filter('[data-field-type="checkbox"]')->last()->text());

        // The list search finds the values.
        $crawler = $this->client->request('GET', self::BASE . 'board/' . $board->getId() . '/list');
        self::assertStringContainsString('1500 EUR', (string) $crawler->filter('[data-title="Website"]')->attr('data-search-extra'));

        // A second run writes nothing; a value the provider drops goes; a renamed field is renamed.
        $again = $this->sync($board, full: true);
        self::assertSame(0, $again->stat('fields.values'));
        $this->put($this->withFields('t1', 'Website', 2, [new RemoteCustomField('f1', 'Budget (EUR)', SyncFieldType::Money, 'currency', [], ['currency' => 'EUR'], '1600', '1600 EUR')]));
        $third = $this->sync($board);
        self::assertSame(1, $third->stat('fields.values'));
        self::assertSame(10, $third->stat('fields.cleared'));
        self::assertSame(1, $this->rows(TaskSyncFieldValue::class));
        $field = $this->em->getRepository(TaskSyncField::class)->findOneBy(['remoteId' => 'f1']);
        self::assertSame('Budget (EUR)', $field?->getName());
        self::assertSame(SyncFieldType::Money, $field?->getType());
        self::assertSame('Draft', $this->em->getRepository(TaskSyncField::class)->findOneBy(['remoteId' => 'f2'])?->choiceName('o1'));
        self::assertSame('zz', $this->em->getRepository(TaskSyncField::class)->findOneBy(['remoteId' => 'f2'])?->choiceName('zz'));
        self::assertInstanceOf(TaskSyncField::class, $field);
        self::assertSame([36, $board->getId(), 'clickup', 'currency', 0], [strlen($field->getId()), $field->getBoard()->getId(), $field->getProvider(), $field->getRemoteType(), $field->getPosition()]);
        self::assertInstanceOf(DateTimeImmutable::class, $field->getUpdatedAt());
        $value = $this->em->getRepository(TaskSyncFieldValue::class)->findOneBy([]);
        self::assertInstanceOf(TaskSyncFieldValue::class, $value);
        self::assertSame([36, 'Website', '1600'], [strlen($value->getId()), $value->getTask()->getTitle(), $value->getValue()]);
        self::assertInstanceOf(DateTimeImmutable::class, $value->getUpdatedAt());
    }

    public function testCommentsInFull(): void
    {
        $board = $this->board();
        $ana   = $this->viewer('ana@example.com');
        $this->workspace([new RemotePerson('7', 'Ana R', 'ana@example.com', RemoteRole::Member), new RemotePerson('5', 'Bob', null, RemoteRole::Member)]);
        $this->put($this->row('t1', 'Talk', 1));
        $file = new RemoteAttachment('att1', 'spec [v2].pdf', 30, 'application/pdf', 'https://t000.p.clickup-attachments.com/att1/spec.pdf', new DateTimeImmutable('@1790000000'));
        $evil = new RemoteAttachment('att2', 'evil.pdf', 30, 'application/pdf', 'https://evil.example.com/evil.pdf');
        $this->provider->setComments('t1', [
            new RemoteComment('c1', 'Please check', null, 'Bob', new DateTimeImmutable('@1790000100'), author: self::ref('5', null, 'Bob'), attachments: [$file, $evil], assignee: self::ref('7', 'ana@example.com', 'Ana R'), resolved: false, updatedAt: new DateTimeImmutable('@1790000150')),
            new RemoteComment('r1', 'Done', null, 'Ana R', new DateTimeImmutable('@1790000200'), author: self::ref('7', 'ana@example.com', 'Ana R'), parentId: 'c1'),
        ]);
        FixtureHttpClient::$responder = static fn (string $m, string $url): MockResponse => str_contains($url, 'spec.pdf')
            ? new MockResponse("%PDF-1.4\n%\xE2\xE3\xCF\xD3\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF", ['http_code' => 200])
            : new MockResponse('', ['http_code' => 500]);

        $report = $this->sync($board);
        self::assertSame(2, $report->commentsCreated);
        self::assertSame(1, $report->files->copied, 'the file on a host outside the allow-list is refused');
        $comment = $this->em->getRepository(TaskComment::class)->findOneBy(['createdAt' => new DateTimeImmutable('@1790000100')]);
        self::assertInstanceOf(TaskComment::class, $comment);
        self::assertStringContainsString('[spec \\[v2\\].pdf](/files/', $comment->getBody());
        self::assertStringContainsString('- evil.pdf', $comment->getBody());
        $meta = $this->em->getRepository(TaskSyncComment::class)->findOneBy(['remoteId' => 'r1']);
        self::assertSame('c1', $meta?->getParentRemoteId());

        $this->login($this->owner);
        $crawler = $this->client->request('GET', self::BASE . 'task/' . $this->taskTitled('Talk')->getId());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Reply to Bob', $crawler->filter('[data-sync-comment-reply]')->text());
        self::assertStringContainsString('Assigned to Ana R', $crawler->filter('[data-sync-comment-assigned]')->text());
        self::assertSame('0', $crawler->filter('[data-sync-comment-resolved]')->attr('data-sync-comment-resolved'));
        self::assertStringContainsString('edited in the provider', (string) $this->client->getResponse()->getContent());

        // Resolving it changes the metadata only; an unchanged comment is not rewritten.
        $this->provider->setComments('t1', [
            new RemoteComment('c1', 'Please check', null, 'Bob', new DateTimeImmutable('@1790000100'), author: self::ref('5', null, 'Bob'), attachments: [$file, $evil], assignee: self::ref('7', 'ana@example.com', 'Ana R'), resolved: true),
            new RemoteComment('r2', 'Orphan reply', null, null, new DateTimeImmutable('@1790000300'), parentId: 'gone'),
        ]);
        $this->put($this->row('t1', 'Talk', 2));
        $again = $this->sync($board);
        self::assertSame([1, 0], [$again->commentsCreated, $again->commentsUpdated]);
        $this->em->clear();
        self::assertTrue($this->em->getRepository(TaskSyncComment::class)->findOneBy(['remoteId' => 'c1'])?->isResolved());
        $this->refreshOwner();
        $crawler = $this->client->request('GET', self::BASE . 'task/' . $this->taskTitled('Talk')->getId());
        self::assertSame(1, $crawler->filter('[data-sync-comment-resolved="1"]')->count());
        self::assertGreaterThanOrEqual(1, $crawler->filter('[data-sync-comment-reply]')->reduce(static fn ($node): bool => $node->text() === 'Reply')->count());
        self::assertNotNull($ana->getId());
    }

    /**
     * @param list<RemoteCustomField> $fields
     */
    private function withFields(string $id, string $title, int $updated, array $fields): RemoteTask
    {
        return new RemoteTask(
            task: new ImportedTaskDto(title: $title, externalId: $id, status: 'To do', priority: TaskPriority::Normal, hasPriority: false),
            remoteUpdatedAt: new DateTimeImmutable('@' . (1_790_000_000 + $updated)),
            customFieldsText: '- **Budget**: 1500',
            customFields: $fields,
        );
    }
}
