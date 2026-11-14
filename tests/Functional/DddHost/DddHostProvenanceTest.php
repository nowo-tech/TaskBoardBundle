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
}
