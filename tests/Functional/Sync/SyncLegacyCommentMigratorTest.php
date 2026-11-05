<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Sync\Entity\TaskBoardConnection;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncComment;
use Nowo\TaskBoardBundle\Sync\People\TaskSyncCommentLegacyAuthorMigrator;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteComment;
use Nowo\TaskBoardBundle\Sync\Provider\RemotePerson;
use Symfony\Component\Yaml\Yaml;

use function glob;
use function ksort;

/**
 * Since 1.25 (§5.6): comments a 1.23 sync wrote with the "**name** (provider):" line move to the person reference
 * when the name matches exactly one person; batched, resumable, idempotent; the next sync sees no conflict.
 */
final class SyncLegacyCommentMigratorTest extends SyncPeopleFunctionalTestCase
{
    public function testThePrefixIsTheSameInEveryShippedLocale(): void
    {
        $files = glob(__DIR__ . '/../../../src/Resources/translations/NowoTaskBoardBundle.*.yaml') ?: [];
        self::assertCount(7, $files);
        foreach ($files as $file) {
            $messages = Yaml::parseFile($file);
            self::assertIsArray($messages);
            self::assertSame('**%name%** (%provider%):', $messages['task_board.sync.comment_prefix'] ?? null, $file);
        }
    }

    public function testLegacyPrefixedCommentsPointAtTheirOnlyMatchingPerson(): void
    {
        $board = $this->boardWithColumns('To do', 'Done');
        $this->put($this->row('t1', 'Task', 1));
        $at = new DateTimeImmutable('@1700000000');
        $this->provider->setComments('t1', [
            new RemoteComment('c1', 'From one', null, 'User 9001', $at),
            new RemoteComment('c2', 'From a twin', null, 'Twin', $at),
            new RemoteComment('c3', 'From nobody', null, 'Nobody', $at),
            new RemoteComment('c4', 'From the owner', 'owner@example.com', 'Owner', $at),
        ]);
        // 1.23: no directory, the names are baked into the bodies.
        $this->sync($board, people: false);
        self::assertSame("**User 9001** (ClickUp):\n\nFrom one", $this->bodies()['c1']);

        $this->workspace([self::member('9001'), new RemotePerson('9002', 'Twin'), new RemotePerson('9003', 'Twin')]);
        $this->put($this->row('t1', 'Task', 2));
        $this->sync($board);

        $first  = $this->migrator()->migrate($this->connection($board), 3);
        $second = $this->migrator()->migrate($this->connection($board), 3, $first->lastId);
        self::assertSame([false, true], [$first->done, $second->done], 'resumable by the last id');
        self::assertSame([1, 2], [$first->migrated + $second->migrated, $first->skipped + $second->skipped]);

        $bodies = $this->bodies();
        self::assertSame('From one', $bodies['c1']);
        self::assertSame("**Twin** (ClickUp):\n\nFrom a twin", $bodies['c2'], 'two people with that name: unchanged');
        self::assertSame("**Nobody** (ClickUp):\n\nFrom nobody", $bodies['c3'], 'nobody with that name: unchanged');
        self::assertSame('From the owner', $bodies['c4']);
        self::assertSame('9001', $this->em->getRepository(TaskSyncComment::class)->findOneBy(['remoteId' => 'c1'])?->getAuthorPerson()?->getProviderUserId());

        // Idempotent, and the next sync sees no conflict.
        $again = $this->migrator()->migrate($this->connection($board));
        self::assertSame([0, 2, true], [$again->migrated, $again->skipped, $again->done]);
        self::assertSame($this->connection($board)->getDirectory()?->getId(), $again->directoryId);
        $report = $this->sync($board, full: true);
        self::assertSame([], $report->conflicts);
        self::assertSame('From one', $this->bodies()['c1']);
    }

    public function testAConnectionWithoutADirectoryMigratesNothing(): void
    {
        $board = $this->boardWithColumns('To do', 'Done');
        $this->put($this->row('t1', 'Task', 1));
        $this->provider->setComments('t1', [new RemoteComment('c1', 'Hi', null, 'User 9001', new DateTimeImmutable('@1700000000'))]);
        $this->sync($board, people: false);
        $result = $this->migrator()->migrate($this->connection($board), 0);
        self::assertSame([0, 1], [$result->migrated, $result->skipped]);
        self::assertNull($result->directoryId);
        self::assertSame($this->connection($board)->getId(), $result->connectionId);
    }

    /**
     * @return array<string, string>
     */
    private function bodies(): array
    {
        $this->em->clear();
        $bodies = [];
        foreach ($this->em->getRepository(TaskSyncComment::class)->findAll() as $sync) {
            $bodies[$sync->getRemoteId()] = $sync->getComment()->getBody();
        }
        ksort($bodies);

        return $bodies;
    }

    private function connection(TaskBoard $board): TaskBoardConnection
    {
        $connection = $this->connectionOf($board);
        self::assertInstanceOf(TaskBoardConnection::class, $connection);

        return $connection;
    }

    private function migrator(): TaskSyncCommentLegacyAuthorMigrator
    {
        $migrator = self::getContainer()->get(TaskSyncCommentLegacyAuthorMigrator::class);
        self::assertInstanceOf(TaskSyncCommentLegacyAuthorMigrator::class, $migrator);

        return $migrator;
    }
}
