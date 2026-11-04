<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskChangeHistory;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Entity\TaskDependency;
use Nowo\TaskBoardBundle\Entity\TaskDocument;
use Nowo\TaskBoardBundle\Entity\TaskLink;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Enum\TaskDependencyType;
use Nowo\TaskBoardBundle\Enum\TaskLinkType;
use Nowo\TaskBoardBundle\Event\TaskAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Import\TaskImportOrchestrator;
use Nowo\TaskBoardBundle\Import\TaskImportSource;
use Nowo\TaskBoardBundle\Sync\Dto\SyncError;
use Nowo\TaskBoardBundle\Sync\Dto\SyncMode;
use Nowo\TaskBoardBundle\Sync\Dto\SyncOptions;
use Nowo\TaskBoardBundle\Sync\Dto\SyncPhase;
use Nowo\TaskBoardBundle\Sync\Dto\SyncStatus;
use Nowo\TaskBoardBundle\Sync\Dto\SyncTrigger;
use Nowo\TaskBoardBundle\Sync\Entity\TaskBoardConnection;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncAttachment;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncItem;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncRun;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderErrorCode;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderException;
use Nowo\TaskBoardBundle\Sync\Exception\RateLimitedException;
use Nowo\TaskBoardBundle\Sync\Hook\MapInlineImageRewriter;
use Nowo\TaskBoardBundle\Sync\Provider\ContainerType;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCredential;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteAttachment;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteComment;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteContainer;
use Nowo\TaskBoardBundle\Sync\Testing\InMemoryTaskProvider;
use Nowo\TaskBoardBundle\Tests\Functional\App\FixtureHttpClient;
use Nowo\TaskBoardBundle\Tests\Functional\App\RecordingAttachmentStorage;
use Nowo\TaskBoardBundle\Tests\Functional\App\TestCredentialResolver;
use Nowo\TaskBoardBundle\Tests\Functional\App\TestSyncLimitChecker;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpClient\Response\MockResponse;

use function array_map;
use function count;
use function str_contains;

/**
 * Since 1.23: the sync engine against a real database and the in-memory provider — the contract's state table (§3.3),
 * the per-field rule (§3.2), deletions and the mass-archive guard (§3.4), files, comments, resume and failures.
 */
final class SyncServiceTest extends SyncFunctionalTestCase
{
    private const IMG = 'https://t000.p.clickup-attachments.com/fixture/1/shot.png';

