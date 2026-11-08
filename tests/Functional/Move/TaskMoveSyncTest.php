<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Move;

use DateTimeImmutable;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\TaskBoardBundle\Copy\TaskCopier;
use Nowo\TaskBoardBundle\Copy\TaskCopyOptions;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskDocument;
use Nowo\TaskBoardBundle\Exception\TaskCopyRefusedException;
use Nowo\TaskBoardBundle\Move\TaskMovePlanner;
use Nowo\TaskBoardBundle\Move\TaskMover;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TaskBoardBundle\Service\TaskChangeRecorder;
use Nowo\TaskBoardBundle\Service\TaskMemberAssigner;
use Nowo\TaskBoardBundle\Sync\Entity\TaskBoardConnection;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncField;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncFieldValue;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncItem;
use Nowo\TaskBoardBundle\Sync\Field\SyncFieldType;
use Nowo\TaskBoardBundle\Sync\Field\SyncFieldValueEraser;
use Nowo\TaskBoardBundle\Sync\View\SyncFieldViewProvider;
use Nowo\TaskBoardBundle\Sync\View\SyncPeopleViewProvider;
use Nowo\TaskBoardBundle\Tests\Functional\Sync\SyncFunctionalTestCase;

/**
 * Spec 217, FR-012 and SC-005: a synced task moved off its board is detached for good, a move waits for a running sync, and
 * a copy of a synced task is a local task.
 */
final class TaskMoveSyncTest extends SyncFunctionalTestCase
{
    public function testAMovedSyncedTaskIsNeitherUpdatedNorRecreatedByTheNextSync(): void
    {
        $board  = $this->board('Linked', ['To do', 'Done']);
        $target = $this->board('Local', ['To do', 'Done']);
        $this->put($this->remote('a1', 'Design'));
        $report = $this->sync($board);
        self::assertSame([], $report->errors);
        $task   = $this->taskTitled('Design');
        $target = $this->em->find(TaskBoard::class, $target->getId());
        self::assertInstanceOf(TaskBoard::class, $target);

        $plan = $this->planner()->plan($task, $target);
        self::assertSame(1, $plan->syncDetached);
        $this->mover()->move($task, $target, [], $plan->fingerprint, $this->owner);
        $this->em->clear();
        $item = $this->em->getRepository(TaskSyncItem::class)->findOneBy(['externalId' => 'a1']);
        self::assertInstanceOf(TaskSyncItem::class, $item);
        self::assertNull($item->getTask(), 'a tombstone: the provider data stays on it');

        $this->put($this->remote('a1', 'Design, renamed remotely', 5));
        $this->sync($board);
        $this->em->clear();
        self::assertSame([], $this->tasksOf($board), 'not recreated on the source');
        $moved = $this->tasksOf($target);
        self::assertCount(1, $moved);
        self::assertSame('Design', $moved[0]->getTitle(), 'not updated');
    }

    public function testAMoveIsRefusedWhileASyncRunsOnEitherBoard(): void
    {
        $board  = $this->board('Linked', ['To do', 'Done']);
        $target = $this->board('Local', ['To do', 'Done']);
        $this->put($this->remote('a1', 'Design'));
        $this->sync($board);
        $connection = $this->connectionOf($board);
        self::assertInstanceOf(TaskBoardConnection::class, $connection);
        $this->em->createQuery('UPDATE ' . TaskBoardConnection::class . ' c SET c.runningRunId = :run WHERE c.id = :id')->execute(['run' => 'run-1', 'id' => $connection->getId()]);
        $this->em->clear();
        $this->refreshOwner();
        $task   = $this->taskTitled('Design');
        $target = $this->em->find(TaskBoard::class, $target->getId());
        self::assertInstanceOf(TaskBoard::class, $target);
        $plan = $this->planner()->plan($task, $target);

        try {
            $this->mover()->move($task, $target, [], $plan->fingerprint, $this->owner);
            self::fail('A running sync must refuse the move.');
        } catch (TaskCopyRefusedException $exception) {
            self::assertSame('sync_running', $exception->reason);
        }
        $local = $this->task($target, 'Local task');
        $back  = $this->planner()->plan($local, $task->getBoard());
        try {
            $this->mover()->move($local, $task->getBoard(), [], $back->fingerprint, $this->owner);
            self::fail('A running sync on the target must refuse the move too.');
        } catch (TaskCopyRefusedException $exception) {
            self::assertSame('sync_running', $exception->reason);
        }
        self::assertSame($board->getId(), $this->taskTitled('Design')->getBoard()->getId());
    }

