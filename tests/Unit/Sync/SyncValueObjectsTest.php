<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Sync;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Import\Dto\ImportedTaskDto;
use Nowo\TaskBoardBundle\Sync\Content\RemoteContentSanitizer;
use Nowo\TaskBoardBundle\Sync\Dto\FileCounts;
use Nowo\TaskBoardBundle\Sync\Dto\SkipCounts;
use Nowo\TaskBoardBundle\Sync\Dto\SyncConflict;
use Nowo\TaskBoardBundle\Sync\Dto\SyncCursor;
use Nowo\TaskBoardBundle\Sync\Dto\SyncError;
use Nowo\TaskBoardBundle\Sync\Dto\SyncMode;
use Nowo\TaskBoardBundle\Sync\Dto\SyncPhase;
use Nowo\TaskBoardBundle\Sync\Dto\SyncReport;
use Nowo\TaskBoardBundle\Sync\Dto\SyncStatus;
use Nowo\TaskBoardBundle\Sync\Dto\SyncValues;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderException;
use Nowo\TaskBoardBundle\Sync\File\FetchedFile;
use Nowo\TaskBoardBundle\Sync\Hook\MapInlineImageRewriter;
use Nowo\TaskBoardBundle\Sync\Hook\NullSyncLimitChecker;
use Nowo\TaskBoardBundle\Sync\Hook\NullTaskAttachmentStorage;
use Nowo\TaskBoardBundle\Sync\Hook\StoredAttachment;
use Nowo\TaskBoardBundle\Sync\Provider\ContainerType;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCredential;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteAttachment;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteContainer;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteTask;
use Nowo\TaskBoardBundle\Sync\Provider\TaskProviderRegistry;
use Nowo\TaskBoardBundle\Sync\Testing\InMemoryTaskProvider;
use Nowo\TaskBoardBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;

use function iterator_to_array;
use function ob_get_clean;
use function ob_start;
use function print_r;
use function serialize;
use function str_repeat;
use function var_dump;

/**
 * Since 1.23: credentials, the sanitizer, the report / cursor round trip and the default hooks.
 */
final class SyncValueObjectsTest extends TestCase
{
    public function testACredentialNeverShowsItsSecret(): void
    {
        $credential = new ProviderCredential('pk_secret_1234', 'conn-1', ['scheme' => 'personal']);
        self::assertSame('pk_secret_1234', $credential->reveal());
        self::assertSame('1234', $credential->lastFour());
        ob_start();
        var_dump($credential);
        $dump = ob_get_clean() . print_r($credential, true);
        self::assertStringNotContainsString('pk_secret', $dump);
        self::assertStringContainsString('[redacted]', $dump);

        try {
            serialize($credential);
            self::fail('serialized');
        } catch (LogicException) {
        }
        $this->expectException(LogicException::class);
        $credential->__unserialize([]);
    }

