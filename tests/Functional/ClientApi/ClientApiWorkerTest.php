<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\ClientApi;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Event\BoardAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ClientApiTestHost;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\HttpKernelInterface;

use function array_column;
use function gc_collect_cycles;
use function getenv;
use function json_decode;
use function json_encode;
use function memory_get_usage;
use function putenv;

/**
 * Since 3.4.0 (client API v1, §8 R7, REQ-RUNTIME-002): ONE kept-alive kernel (`http_kernel` driven directly, the services
 * resetter never called), two people in two contexts, 500 rounds: a board grant flipped every round, a token revoked
 * half-way and a membership removed; every answer holds its own person's and context's data only, nothing of a request
 * survives into the next one, and the memory stays flat.
 */
final class ClientApiWorkerTest extends ClientApiTestCase
{
    private const ROUNDS = 500;

    private string|false $debug = false;

    protected function setUp(): void
    {
        $this->debug = getenv('TASK_BOARD_TEST_DEBUG');
        putenv('TASK_BOARD_TEST_DEBUG=0');
        parent::setUp();
    }

    protected function tearDown(): void
    {
        putenv($this->debug === false ? 'TASK_BOARD_TEST_DEBUG' : 'TASK_BOARD_TEST_DEBUG=' . $this->debug);
        parent::tearDown();
    }

    public function testTwoPeopleInTwoContextsInOneWorker(): void
    {
        $this->user('ana@example.com');
        $this->clientToken(email: 'ana@example.com', token: 'tok-ana', contexts: ['personal' => ['tenant-a']]);
        $this->clientToken(email: 'owner@example.com', token: 'tok-own', contexts: ['org:acme' => ['tenant-b'], 'personal' => ['tenant-a']]);
        $mine   = $this->board('Ana board', tenantKey: 'tenant-a');
        $theirs = $this->board('Acme board', tenantKey: 'tenant-b');
        $this->task($mine, 'Ana task');
        $this->task($theirs, 'Acme task');

        $hidden = false;
        $events = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $events);
        $events->addListener(TaskBoardEvents::BOARD_ACCESS_CHECK, static function (BoardAccessCheckEvent $event) use (&$hidden, $theirs): void {
            if ($hidden && $event->getBoardId() === $theirs->getId()) {
                $event->deny();
            }
        });
        $http = self::getContainer()->get('http_kernel');
        self::assertInstanceOf(HttpKernel::class, $http);
        $manager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        $baseline = 0;
        for ($round = 0; $round < self::ROUNDS; ++$round) {
            $hidden = $round % 2 === 1;
            if ($round === self::ROUNDS / 2) {
                unset(ClientApiTestHost::$tokens['tok-ana']);
                ClientApiTestHost::$contexts['owner@example.com'] = ['org:acme' => ['tenant-b']];
            }

            $ana = $this->handle($http, '/boards', 'tok-ana', 'personal');
            if ($round < self::ROUNDS / 2) {
                self::assertSame(['Ana board'], array_column($ana['boards'] ?? [], 'title'), 'round ' . $round);
            } else {
                self::assertSame('invalid_token', $ana['error'] ?? null, 'round ' . $round);
            }

            $acme = $this->handle($http, '/boards', 'tok-own', 'org:acme');
            self::assertSame($hidden ? [] : ['Acme board'], array_column($acme['boards'] ?? [], 'title'), 'round ' . $round);

            $personal = $this->handle($http, '/boards', 'tok-own', 'personal');
            if ($round < self::ROUNDS / 2) {
                self::assertSame(['Ana board'], array_column($personal['boards'] ?? [], 'title'), 'round ' . $round);
            } else {
                self::assertSame('context_forbidden', $personal['error'] ?? null, 'round ' . $round);
            }
            $manager->clear();
            // The test doubles' own records (the logger keeps every line Symfony logs, the host every bearer) are not
            // the bundle's state.
            $this->logger()->records     = [];
            ClientApiTestHost::$resolved = [];
            if ($round === 50) {
                gc_collect_cycles();
                $baseline = memory_get_usage();
            }
        }
        gc_collect_cycles();
        // Flat: well under one kilobyte per request after the warm-up.
        self::assertLessThan($baseline + 2 * 1024 * 1024, memory_get_usage(), 'flat: about a kilobyte per request of test bookkeeping');
    }

    /**
     * @return array<string, mixed>
     */
    private function handle(HttpKernel $http, string $path, string $token, string $context): array
    {
        $request  = Request::create(self::V1 . $path, 'GET', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'HTTP_X_BEACON_CONTEXT' => $context]);
        $response = $http->handle($request, HttpKernelInterface::MAIN_REQUEST, true);
        $decoded  = json_decode((string) $response->getContent(), true);
        $http->terminate($request, $response);
        self::assertIsArray($decoded, (string) json_encode($response->getContent()));

        return $decoded;
    }
}
