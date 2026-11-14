<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\DddHost;

use Doctrine\ORM\Tools\SchemaTool;
use Nowo\TaskBoardBundle\Doctrine\TaskBoardEntityManager;
use Nowo\TaskBoardBundle\Dto\TaskBoardFormData;
use Nowo\TaskBoardBundle\Dto\TaskFormData;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskChangeHistory;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Provenance\WriteChannel;
use Nowo\TaskBoardBundle\Provenance\WriteProvenance;
use Nowo\TaskBoardBundle\Service\TaskBoardCreatorInterface;
use Nowo\TaskBoardBundle\Service\TaskManagerInterface;
use Nowo\TaskBoardBundle\Tree\TaskReparenterInterface;
use Nowo\TaskBoardBundle\Tree\TaskReparentRefusedException;
use Nowo\TaskBoardBundle\Tree\TaskReparentResult;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Beacon spec 234, T004 (REQ-ARCH-001, both host kinds): on the DDD host (plain PHP users, XML mapping, private entity
 * manager, user references by id) the application layer re-parents through the framework-free port with ids only: the
 * same rules, one history row naming the actor by id with the host's provenance, a new revision, and a refusal reason.
 */
final class DddHostReparentTest extends KernelTestCase
{
    private TaskBoardEntityManager $em;

    /**
     * @param array<string, mixed> $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new DddHostKernel(['write_provenance_provider' => 'app.ddd_provenance']);
    }

    protected function setUp(): void
    {
        DddProvenance::$current = null;
        self::bootKernel();
        $em = self::getContainer()->get('app.task_board_em');
        self::assertInstanceOf(TaskBoardEntityManager::class, $em);
        $this->em = $em;
        $tool     = new SchemaTool($em);
        $tool->dropDatabase();
        $tool->createSchema($em->getMetadataFactory()->getAllMetadata());
    }

    protected function tearDown(): void
    {
        DddProvenance::$current = null;
        parent::tearDown();
    }

    public function testThePortReparentsByIdWithHistoryProvenanceAndRevision(): void
    {
        $container  = self::getContainer();
        $ana        = DddUsers::all()['u-ana'];
        $tasks      = $container->get(TaskManagerInterface::class);
        $creator    = $container->get(TaskBoardCreatorInterface::class);
        $reparenter = $container->get(TaskReparenterInterface::class);
        self::assertInstanceOf(TaskManagerInterface::class, $tasks);
        self::assertInstanceOf(TaskBoardCreatorInterface::class, $creator);
        self::assertInstanceOf(TaskReparenterInterface::class, $reparenter);
        $board  = $creator->create(new TaskBoardFormData('Domain board', 'domain-board'), $ana);
        $parent = $tasks->create($board, new TaskFormData('Epic'), $ana);
        $task   = $tasks->create($board, new TaskFormData('Story'), $ana);
        $child  = $tasks->create($board, new TaskFormData('Sub'), $ana, $task);
        $other  = $tasks->create($creator->create(new TaskBoardFormData('Other board', 'other-board'), $ana), new TaskFormData('Elsewhere'), $ana);
        $this->em->flush();
        $ids = [$parent->getId(), $task->getId(), $child->getId(), $other->getId()];
        $this->em->clear();

        DddProvenance::$current = new WriteProvenance(WriteChannel::Mcp, 'agent');
        $result                 = $reparenter->reparent($ids[1], $ids[0], $ana);

        self::assertEquals(new TaskReparentResult($ids[1], $board->getId(), null, $ids[0], true), $result);
        $connection = $this->em->getConnection();
        $table      = $this->em->getClassMetadata(Task::class)->getTableName();
        self::assertSame(['parent_id' => $ids[0], 'revision' => 2, 'updated_by_id' => 'u-ana', 'channel' => 'mcp', 'actor_label' => 'agent'], $this->row($table, $ids[1]));
        $this->em->clear();
        $rows = $this->em->getRepository(TaskChangeHistory::class)->findBy(['task' => $ids[1], 'changeType' => TaskChangeType::Parent]);
        self::assertCount(1, $rows);
        self::assertSame([null, 'Epic', 'task:' . $ids[0], WriteChannel::Mcp], [$rows[0]->getOldValue(), $rows[0]->getNewValue(), $rows[0]->getContext(), $rows[0]->getChannel()]);
        $historyTable = $this->em->getClassMetadata(TaskChangeHistory::class)->getTableName();
        self::assertSame('u-ana', $connection->fetchOne('SELECT user_id FROM ' . $historyTable . ' WHERE change_type = ?', ['parent']));

        foreach ([[$ids[1], $ids[2], TaskReparentRefusedException::DESCENDANT], [$ids[1], $ids[1], TaskReparentRefusedException::SELF], [$ids[1], $ids[3], TaskReparentRefusedException::OTHER_BOARD], ['missing', null, TaskReparentRefusedException::NOT_FOUND], [$ids[1], 'missing', TaskReparentRefusedException::NOT_FOUND]] as [$taskId, $parentId, $reason]) {
            try {
                $reparenter->reparent($taskId, $parentId, $ana);
                self::fail('Refused expected: ' . $reason);
            } catch (TaskReparentRefusedException $refused) {
                self::assertSame($reason, $refused->reason);
            }
        }
        self::assertSame(0, $connection->getTransactionNestingLevel());

        self::assertTrue($reparenter->reparent($ids[1], null, $ana)->changed);
        self::assertNull($this->row($table, $ids[1])['parent_id']);
        self::assertSame($ids[1], $connection->fetchOne('SELECT parent_id FROM ' . $table . ' WHERE id = ?', [$ids[2]]));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $table, string $id): array
    {
        $row = $this->em->getConnection()->fetchAssociative('SELECT parent_id, revision, updated_by_id, channel, actor_label FROM ' . $table . ' WHERE id = ?', [$id]);
        self::assertIsArray($row);
        $row['revision'] = (int) $row['revision'];

        return $row;
    }
}
