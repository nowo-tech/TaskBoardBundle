<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\DddHost;

use Doctrine\ORM\Tools\SchemaTool;
use Nowo\TaskBoardBundle\Doctrine\TaskBoardEntityManager;
use Nowo\TaskBoardBundle\Dto\TaskBoardFormData;
use Nowo\TaskBoardBundle\Dto\TaskFormData;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskChangeHistory;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Provenance\WriteChannel;
use Nowo\TaskBoardBundle\Provenance\WriteProvenance;
use Nowo\TaskBoardBundle\Service\TaskBoardCreatorInterface;
use Nowo\TaskBoardBundle\Service\TaskCommentManagerInterface;
use Nowo\TaskBoardBundle\Service\TaskManagerInterface;
use Nowo\TaskBoardBundle\Sync\People\TaskSyncPeopleReassigner;
use Nowo\TaskBoardBundle\ValueObject\TaskBoardUserRef;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

use function array_unique;
use function array_values;

/**
 * Since 2.18.0 (Beacon spec 234, REQ-ARCH-001 both host kinds): on the DDD host (plain PHP users, XML mapping, private
 * entity manager, user references by id) the provenance is stored with the last writer as an id, the badges come from
 * a framework-free provider, and the account erasure clears the last writer and, when anonymising, the actor label.
 */
final class DddHostProvenanceTest extends WebTestCase
{
    private KernelBrowser $client;

    private TaskBoardEntityManager $em;

    /**
     * @param array<string, mixed> $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new DddHostKernel([
            'member_badge_provider'     => 'app.ddd_provenance',
            'write_provenance_provider' => 'app.ddd_provenance',
            'sync'                      => ['enabled' => true, 'credential_resolver' => 'app.users'],
        ]);
    }

    protected function setUp(): void
    {
        DddProvenance::$current = null;
        $this->client           = self::createClient();
        $this->client->disableReboot();
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

    public function testTheProvenanceAndBadgesWorkByIdAndTheErasureClearsThem(): void
    {
        $container = self::getContainer();
        $users     = DddUsers::all();
        $ana       = $users['u-ana'];
        $bot       = new DddUser('bot-ci', 'CI bot', 'ci-bot@example.com');
        $tasks     = $container->get(TaskManagerInterface::class);
        $comments  = $container->get(TaskCommentManagerInterface::class);
        self::assertInstanceOf(TaskManagerInterface::class, $tasks);
        self::assertInstanceOf(TaskCommentManagerInterface::class, $comments);
        $creator = $container->get(TaskBoardCreatorInterface::class);
        self::assertInstanceOf(TaskBoardCreatorInterface::class, $creator);

        $board                  = $creator->create(new TaskBoardFormData('Domain board', 'domain-board'), $ana);
        $task                   = $tasks->create($board, new TaskFormData('Domain task'), $ana);
        DddProvenance::$current = new WriteProvenance(WriteChannel::Mcp, 'CI <token>');
        $tasks->updatePriority($task, TaskPriority::High, $bot);
        $comment = $comments->add($task, $bot, 'From the agent');
        $this->em->flush();
        $taskId    = $task->getId();
        $commentId = $comment->getId();
        $this->em->clear();

        $loaded = $this->em->find(Task::class, $taskId);
        self::assertInstanceOf(Task::class, $loaded);
        self::assertEquals(new TaskBoardUserRef('bot-ci'), $loaded->getUpdatedBy());
        self::assertSame(WriteChannel::Mcp, $loaded->getChannel());
        self::assertSame('CI <token>', $loaded->getActorLabel());
        $table = $this->em->getClassMetadata(Task::class)->getTableName();
        self::assertSame('bot-ci', $this->em->getConnection()->fetchOne('SELECT updated_by_id FROM ' . $table . ' WHERE id = ?', [$taskId]));

        $this->client->loginUser($ana);
        $crawler = $this->client->request('GET', '/tools/task-board/task/' . $taskId);
        self::assertResponseIsSuccessful();
        self::assertNotSame([], $crawler->filter('[data-testid="nowo-task-member-badge"]')->each(static fn ($node): string => $node->text()));
        self::assertSame(['Agent'], array_values(array_unique($crawler->filter('[data-testid="nowo-task-member-badge"]')->each(static fn ($node): string => $node->text()))));
        self::assertStringContainsString('via MCP · CI &lt;token&gt;', (string) $this->client->getResponse()->getContent());

        $reassigner = $container->get(TaskSyncPeopleReassigner::class);
        self::assertInstanceOf(TaskSyncPeopleReassigner::class, $reassigner);
        $reassigner->detachUser(new DddUser('BOT-CI', 'Look-alike', 'look@example.com'), $ana, true);
        $this->em->clear();
        self::assertSame('bot-ci', $this->em->getConnection()->fetchOne('SELECT updated_by_id FROM ' . $table . ' WHERE id = ?', [$taskId]), 'a look-alike id erases nothing');

        $reassigner->detachUser($bot, $ana, true);
        $this->em->clear();
        $loaded = $this->em->find(Task::class, $taskId);
        self::assertInstanceOf(Task::class, $loaded);
        self::assertNull($loaded->getUpdatedBy());
        self::assertNull($loaded->getActorLabel());
        self::assertSame(WriteChannel::Mcp, $loaded->getChannel(), 'the channel is no personal data');
        $comment = $this->em->find(TaskComment::class, $commentId);
        self::assertInstanceOf(TaskComment::class, $comment);
        self::assertNull($comment->getUpdatedBy());
        self::assertNull($comment->getActorLabel());
        foreach ($loaded->getChangeHistory() as $entry) {
            self::assertInstanceOf(TaskChangeHistory::class, $entry);
            if ($entry->getChannel() instanceof WriteChannel) {
                self::assertNull($entry->getUser());
                self::assertNull($entry->getActorLabel());
            }
        }
    }

    /**
     * Audit PS-D1-M1: with user references by id, the same writer through a new channel (same object, after a reload,
     * and on a comment) is saved, with the three last-write columns together.
     */
    public function testTheSameWriterThroughANewChannelIsSavedById(): void
    {
        $container = self::getContainer();
        $ana       = DddUsers::all()['u-ana'];
        $tasks     = $container->get(TaskManagerInterface::class);
        $comments  = $container->get(TaskCommentManagerInterface::class);
        $creator   = $container->get(TaskBoardCreatorInterface::class);
        self::assertInstanceOf(TaskManagerInterface::class, $tasks);
        self::assertInstanceOf(TaskCommentManagerInterface::class, $comments);
        self::assertInstanceOf(TaskBoardCreatorInterface::class, $creator);
        $board   = $creator->create(new TaskBoardFormData('Domain board', 'domain-board'), $ana);
        $task    = $tasks->create($board, new TaskFormData('Domain task'), $ana);
        $comment = $comments->add($task, $ana, 'Hi');
        $tasks->updatePriority($task, TaskPriority::Low, $ana);
        $this->em->flush();
        $connection = $this->em->getConnection();
        $row        = fn (string $class, string $id): array => (array) $connection->fetchAssociative('SELECT updated_by_id, channel, actor_label FROM ' . $this->em->getClassMetadata($class)->getTableName() . ' WHERE id = ?', [$id]);

        // Same object.
        DddProvenance::$current = new WriteProvenance(WriteChannel::Mcp, 'ana-mcp');
        $tasks->updatePriority($task, TaskPriority::High, $ana);
        $comments->edit($comment, $ana, 'Hi again');
        $this->em->flush();
        self::assertSame(['updated_by_id' => 'u-ana', 'channel' => 'mcp', 'actor_label' => 'ana-mcp'], $row(Task::class, $task->getId()));
        self::assertSame(['updated_by_id' => 'u-ana', 'channel' => 'mcp', 'actor_label' => 'ana-mcp'], $row(TaskComment::class, $comment->getId()));

        // After a reload (the writer is a TaskBoardUserRef in the row, a host user in the write).
        $taskId    = $task->getId();
        $commentId = $comment->getId();
        $this->em->clear();
        $task    = $this->em->find(Task::class, $taskId);
        $comment = $this->em->find(TaskComment::class, $commentId);
        self::assertInstanceOf(Task::class, $task);
        self::assertInstanceOf(TaskComment::class, $comment);
        DddProvenance::$current = new WriteProvenance(WriteChannel::Api, 'ana-api');
        $tasks->updatePriority($task, TaskPriority::Low, $ana);
        $comments->edit($comment, $ana, 'Hi once more');
        $this->em->flush();
        self::assertSame(['updated_by_id' => 'u-ana', 'channel' => 'api', 'actor_label' => 'ana-api'], $row(Task::class, $taskId));
        self::assertSame(['updated_by_id' => 'u-ana', 'channel' => 'api', 'actor_label' => 'ana-api'], $row(TaskComment::class, $commentId));
    }

