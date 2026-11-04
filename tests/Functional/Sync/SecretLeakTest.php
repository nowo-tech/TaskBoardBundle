<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use Doctrine\DBAL\Connection;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Sync\Dto\SyncReport;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderErrorCode;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderException;
use Nowo\TaskBoardBundle\Sync\Exception\RateLimitedException;
use Nowo\TaskBoardBundle\Sync\Provider\ContainerType;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCredential;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteContainer;
use Nowo\TaskBoardBundle\Sync\Testing\InMemoryTaskProvider;
use Nowo\TaskBoardBundle\Tests\Functional\App\PredictableCsrfTokenManager;
use Nowo\TaskBoardBundle\Tests\Functional\App\TestCredentialResolver;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\EventDispatcher\Event;
use Throwable;

use function is_array;
use function is_object;
use function json_encode;
use function ob_get_clean;
use function ob_start;
use function print_r;
use function serialize;
use function var_dump;

use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_PARTIAL_OUTPUT_ON_ERROR;

/**
 * Since 1.23 (contract §5.1, Beacon SC-003): a canary token runs through successful, rate-limited, failing and
 * crashing syncs and the connection pages, then is searched in the logs, every dispatched event, the stored runs,
 * cursors and connections, the rendered HTML, the flashes and the exceptions. It must appear nowhere.
 */
final class SecretLeakTest extends SyncFunctionalTestCase
{
    private const CANARY = 'pk_CANARY_9f8e7d6c5b4a3210';

    public function testTheTokenNeverLeaks(): void
    {
        TestCredentialResolver::$secret = self::CANARY;
        $board                          = $this->board();
        $provider                       = new InMemoryTaskProvider(acceptedSecret: self::CANARY);
        $provider->addContainer(new RemoteContainer(self::LIST, 'Backlog', ContainerType::List, null, true, false, 2));
        $provider->putTask(self::LIST, $this->remote('s1', 'One', 1));
        $credential = new ProviderCredential(self::CANARY, 'conn-1');

        $seen       = [];
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        foreach ([TaskBoardEvents::SYNC_STARTED, TaskBoardEvents::SYNC_PAGE_DONE, TaskBoardEvents::SYNC_FINISHED, TaskBoardEvents::SYNC_FAILED, TaskBoardEvents::TASK_REMOTE_ARCHIVED, TaskBoardEvents::BOARD_CONNECTION_LINKED, TaskBoardEvents::BOARD_CONNECTION_UNLINKED] as $name) {
            $dispatcher->addListener($name, static function (Event $event) use (&$seen): void {
                $seen[] = (string) json_encode(self::flatten($event), JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            });
        }

        $reports   = [];
        $reports[] = $this->sync($board, $provider, $credential);
        $provider->failNext(new RateLimitedException(60));
        $reports[] = $this->sync($board, $provider, $credential, full: true);
        $provider->failNext(new ProviderException(ProviderErrorCode::ServerError));
        $reports[] = $this->sync($board, $provider, $credential, full: true);
        $provider->removeTask(self::LIST, 's1');
        $reports[] = $this->sync($board, $provider, $credential, full: true, allowMassArchive: true);
        // A wrong token: the provider refuses it (auth_failed), with the canary in the request.
        $reports[] = $this->sync($board, new InMemoryTaskProvider(), $credential, full: true);

        // The pages: the container provider refuses the canary (auth_failed) and renders the error.
        $this->login($this->owner);
        $other = $this->board('Other');
        $this->client->request('GET', '/tools/task-board/board/' . $other->getId() . '/connection');
        $html = (string) $this->client->getResponse()->getContent();
        $this->client->request('POST', '/tools/task-board/board/' . $other->getId() . '/connection/link', ['task_board_sync_link' => [
            'provider' => 'clickup', 'connection' => 'conn-1', 'container' => self::LIST, '_token' => PredictableCsrfTokenManager::value('task_board_connection_link'),
        ]]);
        $this->client->followRedirect();
        $html .= (string) $this->client->getResponse()->getContent();
        $this->client->request('GET', '/tools/task-board/board/' . $board->getId() . '/connection');
        $html .= (string) $this->client->getResponse()->getContent();
        $this->client->request('GET', '/tools/task-board/board/' . $board->getId());
        $html .= (string) $this->client->getResponse()->getContent();

        $database = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $database);
        $stored = '';
        foreach (['task_board_connections', 'task_board_sync_runs', 'task_board_sync_items', 'task_board_tasks', 'task_board_task_links', 'task_board_task_change_history'] as $table) {
            $stored .= json_encode($database->fetchAllAssociative('SELECT * FROM ' . $table));
        }

        ob_start();
        var_dump($credential);
        $dump = ob_get_clean() . print_r($credential, true);
        try {
            serialize($credential);
            self::fail('a credential must not serialize');
        } catch (Throwable $exception) {
            $dump .= $exception->getMessage() . $exception->getTraceAsString();
        }

        $haystacks = [
            'logs'    => (string) json_encode($this->logger()->records),
            'events'  => implode("\n", $seen),
            'reports' => (string) json_encode(array_map(static fn (SyncReport $r): array => $r->toArray(), $reports)),
            'stored'  => $stored,
            'html'    => $html,
            'dump'    => $dump,
        ];
        self::assertNotSame('', $haystacks['events']);
        foreach ($haystacks as $where => $text) {
            self::assertStringNotContainsString(self::CANARY, $text, 'the token leaked into the ' . $where);
            self::assertStringNotContainsString('9f8e7d6c5b4a3210', $text, 'part of the token leaked into the ' . $where);
        }
    }

    /**
     * Every property of an event (private ones too), a few levels deep; entities as class and id, reports as arrays.
     */
    private static function flatten(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 4) {
            return '…';
        }
        if ($value instanceof SyncReport) {
            return $value->toArray();
        }
        if ($value instanceof TaskBoard || $value instanceof Task) {
            return $value::class . '#' . $value->getId();
        }
        if (is_object($value)) {
            $out = ['class' => $value::class];
            foreach ((array) $value as $key => $item) {
                $out[str_replace("\0", '', (string) $key)] = self::flatten($item, $depth + 1);
            }

            return $out;
        }
        if (is_array($value)) {
            return array_map(static fn (mixed $item): mixed => self::flatten($item, $depth + 1), $value);
        }

        return $value;
    }
}