    public function testTheFirstRunImportsEverythingAndASecondRunWritesNothing(): void
    {
        $board = $this->board(columns: ['To do', 'Done']);
        $this->put(
            $this->remote('a1', 'Design', 1, 'In review', 'Shot ![s](' . self::IMG . ")\n\n[x](javascript:alert(1))", attachments: [new RemoteAttachment('att-1', 'spec.pdf', 4, 'application/pdf', 'https://t000.p.clickup-attachments.com/fixture/2/spec.pdf', new DateTimeImmutable('@1790000000'))], images: [self::IMG], fields: '- **Points**: 5', tags: ['ux']),
            $this->remote('a2', 'Build', 2, 'To do', dependsOn: [['a1', TaskDependencyType::BlockedBy]]),
            $this->remote('a3', 'Sub build', 3, 'Done', parent: 'a2'),
        );
        $this->provider->setComments('a1', [
            new RemoteComment('c1', 'Looks good', 'owner@example.com', 'Owner', new DateTimeImmutable('@1790000100')),
            new RemoteComment('c2', 'From outside ![p](' . self::IMG . ')', null, 'Ada', new DateTimeImmutable('@1790000200'), [self::IMG]),
        ]);
        FixtureHttpClient::$responder = static fn (string $m, string $url): MockResponse => str_contains($url, 'spec.pdf')
            ? new MockResponse("%PDF-1.4\n%\xE2\xE3\xCF\xD3\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF", ['http_code' => 200])
            : new MockResponse(FixtureHttpClient::PNG, ['http_code' => 200]);

        $events     = [];
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        foreach ([TaskBoardEvents::SYNC_STARTED, TaskBoardEvents::SYNC_FINISHED, TaskBoardEvents::BOARD_CONNECTION_LINKED] as $name) {
            $dispatcher->addListener($name, static function () use ($name, &$events): void {
                $events[] = $name;
            });
        }

        $report = $this->sync($board);

        self::assertSame(SyncStatus::Succeeded, $report->status, (string) json_encode($report->toArray()));
        self::assertSame(SyncMode::Initial, $report->mode);
        self::assertSame([3, 0, 1], [$report->created, $report->updated, $report->columnsCreated]);
        self::assertSame(1, $report->dependenciesCreated);
        self::assertSame(2, $report->commentsCreated);
        self::assertSame([2, 1], [$report->files->copied, $report->files->reused], 'the attachment and the image; the image in the comment is the same one');
        self::assertSame(3, $report->total);
        self::assertSame([TaskBoardEvents::BOARD_CONNECTION_LINKED, TaskBoardEvents::SYNC_STARTED, TaskBoardEvents::SYNC_FINISHED], $events);

        $this->em->clear();
        $design = $this->taskTitled('Design');
        self::assertSame('In review', $design->getColumn()?->getName());
        self::assertStringNotContainsString('clickup-attachments', (string) $design->getDescription());
        self::assertStringNotContainsString('javascript:', (string) $design->getDescription());
        self::assertStringContainsString('/files/', (string) $design->getDescription());
        self::assertSame(['ux'], $design->getTags());
        $attachment = array_values(array_filter($design->getLinks()->toArray(), static fn (TaskLink $l): bool => $l->getLinkType() === TaskLinkType::Attachment));
        self::assertCount(1, $attachment);
        self::assertSame('spec.pdf', $attachment[0]->getLabel());
        $document = $this->em->getRepository(TaskDocument::class)->findOneBy(['task' => $design]);
        self::assertInstanceOf(TaskDocument::class, $document);
        self::assertSame('Fields (ClickUp)', $document->getTitle());
        // Both comments are written in the same second (MySQL keeps whole seconds): ordered by their body here.
        $comments = $this->em->getRepository(TaskComment::class)->findBy(['task' => $design], ['body' => 'DESC']);
        self::assertCount(2, $comments);
        self::assertSame('Looks good', $comments[0]->getBody());
        self::assertSame('owner@example.com', $comments[0]->getAuthor()->getUserIdentifier());
        self::assertStringStartsWith('**Ada** (ClickUp):', $comments[1]->getBody());
        self::assertSame('Sub build', $this->taskTitled('Sub build')->getTitle());
        self::assertSame('Build', $this->taskTitled('Sub build')->getParent()?->getTitle());
        self::assertTrue($design->isCompleted(), 'the last column completes a task');
        self::assertCount(1, $this->em->getRepository(TaskDependency::class)->findAll());
        $connection = $this->connectionOf($board);
        self::assertInstanceOf(TaskBoardConnection::class, $connection);
        self::assertSame('Backlog', $connection->getContainerLabel());
        self::assertSame(['Space 1'], $connection->getContainerPath());
        self::assertNotNull($connection->getWatermark());
        self::assertNull($connection->getCursor());
        self::assertNull($connection->getRunningRunId());

        $again = $this->sync($board);
        self::assertSame(SyncMode::Incremental, $again->mode);
        self::assertSame([0, 0, 0, 0], [$again->created, $again->updated, $again->commentsCreated, $again->files->copied]);
        self::assertCount(3, $this->tasksOf($board));
        self::assertCount(2, $this->em->getRepository(TaskSyncRun::class)->findAll());

        $full = $this->sync($board, full: true);
        self::assertSame(SyncMode::Full, $full->mode);
        self::assertSame([0, 0, 3], [$full->created, $full->updated, $full->unchanged]);
        self::assertSame(1, $full->files->reused, 'the unchanged attachment is not downloaded again');
    }

