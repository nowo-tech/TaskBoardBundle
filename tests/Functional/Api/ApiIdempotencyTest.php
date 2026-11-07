<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Api;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Nowo\TaskBoardBundle\Api\Command\TaskApiPurgeIdempotencyCommand;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiIdempotencyStoreInterface;
use Nowo\TaskBoardBundle\Api\Idempotency\DoctrineTaskApiIdempotencyStore;
use Nowo\TaskBoardBundle\Api\TaskApiWhere;
use Nowo\TaskBoardBundle\Api\Value\TaskApiIdempotencyOutcome;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ApiTestHost;
use Symfony\Component\Console\Tester\CommandTester;

use function hash;
use function str_repeat;

/**
 * Spec 213 FR-015: a write retried with the same `Idempotency-Key` within 24 hours gets its first successful answer, once.
 */
final class ApiIdempotencyTest extends ApiTestCase
{
    private TaskBoard $roadmap;

    protected function setUp(): void
    {
        parent::setUp();
        ApiTestHost::$tenants = ['tenant-a'];
        $this->roadmap        = $this->board('Roadmap', tenantKey: 'tenant-a');
        $this->issueToken();
    }

    public function testARetryGetsTheFirstAnswerAndWritesOnce(): void
    {
        $path = '/api/tasks/v2/list/' . $this->roadmap->getId() . '/task';
        $this->send('POST', $path, ['name' => 'Once', 'priority' => 2], ['HTTP_IDEMPOTENCY_KEY' => 'k-1']);
        self::assertResponseIsSuccessful();
        $first = (string) $this->client->getResponse()->getContent();
        self::assertFalse($this->client->getResponse()->headers->has('Idempotency-Replayed'));

        // The same JSON written another way is the same request.
        $this->send('POST', $path, '{"priority": 2, "name": "Once"}', ['HTTP_IDEMPOTENCY_KEY' => 'k-1']);
        self::assertResponseIsSuccessful();
        self::assertSame($first, (string) $this->client->getResponse()->getContent());
        self::assertSame('true', $this->client->getResponse()->headers->get('Idempotency-Replayed'));
        self::assertSame('99', $this->client->getResponse()->headers->get('X-RateLimit-Remaining'), 'a replay goes through the pipeline');
        self::assertCount(1, $this->em->getRepository(Task::class)->findAll());
    }

    public function testTheSameKeyForAnotherRequestIsAConflict(): void
    {
        $path = '/api/tasks/v2/list/' . $this->roadmap->getId() . '/task';
        $this->send('POST', $path, ['name' => 'First'], ['HTTP_IDEMPOTENCY_KEY' => 'k-2']);

        $this->send('POST', $path, ['name' => 'Second'], ['HTTP_IDEMPOTENCY_KEY' => 'k-2']);
        $this->assertRefused(409, 'IDEMP_002');
        $task = $this->em->getRepository(Task::class)->findAll()[0];
        $this->send('DELETE', '/api/tasks/v2/task/' . $task->getId(), null, ['HTTP_IDEMPOTENCY_KEY' => 'k-2']);
        $this->assertRefused(409, 'IDEMP_002');
        self::assertCount(1, $this->em->getRepository(Task::class)->findAll());
    }

    public function testKeysBelongToTheirToken(): void
    {
        $path = '/api/tasks/v2/list/' . $this->roadmap->getId() . '/task';
        $this->issueToken(token: 'bt_other_placeholder');
        $this->send('POST', $path, ['name' => 'Mine'], ['HTTP_IDEMPOTENCY_KEY' => 'shared']);
        $this->send('POST', $path, ['name' => 'Theirs'], ['HTTP_IDEMPOTENCY_KEY' => 'shared'], 'bt_other_placeholder');

        self::assertResponseIsSuccessful();
        self::assertCount(2, $this->em->getRepository(Task::class)->findAll());
    }

