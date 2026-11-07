<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional;

use DateTimeImmutable;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Entity\TaskTimeEntry;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Event\BoardAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Service\TaskTimeEntrySnapshotPurger;
use Nowo\TaskBoardBundle\Service\TaskTimeLogger;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use Nowo\TaskBoardBundle\Tests\Functional\App\PredictableCsrfTokenManager;

use function in_array;

/**
 * Since 1.27.0 (owner rule, audit GWd-M2): deleting a user keeps their manual time, with the name it was logged with
 * until it is forgotten; an entry is removed only by its author or a manager of the board, and an entry whose author is
 * gone by a manager only.
 */
final class TaskTimeEntryUserTest extends FunctionalTestCase
{
    private const BASE = '/tools/task-board/';

    public function testDeletingTheUserKeepsTheTimeAndTheName(): void
    {
        $bob   = $this->user('bob@example.com');
        $board = $this->board();
        $task  = $this->task($board, 'Work');
        $entry = $this->timeLogger()->log($task, $bob, 45, new DateTimeImmutable('2026-11-01'));
        self::assertSame('bob@example.com', $entry->getUserName());
        $id     = $entry->getId();
        $taskId = $task->getId();

        // The database rule alone, as a host's raw delete does.
        $bobId = $bob->getId();
        $this->em->clear();
        if ($this->em->getConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
            $this->em->getConnection()->executeStatement('PRAGMA foreign_keys = ON');
        }
        $this->em->getConnection()->executeStatement('DELETE FROM app_user WHERE id = ?', [$bobId]);

        $kept = $this->em->find(TaskTimeEntry::class, $id);
        self::assertInstanceOf(TaskTimeEntry::class, $kept);
        self::assertSame([null, 'bob@example.com', 45], [$kept->getUser(), $kept->getUserName(), $kept->getMinutes()]);

        $this->refreshUsers();
        $this->login($this->owner);
        $crawler = $this->client->request('GET', self::BASE . 'task/' . $taskId);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('bob@example.com', $crawler->filter('.nowo-task-time-entries__user')->text());

        // Forgotten afterwards, by the name kept: the panel says "former user"; the time stays.
        self::assertSame(1, $this->purger()->forgetUserName('bob@example.com'));
        $this->em->clear();
        $this->refreshUsers();
        $this->login($this->owner);
        $crawler = $this->client->request('GET', self::BASE . 'task/' . $taskId);
        self::assertStringContainsString('Former person', $crawler->filter('.nowo-task-time-entries__user')->text());
        self::assertSame(45, $this->em->find(TaskTimeEntry::class, $id)?->getMinutes());
    }

    public function testDetachBeforeDeletingKeepsOrForgets(): void
    {
        $bob  = $this->user('bob@example.com');
        $task = $this->task($this->board(), 'Work');
        $kept = $this->timeLogger()->log($task, $bob, 10, new DateTimeImmutable('2026-11-01'));
        self::assertSame(1, $this->purger()->detachUser($bob));
        self::assertSame([null, 'bob@example.com'], [$kept->getUser(), $kept->getUserName()]);

        $ana  = $this->user('ana@example.com');
        $gone = $this->timeLogger()->log($task, $ana, 20, new DateTimeImmutable('2026-11-01'));
        self::assertSame(1, $this->purger()->detachUser($ana, true));
        self::assertSame([null, null, 20], [$gone->getUser(), $gone->getUserName(), $gone->getMinutes()]);
        self::assertSame(0, $this->purger()->detachUser($ana));
    }

    public function testEntriesOfBefore127AreBackfilled(): void
    {
        $bob   = $this->user('bob@example.com');
        $entry = $this->timeLogger()->log($this->task($this->board(), 'Work'), $bob, 10, new DateTimeImmutable('2026-11-01'));
        $this->em->getConnection()->executeStatement('UPDATE task_board_task_time_entries SET user_name = NULL');
        $this->em->clear();
        self::assertSame(1, $this->purger()->backfillUserNames());
        self::assertSame(0, $this->purger()->backfillUserNames());
        self::assertSame('bob@example.com', $this->em->find(TaskTimeEntry::class, $entry->getId())?->getUserName());
    }