    public function testAFieldEditedHereIsKeptAndNotedOnceWhileTheOthersFollowTheProvider(): void
    {
        $board = $this->board();
        $this->put($this->remote('b1', 'Original', 1, 'To do', 'Text'));
        $this->sync($board);

        $task = $this->taskTitled('Original');
        $task->setTitle('Renamed here');
        $this->em->flush();
        $this->put($this->remote('b1', 'Renamed there', 2, 'Doing', 'Text', due: new DateTimeImmutable('2030-01-02 00:00:00')));

        $report = $this->sync($board);
        self::assertSame(1, $report->updated);
        self::assertCount(1, $report->conflicts);
        self::assertSame('title', $report->conflicts[0]->field);
        self::assertSame('Renamed there', $report->conflicts[0]->remotePreview);
        $this->em->clear();
        $task = $this->taskTitled('Renamed here');
        self::assertSame('Doing', $task->getColumn()?->getName());
        self::assertSame('2030-01-02', $task->getDueAt()?->format('Y-m-d'));
        $notes = $this->em->getRepository(TaskComment::class)->findBy(['task' => $task]);
        self::assertCount(1, $notes);
        self::assertStringContainsString('Renamed there', $notes[0]->getBody());
        self::assertCount(1, $this->em->getRepository(TaskChangeHistory::class)->findBy(['task' => $task, 'changeType' => TaskChangeType::SyncConflict]));

        // The same remote value again (another field changed): no second note.
        $this->put($this->remote('b1', 'Renamed there', 3, 'Doing', 'Text v2', due: new DateTimeImmutable('2030-01-02 00:00:00')));
        $report = $this->sync($board);
        self::assertSame([], $report->conflicts);
        self::assertCount(1, $this->em->getRepository(TaskComment::class)->findBy(['task' => $task->getId()]));

        // Both sides converge: no conflict, the baseline follows.
        $task = $this->taskTitled('Renamed here');
        $task->setTitle('Agreed');
        $this->em->flush();
        $this->put($this->remote('b1', 'Agreed', 4, 'Doing', 'Text v2'));
        self::assertSame([], $this->sync($board)->conflicts);
        $this->put($this->remote('b1', 'Agreed later', 5, 'Doing', 'Text v2'));
        $this->sync($board);
        self::assertSame('Agreed later', $this->tasksOf($board)[0]->getTitle());
    }

    public function testRemoteDeletionsAreArchivedAndRestoredBehindTheMassArchiveGuard(): void
    {
        $board = $this->board();
        $this->put($this->remote('d1', 'One', 1), $this->remote('d2', 'Two', 2), $this->remote('d3', 'Three', 3), $this->remote('d4', 'Four', 4));
        $this->sync($board);
        $archivedEvents = 0;
        $restoredEvents = 0;
        $dispatcher     = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(TaskBoardEvents::TASK_REMOTE_ARCHIVED, static function () use (&$archivedEvents): void {
            ++$archivedEvents;
        });
        $dispatcher->addListener(TaskBoardEvents::TASK_REMOTE_RESTORED, static function () use (&$restoredEvents): void {
            ++$restoredEvents;
        });

        $this->provider->removeTask(self::LIST, 'd1');
        $report = $this->sync($board);
        self::assertSame(1, $report->archived);
        self::assertSame(1, $archivedEvents);
        $this->em->clear();
        self::assertTrue($this->taskTitled('One')->isArchived());
        self::assertSame(Task::ARCHIVE_REASON_REMOTE, $this->taskTitled('One')->getArchiveReason());

        // Three of the three remaining linked tasks vanish: held, unless explicitly allowed.
        foreach (['d2', 'd3', 'd4'] as $id) {
            $this->provider->removeTask(self::LIST, $id);
        }
        $held = $this->sync($board);
        self::assertSame(0, $held->archived);
        self::assertTrue($held->massArchiveHeld());
        self::assertSame(['%count%' => '3', '%total%' => '3'], $held->warnings[0]->params);
        $allowed = $this->sync($board, allowMassArchive: true);
        self::assertSame(3, $allowed->archived);

        // A task that comes back is restored (by the deletions scan, it is not a changed task).
        $this->put($this->remote('d1', 'One', 1));
        $back = $this->sync($board);
        self::assertSame(1, $back->restored);
        self::assertSame(1, $restoredEvents);
        $this->em->clear();
        self::assertFalse($this->taskTitled('One')->isArchived());

        // Archived remotely (flag) while changed: archived by the tasks phase.
        $this->put($this->remote('d1', 'One', 9, archived: true));
        self::assertSame(1, $this->sync($board)->archived);
        // …and a changed one that comes back is restored by the tasks phase.
        $this->put($this->remote('d1', 'One again', 10));
        $report = $this->sync($board);
        self::assertSame(1, $report->restored);
        $this->em->clear();
        self::assertSame('One again', $this->taskTitled('One again')->getTitle());
    }