    /** Audit PS-L1 in id mode: Bruno's write commits while Ana holds an older snapshot; her flush writes the whole triple. */
    public function testOverlappingWritesNeverMixTwoPeopleById(): void
    {
        $container = self::getContainer();
        $ana       = DddUsers::all()['u-ana'];
        $tasks     = $container->get(TaskManagerInterface::class);
        $creator   = $container->get(TaskBoardCreatorInterface::class);
        self::assertInstanceOf(TaskManagerInterface::class, $tasks);
        self::assertInstanceOf(TaskBoardCreatorInterface::class, $creator);
        DddProvenance::$current = new WriteProvenance(WriteChannel::Api, 'ana-laptop');
        $task                   = $tasks->create($creator->create(new TaskBoardFormData('Domain board', 'domain-board'), $ana), new TaskFormData('Domain task'), $ana);
        $this->em->flush();
        $connection = $this->em->getConnection();
        $table      = $this->em->getClassMetadata(Task::class)->getTableName();
        $connection->executeStatement('UPDATE ' . $table . ' SET updated_by_id = ?, actor_label = ? WHERE id = ?', ['u-bruno', 'bruno-ci', $task->getId()]);

        DddProvenance::$current = new WriteProvenance(WriteChannel::Mcp, 'ana-mcp');
        $tasks->updatePriority($task, TaskPriority::High, $ana);
        $this->em->flush();
        self::assertSame(['updated_by_id' => 'u-ana', 'channel' => 'mcp', 'actor_label' => 'ana-mcp'], $connection->fetchAssociative('SELECT updated_by_id, channel, actor_label FROM ' . $table . ' WHERE id = ?', [$task->getId()]));
    }
}
