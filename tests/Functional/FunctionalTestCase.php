<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use Nowo\TaskBoardBundle\Tests\Functional\App\FunctionalKernel;
use Nowo\TaskBoardBundle\Tests\Functional\App\RecordingLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

use function count;

/**
 * Boots {@see FunctionalKernel} on a fresh schema; helpers create users, boards, columns and tasks.
 */
abstract class FunctionalTestCase extends WebTestCase
{
    /** @var array<string, mixed> `nowo_task_board` configuration of the next kernel */
    protected static array $taskBoardConfig = [];

    protected KernelBrowser $client;

    protected EntityManagerInterface $em;

    protected AppUser $owner;

    /**
     * @param array<string, mixed> $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new FunctionalKernel(static::$taskBoardConfig);
    }

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $tool = new SchemaTool($this->em);
        $tool->dropDatabase();
        $tool->createSchema($this->em->getMetadataFactory()->getAllMetadata());

        $this->owner = $this->user('owner@example.com');
    }

    protected function tearDown(): void
    {
        static::$taskBoardConfig = [];
        parent::tearDown();
    }

    /**
     * @param list<string> $roles
     */
    protected function user(string $email, array $roles = ['ROLE_USER']): AppUser
    {
        $user = new AppUser($email, $roles);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    protected function login(AppUser $user): void
    {
        $this->client->loginUser($user);
    }

    /**
     * @param list<string> $columns
     */
    protected function board(string $name = 'Roadmap', array $columns = ['To do', 'Doing', 'Done'], ?AppUser $creator = null, ?string $tenantKey = null): TaskBoard
    {
        $board = new TaskBoard($name, $name . '-' . count($this->em->getRepository(TaskBoard::class)->findAll()), $creator ?? $this->owner);
        if ($tenantKey !== null) {
            $board->setTenantKey($tenantKey);
        }
        foreach ($columns as $position => $column) {
            $board->addColumn(new BoardColumn($board, $column, $position));
        }
        $this->em->persist($board);
        $this->em->flush();

        return $board;
    }

    protected function task(TaskBoard $board, string $title, int $column = 0, ?Task $parent = null, int $position = 0): Task
    {
        $task = new Task(board: $board, title: $title, creator: $this->owner, column: $board->getColumns()->get($column), parent: $parent, position: $position);
        $this->em->persist($task);
        $this->em->flush();

        return $task;
    }

    protected function logger(): RecordingLogger
    {
        $logger = static::getContainer()->get('logger');
        self::assertInstanceOf(RecordingLogger::class, $logger);

        return $logger;
    }

    /**
     * The `value` of the first `name="…_token"` hidden field of the last response.
     */
    protected function token(string $formName = '_token', ?string $within = null): string
    {
        $crawler = $this->client->getCrawler();
        $scope   = $within !== null ? $crawler->filter($within) : $crawler;

        return (string) $scope->filter('input[name="' . $formName . '"]')->first()->attr('value');
    }
}