    public function testTombstonesUserArchivesReadOnlyTasksAndOtherBoardsAreSkipped(): void
    {
        $board = $this->board();
        $this->put($this->remote('t1', 'Deleted here', 1), $this->remote('t2', 'Archived here', 2), $this->remote('t3', 'Read only', 3));
        $this->sync($board);

        $this->em->remove($this->taskTitled('Deleted here'));
        $this->taskTitled('Archived here')->archive();
        $this->em->flush();
        $readOnly   = $this->taskTitled('Read only')->getId();
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(TaskBoardEvents::TASK_ACCESS_CHECK, static function (TaskAccessCheckEvent $event) use ($readOnly): void {
            if ($event->getTask()->getId() === $readOnly) {
                $event->grant();
                $event->markReadOnly();
            }
        });

        $report = $this->sync($board, full: true);
        self::assertSame(0, $report->created, 'a task deleted here is never recreated');
        self::assertSame([1, 1, 1], [$report->skips->locallyDeleted, $report->skips->locallyArchived, $report->skips->readOnly]);
        self::assertCount(2, $this->tasksOf($board));

        // Another board of the tenant already imported x1: skipped there (count only).
        $other = $this->board('Other');
        $this->put($this->remote('x1', 'Shared', 4));
        $this->sync($board);
        $provider = new InMemoryTaskProvider();
        $provider->addContainer(new RemoteContainer(self::LIST, 'Backlog', ContainerType::List, null, true, false));
        $provider->putTask(self::LIST, $this->remote('x1', 'Shared', 4));
        $report = $this->sync($other, $provider);
        self::assertSame([0, 1], [$report->created, $report->skips->elsewhere]);
    }

    public function testACsvImportFollowedByASyncCreatesNoDuplicates(): void
    {
        $board        = $this->board(columns: ['To do', 'Done']);
        $orchestrator = self::getContainer()->get(TaskImportOrchestrator::class);
        self::assertInstanceOf(TaskImportOrchestrator::class, $orchestrator);
        $csv = "Task ID,Task Name,Status\n86a1x1,Design,To do\n86a1x2,Build,To do\n";
        self::assertSame(2, $orchestrator->import($this->freshBoard($board), TaskImportSource::ClickUpCsv, $csv, 'tasks.csv', $this->owner)->created);

        $this->put($this->remote('86a1x1', 'Design v2', 1), $this->remote('86a1x2', 'Build', 2), $this->remote('86a1x3', 'New', 3));
        $report = $this->sync($board);
        self::assertSame([1, 1, 1], [$report->created, $report->updated, $report->unchanged]);
        self::assertCount(3, $this->tasksOf($board));
        self::assertCount(3, $this->em->getRepository(TaskSyncItem::class)->findAll());

        // And a file re-import after the sync still matches the same tasks.
        self::assertSame(0, $orchestrator->import($this->freshBoard($board), TaskImportSource::ClickUpCsv, $csv, 'tasks.csv', $this->owner)->created);
        self::assertCount(3, $this->tasksOf($board));
    }

