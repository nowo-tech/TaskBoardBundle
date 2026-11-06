<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Event\TaskErasedEvent;
use Nowo\TaskBoardBundle\Import\Dto\ImportedTaskDto;
use Nowo\TaskBoardBundle\Service\TaskBoardEraser;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncAttachment;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncFieldValue;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderErrorCode;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderException;
use Nowo\TaskBoardBundle\Sync\Field\SyncFieldType;
use Nowo\TaskBoardBundle\Sync\Field\SyncFieldValueEraser;
use Nowo\TaskBoardBundle\Sync\People\TaskSyncPersonRepository;
use Nowo\TaskBoardBundle\Sync\Provider\ContainerType;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteAttachment;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteComment;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteContainer;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteCustomField;
use Nowo\TaskBoardBundle\Sync\Provider\RemotePersonRef;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteTask;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteTaskDetails;
use Nowo\TaskBoardBundle\Tests\Functional\App\FixtureHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function array_replace_recursive;
use function hash_hmac;
use function json_encode;
use function range;

/**
 * Since 1.27.0: the regressions of the delta of audit GW. Files stay named after an unlink, a lasting detail error does
 * not stop a board, a suppression record is keyed and follows a change, unread state outlives the run and budgets
 * advance over full syncs, and one person's estimates, assignments and fields can be exported.
 */
final class SyncAuditGwDeltaTest extends SyncPeopleFunctionalTestCase
{
    private const BASE = '/tools/task-board/';

    private const PDF = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF";

    protected static function syncConfig(): array
    {
        return array_replace_recursive(parent::syncConfig(), ['sync' => [
            'comments' => ['max_reply_calls_per_run' => 2],
            'watchers' => ['max_detail_calls_per_run' => 2],
        ]]);
    }

    public function testAnUnlinkKeepsTheFilesNamedForALaterDeletion(): void
    {
        $board                        = $this->board();
        FixtureHttpClient::$responder = static fn (): MockResponse => new MockResponse(self::PDF, ['http_code' => 200]);
        $this->put($this->gwRow('t1', 1, attachments: [new RemoteAttachment('att1', 'own.pdf', 30, 'application/pdf', 'https://t000.p.clickup-attachments.com/att1/own.pdf', new DateTimeImmutable('@1790000000'))]));
        $this->provider->setComments('t1', [new RemoteComment('c1', 'Body', null, 'Bob', new DateTimeImmutable('@1790000100'), attachments: [new RemoteAttachment('att2', 'cv.pdf', 30, 'application/pdf', 'https://t000.p.clickup-attachments.com/att2/cv.pdf', new DateTimeImmutable('@1790000000'))])]);
        $this->sync($board);
        self::assertSame(2, $this->rows(TaskSyncAttachment::class));

        $this->service()->unlink($this->freshBoard($board), $this->owner);
        $this->em->clear();
        $this->refreshOwner();
        $rows = $this->em->getRepository(TaskSyncAttachment::class)->findAll();
        self::assertCount(2, $rows);
        self::assertNull($rows[0]->getItem());

        $refs = [];
        $this->dispatcher()->addListener(TaskBoardEvents::TASK_ERASED, static function (TaskErasedEvent $event) use (&$refs): void {
            $refs = [...$refs, ...$event->attachmentRefs];
        });
        $eraser = self::getContainer()->get('app.board_eraser');
        self::assertInstanceOf(TaskBoardEraser::class, $eraser);
        $eraser->deleteBoard($this->freshBoard($board), $this->owner);
        self::assertEqualsCanonicalizing(['file-1', 'file-2'], $refs);
    }