    public function testTheSanitizerKeepsMarkdownAndDropsWhatIsUnsafe(): void
    {
        $sanitizer = new RemoteContentSanitizer();
        $markdown  = "Hello <b onclick=\"x\">bold</b> <!-- hidden -->\u{202E}evil\x07\r\n"
            . "[ok](https://example.test) [mail](mailto:a@example.test) [bad](javascript:alert(1)) [rel](//evil.test) ![img](data:image/png;base64,AAA)\n"
            . "[id]: javascript:alert(1)\n[good]: https://example.test\n"
            . "`<script>kept in code</script>`\n```\n<b>fence</b>\n```\n<javascript:alert(1)>";
        $clean = $sanitizer->markdown($markdown, 10_000);

        self::assertStringContainsString('Hello bold', $clean);
        self::assertStringNotContainsString('<b', str_replace(['`<script>kept in code</script>`', '<b>fence</b>'], '', $clean));
        self::assertStringNotContainsString("\u{202E}", $clean);
        self::assertStringNotContainsString("\x07", $clean);
        self::assertStringNotContainsString('hidden', $clean);
        self::assertStringContainsString('[ok](https://example.test)', $clean);
        self::assertStringContainsString('[mail](mailto:a@example.test)', $clean);
        self::assertStringNotContainsString('javascript', $clean);
        self::assertStringNotContainsString('//evil.test', $clean);
        self::assertStringNotContainsString('data:', $clean);
        self::assertStringContainsString('[good]: https://example.test', $clean);
        self::assertStringContainsString('`<script>kept in code</script>`', $clean);
        self::assertStringContainsString('<b>fence</b>', $clean);

        $long = $sanitizer->markdown(str_repeat('é', 100), 21);
        self::assertSame(str_repeat('é', 9) . '…', $long);
        self::assertSame('a b', $sanitizer->line("a\n\t b", 10));
        self::assertSame("ok\nx", $sanitizer->markdown("ok\n\xff\xfex", 100), 'invalid UTF-8 is dropped');
        self::assertSame("Para\n**Bold** *it* [link](https://example.test)\n- one", $sanitizer->html('<p>Para</p><strong>Bold</strong> <em>it</em> <a href="https://example.test">link</a><li>one</li><script>x</script>', 1000));
        self::assertFalse($sanitizer->isSafeUrl('no-scheme'));
        self::assertTrue($sanitizer->isSafeUrl(' HTTPS://x '));
    }

    public function testTheReportAndTheCursorRoundTrip(): void
    {
        $cursor = new SyncCursor('run-1', SyncPhase::Deletions, '3', new DateTimeImmutable('2030-01-01T00:00:00+00:00'), new DateTimeImmutable('2030-01-02T00:00:00+00:00'), 4, SyncMode::Full, [['t', 'o', 'parent']]);
        $report = new SyncReport(
            runId: 'run-1',
            boardId: 'b',
            provider: 'clickup',
            mode: SyncMode::Full,
            status: SyncStatus::Paused,
            dryRun: true,
            created: 1,
            updated: 2,
            unchanged: 3,
            archived: 4,
            restored: 5,
            skips: new SkipCounts(1, 2, 3, 4, 5),
            conflicts: [new SyncConflict('t', 'Title', 'x', 'title', 'Remote')],
            columnsCreated: 6,
            dependenciesCreated: 7,
            commentsCreated: 8,
            commentsUpdated: 9,
            files: new FileCounts(1, 2, 3, 4),
            warnings: [new SyncError('mass_archive_guard', ['%count%' => '3'])],
            errors: [new SyncError('internal')],
            cursor: $cursor,
            refusalCode: 'storage',
            retryAfterSeconds: 60,
            pagesDone: 2,
            total: 9,
            startedAt: new DateTimeImmutable('2030-01-01T00:00:00+00:00'),
            syncedAt: new DateTimeImmutable('2030-01-03T00:00:00+00:00'),
        );

        self::assertSame($report->toArray(), SyncReport::fromArray($report->toArray())->toArray());
        self::assertSame(15, $report->skipped());
        self::assertTrue($report->massArchiveHeld());
        self::assertTrue($report->hasErrors());
        self::assertFalse($report->isComplete());
        self::assertSame('task_board.sync.error.internal', $report->errors[0]->messageKey());
        self::assertSame($cursor->toArray(), SyncCursor::fromArray($cursor->toArray())->toArray());

        $empty = SyncReport::fromArray(['conflicts' => ['junk'], 'warnings' => ['junk'], 'errors' => [['params' => ['a' => ['x']]]], 'status' => 'nope']);
        self::assertSame(SyncStatus::Failed, $empty->status);
        self::assertSame('', $empty->conflicts[0]->field);
        self::assertSame(['a' => ''], $empty->errors[0]->params);
        $odd = SyncCursor::fromArray(['pendingDependencies' => [['a'], 'x', ['a', 'b', 'c']], 'phase' => 'nope', 'providerCursor' => 7, 'updatedSince' => 'not a date']);
        self::assertSame([['a', 'b', 'c']], $odd->pendingDependencies);
        self::assertSame('7', $odd->providerCursor);
        self::assertNull($odd->updatedSince);
        self::assertSame(SyncPhase::Tasks, $odd->phase);
        self::assertSame(0, SyncValues::int('x'));
        self::assertSame('', SyncValues::string(['x']));
        self::assertNull(SyncValues::date(''));
        self::assertFalse((new SyncReport('r', 'b', 'p', SyncMode::Initial, SyncStatus::Succeeded))->massArchiveHeld());

        self::assertTrue(SyncStatus::Succeeded->isTerminal());
        self::assertFalse(SyncStatus::Running->isTerminal());
        self::assertTrue(SyncStatus::RateLimited->isResumable());
    }