    public function testARunIsTimeSlicedRateLimitedAndResumedUnderOneHistoryRow(): void
    {
        $board    = $this->board();
        $provider = new InMemoryTaskProvider(pageSize: 1);
        $provider->addContainer(new RemoteContainer(self::LIST, 'Backlog', ContainerType::List, null, true, false, 3));
        foreach ([['r1', 'Child', 'r2'], ['r2', 'Parent', null], ['r3', 'Third', null]] as $i => [$id, $title, $parent]) {
            $provider->putTask(self::LIST, $this->remote($id, $title, $i + 1, parent: $parent, dependsOn: $id === 'r1' ? [['r3', TaskDependencyType::Blocks]] : []));
        }

        $first = $this->sync($board, $provider, maxPages: 1);
        self::assertSame(SyncStatus::Paused, $first->status);
        self::assertSame(SyncPhase::Tasks, $first->cursor?->phase);
        self::assertCount(2, $first->cursor->pendingDependencies, 'the parent and the dependency wait for later pages');

        $provider->failNext(new RateLimitedException(120));
        $limited = $this->sync($board, $provider);
        self::assertSame(SyncStatus::RateLimited, $limited->status);
        self::assertSame(120, $limited->retryAfterSeconds);

        $done = $this->sync($board, $provider);
        self::assertSame(SyncStatus::Succeeded, $done->status);
        self::assertSame($first->runId, $done->runId);
        self::assertSame(3, $done->created, 'the counters of every slice');
        self::assertCount(1, $this->em->getRepository(TaskSyncRun::class)->findAll());
        $this->em->clear();
        self::assertSame('Parent', $this->taskTitled('Child')->getParent()?->getTitle());
        self::assertSame(1, $done->dependenciesCreated);
    }

    public function testFailuresLimitsBusyCancelAndDryRun(): void
    {
        $board = $this->board();
        $this->put($this->remote('f1', 'First', 1));

        $this->provider->failNext(null)->failNext(new ProviderException(ProviderErrorCode::AuthFailed));
        $failed = $this->sync($board);
        self::assertSame(SyncStatus::Failed, $failed->status);
        self::assertSame('auth_failed', $failed->errors[0]->code);

        TestSyncLimitChecker::$refuseTasks = true;
        $refused                           = $this->sync($board);
        self::assertSame(SyncStatus::Refused, $refused->status);
        self::assertSame('active_tasks', $refused->refusalCode);
        self::assertSame([], $this->tasksOf($board), 'a refused page writes nothing');
        TestSyncLimitChecker::$refuseTasks = false;

        $dry = $this->sync($board, dryRun: true);
        self::assertSame(SyncStatus::Succeeded, $dry->status);
        self::assertSame(1, $dry->created);
        self::assertSame([], $this->tasksOf($board), 'a dry run writes nothing');

        $this->em->createQuery('UPDATE ' . TaskBoardConnection::class . " c SET c.runningRunId = 'other', c.heartbeatAt = :now")->setParameter('now', new DateTimeImmutable(), 'datetime_immutable')->execute();
        self::assertSame(SyncStatus::Busy, $this->sync($board)->status);
        $this->em->createQuery('UPDATE ' . TaskBoardConnection::class . ' c SET c.runningRunId = NULL, c.heartbeatAt = NULL')->execute();

        $paused = $this->sync($board, maxPages: 1);
        self::assertSame(SyncStatus::Succeeded, $paused->status);
        self::assertCount(1, $this->tasksOf($board));

        // Cancelling: a request before the next page stops the run.
        $this->put($this->remote('f2', 'Second', 2));
        $runs = $this->em->getRepository(TaskSyncRun::class)->findAll();
        self::assertFalse($this->service()->requestCancel($this->freshBoard($board), $runs[0]->getId()), 'a finished run');
        self::assertFalse($this->service()->requestCancel($this->freshBoard($board), 'nope'));
    }

