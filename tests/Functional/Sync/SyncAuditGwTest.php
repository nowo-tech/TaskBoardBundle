<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Event\TaskErasedEvent;
use Nowo\TaskBoardBundle\Import\Dto\ImportedTaskDto;
use Nowo\TaskBoardBundle\Service\TaskBoardEraser;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncAttachment;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncField;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderErrorCode;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderException;
use Nowo\TaskBoardBundle\Sync\Field\SyncFieldType;
use Nowo\TaskBoardBundle\Sync\Field\SyncFieldValueEraser;
use Nowo\TaskBoardBundle\Sync\Provider\ContainerType;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteAttachment;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteComment;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteContainer;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteCustomField;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteStatus;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteStatusType;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteTask;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteTaskDetails;
use Nowo\TaskBoardBundle\Sync\SyncTaskDetailsWriter;
use Nowo\TaskBoardBundle\Sync\View\SyncFieldViewProvider;
use Nowo\TaskBoardBundle\Tests\Functional\App\FixtureHttpClient;
use Nowo\TaskBoardBundle\Tests\Functional\App\PredictableCsrfTokenManager;
use Nowo\TaskBoardBundle\Tests\Functional\App\RecordingAttachmentStorage;
use Nowo\TaskBoardBundle\Tests\Functional\App\TestSyncLimitChecker;
use Symfony\Component\HttpClient\Response\MockResponse;

use function array_keys;
use function count;
use function iterator_to_array;

/**
 * Since 1.27.0: the regressions of audit GW. M1 the list reads only the rows drawn, M2 comment files are tracked, M3
 * erasure and access for field values, L1 no field flip and no rejected URL in an anchor, L2 a failed page defers all
 * its tasks, L5 a local completion date is kept, L8 restore asks the limit, L9 the mailto is encoded; list rows without
 * files read the detail, and a refused time in status stops for the run.
 */
final class SyncAuditGwTest extends SyncPeopleFunctionalTestCase
{
    private const BASE = '/tools/task-board/';

    private const PDF = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF";

    public function testTheListReadsOnlyTheRowsDrawn(): void
    {
        $board = $this->board();
        $this->put(
            $this->gwTask('t1', 1, [new RemoteCustomField('f1', 'Note', SyncFieldType::Text, 'text', [], [], 'first value', 'first value')], new RemoteTaskDetails(customId: 'DEV-1')),
            $this->gwTask('t2', 2, [new RemoteCustomField('f1', 'Note', SyncFieldType::Text, 'text', [], [], 'second value', 'second value')], new RemoteTaskDetails(customId: 'DEV-2')),
        );
        $this->sync($board);
        $first = $this->taskTitled('t1');
        self::assertSame('DEV-1', $this->itemOf('t1')->getCustomId());
        self::assertArrayNotHasKey('customId', $this->itemOf('t1')->getRemoteMeta());

        $view = self::getContainer()->get(SyncFieldViewProvider::class);
        self::assertInstanceOf(SyncFieldViewProvider::class, $view);
        self::assertSame([$first->getId() => 'first value DEV-1'], $view->searchTextsOf([$first]));
        self::assertSame([], $view->searchTextsOf([]));

        $this->login($this->owner);
        $crawler = $this->client->request('GET', self::BASE . 'board/' . $board->getId() . '/list');
        self::assertSame('second value DEV-2', $crawler->filter('[data-title="t2"]')->attr('data-search-extra'));
        $crawler = $this->client->request('GET', self::BASE . 'task/' . $first->getId());
        self::assertSame('DEV-1', $crawler->filter('[data-sync-detail="custom-id"] code')->text());
    }