    public function testACopyOfASyncedTaskIsLocal(): void
    {
        $board = $this->board('Linked', ['To do', 'Done']);
        $this->put($this->remote('a1', 'Design'));
        $this->sync($board);
        $task = $this->taskTitled('Design');

        $copier = self::getContainer()->get(TaskCopier::class);
        self::assertInstanceOf(TaskCopier::class, $copier);
        self::assertTrue($copier->preview($task)->synced);
        $copy = $copier->copy($task, new TaskCopyOptions(title: 'Design (copy)'), $this->owner);

        self::assertNull($this->em->getRepository(TaskSyncItem::class)->findOneBy(['task' => $copy->task]));
        self::assertCount(0, $copy->task->getLinks());
        self::assertInstanceOf(Task::class, $copy->task);
    }

    public function testAMovedTaskKeepsItsProviderDataAndImportedFieldsReadOnly(): void
    {
        $board  = $this->board('Linked', ['To do', 'Done']);
        $target = $this->board('Local', ['To do', 'Done']);
        $this->put($this->remote('a1', 'Design'));
        $this->sync($board);
        $task = $this->taskTitled('Design');
        $item = $this->em->getRepository(TaskSyncItem::class)->findOneBy(['task' => $task]);
        self::assertInstanceOf(TaskSyncItem::class, $item);
        $item->setCustomId('DEV-7');
        $field = new TaskSyncField($task->getBoard(), 'clickup', 'f1', new DateTimeImmutable());
        $field->define('Points', SyncFieldType::Number, 'number', [], [], 0, new DateTimeImmutable());
        $this->em->persist($field);
        $this->em->persist(new TaskSyncFieldValue($field, $task, 3, '3', new DateTimeImmutable()));
        $this->em->flush();
        $target = $this->em->find(TaskBoard::class, $target->getId());
        self::assertInstanceOf(TaskBoard::class, $target);

        $plan = $this->planner()->plan($task, $target);
        self::assertSame(['Points'], $plan->importedFields);
        $this->mover()->move($task, $target, [], $plan->fingerprint, $this->owner);
        $this->em->clear();
        $item = $this->em->getRepository(TaskSyncItem::class)->findOneBy(['externalId' => 'a1']);
        self::assertInstanceOf(TaskSyncItem::class, $item);
        self::assertNull($item->getTask());
        self::assertSame($task->getId(), $item->getDetachedTask()?->getId());

        $moved = $this->em->find(Task::class, $task->getId());
        self::assertInstanceOf(Task::class, $moved);
        $views = self::getContainer()->get(SyncPeopleViewProvider::class);
        self::assertInstanceOf(SyncPeopleViewProvider::class, $views);
        self::assertSame('DEV-7', $views->detailsOf($moved)['customId'] ?? null, 'the panel still shows it, read-only');

        // Owner (decision 4): imported field values stay, hidden on the target board, reached by the source's erasure.
        $fields = self::getContainer()->get(SyncFieldViewProvider::class);
        self::assertInstanceOf(SyncFieldViewProvider::class, $fields);
        self::assertSame([], $fields->fieldsOf($moved));
        self::assertSame([], $fields->searchTextsOf([$moved]));
        $value = $this->em->getRepository(TaskSyncFieldValue::class)->findOneBy(['task' => $moved]);
        self::assertInstanceOf(TaskSyncFieldValue::class, $value, 'kept');
        self::assertSame($board->getId(), $value->getField()->getBoard()->getId(), 'under the source board\'s field');
    }