    public function testARefusalIsNotKeptSoTheRetryIsDecidedAfresh(): void
    {
        $path = '/api/tasks/v2/list/' . $this->roadmap->getId() . '/task';
        $this->send('POST', $path, ['name' => ''], ['HTTP_IDEMPOTENCY_KEY' => 'k-3']);
        $this->assertRefused(400, 'INPUT_002');

        $this->send('POST', $path, ['name' => 'Fixed'], ['HTTP_IDEMPOTENCY_KEY' => 'k-3']);
        self::assertResponseIsSuccessful();
    }

    public function testABadKeyAndAKeyInFlight(): void
    {
        $path = '/api/tasks/v2/list/' . $this->roadmap->getId() . '/task';
        foreach (['', str_repeat('k', 256)] as $key) {
            $this->send('POST', $path, ['name' => 'x'], ['HTTP_IDEMPOTENCY_KEY' => $key]);
            $this->assertRefused(400, 'IDEMP_003');
        }

        $body = ['name' => 'Running'];
        $this->store()->claim($this->scope(), hash('sha256', 'k-4'), $this->fingerprint('POST', $path, '{"name":"Running"}'), new DateTimeImmutable('+1 day'));
        $this->send('POST', $path, $body, ['HTTP_IDEMPOTENCY_KEY' => 'k-4']);
        $this->assertRefused(409, 'IDEMP_001');

        // A claim abandoned by a process that died is taken over after five minutes.
        $this->connectionUpdate('UPDATE ' . DoctrineTaskApiIdempotencyStore::TABLE . ' SET created_at = ? WHERE key_hash = ?', [new DateTimeImmutable('-10 minutes'), hash('sha256', 'k-4')]);
        $this->send('POST', $path, $body, ['HTTP_IDEMPOTENCY_KEY' => 'k-4']);
        self::assertResponseIsSuccessful();
    }

    public function testAnExpiredKeyRunsAgainAndThePurgeClearsOldAnswers(): void
    {
        $path = '/api/tasks/v2/list/' . $this->roadmap->getId() . '/task';
        $this->send('POST', $path, ['name' => 'Day one'], ['HTTP_IDEMPOTENCY_KEY' => 'k-5']);
        $this->connectionUpdate('UPDATE ' . DoctrineTaskApiIdempotencyStore::TABLE . ' SET expires_at = ?', [new DateTimeImmutable('-1 minute')]);

        $this->send('POST', $path, ['name' => 'Day one'], ['HTTP_IDEMPOTENCY_KEY' => 'k-5']);
        self::assertFalse($this->client->getResponse()->headers->has('Idempotency-Replayed'));
        self::assertCount(2, $this->em->getRepository(Task::class)->findAll());

        $this->connectionUpdate('UPDATE ' . DoctrineTaskApiIdempotencyStore::TABLE . ' SET expires_at = ?', [new DateTimeImmutable('-1 minute')]);
        $command = self::getContainer()->get(TaskApiPurgeIdempotencyCommand::class);
        self::assertInstanceOf(TaskApiPurgeIdempotencyCommand::class, $command);
        $tester = new CommandTester($command);
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('1 expired answers deleted.', $tester->getDisplay());
    }

