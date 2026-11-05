<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Entity\TaskTimeEntry;
use Nowo\TaskBoardBundle\Service\TaskTimeLogger;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;

/**
 * Since 1.25 (audit FP-M1): erasing an account never deletes imported work. The PoC of the audit: a task created in
 * ClickUp by Alice (projected creator and comment author), a subtask of the owner under it, the board unlinked, then
 * `detachUser()` and the user's row deleted — the task, the subtask, the comment and the time stay, with Alice's name.
 */
final class SyncAccountErasureTest extends SyncReassignTestCase
{
    public function testDetachUserAfterAnUnlinkKeepsTheImportedTaskAndItsSubtree(): void
    {
        [$alice, $taskId, $subId, $commentId, $entryId] = $this->scenario();

        $this->service()->unlink($this->freshBoardOf($this->em->find(Task::class, $taskId)?->getBoard() ?? self::fail('no task')), $this->owner);
        $this->em->clear();
        $this->refreshOwner();
        $report = $this->reassigner()->detachUser($this->freshUser($alice), $this->owner);
        self::assertSame([1, 1], [$report->creators, $report->comments], 'no sync row any more: the creator and the author are detached directly');

        $this->deleteUserRow($alice);

        $task = $this->em->find(Task::class, $taskId);
        self::assertInstanceOf(Task::class, $task, 'task kept');
        self::assertNull($task->getCreator());
        self::assertSame('alice@example.com', $task->getCreatorName(), 'the name stays');
        self::assertInstanceOf(Task::class, $this->em->find(Task::class, $subId), 'subtask kept');
        $comment = $this->em->find(TaskComment::class, $commentId);
        self::assertInstanceOf(TaskComment::class, $comment, 'comment kept');
        self::assertNull($comment->getAuthor());
        self::assertSame('alice@example.com', $comment->getAuthorName());
        self::assertInstanceOf(TaskTimeEntry::class, $this->em->find(TaskTimeEntry::class, $entryId));

        // The activity stream shows the kept name.
        $this->login($this->owner);
        $this->client->request('GET', '/tools/task-board/task/' . $taskId);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-comment-id="' . $commentId . '"]', 'alice@example.com');
    }

    public function testAnonymisingForgetsTheNameAndADeleteWithoutDetachStillKeepsTheTasks(): void
    {
        [$alice, $taskId, $subId] = $this->scenario();
        $this->em->clear();
        $this->refreshOwner();
        $bob = $this->user('bob@example.com');
        $own = new Task(board: $this->freshBoardOf($this->em->find(Task::class, $taskId)?->getBoard() ?? self::fail('no task')), title: 'Bob manual', creator: $bob);
        $this->em->persist($own);
        $said = new TaskComment($own, $bob, 'Bob says');
        $this->em->persist($said);
        $this->em->flush();
        $ownId  = $own->getId();
        $saidId = $said->getId();

        // Loaded rows follow the bulk detach; anonymise forgets the name snapshots.
        $this->reassigner()->detachUser($bob, $this->owner, true);
        self::assertNull($said->getAuthor());
        self::assertNull($said->getAuthorName());
        $this->em->clear();
        $saidKept = $this->em->find(TaskComment::class, $saidId);
        self::assertInstanceOf(TaskComment::class, $saidKept);
        self::assertNull($saidKept->getAuthorName());
        $kept = $this->em->find(Task::class, $ownId);
        self::assertInstanceOf(Task::class, $kept);
        self::assertNull($kept->getCreator());
        self::assertNull($kept->getCreatorName());

        // No detach at all: the user's row goes and the database keeps the tasks (ON DELETE SET NULL).
        $this->deleteUserRow($alice);
        self::assertInstanceOf(Task::class, $this->em->find(Task::class, $taskId), 'task kept');
        self::assertInstanceOf(Task::class, $this->em->find(Task::class, $subId), 'subtask kept');
    }

    /**
     * @return array{0: AppUser, 1: string, 2: string, 3: string, 4: string}
     */
    private function scenario(): array
    {
        $alice = $this->user('alice@example.com');
        $board = $this->board(creator: $this->owner);
        $this->viewer($board, $alice);
        $directory = $this->directory();
        $item      = $this->item($this->connect($board, $directory), 'Created by Alice in ClickUp', $this->person($directory, '777', $alice), $alice, $alice);
        $parent    = $item->getTask();
        self::assertInstanceOf(Task::class, $parent);
        $sub    = $this->task($board, 'Owner subtask', 0, $parent);
        $logger = self::getContainer()->get(TaskTimeLogger::class);
        self::assertInstanceOf(TaskTimeLogger::class, $logger);
        $entryId = $logger->log($sub, $this->owner, 30, new DateTimeImmutable('2026-11-01'))->getId();
        $comment = $this->em->getRepository(TaskComment::class)->findOneBy(['task' => $parent]);
        self::assertInstanceOf(TaskComment::class, $comment);

        return [$alice, $parent->getId(), $sub->getId(), $comment->getId(), $entryId];
    }

    private function deleteUserRow(AppUser $user): void
    {
        $this->em->getConnection()->executeStatement('DELETE FROM ' . $this->em->getClassMetadata(AppUser::class)->getTableName() . ' WHERE id = ?', [$user->getId()]);
        $this->em->clear();
        $this->refreshOwner();
    }
}