    public function testItemsAreTombstonedWheneverTheTablesExistEvenWithSyncOffNow(): void
    {
        $board  = $this->board('Linked', ['To do', 'Done']);
        $target = $this->board('Local', ['To do', 'Done']);
        $this->put($this->remote('a1', 'Design'));
        $this->sync($board);
        $task   = $this->taskTitled('Design');
        $target = $this->em->find(TaskBoard::class, $target->getId());
        self::assertInstanceOf(TaskBoard::class, $target);
        $members = self::getContainer()->get(TaskMemberAssigner::class);
        self::assertInstanceOf(TaskMemberAssigner::class, $members);
        $recorder = self::getContainer()->get(TaskChangeRecorder::class);
        self::assertInstanceOf(TaskChangeRecorder::class, $recorder);
        $repository = self::getContainer()->get(TaskRepositoryInterface::class);
        self::assertInstanceOf(TaskRepositoryInterface::class, $repository);

        $planner = new TaskMovePlanner($this->em, $members, syncEnabled: false);
        self::assertTrue($planner->syncMapped());
        $mover = new TaskMover($planner, $this->em, $repository, $recorder, syncEnabled: false);
        $mover->move($task, $target, [], $planner->plan($task, $target)->fingerprint, $this->owner);

        $this->em->clear();
        $item = $this->em->getRepository(TaskSyncItem::class)->findOneBy(['externalId' => 'a1']);
        self::assertInstanceOf(TaskSyncItem::class, $item);
        self::assertNull($item->getTask());
    }

    public function testTheFieldsDocumentIsHiddenOnTheTargetAndErasedByTheSource(): void
    {
        // Audit IC-I8 (owner decision 4): in `text` / `both` mode the same values are a document's lines.
        $board  = $this->board('Linked', ['To do', 'Done']);
        $target = $this->board('Local', ['To do', 'Done']);
        $this->put($this->remote('a1', 'Design', fields: "- **Points**: 5\n- **Owner**: zeb"));
        $this->sync($board);
        $task   = $this->taskTitled('Design');
        $target = $this->em->find(TaskBoard::class, $target->getId());
        self::assertInstanceOf(TaskBoard::class, $target);
        $this->mover()->move($task, $target, [], $this->planner()->plan($task, $target)->fingerprint, $this->owner);
        $this->em->clear();
        $this->refreshOwner();

        $document = $this->em->getRepository(TaskDocument::class)->findOneBy(['task' => $task->getId()]);
        self::assertInstanceOf(TaskDocument::class, $document);
        self::assertTrue($document->isSyncFields());
        self::assertFalse($document->isShownOn($target), 'hidden on the target');
        self::assertTrue($document->isShownOn($this->em->find(TaskBoard::class, $board->getId()) ?? $target));
        $this->login($this->owner);
        $this->client->request('GET', '/tools/task-board/task/' . $task->getId());
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('**Points**', (string) $this->client->getResponse()->getContent());

        $eraser = self::getContainer()->get(SyncFieldValueEraser::class);
        self::assertInstanceOf(SyncFieldValueEraser::class, $eraser);
        $source = $this->em->find(TaskBoard::class, $board->getId());
        self::assertInstanceOf(TaskBoard::class, $source);
        self::assertSame(0, $eraser->eraseByValue('zeb', $this->em->find(TaskBoard::class, $target->getId())), 'not the target\'s values');
        self::assertSame(1, $eraser->eraseByValue('zeb', $source), 'the source board\'s erasure reaches them');
        $this->em->clear();
        $document = $this->em->getRepository(TaskDocument::class)->findOneBy(['task' => $task->getId()]);
        self::assertInstanceOf(TaskDocument::class, $document);
        self::assertStringNotContainsString('zeb', $document->getContent());
        self::assertFalse($document->isShownOn($target), 'the origin survives the erasure record');
        self::assertSame(1, $eraser->eraseBoard($this->em->find(TaskBoard::class, $board->getId()) ?? $target));
    }

    public function testTheDetachedTaskForeignKeyHasTheNameDoctrineGenerates(): void
    {
        // Audit IC-I4: the name in UPGRADING and the demo migration, so schema validation reports no rename.
        $schema = (new SchemaTool($this->em))->getSchemaFromMetadata([$this->em->getClassMetadata(TaskSyncItem::class), $this->em->getClassMetadata(Task::class), $this->em->getClassMetadata(TaskBoard::class)]);
        $names  = [];
        foreach ($schema->getTable('task_board_sync_items')->getForeignKeys() as $key) {
            if ($key->getLocalColumns() === ['detached_task_id']) {
                $names[] = $key->getName();
            }
        }
        self::assertSame(['FK_3BD8DF5A4F92A733'], $names);
    }

    private function planner(): TaskMovePlanner
    {
        $service = self::getContainer()->get(TaskMovePlanner::class);
        self::assertInstanceOf(TaskMovePlanner::class, $service);

        return $service;
    }

    private function mover(): TaskMover
    {
        $service = self::getContainer()->get(TaskMover::class);
        self::assertInstanceOf(TaskMover::class, $service);

        return $service;
    }
}