    public function testFilesAreRefusedSafelyAndCommentsFollowTheProvider(): void
    {
        $board = $this->board();
        $svg   = 'https://t000.p.clickup-attachments.com/fixture/9/evil.png';
        $off   = 'https://evil.test/x.png';
        $this->put($this->remote('g1', 'Files', 1, description: '![a](' . $svg . ') ![b](' . $off . ')', attachments: [new RemoteAttachment('big', 'big.zip', 999_999_999_999, null, 'https://t000.p.clickup-attachments.com/fixture/big.zip')], images: [$svg, $off]));
        $this->provider->setComments('g1', [new RemoteComment('k1', 'First', null, 'Ada', new DateTimeImmutable())]);
        FixtureHttpClient::$responder = static fn (): MockResponse => new MockResponse('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', ['http_code' => 200]);

        $report = $this->sync($board);
        self::assertSame(3, $report->files->refused);
        $codes = array_map(static fn (SyncError $w): string => $w->code . ':' . ($w->params['%reason%'] ?? ''), $report->warnings);
        self::assertContains('file_refused:type_not_allowed', $codes);
        self::assertContains('file_refused:host_not_allowed', $codes);
        self::assertContains('file_refused:too_large', $codes);
        self::assertNotContains('https://evil.test/x.png', FixtureHttpClient::$requests, 'an off-allowlist host is never requested');
        $this->em->clear();
        $task = $this->taskTitled('Files');
        self::assertSame(MapInlineImageRewriter::PLACEHOLDER . ' ' . MapInlineImageRewriter::PLACEHOLDER, $task->getDescription());

        // A remote comment edit updates the comment; once edited here, the provider's next edit is a conflict.
        $this->provider->setComments('g1', [new RemoteComment('k1', 'First, edited', null, 'Ada', new DateTimeImmutable())]);
        $this->put($this->remote('g1', 'Files', 2, description: '![a](' . $svg . ') ![b](' . $off . ')', images: [$svg, $off]));
        self::assertSame(1, $this->sync($board)->commentsUpdated);
        $comment = $this->em->getRepository(TaskComment::class)->findOneBy(['task' => $task->getId()]);
        self::assertInstanceOf(TaskComment::class, $comment);
        $comment->edit('Edited here', []);
        $this->em->flush();
        $this->provider->setComments('g1', [new RemoteComment('k1', 'First, edited again', null, 'Ada', new DateTimeImmutable())]);
        $this->put($this->remote('g1', 'Files', 3, description: '![a](' . $svg . ') ![b](' . $off . ')', images: [$svg, $off]));
        $report = $this->sync($board);
        self::assertSame('comment', $report->conflicts[0]->field);

        // Quotas and a failing storage.
        TestSyncLimitChecker::$refuseBytes = true;
        $this->put($this->remote('g2', 'Quota', 4, attachments: [new RemoteAttachment('q', 'q.png', 10, 'image/png', 'https://t000.p.clickup-attachments.com/q.png'), new RemoteAttachment('q2', 'q2.png', 10, 'image/png', 'https://t000.p.clickup-attachments.com/q2.png')]));
        $report = $this->sync($board);
        self::assertSame(2, $report->files->refused, 'the first refusal stops the downloads of the run');
        TestSyncLimitChecker::$refuseBytes = false;
        RecordingAttachmentStorage::$fail  = true;
        FixtureHttpClient::$responder      = null;
        $this->put($this->remote('g2', 'Quota', 5, attachments: [new RemoteAttachment('q', 'q.png', 10, 'image/png', 'https://t000.p.clickup-attachments.com/q.png')]));
        self::assertContains('file_store_failed', array_map(static fn (SyncError $w): string => $w->code, $this->sync($board)->warnings));
    }