    public function testCommentFilesAreTrackedReusedReplacedAndErased(): void
    {
        $board = $this->board();
        $this->put($this->gwTask('t1', 1));
        FixtureHttpClient::$responder = static fn (): MockResponse => new MockResponse(self::PDF, ['http_code' => 200]);
        $file                         = new RemoteAttachment('att1', 'cv.pdf', 30, 'application/pdf', 'https://t000.p.clickup-attachments.com/att1/cv.pdf', new DateTimeImmutable('@1790000000'));
        $this->comment('Please check', $file);
        $this->sync($board);
        self::assertSame(['file-1'], array_keys(RecordingAttachmentStorage::$files));
        self::assertSame(1, $this->rows(TaskSyncAttachment::class));

        // The comment is edited, the file is the same: no new copy.
        $this->comment('Please check again', $file);
        $this->put($this->gwTask('t1', 2));
        self::assertSame(1, $this->sync($board)->files->reused);
        self::assertSame(['file-1'], array_keys(RecordingAttachmentStorage::$files));
        self::assertStringContainsString('/files/file-1', $this->commentBody());

        // A task's own file list does not touch the comment's file.
        $this->em->clear();
        $this->refreshOwner();
        $this->put($this->gwTask('t1', 3, attachments: [new RemoteAttachment('att9', 'own.pdf', 30, 'application/pdf', 'https://t000.p.clickup-attachments.com/att9/own.pdf', new DateTimeImmutable('@1790000000'))]));
        $this->sync($board);
        self::assertSame([], RecordingAttachmentStorage::$deleted);

        // The file changes: the new copy replaces the old one, which is deleted.
        $this->comment('Please check again', new RemoteAttachment('att1', 'cv.pdf', 31, 'application/pdf', 'https://t000.p.clickup-attachments.com/att1/cv.pdf', new DateTimeImmutable('@1790000500')));
        $this->put($this->gwTask('t1', 4));
        $this->sync($board);
        self::assertSame(['file-1'], RecordingAttachmentStorage::$deleted);
        self::assertStringContainsString('/files/file-3', $this->commentBody());

        // A local edit is a conflict: nothing is downloaded.
        $this->em->clear();
        $this->refreshOwner();
        $comment = $this->em->getRepository(TaskComment::class)->findOneBy([]);
        self::assertInstanceOf(TaskComment::class, $comment);
        $comment->edit('Edited here', []);
        $this->em->flush();
        $stored = count(RecordingAttachmentStorage::$files);
        $this->comment('Remote text', new RemoteAttachment('att1', 'cv.pdf', 32, 'application/pdf', 'https://t000.p.clickup-attachments.com/att1/cv.pdf', new DateTimeImmutable('@1790000600')));
        $this->put($this->gwTask('t1', 5));
        self::assertCount(1, $this->sync($board)->conflicts);
        self::assertCount($stored, RecordingAttachmentStorage::$files, 'no copy for a conflict');

        // Erasing the board names every stored copy.
        $refs = [];
        $this->dispatcher()->addListener(TaskBoardEvents::TASK_ERASED, static function (TaskErasedEvent $event) use (&$refs): void {
            $refs = [...$refs, ...$event->attachmentRefs];
        });
        $eraser = self::getContainer()->get('app.board_eraser');
        self::assertInstanceOf(TaskBoardEraser::class, $eraser);
        $eraser->deleteBoard($this->freshBoard($board), $this->owner);
        self::assertContains('file-3', $refs);
    }

    public function testAFileRemovedFromACommentLeavesTheStorage(): void
    {
        $board = $this->board();
        $this->put($this->gwTask('t1', 1));
        FixtureHttpClient::$responder = static fn (): MockResponse => new MockResponse(self::PDF, ['http_code' => 200]);
        $this->comment('With file', new RemoteAttachment('att1', 'cv.pdf', 30, 'application/pdf', 'https://t000.p.clickup-attachments.com/att1/cv.pdf', new DateTimeImmutable('@1790000000')));
        $this->sync($board);

        $this->comment('Without file');
        $this->put($this->gwTask('t1', 2));
        $this->sync($board);
        self::assertSame(['file-1'], RecordingAttachmentStorage::$deleted);
        self::assertSame(0, $this->rows(TaskSyncAttachment::class));
    }

