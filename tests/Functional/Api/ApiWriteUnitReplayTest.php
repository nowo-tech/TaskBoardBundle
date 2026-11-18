<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Api;

use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Events;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskChangeHistory;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ApiTestHost;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\KernelEvents;

use function file_put_contents;
use function json_decode;
use function sys_get_temp_dir;
use function tempnam;

/**
 * Since 2.22.0 (Beacon spec 241 T020): the API's writes survive a replay of nowo-tech/transaction-doctrine's write unit.
 * Between two attempts the unit clears or resets the entity manager, so the user the principal held since `kernel.request`
 * is detached: each attempt now writes the user as the manager manages it (task creator, comment author, history actor),
 * and an attachment the host already stored in this request is not stored twice.
 *
 * The routes run as real write units (transaction-doctrine's bundle in the kernel, `controller_routes`); the first flush of
 * each request meets a deadlock on its first insert, the unit rolls it back and replays the controller.
 */
final class ApiWriteUnitReplayTest extends ApiTestCase
{
    private static int $deadlocks = 0;

    private TaskBoard $board;

    protected function setUp(): void
    {
        self::$hostConfig = ['__write_units' => true];
        parent::setUp();
        ApiTestHost::$tenants = ['tenant-a'];
        $this->board          = $this->board('Roadmap', tenantKey: 'tenant-a');
        $this->issueToken();
        self::$deadlocks = 0;
    }

    public function testATaskCreateIsReplayedWithAManagedCreator(): void
    {
        $this->deadlockOnce();
        $this->send('POST', '/api/tasks/v2/list/' . $this->board->getId() . '/task', ['name' => 'Replayed']);

        self::assertResponseIsSuccessful();
        self::assertSame(1, self::$deadlocks, 'the first attempt met the deadlock');
        $this->em->clear();
        $tasks = $this->em->getRepository(Task::class)->findBy(['title' => 'Replayed']);
        self::assertCount(1, $tasks);
        self::assertSame($this->owner->getId(), $tasks[0]->getCreator()?->getId());
    }

    public function testATaskUpdateIsReplayedWithAManagedHistoryActor(): void
    {
        $task = $this->task($this->board, 'Before');
        $this->deadlockOnce();
        $this->send('PUT', '/api/tasks/v2/task/' . $task->getId(), ['name' => 'After']);

        self::assertResponseIsSuccessful();
        self::assertSame(1, self::$deadlocks);
        $this->em->clear();
        self::assertSame('After', $this->em->find(Task::class, $task->getId())?->getTitle());
        $history = $this->em->getRepository(TaskChangeHistory::class)->findBy(['task' => $task->getId()]);
        self::assertNotSame([], $history);
        foreach ($history as $row) {
            self::assertSame($this->owner->getId(), $row->getUser()?->getId());
        }
    }

    public function testACommentIsReplayedWithAManagedAuthor(): void
    {
        $task = $this->task($this->board, 'Discussed');
        $this->deadlockOnce();
        $this->send('POST', '/api/tasks/v2/task/' . $task->getId() . '/comment', ['comment_text' => 'Once']);

        self::assertResponseIsSuccessful();
        self::assertSame(1, self::$deadlocks);
        $this->em->clear();
        $comments = $this->em->getRepository(TaskComment::class)->findBy(['task' => $task->getId()]);
        self::assertCount(1, $comments);
        self::assertSame($this->owner->getId(), $comments[0]->getAuthor()?->getId());
    }

    /**
     * The host's store writes no row of this database, so no flush can deadlock here: a second attempt of the same request
     * is simulated by running the controller twice with the entity manager cleared in between, as a write unit does.
     */
    public function testAnAttachmentReplayedInTheSameRequestIsStoredOnce(): void
    {
        $task       = $this->task($this->board, 'With a file');
        $em         = $this->em;
        $dispatcher = static::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(KernelEvents::CONTROLLER_ARGUMENTS, static function (ControllerArgumentsEvent $event) use ($em): void {
            $controller = $event->getController();
            $arguments  = $event->getArguments();
            $event->setController(static function () use ($controller, $arguments, $em): mixed {
                $controller(...$arguments);
                $em->clear();

                return $controller(...$arguments);
            }, $event->getAttributes());
        }, -64);
        $path = (string) tempnam(sys_get_temp_dir(), 'tb-replay');
        file_put_contents($path, 'payload');

        $this->client->request('POST', '/api/tasks/v2/task/' . $task->getId() . '/attachment', [], ['attachment' => new UploadedFile($path, 'a.txt', 'text/plain', null, true)], ['HTTP_AUTHORIZATION' => self::TOKEN]);

        self::assertResponseIsSuccessful();
        self::assertCount(1, ApiTestHost::$stored, 'exactly one file');
        self::assertSame('file-1', json_decode((string) $this->client->getResponse()->getContent(), true)['id'] ?? null);
    }

    /** The first insert of the request fails with a deadlock, inside the flush, as a concurrent writer would make it. */
    private function deadlockOnce(): void
    {
        $this->em->getEventManager()->addEventListener(Events::postPersist, new class {
            public function postPersist(PostPersistEventArgs $args): void
            {
                if (ApiWriteUnitReplayTest::deadlock()) {
                    throw new DeadlockException(new class('Deadlock found when trying to get lock') extends RuntimeException implements DriverException {
                        public function getSQLState(): string
                        {
                            return '40P01'; /* a deadlock, as transaction-doctrine classifies it */
                        }
                    }, null);
                }
            }
        });
    }

    /** Whether this insert is the first one of the request: it gets the deadlock (counted). */
    public static function deadlock(): bool
    {
        if (self::$deadlocks > 0) {
            return false;
        }
        ++self::$deadlocks;

        return true;
    }
}
