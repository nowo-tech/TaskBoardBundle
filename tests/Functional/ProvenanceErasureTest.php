<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Provenance\WriteChannel;
use Nowo\TaskBoardBundle\Provenance\WriteProvenance;
use Nowo\TaskBoardBundle\Service\TaskCommentManager;
use Nowo\TaskBoardBundle\Service\TaskManager;
use Nowo\TaskBoardBundle\Sync\People\TaskSyncPeopleReassigner;
use Nowo\TaskBoardBundle\Tests\Functional\App\PathWriteProvenance;
use Nowo\TaskBoardBundle\Tests\Functional\Sync\SyncFunctionalTestCase;

/**
 * Since 2.19.0 (Beacon spec 234): the actor label is personal data. The account erasure (`detachUser()`) drops the
 * last-writer reference of tasks and comments (loaded or not) and, when anonymising, the actor label of the rows that
 * person wrote; without anonymising the label stays, as the name snapshots do.
 */
final class ProvenanceErasureTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        PathWriteProvenance::reset();
        self::$taskBoardConfig = ['write_provenance_provider' => 'app.write_provenance'] + SyncFunctionalTestCase::SYNC_CONFIG;
        parent::setUp();
    }

    protected function tearDown(): void
    {
        PathWriteProvenance::reset();
        parent::tearDown();
    }

    public function testTheErasureDropsTheLastWriterAndWhenAnonymisingTheLabel(): void
    {
        $writer                       = $this->user('writer@example.com');
        PathWriteProvenance::$console = new WriteProvenance(WriteChannel::App, 'Writer\'s phone');
        $task                         = $this->task($this->board(), 'Plan');
        $kept                         = $this->task($task->getBoard(), 'Kept');
        $this->tasks()->updatePriority($task, TaskPriority::High, $writer);
        $this->tasks()->updatePriority($kept, TaskPriority::High, $writer);
        $comment = $this->comments()->add($task, $writer, 'Mine');
        $this->em->flush();

        // Not anonymised: the reference goes, the label stays (loaded rows follow).
        $this->reassigner()->detachUser($writer, $this->owner);
        self::assertNull($task->getUpdatedBy());
        self::assertSame('Writer\'s phone', $task->getActorLabel());
        self::assertNull($comment->getUpdatedBy());

        // Anonymised, with the rows not loaded: the label goes too.
        $kept->recordWrite($writer, new WriteProvenance(WriteChannel::App, 'Writer\'s phone'));
        $this->em->flush();
        $ids = [$task->getId(), $kept->getId(), $comment->getId()];
        $this->em->clear();
        $writer = $this->em->find($writer::class, $writer->getId());
        self::assertNotNull($writer);
        $owner = $this->em->find($this->owner::class, $this->owner->getId());
        self::assertNotNull($owner);
        $this->reassigner()->detachUser($writer, $owner, true);
        $this->em->clear();
        // As with the name snapshots, a row an earlier, non-anonymising erasure already detached keeps its label.
        self::assertSame('Writer\'s phone', $this->em->find(Task::class, $ids[0])?->getActorLabel());
        $row = $this->em->find(Task::class, $ids[1]);
        self::assertInstanceOf(Task::class, $row);
        self::assertNull($row->getUpdatedBy());
        self::assertNull($row->getActorLabel(), 'anonymised');
        self::assertSame(WriteChannel::App, $row->getChannel());
        $comment = $this->em->find(TaskComment::class, $ids[2]);
        self::assertInstanceOf(TaskComment::class, $comment);
        self::assertNull($comment->getUpdatedBy());
    }

    private function tasks(): TaskManager
    {
        $service = self::getContainer()->get(TaskManager::class);
        self::assertInstanceOf(TaskManager::class, $service);

        return $service;
    }

    private function comments(): TaskCommentManager
    {
        $service = self::getContainer()->get(TaskCommentManager::class);
        self::assertInstanceOf(TaskCommentManager::class, $service);

        return $service;
    }

    private function reassigner(): TaskSyncPeopleReassigner
    {
        $service = self::getContainer()->get(TaskSyncPeopleReassigner::class);
        self::assertInstanceOf(TaskSyncPeopleReassigner::class, $service);

        return $service;
    }
}