    public function testFieldValuesCanBeExportedAndErased(): void
    {
        $board = $this->board();
        $mail  = 'zebulon+tag@people.test';
        $this->put($this->gwTask('t1', 1, [
            new RemoteCustomField('f1', 'Contact', SyncFieldType::Email, 'email', [], [], $mail, $mail),
            new RemoteCustomField('f2', 'Phone', SyncFieldType::Phone, 'phone', [], [], '+34 600 000 000', '+34 600 000 000'),
            new RemoteCustomField('f3', 'Stage', SyncFieldType::Text, 'text', [], [], 'Draft', 'Draft'),
        ]));
        $this->sync($board);
        $eraser = self::getContainer()->get(SyncFieldValueEraser::class);
        self::assertInstanceOf(SyncFieldValueEraser::class, $eraser);

        $this->login($this->owner);
        $crawler = $this->client->request('GET', self::BASE . 'task/' . $this->taskTitled('t1')->getId());
        self::assertSame('mailto:zebulon%2Btag@people.test', $crawler->filter('[data-field-type="email"] a')->attr('href'), 'audit GW-L9');

        // Access.
        self::assertCount(3, iterator_to_array($eraser->exportBoard($this->freshBoard($board)), false));
        $found = iterator_to_array($eraser->exportByValue('ZEBULON', $this->freshBoard($board)), false);
        self::assertCount(1, $found);
        self::assertSame(['Contact', 'email', $mail], [$found[0]['field'], $found[0]['type'], $found[0]['text']]);
        self::assertSame($this->taskTitled('t1')->getId(), $found[0]['taskId']);

        // Erasure, everywhere; a re-sync of the same value does not bring it back, and a new value is imported.
        self::assertSame(1, $eraser->eraseByValue($mail, null));
        $this->em->clear();
        $this->refreshOwner();
        $this->login($this->owner);
        $this->client->request('GET', self::BASE . 'task/' . $this->taskTitled('t1')->getId());
        self::assertStringNotContainsString('zebulon', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('zebulon', (string) $this->em->getConnection()->fetchOne("SELECT field_value FROM task_board_sync_field_values WHERE value_text = ''"));
        self::assertCount(0, iterator_to_array($eraser->exportByValue($mail, null), false));

        $fields = static fn (string $contact): array => [
            new RemoteCustomField('f1', 'Contact', SyncFieldType::Email, 'email', [], [], $contact, $contact),
            new RemoteCustomField('f2', 'Phone', SyncFieldType::Phone, 'phone', [], [], '+34 600 000 000', '+34 600 000 000'),
            new RemoteCustomField('f3', 'Stage', SyncFieldType::Text, 'text', [], [], 'Draft', 'Draft'),
        ];
        $this->put($this->gwTask('t1', 2, $fields($mail)));
        self::assertSame(0, $this->sync($board)->stat('fields.values'), 'the erased value stays erased');
        $this->put($this->gwTask('t1', 3, $fields('other@people.test')));
        self::assertSame(1, $this->sync($board)->stat('fields.values'));

        // Per board and type (after an unlink, say); a search too short to mean anything is refused.
        self::assertSame(1, $eraser->eraseBoard($this->freshBoard($board), [SyncFieldType::Phone]));
        self::assertSame(2, $eraser->eraseBoard($this->freshBoard($board)));
        $this->expectException(InvalidArgumentException::class);
        iterator_to_array($eraser->exportByValue('ab', null));
    }

    public function testARejectedValueNeitherFlipsTheFieldNorBecomesALink(): void
    {
        $board  = $this->board();
        $good   = new RemoteCustomField('f1', 'Site', SyncFieldType::Url, 'url', [], [], 'https://example.test/', 'https://example.test/');
        $bad    = new RemoteCustomField('f1', 'Site', SyncFieldType::Url, 'url', [], [], 'javascript:alert(1)', 'javascript:alert(1)', true);
        $forged = new RemoteCustomField('f1', 'Site', SyncFieldType::Url, 'url', [], [], 'javascript:alert(2)', 'javascript:alert(2)');
        $this->put($this->gwTask('t1', 1, [$good]), $this->gwTask('t2', 2, [$bad]), $this->gwTask('t3', 3, [$forged]));
        $this->sync($board);
        $field = $this->em->getRepository(TaskSyncField::class)->findOneBy(['remoteId' => 'f1']);
        self::assertInstanceOf(TaskSyncField::class, $field);
        self::assertSame(SyncFieldType::Url, $field->getType());

        $this->login($this->owner);
        $crawler = $this->client->request('GET', self::BASE . 'task/' . $this->taskTitled('t1')->getId());
        self::assertSame('https://example.test/', $crawler->filter('[data-field-type="url"] a')->attr('href'));
        foreach (['t2', 't3'] as $title) {
            $crawler = $this->client->request('GET', self::BASE . 'task/' . $this->taskTitled($title)->getId());
            self::assertCount(0, $crawler->filter('[data-testid="nowo-task-sync-fields"] a'), $title);
            self::assertStringContainsString('javascript:alert', $crawler->filter('[data-testid="nowo-task-sync-fields"]')->text(), 'kept as text');
        }
    }

    public function testAFailedPageDefersEveryTaskNotFinished(): void
    {
        $board    = $this->board();
        $provider = $this->gwProvider();
        foreach (['t1', 't2', 't3'] as $index => $id) {
            $provider->putTask(self::LIST, $this->gwTask($id, $index + 1));
            $provider->setComments($id, [new RemoteComment('c-' . $id, 'Body', null, 'Bob', new DateTimeImmutable('@1790000100'))]);
        }
        $provider->historyFailure = new ProviderException(ProviderErrorCode::ServerError);
        self::assertNotSame([], $this->sync($board, $provider)->errors);
        $this->em->clear();
        foreach (['t1', 't2', 't3'] as $id) {
            self::assertSame(0, $this->itemOf($id)->getRemoteUpdatedAt()->getTimestamp(), $id);
        }
        $provider->historyFailure = null;
        $this->refreshOwner();
        self::assertSame([], $this->sync($board, $provider)->errors);
        self::assertSame(3, $this->rows(TaskComment::class));
    }

    public function testALocalCompletionDateIsKept(): void
    {
        $board  = $this->board();
        $remote = new DateTimeImmutable('2020-01-01T00:00:00+00:00');
        $this->put($this->gwTask('t1', 1, details: new RemoteTaskDetails(doneAt: $remote), done: true));
        $this->sync($board);
        self::assertSame($remote->getTimestamp(), $this->taskTitled('t1')->getCompletedAt()?->getTimestamp());

        // The remote date moves while the local one is untouched: it follows.
        $moved = new DateTimeImmutable('2020-02-02T00:00:00+00:00');
        $this->put($this->gwTask('t1', 2, details: new RemoteTaskDetails(doneAt: $moved), done: true));
        $this->sync($board);
        self::assertSame($moved->getTimestamp(), $this->taskTitled('t1')->getCompletedAt()?->getTimestamp());

        // Set here: it stays.
        $local = new DateTimeImmutable('2026-06-06T06:06:06+00:00');
        $this->taskTitled('t1')->markCompleted($local);
        $this->em->flush();
        $this->put($this->gwTask('t1', 3, details: new RemoteTaskDetails(doneAt: $moved), done: true));
        self::assertSame(1, $this->sync($board)->stat('details.completionKept'));
        self::assertSame($local->getTimestamp(), $this->taskTitled('t1')->getCompletedAt()?->getTimestamp());
    }

    public function testRestoringAskTheHostsLimit(): void
    {
        $board = $this->board();
        $this->put($this->gwTask('z1', 1, archived: true));
        $this->sync($board);
        $task = $this->taskTitled('z1');
        self::assertTrue($task->isArchived());

        $this->login($this->owner);
        $url                               = self::BASE . 'task/' . $task->getId() . '/restore';
        TestSyncLimitChecker::$refuseTasks = true;
        $this->client->request('POST', $url, ['_token' => PredictableCsrfTokenManager::value('task_board_task_restore')]);
        self::assertResponseRedirects();
        $this->em->clear();
        self::assertTrue($this->taskTitled('z1')->isArchived(), 'refused');

        TestSyncLimitChecker::$refuseTasks = false;
        $this->refreshOwner();
        $this->login($this->owner);
        $this->client->request('POST', $url, ['_token' => PredictableCsrfTokenManager::value('task_board_task_restore')]);
        $this->em->clear();
        self::assertFalse($this->taskTitled('z1')->isArchived());
    }

    public function testFilesMissingFromTheRowAreReadFromTheDetailOnce(): void
    {
        $board                        = $this->board();
        FixtureHttpClient::$responder = static fn (): MockResponse => new MockResponse(self::PDF, ['http_code' => 200]);
        $provider                     = $this->gwProvider();
        $provider->putTask(self::LIST, $this->gwTask('t1', 1, known: false));
        $provider->details['t1'] = $this->gwTask('t1', 1, attachments: [new RemoteAttachment('att1', 'own.pdf', 30, 'application/pdf', 'https://t000.p.clickup-attachments.com/att1/own.pdf', new DateTimeImmutable('@1790000000'))]);
        $report                  = $this->sync($board, $provider);
        self::assertSame([1, 1, 1, 0], [$provider->detailCalls, $report->files->copied, $report->stat('keys.attachments'), $report->stat('attachments.unread')]);

        // A full sync of the same version spends no request on it, so repeated runs get through a large board.
        self::assertSame(0, $this->sync($board, $provider, full: true)->stat('attachments.unread'));
        self::assertSame(1, $provider->detailCalls);

        // The detail does not say either: not claimed as read.
        $provider->putTask(self::LIST, $this->gwTask('t2', 2, known: false));
        $provider->details['t2'] = $this->gwTask('t2', 2, known: false);
        self::assertSame(1, $this->sync($board, $provider)->stat('attachments.unread'));
        $this->login($this->owner);
        $crawler = $this->client->request('GET', self::BASE . 'board/' . $board->getId() . '/connection');
        self::assertSame('not_read', $crawler->filter('[data-completeness-key="attachments"]')->attr('data-completeness-status'));
    }

    public function testARefusedTimeInStatusStopsForTheRun(): void
    {
        $board    = $this->board();
        $provider = $this->gwProvider();
        $provider->putTask(self::LIST, $this->gwTask('t1', 1));
        $provider->putTask(self::LIST, $this->gwTask('t2', 2));
        $provider->putTask(self::LIST, $this->gwTask('t3', 3));
        $provider->historyFailure = new ProviderException(ProviderErrorCode::Forbidden);
        $report                   = $this->sync($board, $provider);
        self::assertSame([], $report->errors);
        self::assertSame([1, 1, 2], [$report->stat(SyncTaskDetailsWriter::STAT_STATUS_CALLS), $report->stat(SyncTaskDetailsWriter::STAT_STATUS_FORBIDDEN), $report->stat(SyncTaskDetailsWriter::STAT_STATUS_SKIPPED)]);
        $this->login($this->owner);
        $crawler = $this->client->request('GET', self::BASE . 'board/' . $board->getId() . '/connection');
        $row     = $crawler->filter('[data-completeness-key="time_in_status"]');
        self::assertSame('unavailable', $row->attr('data-completeness-status'));
        self::assertStringContainsString('3', $row->filter('td')->eq(1)->text());
    }

    private function comment(string $body, RemoteAttachment ...$files): void
    {
        $this->provider->setComments('t1', [new RemoteComment('c1', $body, null, 'Bob', new DateTimeImmutable('@1790000100'), attachments: $files)]);
    }

    private function commentBody(): string
    {
        $this->em->clear();
        $this->refreshOwner();
        $comment = $this->em->getRepository(TaskComment::class)->findOneBy([]);
        self::assertInstanceOf(TaskComment::class, $comment);

        return $comment->getBody();
    }

    private function gwProvider(): FullTaskProvider
    {
        $provider = new FullTaskProvider();
        $provider->addContainer(new RemoteContainer(self::LIST, 'Backlog', ContainerType::List, null, true, false, 3));
        $this->provider = $provider;

        return $provider;
    }

    /**
     * @param list<RemoteCustomField> $fields
     * @param list<RemoteAttachment> $attachments
     */
    private function gwTask(string $id, int $updated, array $fields = [], ?RemoteTaskDetails $details = null, array $attachments = [], bool $done = false, bool $archived = false, bool $known = true): RemoteTask
    {
        return new RemoteTask(
            task: new ImportedTaskDto(title: $id, externalId: $id, status: $done ? 'Done' : 'To do', priority: TaskPriority::Normal, hasPriority: false),
            remoteUpdatedAt: new DateTimeImmutable('@' . (1_790_000_000 + $updated)),
            archived: $archived,
            attachments: $attachments,
            watchersKnown: true,
            checklistsKnown: true,
            status: new RemoteStatus($done ? 'Done' : 'To do', $done ? RemoteStatusType::Closed : RemoteStatusType::Open),
            customFields: $fields,
            details: $details,
            attachmentsKnown: $known,
        );
    }
}