    public function testTheDefaultHooksAndTheRegistry(): void
    {
        $board    = new TaskBoard('B', 'b', new TestUser('1', 'one'));
        $task     = new Task($board, 'T', new TestUser('1', 'one'));
        $stored   = new StoredAttachment('ref', '/files/ref', 'a.png', 3, 'image/png');
        $rewriter = new MapInlineImageRewriter();
        self::assertSame(
            '![a](/files/ref) [image not copied] keep [link](https://x)',
            $rewriter->rewrite($task, '![a](https://cdn/a.png "t") ![b](https://cdn/b.png) keep [link](https://x)', ['https://cdn/a.png' => $stored]),
        );
        self::assertNull((new NullSyncLimitChecker())->check($board, 1, 1));
        $storage = new NullTaskAttachmentStorage();
        $storage->delete($task, $stored);
        try {
            $storage->store($task, new RemoteAttachment('a', 'a', null, null, 'https://x'), static fn (): null => null);
            self::fail('no storage');
        } catch (LogicException) {
        }
        self::assertSame(['id' => 'a', 'name' => 'a', 'size' => null, 'mime' => null, 'host' => 'x'], (new RemoteAttachment('a', 'a', null, null, 'https://x/s?sig=1'))->__debugInfo());

        $memory   = new InMemoryTaskProvider('memory');
        $registry = new TaskProviderRegistry([$memory, new InMemoryTaskProvider('memory')]);
        self::assertTrue($registry->has('memory'));
        self::assertSame($memory, $registry->get('memory'));
        self::assertCount(1, $registry->all());
        $this->expectException(InvalidArgumentException::class);
        $registry->get('nope');
    }

    public function testTheInMemoryProvider(): void
    {
        $provider   = new InMemoryTaskProvider('memory', 'secret', 1);
        $credential = new ProviderCredential('secret', 'c');
        $provider->addContainer(new RemoteContainer('a', 'A', ContainerType::List, null, true, false))
            ->addContainer(new RemoteContainer('b', 'B', ContainerType::Folder, null, false, true));
        $task = new RemoteTask(new ImportedTaskDto('T', 'x'), new DateTimeImmutable('@10'), archived: true);
        $provider->putTask('a', $task)->putTask('a', new RemoteTask(new ImportedTaskDto('U', 'y'), new DateTimeImmutable('@20')));

        self::assertSame('task_board.sync.provider.memory', $provider->labelKey());
        self::assertSame('memory', $provider->importScope($credential));
        self::assertSame('1', $provider->listContainers($credential)->nextCursor);
        self::assertNull($provider->listContainers($credential, null, '1')->nextCursor);
        self::assertNull($provider->findContainer($credential, 'b'), 'a folder is not selectable');
        self::assertSame('1', $provider->fetchTasks($credential, 'a', null, null)->nextCursor);
        self::assertSame('y', $provider->fetchTasks($credential, 'a', new DateTimeImmutable('@15'), null)->tasks[0]->externalId());
        self::assertSame(['y'], iterator_to_array($provider->existingTaskIds($credential, 'a'), false));
        self::assertSame([], $provider->fetchComments($credential, 'x'));
        self::assertContains('fetchComments:x', $provider->calls);

        $fetched = new FetchedFile('/nonexistent/path', 'n', 0, 'text/plain', '');
        $fetched->delete();
        $this->expectException(ProviderException::class);
        $provider->fetchTasks(new ProviderCredential('wrong', 'c'), 'a', null, null);
    }
}
