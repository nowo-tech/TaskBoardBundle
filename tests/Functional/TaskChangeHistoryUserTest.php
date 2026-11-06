<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Nowo\TaskBoardBundle\Entity\TaskChangeHistory;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Service\TaskChangeRecorder;
use Nowo\TaskBoardBundle\Service\TaskTimeEntrySnapshotPurger;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;

/**
 * Since 1.28.0 (audit GWf-L1): a history row, `time_removed` included, outlives its author's account.
 */
final class TaskChangeHistoryUserTest extends FunctionalTestCase
{
    public function testDeletingTheUserKeepsTheRowAndTheName(): void
    {
        $bob  = $this->user('bob@example.com');
        $task = $this->task($this->board(), 'Work');
        (new TaskChangeRecorder())->record($task, $bob, TaskChangeType::TimeRemoved, '60');
        $this->em->flush();
        $row = $this->em->getRepository(TaskChangeHistory::class)->findOneBy(['task' => $task->getId(), 'changeType' => TaskChangeType::TimeRemoved]);
        self::assertInstanceOf(TaskChangeHistory::class, $row);
        self::assertSame('bob@example.com', $row->getUserName());
        [$id, $taskId, $bobId] = [$row->getId(), $task->getId(), $bob->getId()];

        $this->em->clear();
        if ($this->em->getConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
            $this->em->getConnection()->executeStatement('PRAGMA foreign_keys = ON');
        }
        $this->em->getConnection()->executeStatement('DELETE FROM app_user WHERE id = ?', [$bobId]);

        $kept = $this->em->find(TaskChangeHistory::class, $id);
        self::assertInstanceOf(TaskChangeHistory::class, $kept);
        self::assertSame([null, 'bob@example.com', '60'], [$kept->getUser(), $kept->getUserName(), $kept->getOldValue()]);
        $kept->backfillUserName();
        self::assertSame('bob@example.com', $kept->getUserName());

        $this->refreshOwnerRow();
        $this->login($this->owner);
        $this->client->request('GET', '/tools/task-board/task/' . $taskId);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('bob@example.com', (string) $this->client->getResponse()->getContent());

        // Forgotten afterwards: the fact of the change stays, shown as a former user.
        $purger = self::getContainer()->get(TaskTimeEntrySnapshotPurger::class);
        self::assertInstanceOf(TaskTimeEntrySnapshotPurger::class, $purger);
        $purger->forgetUserName('bob@example.com');
        $this->em->clear();
        $this->refreshOwnerRow();
        $this->login($this->owner);
        $this->client->request('GET', '/tools/task-board/task/' . $taskId);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Former user', (string) $this->client->getResponse()->getContent());
        self::assertInstanceOf(TaskChangeHistory::class, $this->em->find(TaskChangeHistory::class, $id));
    }

    private function refreshOwnerRow(): void
    {
        $owner = $this->em->find(AppUser::class, $this->owner->getId());
        self::assertInstanceOf(AppUser::class, $owner);
        $this->owner = $owner;
    }

    public function testDetachAndBackfill(): void
    {
        $bob  = $this->user('bob@example.com');
        $task = $this->task($this->board(), 'Work');
        (new TaskChangeRecorder())->record($task, $bob, TaskChangeType::TimeRemoved, '60');
        $this->em->flush();
        $this->em->getConnection()->executeStatement('UPDATE task_board_task_change_history SET user_name = NULL');
        $this->em->clear();
        $purger = self::getContainer()->get(TaskTimeEntrySnapshotPurger::class);
        self::assertInstanceOf(TaskTimeEntrySnapshotPurger::class, $purger);
        self::assertGreaterThanOrEqual(1, $purger->backfillUserNames());
        $row = $this->em->getRepository(TaskChangeHistory::class)->findOneBy(['changeType' => TaskChangeType::TimeRemoved]);
        self::assertInstanceOf(TaskChangeHistory::class, $row);
        self::assertSame('bob@example.com', $row->getUserName());
        $row->detachUser();
        self::assertSame([null, 'bob@example.com'], [$row->getUser(), $row->getUserName()]);
        $row->detachUser(true);
        self::assertNull($row->getUserName());
    }
}