    public function testALastingDetailErrorDoesNotStopTheBoardAndIsReported(): void
    {
        $board    = $this->board();
        $provider = $this->gwProvider();
        $provider->putTask(self::LIST, $this->gwRow('t1', 1, known: false));
        $provider->putTask(self::LIST, $this->gwRow('t2', 2, known: false));
        $provider->details['t2']        = $this->gwRow('t2', 2);
        $provider->detailFailures['t1'] = new ProviderException(ProviderErrorCode::Forbidden);

        $report = $this->sync($board, $provider);
        self::assertSame([], $report->errors);
        self::assertSame([2, 1, 1], [$report->created, $report->stat('detail.failed'), $report->stat('attachments.unread')]);

        // The report keeps saying so on a later run that reads nothing.
        $this->sync($board, $provider);
        $this->login($this->owner);
        $crawler = $this->client->request('GET', self::BASE . 'board/' . $board->getId() . '/connection');
        self::assertSame(['unavailable', 'not_read'], [$crawler->filter('[data-completeness-key="task_detail"]')->attr('data-completeness-status'), $crawler->filter('[data-completeness-key="attachments"]')->attr('data-completeness-status')]);

        // A passing error is not lasting: the run stops as usual.
        $provider->detailFailures['t1'] = new ProviderException(ProviderErrorCode::RateLimited);
        $this->refreshOwner();
        self::assertNotSame([], $this->sync($board, $provider, full: true)->errors);
    }

    public function testASuppressionRecordIsKeyedAndFollowsAChange(): void
    {
        $board = $this->board();
        $this->workspace([self::member('7'), self::member('8')]);
        $mail   = new RemoteCustomField('f1', 'Contact', SyncFieldType::Email, 'email', [], [], 'zebulon@people.test', 'zebulon@people.test');
        $check  = new RemoteCustomField('f2', 'Done', SyncFieldType::Checkbox, 'checkbox', [], [], true, '');
        $people = new RemoteCustomField('f3', 'Owners', SyncFieldType::People, 'users', [], [], [['id' => '7', 'name' => 'User 7']], 'User 7');
        $this->put($this->gwRow('t1', 1, [$mail, $check, $people]));
        $this->sync($board);
        $eraser = self::getContainer()->get(SyncFieldValueEraser::class);
        self::assertInstanceOf(SyncFieldValueEraser::class, $eraser);
        self::assertSame(3, $eraser->eraseBoard($this->freshBoard($board)));

        // The row alone does not let a dictionary test the value.
        $this->em->clear();
        $this->refreshOwner();
        foreach ($this->em->getRepository(TaskSyncFieldValue::class)->findAll() as $value) {
            self::assertNotSame(hash_hmac('sha256', 'zebulon@people.test', $value->getId()), $value->suppressionRecord());
            self::assertStringNotContainsString('zebulon', (string) json_encode($value->getValue()));
        }

        // The same values stay erased.
        $this->put($this->gwRow('t1', 2, [$mail, $check, $people]));
        self::assertSame(0, $this->sync($board)->stat('fields.values'));

        // Changed ones come back, also those with no text.
        $this->put($this->gwRow('t1', 3, [
            $mail,
            new RemoteCustomField('f2', 'Done', SyncFieldType::Checkbox, 'checkbox', [], [], false, ''),
            new RemoteCustomField('f3', 'Owners', SyncFieldType::People, 'users', [], [], [['id' => '8', 'name' => 'User 8']], 'User 8'),
        ]));
        self::assertSame(2, $this->sync($board)->stat('fields.values'));
    }