    public function testOnlyItsAuthorOrABoardManagerRemovesAnEntry(): void
    {
        $managers   = [$this->owner->getEmail()];
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertIsObject($dispatcher);
        // Every signed-in user edits; the action "manage" is only for the managers above.
        $dispatcher->addListener(TaskBoardEvents::BOARD_ACCESS_CHECK, static function (BoardAccessCheckEvent $event) use (&$managers): void {
            $subject = $event->getSubject();
            if (!$subject instanceof AppUser) {
                return;
            }
            if ($event->getAction() === BoardAccessCheckEvent::ACTION_MANAGE && !in_array($subject->getEmail(), $managers, true)) {
                $event->deny();

                return;
            }
            $event->grant();
        });
        $author = $this->user('author@example.com');
        $editor = $this->user('editor@example.com');
        $board  = $this->board();
        $task   = $this->task($board, 'Work');
        foreach ([$author, $editor] as $member) {
            $task->addMember(new TaskMember($task, $member, TaskMemberRole::Assignee));
        }
        $this->em->flush();
        $first  = $this->timeLogger()->log($task, $author, 10, new DateTimeImmutable('2026-11-01'));
        $second = $this->timeLogger()->log($task, $author, 20, new DateTimeImmutable('2026-11-01'));
        $third  = $this->timeLogger()->log($task, $author, 30, new DateTimeImmutable('2026-11-01'));
        $taskId = $task->getId();

        // Another editor: no button, and refused.
        $this->login($editor);
        $crawler = $this->client->request('GET', self::BASE . 'task/' . $taskId);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.nowo-task-time-entries__remove'));
        $this->client->catchExceptions(true);
        $this->remove($taskId, $first->getId());
        self::assertResponseStatusCodeSame(403);

        // The author; a manager.
        $this->login($author);
        $crawler = $this->client->request('GET', self::BASE . 'task/' . $taskId);
        self::assertCount(3, $crawler->filter('.nowo-task-time-entries__remove'));
        $this->remove($taskId, $first->getId());
        self::assertResponseRedirects();
        $this->login($this->owner);
        $this->remove($taskId, $second->getId());
        self::assertResponseRedirects();
        self::assertNull($this->em->find(TaskTimeEntry::class, $first->getId()));

        // The author is gone: a manager only.
        $this->em->clear();
        $entry = $this->em->find(TaskTimeEntry::class, $third->getId());
        self::assertInstanceOf(TaskTimeEntry::class, $entry);
        $entry->detachUser();
        $this->em->flush();
        $this->login($editor);
        $this->remove($taskId, $third->getId());
        self::assertResponseStatusCodeSame(403);
        $this->login($this->owner);
        $this->remove($taskId, $third->getId());
        self::assertResponseRedirects();
    }

    private function remove(string $taskId, string $entryId): void
    {
        $this->client->request('POST', self::BASE . 'task/' . $taskId . '/time/' . $entryId . '/remove', ['_token' => PredictableCsrfTokenManager::value('task_board_task_time_remove')]);
    }

    private function refreshUsers(): void
    {
        $owner = $this->em->find(AppUser::class, $this->owner->getId());
        self::assertInstanceOf(AppUser::class, $owner);
        $this->owner = $owner;
    }

    private function timeLogger(): TaskTimeLogger
    {
        $logger = self::getContainer()->get(TaskTimeLogger::class);
        self::assertInstanceOf(TaskTimeLogger::class, $logger);

        return $logger;
    }

    private function purger(): TaskTimeEntrySnapshotPurger
    {
        return new TaskTimeEntrySnapshotPurger($this->em);
    }
}