    public function testReplacedAndRemovedAttachmentsAreReleased(): void
    {
        $board = $this->board();
        $file  = static fn (string $id, int $size): RemoteAttachment => new RemoteAttachment($id, $id . '.png', $size, 'image/png', 'https://t000.p.clickup-attachments.com/' . $id . '.png', new DateTimeImmutable('@' . (1_790_000_000 + $size)));
        $this->put($this->remote('h1', 'Files', 1, attachments: [$file('one', 1), $file('two', 1)]));
        $this->sync($board);
        self::assertCount(2, $this->em->getRepository(TaskSyncAttachment::class)->findAll());

        $this->put($this->remote('h1', 'Files', 2, attachments: [$file('one', 2)]));
        $this->sync($board);
        self::assertCount(2, RecordingAttachmentStorage::$deleted, 'the replaced and the removed files');
        $this->em->clear();
        $links = array_filter($this->taskTitled('Files')->getLinks()->toArray(), static fn (TaskLink $l): bool => $l->getLinkType() === TaskLinkType::Attachment);
        self::assertCount(1, $links);
    }

    public function testRunLinkedAndTheRefusalsBeforeARun(): void
    {
        $board   = $this->board();
        $service = $this->service();
        $options = new SyncOptions(actor: $this->owner, trigger: SyncTrigger::Console);

        self::assertSame('not_linked', $service->runLinked($this->freshBoard($board), $options)->errors[0]->code);

        $this->put($this->remote('l1', 'Linked', 1));
        $this->sync($board);
        $report = $service->runLinked($this->freshBoard($board), $options);
        self::assertSame(SyncStatus::Succeeded, $report->status);

        TestCredentialResolver::$available = false;
        self::assertSame('credential_unavailable', $service->runLinked($this->freshBoard($board), $options)->errors[0]->code);
        TestCredentialResolver::$available = true;

        $connection = $this->connectionOf($board);
        self::assertInstanceOf(TaskBoardConnection::class, $connection);
        $this->em->createQuery('UPDATE ' . TaskBoardConnection::class . " c SET c.provider = 'gone'")->execute();
        self::assertSame('provider_unavailable', $service->runLinked($this->freshBoard($board), $options)->errors[0]->code);
        $this->em->createQuery('UPDATE ' . TaskBoardConnection::class . " c SET c.provider = 'clickup'")->execute();

        $other = new InMemoryTaskProvider();
        $board = $this->freshBoard($board);
        self::assertSame('board_linked_elsewhere', $service->run($board, $other, new ProviderCredential('pk_test_token', 'conn-1'), 'L2', $options)->errors[0]->code);
        $stranger = $this->user('stranger@example.com');
        self::assertSame('actor_not_allowed', $service->run($this->freshBoard($board), $this->provider, new ProviderCredential('pk_test_token', 'conn-1'), self::LIST, new SyncOptions(actor: $stranger))->errors[0]->code);

        $service->unlink($this->freshBoard($board), $this->owner);
        self::assertNull($this->connectionOf($board));
        self::assertCount(1, $this->tasksOf($board), 'unlinking keeps the tasks');
        $service->unlink($this->freshBoard($board), $this->owner);

        // Relinking adopts the tasks (their import links stay): no duplicates.
        $report = $this->sync($board);
        self::assertSame([0, 1], [$report->created, $report->updated + $report->unchanged]);
    }

    public function testAnUnexpectedErrorFailsTheRunAndKeepsTheLockFree(): void
    {
        $board = $this->board();
        $this->put($this->remote('e1', 'Boom', 1));
        $this->sync($board);
        // The second run updates the linked task: its edit check fails unexpectedly.
        $this->put($this->remote('e1', 'Boom again', 2));
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(TaskBoardEvents::TASK_ACCESS_CHECK, static function (): never {
            throw new RuntimeException('listener failed with a secret pk_test_token');
        });

        $report = $this->sync($board, full: true);
        self::assertSame(SyncStatus::Failed, $report->status);
        self::assertSame('internal', $report->errors[0]->code);
        $this->em->clear();
        self::assertNull($this->connectionOf($board)?->getRunningRunId());
        foreach ($this->logger()->records as $record) {
            self::assertStringNotContainsString('pk_test_token', (string) json_encode($record));
        }
        self::assertGreaterThan(0, count($this->logger()->records));
    }
}