    public function testUnreadStateOutlivesTheRunAndBudgetsAdvance(): void
    {
        $board    = $this->board();
        $provider = $this->gwProvider();
        foreach (range(1, 5) as $n) {
            // The rows lack watchers and checklists too: the budget must still reach the next tasks.
            $provider->putTask(self::LIST, $this->gwRow('t' . $n, $n, known: false, watchersKnown: false));
            $provider->details['t' . $n] = $this->gwRow('t' . $n, $n);
        }
        $provider->threads = 1;

        $first = $this->sync($board, $provider);
        self::assertSame([2, 2, 3, 3], [$first->stat('detail.calls'), $first->stat('comments.reply_calls'), $first->stat('attachments.unread'), $first->stat('comments.replies_unread')]);

        // An incremental run reads nothing and the report still says what is unread.
        $this->sync($board, $provider);
        $this->login($this->owner);
        $crawler = $this->client->request('GET', self::BASE . 'board/' . $board->getId() . '/connection');
        self::assertSame('3', $crawler->filter('[data-completeness-key="attachments"] td')->eq(1)->text());
        self::assertSame('not_read', $crawler->filter('[data-completeness-key="comment_replies"]')->attr('data-completeness-status'));

        // Each full sync reaches the next tasks, until nothing is left.
        $this->refreshOwner();
        $second = $this->sync($board, $provider, full: true);
        self::assertSame([2, 2, 1, 1], [$second->stat('detail.calls'), $second->stat('comments.reply_calls'), $second->stat('attachments.unread'), $second->stat('comments.replies_unread')]);
        $third = $this->sync($board, $provider, full: true);
        self::assertSame([1, 1, 0, 0], [$third->stat('detail.calls'), $third->stat('comments.reply_calls'), $third->stat('attachments.unread'), $third->stat('comments.replies_unread')]);
        $last = $this->sync($board, $provider, full: true);
        self::assertSame([0, 0], [$last->stat('detail.calls'), $last->stat('comments.reply_calls')]);
        $this->login($this->owner);
        $crawler = $this->client->request('GET', self::BASE . 'board/' . $board->getId() . '/connection');
        self::assertCount(0, $crawler->filter('[data-completeness-status="not_read"]'));
    }

    public function testExportOfOnePerson(): void
    {
        $board = $this->board();
        $this->workspace([self::member('7'), self::member('8')]);
        $this->put(
            $this->gwRow('t1', 1, [new RemoteCustomField('f1', 'Owners', SyncFieldType::People, 'users', [], [], [['id' => '7', 'name' => 'User 7']], 'User 7')], new RemoteTaskDetails(estimates: [['person' => new RemotePersonRef('7', 'User 7'), 'minutes' => 90], ['person' => new RemotePersonRef('8', 'User 8'), 'minutes' => 5]])),
            $this->gwRow('t2', 2),
        );
        $this->provider->setComments('t2', [new RemoteComment('c1', 'Do it', null, 'Bob', new DateTimeImmutable('@1790000100'), assignee: self::ref('7'), resolved: true)]);
        $this->sync($board);
        $people = self::getContainer()->get(TaskSyncPersonRepository::class);
        self::assertInstanceOf(TaskSyncPersonRepository::class, $people);

        $export = $people->exportPerson($this->personOf('7'));
        self::assertSame('7', $export['person']['providerUserId']);
        self::assertSame([['taskId' => $this->taskTitled('t1')->getId(), 'minutes' => 90]], $export['estimates']);
        self::assertSame([['taskId' => $this->taskTitled('t1')->getId(), 'field' => 'Owners']], $export['fields']);
        $comment = $this->em->getRepository(TaskComment::class)->findOneBy([]);
        self::assertInstanceOf(TaskComment::class, $comment);
        self::assertSame([['taskId' => $this->taskTitled('t2')->getId(), 'commentId' => $comment->getId(), 'resolved' => true]], $export['commentAssignments']);
        self::assertSame([], $people->exportPerson($this->personOf('8'))['fields']);
    }

    private function gwProvider(): FullTaskProvider
    {
        $provider = new FullTaskProvider();
        $provider->addContainer(new RemoteContainer(self::LIST, 'Backlog', ContainerType::List, null, true, false, 5));
        $this->provider = $provider;

        return $provider;
    }

    /**
     * @param list<RemoteCustomField> $fields
     * @param list<RemoteAttachment> $attachments
     */
    private function gwRow(string $id, int $updated, array $fields = [], ?RemoteTaskDetails $details = null, array $attachments = [], bool $known = true, bool $watchersKnown = true): RemoteTask
    {
        return new RemoteTask(
            task: new ImportedTaskDto(title: $id, externalId: $id, status: 'To do', priority: TaskPriority::Normal, hasPriority: false),
            remoteUpdatedAt: new DateTimeImmutable('@' . (1_790_000_000 + $updated)),
            attachments: $attachments,
            watchersKnown: $watchersKnown,
            checklistsKnown: true,
            customFields: $fields,
            details: $details,
            attachmentsKnown: $known,
        );
    }
}