    public function testAReplayAfterTheTokenLostItsReachIsNotFound(): void
    {
        $path = '/api/tasks/v2/list/' . $this->roadmap->getId() . '/task';
        $this->send('POST', $path, ['name' => 'Secret'], ['HTTP_IDEMPOTENCY_KEY' => 'k-6']);
        self::assertResponseIsSuccessful();

        $this->issueToken(where: TaskApiWhere::limitedTo(['tenant-b'], []));
        $this->send('POST', $path, ['name' => 'Secret'], ['HTTP_IDEMPOTENCY_KEY' => 'k-6']);
        $this->assertRefused(404, 'ACCESS_001');
        self::assertStringNotContainsString('Secret', (string) $this->client->getResponse()->getContent());

        // A board that is gone leaves its tenant: a token still reaching the tenant gets the answer.
        $this->issueToken();
        $task = $this->em->getRepository(Task::class)->findAll()[0];
        $this->send('DELETE', '/api/tasks/v2/task/' . $task->getId(), null, ['HTTP_IDEMPOTENCY_KEY' => 'k-7']);
        $this->send('DELETE', '/api/tasks/v2/task/' . $task->getId(), null, ['HTTP_IDEMPOTENCY_KEY' => 'k-7']);
        self::assertSame('true', $this->client->getResponse()->headers->get('Idempotency-Replayed'));
        $this->connectionUpdate('UPDATE ' . DoctrineTaskApiIdempotencyStore::TABLE . ' SET board_id = ?', ['gone-board']);
        $this->send('DELETE', '/api/tasks/v2/task/' . $task->getId(), null, ['HTTP_IDEMPOTENCY_KEY' => 'k-7']);
        self::assertResponseIsSuccessful();
        $this->issueToken(where: TaskApiWhere::limitedTo([], ['gone-board']));
        $this->send('DELETE', '/api/tasks/v2/task/' . $task->getId(), null, ['HTTP_IDEMPOTENCY_KEY' => 'k-7']);
        self::assertResponseIsSuccessful('a where naming the board that is gone still reaches it');
        $this->issueToken(where: TaskApiWhere::limitedTo([], []));
        $this->send('DELETE', '/api/tasks/v2/task/' . $task->getId(), null, ['HTTP_IDEMPOTENCY_KEY' => 'k-7']);
        $this->assertRefused(404, 'ACCESS_001');
    }

    public function testTheStoreClaimsOnceAndForgetsAToken(): void
    {
        $store = $this->store();
        $until = new DateTimeImmutable('+1 day');
        self::assertSame(TaskApiIdempotencyOutcome::Claimed, $store->claim('scope', 'key', 'fp', $until)->outcome);
        self::assertSame(TaskApiIdempotencyOutcome::InFlight, $store->claim('scope', 'key', 'fp', $until)->outcome);
        self::assertSame(TaskApiIdempotencyOutcome::Mismatch, $store->claim('scope', 'key', 'other', $until)->outcome);
        $store->complete('scope', 'key', 201, '{"ok":true}', 'tenant', 'board');
        $claim = $store->claim('scope', 'key', 'fp', $until);
        self::assertSame([TaskApiIdempotencyOutcome::Completed, 201, '{"ok":true}', 'tenant', 'board'], [$claim->outcome, $claim->status, $claim->body, $claim->tenantKey, $claim->boardId]);
        $store->release('scope', 'key');
        self::assertSame(TaskApiIdempotencyOutcome::Completed, $store->claim('scope', 'key', 'fp', $until)->outcome, 'a completed answer is not released');

        $store->forget('scope');
        self::assertSame(TaskApiIdempotencyOutcome::Claimed, $store->claim('scope', 'key', 'fp', $until)->outcome);
        self::assertSame(1, $store->purgeExpired(new DateTimeImmutable('+2 days')));
    }

    private function store(): TaskApiIdempotencyStoreInterface
    {
        $store = self::getContainer()->get(TaskApiIdempotencyStoreInterface::class);
        self::assertInstanceOf(DoctrineTaskApiIdempotencyStore::class, $store);

        return $store;
    }

    private function scope(): string
    {
        return hash('sha256', 'nowo-task-board-api|' . hash('sha256', 'token-id|owner@example.com|' . self::TOKEN));
    }

    private function fingerprint(string $method, string $path, string $json): string
    {
        return hash('sha256', $method . "\n" . $path . "\n" . $json);
    }

    /**
     * @param list<mixed> $parameters
     */
    private function connectionUpdate(string $sql, array $parameters): void
    {
        $types = array_map(static fn (mixed $value): string => $value instanceof DateTimeImmutable ? Types::DATETIME_IMMUTABLE : Types::STRING, $parameters);
        $this->em->getConnection()->executeStatement($sql, $parameters, $types);
    }
}
